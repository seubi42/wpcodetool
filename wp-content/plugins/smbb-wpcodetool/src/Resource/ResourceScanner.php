<?php

namespace Smbb\WpCodeTool\Resource;

// Le scanner dépend de constantes WordPress comme WP_PLUGIN_DIR.
defined('ABSPATH') || exit;

/**
 * Trouve les ressources CodeTool déclarées par les plugins actifs.
 *
 * Pour ce premier essai on scanne les dossiers :
 * wp-content/plugins/{plugin-actif}/codetool/models/*.json
 *
 * Important : on ne scanne pas tous les plugins installés, seulement les actifs.
 * C'est plus proche du comportement WordPress attendu et évite qu'un plugin désactivé
 * ajoute quand même des menus admin.
 */
final class ResourceScanner
{
    // Erreurs rencontrées pendant le scan. La page debug les affichera.
    private $errors = array();

    /**
     * Lance le scan et retourne les ressources valides.
     *
     * @return ResourceDefinition[]
     */
    public function scan()
    {
        $this->errors = array();
        $resources = array();

        foreach ($this->activePluginDirs() as $plugin_dir) {
            $models_dir = $plugin_dir . DIRECTORY_SEPARATOR . 'codetool' . DIRECTORY_SEPARATOR . 'models';

            if (!is_dir($models_dir)) {
                continue;
            }

            $model_files = glob($models_dir . DIRECTORY_SEPARATOR . '*.json') ?: array();
            sort($model_files);

            foreach ($model_files as $model_file) {
                $resource = $this->loadModel($plugin_dir, $model_file);

                if ($resource) {
                    if (isset($resources[$resource->name()])) {
                        $this->errors[] = array(
                            'file' => $model_file,
                            'message' => 'Nom de ressource en doublon : "' . $resource->name() . '".',
                        );

                        continue;
                    }

                    $resources[$resource->name()] = $resource;
                }
            }
        }

        return $resources;
    }

    /**
     * Retourne les erreurs du dernier scan.
     */
    public function errors()
    {
        return $this->errors;
    }

    /**
     * Calcule les dossiers des plugins actifs.
     *
     * active_plugins contient des chemins comme smbb-sample/smbb-sample.php.
     * On en déduit le dossier racine du plugin.
     */
    private function activePluginDirs()
    {
        $plugins = (array) get_option('active_plugins', array());

        // Multisite : les plugins activés réseau sont stockés dans une option de site.
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
     * Charge un fichier JSON de modèle.
     */
    private function loadModel($plugin_dir, $model_file)
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
                'message' => 'La ressource doit déclarer un champ "name".',
            );

            return null;
        }

        return new ResourceDefinition($data, $plugin_dir, $model_file);
    }
}
