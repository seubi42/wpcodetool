<?php

namespace Smbb\WpCodeTool\Resource;

// Le scanner depend de constantes WordPress comme WP_PLUGIN_DIR.
defined('ABSPATH') || exit;

/**
 * Trouve les ressources CodeTool declarees par les plugins actifs.
 *
 * Pour ce premier essai on scanne les dossiers :
 * wp-content/plugins/{plugin-actif}/codetool/models/*.json
 *
 * Important : on ne scanne pas tous les plugins installes, seulement les actifs.
 * C'est plus proche du comportement WordPress attendu et evite qu'un plugin desactive
 * ajoute quand meme des menus admin.
 */
final class ResourceScanner
{
    private const CACHE_GROUP = 'smbb_wpcodetool';
    private const CACHE_KEY = 'resource_scan';
    private const CACHE_OPTION = 'smbb_wpcodetool_resource_scan_cache';
    private const CACHE_SCHEMA_VERSION = '2';

    // Erreurs rencontrees pendant le scan. La page debug les affichera.
    private $errors = array();
    private $memory_fingerprint = '';
    private $memory_resources = array();
    private $memory_errors = array();
    private $validator;

    public function __construct(ResourceModelValidator $validator = null)
    {
        $this->validator = $validator ?: new ResourceModelValidator();
    }

    /**
     * Lance le scan et retourne les ressources valides.
     *
     * @return ResourceDefinition[]
     */
    public function scan()
    {
        $fingerprint = $this->fingerprint();

        if ($this->memory_fingerprint === $fingerprint) {
            $this->errors = $this->memory_errors;

            return $this->memory_resources;
        }

        $cached = $this->cachedPayload($fingerprint);

        if ($cached !== null) {
            return $this->restoreFromPayload($fingerprint, $cached);
        }

        return $this->freshScan($fingerprint);
    }

    /**
     * Retourne les erreurs du dernier scan.
     */
    public function errors()
    {
        return $this->errors;
    }

    /**
     * Lance un scan complet puis persiste le resultat.
     *
     * @return ResourceDefinition[]
     */
    private function freshScan($fingerprint)
    {
        $payload = $this->scanPayload();
        $resources = $this->resourcesFromPayload($payload['resources']);
        $errors = $this->normalizeErrors($payload['errors']);

        $this->remember($fingerprint, $resources, $errors);
        $this->storePersistentCache($fingerprint, array(
            'resources' => $payload['resources'],
            'errors' => $errors,
        ));

        return $resources;
    }

    /**
     * Reconstruit des ResourceDefinition depuis un cache persistant.
     *
     * @param array<string,mixed> $payload
     * @return ResourceDefinition[]
     */
    private function restoreFromPayload($fingerprint, array $payload)
    {
        $resources = $this->resourcesFromPayload(isset($payload['resources']) && is_array($payload['resources']) ? $payload['resources'] : array());
        $errors = $this->normalizeErrors(isset($payload['errors']) ? $payload['errors'] : array());

        $this->remember($fingerprint, $resources, $errors);

        return $resources;
    }

    /**
     * Lance le scan reel des fichiers JSON et retourne un payload cacheable.
     *
     * @return array<string,mixed>
     */
    private function scanPayload()
    {
        $this->errors = array();
        $resources = array();

        foreach ($this->activePluginDirs() as $plugin_dir) {
            foreach ($this->modelFiles($plugin_dir) as $model_file) {
                $data = $this->loadModel($model_file);

                if ($data === null) {
                    continue;
                }

                $validation_errors = $this->validator->validate($data, $plugin_dir, $model_file);

                if ($validation_errors) {
                    $this->errors = array_merge($this->errors, $validation_errors);
                    continue;
                }

                $resource = new ResourceDefinition($data, $plugin_dir, $model_file);

                if (isset($resources[$resource->name()])) {
                    $this->errors[] = array(
                        'file' => $model_file,
                        'message' => 'Nom de ressource en doublon : "' . $resource->name() . '".',
                    );

                    continue;
                }

                $resources[$resource->name()] = array(
                    'data' => $resource->raw(),
                    'plugin_dir' => $resource->pluginDir(),
                    'model_file' => $resource->modelFile(),
                );
            }
        }

        return array(
            'resources' => $resources,
            'errors' => $this->errors,
        );
    }

    /**
     * Empreinte stable des plugins actifs et de leurs modeles JSON.
     */
    private function fingerprint()
    {
        $parts = array('scanner_schema:' . self::CACHE_SCHEMA_VERSION);

        foreach ($this->activePluginDirs() as $plugin_dir) {
            $parts[] = $plugin_dir;

            foreach ($this->modelFiles($plugin_dir) as $model_file) {
                $parts[] = $model_file . '|' . (int) @filemtime($model_file) . '|' . (int) @filesize($model_file);
            }
        }

        return md5(implode("\n", $parts));
    }

    /**
     * Charge les fichiers modele d'un plugin en ordre stable.
     *
     * @return string[]
     */
    private function modelFiles($plugin_dir)
    {
        $models_dir = $plugin_dir . DIRECTORY_SEPARATOR . 'codetool' . DIRECTORY_SEPARATOR . 'models';

        if (!is_dir($models_dir)) {
            return array();
        }

        $model_files = glob($models_dir . DIRECTORY_SEPARATOR . '*.json') ?: array();
        sort($model_files);

        return $model_files;
    }

    /**
     * Calcule les dossiers des plugins actifs.
     *
     * active_plugins contient des chemins comme smbb-sample/smbb-sample.php.
     * On en deduit le dossier racine du plugin.
     */
    private function activePluginDirs()
    {
        $plugins = (array) get_option('active_plugins', array());

        // Multisite : les plugins actives reseau sont stockes dans une option de site.
        if (is_multisite()) {
            $network_plugins = array_keys((array) get_site_option('active_sitewide_plugins', array()));
            $plugins = array_merge($plugins, $network_plugins);
        }

        $dirs = array();

        foreach (array_unique($plugins) as $plugin_file) {
            $relative_dir = dirname((string) $plugin_file);

            if ($relative_dir === '.' || $relative_dir === DIRECTORY_SEPARATOR) {
                $plugin_dir = WP_PLUGIN_DIR;
            } else {
                $plugin_dir = WP_PLUGIN_DIR . DIRECTORY_SEPARATOR . str_replace(array('/', '\\'), DIRECTORY_SEPARATOR, $relative_dir);
            }

            if (is_dir($plugin_dir)) {
                $dirs[$plugin_dir] = $plugin_dir;
            }
        }

        return array_values($dirs);
    }

    /**
     * Charge un fichier JSON de modele.
     *
     * @return array<string,mixed>|null
     */
    private function loadModel($model_file)
    {
        $json = file_get_contents($model_file);

        if ($json === false) {
            $this->errors[] = array(
                'file' => $model_file,
                'message' => 'Impossible de lire le fichier JSON.',
            );

            return null;
        }

        $data = json_decode($json, true);

        if (!is_array($data)) {
            $this->errors[] = array(
                'file' => $model_file,
                'message' => 'JSON invalide : ' . json_last_error_msg(),
            );

            return null;
        }

        if (empty($data['name'])) {
            $this->errors[] = array(
                'file' => $model_file,
                'message' => 'La ressource doit declarer un champ "name".',
            );

            return null;
        }

        return $data;
    }

    /**
     * Lit le cache persistant si son empreinte correspond.
     *
     * @return array<string,mixed>|null
     */
    private function cachedPayload($fingerprint)
    {
        $cached = wp_cache_get(self::CACHE_KEY, self::CACHE_GROUP);

        if (!is_array($cached)) {
            $cached = get_option(self::CACHE_OPTION, array());
        }

        if (!is_array($cached) || empty($cached['fingerprint']) || $cached['fingerprint'] !== $fingerprint) {
            return null;
        }

        if (!isset($cached['resources']) || !is_array($cached['resources'])) {
            return null;
        }

        wp_cache_set(self::CACHE_KEY, $cached, self::CACHE_GROUP);

        return array(
            'resources' => $cached['resources'],
            'errors' => isset($cached['errors']) ? $cached['errors'] : array(),
        );
    }

    /**
     * Persiste le resultat du scan pour les prochaines requetes.
     *
     * @param array<string,mixed> $payload
     */
    private function storePersistentCache($fingerprint, array $payload)
    {
        $cached = array(
            'fingerprint' => (string) $fingerprint,
            'resources' => isset($payload['resources']) && is_array($payload['resources']) ? $payload['resources'] : array(),
            'errors' => $this->normalizeErrors(isset($payload['errors']) ? $payload['errors'] : array()),
        );

        wp_cache_set(self::CACHE_KEY, $cached, self::CACHE_GROUP);
        update_option(self::CACHE_OPTION, $cached, false);
    }

    /**
     * Remet en memoire le dernier scan utile a la requete courante.
     *
     * @param ResourceDefinition[] $resources
     * @param array<int,array<string,string>> $errors
     */
    private function remember($fingerprint, array $resources, array $errors)
    {
        $this->memory_fingerprint = (string) $fingerprint;
        $this->memory_resources = $resources;
        $this->memory_errors = $errors;
        $this->errors = $errors;
    }

    /**
     * Reconstruit les objets ResourceDefinition depuis un payload serialisable.
     *
     * @param array<string,mixed> $resource_payloads
     * @return ResourceDefinition[]
     */
    private function resourcesFromPayload(array $resource_payloads)
    {
        $resources = array();

        foreach ($resource_payloads as $payload) {
            if (!is_array($payload) || !isset($payload['data']) || !is_array($payload['data']) || empty($payload['plugin_dir']) || empty($payload['model_file'])) {
                continue;
            }

            $resource = new ResourceDefinition(
                $payload['data'],
                (string) $payload['plugin_dir'],
                (string) $payload['model_file']
            );

            $resources[$resource->name()] = $resource;
        }

        return $resources;
    }

    /**
     * Normalise les erreurs pour garantir un format stable dans le cache.
     *
     * @param mixed $errors
     * @return array<int,array<string,string>>
     */
    private function normalizeErrors($errors)
    {
        if (!is_array($errors)) {
            return array();
        }

        $normalized = array();

        foreach ($errors as $error) {
            if (!is_array($error) || empty($error['message'])) {
                continue;
            }

            $normalized[] = array(
                'file' => isset($error['file']) ? (string) $error['file'] : '',
                'message' => (string) $error['message'],
            );
        }

        return $normalized;
    }
}
