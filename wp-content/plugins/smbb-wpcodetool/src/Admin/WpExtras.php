<?php

namespace Smbb\WpCodeTool\Admin;

defined('ABSPATH') || exit;

/**
 * « Marque blanche et ménage » (CodeTool › Apparence) : ce que les agences retouchent d'habitude.
 * Marque blanche et ménage (favicon, marque, barre allégée, accueil, commentaires) restent
 * indépendants du style moderne ; colonnes, listes et Ctrl K s'éteignent avec lui.
 *
 * - favicon        : le logo de la page de connexion (ou le sigle) comme icône d'onglet, partout.
 * - wl_brand       : logo du site à la place du logo WordPress en haut, pied de page à soi, sans version.
 * - adminbar_clean : barre du haut sans « + Nouveau », commentaires ni menu WordPress.
 * - dashboard_clean: accueil de l'admin sans les widgets de WordPress (ceux des extensions restent).
 * - list_columns   : vignette et ID dans les listes de contenus.
 * - list_duplicate : « Dupliquer » dans les listes de contenus (PostTools), même sans style moderne.
 * - list_children  : filtre « Enfants de… » des listes hiérarchiques (PostTools).
 * - palette        : recherche Ctrl K dans toute l'admin (assets/wp-palette.js).
 */
final class WpExtras
{
    /** Widgets de WordPress retirés de l'accueil (contexte => identifiants). */
    const CORE_WIDGETS = array(
        'normal' => array('dashboard_right_now', 'dashboard_activity', 'dashboard_site_health', 'dashboard_recent_comments', 'dashboard_incoming_links', 'dashboard_plugins'),
        'side' => array('dashboard_quick_press', 'dashboard_primary', 'dashboard_secondary', 'dashboard_recent_drafts'),
    );

    public static function hooks()
    {
        $s = Skin::settings();

        if (!empty($s['favicon'])) {
            add_filter('get_site_icon_url', array(__CLASS__, 'siteIcon'), 20, 2);
            add_action('wp_head', array(__CLASS__, 'faviconTags'), 99);
            add_action('admin_head', array(__CLASS__, 'faviconTags'), 99);
            add_action('login_head', array(__CLASS__, 'faviconTags'), 99);
        }

        if (!empty($s['wl_brand'])) {
            add_action('admin_bar_menu', array(__CLASS__, 'brandNode'), 5);
            add_filter('admin_footer_text', array(__CLASS__, 'footerText'), 20);
            add_filter('update_footer', '__return_empty_string', 20);
        }

        if (!empty($s['wl_brand']) || !empty($s['adminbar_clean'])) {
            add_action('admin_bar_menu', array(__CLASS__, 'cleanBar'), 999);
        }

        if (!empty($s['wl_brand']) || (!empty($s['modern']) && !empty($s['palette']))) {
            add_action('admin_enqueue_scripts', array(__CLASS__, 'barCss'));
            add_action('wp_enqueue_scripts', array(__CLASS__, 'barCss'));
        }

        if (!empty($s['dashboard_clean'])) {
            add_action('wp_dashboard_setup', array(__CLASS__, 'cleanDashboard'), 999);
        }

        // Les améliorations d'affichage suivent l'interrupteur maître « Style moderne ».
        $modern = !empty($s['modern']);

        if ($modern && !empty($s['list_columns'])) {
            add_action('load-edit.php', array(__CLASS__, 'listColumns'));
        }

        if (!empty($s['mode_toggle'])) {
            add_action('admin_bar_menu', array(__CLASS__, 'modeNode'), 8); // juste après le compte : il s'affiche à sa gauche
            add_action('admin_enqueue_scripts', array(__CLASS__, 'modeAssets'));
        }

        if (!empty($s['wp_media'])) {
            add_action('admin_enqueue_scripts', array(__CLASS__, 'mediaAssets'), 40);
        }

        if ($modern && !empty($s['list_ux'])) {
            ListUx::hooks();
        }

        // « Dupliquer » est une fonction (remplace l'extension qu'on installait pour ça) : indépendant
        // du style moderne. « Enfants de… » est une amélioration des listes : il le suit.
        if (!empty($s['list_duplicate']) || ($modern && !empty($s['list_children']))) {
            PostTools::hooks(!empty($s['list_duplicate']), $modern && !empty($s['list_children']));
        }

        if ($s['preset'] !== 'galet') {
            add_filter('admin_body_class', array(__CLASS__, 'presetClass'), 99);
            add_filter('login_body_class', array(__CLASS__, 'presetLoginClass'), 99);
            add_action('admin_enqueue_scripts', array(__CLASS__, 'presetAssets'), 100);
            add_action('login_enqueue_scripts', array(__CLASS__, 'presetAssets'), 100);
        }

        add_action('wp_ajax_smbb_codetool_mode', array(__CLASS__, 'saveMode'));
        add_action('admin_post_smbb_codetool_mode', array(__CLASS__, 'saveMode'));

        // Résultats « métier » des extensions (participants, commandes…) : voir PaletteSearch.
        PaletteSearch::hooks();

        if ($modern && !empty($s['palette'])) {
            add_action('admin_enqueue_scripts', array(__CLASS__, 'palette'), 30);
            add_action('admin_bar_menu', array(__CLASS__, 'searchNode'), 999);
        } elseif ($modern) {
            // Sans « Ctrl K partout » : la palette se charge quand même sur les écrans de l'app
            // dès qu'une extension fournit des résultats (sinon la palette WordPress suffit).
            add_action('admin_enqueue_scripts', array(__CLASS__, 'appPalette'), 30);
        }
    }

    /* ---------------------------------------------------------------- Favicon */

    /**
     * Logo choisi (médiathèque) à la bonne taille. Sans logo choisi, pas d'icône WordPress :
     * faviconTags() met le sigle (data URI, que esc_url() refuserait dans wp_site_icon()).
     */
    public static function siteIcon($url, $size = 512)
    {
        $image = self::logoImage((int) $size <= 150 ? 'thumbnail' : 'medium');

        return $image ? $image : '';
    }

    public static function faviconTags()
    {
        if (self::logoImage('thumbnail')) {
            return; // wp_site_icon() s'en charge avec l'image choisie.
        }

        echo '<link rel="icon" type="image/svg+xml" href="' . esc_attr(self::markDataUri()) . '">' . "\n";
    }

    private static function logoImage($size)
    {
        $id = (int) Skin::settings()['login_logo'];

        return $id ? (string) wp_get_attachment_image_url($id, $size) : '';
    }

    /** Le sigle SMBB en data URI, couleur de l'accent du site. */
    public static function markDataUri()
    {
        $accent = Skin::siteAccent();
        $color = $accent ? $accent : '#db1f26';
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1000 1000"><path fill="' . $color . '" d="' . WpSkin::MARK_PATH . '"/></svg>';

        return 'data:image/svg+xml,' . rawurlencode($svg);
    }

    /** Petit logo pour la barre du haut : image choisie, sinon le sigle. */
    private static function logoTag()
    {
        $url = self::logoImage('thumbnail');

        return '<img class="smbb-ab-logo" src="' . ($url ? esc_url($url) : esc_attr(self::markDataUri())) . '" alt="" width="20" height="20">';
    }

    /* ---------------------------------------------------------------- Marque blanche */

    public static function brandNode($bar)
    {
        $bar->add_node(array(
            'id' => 'smbb-brand',
            'title' => self::logoTag() . '<span class="screen-reader-text">' . esc_html(get_bloginfo('name', 'display')) . '</span>',
            'href' => is_admin() ? home_url('/') : admin_url(),
            'meta' => array('title' => is_admin() ? __('Voir le site', 'smbb-wpcodetool') : __('Administration', 'smbb-wpcodetool')),
        ));
    }

    public static function footerText()
    {
        $text = Skin::settings()['footer_text'];

        return '<span id="footer-thankyou">' . esc_html($text !== '' ? $text : get_bloginfo('name', 'display')) . '</span>';
    }

    public static function cleanBar($bar)
    {
        $s = Skin::settings();

        if (!empty($s['wl_brand']) || !empty($s['adminbar_clean'])) {
            $bar->remove_node('wp-logo');
        }

        if (!empty($s['adminbar_clean'])) {
            foreach (array('new-content', 'comments', 'customize', 'search') as $id) {
                $bar->remove_node($id);
            }
        }
    }

    public static function barCss()
    {
        if (!is_admin_bar_showing()) {
            return;
        }

        $css = '';

        if (!empty(Skin::settings()['wl_brand'])) {
            // Notre logo de marque suffit : WordPress remet l'icône du site (ou la maison) devant
            // le nom du site, ce qui donnait deux fois le même logo côte à côte.
            $css .= '#wpadminbar #wp-admin-bar-site-name>.ab-item:before{display:none!important;content:none!important}'
                . '#wpadminbar #wp-admin-bar-site-name>.ab-item img,#wpadminbar #wp-admin-bar-site-name>.ab-item .site-icon{display:none!important}';
        }

        wp_add_inline_style('admin-bar', $css . '#wpadminbar #wp-admin-bar-smbb-brand>.ab-item{display:flex;align-items:center;padding:0 10px}'
            . '#wpadminbar .smbb-ab-logo{display:block;width:20px;height:20px;border-radius:5px;object-fit:contain}'
            . '#wpadminbar #wp-admin-bar-smbb-search>.ab-item{display:flex;align-items:center;gap:6px}'
            . '#wpadminbar #wp-admin-bar-smbb-search .ab-icon{margin:0;padding:0}'
            . '#wpadminbar #wp-admin-bar-smbb-search .ab-icon::before{content:"\\f179";top:2px}'
            . '#wpadminbar #wp-admin-bar-smbb-search kbd{padding:0 5px;border:1px solid;border-color:color-mix(in srgb,currentColor 35%,transparent);border-radius:5px;background:none;font-family:inherit;font-size:11px;line-height:1.6;color:inherit;opacity:.8}');
    }

    /* ---------------------------------------------------------------- Accueil */

    public static function cleanDashboard()
    {
        global $wp_meta_boxes;

        // Ajouté par admin-filters.php, chargé après init : on le retire ici.
        remove_action('welcome_panel', 'wp_welcome_panel');

        foreach (self::CORE_WIDGETS as $context => $ids) {
            foreach ($ids as $id) {
                remove_meta_box($id, 'dashboard', $context);
            }
        }

        // Plus rien du tout : un mot d'accueil plutôt qu'un écran vide.
        $left = 0;

        foreach ((array) ($wp_meta_boxes['dashboard'] ?? array()) as $priorities) {
            foreach ((array) $priorities as $boxes) {
                $left += count(array_filter((array) $boxes));
            }
        }

        if (!$left) {
            wp_add_dashboard_widget('smbb_hello', get_bloginfo('name', 'display'), array(__CLASS__, 'helloWidget'));
        }
    }

    public static function helloWidget()
    {
        $user = wp_get_current_user();
        $name = $user->first_name !== '' ? $user->first_name : $user->display_name;
        echo '<p>' . esc_html(sprintf(__('Bonjour %s.', 'smbb-wpcodetool'), $name)) . '</p>';
        echo '<p><a class="button" href="' . esc_url(home_url('/')) . '">' . esc_html__('Voir le site', 'smbb-wpcodetool') . '</a> '
            . '<a class="button" href="' . esc_url(get_edit_profile_url()) . '">' . esc_html__('Mon profil', 'smbb-wpcodetool') . '</a></p>';
    }

    /* ---------------------------------------------------------------- Colonnes */

    public static function listColumns()
    {
        $type = isset($_GET['post_type']) ? sanitize_key(wp_unslash($_GET['post_type'])) : 'post'; // phpcs:ignore WordPress.Security.NonceVerification

        if (!post_type_exists($type)) {
            return;
        }

        add_filter('manage_' . $type . '_posts_columns', array(__CLASS__, 'columns'), 20);
        add_action('manage_' . $type . '_posts_custom_column', array(__CLASS__, 'column'), 10, 2);
        add_action('admin_head', array(__CLASS__, 'columnsCss'));
    }

    public static function columns($columns)
    {
        $type = get_current_screen() ? get_current_screen()->post_type : 'post';
        $out = array();

        foreach ($columns as $key => $label) {
            $out[$key] = $label;

            // WooCommerce a déjà sa vignette (« thumb ») : pas de doublon.
            if ($key === 'cb' && post_type_supports($type, 'thumbnail') && !isset($columns['thumb'])) {
                $out['smbb_thumb'] = '<span class="dashicons dashicons-format-image" title="' . esc_attr__('Image', 'smbb-wpcodetool') . '"></span><span class="screen-reader-text">' . esc_html__('Image', 'smbb-wpcodetool') . '</span>';
            }
        }

        $out['smbb_id'] = __('ID', 'smbb-wpcodetool');

        return $out;
    }

    public static function column($column, $post_id)
    {
        if ($column === 'smbb_thumb') {
            $thumb = get_the_post_thumbnail($post_id, array(80, 80), array('class' => 'smbb-list-thumb', 'alt' => ''));
            echo $thumb ? $thumb : '<span class="smbb-list-thumb is-empty" aria-hidden="true"></span>'; // phpcs:ignore -- HTML de WordPress.
        } elseif ($column === 'smbb_id') {
            echo (int) $post_id;
        }
    }

    public static function columnsCss()
    {
        echo '<style>.wp-list-table .column-smbb_thumb{width:52px}.wp-list-table .column-smbb_id{width:64px;color:var(--smbb-t-muted,#646970);font-variant-numeric:tabular-nums}'
            . '.smbb-list-thumb{display:block;width:40px;height:40px;border-radius:8px;object-fit:cover;background:var(--smbb-t-fill,#f0f0f1)}'
            . '@media screen and (max-width:782px){.wp-list-table .column-smbb_thumb,.wp-list-table .column-smbb_id{display:none}}</style>';
    }

    /* ---------------------------------------------------------------- Recherche Ctrl K */

    /**
     * Palette limitée aux écrans CodeTool (admin.php?page=smbb-codetool-… / smbb-wpcodetool…).
     */
    public static function appPalette()
    {
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : ''; // phpcs:ignore -- lecture seule.

        if (strpos($page, 'smbb-codetool') !== 0 && strpos($page, 'smbb-wpcodetool') !== 0) {
            return;
        }

        if (PaletteSearch::hasProviders()) {
            self::palette();
        }
    }

    public static function palette()
    {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;

        // L'éditeur de blocs a déjà la palette de WordPress.
        if ($screen && method_exists($screen, 'is_block_editor') && $screen->is_block_editor()) {
            return;
        }

        $create = array();

        foreach (get_post_types(array('show_ui' => true), 'objects') as $type) {
            if (!$type->show_in_menu || !current_user_can($type->cap->create_posts)) {
                continue;
            }

            $create[] = array(
                'label' => sprintf(__('Créer : %s', 'smbb-wpcodetool'), $type->labels->singular_name),
                'url' => admin_url($type->name === 'post' ? 'post-new.php' : 'post-new.php?post_type=' . $type->name),
            );
        }

        if (current_user_can('create_users')) {
            $create[] = array('label' => __('Créer : Utilisateur', 'smbb-wpcodetool'), 'url' => admin_url('user-new.php'));
        }

        if (current_user_can('upload_files')) {
            $create[] = array('label' => __('Ajouter : Média', 'smbb-wpcodetool'), 'url' => admin_url('media-new.php'));
        }

        $types = array();

        foreach (get_post_types(array('show_in_rest' => true, 'show_ui' => true), 'objects') as $type) {
            if (current_user_can($type->cap->edit_posts)) {
                $types[$type->name] = $type->labels->singular_name;
            }
        }

        wp_enqueue_style('smbb-wpcodetool-wp-palette', SMBB_WPCODETOOL_URL . 'assets/wp-palette.css', array(), self::version('wp-palette.css'));
        wp_enqueue_script('smbb-wpcodetool-wp-palette', SMBB_WPCODETOOL_URL . 'assets/wp-palette.js', array(), self::version('wp-palette.js'), true);
        wp_localize_script('smbb-wpcodetool-wp-palette', 'smbbPaletteData', array(
            'create' => $create,
            'types' => $types,
            'rest' => esc_url_raw(rest_url('wp/v2/search')),
            'nonce' => wp_create_nonce('wp_rest'),
            'edit' => admin_url('post.php?action=edit&post='),
            'search' => PaletteSearch::clientData(),
            'i18n' => array(
                // Une extension qui fournit des résultats peut annoncer ce qu'on trouve (« un participant, une commande… »).
                'placeholder' => apply_filters('smbb_wpcodetool_palette_placeholder', __('Chercher une page, un contenu, une action…', 'smbb-wpcodetool')),
                'pages' => __('Pages de l\'admin', 'smbb-wpcodetool'),
                'actions' => __('Actions', 'smbb-wpcodetool'),
                'content' => __('Contenus', 'smbb-wpcodetool'),
                'empty' => __('Rien trouvé.', 'smbb-wpcodetool'),
                'hint' => __('↑ ↓ pour choisir, Entrée pour ouvrir, Échap pour fermer', 'smbb-wpcodetool'),
                'hintActions' => __('↑ ↓ pour choisir, Tab ou → pour les actions rapides, Entrée pour ouvrir', 'smbb-wpcodetool'),
                'searching' => __('Recherche…', 'smbb-wpcodetool'),
                'recent' => __('Récemment ouverts', 'smbb-wpcodetool'),
            ),
        ));
    }

    public static function searchNode($bar)
    {
        if (!is_admin()) {
            return;
        }

        $bar->add_node(array(
            'id' => 'smbb-search',
            'parent' => 'top-secondary',
            'title' => '<span class="ab-icon" aria-hidden="true"></span><span class="ab-label">' . esc_html__('Rechercher', 'smbb-wpcodetool') . '</span> <kbd>Ctrl K</kbd>',
            'href' => '#',
            'meta' => array('class' => 'smbb-palette-open'),
        ));
    }

    /* ---------------------------------------------------------------- Médiathèque et éditeur */

    /**
     * Couleur du thème pour les composants de WordPress (éditeur de blocs, médias…), qui lisent
     * --wp-admin-theme-color, et feuille assets/wp-media.css pour la médiathèque.
     */
    public static function mediaAssets()
    {
        $theme = Skin::active() ? Skin::theme() : null;

        if (!$theme) {
            return;
        }

        list($r, $g, $b) = Skin::rgb($theme['accent']);
        list($r2, $g2, $b2) = Skin::rgb($theme['accent_hover']);
        list($r3, $g3, $b3) = Skin::rgb($theme['accent_deep']);
        $vars = 'html body.wp-admin{'
            . '--wp-admin-theme-color:' . $theme['accent'] . ';'
            . '--wp-admin-theme-color--rgb:' . $r . ',' . $g . ',' . $b . ';'
            . '--wp-admin-theme-color-darker-10:' . $theme['accent_hover'] . ';'
            . '--wp-admin-theme-color-darker-10--rgb:' . $r2 . ',' . $g2 . ',' . $b2 . ';'
            . '--wp-admin-theme-color-darker-20:' . $theme['accent_deep'] . ';'
            . '--wp-admin-theme-color-darker-20--rgb:' . $r3 . ',' . $g3 . ',' . $b3 . ';'
            . '--smbb-m-accent:' . $theme['accent'] . ';'
            . '--smbb-m-accent-rgb:' . $r . ',' . $g . ',' . $b . ';'
            . '}';

        // Mode sombre (médiathèque) : l'accent éclairci de la palette sombre.
        $dark = Skin::darkPalette($theme);
        list($r4, $g4, $b4) = Skin::rgb($dark['accent']);
        $vars .= 'html.smbb-dark body.wp-admin:not(.block-editor-page){--smbb-m-accent:' . $dark['accent'] . ';--smbb-m-accent-rgb:' . $r4 . ',' . $g4 . ',' . $b4 . ';}';

        wp_enqueue_style('smbb-wpcodetool-wp-media', SMBB_WPCODETOOL_URL . 'assets/wp-media.css', array(), self::version('wp-media.css'));
        wp_add_inline_style('smbb-wpcodetool-wp-media', $vars);

        wp_enqueue_script('smbb-wpcodetool-wp-media', SMBB_WPCODETOOL_URL . 'assets/wp-media.js', array(), self::version('wp-media.js'), true);
        wp_localize_script('smbb-wpcodetool-wp-media', 'smbbWpMedia', array(
            'i18n' => array(
                'view' => __('Voir', 'smbb-wpcodetool'),
                'edit' => __('Modifier', 'smbb-wpcodetool'),
                'download' => __('Télécharger', 'smbb-wpcodetool'),
                'delete' => __('Supprimer', 'smbb-wpcodetool'),
                'replace' => __('Remplacer', 'smbb-wpcodetool'),
                'copied' => __('Copié !', 'smbb-wpcodetool'),
            ),
        ));
    }

    /* ---------------------------------------------------------------- Préréglage Porsche */

    /** Fichiers de Porsche Next sur le CDN du Porsche Design System (latin, normal et demi-gras). */
    const PORSCHE_FONTS = array(
        400 => 'https://cdn.ui.porsche.com/porsche-design-system/fonts/porsche-next-latin-regular.b8f1c20.woff2',
        600 => 'https://cdn.ui.porsche.com/porsche-design-system/fonts/porsche-next-latin-semi-bold.b5f6fca.woff2',
    );

    public static function presetClass($classes)
    {
        return strpos(' ' . $classes . ' ', ' smbb-skin ') !== false ? $classes . ' smbb-preset-' . sanitize_html_class(Skin::settings()['preset']) : $classes;
    }

    public static function presetLoginClass($classes)
    {
        if (in_array('smbb-login', (array) $classes, true)) {
            $classes[] = 'smbb-preset-' . sanitize_html_class(Skin::settings()['preset']);
        }

        return $classes;
    }

    public static function presetAssets()
    {
        $login = doing_action('login_enqueue_scripts');

        if (!$login && !Skin::active()) {
            return;
        }

        $preset = Skin::settings()['preset'];
        $file = 'preset-' . sanitize_file_name($preset) . '.css';
        wp_enqueue_style('smbb-wpcodetool-preset', SMBB_WPCODETOOL_URL . 'assets/' . $file, array(), self::version($file));

        // Script facultatif de l'allure (assets/preset-<clé>.js), s'il existe.
        $script = 'preset-' . sanitize_file_name($preset) . '.js';

        if (is_file(SMBB_WPCODETOOL_PATH . 'assets/' . $script)) {
            wp_enqueue_script('smbb-wpcodetool-preset', SMBB_WPCODETOOL_URL . 'assets/' . $script, array(), self::version($script), true);
        }

        if ($preset === 'cristal' && Skin::settings()['cristal_font']) {
            wp_enqueue_style('smbb-wpcodetool-cristal-font', 'https://fonts.googleapis.com/css2?family=Geist:wght@400;500;600;700&family=Geist+Mono:wght@400;500;600&display=swap', array(), null);
        }

        if ($preset === 'porsche' && Skin::settings()['porsche_font']) {
            $css = '';

            foreach (self::PORSCHE_FONTS as $weight => $url) {
                $css .= "@font-face{font-family:'Porsche Next';font-style:normal;font-weight:" . $weight . ";font-display:swap;src:url('" . esc_url($url) . "') format('woff2')}";
            }

            // 700 : le demi-gras sert aussi pour le gras, comme sur le design system.
            $css .= "@font-face{font-family:'Porsche Next';font-style:normal;font-weight:700;font-display:swap;src:url('" . esc_url(self::PORSCHE_FONTS[600]) . "') format('woff2')}";

            wp_add_inline_style('smbb-wpcodetool-preset', $css);
        }
    }

    /* ---------------------------------------------------------------- Bascule clair / sombre */

    /** Icônes de la bascule (traits, couleur du texte de la barre). */
    const MODE_ICONS = array(
        'light' => '<circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/>',
        'dark' => '<path d="M20 14.5A8 8 0 0 1 9.5 4a8 8 0 1 0 10.5 10.5z"/>',
        'auto' => '<circle cx="12" cy="12" r="8.5"/><path d="M12 3.5v17a8.5 8.5 0 0 0 0-17z" fill="currentColor" stroke="none"/>',
    );

    private static function modeSvg($mode)
    {
        return '<svg class="smbb-mode-svg" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . self::MODE_ICONS[$mode] . '</svg>';
    }

    public static function modeNode($bar)
    {
        if (!is_admin() || !Skin::active()) {
            return;
        }

        $current = Skin::mode();
        $modes = Skin::modes();

        $bar->add_node(array(
            'id' => 'smbb-mode',
            'parent' => 'top-secondary',
            'title' => '<span class="smbb-mode-current">' . self::modeSvg($current) . '</span><span class="screen-reader-text">' . esc_html__('Mode d\'affichage', 'smbb-wpcodetool') . ' : ' . esc_html($modes[$current]) . '</span>',
            'href' => '#',
            'meta' => array('title' => __('Mode d\'affichage', 'smbb-wpcodetool')),
        ));

        foreach ($modes as $key => $label) {
            $bar->add_node(array(
                'id' => 'smbb-mode-' . $key,
                'parent' => 'smbb-mode',
                'title' => self::modeSvg($key) . '<span>' . esc_html($label) . '</span>',
                // Sans JavaScript, le lien enregistre le choix et revient sur la page.
                'href' => wp_nonce_url(admin_url('admin-post.php?action=smbb_codetool_mode&mode=' . $key), 'smbb_codetool_mode'),
                'meta' => array('class' => 'smbb-mode-item' . ($key === $current ? ' is-current' : '')),
            ));
        }
    }

    public static function modeAssets()
    {
        if (!is_admin_bar_showing() || !Skin::active()) {
            return;
        }

        $icons = array();

        foreach (array_keys(Skin::modes()) as $key) {
            $icons[$key] = self::modeSvg($key);
        }

        wp_add_inline_style('admin-bar', '#wpadminbar #wp-admin-bar-smbb-mode>.ab-item{display:flex;align-items:center;padding:0 9px}'
            . '#wpadminbar .smbb-mode-current{display:flex}'
            . '#wpadminbar .smbb-mode-svg{display:block}'
            . '#wpadminbar #wp-admin-bar-smbb-mode .ab-sub-wrapper{min-width:150px}'
            . '#wpadminbar #wp-admin-bar-smbb-mode .smbb-mode-item>.ab-item{display:flex;align-items:center;gap:9px}'
            . '#wpadminbar #wp-admin-bar-smbb-mode .smbb-mode-item .smbb-mode-svg{width:16px;height:16px;opacity:.75}'
            . '#wpadminbar #wp-admin-bar-smbb-mode .smbb-mode-item.is-current>.ab-item{font-weight:600}'
            . '#wpadminbar #wp-admin-bar-smbb-mode .smbb-mode-item.is-current>.ab-item::after{content:"";width:6px;height:6px;margin-left:auto;border-radius:50%;background:currentColor}');

        $data = array(
            'ajax' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('smbb_codetool_mode'),
            'icons' => $icons,
        );

        wp_add_inline_script('admin-bar', 'window.smbbMode=' . wp_json_encode($data) . ';' . self::modeJs());
    }

    /** Bascule immédiate (classe html.smbb-dark), puis enregistrement du choix dans le profil. */
    private static function modeJs()
    {
        return <<<'JS'
(function () {
    var cfg = window.smbbMode, root = document.documentElement;
    var q = window.matchMedia ? window.matchMedia('(prefers-color-scheme: dark)') : null;
    var mode = null;
    function apply() {
        root.classList.toggle('smbb-dark', mode === 'dark' || (mode === 'auto' && !!q && q.matches));
    }
    if (q) {
        (q.addEventListener ? q.addEventListener.bind(q, 'change') : q.addListener.bind(q))(function () { if (mode) { apply(); } });
    }
    document.addEventListener('click', function (e) {
        var a = e.target.closest && e.target.closest('#wp-admin-bar-smbb-mode .smbb-mode-item > a');
        if (!a) {
            if (e.target.closest && e.target.closest('#wp-admin-bar-smbb-mode > a')) { e.preventDefault(); }
            return;
        }
        e.preventDefault();
        var li = a.parentNode;
        mode = li.getAttribute('data-mode') || (a.href.match(/mode=(\w+)/) || [])[1];
        apply();
        Array.prototype.forEach.call(document.querySelectorAll('#wp-admin-bar-smbb-mode .smbb-mode-item'), function (n) {
            n.classList.toggle('is-current', n === li);
        });
        var cur = document.querySelector('#wp-admin-bar-smbb-mode .smbb-mode-current');
        if (cur && cfg.icons[mode]) { cur.innerHTML = cfg.icons[mode]; }
        var body = new FormData();
        body.append('action', 'smbb_codetool_mode');
        body.append('mode', mode);
        body.append('_wpnonce', cfg.nonce);
        fetch(cfg.ajax, { method: 'POST', credentials: 'same-origin', body: body });
    });
})();
JS;
    }

    /** Enregistre le mode choisi dans le profil (AJAX, ou lien sans JavaScript). */
    public static function saveMode()
    {
        check_admin_referer('smbb_codetool_mode');

        $mode = isset($_REQUEST['mode']) ? sanitize_key(wp_unslash($_REQUEST['mode'])) : '';

        if (isset(Skin::modes()[$mode])) {
            update_user_meta(get_current_user_id(), Skin::USER_MODE, $mode);
        }

        if (wp_doing_ajax()) {
            wp_send_json_success(array('mode' => $mode));
        }

        wp_safe_redirect(wp_get_referer() ? wp_get_referer() : admin_url());
        exit;
    }

    private static function version($file)
    {
        $path = SMBB_WPCODETOOL_PATH . 'assets/' . $file;

        return SMBB_WPCODETOOL_VERSION . (file_exists($path) ? '.' . filemtime($path) : '');
    }
}
