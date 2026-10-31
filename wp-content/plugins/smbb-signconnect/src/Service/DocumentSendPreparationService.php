<?php

namespace Smbb\SignConnect\Service;

use Smbb\SignConnect\Repository\DocumentRepository;
use Smbb\SignConnect\Repository\DocumentAuditRepository;
use Smbb\SignConnect\Repository\SenderSignatureRepository;
use Smbb\SignConnect\Repository\SignatureFieldRepository;
use Smbb\SignConnect\Repository\StampRepository;
use Smbb\SignConnect\Support\DocumentStatus;
use Smbb\SignConnect\Support\DocumentCriticality;
use Smbb\SignConnect\Support\SignConnectSettings;
use Smbb\SignConnect\Support\SignatureFieldType;
use Smbb\SignConnect\User\UserStorageProfileFields;

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
    private $sender_signatures;
    private $stamps;
    private $audit;

    public function __construct(
        DocumentRepository $documents = null,
        DocumentSendDeliveryService $delivery = null,
        SignatureFieldRepository $signature_fields = null,
        SenderSignatureRepository $sender_signatures = null,
        StampRepository $stamps = null,
        DocumentAuditRepository $audit = null
    )
    {
        $this->documents = $documents ?: new DocumentRepository();
        $this->delivery = $delivery ?: new DocumentSendDeliveryService($this->documents);
        $this->signature_fields = $signature_fields ?: new SignatureFieldRepository();
        $this->sender_signatures = $sender_signatures ?: new SenderSignatureRepository();
        $this->stamps = $stamps ?: new StampRepository();
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
        $requires_sender_signature = $this->requiresSenderSignature($document_id);
        $validation = $this->validate($data);

        if (!$validation['success']) {
            return $validation;
        }

        if ($requires_sender_signature && !$this->isSignatureDataUrl($data['sender_signature_data'])) {
            return $this->error(__('Please sign as sender before sending this document.', 'smbb-signconnect'), 400);
        }

        if ($this->requiresSenderStamp($document_id) && !$this->senderHasStamp($user_id)) {
            return $this->error(__('Please select a sender stamp in your WordPress user profile before sending this document.', 'smbb-signconnect'), 400);
        }

        $saved = $this->documents->saveSendSettings($document_id, $user_id, array(
            'send_channel' => $data['send_channel'],
            'recipient_email' => $data['send_channel'] === 'email' ? $data['recipient_email'] : '',
            'recipient_phone' => $data['send_channel'] === 'sms' ? $data['recipient_phone'] : '',
            'send_message' => $data['send_message'],
            'require_identity_photo' => $data['require_identity_photo'],
            'return_expected' => $data['return_expected'],
            'criticality' => $this->effectiveCriticality($document, $data['criticality']),
            'certification_method' => $this->effectiveCertificationMethod($this->effectiveCriticality($document, $data['criticality'])),
            'link_expires_at' => $this->expirationDate($data['expiration_days']),
        ));

        if (!$saved) {
            return $this->error(__('The sending settings could not be saved.', 'smbb-signconnect'), 500);
        }

        if ($requires_sender_signature) {
            $sender_saved = $this->sender_signatures->saveForDocument($document_id, $user_id, $data['sender_signature_data'], $data['remember_sender_signature']);

            if (!$sender_saved) {
                return $this->error(__('The sender signature could not be saved.', 'smbb-signconnect'), 500);
            }
        }

        $this->audit->record($document_id, 'send_settings_saved', array(
            'channel' => $data['send_channel'],
            'return_expected' => $data['return_expected'],
            'criticality' => isset($saved['criticality']) ? (string) $saved['criticality'] : $this->effectiveCriticality($document, $data['criticality']),
            'certification_method' => isset($saved['certification_method']) ? (string) $saved['certification_method'] : $this->effectiveCertificationMethod($this->effectiveCriticality($document, $data['criticality'])),
            'expires_at' => isset($saved['link_expires_at']) ? (string) $saved['link_expires_at'] : '',
            'sender_signature_saved' => $requires_sender_signature ? 1 : 0,
            'sender_signature_remembered' => $requires_sender_signature && $data['remember_sender_signature'] ? 1 : 0,
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
            'criticality' => DocumentCriticality::normalize(isset($post['criticality']) ? (string) wp_unslash($post['criticality']) : ''),
            'expiration_days' => isset($post['expiration_days'])
                ? SignConnectSettings::clampExpiration($post['expiration_days'])
                : SignConnectSettings::defaultExpirationDays(),
            'sender_signature_data' => isset($post['sender_signature_data']) ? (string) wp_unslash($post['sender_signature_data']) : '',
            'remember_sender_signature' => !empty($post['remember_sender_signature']),
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

    private function requiresSenderSignature($document_id)
    {
        foreach ($this->signature_fields->listForDocument($document_id) as $field) {
            if (SignatureFieldType::requiresSenderSignature(isset($field['field_type']) ? $field['field_type'] : '')) {
                return true;
            }
        }

        return false;
    }

    private function requiresSenderStamp($document_id)
    {
        foreach ($this->signature_fields->listForDocument($document_id) as $field) {
            if (SignatureFieldType::normalize(isset($field['field_type']) ? $field['field_type'] : '') === SignatureFieldType::SENDER_STAMP) {
                return true;
            }
        }

        return false;
    }

    private function effectiveCriticality(array $document, $posted_criticality)
    {
        if (!empty($document['criticality_locked']) && !empty($document['criticality'])) {
            return DocumentCriticality::normalize((string) $document['criticality']);
        }

        return DocumentCriticality::normalize($posted_criticality);
    }

    private function effectiveCertificationMethod($criticality)
    {
        $method = SignConnectSettings::certificationMethodForCriticality($criticality);

        return SignConnectSettings::certificationMethodAvailable($method) ? $method : 'none';
    }

    private function senderHasStamp($user_id)
    {
        $stamp_id = (int) get_user_meta($user_id, UserStorageProfileFields::STAMP_META_KEY, true);

        return $stamp_id > 0 && $this->stamps->find($stamp_id);
    }

    private function isSignatureDataUrl($signature)
    {
        return is_string($signature)
            && preg_match('/^data:image\/png;base64,[a-zA-Z0-9+\/=]+$/', $signature) === 1
            && strlen($signature) > 200;
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
