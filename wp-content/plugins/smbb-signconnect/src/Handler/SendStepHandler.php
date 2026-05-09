<?php

namespace Smbb\SignConnect\Handler;

use Smbb\SignConnect\Repository\DocumentRepository;
use Smbb\SignConnect\Service\DocumentSendMessageSuggestionService;
use Smbb\SignConnect\Service\DocumentSendPreparationService;

defined('ABSPATH') || exit;

final class SendStepHandler
{
    private $send_preparation;
    private $message_suggestion;

    public function __construct(DocumentRepository $documents = null, DocumentSendPreparationService $send_preparation = null, DocumentSendMessageSuggestionService $message_suggestion = null)
    {
        $documents = $documents ?: new DocumentRepository();
        $this->send_preparation = $send_preparation ?: new DocumentSendPreparationService($documents);
        $this->message_suggestion = $message_suggestion ?: new DocumentSendMessageSuggestionService($documents);
    }

    public function handlePrepareSend()
    {
        if (!is_user_logged_in()) {
            wp_send_json_error(array('message' => __('Please sign in.', 'smbb-signconnect')), 401);
        }

        $document_id = isset($_POST['document_id']) ? absint($_POST['document_id']) : 0;

        if ($document_id < 1 || !check_ajax_referer('smbb_signconnect_prepare_send_' . $document_id, '_wpnonce', false)) {
            wp_send_json_error(array('message' => __('Session expired. Please reload the page.', 'smbb-signconnect')), 403);
        }

        $result = $this->send_preparation->prepareFromPost($document_id, get_current_user_id(), $_POST);

        if (empty($result['success'])) {
            wp_send_json_error(
                array('message' => isset($result['message']) ? $result['message'] : __('The sending settings could not be saved.', 'smbb-signconnect')),
                isset($result['status_code']) ? (int) $result['status_code'] : 400
            );
        }

        wp_send_json_success(array(
            'message' => isset($result['message']) ? $result['message'] : __('Sending settings saved.', 'smbb-signconnect'),
            'status' => isset($result['status']) ? (string) $result['status'] : 'ready_to_send',
        ));
    }

    public function handleSuggestSendMessage()
    {
        if (!is_user_logged_in()) {
            wp_send_json_error(array('message' => __('Please sign in.', 'smbb-signconnect')), 401);
        }

        $document_id = isset($_POST['document_id']) ? absint($_POST['document_id']) : 0;

        if ($document_id < 1 || !check_ajax_referer('smbb_signconnect_prepare_send_' . $document_id, '_wpnonce', false)) {
            wp_send_json_error(array('message' => __('Session expired. Please reload the page.', 'smbb-signconnect')), 403);
        }

        $result = $this->message_suggestion->suggest($document_id, get_current_user_id());

        if (empty($result['success'])) {
            wp_send_json_error(
                array('message' => isset($result['message']) ? $result['message'] : __('AI suggestion failed.', 'smbb-signconnect')),
                isset($result['status_code']) ? (int) $result['status_code'] : 400
            );
        }

        wp_send_json_success(array(
            'message' => isset($result['message']) ? (string) $result['message'] : '',
        ));
    }
}
