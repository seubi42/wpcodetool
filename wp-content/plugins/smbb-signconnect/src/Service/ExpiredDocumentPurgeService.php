<?php

namespace Smbb\SignConnect\Service;

use Smbb\SignConnect\Repository\DocumentRepository;
use Smbb\SignConnect\Repository\StorageRepository;

defined('ABSPATH') || exit;

/**
 * Nettoie les documents dont le lien public de signature est expiré.
 *
 * Regle volontairement prudente :
 * - on ne purge que les documents non signés ;
 * - on efface d'abord le ou les objets S3 ;
 * - seulement ensuite on soft-delete la ligne en base.
 *
 * Comme ca, une erreur S3 ne rend pas la base incoherente.
 */
final class ExpiredDocumentPurgeService
{
    private $documents;
    private $storages;
    private $remover;

    public function __construct(
        DocumentRepository $documents = null,
        StorageRepository $storages = null,
        S3DocumentRemover $remover = null
    ) {
        $this->documents = $documents ?: new DocumentRepository();
        $this->storages = $storages ?: new StorageRepository();
        $this->remover = $remover ?: new S3DocumentRemover();
    }

    public function run($limit = 100)
    {
        $expired_documents = $this->documents->findExpiredUnsigned((int) $limit);
        $result = array(
            'found' => count($expired_documents),
            'deleted' => 0,
            'failed' => 0,
        );

        foreach ($expired_documents as $document) {
            try {
                $storage_id = isset($document['storage_id']) ? (int) $document['storage_id'] : 0;
                $storage = $storage_id > 0 ? $this->storages->find($storage_id) : null;

                if (!$storage) {
                    throw new \RuntimeException(sprintf(
                        'Storage introuvable pour le document #%d.',
                        isset($document['id']) ? (int) $document['id'] : 0
                    ));
                }

                $this->remover->delete($storage, $document);
                $this->documents->markDeletedByCron((int) $document['id']);
                $result['deleted']++;
            } catch (\Throwable $exception) {
                $result['failed']++;
            }
        }

        return $result;
    }
}
