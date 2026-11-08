<?php

namespace Smbb\WpCodeTool\Admin;

defined('ABSPATH') || exit;

/**
 * Barre d'app en haut des écrans CodeTool (peau active uniquement).
 *
 * Elle se construit seule depuis le menu WordPress déjà enregistré :
 * - ligne 1 : nom et icône de l'app (menu de premier niveau), groupes du menu en onglets
 *   (admin.menu.group), recherche (palette de commandes WordPress, Ctrl K) et « + Créer »
 *   (ressources marquées admin.adminBar.newContent) ;
 * - ligne 2 : les pages du groupe ouvert, en pastilles.
 * Sans groupe déclaré (ex. les pages de CodeTool lui-même), les pages vont directement en ligne 1.
 *
 * En contrepartie, le menu de gauche n'affiche plus la liste dépliée des pages de l'app :
 * une seule ligne par app (le survol garde le menu volant de WordPress sur les autres écrans).
 */
final class AppBar
{
    /**
     * Menus de premier niveau créés par CodeTool (les « apps ») : slug => true.
     *
     * @var array<string,bool>
     */
    private static $apps = array('smbb-wpcodetool' => true);

    /**
     * Icônes ajoutées par les modules (Formulaires…) : slug => dashicon.
     *
     * @var array<string,string>
     */
    private static $extraIcons = array();

    /**
     * @param array<string,string> $icons slug de menu => dashicon
     */
    public static function addIcons(array $icons)
    {
        foreach ($icons as $slug => $icon) {
            self::$extraIcons[(string) $slug] = (string) $icon;
        }
    }

    /**
     * @param string[] $slugs
     */
    public static function setApps(array $slugs)
    {
        self::$apps = array('smbb-wpcodetool' => true);

        foreach ($slugs as $slug) {
            self::$apps[(string) $slug] = true;
        }
    }

    public static function isAppSlug($slug)
    {
        return isset(self::$apps[(string) $slug]);
    }

    /**
     * Données de la barre pour la page ouverte, ou null si la page n'appartient pas à une app.
     *
     * @param array<string,string> $group_labels classe CSS de groupe => libellé
     * @param array<int,array{label:string,url:string}> $create entrées de « + Créer »
     * @return array|null
     */
    public static function build(array $group_labels, array $create, array $icons = array(), array $group_icons = array())
    {
        global $menu, $submenu, $submenu_file, $plugin_page;

        $parent = get_admin_page_parent();

        if (!self::isAppSlug($parent) || empty($submenu[$parent]) || !is_array($submenu[$parent])) {
            return null;
        }

        $brand = array('title' => '', 'icon' => '');

        foreach ((array) $menu as $item) {
            if (isset($item[2]) && $item[2] === $parent) {
                $brand['title'] = self::cleanTitle(isset($item[0]) ? $item[0] : '');
                $brand['icon'] = isset($item[6]) ? (string) $item[6] : '';
                break;
            }
        }

        $current_slug = $submenu_file ? (string) $submenu_file : (string) $plugin_page;
        $groups = array();
        $index = -1;
        $current_group = 0;

        foreach ($submenu[$parent] as $item) {
            if (!isset($item[2]) || (isset($item[1]) && !current_user_can($item[1]))) {
                continue;
            }

            $classes = isset($item[4]) ? (string) $item[4] : '';
            $label = '';

            $group_icon = '';

            if (preg_match('/smbb-menu-group-\d+/', $classes, $m) && isset($group_labels[$m[0]])) {
                $label = (string) $group_labels[$m[0]];
                $group_icon = isset($group_icons[$m[0]]) ? (string) $group_icons[$m[0]] : '';
            }

            if ($index < 0 || $label !== '') {
                $groups[] = array('label' => $label, 'icon' => $group_icon, 'items' => array());
                $index = count($groups) - 1;
            }

            $slug = (string) $item[2];
            $is_current = $slug === $current_slug;

            if ($is_current) {
                $current_group = $index;
            }

            $groups[$index]['items'][] = array(
                'title' => self::cleanTitle(isset($item[0]) ? $item[0] : ''),
                'count' => self::count(isset($item[0]) ? $item[0] : ''),
                'url' => self::url($slug, $parent),
                'current' => $is_current,
                'icon' => self::itemIcon($slug, $parent, $icons),
            );
        }

        if (!$groups || $brand['title'] === '') {
            return null;
        }

        return array(
            'parent' => $parent,
            'brand' => $brand,
            'home' => $groups[0]['items'][0]['url'],
            'groups' => $groups,
            'current' => $current_group,
            'create' => $create,
        );
    }

    /**
     * HTML de la barre.
     */
    public static function render(array $bar)
    {
        self::$glyphs = !isset($bar['icons']) || $bar['icons'] !== false;
        $grouped = count($bar['groups']) > 1 || $bar['groups'][0]['label'] !== '';
        $current = $bar['groups'][$bar['current']];
        ?>
        <div class="smbb-appbar" data-smbb-appbar>
            <div class="smbb-appbar-main">
                <a class="smbb-appbar-brand" href="<?php echo esc_url($bar['home']); ?>">
                    <?php echo self::icon($bar['brand']['icon']); // phpcs:ignore -- échappé dans icon(). ?>
                    <span><?php echo esc_html($bar['brand']['title']); ?></span>
                </a>
                <nav class="smbb-appbar-tabs" aria-label="<?php echo esc_attr($bar['brand']['title']); ?>">
                    <?php if ($grouped) : ?>
                        <?php foreach ($bar['groups'] as $i => $group) : ?>
                            <?php
                            $count = array_sum(wp_list_pluck($group['items'], 'count'));
                            $label = $group['label'] !== '' ? $group['label'] : $group['items'][0]['title'];
                            ?>
                            <a class="smbb-appbar-tab<?php echo $i === $bar['current'] ? ' is-active' : ''; ?>" href="<?php echo esc_url($group['items'][0]['url']); ?>"<?php echo $i === $bar['current'] ? ' aria-current="true"' : ''; ?>>
                                <?php echo self::glyph($group['icon'] !== '' ? $group['icon'] : $group['items'][0]['icon']); // phpcs:ignore -- échappé dans glyph(). ?>
                                <?php echo esc_html($label); ?>
                                <?php if ($count > 0) : ?><span class="smbb-appbar-count"><?php echo esc_html(number_format_i18n($count)); ?></span><?php endif; ?>
                            </a>
                        <?php endforeach; ?>
                    <?php else : ?>
                        <?php self::renderItems($current['items'], 'smbb-appbar-tab'); ?>
                    <?php endif; ?>
                </nav>
                <div class="smbb-appbar-tools">
                    <button type="button" class="smbb-appbar-search" data-smbb-command-open hidden>
                        <span class="dashicons dashicons-search" aria-hidden="true"></span>
                        <span class="smbb-appbar-search-label"><?php esc_html_e('Chercher ou faire…', 'smbb-wpcodetool'); ?></span>
                        <kbd>Ctrl K</kbd>
                    </button>
                    <?php if ($bar['create']) : ?>
                        <details class="smbb-appbar-create">
                            <summary class="smbb-appbar-create-button">
                                <span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span>
                                <?php esc_html_e('Créer', 'smbb-wpcodetool'); ?>
                            </summary>
                            <div class="smbb-appbar-menu" role="menu">
                                <?php foreach ($bar['create'] as $entry) : ?>
                                    <a role="menuitem" href="<?php echo esc_url($entry['url']); ?>"><?php echo esc_html($entry['label']); ?></a>
                                <?php endforeach; ?>
                            </div>
                        </details>
                    <?php endif; ?>
                </div>
            </div>
            <?php if ($grouped && count($current['items']) > 1) : ?>
                <nav class="smbb-appbar-sub" aria-label="<?php echo esc_attr($current['label']); ?>">
                    <?php self::renderItems($current['items'], 'smbb-appbar-pill'); ?>
                </nav>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Commandes pour la palette WordPress : toutes les pages de l'app et les créations.
     *
     * @return array<int,array{name:string,label:string,url:string}>
     */
    public static function commands(array $bar)
    {
        $out = array();
        $prefix = $bar['brand']['title'];

        foreach ($bar['groups'] as $group) {
            foreach ($group['items'] as $item) {
                $out[] = array(
                    'name' => 'smbb/' . md5($item['url']),
                    'label' => $prefix . ' › ' . $item['title'],
                    'url' => $item['url'],
                );
            }
        }

        foreach ($bar['create'] as $entry) {
            $out[] = array(
                'name' => 'smbb/new-' . md5($entry['url']),
                'label' => $entry['label'],
                'url' => $entry['url'],
            );
        }

        return $out;
    }

    /**
     * Menu de gauche : une seule ligne par app, la liste dépliée disparaît (la barre la remplace).
     */
    public static function compactMenuCss()
    {
        global $menu;

        $selectors = array();
        $panels = array();

        foreach ((array) $menu as $item) {
            if (!isset($item[2], $item[5]) || !self::isAppSlug($item[2])) {
                continue;
            }

            $li = '#adminmenu li#' . sanitize_html_class($item[5]) . '.wp-has-current-submenu';
            $selectors[] = $li . ' > .wp-submenu';
            $panels[] = 'body:has(' . $li . ')';
        }

        if (!$selectors) {
            return '';
        }

        // Menu « rail + panneau » : pas de panneau quand la barre d'app montre déjà les pages.
        return '@media screen and (min-width:783px){' . implode(',', $selectors) . '{display:none}'
            . implode(',', $panels) . '{--smbb-menu-panel:0px}}';
    }

    private static function renderItems(array $items, $class)
    {
        foreach ($items as $item) {
            ?>
            <a class="<?php echo esc_attr($class . ($item['current'] ? ' is-active' : '')); ?>" href="<?php echo esc_url($item['url']); ?>"<?php echo $item['current'] ? ' aria-current="page"' : ''; ?>>
                <?php echo self::glyph($item['icon']); // phpcs:ignore -- échappé dans glyph(). ?>
                <?php echo esc_html($item['title']); ?>
                <?php if ($item['count'] > 0) : ?><span class="smbb-appbar-count"><?php echo esc_html(number_format_i18n($item['count'])); ?></span><?php endif; ?>
            </a>
            <?php
        }
    }

    /**
     * Icônes des pages de CodeTool lui-même.
     */
    const CORE_ICONS = array(
        'smbb-wpcodetool' => 'dashicons-admin-home',
        'smbb-wpcodetool-resources' => 'dashicons-database',
        'smbb-wpcodetool-api' => 'dashicons-rest-api',
        'smbb-wpcodetool-routes' => 'dashicons-admin-links',
        'smbb-wpcodetool-api-tokens' => 'dashicons-admin-network',
        'smbb-wpcodetool-encryption' => 'dashicons-lock',
        'smbb-wpcodetool-appearance' => 'dashicons-admin-appearance',
        'smbb-wpcodetool-cron-tasks' => 'dashicons-clock',
        'smbb-wpcodetool-content' => 'dashicons-admin-post',
        'smbb-wpcodetool-woocommerce' => 'dashicons-cart',
        'smbb-wpcodetool-communication' => 'dashicons-email-alt',
        'smbb-wpcodetool-sms' => 'dashicons-smartphone',
        'smbb-wpcodetool-outbox' => 'dashicons-controls-forward',
        'smbb-wpcodetool-roles' => 'dashicons-admin-users',
        'smbb-wpcodetool-roles-compare' => 'dashicons-columns',
        'smbb-wpcodetool-roles-history' => 'dashicons-backup',
        'smbb-wpcodetool-security' => 'dashicons-shield-alt',
        'smbb-wpcodetool-security-turnstile' => 'dashicons-cloud',
        'smbb-wpcodetool-security-2fa' => 'dashicons-privacy',
        'smbb-wpcodetool-security-blocked' => 'dashicons-dismiss',
        'smbb-wpcodetool-activity' => 'dashicons-list-view',
        'smbb-wpcodetool-activity-settings' => 'dashicons-admin-settings',
        'smbb-wpcodetool-wizard' => 'dashicons-welcome-learn-more',
    );

    /**
     * Icône d'une page qui n'en déclare aucune : jamais d'entrée sans icône dans la barre.
     */
    const DEFAULT_ICON = 'dashicons-admin-page';

    private static function itemIcon($slug, $parent, array $icons)
    {
        // L'entrée qui ouvre l'app elle-même (« Tableau de bord ») : icône de tableau de bord.
        if ($slug === $parent && $slug !== 'smbb-wpcodetool') {
            return 'dashicons-dashboard';
        }

        if (isset(self::$extraIcons[$slug])) {
            return self::$extraIcons[$slug];
        }

        if (isset($icons[$slug]) && self::isIcon($icons[$slug])) {
            return (string) $icons[$slug];
        }

        if (isset(self::CORE_ICONS[$slug])) {
            return self::CORE_ICONS[$slug];
        }

        // Repli : filtrable pour les modules qui veulent un autre pictogramme par défaut.
        $default = (string) apply_filters('smbb_wpcodetool_appbar_default_icon', self::DEFAULT_ICON, $slug, $parent);

        return self::isIcon($default) ? $default : self::DEFAULT_ICON;
    }

    /**
     * Valeur affichable par glyph() : une dashicon ou un SVG en data URI.
     */
    private static function isIcon($icon)
    {
        $icon = (string) $icon;

        return strpos($icon, 'dashicons-') === 0 || strpos($icon, 'data:image/svg+xml;base64,') === 0;
    }

    /** @var bool Icônes affichées (réglage CodeTool › Apparence). */
    private static $glyphs = true;

    private static function glyph($icon)
    {
        if (!self::$glyphs) {
            return '';
        }

        if (strpos((string) $icon, 'data:image/svg+xml;base64,') === 0) {
            return '<span class="smbb-appbar-glyph smbb-svg-icon" aria-hidden="true" style="--smbb-icon:url(\'' . esc_attr($icon) . '\')"></span>';
        }

        if (strpos((string) $icon, 'dashicons-') !== 0) {
            return '';
        }

        return '<span class="smbb-appbar-glyph dashicons ' . esc_attr($icon) . '" aria-hidden="true"></span>';
    }

    private static function icon($icon)
    {
        if (strpos($icon, 'data:image/svg+xml;base64,') === 0) {
            // SVG passé en masque : il prend la couleur du texte, comme une dashicon (accent du skin).
            return '<span class="smbb-appbar-icon" aria-hidden="true"><span class="smbb-svg-icon" style="--smbb-icon:url(\'' . esc_attr($icon) . '\')"></span></span>';
        }

        if (strpos($icon, 'dashicons-') === 0) {
            return '<span class="smbb-appbar-icon dashicons ' . esc_attr($icon) . '" aria-hidden="true"></span>';
        }

        return '<span class="smbb-appbar-icon dashicons dashicons-admin-generic" aria-hidden="true"></span>';
    }

    /**
     * Titre de menu sans les bulles de compteur (« Inscriptions <span class="awaiting-mod">3</span> »).
     */
    private static function cleanTitle($title)
    {
        $title = preg_replace('#<span[^>]*class="[^"]*(awaiting-mod|update-plugins|count|menu-counter)[^"]*"[^>]*>.*?</span>\s*(</span>)?#is', '', (string) $title);

        return trim(wp_strip_all_tags((string) $title));
    }

    private static function count($title)
    {
        if (preg_match('#class="[^"]*(awaiting-mod|update-plugins|menu-counter)[^"]*"[^>]*>(?:<span[^>]*>)?\s*(\d+)#i', (string) $title, $m)) {
            return (int) $m[2];
        }

        return 0;
    }

    private static function url($slug, $parent)
    {
        if (strpos($slug, '.php') !== false || strpos($slug, '://') !== false) {
            return strpos($slug, '://') !== false ? $slug : admin_url($slug);
        }

        // Même règle que WordPress : page d'un plugin rattachée au parent.
        $parent_file = strpos($parent, '.php') !== false ? $parent : 'admin.php';

        return add_query_arg('page', $slug, admin_url($parent_file));
    }
}
