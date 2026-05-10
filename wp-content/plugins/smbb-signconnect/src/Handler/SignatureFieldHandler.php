<?php

namespace Smbb\SignConnect\Handler;

use Smbb\SignConnect\Repository\SignatureFieldRepository;
use Smbb\SignConnect\Repository\DocumentRepository;
use Smbb\SignConnect\Repository\DocumentAuditRepository;
use Smbb\SignConnect\Service\DocumentAccessService;
use Smbb\SignConnect\Support\DocumentStatus;

defined('ABSPATH') || exit;

final class SignatureFieldHandler
{
    private $signature_fields;
    private $access;
    private $documents;
    private $audit;

    public function __construct(
        SignatureFieldRepository $signature_fields = null,
        DocumentAccessService $access = null,
        DocumentRepository $documents = null,
        DocumentAuditRepository $audit = null
    )
    {
        $this->signature_fields = $signature_fields ?: new SignatureFieldRepository();
        $this->access = $access ?: new DocumentAccessService();
        $this->documents = $documents ?: new DocumentRepository();
        $this->audit = $audit ?: new DocumentAuditRepository();
    }

    public function handleSaveSignatureField()
    {
        if (!is_user_logged_in()) {
            wp_send_json_error(array('message' => __('Please sign in.', 'smbb-signconnect')), 401);
        }

        $document_id = isset($_POST['document_id']) ? absint($_POST['document_id']) : 0;

        if ($document_id < 1 || !check_ajax_referer('smbb_signconnect_signature_field_' . $document_id, '_wpnonce', false)) {
            wp_send_json_error(array('message' => __('Session expired. Please reload the page.', 'smbb-signconnect')), 403);
        }

        if (!$this->access->authorizedDocument($document_id)) {
            wp_send_json_error(array('message' => __('Document not found or inaccessible.', 'smbb-signconnect')), 404);
        }

        $document = $this->documents->findOwnedByUser($document_id, get_current_user_id());
        $status = $document ? DocumentStatus::normalize(isset($document['document_status']) ? $document['document_status'] : '') : DocumentStatus::DRAFT;

        if (!in_array($status, array(DocumentStatus::DRAFT, DocumentStatus::ZONE_READY, DocumentStatus::READY_TO_SEND), true)) {
            wp_send_json_error(array('message' => __('This document can no longer be edited.', 'smbb-signconnect')), 409);
        }

        $fields_json = isset($_POST['fields']) ? wp_unslash((string) $_POST['fields']) : '[]';
        $fields = json_decode($fields_json, true);

        if (!is_array($fields)) {
            wp_send_json_error(array('message' => __('Signature areas are invalid.', 'smbb-signconnect')), 400);
        }

        $field_ids = $this->signature_fields->saveForDocument($document_id, $fields);

        if ($field_ids === false) {
            wp_send_json_error(array('message' => __('The signature area could not be saved.', 'smbb-signconnect')), 500);
        }

        $field_count = count(array_filter($field_ids, static function ($field_id) {
            return (int) $field_id > 0;
        }));

        if ($field_count > 0) {
            $this->documents->markZoneReady($document_id, get_current_user_id());
        }

        $this->audit->record($document_id, 'signature_fields_saved', array(
            'field_count' => $field_count,
        ), 'owner', get_current_user_id(), __('Signature areas saved.', 'smbb-signconnect'));

        wp_send_json_success(array(
            'message' => __('Signature areas saved.', 'smbb-signconnect'),
            'field_ids' => array_values(array_map('intval', $field_ids)),
            'fields' => array_map(static function ($field) {
                return array(
                    'id' => isset($field['id']) ? (int) $field['id'] : 0,
                    'page_number' => (int) $field['page_number'],
                    'x' => (float) $field['x'],
                    'y' => (float) $field['y'],
                    'width' => (float) $field['width'],
                    'height' => (float) $field['height'],
                    'field_type' => isset($field['field_type']) ? (string) $field['field_type'] : 'signature',
                    'label' => isset($field['label']) ? (string) $field['label'] : __('Signature', 'smbb-signconnect'),
                );
            }, $this->signature_fields->listForDocument($document_id)),
        ));
    }
}
