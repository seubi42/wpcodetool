<?php

namespace Smbb\SignConnect\Service;

use Smbb\SignConnect\Repository\StorageRepository;

defined('ABSPATH') || exit;

/**
 * Prepare une copie temporaire locale d'un PDF stocke dans S3.
 *
 * wp_mail() ne sait joindre que des chemins locaux. On télécharge donc le PDF
 * dans le dossier temporaire WordPress, le temps strictement necessaire a
 * l'envoi du mail, puis l'appelant supprime ce fichier.
 */
final class S3DocumentAttachmentProvider
{
    private $storages;

    public function __construct(StorageRepository $storages = null)
    {
        $this->storages = $storages ?: new StorageRepository();
    }

    public function temporaryCopy(array $document)
    {
        if (!class_exists('\Smbb\WpCodeTool\Connector\SmbbS3Client')) {
            return '';
        }

        $storage_id = isset($document['storage_id']) ? (int) $document['storage_id'] : 0;
        $storage_path = !empty($document['signed_storage_path'])
            ? (string) $document['signed_storage_path']
            : (isset($document['storage_path']) ? (string) $document['storage_path'] : '');
        $storage = $this->storages->find($storage_id);

        if (!$storage || $storage_path === '') {
            return '';
        }

        $client = \Smbb\WpCodeTool\Connector\SmbbS3Client::fromSettings($storage);
        $object = $client->getObject($storage_path);
        $body = isset($object['Body']) ? $object['Body'] : null;

        if (!$body) {
            return '';
        }

        $filename = !empty($document['filename']) ? sanitize_file_name((string) $document['filename']) : 'document.pdf';

        if ($filename === '') {
            $filename = 'document.pdf';
        }

        if (strtolower(pathinfo($filename, PATHINFO_EXTENSION)) !== 'pdf') {
            $filename .= '.pdf';
        }

        if (!empty($document['signed_storage_path'])) {
            $info = pathinfo($filename);
            $filename = (isset($info['filename']) ? $info['filename'] : 'document') . '_signed.pdf';
        }

        $temporary_path = $this->temporaryPdfPath($filename);

        if (!$temporary_path) {
            return '';
        }

        $target = fopen($temporary_path, 'wb');

        if (!$target) {
            @unlink($temporary_path);
            return '';
        }

        // On ecrit par morceaux quand le SDK nous donne un stream, pour eviter de charger un gros PDF en memoire.
        if (is_object($body) && method_exists($body, 'eof') && method_exists($body, 'read')) {
            while (!$body->eof()) {
                fwrite($target, $body->read(1048576));
            }
        } elseif (is_object($body) && method_exists($body, 'getContents')) {
            fwrite($target, $body->getContents());
        } else {
            fwrite($target, (string) $body);
        }

        fclose($target);

        return $temporary_path;
    }

    private function temporaryPdfPath($filename)
    {
        $upload_dir = wp_upload_dir();
        $base_dir = empty($upload_dir['basedir']) ? sys_get_temp_dir() : $upload_dir['basedir'];
        $directory = trailingslashit($base_dir) . 'signconnect-mail';

        if (!wp_mkdir_p($directory)) {
            $directory = sys_get_temp_dir();
        }

        $filename = sanitize_file_name((string) $filename);

        if ($filename === '') {
            $filename = 'document.pdf';
        }

        if (strtolower(pathinfo($filename, PATHINFO_EXTENSION)) !== 'pdf') {
            $filename .= '.pdf';
        }

        $info = pathinfo($filename);
        $basename = isset($info['filename']) ? $info['filename'] : 'document';

        return trailingslashit($directory) . $basename . '-' . wp_generate_password(8, false, false) . '.pdf';
    }
}
