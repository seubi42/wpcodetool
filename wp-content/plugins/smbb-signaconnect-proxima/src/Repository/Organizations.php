<?php

namespace Smbb\SignConnectProxima\Repository;

defined('ABSPATH') || exit;

/**
 * Lecture complète d'une organisation (réglages, clés, stockage).
 */
final class Organizations
{
    private static $rows = array();

    public static function find($id)
    {
        global $wpdb;

        $id = (int) $id;

        if ($id < 1) {
            return null;
        }

        if (!array_key_exists($id, self::$rows)) {
            $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . Lookup::table('scp_organization') . ' WHERE id = %d AND deleted_flag = 0', $id), ARRAY_A);

            if ($row) {
                $row = self::decryptSecrets($row);

                foreach (array('allowed_levels', 'internal_channels') as $key) {
                    $decoded = json_decode((string) $row[$key], true);
                    $row[$key] = is_array($decoded) ? array_values($decoded) : array();
                }
            }

            self::$rows[$id] = $row ?: null;
        }

        return self::$rows[$id];
    }

    /**
     * Ville des champs « Lieu » : celle de l'équipe du document si elle est renseignée, sinon
     * la colonne city de l'organisation, sinon la ville de l'adresse de facturation
     * (ligne « 75001 Paris »), sinon ''.
     */
    public static function city(array $organization, $team_id = 0)
    {
        $team = (int) $team_id > 0 ? \Smbb\SignConnectProxima\Service\StampService::team($team_id) : null;

        if ($team && (int) $team['organization_id'] === (int) $organization['id'] && !empty($team['city']) && trim((string) $team['city']) !== '') {
            return trim((string) $team['city']);
        }

        $city = isset($organization['city']) ? trim((string) $organization['city']) : '';

        if ($city !== '') {
            return $city;
        }

        $lines = array_values(array_filter(array_map('trim', preg_split('/\R/', isset($organization['billing_address']) ? (string) $organization['billing_address'] : ''))));

        foreach (array_reverse($lines) as $line) {
            if (preg_match('/^(?:F-?)?\d{5}\s*,?\s+(.+)$/u', $line, $match)) {
                return self::cleanCity($match[1]);
            }

            if (preg_match('/^(.+?)\s+\d{5}$/u', $line, $match)) {
                return self::cleanCity($match[1]);
            }
        }

        return '';
    }

    private static function cleanCity($city)
    {
        // « Paris Cedex 08 » → « Paris », « Lyon, France » → « Lyon ».
        $city = preg_replace('/\s+cedex(\s+\d+)?$/iu', '', trim((string) $city));
        $city = preg_replace('/\s*,\s*france$/iu', '', $city);

        return trim($city);
    }

    /**
     * Clés API chiffrées en base par CodeTool (colonnes "secret") : déchiffrées pour les services.
     * Une valeur illisible (clé de chiffrement perdue) devient vide : le service la verra non configurée.
     */
    public static function decryptSecrets(array $row)
    {
        if (!class_exists('Smbb\\WpCodeTool\\Support\\Secrets')) {
            return $row;
        }

        foreach (array('universign_api_key', 'openai_api_key', 'twilio_token', 's3_secret_key') as $key) {
            if (isset($row[$key]) && \Smbb\WpCodeTool\Support\Secrets::isEncrypted($row[$key])) {
                $plain = \Smbb\WpCodeTool\Support\Secrets::decrypt($row[$key]);
                $row[$key] = $plain === null ? '' : $plain;
            }
        }

        return $row;
    }

    public static function canSend(array $organization)
    {
        return isset($organization['status']) && $organization['status'] === 'active';
    }

    public static function isBlocked(array $organization)
    {
        return isset($organization['status']) && $organization['status'] === 'blocked';
    }

    /**
     * Stockage des documents prêt : une unité de CodeTool choisie (onglet Stockage), ou l'ancien réglage S3.
     */
    public static function hasStorage(array $organization)
    {
        return \Smbb\SignConnectProxima\Service\Storage::unitOf($organization) !== '' || self::hasLegacyS3($organization);
    }

    /**
     * @deprecated Ancien nom de hasStorage() (le stockage n'est plus forcément un S3).
     */
    public static function hasS3(array $organization)
    {
        return self::hasStorage($organization);
    }

    /**
     * Ancien réglage S3 propre à l'organisation (colonnes s3_*), repris dans CodeTool par StorageMigration.
     */
    public static function hasLegacyS3(array $organization)
    {
        return !empty($organization['s3_bucket']) && !empty($organization['s3_access_key']) && !empty($organization['s3_secret_key']);
    }

    public static function hasUniversign(array $organization)
    {
        return !empty($organization['universign_api_key']);
    }

    /**
     * Niveaux cochés sur l'organisation et réellement utilisables (clé Universign pour les niveaux Universign).
     *
     * @return string[]
     */
    public static function availableLevels(array $organization)
    {
        $levels = array();

        foreach (\Smbb\SignConnectProxima\Support\Levels::all() as $level) {
            if (!in_array($level, (array) $organization['allowed_levels'], true)) {
                continue;
            }

            if ($level !== \Smbb\SignConnectProxima\Support\Levels::INTERNAL && !self::hasUniversign($organization)) {
                continue;
            }

            $levels[] = $level;
        }

        return $levels;
    }

    public static function hasTwilio(array $organization)
    {
        return !empty($organization['twilio_sid']) && !empty($organization['twilio_token']) && !empty($organization['twilio_from']);
    }

    /**
     * @return int[]
     */
    public static function reminderDays(array $organization)
    {
        $days = array_filter(array_map('intval', explode(',', (string) $organization['reminder_days'])));
        sort($days);

        return array_values(array_unique($days));
    }

    const DEFAULT_COLOR = '#db1f26';

    public static function brandColor(array $organization)
    {
        $color = isset($organization['brand_color']) ? (string) $organization['brand_color'] : '';

        // L'ancien bleu par défaut (#1e40af) suit le nouveau rouge SignaConnect.
        if (!preg_match('/^#[0-9a-fA-F]{3,8}$/', $color) || strtolower($color) === '#1e40af') {
            return self::DEFAULT_COLOR;
        }

        return $color;
    }
}
