<?php

namespace Smbb\WpCodeTool\Admin;

defined('ABSPATH') || exit;

/**
 * Peau « premium » des écrans CodeTool : thèmes nommés, chacun avec sa palette complète.
 *
 * - Le premier thème s'appelle « Aubergine » ; un plugin en ajoute d'autres avec le filtre
 *   `smbb_codetool_themes` (une couleur d'accent suffit, le reste est calculé).
 * - Le thème du site vient du filtre `smbb_codetool_default_theme` (un plugin comme Tomo y
 *   branche son réglage), sinon « aubergine ».
 * - Chaque utilisateur peut choisir un autre thème, ou l'apparence classique, dans son profil.
 * - La typo reste celle de l'admin WordPress : la peau ne charge aucune police.
 *
 * Mécanique : la classe `smbb-skin` est posée sur le <body> des pages CodeTool, les couleurs du
 * thème sont écrites en variables `--smbb-t-*`, et assets/theme.css les applique par-dessus
 * admin.css. Apparence classique = ni classe ni fichier, donc l'écran d'avant à l'identique.
 */
final class Skin
{
    const CLASSIC = 'classic';
    const CUSTOM = 'custom';
    const USER_META = 'smbb_codetool_theme';
    const OPTION = 'smbb_codetool_appearance';
    const USER_MENU = 'smbb_codetool_menu_style';

    /** @var array<string,array>|null */
    private static $themes = null;

    /** @var string|null */
    private static $current = null;

    /** Interrupteurs du réglage du site (0/1) et leur valeur par défaut. */
    const SWITCHES = array(
        'modern' => 1,      // Style moderne (sinon apparence classique pour tout le monde).
        'icons' => 1,       // Icônes dans la barre d'app.
        'wp_chrome' => 0,   // Barre du haut et menu de gauche de WordPress aux couleurs du thème.
        'wp_screens' => 0,  // Écrans natifs de WordPress (articles, réglages…) harmonisés.
        'wp_notices' => 0,  // Messages des autres extensions rangés derrière une cloche.
        'login' => 0,       // Page de connexion aux couleurs du thème, logo vers le site.
        'login_clock' => 0, // Page de connexion : heure et date en direct au-dessus du logo, façon écran de verrouillage.
        'wp_profile' => 0,  // Page profil / compte utilisateur rangée en cartes avec sommaire.
        'fx_glass' => 0,    // Effets : barres et menus en verre dépoli.
        'fx_transitions' => 0, // Effets : fondu enchaîné entre les pages de l'admin.
        'fx_toasts' => 0,   // Effets : confirmations de WordPress en bulles qui disparaissent seules.
        'fx_skeleton' => 0, // Effets : squelette de chargement pendant qu'une page se charge.
        'fx_prefetch' => 0, // Effets : pages préchargées au survol d'un lien (Chrome, Edge), elles s'ouvrent aussitôt.
        'wc_account' => 0,  // Côté site : page « Mon compte » de WooCommerce aux couleurs du thème.
        'wc_thanks' => 0,   // Côté site : page « Commande reçue » (carte Merci animée, récap et réservations en cartes).
        'favicon' => 0,     // Favicon (admin, connexion, site) = logo de la page de connexion.
        'wl_brand' => 0,    // Marque blanche : logo du site dans la barre du haut, pied de page à soi, sans version.
        'adminbar_clean' => 0, // Barre du haut allégée : sans « + Nouveau », commentaires ni logo WordPress.
        'dashboard_clean' => 0, // Accueil de l'admin sans les widgets de WordPress.
        'list_columns' => 0,   // Listes : vignette et ID dans les articles, pages et contenus.
        'list_duplicate' => 1, // Listes : « Dupliquer » (ligne, action groupée, barre du haut), copie en brouillon. Indépendant du style moderne.
        'list_children' => 1,  // Listes hiérarchiques : bouton « Enfants de… » (arbre des parents) en haut.
        'palette' => 0,        // Recherche Ctrl K dans toute l'admin (pages, contenus, actions).
        'mode_toggle' => 1,    // Bascule Clair / Sombre / Auto dans la barre du haut, à gauche du compte.
        'wp_media' => 0,       // Médiathèque, fenêtre des médias et éditeur de blocs à la couleur du thème.
        'list_ux' => 1,        // Listes : barre d'actions groupées flottante et dates lisibles (« Aujourd'hui, 14:05 »).
        'porsche_font' => 0,   // Allure Porsche : police Porsche Next depuis le CDN Porsche (projets Porsche seulement).
        'cristal_font' => 1,   // Allure Cristal : polices Geist et Geist Mono (Google Fonts), sinon police système.
    );

    const USER_DENSITY = 'smbb_codetool_density';
    const USER_MODE = 'smbb_codetool_mode';

    /** Mode d'affichage : clé => libellé. « auto » suit le réglage clair/sombre de l'appareil. */
    public static function modes()
    {
        return array(
            'light' => __('Clair', 'smbb-wpcodetool'),
            'dark' => __('Sombre', 'smbb-wpcodetool'),
            'auto' => __('Auto', 'smbb-wpcodetool'),
        );
    }

    /** Mode pour l'utilisateur courant : son choix (profil), sinon celui du site. */
    public static function mode()
    {
        $user = (string) get_user_meta(get_current_user_id(), self::USER_MODE, true);

        return isset(self::modes()[$user]) ? $user : self::settings()['mode'];
    }

    /**
     * Allures du style moderne : clé => libellé. « galet » est le style d'origine (cartes douces,
     * dégradés) ; « porsche » reprend les codes du Porsche Design System v4 (assets/preset-porsche.css).
     */
    public static function presets()
    {
        return array(
            'galet' => __('Galet', 'smbb-wpcodetool'),
            'porsche' => __('Porsche', 'smbb-wpcodetool'),
            'cristal' => __('Cristal', 'smbb-wpcodetool'),
        );
    }

    /** Densité d'affichage : clé => libellé. */
    public static function densities()
    {
        return array(
            'comfortable' => __('Confortable', 'smbb-wpcodetool'),
            'compact' => __('Compacte', 'smbb-wpcodetool'),
        );
    }

    /** Densité pour l'utilisateur courant : son choix (profil), sinon celle du site. */
    public static function density()
    {
        $user = (string) get_user_meta(get_current_user_id(), self::USER_DENSITY, true);

        return isset(self::densities()[$user]) ? $user : self::settings()['density'];
    }

    /**
     * Styles du menu de gauche (option « Barre du haut et menu de gauche ») : clé => libellé.
     *
     * @return array<string,string>
     */
    public static function menuStyles()
    {
        return array(
            'light' => __('Clair en pastilles', 'smbb-wpcodetool'),
            'dark' => __('Sombre teinté', 'smbb-wpcodetool'),
            'rail' => __('Rail d\'icônes + panneau', 'smbb-wpcodetool'),
            'float' => __('Carte flottante', 'smbb-wpcodetool'),
        );
    }

    /**
     * Style du menu pour l'utilisateur courant : son choix (profil), sinon celui du site.
     */
    public static function menuStyle()
    {
        $styles = self::menuStyles();
        $user = (string) get_user_meta(get_current_user_id(), self::USER_MENU, true);

        return isset($styles[$user]) ? $user : self::settings()['menu_style'];
    }

    public static function hooks()
    {
        WpSkin::hooks();
        UxPrefs::hooks();
        add_action('show_user_profile', array(__CLASS__, 'profileField'));
        add_action('edit_user_profile', array(__CLASS__, 'profileField'));
        add_action('personal_options_update', array(__CLASS__, 'saveProfileField'));
        add_action('edit_user_profile_update', array(__CLASS__, 'saveProfileField'));
        add_action('admin_head', array(__CLASS__, 'modeScript'), 0);
    }

    /**
     * Thèmes disponibles : clé => définition (label, accent, et éventuellement toutes les teintes).
     *
     * @return array<string,array>
     */
    public static function themes()
    {
        if (self::$themes !== null) {
            return self::$themes;
        }

        $themes = array(
            // Réglé à la main (maquette validée par Seb le 06/11/2026).
            'aubergine' => array(
                'label' => __('Aubergine', 'smbb-wpcodetool'),
                'accent' => '#a8406a',
                'accent_hover' => '#8f3459',
                'accent_soft' => '#f5e3eb',
                'accent_deep' => '#6e2446',
                'bg' => '#f6f4f7',
                'fill' => '#efecf1',
                'fill_hover' => '#e6e1e9',
                'text' => '#1c1820',
                'muted' => '#6c6573',
                'line' => '#e3dee7',
                // Palette sombre prête pour un mode sombre (pas encore activé : il reste trop de
                // couleurs claires écrites en dur dans les CSS des plugins).
                'dark' => array(
                    'accent' => '#e0759f',
                    'accent_ink' => '#1c1018',
                    'accent_soft' => '#3a2130',
                    'accent_deep' => '#f3b6cd',
                    'bg' => '#141116',
                    'card' => '#1d1920',
                    'fill' => '#252028',
                    'text' => '#f1edf3',
                    'muted' => '#a39aa9',
                    'line' => '#332c37',
                ),
            ),
        );

        // Variantes prêtes à l'emploi : une couleur d'accent, le reste est calculé.
        $accents = array(
            'framboise' => array(__('Framboise', 'smbb-wpcodetool'), '#c2185b'),
            'rouge' => array(__('Rouge course', 'smbb-wpcodetool'), '#c8102e'),
            'orange' => array(__('Orange', 'smbb-wpcodetool'), '#d35400'),
            'or' => array(__('Or', 'smbb-wpcodetool'), '#a67c00'),
            'vert' => array(__('Vert', 'smbb-wpcodetool'), '#1e7a46'),
            'bleu' => array(__('Bleu', 'smbb-wpcodetool'), '#2271b1'),
            'marine' => array(__('Bleu marine', 'smbb-wpcodetool'), '#1d3557'),
            'ardoise' => array(__('Ardoise', 'smbb-wpcodetool'), '#3c434a'),
        );

        foreach ($accents as $key => $theme) {
            $themes[$key] = array('label' => $theme[0], 'accent' => $theme[1]);
        }

        // Palettes à dégradé (esprit Apple / UniFi) : l'accent glisse vers une 2e couleur sur les
        // boutons principaux, l'entrée courante du menu et les icônes d'app. Gris neutres, à peine teintés.
        $gradients = array(
            'apple' => array(__('Bleu Apple', 'smbb-wpcodetool'), '#0071e3', '#5e5ce6'),
            'unifi' => array(__('UniFi', 'smbb-wpcodetool'), '#006fff', '#00a6ff'),
            'indigo' => array(__('Indigo', 'smbb-wpcodetool'), '#5847e0', '#a54bf0'),
            'aurore' => array(__('Aurore', 'smbb-wpcodetool'), '#d6246e', '#ff7a45'),
            'menthe' => array(__('Menthe', 'smbb-wpcodetool'), '#00866f', '#1aa3c4'),
            'graphite' => array(__('Graphite', 'smbb-wpcodetool'), '#2c2c2e', '#5a5a60'),
        );

        foreach ($gradients as $key => $theme) {
            $themes[$key] = array(
                'label' => $theme[0],
                'accent' => $theme[1],
                'accent_2' => $theme[2],
                'bg' => self::mix($theme[1], '#f5f5f7', 0.99),
                'fill' => self::mix($theme[1], '#ececf0', 0.985),
                'fill_hover' => self::mix($theme[1], '#e2e2e7', 0.98),
                'line' => self::mix($theme[1], '#e3e3e8', 0.98),
                'text' => '#1d1d1f',
                'muted' => '#6e6e73',
            );
        }

        // Palette « Encre » (allure Porsche) : le noir du Porsche Design System v4, gris « surface / canvas ».
        // Clé 'porsche' gardée : c'est celle déjà enregistrée dans les réglages.
        $themes['porsche'] = array(
            'label' => __('Encre', 'smbb-wpcodetool'),
            'accent' => '#010205',
            'accent_hover' => '#2e2f33',
            'accent_ink' => '#ffffff',
            'accent_soft' => '#e9e9ed',
            'accent_deep' => '#010205',
            'bg' => '#f1f1f4',
            'card' => '#ffffff',
            'fill' => '#ebebef',
            'fill_hover' => '#e1e1e6',
            'text' => '#010205',
            'muted' => '#6b6b70',
            'line' => '#e2e2e6',
            'line_strong' => '#c8c8cd',
            'dark' => array(
                'accent' => '#fbfcff',
                'accent_hover' => '#d9dade',
                'accent_ink' => '#010205',
                'accent_soft' => '#2a2a2e',
                'accent_deep' => '#fbfcff',
                'bg' => '#0b0b0d',
                'card' => '#19191a',
                'fill' => '#242426',
                'fill_hover' => '#2e2e31',
                'text' => '#f6f7fa',
                'muted' => '#909095',
                'line' => '#2c2c2f',
                'line_strong' => '#4a4a4e',
            ),
        );

        $settings = self::settings();

        if ($settings['accent'] !== '') {
            $themes[self::CUSTOM] = array('label' => __('Personnalisé', 'smbb-wpcodetool'), 'accent' => $settings['accent']);
        }

        $themes = apply_filters('smbb_codetool_themes', $themes);
        $out = array();

        foreach ((array) $themes as $key => $theme) {
            $key = sanitize_key((string) $key);
            $accent = is_array($theme) && isset($theme['accent']) ? sanitize_hex_color((string) $theme['accent']) : '';

            if ($key === '' || $key === self::CLASSIC || !$accent) {
                continue;
            }

            $out[$key] = self::complete($theme, $accent, $key);
        }

        return self::$themes = $out;
    }

    /**
     * Thème appliqué à l'utilisateur courant, ou self::CLASSIC.
     */
    public static function current()
    {
        if (self::$current !== null) {
            return self::$current;
        }

        // Style moderne coupé dans CodeTool › Apparence : admin WordPress classique pour tout le monde,
        // même pour qui avait choisi une palette dans son profil ou le widget de préférences.
        if (!self::modern()) {
            return self::$current = self::CLASSIC;
        }

        $themes = self::themes();
        $user = (string) get_user_meta(get_current_user_id(), self::USER_META, true);

        if ($user === self::CLASSIC || isset($themes[$user])) {
            return self::$current = $user;
        }

        return self::$current = self::siteTheme();
    }

    /**
     * Interrupteur « Style moderne » de CodeTool › Apparence. Coupé : rien du style CodeTool,
     * ni widget « Mes préférences d'affichage », ni réglages de style dans le profil.
     */
    public static function modern()
    {
        return !empty(self::settings()['modern']);
    }

    /**
     * Thème choisi pour le site (réglage d'un plugin via filtre), « aubergine » par défaut.
     */
    public static function siteTheme()
    {
        $settings = self::settings();

        if (!$settings['modern']) {
            return self::CLASSIC;
        }

        return self::sitePalette();
    }

    /**
     * Palette choisie pour le site, même quand le style moderne est coupé (pour l'afficher).
     */
    public static function sitePalette()
    {
        $themes = self::themes();
        $saved = self::settings()['theme'];

        if ($saved !== '' && isset($themes[$saved])) {
            return $saved;
        }

        // Pas encore choisi dans CodeTool › Apparence : un plugin peut proposer sa valeur
        // (Tomo reprend son ancien réglage « Couleur d'accent »).
        $site = (string) apply_filters('smbb_codetool_default_theme', 'aubergine');

        if (isset($themes[$site])) {
            return $site;
        }

        return isset($themes['aubergine']) ? 'aubergine' : (string) key($themes);
    }

    /**
     * Réglage du site (CodeTool › Apparence) : thème et couleur personnalisée.
     *
     * @return array{theme:string,accent:string}
     */
    public static function settings()
    {
        $saved = get_option(self::OPTION, array());
        $saved = is_array($saved) ? $saved : array();

        $theme = isset($saved['theme']) ? sanitize_key((string) $saved['theme']) : '';

        // Avant : « classique » était une carte de la palette. C'est maintenant l'interrupteur « modern ».
        if ($theme === self::CLASSIC) {
            $theme = '';

            if (!isset($saved['modern'])) {
                $saved['modern'] = 0;
            }
        }

        $out = array(
            'theme' => $theme,
            'accent' => isset($saved['accent']) ? (string) sanitize_hex_color((string) $saved['accent']) : '',
            // Fond des pages : « tinted » (teinté par le thème) ou « white ».
            'background' => isset($saved['background']) && $saved['background'] === 'white' ? 'white' : 'tinted',
            // Navigation : « top » (barre d'app en haut, menu latéral réduit) ou « side » (menu WordPress).
            'nav' => isset($saved['nav']) && $saved['nav'] === 'side' ? 'side' : 'top',
            // Logo de la page de connexion (pièce jointe), 0 = icône du site ou nom du site.
            'login_logo' => isset($saved['login_logo']) ? absint($saved['login_logo']) : 0,
            // Cadre du logo de connexion : « none », « rounded » (boîte arrondie) ou « circle ».
            'menu_style' => isset($saved['menu_style']) && isset(self::menuStyles()[$saved['menu_style']]) ? $saved['menu_style'] : 'light',
            'login_shape' => isset($saved['login_shape']) && in_array($saved['login_shape'], array('rounded', 'circle'), true) ? $saved['login_shape'] : 'none',
            'density' => isset($saved['density']) && $saved['density'] === 'compact' ? 'compact' : 'comfortable',
            'mode' => isset($saved['mode']) && isset(self::modes()[$saved['mode']]) ? $saved['mode'] : 'light',
            'preset' => isset($saved['preset']) && isset(self::presets()[$saved['preset']]) ? $saved['preset'] : 'galet',
            // Marque blanche : texte du pied de page de l'admin (vide = nom du site).
            'footer_text' => isset($saved['footer_text']) ? sanitize_text_field((string) $saved['footer_text']) : '',
        );

        foreach (self::SWITCHES as $key => $default) {
            $out[$key] = isset($saved[$key]) ? (int) (bool) $saved[$key] : $default;
        }

        return $out;
    }

    /**
     * @param array $extra background, nav, login_logo et les interrupteurs de SWITCHES (les valeurs absentes gardent l'existant)
     */
    public static function saveSettings($theme, $accent, array $extra = array())
    {
        $current = self::settings();

        $value = array(
            'theme' => sanitize_key((string) $theme),
            'accent' => (string) sanitize_hex_color((string) $accent),
            'background' => isset($extra['background']) ? ($extra['background'] === 'white' ? 'white' : 'tinted') : $current['background'],
            'nav' => isset($extra['nav']) ? ($extra['nav'] === 'side' ? 'side' : 'top') : $current['nav'],
            'login_logo' => isset($extra['login_logo']) ? absint($extra['login_logo']) : $current['login_logo'],
            'menu_style' => isset($extra['menu_style']) ? (isset(self::menuStyles()[$extra['menu_style']]) ? $extra['menu_style'] : 'light') : $current['menu_style'],
            'login_shape' => isset($extra['login_shape']) ? (in_array($extra['login_shape'], array('rounded', 'circle'), true) ? $extra['login_shape'] : 'none') : $current['login_shape'],
            'density' => isset($extra['density']) ? ($extra['density'] === 'compact' ? 'compact' : 'comfortable') : $current['density'],
            'mode' => isset($extra['mode']) ? (isset(self::modes()[$extra['mode']]) ? $extra['mode'] : 'light') : $current['mode'],
            'preset' => isset($extra['preset']) ? (isset(self::presets()[$extra['preset']]) ? $extra['preset'] : 'galet') : $current['preset'],
            'footer_text' => isset($extra['footer_text']) ? sanitize_text_field((string) $extra['footer_text']) : $current['footer_text'],
        );

        foreach (self::SWITCHES as $key => $default) {
            $value[$key] = isset($extra[$key]) ? (int) (bool) $extra[$key] : $current[$key];
        }

        update_option(self::OPTION, $value, true);

        self::$themes = null;
        self::$current = null;
    }

    /**
     * Accent du thème du site (pages publiques, apparence classique), ou null.
     */
    public static function siteAccent()
    {
        $themes = self::themes();
        $site = self::sitePalette();

        return isset($themes[$site]) ? $themes[$site]['accent'] : null;
    }

    /**
     * Barre d'app en haut (navigation modernisée) ?
     */
    public static function topNav()
    {
        return self::active() && self::settings()['nav'] === 'top';
    }

    public static function navIcons()
    {
        return self::settings()['icons'] === 1;
    }

    public static function active()
    {
        return self::current() !== self::CLASSIC && self::current() !== '';
    }

    /**
     * Couleur d'accent du thème courant (null en apparence classique).
     */
    public static function accent()
    {
        $theme = self::theme();

        return $theme ? $theme['accent'] : null;
    }

    /**
     * @return array|null
     */
    public static function theme()
    {
        $themes = self::themes();
        $key = self::current();

        return isset($themes[$key]) ? $themes[$key] : null;
    }

    /**
     * Classes à ajouter au <body>.
     */
    public static function bodyClass($classes)
    {
        if (!self::active()) {
            return $classes;
        }

        return trim($classes . ' smbb-skin smbb-theme-' . sanitize_html_class(self::current()));
    }

    /**
     * Variables CSS du thème courant, posées sur body.smbb-skin.
     */
    public static function css()
    {
        $theme = self::theme();

        if (!$theme) {
            return '';
        }

        $map = array(
            'accent' => 'accent',
            'accent-hover' => 'accent_hover',
            'accent-ink' => 'accent_ink',
            'accent-soft' => 'accent_soft',
            'accent-deep' => 'accent_deep',
            'bg' => 'bg',
            'card' => 'card',
            'fill' => 'fill',
            'fill-hover' => 'fill_hover',
            'text' => 'text',
            'muted' => 'muted',
            'line' => 'line',
            'line-strong' => 'line_strong',
        );
        $vars = '';

        // Fond blanc : la page et les gris ne sont plus teintés par l'accent.
        if (self::settings()['background'] === 'white') {
            $theme = array_merge($theme, array(
                'bg' => '#ffffff',
                'fill' => '#f1f1f3',
                'fill_hover' => '#e7e7eb',
                'line' => '#e3e3e8',
                'line_strong' => '#cfcfd6',
            ));
        }

        foreach ($map as $var => $key) {
            $vars .= '--smbb-t-' . $var . ':' . $theme[$key] . ';';
        }

        $light = $theme;
        list($r, $g, $b) = self::rgb($theme['accent']);
        $vars .= '--smbb-t-accent-rgb:' . $r . ',' . $g . ',' . $b . ';';

        // Dégradé : seulement pour les palettes qui ont une 2e couleur ; sinon les CSS restent en aplat.
        if (!empty($theme['accent_2'])) {
            $vars .= '--smbb-t-accent-2:' . $theme['accent_2'] . ';'
                . '--smbb-t-grad:linear-gradient(135deg,' . $theme['accent'] . ',' . $theme['accent_2'] . ');'
                . '--smbb-t-grad-hover:linear-gradient(135deg,' . $theme['accent_hover'] . ',' . self::mix($theme['accent_2'], '#000000', 0.16) . ');'
                . '--smbb-t-grad-ink:' . $theme['accent_ink'] . ';';
        }

        $css = 'body.smbb-skin,body.smbb-login{' . $vars . '}';

        // Mode sombre : mêmes variables, valeurs sombres, sous html.smbb-dark (posée par modeScript()).
        // Toujours posé : la bascule de la barre du haut passe en sombre sans recharger la page.
        $dark = self::darkPalette($light);
        $vars = '';

        foreach ($map as $var => $key) {
            $vars .= '--smbb-t-' . $var . ':' . $dark[$key] . ';';
        }

        list($r, $g, $b) = self::rgb($dark['accent']);
        $vars .= '--smbb-t-accent-rgb:' . $r . ',' . $g . ',' . $b . ';';

        if (!empty($light['accent_2'])) {
            $second = self::mix($light['accent_2'], '#ffffff', 0.18);
            $vars .= '--smbb-t-grad:linear-gradient(135deg,' . $dark['accent'] . ',' . $second . ');'
                . '--smbb-t-grad-hover:linear-gradient(135deg,' . $dark['accent_hover'] . ',' . self::mix($second, '#000000', 0.12) . ');'
                . '--smbb-t-grad-ink:' . $dark['accent_ink'] . ';';
        }

        $css .= 'html.smbb-dark body.smbb-skin,html.smbb-dark body.smbb-login{' . $vars . '}';

        return $css;
    }

    /**
     * Teintes sombres d'un thème : celles écrites dans sa définition (« dark »), sinon calculées
     * depuis l'accent (gris neutres façon Apple, à peine teintés ; accent éclairci pour rester lisible).
     */
    public static function darkPalette(array $theme)
    {
        $accent = $theme['accent'];
        $lum = self::luminance($accent);
        // Un accent très sombre (Graphite, Marine…) devient clair, sinon il disparaît sur le fond.
        $bright = $lum < 0.08 ? self::mix($accent, '#ffffff', 0.62) : self::mix($accent, '#ffffff', $lum < 0.2 ? 0.3 : 0.16);
        $given = isset($theme['dark']) && is_array($theme['dark']) ? $theme['dark'] : array();
        $get = function ($name, $fallback) use ($given) {
            $color = isset($given[$name]) ? sanitize_hex_color((string) $given[$name]) : '';

            return $color ?: $fallback;
        };
        $a = $get('accent', $bright);

        return array(
            'accent' => $a,
            'accent_hover' => $get('accent_hover', self::mix($a, '#ffffff', 0.14)),
            'accent_ink' => $get('accent_ink', self::luminance($a) > 0.4 ? '#141416' : '#ffffff'),
            'accent_soft' => $get('accent_soft', self::mix($a, '#1c1c1f', 0.78)),
            'accent_deep' => $get('accent_deep', self::mix($a, '#ffffff', 0.45)),
            'bg' => $get('bg', self::mix($accent, '#121214', 0.96)),
            'card' => $get('card', self::mix($accent, '#1e1e21', 0.96)),
            'fill' => $get('fill', self::mix($accent, '#2a2a2e', 0.95)),
            'fill_hover' => $get('fill_hover', self::mix($accent, '#343439', 0.94)),
            'text' => $get('text', '#f2f2f5'),
            'muted' => $get('muted', '#a1a1a8'),
            'line' => $get('line', self::mix($accent, '#323236', 0.95)),
            'line_strong' => $get('line_strong', self::mix($accent, '#48484e', 0.94)),
        );
    }

    /**
     * Pose html.smbb-dark avant l'affichage (pas de flash blanc). En « auto », suit l'appareil en direct.
     */
    public static function modeScript($force = false)
    {
        // Connexion : WpSkin::loginHead() passe $force, la page suit alors le mode du site.
        if ($force !== true && !self::active()) {
            return;
        }

        $mode = self::mode();

        if ($mode === 'light') {
            return;
        }

        echo '<script>(function(d){var m=' . wp_json_encode($mode) . ',q=window.matchMedia&&matchMedia("(prefers-color-scheme: dark)"),'
            . 'f=function(){d.classList.toggle("smbb-dark",m==="dark"||(m==="auto"&&!!q&&q.matches));};f();'
            . 'if(m==="auto"&&q){q.addEventListener?q.addEventListener("change",f):q.addListener(f);}})(document.documentElement);</script>' . "\n";
    }

    /**
     * Profil : « Personnaliser mon style », puis les mêmes cartes que CodeTool › Apparence
     * (palette, style du menu, densité). Coupé = l'utilisateur suit les réglages du site.
     */
    public static function profileField($user)
    {
        // Style moderne coupé : pas de réglages de style dans le profil (ils n'auraient aucun effet).
        if (!self::modern()) {
            return;
        }

        $themes = self::themes();
        $settings = self::settings();
        $theme = (string) get_user_meta($user->ID, self::USER_META, true);
        $menu = (string) get_user_meta($user->ID, self::USER_MENU, true);
        $density = (string) get_user_meta($user->ID, self::USER_DENSITY, true);
        $custom = $theme !== '' || $menu !== '' || $density !== '';

        // Cartes préremplies avec les choix du site, pour partir de ce que l'on voit déjà.
        $theme = $theme !== '' ? $theme : self::siteTheme();
        $menu = isset(self::menuStyles()[$menu]) ? $menu : $settings['menu_style'];
        $density = isset(self::densities()[$density]) ? $density : $settings['density'];
        unset($themes[self::CUSTOM]);
        ?>
        <h2><?php esc_html_e('Apparence des écrans CodeTool', 'smbb-wpcodetool'); ?></h2>
        <div class="smbb-codetool smbb-profile-style">
            <?php wp_nonce_field('smbb_codetool_theme', 'smbb_codetool_theme_nonce'); ?>
            <p class="smbb-codetool-field-label"><span class="dashicons dashicons-visibility" aria-hidden="true"></span> <?php esc_html_e('Mode d\'affichage', 'smbb-wpcodetool'); ?></p>
            <div class="smbb-codetool-segmented smbb-mode-choice" role="radiogroup" aria-label="<?php esc_attr_e('Mode d\'affichage', 'smbb-wpcodetool'); ?>">
                <?php
                $mode = (string) get_user_meta($user->ID, self::USER_MODE, true);
                $mode = isset(self::modes()[$mode]) ? $mode : $settings['mode'];
                $mode_icons = array('light' => 'lightbulb', 'dark' => 'admin-site-alt3', 'auto' => 'image-flip-horizontal');

                foreach (self::modes() as $key => $label) :
                    ?>
                    <label class="smbb-mode-option">
                        <input type="radio" name="smbb_codetool_mode" value="<?php echo esc_attr($key); ?>" <?php checked($mode, $key); ?>>
                        <span><span class="dashicons dashicons-<?php echo esc_attr($mode_icons[$key]); ?>" aria-hidden="true"></span><?php echo esc_html($label); ?></span>
                    </label>
                <?php endforeach; ?>
            </div>
            <p class="description smbb-mode-note"><?php esc_html_e('Auto suit le réglage clair ou sombre de votre ordinateur ou téléphone.', 'smbb-wpcodetool'); ?></p>

            <?php Pages\AppearancePage::toggle('smbb_codetool_custom_style', $custom, __('Personnaliser mon style', 'smbb-wpcodetool'), __('Coupé, vous suivez l\'apparence choisie pour tout le site.', 'smbb-wpcodetool')); ?>

            <div class="smbb-profile-style-details">
                <p class="smbb-codetool-field-label"><span class="dashicons dashicons-art" aria-hidden="true"></span> <?php esc_html_e('Palette de couleurs', 'smbb-wpcodetool'); ?></p>
                <div class="smbb-theme-grid" role="radiogroup">
                    <?php
                    foreach ($themes as $key => $def) {
                        Pages\AppearancePage::card($key, $def['label'], array('accent' => $def['accent'], 'accent2' => $def['accent_2'], 'soft' => $def['accent_soft'], 'bg' => $def['bg']), $theme === $key, '', '', 'smbb_codetool_theme');
                    }

                    Pages\AppearancePage::card(self::CLASSIC, __('Apparence classique', 'smbb-wpcodetool'), array('accent' => '#2271b1', 'soft' => '#f0f6fc', 'bg' => '#f0f0f1'), $theme === self::CLASSIC, '', __('Le style WordPress d\'avant.', 'smbb-wpcodetool'), 'smbb_codetool_theme');
                    ?>
                </div>

                <?php if (!empty($settings['wp_chrome'])) : ?>
                    <p class="smbb-codetool-field-label"><span class="dashicons dashicons-menu-alt3" aria-hidden="true"></span> <?php esc_html_e('Style du menu de gauche', 'smbb-wpcodetool'); ?></p>
                    <div class="smbb-choice-grid smbb-menu-grid" role="radiogroup">
                        <?php
                        $notes = Pages\AppearancePage::menuNotes();

                        foreach (self::menuStyles() as $key => $label) {
                            Pages\AppearancePage::menuChoice($key, $menu, $label, isset($notes[$key]) ? $notes[$key] : '', 'smbb_codetool_menu_style');
                        }
                        ?>
                    </div>
                <?php endif; ?>

                <?php Pages\AppearancePage::toggle('smbb_codetool_density_compact', $density === 'compact', __('Densité compacte', 'smbb-wpcodetool'), __('Tableaux, formulaires et menu plus serrés, pour voir plus de lignes à l\'écran.', 'smbb-wpcodetool')); ?>
            </div>
        </div>
        <?php
    }

    public static function saveProfileField($user_id)
    {
        if (!self::modern()) {
            return; // champs non affichés : on garde les choix de chacun pour le jour où le style revient
        }

        if (!current_user_can('edit_user', $user_id) || !isset($_POST['smbb_codetool_theme_nonce'])
            || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['smbb_codetool_theme_nonce'])), 'smbb_codetool_theme')) {
            return;
        }

        // Mode d'affichage : choix personnel, indépendant de « Personnaliser mon style ».
        $mode = isset($_POST['smbb_codetool_mode']) ? sanitize_key(wp_unslash($_POST['smbb_codetool_mode'])) : '';

        if (isset(self::modes()[$mode])) {
            update_user_meta($user_id, self::USER_MODE, $mode);
        }

        // Interrupteur coupé : on suit le site, les choix personnels sont effacés.
        if (empty($_POST['smbb_codetool_custom_style'])) {
            delete_user_meta($user_id, self::USER_META);
            delete_user_meta($user_id, self::USER_MENU);
            delete_user_meta($user_id, self::USER_DENSITY);

            return;
        }

        $theme = isset($_POST['smbb_codetool_theme']) ? sanitize_key(wp_unslash($_POST['smbb_codetool_theme'])) : '';

        if ($theme === self::CLASSIC || ($theme !== self::CUSTOM && isset(self::themes()[$theme]))) {
            update_user_meta($user_id, self::USER_META, $theme);
        } else {
            delete_user_meta($user_id, self::USER_META);
        }

        $menu = isset($_POST['smbb_codetool_menu_style']) ? sanitize_key(wp_unslash($_POST['smbb_codetool_menu_style'])) : '';

        if (isset(self::menuStyles()[$menu])) {
            update_user_meta($user_id, self::USER_MENU, $menu);
        }

        update_user_meta($user_id, self::USER_DENSITY, !empty($_POST['smbb_codetool_density_compact']) ? 'compact' : 'comfortable');
    }

    /**
     * Complète une définition : les teintes absentes sont calculées depuis l'accent.
     */
    private static function complete($theme, $accent, $key)
    {
        $theme = (array) $theme;
        $get = function ($name, $fallback) use ($theme) {
            $color = isset($theme[$name]) ? sanitize_hex_color((string) $theme[$name]) : '';

            return $color ?: $fallback;
        };

        return array(
            'label' => isset($theme['label']) && $theme['label'] !== '' ? (string) $theme['label'] : ucfirst($key),
            'accent' => $accent,
            'accent_2' => $get('accent_2', ''),
            'accent_hover' => $get('accent_hover', self::mix($accent, '#000000', 0.16)),
            'accent_ink' => $get('accent_ink', self::luminance($accent) > 0.55 ? '#1c1a1f' : '#ffffff'),
            'accent_soft' => $get('accent_soft', self::mix($accent, '#ffffff', 0.86)),
            'accent_deep' => $get('accent_deep', self::mix($accent, '#000000', 0.38)),
            'bg' => $get('bg', self::mix($accent, '#f6f6f8', 0.96)),
            'card' => $get('card', '#ffffff'),
            'fill' => $get('fill', self::mix($accent, '#eeeef1', 0.94)),
            'fill_hover' => $get('fill_hover', self::mix($accent, '#e4e4e8', 0.92)),
            'text' => $get('text', '#1c1b1f'),
            'muted' => $get('muted', '#66646b'),
            'line' => $get('line', self::mix($accent, '#e2e2e6', 0.93)),
            'line_strong' => $get('line_strong', self::mix($accent, '#cdcdd3', 0.9)),
            'dark' => isset($theme['dark']) && is_array($theme['dark']) ? $theme['dark'] : array(),
        );
    }

    /**
     * Mélange deux couleurs : $weight = part de $b (0 = $a pur, 1 = $b pur).
     */
    public static function mix($a, $b, $weight)
    {
        $ca = self::rgb($a);
        $cb = self::rgb($b);
        $out = '#';

        for ($i = 0; $i < 3; $i++) {
            $out .= str_pad(dechex((int) round($ca[$i] * (1 - $weight) + $cb[$i] * $weight)), 2, '0', STR_PAD_LEFT);
        }

        return $out;
    }

    /**
     * @return array{0:int,1:int,2:int}
     */
    public static function rgb($hex)
    {
        $hex = ltrim((string) $hex, '#');

        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }

        $hex = substr($hex . '000000', 0, 6);

        return array((int) hexdec(substr($hex, 0, 2)), (int) hexdec(substr($hex, 2, 2)), (int) hexdec(substr($hex, 4, 2)));
    }

    private static function luminance($hex)
    {
        list($r, $g, $b) = self::rgb($hex);

        return (0.299 * $r + 0.587 * $g + 0.114 * $b) / 255;
    }
}
