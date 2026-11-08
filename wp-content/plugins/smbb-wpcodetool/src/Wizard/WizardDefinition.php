<?php

namespace Smbb\WpCodeTool\Wizard;

defined('ABSPATH') || exit;

/**
 * Assistant de démarrage déclaré par un plugin dans codetool/wizards/<nom>.json.
 *
 * Format :
 * {
 *   "name": "passflow",                       // clé unique (lettres, chiffres, _ et -)
 *   "title": "Bienvenue dans Passflow",
 *   "description": "…",
 *   "capability": "manage_options",           // droit pour ouvrir l'assistant
 *   "redirectOnActivation": true,             // ouvert une fois à l'activation du plugin
 *   "menuParent": "smbb-codetool-passflow",   // bandeau « Terminer la configuration » sur ces pages
 *   "hooks": { "file": "hooks/WizardHooks.php", "class": "Vendor\\Plugin\\WizardHooks" },
 *   "steps": [ { "key": "shop", "type": "plugins", "title": "…", … } ]
 * }
 *
 * Types d'étapes : appearance, plugins, form, profiles, view, finish (voir WizardManager).
 * Les chemins (hooks.file, view, dir) sont relatifs au dossier codetool/ du plugin, sauf « dir »
 * des profils, relatif à la racine du plugin.
 */
final class WizardDefinition
{
    const TYPES = array('appearance', 'plugins', 'form', 'profiles', 'view', 'finish');

    /** @var array<string,mixed> */
    private $data;

    /** @var string dossier du plugin, sans séparateur final */
    private $plugin_dir;

    /** @var string */
    private $file;

    /** @var array<int,array<string,mixed>> */
    private $steps = array();

    public function __construct(array $data, $plugin_dir, $file)
    {
        $this->data = $data;
        $this->plugin_dir = rtrim((string) $plugin_dir, '/\\');
        $this->file = (string) $file;

        $seen = array();

        foreach (isset($data['steps']) && is_array($data['steps']) ? $data['steps'] : array() as $step) {
            if (!is_array($step) || empty($step['key']) || empty($step['type']) || !in_array($step['type'], self::TYPES, true)) {
                continue;
            }

            $key = sanitize_key((string) $step['key']);

            if ($key === '' || isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $step['key'] = $key;
            $step['title'] = isset($step['title']) ? (string) $step['title'] : $key;
            $step['description'] = isset($step['description']) ? (string) $step['description'] : '';
            $step['icon'] = isset($step['icon']) ? (string) $step['icon'] : '';
            $step['optional'] = !empty($step['optional']);
            $this->steps[] = $step;
        }
    }

    /**
     * Assistant valide : un nom et au moins une étape.
     */
    public function valid()
    {
        return $this->name() !== '' && !empty($this->steps);
    }

    public function name()
    {
        return isset($this->data['name']) ? sanitize_key((string) $this->data['name']) : '';
    }

    public function title()
    {
        return isset($this->data['title']) ? (string) $this->data['title'] : $this->name();
    }

    public function description()
    {
        return isset($this->data['description']) ? (string) $this->data['description'] : '';
    }

    public function capability()
    {
        return !empty($this->data['capability']) ? (string) $this->data['capability'] : 'manage_options';
    }

    public function redirectOnActivation()
    {
        return !empty($this->data['redirectOnActivation']);
    }

    public function menuParent()
    {
        return isset($this->data['menuParent']) ? sanitize_key((string) $this->data['menuParent']) : '';
    }

    public function pluginDir()
    {
        return $this->plugin_dir;
    }

    public function file()
    {
        return $this->file;
    }

    /**
     * Chemin d'un fichier relatif au dossier codetool/ du plugin (null s'il sort du plugin).
     */
    public function codetoolPath($relative)
    {
        return $this->safePath($this->plugin_dir . DIRECTORY_SEPARATOR . 'codetool', $relative);
    }

    /**
     * Chemin d'un fichier relatif à la racine du plugin (null s'il sort du plugin).
     */
    public function pluginPath($relative)
    {
        return $this->safePath($this->plugin_dir, $relative);
    }

    private function safePath($base, $relative)
    {
        $relative = ltrim(str_replace(array('/', '\\'), DIRECTORY_SEPARATOR, (string) $relative), DIRECTORY_SEPARATOR);

        if ($relative === '' || strpos($relative, '..') !== false) {
            return null;
        }

        return $base . DIRECTORY_SEPARATOR . $relative;
    }

    public function hookFile()
    {
        return isset($this->data['hooks']['file']) ? $this->codetoolPath($this->data['hooks']['file']) : null;
    }

    public function hookClass()
    {
        return isset($this->data['hooks']['class']) ? (string) $this->data['hooks']['class'] : '';
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function steps()
    {
        return $this->steps;
    }

    /**
     * @return array<string,mixed>|null
     */
    public function step($key)
    {
        foreach ($this->steps as $step) {
            if ($step['key'] === $key) {
                return $step;
            }
        }

        return null;
    }
}
