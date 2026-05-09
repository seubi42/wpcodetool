<?php

namespace Smbb\SignConnect\Service;

use Smbb\SignConnect\Repository\DocumentRepository;
use Smbb\SignConnect\Repository\StorageRepository;

defined('ABSPATH') || exit;

final class DocumentAccessService
{
    private $documents;
    private $storages;
    private $streamer;

    public function __construct(DocumentRepository $documents = null, StorageRepository $storages = null, S3DocumentStreamer $streamer = null)
    {
        $this->documents = $documents ?: new DocumentRepository();
        $this->storages = $storages ?: new StorageRepository();
        $this->streamer = $streamer ?: new S3DocumentStreamer();
    }

    public function authorizedDocument($document_id)
    {
        if (!is_user_logged_in()) {
            return null;
        }

        if (current_user_can('manage_options')) {
            return $this->documents->find($document_id);
        }

        return $this->documents->findOwnedByUser($document_id, get_current_user_id());
    }

    public function streamAuthorizedDocument($document_id, $disposition)
    {
        if (!is_user_logged_in()) {
            wp_safe_redirect(wp_login_url(home_url('/')));
            exit;
        }

        $document = $this->authorizedDocument($document_id);

        if (!$document) {
            wp_die(esc_html__('Document not found.', 'smbb-signconnect'));
        }

        $storage = $this->storages->find(isset($document['storage_id']) ? (int) $document['storage_id'] : 0);

        if (!$storage || empty($document['storage_path'])) {
            wp_die(esc_html__('The associated file is unavailable.', 'smbb-signconnect'));
        }

        try {
            $this->streamer->stream($storage, $document, $disposition);
        } catch (\Throwable $exception) {
            wp_die(esc_html($exception->getMessage()));
        }
    }
}
