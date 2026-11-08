<?php

namespace Smbb\SignConnectProxima\Service;

use Smbb\SignConnectProxima\Repository\Organizations;
use Smbb\WpCodeTool\Connector\SmbbS3Client;

defined('ABSPATH') || exit;

if (!function_exists('wp_tempnam')) {
    require_once ABSPATH . 'wp-admin/includes/file.php';
}

/**
 * Fichiers d'une organisation, rangés sur une unité de stockage de CodeTool (CodeTool › Réglages › Stockage) :
 * l'organisation choisit son unité dans une liste (colonne storage_unit) et chaque document retient l'unité
 * où ses PDF ont été rangés (scp_document.storage_unit), pour qu'un changement d'unité ne les perde pas.
 *
 * Repli : une organisation encore réglée à l'ancienne (colonnes s3_*) garde son S3 jusqu'à la reprise
 * automatique (StorageMigration) qui crée l'unité correspondante dans CodeTool.
 */
final class Storage
{
    /** @var \Smbb\WpCodeTool\Storage\DiskInterface|null */
    private $disk;

    /** @var SmbbS3Client|null ancien réglage s3_* (CodeTool sans unités de stockage) */
    private $client;

    private function __construct($disk, $client = null)
    {
        $this->disk = $disk;
        $this->client = $client;
    }

    /**
     * Unités de CodeTool disponibles (CodeTool assez récent).
     */
    public static function unitsAvailable()
    {
        return class_exists('Smbb\\WpCodeTool\\Storage\\Storage');
    }

    /**
     * Unité choisie par l'organisation ('' = aucune).
     */
    public static function unitOf(array $organization)
    {
        $unit = isset($organization['storage_unit']) ? (string) $organization['storage_unit'] : '';

        return $unit !== '' && self::unitsAvailable() && \Smbb\WpCodeTool\Storage\Storage::exists($unit) ? $unit : '';
    }

    public static function forOrganization(array $organization)
    {
        $unit = self::unitOf($organization);

        if ($unit !== '') {
            return new self(\Smbb\WpCodeTool\Storage\Storage::disk($unit));
        }

        if (!Organizations::hasLegacyS3($organization)) {
            throw new \RuntimeException(__("Aucun stockage n'est choisi pour l'organisation (onglet Stockage).", 'smbb-signaconnect-proxima'));
        }

        if (!class_exists(SmbbS3Client::class)) {
            throw new \RuntimeException(__('Le connecteur S3 de CodeTool est indisponible.', 'smbb-signaconnect-proxima'));
        }

        return new self(null, new SmbbS3Client(
            (string) $organization['s3_endpoint'],
            (string) $organization['s3_access_key'],
            (string) $organization['s3_secret_key'],
            (string) $organization['s3_bucket'],
            !empty($organization['s3_path_style']),
            !empty($organization['s3_region']) ? (string) $organization['s3_region'] : 'us-east-1',
            (string) $organization['s3_prefix']
        ));
    }

    /**
     * Stockage des fichiers d'un document : l'unité où ils ont été rangés, sinon celle de l'organisation.
     */
    public static function forDocument(array $document, array $organization)
    {
        $unit = isset($document['storage_unit']) ? (string) $document['storage_unit'] : '';

        if ($unit !== '' && self::unitsAvailable()) {
            return new self(\Smbb\WpCodeTool\Storage\Storage::disk($unit));
        }

        return self::forOrganization($organization);
    }

    public function putFile($key, $path, $content_type = 'application/pdf')
    {
        if ($this->disk) {
            $this->disk->putFile($key, $path, $content_type);

            return;
        }

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
        if ($this->disk) {
            $this->disk->putString($key, $content, $content_type);

            return;
        }

        $this->client->putObject($key, (string) $content, array('ContentType' => $content_type));
    }

    /**
     * Télécharge un objet dans un fichier temporaire (à supprimer par l'appelant).
     */
    public function download($key)
    {
        if ($this->disk) {
            return $this->disk->download($key);
        }

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
        if ($this->disk) {
            return $this->disk->getString($key);
        }

        $object = $this->client->getObject($key);

        return isset($object['Body']) ? (string) $object['Body'] : '';
    }

    public function delete($key)
    {
        if ((string) $key === '') {
            return;
        }

        if ($this->disk) {
            $this->disk->delete($key);

            return;
        }

        $this->client->deleteObject($key);
    }

    /**
     * Test complet : lister, écrire, relire et supprimer un petit fichier.
     *
     * @return string message de succès
     */
    public function selfTest()
    {
        if ($this->disk) {
            $result = $this->disk->test();

            return is_string($result) && $result !== '' ? $result : __('Stockage OK : écriture, lecture et suppression réussies.', 'smbb-signaconnect-proxima');
        }

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
