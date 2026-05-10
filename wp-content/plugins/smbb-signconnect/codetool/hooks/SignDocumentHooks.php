<?php

namespace Smbb\SignConnect\CodeTool;

use Smbb\SignConnect\Repository\StorageRepository;
use Smbb\SignConnect\Repository\DocumentAuditRepository;
use Smbb\SignConnect\Service\S3DocumentRemover;

defined('ABSPATH') || exit;

final class SignDocumentHooks
{
    private $storages;
    private $remover;

    public function __construct(StorageRepository $storages = null, S3DocumentRemover $remover = null)
    {
        $this->storages = $storages ?: new StorageRepository();
        $this->remover = $remover ?: new S3DocumentRemover();
    }

    public function beforeDelete(array $row, array $context)
    {
        if (empty($row['storage_path'])) {
            return true;
        }

        $storage_id = isset($row['storage_id']) ? (int) $row['storage_id'] : 0;
        $storage = $storage_id > 0 ? $this->storages->find($storage_id) : null;

        if (!$storage) {
            return __('The storage linked to this document was not found. S3 file deletion is impossible.', 'smbb-signconnect');
        }

        try {
            $this->remover->delete($storage, $row);
            (new DocumentAuditRepository())->record((int) $row['id'], 'admin_deleted', array(
                'storage_id' => $storage_id,
                'storage_path' => isset($row['storage_path']) ? (string) $row['storage_path'] : '',
            ), 'admin', get_current_user_id(), __('Document deleted from admin.', 'smbb-signconnect'));
        } catch (\Throwable $exception) {
            return sprintf(
                /* translators: %s: S3 error message. */
                __('The S3 file could not be deleted: %s', 'smbb-signconnect'),
                $exception->getMessage()
            );
        }

        return true;
    }
}
