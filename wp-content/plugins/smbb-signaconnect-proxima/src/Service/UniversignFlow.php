<?php

namespace Smbb\SignConnectProxima\Service;

use Smbb\SignConnectProxima\Repository\AuditLog;
use Smbb\SignConnectProxima\Repository\Documents;
use Smbb\SignConnectProxima\Repository\Lookup;
use Smbb\SignConnectProxima\Repository\Organizations;
use Smbb\SignConnectProxima\Support\Levels;
use Smbb\SignConnectProxima\Support\UserPrefs;

defined('ABSPATH') || exit;

if (!function_exists('wp_tempnam')) {
    require_once ABSPATH . 'wp-admin/includes/file.php';
}

/**
 * Signature Universign (simple avec code ou qualifiée eIDAS) : création de la transaction,
 * puis suivi par webhook (et, en secours, par la tâche horaire).
 *
 * Universign envoie lui-même l'invitation et les relances au signataire. SignaConnect garde
 * le PDF source sur le S3 de l'organisation ; le PDF signé et l'attestation de preuve ne
 * sont copiés sur le S3 que si l'organisation l'a demandé, sinon ils sont lus chez Universign.
 */
final class UniversignFlow
{
    /**
     * Crée et démarre la transaction. Le document est déjà passé à « sent » par SendService.
     *
     * @param string $auth sms|email (niveau simple)
     * @return array{transaction_id:string,document_id:string}
     */
    public static function start(array $document, array $organization, $auth)
    {
        $client = Universign::forOrganization($organization);
        $storage = Storage::forDocument($document, $organization);
        $level = $document['signature_level'];
        $source = $storage->download($document['source_storage_path']);
        $prepared = '';
        $transaction_id = '';

        try {
            $fields = Documents::fields($document['id']);
            $pdf_path = $source;
            $prefill = array_values(array_filter($fields, function ($field) {
                return in_array($field['field_type'], array('full_name', 'place', 'sender_stamp', 'sender_signature'), true);
            }));

            // Nom du signataire, lieu et tampon (équipe, sinon organisation) sont connus d'avance : on les pose
            // dans le PDF avant de l'envoyer. Universign porte la signature et la date de signature
            // (champ automatique signature_date, rempli par Universign au moment où le signataire signe).
            if ($prefill) {
                $stamp = StampService::path($organization, isset($document['team_id']) ? (int) $document['team_id'] : 0);
                $sender = !empty($document['sender_id']) ? get_userdata((int) $document['sender_id']) : false;
                $prepared = wp_tempnam('scp-universign.pdf');
                $sender_signature = $sender ? UserPrefs::signatureFile($sender->ID) : '';
                Pdf::sign($source, $prepared, $prefill, array(
                    'title' => $document['filename'],
                    'signer_name' => Documents::recipientName($document),
                    'place' => Organizations::city($organization, isset($document['team_id']) ? (int) $document['team_id'] : 0),
                    'stamp_path' => is_string($stamp) ? $stamp : '',
                    'stamp_name' => (string) $organization['name'],
                    'sender_name' => $sender ? $sender->display_name : '',
                    'sender_signature_png' => $sender_signature,
                ));
                $pdf_path = $prepared;
            }

            $sizes = Pdf::pageSizes($pdf_path);
            $validity = max(1, min(60, (int) $organization['send_validity_days']));
            $filename = self::pdfName($document['filename']);

            $transaction = $client->request('POST', '/v1/transactions', array(
                'name' => mb_substr(preg_replace('/\.pdf$/i', '', $document['filename']), 0, 100),
                'language' => 'fr',
                'duration' => $validity * 1440,
            ));
            $transaction_id = isset($transaction['id']) ? (string) $transaction['id'] : '';

            if ($transaction_id === '') {
                throw new \RuntimeException(__("Universign n'a pas créé la transaction.", 'smbb-signaconnect-proxima'));
            }

            $file_id = $client->uploadFile($pdf_path, $filename);
            $added = $client->request('POST', self::txPath($transaction_id) . '/documents', array('document' => $file_id, 'name' => $filename));
            $universign_document_id = isset($added['id']) ? (string) $added['id'] : '';

            if ($universign_document_id === '') {
                throw new \RuntimeException(__("Universign n'a pas accepté le document.", 'smbb-signaconnect-proxima'));
            }

            $field_ids = array();
            $number = 0;

            foreach ($fields as $field) {
                if ($field['field_type'] !== 'signature') {
                    continue;
                }

                $number++;
                $page = (int) $field['page_number'];
                $size = isset($sizes[$page]) ? $sizes[$page] : array(595.0, 842.0);
                $created = $client->request('POST', self::txPath($transaction_id) . '/documents/' . rawurlencode($universign_document_id) . '/fields', array_merge(
                    array('type' => 'signature', 'name' => 'Signature ' . $number, 'page' => $page),
                    self::box($field, $size)
                ));

                if (empty($created['id'])) {
                    throw new \RuntimeException(__("Universign n'a pas accepté la zone de signature.", 'smbb-signaconnect-proxima'));
                }

                $field_ids[] = (string) $created['id'];
            }

            if (!$field_ids) {
                throw new \RuntimeException(__('Placez au moins une zone de signature sur le document.', 'smbb-signaconnect-proxima'));
            }

            foreach ($field_ids as $field_id) {
                $client->request('POST', self::txPath($transaction_id) . '/signatures', array(
                    'signer' => $document['recipient_email'],
                    'field' => $field_id,
                ));
            }

            self::addDateFields($client, $transaction_id, $universign_document_id, $fields, $sizes, $document);
            self::addParticipant($client, $transaction_id, self::participant($document, $organization, $level, $auth), $document, $organization);
            $client->request('POST', self::txPath($transaction_id) . '/start', array());

            return array('transaction_id' => $transaction_id, 'document_id' => $universign_document_id);
        } catch (\Throwable $exception) {
            if ($transaction_id !== '') {
                try {
                    $client->cancel($transaction_id);
                } catch (\Throwable $ignored) {
                    // Brouillon Universign : supprimé automatiquement sous 24 h.
                }
            }

            throw $exception;
        } finally {
            foreach (array($source, $prepared, isset($sender_signature) ? $sender_signature : '') as $path) {
                if ($path && file_exists($path)) {
                    @unlink($path);
                }
            }
        }
    }

    /**
     * Relit la transaction chez Universign et met le document à jour.
     *
     * @return string statut SignaConnect après synchronisation
     */
    public static function sync(array $document, $origin = 'cron')
    {
        if (empty($document['universign_transaction_id'])) {
            return (string) $document['status'];
        }

        $organization = Organizations::find($document['organization_id']);

        if (!$organization) {
            return (string) $document['status'];
        }

        $transaction = Universign::forOrganization($organization)->transaction($document['universign_transaction_id']);
        $state = isset($transaction['state']) ? strtolower((string) $transaction['state']) : '';
        $refusal = self::refusal($transaction);
        Documents::update($document['id'], array(
            'universign_last_event' => mb_substr($state . (!empty($transaction['stalled']) ? ' (bloquée)' : ''), 0, 100),
            'universign_last_event_at' => current_time('mysql'),
        ));

        if (!in_array($document['status'], array('sent', 'opened'), true)) {
            return (string) $document['status'];
        }

        if (in_array($state, array('completed', 'archived', 'closed'), true)) {
            self::complete($document, $organization, $transaction, $origin);
        } elseif ($refusal !== '') {
            self::close($document, $organization, 'refused', $origin, $refusal);
        } elseif ($state === 'cancelled') {
            self::close($document, $organization, 'cancelled', $origin);
        } elseif ($state === 'expired') {
            self::close($document, $organization, 'expired', $origin);
        } elseif ($document['status'] === 'sent' && self::opened($transaction)) {
            Documents::transition($document['id'], array('sent'), array('status' => 'opened', 'opened_at' => current_time('mysql')));
        }

        $document = Documents::find($document['id']);

        return $document ? (string) $document['status'] : '';
    }

    /**
     * Secours si les webhooks ne passent pas : documents Universign en attente, les plus anciens d'abord.
     */
    public static function syncPending($limit = 20)
    {
        global $wpdb;

        $ids = $wpdb->get_col($wpdb->prepare(
            'SELECT id FROM ' . Lookup::table('scp_document') . " WHERE deleted_flag = 0 AND status IN ('sent','opened') AND signature_level <> %s AND universign_transaction_id IS NOT NULL ORDER BY universign_last_event_at ASC LIMIT %d",
            Levels::INTERNAL,
            (int) $limit
        ));

        foreach ($ids as $id) {
            $document = Documents::find($id);

            try {
                if ($document) {
                    self::sync($document, 'cron');
                }
            } catch (\Throwable $exception) {
                Documents::update($id, array('universign_last_event_at' => current_time('mysql')));
                AuditLog::record('universign_sync_failed', $exception->getMessage(), array(), $document ? $document['organization_id'] : null, $id, 'system');
            }
        }
    }

    public static function cancel(array $document)
    {
        $organization = Organizations::find($document['organization_id']);

        if ($organization && !empty($document['universign_transaction_id'])) {
            Universign::forOrganization($organization)->cancel($document['universign_transaction_id']);
        }
    }

    public static function remind(array $document)
    {
        $organization = Organizations::find($document['organization_id']);

        if (!$organization || empty($document['universign_transaction_id'])) {
            throw new \RuntimeException(__('Transaction Universign introuvable.', 'smbb-signaconnect-proxima'));
        }

        if (!empty($document['last_reminder_at']) && strtotime($document['last_reminder_at']) > current_time('timestamp') - HOUR_IN_SECONDS) {
            throw new \RuntimeException(__('Universign impose une heure entre deux relances.', 'smbb-signaconnect-proxima'));
        }

        Universign::forOrganization($organization)->remind($document['universign_transaction_id'], $document['recipient_email']);
    }

    /**
     * PDF signé (ou attestation) téléchargé chez Universign dans un fichier temporaire.
     */
    public static function download(array $document, $what = 'signed')
    {
        $organization = Organizations::find($document['organization_id']);

        if (!$organization || empty($document['universign_transaction_id'])) {
            throw new \RuntimeException(__('Document indisponible.', 'smbb-signaconnect-proxima'));
        }

        $client = Universign::forOrganization($organization);
        $target = wp_tempnam('scp-universign-' . $what . '.pdf');

        try {
            if ($what === 'evidence') {
                return $client->downloadAttestation($document['universign_transaction_id'], $target);
            }

            return $client->downloadSigned($document['universign_transaction_id'], self::universignDocumentId($document, $client), $target);
        } catch (\Throwable $exception) {
            @unlink($target);
            throw $exception;
        }
    }

    /* ---------- Fin de transaction ---------- */

    private static function complete(array $document, array $organization, array $transaction, $origin)
    {
        $signed_at = self::localDate(isset($transaction['closed_at']) ? $transaction['closed_at'] : '') ?: current_time('mysql');
        $signer = self::signerName($transaction) ?: Documents::recipientName($document);

        if (!Documents::transition($document['id'], array('sent', 'opened'), array(
            'status' => 'signed',
            'signed_at' => $signed_at,
            'closed_at' => $signed_at,
            'signer_name' => mb_substr($signer, 0, 190),
            'billable' => 1,
            'billing_month' => substr($signed_at, 0, 7),
        ))) {
            return;
        }

        AuditLog::record('document_signed', sprintf(__('Document signé sur Universign (%s).', 'smbb-signaconnect-proxima'), Levels::label($document['signature_level'])), array(
            'transaction' => $document['universign_transaction_id'],
            'signer' => $signer,
            'origin' => $origin,
        ), $organization['id'], $document['id'], 'system');

        $document = Documents::find($document['id']);
        $signed = '';
        $evidence = '';

        try {
            $signed = self::download($document, 'signed');
            $update = array('signed_sha256' => hash_file('sha256', $signed));

            if (!empty($organization['copy_universign_to_s3'])) {
                $storage = !empty($document['storage_unit']) ? Storage::forDocument($document, $organization) : Storage::forOrganization($organization);

                if (empty($document['storage_unit']) && Storage::unitOf($organization) !== '' && \Smbb\SignConnectProxima\Support\Schema::atLeast('0.12.0')) {
                    $update['storage_unit'] = Storage::unitOf($organization);
                }

                $update['signed_storage_path'] = Storage::documentKey($organization['id'], $document['id'], 'signed');
                $storage->putFile($update['signed_storage_path'], $signed);

                try {
                    $evidence = self::download($document, 'evidence');
                    $update['evidence_storage_path'] = Storage::documentKey($organization['id'], $document['id'], 'evidence');
                    $storage->putFile($update['evidence_storage_path'], $evidence);
                } catch (\Throwable $exception) {
                    AuditLog::record('universign_copy_failed', $exception->getMessage(), array('file' => 'evidence'), $organization['id'], $document['id'], 'system');
                }
            }

            Documents::update($document['id'], $update);
            $document = Documents::find($document['id']);
            Mailer::signedToSender($document, $organization, $signed);
        } catch (\Throwable $exception) {
            // Archivage Universign parfois différé de quelques minutes : le PDF reste lisible à la demande.
            AuditLog::record('universign_copy_failed', $exception->getMessage(), array('file' => 'signed'), $organization['id'], $document['id'], 'system');
        } finally {
            foreach (array($signed, $evidence) as $path) {
                if ($path && file_exists($path)) {
                    @unlink($path);
                }
            }
        }
    }

    private static function close(array $document, array $organization, $status, $origin, $reason = '')
    {
        $data = array('status' => $status, 'closed_at' => current_time('mysql'));

        if ($status === 'refused') {
            $data['refusal_message'] = sprintf(__('Refus sur Universign (%s).', 'smbb-signaconnect-proxima'), $reason);
        }

        if (!Documents::transition($document['id'], array('sent', 'opened'), $data)) {
            return;
        }

        $labels = array(
            'refused' => __('Signature refusée sur Universign.', 'smbb-signaconnect-proxima'),
            'cancelled' => __('Transaction Universign annulée.', 'smbb-signaconnect-proxima'),
            'expired' => __('Transaction Universign expirée sans signature.', 'smbb-signaconnect-proxima'),
        );
        AuditLog::record('document_' . $status, $labels[$status], array('transaction' => $document['universign_transaction_id'], 'reason' => $reason, 'origin' => $origin), $organization['id'], $document['id'], 'system');
        $document = Documents::find($document['id']);

        try {
            if ($status === 'refused') {
                Mailer::refusedToSender($document, $organization);
                self::cancel($document);
            } elseif ($status === 'expired') {
                Mailer::expiredToSender($document, $organization);
            }
        } catch (\Throwable $exception) {
            AuditLog::record('notification_failed', $exception->getMessage(), array('to' => 'sender'), $organization['id'], $document['id'], 'system');
        }
    }

    /* ---------- Aides ---------- */

    /**
     * Zone en points, origine en bas à gauche (convention Universign). Signature : largeur = 4 × hauteur, 92 × 23 minimum.
     *
     * @param array{0:float,1:float} $size
     */
    /**
     * Champs « Date de signature » : champ automatique Universign signature_date (jj/mm/aaaa), daté
     * par Universign quand le signataire signe. Un refus d'Universign sur ce champ ne bloque pas
     * l'envoi : il est noté dans le journal et la zone reste vide.
     */
    private static function addDateFields($client, $transaction_id, $universign_document_id, array $fields, array $sizes, array $document)
    {
        $number = 0;

        foreach ($fields as $field) {
            if ($field['field_type'] !== 'date') {
                continue;
            }

            $number++;
            $page = (int) $field['page_number'];
            $size = isset($sizes[$page]) ? $sizes[$page] : array(595.0, 842.0);

            try {
                $created = $client->request('POST', self::txPath($transaction_id) . '/documents/' . rawurlencode($universign_document_id) . '/fields', array_merge(
                    array('type' => 'signature_date', 'name' => 'Date ' . $number, 'page' => $page, 'format' => 'DD/MM/YYYY'),
                    self::textBox($field, $size)
                ));

                if (!empty($created['id'])) {
                    // Rattache le champ au signataire, comme une zone de signature.
                    try {
                        $client->request('POST', self::txPath($transaction_id) . '/signatures', array(
                            'signer' => $document['recipient_email'],
                            'field' => (string) $created['id'],
                        ));
                    } catch (\Throwable $ignored) {
                        // Champ automatique : Universign peut le remplir sans rattachement explicite.
                    }
                }
            } catch (\Throwable $exception) {
                AuditLog::record('universign_date_field', sprintf(__('Date de signature non posée par Universign (%s).', 'smbb-signaconnect-proxima'), $exception->getMessage()), array(), (int) $document['organization_id'], (int) $document['id'], 'system');
            }
        }
    }

    /**
     * Zone de texte Universign (points PDF, origine en bas à gauche comme box()), taille de police 7 à 12.
     *
     * @return array{x:int,y:int,width:int,height:int,font_size:int}
     */
    public static function textBox(array $field, array $size)
    {
        list($page_w, $page_h) = $size;
        $w = (int) max(40, round((float) $field['width'] * $page_w));
        $h = (int) max(10, round((float) $field['height'] * $page_h));
        $x = (float) $field['x'] * $page_w;
        $y = $page_h - (float) $field['y'] * $page_h - $h;

        return array(
            'x' => (int) round(max(0, min($x, $page_w - $w))),
            'y' => (int) round(max(0, min($y, $page_h - $h))),
            'width' => $w,
            'height' => $h,
            'font_size' => (int) max(7, min(12, floor($h * 0.6))),
        );
    }

    public static function box(array $field, array $size)
    {
        list($page_w, $page_h) = $size;
        // Entiers exacts : Universign exige largeur = 4 × hauteur au point près.
        $h = (int) max(23, round((float) $field['width'] * $page_w / 4));
        $h = (int) min($h, floor($page_w / 4), floor($page_h));
        $w = $h * 4;
        $x = (float) $field['x'] * $page_w;
        $top = (float) $field['y'] * $page_h;
        $y = $page_h - $top - $h;

        return array(
            'x' => (int) round(max(0, min($x, $page_w - $w))),
            'y' => (int) round(max(0, min($y, $page_h - $h))),
            'width' => $w,
            'height' => $h,
        );
    }

    /**
     * Invitation tout de suite, puis relances Universign aux jours de l'organisation (en heures, écarts ≥ 24 h).
     *
     * @return int[]
     */
    public static function schedule(array $organization)
    {
        $schedule = array(0);
        $previous = 0;

        foreach (Organizations::reminderDays($organization) as $day) {
            $gap = ($day - $previous) * 24;

            if ($gap >= 24) {
                $schedule[] = $gap;
                $previous = $day;
            }
        }

        return $schedule;
    }

    private static function participant(array $document, array $organization, $level, $auth)
    {
        $sender = !empty($document['sender_id']) ? get_userdata((int) $document['sender_id']) : false;
        $message = trim((string) $document['send_message']);
        $name = Documents::recipientName($document);
        $participant = array(
            'email' => $document['recipient_email'],
            // Nom prérempli sur la page Universign ; « suggestion » laisse le signataire le corriger.
            'full_name' => $name !== '' ? mb_substr($name, 0, 190) : null,
            'full_name_type' => $name !== '' ? 'suggestion' : null,
            'schedule' => self::schedule($organization),
            'min_signature_level' => Levels::universignLevel($level),
            'invitation_subject' => mb_substr(sprintf(__('%1$s : document à signer « %2$s »', 'smbb-signaconnect-proxima'), $organization['name'], preg_replace('/\.pdf$/i', '', $document['filename'])), 0, 100),
            'invitation_message' => $message !== '' ? mb_substr(str_replace("\n", '<br/>', esc_html($message)), 0, 1000) : null,
        );

        // Signature dessinée à la main sur la page Universign (réglage ; paramètre vu dans les réponses de l'API, non documenté en entrée).
        $settings = get_option('smbb_scp_settings', array());

        if (is_array($settings) && !empty($settings['universign_handwritten'])) {
            $participant['signature_image_policy'] = 'handwritten';
        }

        if ($sender && $message === '') {
            $participant['invitation_message'] = esc_html(sprintf(__('%1$s (%2$s) vous invite à signer ce document.', 'smbb-signaconnect-proxima'), $sender->display_name, $organization['name']));
        }

        // Universign exige le format international : on normalise aussi les anciens envois.
        $phone = !empty($document['recipient_phone']) ? Sms::normalizePhone($document['recipient_phone']) : '';

        if ($level === Levels::SIMPLE) {
            if ($auth === 'email') {
                $participant['level1_auth_means'] = 'email';
            } else {
                $participant['phone_number'] = $phone;
                $participant['phone_number_type'] = 'prerequisite';
            }
        } elseif ($phone !== '') {
            $participant['phone_number'] = $phone;
            $participant['phone_number_type'] = 'suggestion';
        }

        return $participant;
    }

    /**
     * Ajout du signataire. Formats non confirmés par la doc : on tente le format documenté,
     * puis les listes répétées, puis sans le nom prérempli. Chaque repli est tracé.
     */
    private static function addParticipant(Universign $client, $transaction_id, array $participant, array $document, array $organization)
    {
        $path = self::txPath($transaction_id) . '/participants';
        $without_name = $participant;
        unset($without_name['full_name'], $without_name['full_name_type']);
        $attempts = array(
            array($participant, false, ''),
            array($participant, true, 'listes répétées'),
            array($without_name, false, 'sans nom prérempli'),
            array($without_name, true, 'sans nom prérempli, listes répétées'),
        );

        // Signature dessinée refusée par Universign : on réessaie sans, plutôt que de bloquer l'envoi.
        if (isset($participant['signature_image_policy'])) {
            foreach ($attempts as $attempt) {
                unset($attempt[0]['signature_image_policy']);
                $attempt[2] = trim($attempt[2] . ', sans signature dessinée', ', ');
                $attempts[] = $attempt;
            }
        }
        $first_error = null;

        foreach ($attempts as $attempt) {
            try {
                $client->request('POST', $path, $attempt[0], $attempt[1]);

                if ($first_error !== null) {
                    AuditLog::record('universign_format_fallback', sprintf(__('Signataire accepté par Universign (%s).', 'smbb-signaconnect-proxima'), $attempt[2]), array('first_error' => $first_error->getMessage()), $organization['id'], $document['id'], 'system');
                }

                return;
            } catch (\RuntimeException $exception) {
                if (strpos($exception->getMessage(), 'HTTP 400') === false) {
                    throw $exception;
                }

                $first_error = $first_error ?: $exception;
            }
        }

        throw $first_error;
    }

    private static function txPath($transaction_id)
    {
        return '/v1/transactions/' . rawurlencode($transaction_id);
    }

    private static function universignDocumentId(array $document, Universign $client)
    {
        if (!empty($document['universign_document_id'])) {
            return (string) $document['universign_document_id'];
        }

        $transaction = $client->transaction($document['universign_transaction_id']);

        if (!empty($transaction['documents'][0]['id'])) {
            return (string) $transaction['documents'][0]['id'];
        }

        throw new \RuntimeException(__('Document Universign introuvable.', 'smbb-signaconnect-proxima'));
    }

    /**
     * Motif de refus éventuel (action bloquée : signature_refusal, consultation_refusal…).
     */
    private static function refusal(array $transaction)
    {
        $found = '';
        $walk = function ($value, $key = '') use (&$walk, &$found) {
            if ($found !== '') {
                return;
            }

            if (is_array($value)) {
                foreach ($value as $child_key => $child) {
                    $walk($child, is_string($child_key) ? $child_key : $key);
                }

                return;
            }

            if (is_string($value) && preg_match('/_refusal$/', $value) && stripos((string) $key, 'stall') !== false) {
                $found = $value;
            }
        };
        $walk($transaction);

        return $found;
    }

    private static function opened(array $transaction)
    {
        foreach (array('participants', 'actions') as $list) {
            foreach (isset($transaction[$list]) && is_array($transaction[$list]) ? $transaction[$list] : array() as $item) {
                foreach (array('first_access_at', 'opened_at', 'accessed_at', 'consulted_at') as $key) {
                    if (!empty($item[$key])) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    private static function signerName(array $transaction)
    {
        foreach (isset($transaction['participants']) && is_array($transaction['participants']) ? $transaction['participants'] : array() as $participant) {
            if (!empty($participant['full_name'])) {
                return sanitize_text_field((string) $participant['full_name']);
            }
        }

        return '';
    }

    private static function localDate($iso)
    {
        $timestamp = $iso ? strtotime((string) $iso) : false;

        return $timestamp ? get_date_from_gmt(gmdate('Y-m-d H:i:s', $timestamp)) : '';
    }

    private static function pdfName($filename)
    {
        $name = sanitize_file_name((string) $filename) ?: 'document.pdf';

        return preg_match('/\.pdf$/i', $name) ? $name : $name . '.pdf';
    }
}
