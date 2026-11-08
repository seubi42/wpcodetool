<?php

namespace Smbb\SignConnectProxima\Service;

use Smbb\SignConnectProxima\Repository\AuditLog;
use Smbb\SignConnectProxima\Repository\Lookup;
use Smbb\SignConnectProxima\Repository\Organizations;
use Smbb\SignConnectProxima\Support\Schema;

defined('ABSPATH') || exit;

/**
 * Stockage centralisé dans CodeTool (0.12.0).
 *
 * - Reprise, une seule fois : chaque organisation réglée avec ses propres colonnes s3_* reçoit une unité
 *   de stockage CodeTool équivalente (une seule unité pour plusieurs organisations sur le même bucket et
 *   le même préfixe), puis storage_unit. Ses documents rangés sur ce S3 retiennent cette unité.
 *   Les colonnes s3_* restent en base (retour arrière possible) mais ne sont plus affichées.
 * - CodeTool › Stockage : une unité choisie par une organisation (ou qui garde des documents) ne se supprime pas.
 */
final class StorageMigration
{
    const OPTION = 'smbb_scp_storage_migrated';

    public static function hooks()
    {
        add_action('admin_init', array(__CLASS__, 'maybeRun'), 30);
        add_filter('smbb_wpcodetool_storage_usage', array(__CLASS__, 'usage'), 10, 2);
    }

    public static function maybeRun()
    {
        if (get_option(self::OPTION) || !Storage::unitsAvailable() || !Schema::ensure()) {
            return;
        }

        self::run();
    }

    /**
     * @return array<int,string> organisation => unité
     */
    public static function run()
    {
        global $wpdb;

        $table = Lookup::table('scp_organization');
        $rows = $wpdb->get_results("SELECT * FROM {$table} WHERE (storage_unit IS NULL OR storage_unit = '') AND s3_bucket IS NOT NULL AND s3_bucket <> ''", ARRAY_A); // phpcs:ignore
        $done = array();
        $failed = false;

        foreach (is_array($rows) ? $rows : array() as $row) {
            $organization = Organizations::decryptSecrets($row);

            if (!Organizations::hasLegacyS3($organization)) {
                continue;
            }

            $settings = array(
                'endpoint' => esc_url_raw(trim((string) $organization['s3_endpoint'])),
                'region' => sanitize_text_field(trim((string) $organization['s3_region'])),
                'bucket' => sanitize_text_field(trim((string) $organization['s3_bucket'])),
                'access_key' => sanitize_text_field(trim((string) $organization['s3_access_key'])),
                'prefix' => sanitize_text_field(trim((string) $organization['s3_prefix'])),
                'path_style' => !empty($organization['s3_path_style']) ? 1 : 0,
            );

            try {
                $unit = \Smbb\WpCodeTool\Storage\Storage::findSame('s3', $settings);

                if ($unit === null) {
                    $label = sprintf(__('S3 %1$s · %2$s', 'smbb-signaconnect-proxima'), $settings['bucket'], (string) $organization['name']);
                    $unit = \Smbb\WpCodeTool\Storage\Storage::save('', $label, 's3', $settings + array('secret_key' => (string) $organization['s3_secret_key']));
                }
            } catch (\Throwable $exception) {
                $failed = true;
                AuditLog::record('storage_migration_failed', $exception->getMessage(), array(), (int) $row['id'], null, 'system');
                continue;
            }

            $wpdb->update($table, array('storage_unit' => $unit), array('id' => (int) $row['id'])); // phpcs:ignore
            // Documents déjà rangés sur ce S3 : ils retiennent l'unité (l'organisation pourra en changer ensuite).
            $wpdb->query($wpdb->prepare('UPDATE ' . Lookup::table('scp_document') . " SET storage_unit = %s WHERE organization_id = %d AND (storage_unit IS NULL OR storage_unit = '') AND (source_storage_path IS NOT NULL OR signed_storage_path IS NOT NULL OR evidence_storage_path IS NOT NULL)", $unit, (int) $row['id'])); // phpcs:ignore
            AuditLog::record('storage_migrated', sprintf(__('Stockage S3 repris dans CodeTool › Stockage : unité « %s ».', 'smbb-signaconnect-proxima'), \Smbb\WpCodeTool\Storage\Storage::label($unit)), array('unit' => $unit), (int) $row['id'], null, 'system');
            $done[(int) $row['id']] = $unit;
        }

        if (!$failed) {
            update_option(self::OPTION, current_time('mysql'), false);
        }

        return $done;
    }

    /**
     * CodeTool › Stockage : où une unité sert dans SignaConnect.
     */
    public static function usage($usage, $unit)
    {
        global $wpdb;

        if (!Schema::ensure()) {
            return $usage;
        }

        $names = $wpdb->get_col($wpdb->prepare('SELECT name FROM ' . Lookup::table('scp_organization') . ' WHERE storage_unit = %s AND deleted_flag = 0 ORDER BY name LIMIT 20', (string) $unit)); // phpcs:ignore

        foreach (is_array($names) ? $names : array() as $name) {
            $usage[] = sprintf(__('SignaConnect : %s', 'smbb-signaconnect-proxima'), $name);
        }

        $documents = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . Lookup::table('scp_document') . ' WHERE storage_unit = %s AND anonymized_at IS NULL AND deleted_flag = 0', (string) $unit)); // phpcs:ignore

        if ($documents > 0) {
            $usage[] = sprintf(_n('SignaConnect : %d document', 'SignaConnect : %d documents', $documents, 'smbb-signaconnect-proxima'), $documents);
        }

        return $usage;
    }
}
