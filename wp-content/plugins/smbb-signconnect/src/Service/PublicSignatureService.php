<?php

namespace Smbb\SignConnect\Service;

use Smbb\SignConnect\Repository\DocumentRepository;
use Smbb\SignConnect\Repository\DocumentAuditRepository;
use Smbb\SignConnect\Support\DocumentStatus;
use Smbb\SignConnect\Support\SignConnectSettings;

defined('ABSPATH') || exit;

/**
 * Valide et enregistre la signature recue cote signataire.
 */
final class PublicSignatureService
{
    private $documents;
    private $attachments;
    private $signed_pdfs;
    private $identity_photos;
    private $audit;

    public function __construct(
        DocumentRepository $documents = null,
        S3DocumentAttachmentProvider $attachments = null,
        SignedPdfGenerator $signed_pdfs = null,
        IdentityPhotoAttachmentService $identity_photos = null,
        DocumentAuditRepository $audit = null
    ) {
        $this->documents = $documents ?: new DocumentRepository();
        $this->attachments = $attachments ?: new S3DocumentAttachmentProvider();
        $this->signed_pdfs = $signed_pdfs ?: new SignedPdfGenerator();
        $this->identity_photos = $identity_photos ?: new IdentityPhotoAttachmentService();
        $this->audit = $audit ?: new DocumentAuditRepository();
    }

    public function signFromPost($document_id, $token, array $post, array $files = array())
    {
        if ($this->rateLimited($document_id)) {
            return $this->error(__('Too many attempts. Please wait a moment before trying again.', 'smbb-signconnect'), 429);
        }

        $document = $this->documents->findByPublicToken($document_id, $token);

        if (!$document) {
            $this->incrementRateLimit($document_id);
            return $this->error(__('Invalid signature link.', 'smbb-signconnect'), 404);
        }

        if ($this->isExpired($document)) {
            return $this->error(__('This signature link has expired.', 'smbb-signconnect'), 410);
        }

        if (!DocumentStatus::canPublicAnswer(isset($document['document_status']) ? $document['document_status'] : '')) {
            return $this->error(__('This document is not waiting for a signature anymore.', 'smbb-signconnect'), 409);
        }

        if (!empty($document['sign_date'])) {
            return $this->error(__('This document is already signed.', 'smbb-signconnect'), 409);
        }

        $signature = isset($post['signature_data']) ? (string) wp_unslash($post['signature_data']) : '';
        $return_expected = isset($document['return_expected']) && (string) $document['return_expected'] === 'approval_refusal' ? 'approval_refusal' : 'signature';
        $return_status = isset($post['signer_return_status']) ? sanitize_key((string) wp_unslash($post['signer_return_status'])) : 'signed';
        $return_status = in_array($return_status, array('signed', 'approved', 'refused'), true) ? $return_status : 'signed';
        $return_message = isset($post['signer_return_message']) ? sanitize_textarea_field((string) wp_unslash($post['signer_return_message'])) : '';

        if ($return_expected === 'approval_refusal' && $return_status === 'refused') {
            if ($return_message === '') {
                return $this->error(__('Please add a message to explain the refusal.', 'smbb-signconnect'), 400);
            }
        } elseif (!$this->isSignatureDataUrl($signature)) {
            return $this->error(__('Please sign in the expected area.', 'smbb-signconnect'), 400);
        }

        $contact = !empty($document['recipient_email'])
            ? (string) $document['recipient_email']
            : (isset($document['recipient_phone']) ? (string) $document['recipient_phone'] : '');
        $signed_at = current_time('mysql');
        $identity = $this->signerIdentity($post);

        if ($identity['first_name'] === '' || $identity['last_name'] === '') {
            return $this->error(__('Please enter your first and last name.', 'smbb-signconnect'), 400);
        }

        if ($return_status !== 'refused' && $identity['place'] === '') {
            return $this->error(__('Please enter the signing place or use geolocation.', 'smbb-signconnect'), 400);
        }

        $identity_photo = array();

        try {
            if (!empty($document['require_identity_photo']) && $return_status !== 'refused') {
                $identity_file = isset($files['identity_photo']) && is_array($files['identity_photo']) ? $files['identity_photo'] : array();
                $identity_photo = $this->identity_photos->upload($document, $identity_file);
            }

            $signed_pdf = $this->signed_pdfs->generateAndUpload($document, $signature, $contact, $signed_at, $identity, array(
                'status' => $return_status,
                'message' => $return_message,
            ), $identity_photo);
        } catch (\Throwable $exception) {
            return $this->error($exception->getMessage(), 500);
        }

        $saved = $this->documents->markSigned($document_id, $token, array(
            'sign_date' => $signed_at,
            'signer_contact' => $contact,
            'signer_first_name' => $identity['first_name'],
            'signer_last_name' => $identity['last_name'],
            'signer_place' => $identity['place'],
            'signer_return_status' => $return_status,
            'signer_return_message' => $return_message,
            'signer_latitude' => $identity['latitude'],
            'signer_longitude' => $identity['longitude'],
            'signature_data' => $signature,
            'signer_ip' => isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field((string) wp_unslash($_SERVER['REMOTE_ADDR'])) : '',
            'signer_user_agent' => isset($_SERVER['HTTP_USER_AGENT']) ? substr(sanitize_text_field((string) wp_unslash($_SERVER['HTTP_USER_AGENT'])), 0, 255) : '',
            'signed_storage_path' => isset($signed_pdf['storage_path']) ? (string) $signed_pdf['storage_path'] : '',
            'signed_file_size' => isset($signed_pdf['file_size']) ? (int) $signed_pdf['file_size'] : 0,
            'signed_pdf_date' => $signed_at,
            'identity_photo_storage_path' => isset($identity_photo['storage_path']) ? (string) $identity_photo['storage_path'] : '',
            'identity_photo_file_size' => isset($identity_photo['file_size']) ? (int) $identity_photo['file_size'] : 0,
            'identity_photo_mime_type' => isset($identity_photo['mime_type']) ? (string) $identity_photo['mime_type'] : '',
            'identity_photo_filename' => isset($identity_photo['filename']) ? (string) $identity_photo['filename'] : '',
        ));

        if (!$saved) {
            return $this->error(__('The signature could not be saved.', 'smbb-signconnect'), 500);
        }

        $this->audit->record($document_id, $return_status === 'refused' ? 'refused' : 'signed', array(
            'contact' => $contact,
            'first_name' => $identity['first_name'],
            'last_name' => $identity['last_name'],
            'place' => $identity['place'],
            'return_status' => $return_status,
            'signed_at' => $signed_at,
            'ip' => isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field((string) wp_unslash($_SERVER['REMOTE_ADDR'])) : '',
            'has_identity_photo' => !empty($identity_photo),
        ), 'signer', null, $return_status === 'refused' ? __('Document refused.', 'smbb-signconnect') : __('Document signed.', 'smbb-signconnect'));

        $this->notifyOwner($saved);

        return array(
            'success' => true,
            'message' => __('Your response has been transmitted.', 'smbb-signconnect'),
            'document' => $saved,
            'proof' => $this->proofSummary($saved),
        );
    }

    private function notifyOwner(array $document)
    {
        $owner_id = isset($document['creation_by']) ? (int) $document['creation_by'] : 0;
        $owner = $owner_id > 0 ? get_userdata($owner_id) : false;

        if (!$owner || empty($owner->user_email)) {
            return;
        }

        $is_refused = isset($document['document_status']) && (string) $document['document_status'] === DocumentStatus::REFUSED;
        $subject = sprintf(
            /* translators: %s: filename. */
            $is_refused ? __('Document refused: %s', 'smbb-signconnect') : __('Document signed: %s', 'smbb-signconnect'),
            isset($document['filename']) ? (string) $document['filename'] : __('Document', 'smbb-signconnect')
        );
        $contact = isset($document['signer_contact']) ? (string) $document['signer_contact'] : '';
        $body = $this->ownerNotificationBody($document, $contact, $is_refused);

        $attachment_path = '';
        $attachments = array();

        try {
            $attachment_path = $this->attachments->temporaryCopy($document);

            if ($attachment_path !== '') {
                $attachments[] = $attachment_path;
            }
        } catch (\Throwable $exception) {
            // La notification ne doit pas bloquer la signature si S3 est temporairement indisponible.
            $attachment_path = '';
        }

        wp_mail($owner->user_email, $subject, $body, array('Content-Type: text/html; charset=UTF-8'), $attachments);

        if ($attachment_path !== '' && file_exists($attachment_path)) {
            @unlink($attachment_path);
        }
    }

    private function isExpired(array $document)
    {
        if (empty($document['link_expires_at'])) {
            return false;
        }

        $expires_at = strtotime((string) $document['link_expires_at']);

        return $expires_at && $expires_at <= current_time('timestamp');
    }

    private function ownerNotificationBody(array $document, $contact, $is_refused)
    {
        $brand = SignConnectSettings::brandColor();
        $filename = !empty($document['filename']) ? (string) $document['filename'] : __('Document', 'smbb-signconnect');
        $title = $is_refused ? __('Document refused', 'smbb-signconnect') : __('Document signed', 'smbb-signconnect');
        $summary = $contact !== ''
            ? sprintf($is_refused ? __('The recipient replied to %s.', 'smbb-signconnect') : __('The document was signed by %s.', 'smbb-signconnect'), $contact)
            : ($is_refused ? __('The recipient refused the document.', 'smbb-signconnect') : __('The document was signed.', 'smbb-signconnect'));

        $proof = $this->proofSummary($document);
        $status_color = $is_refused ? '#cc1818' : $brand;

        $html = '<!doctype html><html><body style="margin:0;padding:0;background:#f3f5f7;font-family:Arial,Helvetica,sans-serif;color:#1d2327;">';
        $html .= '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f3f5f7;padding:32px 12px;"><tr><td align="center">';
        $html .= '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:620px;background:#ffffff;border:1px solid #dfe3e8;border-radius:10px;overflow:hidden;box-shadow:0 10px 28px rgba(15,23,42,0.08);">';
        $html .= '<tr><td style="padding:28px 30px 24px;border-top:5px solid ' . esc_attr($status_color) . ';border-bottom:1px solid #eef0f2;">';
        $html .= '<div style="font-size:12px;letter-spacing:.08em;text-transform:uppercase;color:#6b7280;font-weight:700;">SignConnect</div>';
        $html .= '<h1 style="margin:8px 0 0;font-size:26px;line-height:1.2;color:#111827;">' . esc_html($title) . '</h1>';
        $html .= '<p style="margin:10px 0 0;color:#6b7280;font-size:14px;">' . esc_html__('The recipient response has been recorded.', 'smbb-signconnect') . '</p>';
        $html .= '</td></tr>';
        $html .= '<tr><td style="padding:28px 30px;">';
        $html .= '<p style="margin:0 0 20px;font-size:16px;line-height:1.55;">' . esc_html($summary) . '</p>';
        $html .= '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:0 0 22px;border:1px solid #e5e7eb;border-radius:8px;background:#fbfcfd;">';
        $html .= '<tr><td style="padding:14px 16px;color:#6b7280;font-size:13px;">' . esc_html__('Document', 'smbb-signconnect') . '</td><td style="padding:14px 16px;text-align:right;font-size:14px;font-weight:700;">' . esc_html($filename) . '</td></tr>';

        foreach ($proof as $label => $value) {
            if ($value === '') {
                continue;
            }

            $html .= '<tr><td style="padding:0 16px 12px;color:#6b7280;font-size:13px;">' . esc_html($label) . '</td><td style="padding:0 16px 12px;text-align:right;font-size:14px;">' . esc_html($value) . '</td></tr>';
        }

        $html .= '</table>';

        if ($is_refused && !empty($document['signer_return_message'])) {
            $html .= '<div style="margin:18px 0;padding:14px 16px;background:#fff8f8;border-left:4px solid #cc1818;border-radius:4px;">' . nl2br(esc_html((string) $document['signer_return_message'])) . '</div>';
        }

        $html .= '<p style="margin:20px 0 0;color:#6b7280;font-size:13px;line-height:1.5;">' . esc_html__('The final PDF is attached to this email when available.', 'smbb-signconnect') . '</p>';
        $html .= '</td></tr></table></td></tr></table></body></html>';

        return $html;
    }

    private function proofSummary(array $document)
    {
        $signed_at = !empty($document['sign_date'])
            ? mysql2date(get_option('date_format') . ' ' . get_option('time_format'), (string) $document['sign_date'])
            : '';
        $name = trim((isset($document['signer_first_name']) ? (string) $document['signer_first_name'] : '') . ' ' . (isset($document['signer_last_name']) ? (string) $document['signer_last_name'] : ''));
        $status = isset($document['signer_return_status']) ? (string) $document['signer_return_status'] : '';

        if ($status === 'approved') {
            $status_label = __('Good for approval', 'smbb-signconnect');
        } elseif ($status === 'refused') {
            $status_label = __('Refusal', 'smbb-signconnect');
        } else {
            $status_label = __('Signature', 'smbb-signconnect');
        }

        return array(
            __('Response', 'smbb-signconnect') => $status_label,
            __('Signer', 'smbb-signconnect') => $name,
            __('Contact', 'smbb-signconnect') => isset($document['signer_contact']) ? (string) $document['signer_contact'] : '',
            __('Signed on', 'smbb-signconnect') => $signed_at,
            __('Place', 'smbb-signconnect') => isset($document['signer_place']) ? (string) $document['signer_place'] : '',
            __('IP address', 'smbb-signconnect') => isset($document['signer_ip']) ? (string) $document['signer_ip'] : '',
        );
    }

    private function rateLimited($document_id)
    {
        $count = (int) get_transient($this->rateLimitKey($document_id));

        return $count >= 8;
    }

    private function incrementRateLimit($document_id)
    {
        $key = $this->rateLimitKey($document_id);
        $count = (int) get_transient($key);
        set_transient($key, $count + 1, 15 * MINUTE_IN_SECONDS);
    }

    private function rateLimitKey($document_id)
    {
        $ip = isset($_SERVER['REMOTE_ADDR']) ? (string) wp_unslash($_SERVER['REMOTE_ADDR']) : 'unknown';

        return 'smbb_signconnect_public_attempt_' . absint($document_id) . '_' . md5($ip);
    }

    private function isSignatureDataUrl($signature)
    {
        return is_string($signature)
            && preg_match('/^data:image\/png;base64,[a-zA-Z0-9+\/=]+$/', $signature) === 1
            && strlen($signature) > 200;
    }

    private function signerIdentity(array $post)
    {
        $place = isset($post['signer_place']) ? sanitize_text_field((string) wp_unslash($post['signer_place'])) : '';
        $latitude = isset($post['signer_latitude']) ? sanitize_text_field((string) wp_unslash($post['signer_latitude'])) : '';
        $longitude = isset($post['signer_longitude']) ? sanitize_text_field((string) wp_unslash($post['signer_longitude'])) : '';

        if ($place === '' && $latitude !== '' && $longitude !== '') {
            $place = 'gps';
        }

        return array(
            'first_name' => isset($post['signer_first_name']) ? sanitize_text_field((string) wp_unslash($post['signer_first_name'])) : '',
            'last_name' => isset($post['signer_last_name']) ? sanitize_text_field((string) wp_unslash($post['signer_last_name'])) : '',
            'place' => $place,
            'latitude' => is_numeric($latitude) ? (string) $latitude : '',
            'longitude' => is_numeric($longitude) ? (string) $longitude : '',
        );
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
