<?php

namespace Smbb\SignConnect\Service;

defined('ABSPATH') || exit;

final class S3DocumentRemover
{
    public function delete(array $storage, array $document)
    {
        $paths = array_filter(array_unique(array(
            isset($document['storage_path']) ? (string) $document['storage_path'] : '',
            isset($document['signed_storage_path']) ? (string) $document['signed_storage_path'] : '',
            isset($document['identity_photo_storage_path']) ? (string) $document['identity_photo_storage_path'] : '',
        )));

        if (!$paths) {
            return;
        }

        if (!class_exists('\Smbb\WpCodeTool\Connector\SmbbS3Client')) {
            throw new \RuntimeException(__('The S3 connector from SMBB WP CodeTool is unavailable.', 'smbb-signconnect'));
        }

        $client = \Smbb\WpCodeTool\Connector\SmbbS3Client::fromSettings($storage);

        foreach ($paths as $path) {
            $client->deleteObject($path);
        }
    }
}
