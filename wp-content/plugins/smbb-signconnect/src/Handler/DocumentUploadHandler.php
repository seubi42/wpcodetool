<?php

namespace Smbb\SignConnect\Handler;

use Smbb\SignConnect\Repository\DocumentRepository;
use Smbb\SignConnect\Repository\DocumentAuditRepository;
use Smbb\SignConnect\Repository\StorageRepository;
use Smbb\SignConnect\Service\DocumentAiEnrichmentService;
use Smbb\SignConnect\Service\S3DocumentUploader;
use Smbb\SignConnect\Support\UrlHelper;
use Smbb\SignConnect\User\UserStorageProfileFields;

defined('ABSPATH') || exit;

final class DocumentUploadHandler
{
    private $storages;
    private $documents;
    private $audit;
    private $uploader;
    private $ai_enrichment;

    public function __construct(
        StorageRepository $storages = null,
        DocumentRepository $documents = null,
        DocumentAuditRepository $audit = null,
        S3DocumentUploader $uploader = null,
        DocumentAiEnrichmentService $ai_enrichment = null
    ) {
        $this->storages = $storages ?: new StorageRepository();
        $this->documents = $documents ?: new DocumentRepository();
        $this->audit = $audit ?: new DocumentAuditRepository();
        $this->uploader = $uploader ?: new S3DocumentUploader();
        $this->ai_enrichment = $ai_enrichment ?: new DocumentAiEnrichmentService($this->documents);
    }

    public function handleUpload()
    {
        if (!is_user_logged_in()) {
            wp_safe_redirect(wp_login_url(UrlHelper::currentUrl()));
            exit;
        }

        $redirect_url = UrlHelper::safeRefererUrl();
        $result = $this->processUpload();

        if (empty($result['success'])) {
            $this->redirectWithNotice($redirect_url, 'error', isset($result['message']) ? $result['message'] : __('File upload failed.', 'smbb-signconnect'));
        }

        $redirect_url = add_query_arg(array(
            'signconnect_document' => isset($result['document_id']) ? (int) $result['document_id'] : 0,
            'signconnect_step' => 'zone',
        ), $redirect_url);

        $this->redirectWithNotice($redirect_url, 'success', __('Document uploaded successfully.', 'smbb-signconnect'));
    }

    public function handleAjaxUpload()
    {
        if (!is_user_logged_in()) {
            wp_send_json_error(array('message' => __('Please sign in to upload a document.', 'smbb-signconnect')), 401);
        }

        $result = $this->processUpload();

        if (empty($result['success'])) {
            wp_send_json_error(array('message' => isset($result['message']) ? $result['message'] : __('File upload failed.', 'smbb-signconnect')), 400);
        }

        wp_send_json_success(array(
            'message' => __('Document uploaded successfully.', 'smbb-signconnect'),
            'document_id' => isset($result['document_id']) ? (int) $result['document_id'] : 0,
            'redirect_url' => add_query_arg(array(
                'signconnect_document' => isset($result['document_id']) ? (int) $result['document_id'] : 0,
                'signconnect_step' => 'zone',
            ), UrlHelper::safeRefererUrl()),
        ));
    }

    private function processUpload()
    {
        $user_id = get_current_user_id();

        if (!check_ajax_referer('smbb_signconnect_upload_document_' . $user_id, '_wpnonce', false)) {
            return array('success' => false, 'message' => __('Session expired. Please reload the page.', 'smbb-signconnect'));
        }

        $storage_id = (int) get_user_meta($user_id, UserStorageProfileFields::META_KEY, true);
        $posted_storage_id = isset($_POST['storage_id']) ? absint($_POST['storage_id']) : 0;

        if ($storage_id < 1 || $posted_storage_id !== $storage_id) {
            return array('success' => false, 'message' => __('No valid SignConnect storage is configured for your account.', 'smbb-signconnect'));
        }

        $storage = $this->storages->find($storage_id);

        if (!$storage) {
            return array('success' => false, 'message' => __('The SignConnect storage configured for your account is unavailable.', 'smbb-signconnect'));
        }

        $file = isset($_FILES['signconnect_document']) && is_array($_FILES['signconnect_document']) ? $_FILES['signconnect_document'] : array();

        try {
            $storage_path = $this->uploader->upload($storage, $file, $user_id);
            $document_id = $this->documents->createUploadedDocument(array(
                'filename' => isset($file['name']) ? (string) $file['name'] : '',
                'storage_id' => $storage_id,
                'storage_path' => $storage_path,
                'file_size' => isset($file['size']) ? (int) $file['size'] : 0,
                'token' => wp_generate_password(32, false, false),
            ));

            if (!$document_id) {
                return array('success' => false, 'message' => __('The document was uploaded, but the database record could not be created.', 'smbb-signconnect'));
            }

            $this->audit->record((int) $document_id, 'uploaded', array(
                'filename' => isset($file['name']) ? (string) $file['name'] : '',
                'storage_id' => $storage_id,
                'storage_path' => $storage_path,
                'file_size' => isset($file['size']) ? (int) $file['size'] : 0,
            ), 'owner', $user_id, __('Document uploaded.', 'smbb-signconnect'));

            $this->ai_enrichment->enrichAfterUpload((int) $document_id, $user_id, $file);

            return array('success' => true, 'document_id' => (int) $document_id);
        } catch (\Throwable $exception) {
            return array('success' => false, 'message' => $exception->getMessage());
        }
    }

    private function redirectWithNotice($url, $type, $message)
    {
        set_transient('smbb_signconnect_upload_' . get_current_user_id(), array(
            'type' => $type === 'success' ? 'success' : 'error',
            'message' => (string) $message,
        ), 60);

        wp_safe_redirect(add_query_arg('signconnect_upload', '1', $url));
        exit;
    }
}
