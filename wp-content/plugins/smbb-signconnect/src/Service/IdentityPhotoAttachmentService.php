<?php

namespace Smbb\SignConnect\Service;

use Smbb\SignConnect\Repository\StorageRepository;

defined('ABSPATH') || exit;

/**
 * Gere la photo d\'identite fournie au moment de la signature publique.
 *
 * La photo est un complement de preuve :
 * - elle reste côté serveur uniquement ;
 * - elle est envoyée dans le meme stockage S3 que le document ;
 * - elle est aussi injectee en page annexe dans le PDF signé.
 */
final class IdentityPhotoAttachmentService
{
    private $storages;

    public function __construct(StorageRepository $storages = null)
    {
        $this->storages = $storages ?: new StorageRepository();
    }

    public function upload(array $document, array $file)
    {
        if (!class_exists('\Smbb\WpCodeTool\Connector\SmbbS3Client')) {
            throw new \RuntimeException(__('The S3 connector from SMBB WP CodeTool is unavailable.', 'smbb-signconnect'));
        }

        $this->assertUploadedImage($file);

        $storage_id = isset($document['storage_id']) ? (int) $document['storage_id'] : 0;
        $storage = $this->storages->find($storage_id);

        if (!$storage) {
            throw new \RuntimeException(__('The document storage is unavailable.', 'smbb-signconnect'));
        }

        $mime_type = $this->imageMimeType($file);
        $extension = $mime_type === 'image/png' ? 'png' : 'jpg';
        $path = $this->storagePath($document, $extension);
        $body = fopen((string) $file['tmp_name'], 'rb');

        if (!$body) {
            throw new \RuntimeException(__('The identity photo cannot be read.', 'smbb-signconnect'));
        }

        try {
            $client = \Smbb\WpCodeTool\Connector\SmbbS3Client::fromSettings($storage);
            $client->putObject($path, $body, array(
                'ContentType' => $mime_type,
            ));
        } finally {
            if (is_resource($body)) {
                fclose($body);
            }
        }

        return array(
            'storage_path' => $path,
            'file_size' => isset($file['size']) ? max(0, (int) $file['size']) : 0,
            'mime_type' => $mime_type,
            'filename' => isset($file['name']) ? sanitize_file_name((string) $file['name']) : '',
            'tmp_name' => isset($file['tmp_name']) ? (string) $file['tmp_name'] : '',
        );
    }

    public function assertUploadedImage(array $file)
    {
        if (empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
            throw new \RuntimeException(__('Please add an identity photo.', 'smbb-signconnect'));
        }

        if (!empty($file['error'])) {
            throw new \RuntimeException($this->uploadErrorMessage((int) $file['error']));
        }

        if (!function_exists('wp_check_filetype_and_ext')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }

        $allowed = array(
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
        );
        $check = wp_check_filetype_and_ext(
            isset($file['tmp_name']) ? (string) $file['tmp_name'] : '',
            isset($file['name']) ? (string) $file['name'] : '',
            $allowed
        );

        if (empty($check['ext']) || empty($check['type']) || !in_array($check['type'], array('image/jpeg', 'image/png'), true)) {
            throw new \RuntimeException(__('The identity photo must be a JPG or PNG image.', 'smbb-signconnect'));
        }
    }

    private function imageMimeType(array $file)
    {
        if (!function_exists('wp_check_filetype_and_ext')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }

        $check = wp_check_filetype_and_ext(
            isset($file['tmp_name']) ? (string) $file['tmp_name'] : '',
            isset($file['name']) ? (string) $file['name'] : '',
            array(
                'jpg' => 'image/jpeg',
                'jpeg' => 'image/jpeg',
                'png' => 'image/png',
            )
        );

        return !empty($check['type']) && $check['type'] === 'image/png' ? 'image/png' : 'image/jpeg';
    }

    private function storagePath(array $document, $extension)
    {
        $source_path = isset($document['storage_path']) ? (string) $document['storage_path'] : '';
        $info = pathinfo($source_path);
        $directory = !empty($info['dirname']) && $info['dirname'] !== '.' ? trim($info['dirname'], '/') . '/' : '';
        $filename = isset($info['filename']) && $info['filename'] !== '' ? sanitize_title($info['filename']) : 'document';
        $unique = gmdate('Ymd-His') . '-' . wp_generate_password(8, false, false);

        return $directory . 'annexes/' . $filename . '-identity-' . $unique . '.' . $extension;
    }

    private function uploadErrorMessage($code)
    {
        switch ($code) {
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                return __('The identity photo is too large.', 'smbb-signconnect');
            case UPLOAD_ERR_PARTIAL:
                return __('The identity photo was only partially uploaded.', 'smbb-signconnect');
            case UPLOAD_ERR_NO_FILE:
                return __('Please add an identity photo.', 'smbb-signconnect');
            default:
                return __('Identity photo upload failed.', 'smbb-signconnect');
        }
    }
}
