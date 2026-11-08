<?php

namespace Smbb\WpCodeTool\Wizard;

defined('ABSPATH') || exit;

/**
 * Trouve les assistants déclarés par les plugins actifs : codetool/wizards/*.json.
 *
 * Peu de fichiers, lus seulement sur les écrans d'admin : pas de cache persistant,
 * juste la mémoire de la requête.
 */
final class WizardScanner
{
    /** @var WizardDefinition[]|null */
    private static $wizards = null;

    /**
     * @return WizardDefinition[] indexés par nom
     */
    public static function all()
    {
        if (self::$wizards !== null) {
            return self::$wizards;
        }

        self::$wizards = array();

        foreach (self::activePluginDirs() as $dir) {
            foreach (self::fromPluginDir($dir) as $wizard) {
                if (!isset(self::$wizards[$wizard->name()])) {
                    self::$wizards[$wizard->name()] = $wizard;
                }
            }
        }

        return self::$wizards;
    }

    /**
     * @return WizardDefinition|null
     */
    public static function find($name)
    {
        $all = self::all();
        $name = sanitize_key((string) $name);

        return isset($all[$name]) ? $all[$name] : null;
    }

    /**
     * Assistants d'un dossier de plugin (même désactivé : sert à l'activation).
     *
     * @return WizardDefinition[]
     */
    public static function fromPluginDir($dir)
    {
        $dir = rtrim((string) $dir, '/\\');
        $files = glob($dir . DIRECTORY_SEPARATOR . 'codetool' . DIRECTORY_SEPARATOR . 'wizards' . DIRECTORY_SEPARATOR . '*.json');
        $out = array();

        foreach (is_array($files) ? $files : array() as $file) {
            $data = json_decode((string) file_get_contents($file), true);

            if (!is_array($data)) {
                continue;
            }

            $wizard = new WizardDefinition($data, $dir, $file);

            if ($wizard->valid()) {
                $out[] = $wizard;
            }
        }

        return $out;
    }

    /**
     * Oublie le scan (après l'activation d'un plugin par l'assistant).
     */
    public static function flush()
    {
        self::$wizards = null;
    }

    /**
     * @return string[]
     */
    private static function activePluginDirs()
    {
        $plugins = (array) get_option('active_plugins', array());

        if (is_multisite()) {
            $plugins = array_merge($plugins, array_keys((array) get_site_option('active_sitewide_plugins', array())));
        }

        $dirs = array();

        foreach (array_unique($plugins) as $plugin_file) {
            $relative = dirname((string) $plugin_file);

            if ($relative === '.' || $relative === '') {
                continue;
            }

            $dir = WP_PLUGIN_DIR . DIRECTORY_SEPARATOR . str_replace(array('/', '\\'), DIRECTORY_SEPARATOR, $relative);

            if (is_dir($dir)) {
                $dirs[$dir] = $dir;
            }
        }

        return array_values($dirs);
    }
}
