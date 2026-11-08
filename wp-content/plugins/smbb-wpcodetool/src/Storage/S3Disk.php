<?php

namespace Smbb\WpCodeTool\Storage;

use Smbb\WpCodeTool\Connector\SmbbS3Client;

defined('ABSPATH') || exit;

if (!function_exists('wp_tempnam')) {
    require_once ABSPATH . 'wp-admin/includes/file.php';
}

/**
 * Unité S3 (AWS ou compatible : OVH, Scaleway, MinIO…) sur le connecteur S3 de CodeTool.
 */
final class S3Disk implements DiskInterface
{
    /** @var SmbbS3Client */
    private $client;

    public function __construct(array $settings)
    {
        if (!class_exists(\Aws\S3\S3Client::class)) {
            throw new \RuntimeException(__('Le SDK AWS de CodeTool est absent (dossier vendor).', 'smbb-wpcodetool'));
        }

        if (trim((string) ($settings['bucket'] ?? '')) === '' || trim((string) ($settings['access_key'] ?? '')) === '' || (string) ($settings['secret_key'] ?? '') === '') {
            throw new \RuntimeException(__('Unité S3 incomplète : bucket, clé d\'accès et clé secrète sont nécessaires.', 'smbb-wpcodetool'));
        }

        $this->client = new SmbbS3Client(
            trim((string) ($settings['endpoint'] ?? '')) !== '' ? trim((string) $settings['endpoint']) : 'https://s3.amazonaws.com',
            trim((string) $settings['access_key']),
            (string) $settings['secret_key'],
            trim((string) $settings['bucket']),
            !empty($settings['path_style']),
            trim((string) ($settings['region'] ?? '')) !== '' ? trim((string) $settings['region']) : 'us-east-1',
            (string) ($settings['prefix'] ?? '')
        );
    }

    public function client()
    {
        return $this->client;
    }

    public function putFile($key, $path, $content_type = 'application/octet-stream')
    {
        $body = fopen($path, 'rb'); // phpcs:ignore

        if (!$body) {
            throw new \RuntimeException(__('Le fichier à envoyer est illisible.', 'smbb-wpcodetool'));
        }

        try {
            $this->client->putObject($key, $body, array('ContentType' => $content_type));
        } finally {
            if (is_resource($body)) {
                fclose($body); // phpcs:ignore
            }
        }
    }

    public function putString($key, $content, $content_type = 'text/plain')
    {
        $this->client->putObject($key, (string) $content, array('ContentType' => $content_type));
    }

    public function download($key)
    {
        $object = $this->client->getObject($key);
        $body = isset($object['Body']) ? $object['Body'] : null;
        $path = wp_tempnam('smbb-storage');

        if (!$body || !$path) {
            throw new \RuntimeException(__('Le fichier stocké est illisible.', 'smbb-wpcodetool'));
        }

        $target = fopen($path, 'wb'); // phpcs:ignore

        if (is_object($body) && method_exists($body, 'eof') && method_exists($body, 'read')) {
            while (!$body->eof()) {
                fwrite($target, $body->read(1048576)); // phpcs:ignore
            }
        } else {
            fwrite($target, (string) $body); // phpcs:ignore
        }

        fclose($target); // phpcs:ignore

        return $path;
    }

    public function getString($key)
    {
        $object = $this->client->getObject($key);

        return isset($object['Body']) ? (string) $object['Body'] : '';
    }

    public function delete($key)
    {
        if ((string) $key !== '') {
            $this->client->deleteObject($key);
        }
    }

    public function exists($key)
    {
        try {
            $this->client->client()->headObject(array('Bucket' => $this->client->bucket(), 'Key' => $this->client->key($key)));

            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    public function temporaryUrl($key, $seconds = 600)
    {
        return $this->client->temporaryUrl($key, '+' . max(60, (int) $seconds) . ' seconds');
    }

    public function test()
    {
        $this->client->testConnection();
        $key = 'codetool-test/' . gmdate('Ymd-His') . '-' . wp_generate_password(8, false, false) . '.txt';
        $content = 'CodeTool ' . gmdate('c');
        $this->putString($key, $content);

        if ($this->getString($key) !== $content) {
            throw new \RuntimeException(__('Le fichier de test a été écrit mais sa relecture ne correspond pas.', 'smbb-wpcodetool'));
        }

        $this->delete($key);

        return __('Connexion S3 OK : lecture, écriture et suppression réussies.', 'smbb-wpcodetool');
    }
}
