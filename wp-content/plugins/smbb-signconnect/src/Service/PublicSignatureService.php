<?php

namespace Smbb\SignConnect\Service;

use Smbb\SignConnect\Repository\DocumentRepository;

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

    public function __construct(
        DocumentRepository $documents = null,
        S3DocumentAttachmentProvider $attachments = null,
        SignedPdfGenerator $signed_pdfs = null,
        IdentityPhotoAttachmentService $identity_photos = null
    ) {
        $this->documents = $documents ?: new DocumentRepository();
        $this->attachments = $attachments ?: new S3DocumentAttachmentProvider();
        $this->signed_pdfs = $signed_pdfs ?: new SignedPdfGenerator();
        $this->identity_photos = $identity_photos ?: new IdentityPhotoAttachmentService();
    }

    public function signFromPost($document_id, $token, array $post, array $files = array())
    {
        $document = $this->documents->findByPublicToken($document_id, $token);

        if (!$document) {
            return $this->error(__('Invalid signature link.', 'smbb-signconnect'), 404);
        }

        if ($this->isExpired($document)) {
            return $this->error(__('This signature link has expired.', 'smbb-signconnect'), 410);
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

        $this->notifyOwner($saved);

        return array(
            'success' => true,
            'message' => __('Document signed successfully.', 'smbb-signconnect'),
            'document' => $saved,
        );
    }

    private function notifyOwner(array $document)
    {
        $owner_id = isset($document['creation_by']) ? (int) $document['creation_by'] : 0;
        $owner = $owner_id > 0 ? get_userdata($owner_id) : false;

        if (!$owner || empty($owner->user_email)) {
            return;
        }

        $subject = sprintf(
            /* translators: %s: filename. */
            __('Document signed: %s', 'smbb-signconnect'),
            isset($document['filename']) ? (string) $document['filename'] : __('Document', 'smbb-signconnect')
        );
        $contact = isset($document['signer_contact']) ? (string) $document['signer_contact'] : '';
        $body = $contact !== ''
            ? sprintf(__('Your document has just been signed by %s.', 'smbb-signconnect'), $contact)
            : __('Your document has just been signed.', 'smbb-signconnect');

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

        wp_mail($owner->user_email, $subject, $body, array(), $attachments);

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
