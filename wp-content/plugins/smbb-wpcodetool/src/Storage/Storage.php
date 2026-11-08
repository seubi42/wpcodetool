<?php

namespace Smbb\WpCodeTool\Storage;

use Smbb\WpCodeTool\Support\Secrets;

defined('ABSPATH') || exit;

/**
 * Unités de stockage de CodeTool (CodeTool › Réglages › Stockage).
 *
 * Une unité = un endroit où ranger des fichiers : le dossier privé de WordPress (unité « wp », toujours là,
 * non supprimable) ou un bucket S3 (AWS, OVH, Scaleway, MinIO…). D'autres pilotes peuvent s'ajouter.
 * Les plugins (Formulaires, SignaConnect…) proposent une liste des unités et appellent Storage::disk($id).
 *
 * Option smbb_codetool_storage : array('units' => array(id => array(id, label, driver, settings))).
 * Les clés secrètes sont chiffrées (Support\Secrets) et suivent CodeTool › Chiffrement.
 *
 * Ajouter un pilote :
 *
 *     add_filter('smbb_wpcodetool_storage_drivers', function ($drivers) {
 *         $drivers['ftp'] = array('label' => 'FTP', 'fields' => array(...), 'secrets' => array('password'),
 *             'factory' => function (array $settings) { return new MyFtpDisk($settings); });
 *         return $drivers;
 *     });
 *
 * Dire qu'une unité sert (elle ne peut alors plus être supprimée) :
 *
 *     add_filter('smbb_wpcodetool_storage_usage', function ($usage, $unit_id) {
 *         if (...) { $usage[] = 'SignaConnect : organisation Proxima'; }
 *         return $usage;
 *     }, 10, 2);
 */
final class Storage
{
    const OPTION = 'smbb_codetool_storage';
    const DEFAULT_UNIT = 'wp';
    const TESTS_OPTION = 'smbb_codetool_storage_tests';

    /** @var array<string,DiskInterface> */
    private static $disks = array();

    public static function boot()
    {
        add_filter('smbb_wpcodetool_option_secrets', array(self::class, 'declareSecrets'));
    }

    /**
     * Pilotes : clé => {label, fields: [clé => {label, type, help, placeholder}], secrets: [clés], factory}.
     */
    public static function drivers()
    {
        $drivers = array(
            's3' => array(
                'label' => __('S3 (AWS ou compatible)', 'smbb-wpcodetool'),
                'icon' => 'dashicons-cloud',
                'fields' => array(
                    'endpoint' => array('label' => __('Adresse (endpoint)', 'smbb-wpcodetool'), 'placeholder' => 'https://s3.amazonaws.com', 'help' => __('Vide = AWS. OVH : https://s3.gra.io.cloud.ovh.net, Scaleway : https://s3.fr-par.scw.cloud…', 'smbb-wpcodetool')),
                    'region' => array('label' => __('Région', 'smbb-wpcodetool'), 'placeholder' => 'eu-west-3'),
                    'bucket' => array('label' => __('Bucket', 'smbb-wpcodetool'), 'required' => true),
                    'access_key' => array('label' => __('Clé d\'accès', 'smbb-wpcodetool'), 'required' => true),
                    'secret_key' => array('label' => __('Clé secrète', 'smbb-wpcodetool'), 'type' => 'password', 'required' => true),
                    'prefix' => array('label' => __('Préfixe (dossier)', 'smbb-wpcodetool'), 'placeholder' => 'mon-site/', 'help' => __('Facultatif : tous les fichiers de l\'unité sont rangés sous ce dossier du bucket.', 'smbb-wpcodetool')),
                    'path_style' => array('label' => __('Adresses « path style »', 'smbb-wpcodetool'), 'type' => 'toggle', 'help' => __('À cocher pour MinIO et certains hébergeurs (bucket dans le chemin au lieu du nom de domaine).', 'smbb-wpcodetool')),
                ),
                'secrets' => array('secret_key'),
                'factory' => static function (array $settings) {
                    return new S3Disk($settings);
                },
            ),
        );

        $drivers = (array) apply_filters('smbb_wpcodetool_storage_drivers', $drivers);

        foreach ($drivers as $key => $driver) {
            if (!is_array($driver) || empty($driver['factory']) || !is_callable($driver['factory'])) {
                unset($drivers[$key]);
                continue;
            }

            $drivers[$key] += array('label' => $key, 'icon' => 'dashicons-database', 'fields' => array(), 'secrets' => array());
        }

        return $drivers;
    }

    /**
     * Unité intégrée « WordPress » (dossier privé de uploads).
     */
    private static function builtin()
    {
        return array(
            'id' => self::DEFAULT_UNIT,
            'label' => __('WordPress (dossier uploads)', 'smbb-wpcodetool'),
            'driver' => 'wp',
            'settings' => array(),
            'builtin' => true,
        );
    }

    private static function stored()
    {
        $option = get_option(self::OPTION);

        return is_array($option) && isset($option['units']) && is_array($option['units']) ? $option['units'] : array();
    }

    /**
     * Toutes les unités, « WordPress » en premier. Les clés secrètes restent chiffrées.
     *
     * @return array<string,array>
     */
    public static function units()
    {
        $units = array(self::DEFAULT_UNIT => self::builtin());

        foreach (self::stored() as $id => $unit) {
            if (!is_array($unit) || $id === self::DEFAULT_UNIT) {
                continue;
            }

            $units[(string) $id] = array(
                'id' => (string) $id,
                'label' => (string) ($unit['label'] ?? $id),
                'driver' => (string) ($unit['driver'] ?? ''),
                'settings' => is_array($unit['settings'] ?? null) ? $unit['settings'] : array(),
                'builtin' => false,
                'created_at' => (string) ($unit['created_at'] ?? ''),
            );
        }

        return $units;
    }

    public static function get($id)
    {
        $units = self::units();

        return isset($units[(string) $id]) ? $units[(string) $id] : null;
    }

    public static function exists($id)
    {
        return self::get($id) !== null;
    }

    /**
     * Pour une liste déroulante : id => libellé (« WordPress » en premier).
     */
    public static function choices()
    {
        $drivers = self::drivers();
        $out = array();

        foreach (self::units() as $id => $unit) {
            $out[$id] = $unit['builtin'] || !isset($drivers[$unit['driver']])
                ? $unit['label']
                : $unit['label'] . ' · ' . $drivers[$unit['driver']]['label'];
        }

        return $out;
    }

    public static function label($id)
    {
        $unit = self::get($id);

        return $unit ? $unit['label'] : (string) $id;
    }

    /**
     * Le disque d'une unité, prêt à servir. Unité inconnue : exception (jamais de repli silencieux
     * vers une autre unité, les fichiers partiraient au mauvais endroit).
     *
     * @return DiskInterface
     */
    public static function disk($id = self::DEFAULT_UNIT)
    {
        $id = (string) ($id === '' || $id === null ? self::DEFAULT_UNIT : $id);

        if (isset(self::$disks[$id])) {
            return self::$disks[$id];
        }

        $unit = self::get($id);

        if (!$unit) {
            throw new \RuntimeException(sprintf(__('Unité de stockage inconnue : %s.', 'smbb-wpcodetool'), $id));
        }

        if ($unit['builtin']) {
            return self::$disks[$id] = new WpDisk();
        }

        $drivers = self::drivers();

        if (!isset($drivers[$unit['driver']])) {
            throw new \RuntimeException(sprintf(__('Le pilote de stockage « %s » n\'est plus disponible.', 'smbb-wpcodetool'), $unit['driver']));
        }

        return self::$disks[$id] = call_user_func($drivers[$unit['driver']]['factory'], self::plainSettings($unit));
    }

    /**
     * Réglages d'une unité avec les secrets déchiffrés (jamais affichés, seulement pour le pilote).
     */
    public static function plainSettings(array $unit)
    {
        $drivers = self::drivers();
        $settings = $unit['settings'];

        foreach ((array) ($drivers[$unit['driver']]['secrets'] ?? array()) as $key) {
            if (isset($settings[$key]) && is_string($settings[$key]) && $settings[$key] !== '') {
                $plain = Secrets::decrypt($settings[$key]);
                $settings[$key] = is_string($plain) ? $plain : '';
            }
        }

        return $settings;
    }

    /**
     * Crée ou modifie une unité. $settings en clair ; un secret vide garde l'ancien.
     *
     * @return string id de l'unité
     */
    public static function save($id, $label, $driver, array $settings)
    {
        $drivers = self::drivers();

        if (!isset($drivers[$driver])) {
            throw new \InvalidArgumentException(__('Type de stockage inconnu.', 'smbb-wpcodetool'));
        }

        $label = trim(sanitize_text_field((string) $label));

        if ($label === '') {
            throw new \InvalidArgumentException(__('Donnez un nom à l\'unité de stockage.', 'smbb-wpcodetool'));
        }

        $units = self::stored();
        $id = sanitize_key((string) $id);

        if ($id === self::DEFAULT_UNIT) {
            throw new \InvalidArgumentException(__('L\'unité WordPress ne se modifie pas.', 'smbb-wpcodetool'));
        }

        $previous = $id !== '' && isset($units[$id]) ? $units[$id] : null;

        if ($previous && ($previous['driver'] ?? '') !== $driver) {
            $previous = null;
        }

        if ($id === '' || !isset($units[$id])) {
            $id = self::newId($label, $units);
        }

        $clean = array();

        foreach ($drivers[$driver]['fields'] as $key => $field) {
            $type = $field['type'] ?? 'text';
            $value = $settings[$key] ?? '';

            if ($type === 'toggle') {
                $clean[$key] = !empty($value) ? 1 : 0;
                continue;
            }

            $value = trim((string) $value);

            if (in_array($key, (array) $drivers[$driver]['secrets'], true)) {
                if ($value === '') {
                    $clean[$key] = (string) ($previous['settings'][$key] ?? '');
                } else {
                    $clean[$key] = Secrets::encrypt($value);
                }
                continue;
            }

            $clean[$key] = $key === 'endpoint' ? esc_url_raw($value) : sanitize_text_field($value);
        }

        foreach ($drivers[$driver]['fields'] as $key => $field) {
            if (!empty($field['required']) && (string) ($clean[$key] ?? '') === '') {
                throw new \InvalidArgumentException(sprintf(__('Champ obligatoire : %s.', 'smbb-wpcodetool'), $field['label']));
            }
        }

        $units[$id] = array(
            'id' => $id,
            'label' => $label,
            'driver' => $driver,
            'settings' => $clean,
            'created_at' => (string) ($previous['created_at'] ?? current_time('mysql')),
        );

        self::write($units);

        return $id;
    }

    /**
     * Unités qui pointent déjà au même endroit (même pilote et mêmes réglages hors secrets) : pour éviter
     * les doublons lors d'une reprise (SignaConnect).
     */
    public static function findSame($driver, array $settings)
    {
        $drivers = self::drivers();
        $secrets = (array) ($drivers[$driver]['secrets'] ?? array());

        foreach (self::units() as $id => $unit) {
            if ($unit['builtin'] || $unit['driver'] !== $driver) {
                continue;
            }

            $same = true;

            foreach ($unit['settings'] as $key => $value) {
                if (in_array($key, $secrets, true)) {
                    continue;
                }

                if (trim((string) $value) !== trim((string) ($settings[$key] ?? ''))) {
                    $same = false;
                    break;
                }
            }

            if ($same) {
                return $id;
            }
        }

        return null;
    }

    /**
     * Où une unité sert (Formulaires, SignaConnect…). Une unité qui sert ne se supprime pas.
     *
     * @return string[]
     */
    public static function usage($id)
    {
        return array_values(array_filter(array_map('strval', (array) apply_filters('smbb_wpcodetool_storage_usage', array(), (string) $id))));
    }

    public static function delete($id)
    {
        $id = (string) $id;

        if ($id === self::DEFAULT_UNIT) {
            throw new \InvalidArgumentException(__('L\'unité WordPress ne peut pas être supprimée.', 'smbb-wpcodetool'));
        }

        $usage = self::usage($id);

        if ($usage) {
            throw new \InvalidArgumentException(sprintf(__('Unité utilisée par : %s. Choisissez une autre unité là-bas avant de la supprimer.', 'smbb-wpcodetool'), implode(', ', $usage)));
        }

        $units = self::stored();
        unset($units[$id], self::$disks[$id]);
        self::write($units);
    }

    /**
     * Test d'une unité : écrit, relit et supprime un petit fichier.
     *
     * @return array{ok:bool,message:string}
     */
    public static function test($id)
    {
        try {
            unset(self::$disks[(string) $id]);
            $result = self::disk($id)->test();
            $out = array('ok' => true, 'message' => is_string($result) && $result !== '' ? $result : __('Écriture, lecture et suppression réussies.', 'smbb-wpcodetool'));
        } catch (\Throwable $e) {
            $out = array('ok' => false, 'message' => $e->getMessage());
        }

        // Dernier test de chaque unité : affiché sur sa ligne dans CodeTool › Stockage.
        $tests = get_option(self::TESTS_OPTION);
        $tests = is_array($tests) ? $tests : array();
        $tests[(string) $id] = $out + array('at' => time());
        update_option(self::TESTS_OPTION, $tests, false);

        return $out;
    }

    /**
     * Dernier test d'une unité : {ok, message, at} ou null.
     */
    public static function lastTest($id)
    {
        $tests = get_option(self::TESTS_OPTION);

        return is_array($tests) && isset($tests[(string) $id]) && is_array($tests[(string) $id]) ? $tests[(string) $id] : null;
    }

    /**
     * Les clés secrètes des unités dans CodeTool › Chiffrement.
     */
    public static function declareSecrets($secrets)
    {
        $drivers = self::drivers();

        foreach (self::stored() as $id => $unit) {
            foreach ((array) ($drivers[$unit['driver'] ?? '']['secrets'] ?? array()) as $key) {
                $secrets[] = array(
                    'label' => sprintf(__('Stockage : %s', 'smbb-wpcodetool'), (string) ($unit['label'] ?? $id)),
                    'name' => (string) ($drivers[$unit['driver']]['fields'][$key]['label'] ?? $key),
                    'option' => self::OPTION,
                    'path' => array('units', (string) $id, 'settings', $key),
                );
            }
        }

        return $secrets;
    }

    private static function newId($label, array $units)
    {
        $base = substr(trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower(remove_accents((string) $label))), '-'), 0, 32);
        $base = rtrim($base, '-');
        $base = $base !== '' && $base !== self::DEFAULT_UNIT ? $base : 'unite';
        $id = $base;
        $n = 2;

        while (isset($units[$id]) || $id === self::DEFAULT_UNIT) {
            $id = $base . '-' . $n++;
        }

        return $id;
    }

    private static function write(array $units)
    {
        $option = get_option(self::OPTION);
        $option = is_array($option) ? $option : array();
        $option['units'] = $units;
        update_option(self::OPTION, $option, false);
        self::$disks = array();
    }
}
