<?php

namespace Smbb\Sample\CodeTool;

// Hooks d'exemple pour une ressource de type "settings_page" stockée dans wp_options.
defined('ABSPATH') || exit;

/**
 * Nettoyage et validation de la page de réglages sample_settings.
 *
 * Différence avec TestHooks : ici il n'y a pas plusieurs lignes SQL.
 * On manipule un seul objet de configuration stocké dans la table wp_options.
 */
final class SampleSettingsHooks
{
    /**
     * Normalise la structure de l'objet avant validation.
     *
     * Le but est que validate() puisse travailler sur un objet complet, même si le formulaire
     * n'a pas envoyé certaines clés comme features[] ou api[...].
     */
    public function beforeValidate(array $data, array $context = array())
    {
        // Les checkbox/toggle HTML n'envoient souvent rien quand ils sont décochés.
        // On force donc un booléen propre.
        $data['enabled'] = !empty($data['enabled']);
        $data['label'] = isset($data['label']) ? trim((string) $data['label']) : '';
        $data['support_email'] = isset($data['support_email']) ? trim((string) $data['support_email']) : '';

        // Le groupe api est un sous-objet de l'option.
        if (!isset($data['api']) || !is_array($data['api'])) {
            $data['api'] = array();
        }

        $data['api']['endpoint'] = isset($data['api']['endpoint']) ? trim((string) $data['api']['endpoint']) : '';
        $data['api']['token'] = isset($data['api']['token']) ? trim((string) $data['api']['token']) : '';
        $data['api']['timeout'] = isset($data['api']['timeout']) ? (int) $data['api']['timeout'] : 10;

        // features est une liste de clés. Si aucune case n'est cochée, on veut un tableau vide.
        if (!isset($data['features']) || !is_array($data['features'])) {
            $data['features'] = array();
        }

        // sanitize_key évite de stocker une valeur inattendue dans cette liste.
        $data['features'] = array_values(array_filter(array_map('sanitize_key', $data['features'])));

        return $data;
    }

    /**
     * Valide les réglages.
     *
     * On retourne des clés lisibles par le futur form builder. Pour les sous-objets,
     * on utilise ici une notation pointée, par exemple api.timeout.
     */
    public function validate(array $data, array $context = array())
    {
        $errors = array();

        if ($data['support_email'] !== '' && !is_email($data['support_email'])) {
            $errors['support_email'] = 'Support email must be valid.';
        }

        if ($data['api']['endpoint'] !== '' && filter_var($data['api']['endpoint'], FILTER_VALIDATE_URL) === false) {
            $errors['api.endpoint'] = 'API endpoint must be a valid URL.';
        }

        if ($data['api']['timeout'] < 1 || $data['api']['timeout'] > 120) {
            $errors['api.timeout'] = 'Timeout must be between 1 and 120 seconds.';
        }

        return $errors;
    }

    /**
     * Dernière normalisation avant update_option().
     */
    public function beforeSave(array $data, array $context = array())
    {
        // Même si la validation est contournée par erreur, on borne encore la valeur.
        $data['api']['timeout'] = max(1, min(120, (int) $data['api']['timeout']));

        return $data;
    }

    /**
     * Point d'extension après sauvegarde des réglages.
     */
    public function afterSave(array $settings, array $context = array())
    {
        do_action('smbb_sample_settings_saved', $settings, $context);
    }
}
