<?php

namespace Smbb\WpCodeTool\Wizard;

defined('ABSPATH') || exit;

/**
 * Plugins demandés par une étape « plugins » : état, installation depuis wordpress.org, activation,
 * et coupure de l'assistant propre au plugin quand le nôtre le remplace (skipOnboarding).
 */
final class PluginInstaller
{
    /**
     * Fichier principal d'un plugin installé (dossier/fichier.php), ou ''.
     */
    public static function file($slug, $hint = '')
    {
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $slug = sanitize_key((string) $slug);
        $plugins = get_plugins();

        if ($hint !== '' && isset($plugins[$hint])) {
            return $hint;
        }

        if (isset($plugins[$slug . '/' . $slug . '.php'])) {
            return $slug . '/' . $slug . '.php';
        }

        foreach (array_keys($plugins) as $file) {
            if (strpos($file, $slug . '/') === 0) {
                return $file;
            }
        }

        return '';
    }

    /**
     * missing, installed (pas actif) ou active.
     */
    public static function status($slug, $hint = '')
    {
        $file = self::file($slug, $hint);

        if ($file === '') {
            return 'missing';
        }

        return is_plugin_active($file) ? 'active' : 'installed';
    }

    /**
     * Installe (si besoin) puis active. Retourne true ou un message d'erreur.
     *
     * @return true|string
     */
    public static function installAndActivate($slug, $hint = '')
    {
        $slug = sanitize_key((string) $slug);
        $file = self::file($slug, $hint);

        if ($file === '') {
            if (!current_user_can('install_plugins')) {
                return __('Votre compte ne peut pas installer d\'extensions : demandez à un administrateur.', 'smbb-wpcodetool');
            }

            require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
            require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/misc.php';

            $api = plugins_api('plugin_information', array('slug' => $slug, 'fields' => array('sections' => false)));

            if (is_wp_error($api)) {
                return sprintf(__('Extension introuvable sur wordpress.org : %s', 'smbb-wpcodetool'), $api->get_error_message());
            }

            $skin = new \WP_Ajax_Upgrader_Skin();
            $upgrader = new \Plugin_Upgrader($skin);
            $result = $upgrader->install($api->download_link);

            if (is_wp_error($result)) {
                return $result->get_error_message();
            }

            if (is_wp_error($skin->result)) {
                return $skin->result->get_error_message();
            }

            if ($skin->get_errors()->has_errors()) {
                return $skin->get_error_messages();
            }

            if (!$result) {
                return __('L\'installation n\'a pas abouti (droits d\'écriture sur wp-content/plugins ?).', 'smbb-wpcodetool');
            }

            wp_clean_plugins_cache();
            $file = self::file($slug, $hint);

            if ($file === '') {
                return __('Extension téléchargée mais introuvable ensuite.', 'smbb-wpcodetool');
            }
        }

        if (is_plugin_active($file)) {
            return true;
        }

        if (!current_user_can('activate_plugins')) {
            return __('Votre compte ne peut pas activer d\'extensions : demandez à un administrateur.', 'smbb-wpcodetool');
        }

        $activated = activate_plugin($file, '', false, true);

        if (is_wp_error($activated)) {
            return $activated->get_error_message();
        }

        WizardScanner::flush();

        return true;
    }

    /**
     * Coupe l'assistant de démarrage d'un plugin connu (il ferait doublon avec le nôtre).
     * Extensible : filtre smbb_wpcodetool_wizard_skip_onboarding ($done, $slug).
     */
    public static function skipOnboarding($slug)
    {
        $slug = sanitize_key((string) $slug);

        if ($slug === 'woocommerce') {
            delete_transient('_wc_activation_redirect');

            $profile = get_option('woocommerce_onboarding_profile', array());
            $profile = is_array($profile) ? $profile : array();
            $profile['skipped'] = true;
            $profile['completed'] = true;
            update_option('woocommerce_onboarding_profile', $profile);

            update_option('woocommerce_task_list_hidden', 'yes');
            update_option('woocommerce_extended_task_list_hidden', 'yes');
            update_option('woocommerce_show_marketplace_suggestions', 'no');
            update_option('woocommerce_allow_tracking', 'no');
            update_option('woocommerce_task_list_welcome_modal_dismissed', 'yes');
            update_option('woocommerce_coming_soon', 'no');
        }

        do_action('smbb_wpcodetool_wizard_skip_onboarding', $slug);
    }

    /**
     * Garde-fous permanents tant qu'un assistant CodeTool remplace celui de WooCommerce.
     */
    public static function woocommerceGuards()
    {
        add_filter('woocommerce_prevent_automatic_wizard_redirect', '__return_true');
        add_filter('woocommerce_enable_setup_wizard', '__return_false');
    }
}
