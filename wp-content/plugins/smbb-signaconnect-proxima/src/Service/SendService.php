<?php

namespace Smbb\SignConnectProxima\Service;

use Smbb\SignConnectProxima\Repository\AuditLog;
use Smbb\SignConnectProxima\Repository\Documents;
use Smbb\SignConnectProxima\Repository\Lookup;
use Smbb\SignConnectProxima\Repository\Memberships;
use Smbb\SignConnectProxima\Repository\Organizations;
use Smbb\SignConnectProxima\Repository\Templates;
use Smbb\SignConnectProxima\Support\Levels;
use Smbb\SignConnectProxima\Support\Placeholders;
use Smbb\SignConnectProxima\Support\UserPrefs;
use Smbb\SignConnectProxima\Support\Token;

defined('ABSPATH') || exit;

/**
 * Parcours de l'expéditeur : dépôt, zones, envoi (interne ou Universign), relance, annulation.
 */
final class SendService
{
    const MAX_UPLOAD_BYTES = 20971520; // 20 Mo

    /**
     * Dépôt du PDF : contrôle, rangement sur le S3 de l'organisation, document en brouillon.
     */
    public static function upload($user_id, $team_id, array $file)
    {
        $membership = Memberships::forUserTeam($user_id, $team_id);

        if (!$membership) {
            throw new \RuntimeException(__("Vous ne faites pas partie de cette équipe.", 'smbb-signaconnect-proxima'));
        }

        $organization = self::sendableOrganization($membership['organization_id']);

        if (self::templatesOnly($team_id) && !self::templates($organization['id'])) {
            throw new \RuntimeException(__("Votre équipe n'envoie que des documents issus d'un modèle, et votre organisation n'en a encore aucun d'actif. Contactez votre administrateur.", 'smbb-signaconnect-proxima'));
        }

        self::assertUpload($file);
        $inspection = Pdf::inspect($file['tmp_name']);
        $filename = sanitize_file_name((string) $file['name']) ?: 'document.pdf';

        $document_id = Documents::insert(array(
            'organization_id' => (int) $organization['id'],
            'team_id' => (int) $team_id,
            'sender_id' => (int) $user_id,
            'membership_id' => (int) $membership['id'],
            'filename' => mb_substr($filename, 0, 255),
            'file_size' => (int) filesize($inspection['path']),
            'page_count' => (int) $inspection['page_count'],
            'source_sha256' => hash_file('sha256', $inspection['path']),
            'detection_source' => 'manual',
            'signature_level' => Levels::INTERNAL,
            'status' => 'draft',
            'storage_location' => 's3',
        ));

        try {
            $key = Storage::documentKey($organization['id'], $document_id, 'source');
            Storage::forOrganization($organization)->putFile($key, $inspection['path']);
            Documents::update($document_id, array('source_storage_path' => $key));
        } catch (\Throwable $exception) {
            Documents::update($document_id, array('deleted_flag' => 1, 'deleted_date' => current_time('mysql')));
            throw new \RuntimeException(sprintf(__("Le PDF n'a pas pu être rangé sur le stockage de l'organisation : %s", 'smbb-signaconnect-proxima'), $exception->getMessage()));
        } finally {
            if ($inspection['normalized']) {
                @unlink($inspection['path']);
            }
        }

        AuditLog::record('document_uploaded', __('PDF déposé.', 'smbb-signaconnect-proxima'), array(
            'filename' => $filename,
            'pages' => (int) $inspection['page_count'],
            'normalized' => $inspection['normalized'],
        ), $organization['id'], $document_id, 'user', $user_id);

        return Documents::find($document_id);
    }

    /**
     * Envoi au signataire : signature interne (lien SignaConnect) ou Universign (invitation envoyée par Universign).
     *
     * @param array<string,mixed> $input recipient_name, recipient_email, recipient_phone, channels[], send_message,
     *                                   fields (JSON), template_id, signature_level, auth_means
     */
    public static function send($user_id, $document_id, array $input)
    {
        $document = self::ownedDraft($user_id, $document_id);
        $organization = self::sendableOrganization($document['organization_id']);
        $template_id = isset($input['template_id']) ? absint($input['template_id']) : 0;
        $template = $template_id ? self::template($organization['id'], $template_id) : null;
        $level = Levels::normalize(isset($input['signature_level']) ? $input['signature_level'] : '', '');
        $available = Organizations::availableLevels($organization);
        $recipient = array(
            'recipient_name' => mb_substr(trim(sanitize_text_field(isset($input['recipient_name']) ? $input['recipient_name'] : '')), 0, 190),
            'recipient_email' => sanitize_email(isset($input['recipient_email']) ? $input['recipient_email'] : ''),
            'recipient_phone' => sanitize_text_field(isset($input['recipient_phone']) ? $input['recipient_phone'] : ''),
        );
        $phone = $recipient['recipient_phone'] !== '' ? Sms::normalizePhone($recipient['recipient_phone']) : '';
        $is_universign = $level !== Levels::INTERNAL;
        $auth = isset($input['auth_means']) && in_array($input['auth_means'], array('sms', 'email'), true)
            ? $input['auth_means']
            : ((string) $organization['simple_auth_means'] === 'email' ? 'email' : 'sms');
        $channels = array();
        $errors = array();

        if (!$template && self::templatesOnly((int) $document['team_id'])) {
            $errors[] = __("Document inconnu : votre équipe n'envoie que des documents reconnus par un modèle. Contactez le gestionnaire de votre organisation pour revoir le paramétrage.", 'smbb-signaconnect-proxima');
        }

        if ($level === '' || !in_array($level, $available, true)) {
            $errors[] = __("Ce niveau de signature n'est pas disponible pour votre organisation.", 'smbb-signaconnect-proxima');
        } elseif ($template && !empty($template['level_enforced']) && Levels::rank($level) < Levels::rank($template['suggested_level'])) {
            $errors[] = sprintf(__('Le modèle « %1$s » impose au moins le niveau %2$s.', 'smbb-signaconnect-proxima'), $template['name'], Levels::label($template['suggested_level']));
        }

        if ($recipient['recipient_name'] === '') {
            $errors[] = __('Indiquez le nom complet du signataire.', 'smbb-signaconnect-proxima');
        }

        if ($recipient['recipient_email'] !== '' && !is_email($recipient['recipient_email'])) {
            $errors[] = __("L'email du signataire n'est pas valide.", 'smbb-signaconnect-proxima');
        }

        if ($recipient['recipient_phone'] !== '' && $phone === '') {
            $errors[] = __('Le numéro de mobile du signataire est invalide (ex. 06 12 34 56 78 ou +33…).', 'smbb-signaconnect-proxima');
        }

        if ($is_universign) {
            if ($recipient['recipient_email'] === '') {
                $errors[] = __("L'email du signataire est nécessaire : Universign lui envoie l'invitation.", 'smbb-signaconnect-proxima');
            }

            if ($level === Levels::SIMPLE && $auth === 'sms' && $phone === '') {
                $errors[] = __('Le mobile du signataire est nécessaire pour le code par SMS.', 'smbb-signaconnect-proxima');
            }
        } else {
            $channels = isset($input['channels']) && is_array($input['channels']) ? array_map('sanitize_key', $input['channels']) : array();
            $channels = array_values(array_intersect($channels, $organization['internal_channels']));

            if (!$channels) {
                $errors[] = __("Choisissez comment prévenir le signataire (email ou SMS).", 'smbb-signaconnect-proxima');
            }

            if (in_array('email', $channels, true) && $recipient['recipient_email'] === '') {
                $errors[] = __("L'email du signataire est nécessaire pour l'envoi par email.", 'smbb-signaconnect-proxima');
            }

            if (in_array('sms', $channels, true)) {
                if ($phone === '') {
                    $errors[] = __('Le numéro de mobile du signataire est nécessaire pour le SMS (ex. 06 12 34 56 78 ou +33…).', 'smbb-signaconnect-proxima');
                } elseif (!Organizations::hasTwilio($organization)) {
                    $errors[] = __("L'envoi par SMS n'est pas configuré pour cette organisation.", 'smbb-signaconnect-proxima');
                }
            }
        }

        $custom = self::customValues($template, isset($input['custom']) && is_array($input['custom']) ? $input['custom'] : array(), $errors);
        $fields = self::cleanFields(isset($input['fields']) ? $input['fields'] : '', (int) $document['page_count']);

        if (!array_filter($fields, function ($field) {
            return $field['field_type'] === 'signature';
        })) {
            $errors[] = __('Placez au moins une zone de signature sur le document.', 'smbb-signaconnect-proxima');
        }

        if (array_filter($fields, function ($field) {
            return $field['field_type'] === 'sender_signature';
        }) && !UserPrefs::hasSignature($user_id)) {
            $errors[] = __('Ajoutez votre signature personnelle dans « Mon profil » avant d\'utiliser une zone « Signature de l\'expéditeur ».', 'smbb-signaconnect-proxima');
        }

        if ($errors) {
            throw new \InvalidArgumentException(implode("\n", $errors));
        }

        $message = isset($input['send_message']) ? sanitize_textarea_field($input['send_message']) : '';
        $validity = max(1, (int) $organization['send_validity_days']);
        $now = current_time('mysql');

        $naming = self::naming($document, $organization, $template, $recipient, $phone, $custom);
        Documents::replaceFields($document_id, $fields);
        $updated = Documents::transition($document_id, array('draft'), array_merge($recipient, $naming, array(
            'recipient_phone' => $phone !== '' ? $phone : null,
            'send_message' => $message,
            'delivery_channels' => $is_universign ? array('universign') : $channels,
            'signature_level' => $level,
            'template_id' => $template ? (int) $template['id'] : null,
            'detection_source' => $template ? 'template' : 'manual',
            'public_token_secret' => $is_universign ? null : Token::newSecret(),
            'status' => 'sent',
            'sent_at' => $now,
            'expires_at' => date('Y-m-d H:i:s', current_time('timestamp') + min($is_universign ? 60 : 365, $validity) * DAY_IN_SECONDS),
        )));

        if (!$updated) {
            throw new \RuntimeException(__('Ce document a déjà été envoyé.', 'smbb-signaconnect-proxima'));
        }

        $document = Documents::find($document_id);

        try {
            if ($is_universign) {
                $started = UniversignFlow::start($document, $organization, $auth);
                Documents::update($document_id, array(
                    'universign_transaction_id' => $started['transaction_id'],
                    'universign_document_id' => $started['document_id'],
                    'universign_last_event' => 'started',
                    'universign_last_event_at' => current_time('mysql'),
                ));
                $used = array('universign');
            } else {
                $used = Mailer::signatureRequest($document, $organization);
            }
        } catch (\Throwable $exception) {
            // Rien n'est parti : on revient au brouillon pour que l'expéditeur corrige et renvoie.
            Documents::update($document_id, array('status' => 'draft', 'sent_at' => null, 'expires_at' => null, 'signature_level' => Levels::INTERNAL));
            AuditLog::record('document_send_failed', $exception->getMessage(), array('level' => $level), $organization['id'], $document_id, 'user', $user_id);
            throw new \RuntimeException(sprintf(__("L'envoi a échoué : %s", 'smbb-signaconnect-proxima'), $exception->getMessage()));
        }

        if ($template) {
            global $wpdb;
            $wpdb->query($wpdb->prepare('UPDATE ' . Lookup::table('scp_template') . ' SET match_count = match_count + 1 WHERE id = %d', $template['id']));
        }

        $document = Documents::find($document_id);
        AuditLog::record('document_sent', sprintf(__('Document envoyé en signature %s.', 'smbb-signaconnect-proxima'), mb_strtolower(Levels::label($level))), array(
            'level' => $level,
            'channels' => $used,
            'auth' => $level === Levels::SIMPLE ? $auth : null,
            'recipient' => $recipient['recipient_name'],
            'email' => $recipient['recipient_email'],
            'phone' => $phone,
            'fields' => count($fields),
            'template' => $template ? $template['name'] : null,
            'transaction' => $document['universign_transaction_id'],
            'expires_at' => $document['expires_at'],
        ), $organization['id'], $document_id, 'user', $user_id);

        return $document;
    }

    public static function remind($user_id, $document_id, $automatic = false)
    {
        $document = $automatic ? Documents::find($document_id) : self::manageable($user_id, $document_id);

        if (!$document || !in_array($document['status'], array('sent', 'opened'), true) || ($automatic && $document['signature_level'] !== Levels::INTERNAL)) {
            throw new \RuntimeException(__("Ce document n'attend plus de signature.", 'smbb-signaconnect-proxima'));
        }

        $organization = Organizations::find($document['organization_id']);

        if (!$organization || Organizations::isBlocked($organization)) {
            throw new \RuntimeException(__("L'organisation est bloquée.", 'smbb-signaconnect-proxima'));
        }

        if ($document['signature_level'] !== Levels::INTERNAL) {
            UniversignFlow::remind($document);
            $used = array('universign');
        } else {
            $used = Mailer::signatureRequest($document, $organization, true);
        }
        Documents::update($document_id, array(
            'reminder_count' => (int) $document['reminder_count'] + 1,
            'last_reminder_at' => current_time('mysql'),
        ));
        AuditLog::record('document_reminded', $automatic ? __('Relance automatique envoyée.', 'smbb-signaconnect-proxima') : __('Relance envoyée.', 'smbb-signaconnect-proxima'), array('channels' => $used), $organization['id'], $document_id, $automatic ? 'system' : 'user', $automatic ? null : $user_id);

        return Documents::find($document_id);
    }

    public static function cancel($user_id, $document_id)
    {
        $document = self::manageable($user_id, $document_id);

        if (!in_array($document['status'], array('draft', 'sent', 'opened'), true)) {
            throw new \RuntimeException(__('Ce document ne peut plus être annulé.', 'smbb-signaconnect-proxima'));
        }

        if ($document['status'] !== 'draft' && !empty($document['universign_transaction_id'])) {
            // Universign d'abord : si la transaction vient d'être signée, l'annulation est refusée.
            UniversignFlow::cancel($document);
        }

        if (!Documents::transition($document_id, array('draft', 'sent', 'opened'), array('status' => 'cancelled', 'closed_at' => current_time('mysql')))) {
            throw new \RuntimeException(__('Ce document ne peut plus être annulé.', 'smbb-signaconnect-proxima'));
        }

        AuditLog::record('document_cancelled', __('Envoi annulé.', 'smbb-signaconnect-proxima'), array(), $document['organization_id'], $document_id, 'user', $user_id);

        return Documents::find($document_id);
    }

    /**
     * Modèles de l'organisation, au format attendu par l'éditeur de zones.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function templates($organization_id)
    {
        global $wpdb;

        $rows = $wpdb->get_results($wpdb->prepare(
            // SELECT * : les colonnes des réglages d'envoi (0.10.0) peuvent manquer tant que la table n'est pas à jour.
            'SELECT * FROM ' . Lookup::table('scp_template') . ' WHERE organization_id = %d AND is_active = 1 AND deleted_flag = 0 ORDER BY name ASC',
            (int) $organization_id
        ), ARRAY_A);
        $templates = array();

        foreach (is_array($rows) ? $rows : array() as $row) {
            $zones = json_decode((string) $row['field_zones'], true);
            $detect = json_decode((string) $row['detection_zones'], true);
            $settings = Templates::settings($row);
            $templates[] = array(
                'id' => (int) $row['id'],
                'name' => (string) $row['name'],
                'zones' => is_array($zones) ? array_values($zones) : array(),
                'detect' => is_array($detect) ? array_values($detect) : array(),
                'level' => (string) $row['suggested_level'],
                'levelEnforced' => (bool) $row['level_enforced'],
                'defaultMessage' => $settings['default_message'],
                'skipPreview' => $settings['skip_preview'],
                'customFields' => $settings['custom_fields'],
                'naming' => $settings['naming_pattern'] !== '',
            );
        }

        return $templates;
    }

    /**
     * Valeurs des champs perso du modèle, saisies à l'envoi : clé => {label, value}.
     * Les champs obligatoires vides ajoutent une erreur.
     *
     * @param array<string,mixed>|null $template
     * @param array<string,mixed>      $input
     * @param string[]                 $errors
     * @return array<string,array{label:string,value:string}>
     */
    private static function customValues($template, array $input, array &$errors)
    {
        $values = array();

        foreach ($template ? Templates::customFields($template) : array() as $field) {
            $value = isset($input[$field['key']]) ? mb_substr(trim(sanitize_text_field((string) $input[$field['key']])), 0, 190) : '';

            if ($value === '' && $field['required']) {
                $errors[] = sprintf(__('Renseignez « %s ».', 'smbb-signaconnect-proxima'), $field['label']);
            }

            if ($value !== '') {
                $values[$field['key']] = array('label' => $field['label'], 'value' => $value);
            }
        }

        return $values;
    }

    /**
     * Nom du PDF d'après la stratégie de nommage du modèle (ex. « Devis - [nom] - [N° de commande] ») :
     * le PDF envoyé à Universign et le PDF signé portent ce nom. Le nom d'origine est gardé à part.
     *
     * @return array<string,mixed> colonnes à mettre à jour
     */
    private static function naming(array $document, array $organization, $template, array $recipient, $phone, array $custom)
    {
        $columns = $custom ? array('custom_values' => wp_json_encode($custom, JSON_UNESCAPED_UNICODE)) : array();
        $pattern = $template ? Templates::settings($template)['naming_pattern'] : '';

        if (trim($pattern) === '') {
            return $columns;
        }

        $preview = array_merge($document, $recipient, array('recipient_phone' => $phone, 'custom_values' => $custom, 'sent_at' => current_time('mysql')));
        $name = self::cleanName(Placeholders::render($pattern, Placeholders::values($preview, $organization, $template)));

        if ($name === '') {
            return $columns;
        }

        return array_merge($columns, array(
            'filename' => $name . '.pdf',
            'source_filename' => empty($document['source_filename']) ? $document['filename'] : $document['source_filename'],
        ));
    }

    /**
     * Nom de fichier lisible : espaces et accents gardés, caractères interdits sous Windows retirés,
     * variables restées vides (« Devis -  - 42 ») resserrées.
     */
    public static function cleanName($name)
    {
        $name = preg_replace('#[\\\\/:*?"<>|\x00-\x1F]+#u', ' ', (string) $name);
        $name = preg_replace('/\[[^\[\]]*\]/u', '', $name);
        $name = preg_replace('/\s+/u', ' ', $name);
        $name = preg_replace('/(\s*[-_–,]\s*){2,}/u', ' - ', $name);
        $name = preg_replace('/\.pdf$/i', '', trim($name));
        $name = preg_replace('/^[\s\-_–,.]+|[\s\-_–,.]+$/u', '', $name);

        return mb_substr($name, 0, 180);
    }

    private static function template($organization_id, $template_id)
    {
        $template = Templates::find($template_id);

        return $template && (int) $template['organization_id'] === (int) $organization_id && empty($template['deleted_flag']) ? $template : null;
    }

    /**
     * Équipe limitée aux modèles connus : chaque envoi doit partir d'un modèle de l'organisation.
     */
    public static function templatesOnly($team_id)
    {
        $team = StampService::team($team_id);

        return $team && !empty($team['templates_only']);
    }

    public static function viewable($user_id, $document_id)
    {
        $document = Documents::find($document_id);

        if (!$document || !Memberships::canView($user_id, $document)) {
            throw new \RuntimeException(__('Document introuvable.', 'smbb-signaconnect-proxima'));
        }

        return $document;
    }

    private static function manageable($user_id, $document_id)
    {
        $document = self::viewable($user_id, $document_id);

        if (!Memberships::canManage($user_id, $document)) {
            throw new \RuntimeException(__("Seul l'expéditeur (ou l'admin de l'organisation) peut relancer ou annuler cet envoi.", 'smbb-signaconnect-proxima'));
        }

        return $document;
    }

    private static function ownedDraft($user_id, $document_id)
    {
        $document = Documents::find($document_id);

        if (!$document || (int) $document['sender_id'] !== (int) $user_id) {
            throw new \RuntimeException(__('Document introuvable.', 'smbb-signaconnect-proxima'));
        }

        if ($document['status'] !== 'draft') {
            throw new \RuntimeException(__('Ce document a déjà été envoyé.', 'smbb-signaconnect-proxima'));
        }

        return $document;
    }

    private static function sendableOrganization($organization_id)
    {
        $organization = Organizations::find($organization_id);

        if (!$organization || !Organizations::canSend($organization)) {
            throw new \RuntimeException(__("Les envois sont suspendus pour cette organisation. Contactez votre administrateur.", 'smbb-signaconnect-proxima'));
        }

        if (!Organizations::availableLevels($organization)) {
            throw new \RuntimeException(__("Aucun niveau de signature n'est disponible pour cette organisation. Contactez votre administrateur.", 'smbb-signaconnect-proxima'));
        }

        if (!Organizations::hasS3($organization)) {
            throw new \RuntimeException(__("Le stockage de l'organisation n'est pas configuré. Contactez votre administrateur.", 'smbb-signaconnect-proxima'));
        }

        return $organization;
    }

    public static function assertUpload(array $file)
    {
        if (!empty($file['error']) || empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
            throw new \RuntimeException(__("Le fichier n'a pas été reçu. Réessayez.", 'smbb-signaconnect-proxima'));
        }

        if ((int) $file['size'] > self::MAX_UPLOAD_BYTES) {
            throw new \RuntimeException(__('Le PDF dépasse 20 Mo.', 'smbb-signaconnect-proxima'));
        }

        $handle = fopen($file['tmp_name'], 'rb');
        $magic = $handle ? fread($handle, 5) : '';

        if ($handle) {
            fclose($handle);
        }

        if (strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION)) !== 'pdf' || $magic !== '%PDF-') {
            throw new \RuntimeException(__('Seuls les fichiers PDF sont acceptés.', 'smbb-signaconnect-proxima'));
        }
    }

    /**
     * Zones reçues de l'éditeur : types connus, ratios bornés à la page.
     *
     * @param mixed $raw JSON ou tableau
     * @return array<int,array<string,mixed>>
     */
    public static function cleanFields($raw, $page_count)
    {
        $items = is_array($raw) ? $raw : json_decode(wp_unslash((string) $raw), true);
        $fields = array();

        foreach (is_array($items) ? $items : array() as $item) {
            if (!is_array($item) || !in_array(isset($item['field_type']) ? $item['field_type'] : '', Pdf::FIELD_TYPES, true)) {
                continue;
            }

            $page = (int) (isset($item['page_number']) ? $item['page_number'] : 0);

            if ($page < 1 || $page > max(1, (int) $page_count)) {
                continue;
            }

            $x = min(1, max(0, (float) $item['x']));
            $y = min(1, max(0, (float) $item['y']));
            $w = min(1 - $x, max(0.01, (float) $item['width']));
            $h = min(1 - $y, max(0.005, (float) $item['height']));

            $fields[] = array(
                'page_number' => $page,
                'x' => sprintf('%.6F', $x),
                'y' => sprintf('%.6F', $y),
                'width' => sprintf('%.6F', $w),
                'height' => sprintf('%.6F', $h),
                'field_type' => $item['field_type'],
            );

            if (count($fields) >= 50) {
                break;
            }
        }

        return $fields;
    }
}
