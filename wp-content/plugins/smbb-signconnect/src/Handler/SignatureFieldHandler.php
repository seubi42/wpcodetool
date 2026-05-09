<?php

namespace Smbb\SignConnect\Handler;

use Smbb\SignConnect\Repository\SignatureFieldRepository;
use Smbb\SignConnect\Service\DocumentAccessService;

defined('ABSPATH') || exit;

final class SignatureFieldHandler
{
    private $signature_fields;
    private $access;

    public function __construct(SignatureFieldRepository $signature_fields = null, DocumentAccessService $access = null)
    {
        $this->signature_fields = $signature_fields ?: new SignatureFieldRepository();
        $this->access = $access ?: new DocumentAccessService();
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

        $fields_json = isset($_POST['fields']) ? wp_unslash((string) $_POST['fields']) : '[]';
        $fields = json_decode($fields_json, true);

        if (!is_array($fields)) {
            wp_send_json_error(array('message' => __('Signature areas are invalid.', 'smbb-signconnect')), 400);
        }

        $field_ids = $this->signature_fields->saveForDocument($document_id, $fields);

        if ($field_ids === false) {
            wp_send_json_error(array('message' => __('The signature area could not be saved.', 'smbb-signconnect')), 500);
        }

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
                );
            }, $this->signature_fields->listForDocument($document_id)),
        ));
    }
}
