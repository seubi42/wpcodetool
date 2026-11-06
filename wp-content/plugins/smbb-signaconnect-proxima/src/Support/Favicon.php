<?php

namespace Smbb\SignConnectProxima\Support;

defined('ABSPATH') || exit;

/**
 * Favicon SignaConnect (Réglages > « Favicon SignaConnect ») : remplace l'icône du site
 * sur le front, l'admin et la page de connexion. Fichiers dans assets/favicon/.
 */
final class Favicon
{
    const OPTION = 'smbb_scp_settings';

    public static function hooks()
    {
        add_action('init', array(__CLASS__, 'boot'));
    }

    public static function boot()
    {
        if (!self::enabled()) {
            return;
        }

        // L'icône du site WordPress (Personnaliser > Identité du site) est remplacée, pas doublée.
        remove_action('wp_head', 'wp_site_icon', 99);
        remove_action('admin_head', 'wp_site_icon');
        remove_action('login_head', 'wp_site_icon', 99);

        add_action('wp_head', array(__CLASS__, 'render'), 99);
        add_action('admin_head', array(__CLASS__, 'render'));
        add_action('login_head', array(__CLASS__, 'render'), 99);
        add_filter('get_site_icon_url', array(__CLASS__, 'siteIconUrl'), 10, 2);
    }

    public static function enabled()
    {
        $settings = get_option(self::OPTION, array());

        return is_array($settings) && !empty($settings['favicon']);
    }

    public static function url($file)
    {
        return SMBB_SCP_URL . 'assets/favicon/' . $file . '?v=' . rawurlencode(SMBB_SCP_VERSION);
    }

    public static function render()
    {
        echo '<link rel="icon" href="' . esc_url(self::url('favicon.ico')) . '" sizes="32x32" />' . "\n";
        echo '<link rel="icon" href="' . esc_url(self::url('favicon.svg')) . '" type="image/svg+xml" />' . "\n";
        echo '<link rel="apple-touch-icon" href="' . esc_url(self::url('apple-touch-icon.png')) . '" />' . "\n";
    }

    /**
     * Utilisé par WordPress ailleurs (flux RSS, applications, etc.).
     */
    public static function siteIconUrl($url, $size)
    {
        return self::url((int) $size > 0 && (int) $size <= 32 ? 'favicon-32.png' : 'favicon-192.png');
    }
}
