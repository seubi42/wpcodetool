<?php

namespace Smbb\SignConnectProxima\Service;

use Smbb\SignConnectProxima\Repository\AuditLog;
use Smbb\SignConnectProxima\Repository\Documents;
use Smbb\SignConnectProxima\Repository\Organizations;
use Smbb\SignConnectProxima\Support\Levels;
use Smbb\SignConnectProxima\Support\Token;
use Smbb\SignConnectProxima\Support\UserPrefs;

defined('ABSPATH') || exit;

if (!function_exists('wp_tempnam')) {
    require_once ABSPATH . 'wp-admin/includes/file.php';
}

/**
 * Parcours du signataire (signature interne) : ouverture du lien, signature ou refus.
 */
final class SigningService
{
    const MAX_SIGNATURE_BYTES = 1048576;

    /**
     * Charge le document d'un lien public.
     *
     * @return array{state:string,document:array|null,organization:array|null}
     *         state : invalid, blocked, expired, pending, signed, refused, closed
     */
    public static function resolve($encoded_id, $token)
    {
        $document_id = Token::decodeId($encoded_id);
        $document = $document_id > 0 ? Documents::find($document_id) : null;

        if (!$document || $document['signature_level'] !== Levels::INTERNAL || !Token::verify($document_id, $document['public_token_secret'], $token)) {
            return array('state' => 'invalid', 'document' => null, 'organization' => null);
        }

        $organization = Organizations::find($document['organization_id']);

        if (!$organization) {
            return array('state' => 'invalid', 'document' => null, 'organization' => null);
        }

        $state = 'closed';

        if (in_array($document['status'], array('sent', 'opened'), true)) {
            if (Organizations::isBlocked($organization)) {
                $state = 'blocked';
            } elseif (!empty($document['expires_at']) && strtotime($document['expires_at']) <= current_time('timestamp')) {
                $state = 'expired';
            } else {
                $state = 'pending';
            }
        } elseif ($document['status'] === 'signed') {
            $state = empty($document['anonymized_at']) ? 'signed' : 'closed';
        } elseif ($document['status'] === 'refused') {
            $state = 'refused';
        } elseif ($document['status'] === 'expired') {
            $state = 'expired';
        }

        return array('state' => $state, 'document' => $document, 'organization' => $organization);
    }

    public static function markOpened(array $document)
    {
        if ($document['status'] !== 'sent') {
            return;
        }

        if (Documents::transition($document['id'], array('sent'), array('status' => 'opened', 'opened_at' => current_time('mysql')))) {
            AuditLog::record('document_opened', __('Lien de signature ouvert par le signataire.', 'smbb-signaconnect-proxima'), array(
                'user_agent' => self::userAgent(),
            ), $document['organization_id'], $document['id'], 'signer');
        }
    }

    public static function sign(array $document, array $organization, array $input)
    {
        self::guardAttempts($document['id']);
        $signature = isset($input['signature']) ? (string) $input['signature'] : '';
        $name = trim(sanitize_text_field(isset($input['signer_name']) ? $input['signer_name'] : ''));

        if ($name === '') {
            throw new \InvalidArgumentException(__('Indiquez votre nom complet.', 'smbb-signaconnect-proxima'));
        }

        if (empty($input['consent'])) {
            throw new \InvalidArgumentException(__('Cochez la case pour confirmer que vous acceptez de signer ce document.', 'smbb-signaconnect-proxima'));
        }

        if (strlen($signature) > self::MAX_SIGNATURE_BYTES || !preg_match('#^data:image/png;base64,([A-Za-z0-9+/=]{200,})$#', $signature, $match)) {
            throw new \InvalidArgumentException(__('Dessinez votre signature dans le cadre.', 'smbb-signaconnect-proxima'));
        }

        $png = base64_decode($match[1], true);

        if ($png === false || strpos($png, "\x89PNG") !== 0) {
            throw new \InvalidArgumentException(__('Signature illisible, recommencez.', 'smbb-signaconnect-proxima'));
        }

        $storage = Storage::forDocument($document, $organization);
        $signed_at = current_time('mysql');
        $ip = self::ip();
        $source = '';
        $signature_png = '';
        $signed_pdf = wp_tempnam('scp-signed.pdf');
        $stamp = StampService::path($organization, isset($document['team_id']) ? (int) $document['team_id'] : 0);
        $sender = !empty($document['sender_id']) ? get_userdata((int) $document['sender_id']) : false;
        $sender_signature = '';

        try {
            $sender_signature = $sender ? UserPrefs::signatureFile($sender->ID) : '';
            $source = $storage->download($document['source_storage_path']);
            $source_hash = hash_file('sha256', $source);

            if (!empty($document['source_sha256']) && !hash_equals($document['source_sha256'], $source_hash)) {
                throw new \RuntimeException(__('Le document stocké ne correspond plus au document envoyé.', 'smbb-signaconnect-proxima'));
            }

            $signature_png = wp_tempnam('scp-signature.png');
            file_put_contents($signature_png, $png);

            Pdf::sign($source, $signed_pdf, Documents::fields($document['id']), array(
                'title' => $document['filename'],
                'signature_png' => $signature_png,
                'signer_name' => $name,
                'signed_at' => $signed_at,
                'place' => Organizations::city($organization, isset($document['team_id']) ? (int) $document['team_id'] : 0),
                'stamp_path' => is_string($stamp) ? $stamp : '',
                'stamp_name' => (string) $organization['name'],
                'sender_name' => $sender ? $sender->display_name : '',
                'sender_signature_png' => $sender_signature,
                'brand_color' => Organizations::brandColor($organization),
                'proof' => array(
                    'Document' => $document['filename'],
                    'Référence' => 'SC-' . $document['id'],
                    'Niveau' => Levels::label(Levels::INTERNAL) . ' : ' . Levels::description(Levels::INTERNAL),
                    'Organisation' => $organization['name'],
                    'Expéditeur' => $sender ? $sender->display_name . ' <' . $sender->user_email . '>' : '',
                    'Signataire' => $name,
                    'Contact du signataire' => trim($document['recipient_email'] . ' ' . $document['recipient_phone']),
                    'Lien envoyé par' => implode(', ', array_map(function ($channel) {
                        return $channel === 'sms' ? 'SMS' : 'email';
                    }, $document['delivery_channels'])),
                    'Envoyé le' => self::date($document['sent_at']),
                    'Ouvert le' => self::date($document['opened_at']),
                    'Signé le' => self::date($signed_at) . ' (' . wp_timezone_string() . ')',
                    'Adresse IP' => $ip,
                    'Navigateur' => self::userAgent(),
                    'SHA-256 du PDF original' => $source_hash,
                ),
            ));

            $signed_hash = hash_file('sha256', $signed_pdf);
            $signed_key = preg_replace('/-source-/', '-signed-', $document['source_storage_path'], 1);
            $signed_key = $signed_key !== $document['source_storage_path'] ? $signed_key : Storage::documentKey($organization['id'], $document['id'], 'signed');
            $storage->putFile($signed_key, $signed_pdf);

            $done = Documents::transition($document['id'], array('sent', 'opened'), array(
                'status' => 'signed',
                'signed_at' => $signed_at,
                'closed_at' => $signed_at,
                'signer_name' => mb_substr($name, 0, 190),
                'signer_ip' => $ip,
                'signer_user_agent' => mb_substr(self::userAgent(), 0, 255),
                'signed_sha256' => $signed_hash,
                'signed_storage_path' => $signed_key,
                'billable' => 1,
                'billing_month' => substr($signed_at, 0, 7),
            ));

            if (!$done) {
                $storage->delete($signed_key);
                throw new \RuntimeException(__('Ce document a déjà été traité.', 'smbb-signaconnect-proxima'));
            }

            AuditLog::record('document_signed', __('Document signé (niveau Simple).', 'smbb-signaconnect-proxima'), array(
                'signer' => $name,
                'ip' => $ip,
                'user_agent' => self::userAgent(),
                'source_sha256' => $source_hash,
                'signed_sha256' => $signed_hash,
            ), $organization['id'], $document['id'], 'signer');

            $document = Documents::find($document['id']);
            self::notify(function () use ($document, $organization, $signed_pdf) {
                Mailer::signedToSigner($document, $organization, $signed_pdf);
            }, $document, 'signer');
            self::notify(function () use ($document, $organization, $signed_pdf) {
                Mailer::signedToSender($document, $organization, $signed_pdf);
            }, $document, 'sender');

            return $document;
        } finally {
            foreach (array($source, $signature_png, $signed_pdf, $sender_signature) as $path) {
                if ($path && file_exists($path)) {
                    @unlink($path);
                }
            }
        }
    }

    public static function refuse(array $document, array $organization, array $input)
    {
        self::guardAttempts($document['id']);
        $message = trim(sanitize_textarea_field(isset($input['message']) ? $input['message'] : ''));
        $name = trim(sanitize_text_field(isset($input['signer_name']) ? $input['signer_name'] : ''));

        if ($message === '') {
            throw new \InvalidArgumentException(__('Expliquez en quelques mots pourquoi vous refusez.', 'smbb-signaconnect-proxima'));
        }

        $now = current_time('mysql');

        if (!Documents::transition($document['id'], array('sent', 'opened'), array(
            'status' => 'refused',
            'closed_at' => $now,
            'refusal_message' => mb_substr($message, 0, 2000),
            'signer_name' => $name !== '' ? mb_substr($name, 0, 190) : null,
            'signer_ip' => self::ip(),
            'signer_user_agent' => mb_substr(self::userAgent(), 0, 255),
        ))) {
            throw new \RuntimeException(__('Ce document a déjà été traité.', 'smbb-signaconnect-proxima'));
        }

        AuditLog::record('document_refused', __('Signature refusée par le signataire.', 'smbb-signaconnect-proxima'), array(
            'message' => $message,
            'ip' => self::ip(),
        ), $organization['id'], $document['id'], 'signer');

        $document = Documents::find($document['id']);
        self::notify(function () use ($document, $organization) {
            Mailer::refusedToSender($document, $organization);
        }, $document, 'sender');

        return $document;
    }

    /**
     * Les notifications ne doivent jamais faire échouer une signature déjà enregistrée.
     */
    private static function notify(callable $send, array $document, $who)
    {
        try {
            $send();
        } catch (\Throwable $exception) {
            AuditLog::record('notification_failed', $exception->getMessage(), array('to' => $who), $document['organization_id'], $document['id'], 'system');
        }
    }

    /**
     * 10 tentatives par document et par IP sur 15 minutes.
     */
    private static function guardAttempts($document_id)
    {
        $key = 'scp_sign_' . absint($document_id) . '_' . md5(self::ip());
        $count = (int) get_transient($key);

        if ($count >= 10) {
            throw new \RuntimeException(__('Trop de tentatives. Patientez quelques minutes.', 'smbb-signaconnect-proxima'));
        }

        set_transient($key, $count + 1, 15 * MINUTE_IN_SECONDS);
    }

    private static function date($value)
    {
        return $value ? mysql2date('d/m/Y H:i:s', $value) : '';
    }

    public static function ip()
    {
        return isset($_SERVER['REMOTE_ADDR']) ? substr(sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])), 0, 64) : '';
    }

    private static function userAgent()
    {
        return isset($_SERVER['HTTP_USER_AGENT']) ? substr(sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])), 0, 255) : '';
    }
}
