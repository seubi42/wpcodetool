<?php

namespace Smbb\Passflow\Support;

defined('ABSPATH') || exit;

/**
 * Couleurs du back-office Passflow (Réglages › Général › Apparence) : une teinte d'accent, au choix dans
 * une palette ou libre, appliquée par variables CSS sur les écrans Passflow, la barre d'admin, la page
 * « Régler votre commande ». Option : boutons principaux aux mêmes couleurs.
 */
final class Theme
{
    const DEFAULT_ACCENT = '#b5476b';

    public static function hooks()
    {
        add_action('admin_enqueue_scripts', array(__CLASS__, 'inline'), 99);
        add_action('wp_enqueue_scripts', array(__CLASS__, 'inline'), 99);
        add_filter('smbb_codetool_default_theme', array(__CLASS__, 'codetoolDefault'));
        add_action('admin_init', array(__CLASS__, 'migrateToCodetool'));
    }

    /**
     * Reprise unique : l'ancien choix Passflow (ex. « Bleu ») devient le thème du site dans
     * CodeTool › Apparence, tant que rien n'y a encore été enregistré.
     */
    public static function migrateToCodetool()
    {
        if (!class_exists('\\Smbb\\WpCodeTool\\Admin\\Skin') || !current_user_can('manage_options')) {
            return;
        }

        $skin = '\\Smbb\\WpCodeTool\\Admin\\Skin';

        if ($skin::settings()['theme'] !== '') {
            return;
        }

        $choice = (string) Settings::get('admin_theme');
        $accent = (string) sanitize_hex_color((string) Settings::get('admin_accent'));

        if ($choice === 'custom' && $accent !== '') {
            $skin::saveSettings($skin::CUSTOM, $accent);
        } elseif (isset(self::palettes()[$choice])) {
            $skin::saveSettings($choice, '');
        }
    }

    /**
     * Tant que rien n'est choisi dans CodeTool › Apparence, le thème du site reprend l'ancien
     * réglage Passflow (Réglages › Général › Apparence, retiré de l'écran le 06/11/2026).
     */
    public static function codetoolDefault($theme)
    {
        $choice = (string) Settings::get('admin_theme');

        return isset(self::palettes()[$choice]) ? $choice : $theme;
    }

    /**
     * Vrai quand la peau CodeTool est active pour l'utilisateur courant (pas en apparence classique).
     */
    public static function skinActive()
    {
        return is_admin() && class_exists('\\Smbb\\WpCodeTool\\Admin\\Skin') && \Smbb\WpCodeTool\Admin\Skin::active();
    }

    /**
     * Palette proposée : clé => array(libellé, couleur).
     *
     * @return array<string,array{0:string,1:string}>
     */
    public static function palettes()
    {
        $d = 'smbb-passflow';

        return array(
            'aubergine' => array(__('Aubergine', $d), '#b5476b'),
            'framboise' => array(__('Framboise', $d), '#c2185b'),
            'rouge' => array(__('Rouge course', $d), '#c8102e'),
            'orange' => array(__('Orange', $d), '#d35400'),
            'or' => array(__('Or', $d), '#a67c00'),
            'vert' => array(__('Vert', $d), '#1e7a46'),
            'bleu' => array(__('Bleu', $d), '#2271b1'),
            'marine' => array(__('Bleu marine', $d), '#1d3557'),
            'ardoise' => array(__('Ardoise', $d), '#3c434a'),
        );
    }

    /**
     * Options du select des réglages (libellé => valeur), avec « Personnalisée ».
     *
     * @return array<string,string>
     */
    public static function options()
    {
        $out = array();

        foreach (self::palettes() as $key => $p) {
            $out[$p[0]] = $key;
        }

        $out[__('Personnalisée', 'smbb-passflow')] = 'custom';

        return $out;
    }

    public static function accent()
    {
        // Le thème se choisit maintenant dans CodeTool › Apparence (et dans le profil de chacun).
        if (class_exists('\\Smbb\\WpCodeTool\\Admin\\Skin')) {
            $skin = self::skinActive() ? \Smbb\WpCodeTool\Admin\Skin::accent() : \Smbb\WpCodeTool\Admin\Skin::siteAccent();

            if ($skin) {
                return $skin;
            }
        }

        $theme = (string) Settings::get('admin_theme');
        $palettes = self::palettes();

        if ($theme === 'custom') {
            $color = sanitize_hex_color((string) Settings::get('admin_accent'));

            return $color ?: self::DEFAULT_ACCENT;
        }

        return isset($palettes[$theme]) ? $palettes[$theme][1] : self::DEFAULT_ACCENT;
    }

    /**
     * Version plus foncée de l'accent (survol des boutons).
     */
    public static function darker($hex, $factor = 0.82)
    {
        $hex = ltrim((string) $hex, '#');

        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }

        $out = '#';

        foreach (str_split(substr($hex . '000000', 0, 6), 2) as $part) {
            $out .= str_pad(dechex((int) round(hexdec($part) * $factor)), 2, '0', STR_PAD_LEFT);
        }

        return $out;
    }

    public static function css()
    {
        $accent = self::accent();
        $dark = self::darker($accent);
        $css = '.passflow-admin,#wpadminbar #wp-admin-bar-passflow-today{--passflow-brand:' . $accent . ';--passflow-brand-dark:' . $dark . ';}';

        if ((int) Settings::get('admin_buttons') === 1) {
            $css .= '.passflow-admin .button-primary,.passflow-pos .button-primary{background:' . $accent . ';border-color:' . $accent . ';}'
                . '.passflow-admin .button-primary:hover,.passflow-admin .button-primary:focus,.passflow-pos .button-primary:hover{background:' . $dark . ';border-color:' . $dark . ';}';
        }

        return $css;
    }

    public static function inline()
    {
        if (wp_style_is('smbb-passflow-admin', 'enqueued')) {
            wp_add_inline_style('smbb-passflow-admin', self::css());
        }
    }
}
