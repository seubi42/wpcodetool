<?php

namespace Smbb\SignConnect\Service;

use Smbb\SignConnect\Repository\DocumentRepository;
use Smbb\SignConnect\Repository\DocumentAuditRepository;
use Smbb\SignConnect\Repository\SignatureFieldRepository;
use Smbb\SignConnect\Support\DocumentStatus;
use Smbb\SignConnect\Support\SignConnectSettings;

defined('ABSPATH') || exit;

/**
 * Prepare les informations d\'envoi d'un document.
 *
 * Cette classe concentre la logique metier de l'etape 3 :
 * - lecture et nettoyage des champs POST ;
 * - validation du canal choisi ;
 * - calcul de la date d'expiration ;
 * - persistance sur signdocument.
 *
 * Le shortcode reste ainsi une couche WordPress/AJAX, et l'envoi réel email/SMS pourra
 * se brancher ici plus tard sans gonfler le renderer front.
 */
final class DocumentSendPreparationService
{
    private $documents;
    private $delivery;
    private $signature_fields;
    private $audit;

    public function __construct(
        DocumentRepository $documents = null,
        DocumentSendDeliveryService $delivery = null,
        SignatureFieldRepository $signature_fields = null,
        DocumentAuditRepository $audit = null
    )
    {
        $this->documents = $documents ?: new DocumentRepository();
        $this->delivery = $delivery ?: new DocumentSendDeliveryService($this->documents);
        $this->signature_fields = $signature_fields ?: new SignatureFieldRepository();
        $this->audit = $audit ?: new DocumentAuditRepository();
    }

    public function prepareFromPost($document_id, $user_id, array $post)
    {
        $document = $this->documents->findOwnedByUser($document_id, $user_id);

        if (!$document) {
            return $this->error(__('Document not found or inaccessible.', 'smbb-signconnect'), 404);
        }

        if (!DocumentStatus::canPrepareSend(isset($document['document_status']) ? $document['document_status'] : '')) {
            return $this->error(__('This document can no longer be sent.', 'smbb-signconnect'), 409);
        }

        if (count($this->signature_fields->listForDocument($document_id)) < 1) {
            return $this->error(__('Please define at least one signature area before sending.', 'smbb-signconnect'), 400);
        }

        $data = $this->sanitizePostData($post);
        $validation = $this->validate($data);

        if (!$validation['success']) {
            return $validation;
        }

        $saved = $this->documents->saveSendSettings($document_id, $user_id, array(
            'send_channel' => $data['send_channel'],
            'recipient_email' => $data['send_channel'] === 'email' ? $data['recipient_email'] : '',
            'recipient_phone' => $data['send_channel'] === 'sms' ? $data['recipient_phone'] : '',
            'send_message' => $data['send_message'],
            'require_identity_photo' => $data['require_identity_photo'],
            'return_expected' => $data['return_expected'],
            'link_expires_at' => $this->expirationDate($data['expiration_days']),
        ));

        if (!$saved) {
            return $this->error(__('The sending settings could not be saved.', 'smbb-signconnect'), 500);
        }

        $this->audit->record($document_id, 'send_settings_saved', array(
            'channel' => $data['send_channel'],
            'return_expected' => $data['return_expected'],
            'expires_at' => isset($saved['link_expires_at']) ? (string) $saved['link_expires_at'] : '',
        ), 'owner', $user_id, __('Sending settings saved.', 'smbb-signconnect'));

        try {
            $delivery = $this->delivery->deliver($saved, $user_id);
            $saved = isset($delivery['document']) && is_array($delivery['document']) ? $delivery['document'] : $saved;
        } catch (\Throwable $exception) {
            return $this->error($exception->getMessage(), 500);
        }

        return array(
            'success' => true,
            'message' => __('Signature link sent.', 'smbb-signconnect'),
            'document' => $saved,
            'status' => isset($saved['document_status']) ? (string) $saved['document_status'] : 'ready_to_send',
        );
    }

    private function sanitizePostData(array $post)
    {
        // Le canal pilote quel champ destinataire est obligatoire et persiste.
        $send_channel = isset($post['send_channel']) ? sanitize_key((string) wp_unslash($post['send_channel'])) : 'email';
        $send_channel = in_array($send_channel, array('email', 'sms'), true) ? $send_channel : 'email';

        if ($send_channel === 'sms' && !SignConnectSettings::twilioConfigured()) {
            $send_channel = 'email';
        }

        return array(
            'send_channel' => $send_channel,
            'recipient_email' => isset($post['recipient_email']) ? sanitize_email((string) wp_unslash($post['recipient_email'])) : '',
            'recipient_phone' => isset($post['recipient_phone']) ? sanitize_text_field((string) wp_unslash($post['recipient_phone'])) : '',
            'send_message' => isset($post['send_message']) ? sanitize_textarea_field((string) wp_unslash($post['send_message'])) : '',
            'require_identity_photo' => !empty($post['require_identity_photo']),
            'return_expected' => isset($post['return_expected']) && (string) wp_unslash($post['return_expected']) === 'approval_refusal' ? 'approval_refusal' : 'signature',
            'expiration_days' => isset($post['expiration_days'])
                ? SignConnectSettings::clampExpiration($post['expiration_days'])
                : SignConnectSettings::defaultExpirationDays(),
        );
    }

    private function validate(array $data)
    {
        if ($data['send_channel'] === 'email' && ($data['recipient_email'] === '' || !is_email($data['recipient_email']))) {
            return $this->error(__('Please enter a valid email address.', 'smbb-signconnect'), 400);
        }

        if ($data['send_channel'] === 'sms' && $data['recipient_phone'] === '') {
            return $this->error(__('Please enter a phone number.', 'smbb-signconnect'), 400);
        }

        return array('success' => true);
    }

    private function expirationDate($expiration_days)
    {
        /*
         * current_time('timestamp') respecte le fuseau WordPress.
         * On stocke ensuite une datetime SQL locale, comme les autres champs du plugin.
         */
        return date('Y-m-d H:i:s', current_time('timestamp') + ((int) $expiration_days * DAY_IN_SECONDS));
    }

    private function error($message, $status)
    {
        return array(
            'success' => false,
            'message' => (string) $message,
            'status_code' => (int) $status,
        );
    }
}
