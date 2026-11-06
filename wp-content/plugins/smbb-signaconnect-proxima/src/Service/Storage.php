<?php

namespace Smbb\SignConnectProxima\Service;

use Smbb\SignConnectProxima\Repository\Organizations;
use Smbb\WpCodeTool\Connector\SmbbS3Client;

defined('ABSPATH') || exit;

if (!function_exists('wp_tempnam')) {
    require_once ABSPATH . 'wp-admin/includes/file.php';
}

/**
 * Fichiers d'une organisation sur son S3 (connecteur S3 de CodeTool).
 */
final class Storage
{
    private $client;

    private function __construct(SmbbS3Client $client)
    {
        $this->client = $client;
    }

    public static function forOrganization(array $organization)
    {
        if (!class_exists(SmbbS3Client::class)) {
            throw new \RuntimeException(__('Le connecteur S3 de CodeTool est indisponible.', 'smbb-signaconnect-proxima'));
        }

        if (!Organizations::hasS3($organization)) {
            throw new \RuntimeException(__("Le stockage S3 de l'organisation n'est pas configuré.", 'smbb-signaconnect-proxima'));
        }

        return new self(new SmbbS3Client(
            (string) $organization['s3_endpoint'],
            (string) $organization['s3_access_key'],
            (string) $organization['s3_secret_key'],
            (string) $organization['s3_bucket'],
            !empty($organization['s3_path_style']),
            !empty($organization['s3_region']) ? (string) $organization['s3_region'] : 'us-east-1',
            (string) $organization['s3_prefix']
        ));
    }

    public function putFile($key, $path, $content_type = 'application/pdf')
    {
        $body = fopen($path, 'rb');

        if (!$body) {
            throw new \RuntimeException(__('Le fichier à envoyer est illisible.', 'smbb-signaconnect-proxima'));
        }

        try {
            $this->client->putObject($key, $body, array('ContentType' => $content_type));
        } finally {
            if (is_resource($body)) {
                fclose($body);
            }
        }
    }

    public function putString($key, $content, $content_type = 'text/plain')
    {
        $this->client->putObject($key, (string) $content, array('ContentType' => $content_type));
    }

    /**
     * Télécharge un objet dans un fichier temporaire (à supprimer par l'appelant).
     */
    public function download($key)
    {
        $object = $this->client->getObject($key);
        $body = isset($object['Body']) ? $object['Body'] : null;
        $path = wp_tempnam('scp-file');

        if (!$body || !$path) {
            throw new \RuntimeException(__('Le fichier stocké est illisible.', 'smbb-signaconnect-proxima'));
        }

        $target = fopen($path, 'wb');

        if (is_object($body) && method_exists($body, 'eof') && method_exists($body, 'read')) {
            while (!$body->eof()) {
                fwrite($target, $body->read(1048576));
            }
        } else {
            fwrite($target, (string) $body);
        }

        fclose($target);

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

    /**
     * Test complet : lister, écrire, relire et supprimer un petit fichier.
     *
     * @return string message de succès
     */
    public function selfTest()
    {
        $this->client->testConnection();
        $key = 'signaconnect-test/' . gmdate('Ymd-His') . '-' . wp_generate_password(8, false, false) . '.txt';
        $content = 'SignaConnect ' . gmdate('c');
        $this->putString($key, $content);

        if ($this->getString($key) !== $content) {
            throw new \RuntimeException(__('Le fichier de test a été écrit mais sa relecture ne correspond pas.', 'smbb-signaconnect-proxima'));
        }

        $this->delete($key);

        return __('Connexion S3 OK : lecture, écriture et suppression réussies.', 'smbb-signaconnect-proxima');
    }

    /**
     * Clé de rangement d'un document : org-<id>/AAAA/MM/doc-<id>-<suffixe>.pdf
     */
    public static function documentKey($organization_id, $document_id, $suffix = 'source')
    {
        return 'org-' . absint($organization_id) . '/' . gmdate('Y/m') . '/doc-' . absint($document_id) . '-' . sanitize_key($suffix) . '-' . wp_generate_password(8, false, false) . '.pdf';
    }
}
