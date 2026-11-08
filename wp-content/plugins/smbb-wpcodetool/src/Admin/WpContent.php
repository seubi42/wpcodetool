<?php

namespace Smbb\WpCodeTool\Admin;

defined('ABSPATH') || exit;

/**
 * « Contenu WP » (CodeTool › Contenu WP) : couper les briques de contenu de WordPress
 * dont le site n'a pas besoin. Chaque réglage est indépendant et coupé par défaut.
 *
 * - posts_off            : pas de blog (menu Articles, « + Nouveau », accueil, flux, archives, plan du site…).
 * - comments_off         : commentaires coupés partout (affichage, envoi, admin, API REST, XML-RPC, pings).
 * - attachment_pages_off : les pages de pièce jointe renvoient vers le fichier.
 * - author_archives_off  : pas de pages auteur ni de liste des comptes dans l'API pour les visiteurs.
 * - emojis_off           : pas de script ni de styles d'émojis de WordPress.
 * - xmlrpc_off           : XML-RPC coupé (vieille API, cible fréquente des robots).
 *
 * Rien n'est supprimé en base : recocher remet tout comme avant.
 */
final class WpContent
{
    const OPTION = 'smbb_codetool_content';

    /** Interrupteurs et leur valeur par défaut. */
    const SWITCHES = array(
        'posts_off' => 0,
        'comments_off' => 0,
        'attachment_pages_off' => 0,
        'author_archives_off' => 0,
        'emojis_off' => 0,
        'xmlrpc_off' => 0,
    );

    /** @var array|null */
    private static $settings = null;

    /**
     * @return array<string,int>
     */
    public static function settings()
    {
        if (self::$settings !== null) {
            return self::$settings;
        }

        $saved = get_option(self::OPTION, null);

        // Avant : « Couper les commentaires partout » vivait dans CodeTool › Apparence.
        // On reprend sa valeur une fois, la première fois que la page Contenu WP n'a pas encore de réglage.
        if (!is_array($saved)) {
            $appearance = get_option(Skin::OPTION, array());
            $saved = array(
                'comments_off' => is_array($appearance) && !empty($appearance['comments_off']) ? 1 : 0,
            );
            update_option(self::OPTION, self::clean($saved), true);
        }

        self::$settings = self::clean($saved);

        return self::$settings;
    }

    public static function save(array $values)
    {
        self::$settings = null;
        update_option(self::OPTION, self::clean($values), true);
    }

    public static function on($key)
    {
        return !empty(self::settings()[$key]);
    }

    private static function clean(array $values)
    {
        $out = array();

        foreach (self::SWITCHES as $key => $default) {
            $out[$key] = isset($values[$key]) ? (int) (bool) $values[$key] : $default;
        }

        return $out;
    }

    /**
     * Appelé sur init (priorité 0), après l'enregistrement des types de contenu de WordPress.
     */
    public static function hooks()
    {
        if (self::on('posts_off')) {
            self::postsOff();
        }

        if (self::on('comments_off')) {
            self::commentsOff();
        }

        if (self::on('attachment_pages_off')) {
            add_action('template_redirect', array(__CLASS__, 'redirectAttachment'), 1);
        }

        if (self::on('author_archives_off')) {
            add_action('template_redirect', array(__CLASS__, 'blockAuthorArchive'), 1);
            add_filter('author_link', array(__CLASS__, 'authorLink'), 99);
            add_filter('rest_endpoints', array(__CLASS__, 'dropUserRoutes'));
            add_filter('wp_sitemaps_add_provider', array(__CLASS__, 'dropUserSitemap'), 10, 2);
        }

        if (self::on('emojis_off')) {
            self::emojisOff();
        }

        if (self::on('xmlrpc_off')) {
            add_filter('xmlrpc_enabled', '__return_false');
            add_filter('xmlrpc_methods', '__return_empty_array', 99);
            add_filter('wp_headers', array(__CLASS__, 'dropPingbackHeader'));
            remove_action('wp_head', 'rsd_link');
        }
    }

    /* ---------------------------------------------------------------- Articles */

    private static function postsOff()
    {
        // Le type « post » et ses taxonomies sortent des menus, de la barre du haut, de la recherche,
        // de la recherche Ctrl K et des menus de navigation. Il reste dans l'API REST (éditeur de blocs).
        add_action('init', array(__CLASS__, 'hidePostType'), 1);

        // Admin : menu, écrans, barre du haut, accueil, colonne « Articles » des comptes.
        add_action('admin_menu', array(__CLASS__, 'dropPostMenus'), 999);
        add_action('admin_init', array(__CLASS__, 'blockPostScreens'), 1);
        add_action('admin_bar_menu', array(__CLASS__, 'dropPostNodes'), 999);
        add_action('wp_dashboard_setup', array(__CLASS__, 'dropPostWidgets'), 999);
        add_action('admin_head-index.php', array(__CLASS__, 'dashboardCss'));
        add_filter('manage_users_columns', array(__CLASS__, 'dropUserPostsColumn'));
        add_action('admin_notices', array(__CLASS__, 'homeNotice'));

        // Site : flux, archives et articles seuls, plan du site, widgets du blog.
        remove_action('wp_head', 'feed_links', 2);
        remove_action('wp_head', 'feed_links_extra', 3);
        add_action('template_redirect', array(__CLASS__, 'blockBlogPages'), 1);
        add_filter('wp_sitemaps_post_types', array(__CLASS__, 'dropPostSitemap'));
        add_filter('wp_sitemaps_taxonomies', array(__CLASS__, 'dropTaxonomySitemaps'));
        add_action('widgets_init', array(__CLASS__, 'dropBlogWidgets'), 20);
    }

    public static function hidePostType()
    {
        $post = get_post_type_object('post');

        if ($post) {
            $post->show_ui = false;
            $post->show_in_menu = false;
            $post->show_in_admin_bar = false;
            $post->show_in_nav_menus = false;
            $post->exclude_from_search = true;
        }

        foreach (array('category', 'post_tag', 'post_format') as $name) {
            $taxonomy = get_taxonomy($name);

            if ($taxonomy && $taxonomy->object_type === array('post')) {
                $taxonomy->show_ui = false;
                $taxonomy->show_in_menu = false;
                $taxonomy->show_in_nav_menus = false;
                $taxonomy->show_tagcloud = false;
                $taxonomy->show_in_quick_edit = false;
                $taxonomy->show_admin_column = false;
            }
        }
    }

    public static function dropPostMenus()
    {
        remove_menu_page('edit.php');
        remove_submenu_page('options-general.php', 'options-writing.php');
    }

    /** Écrans des articles (liste, création, modification, catégories, étiquettes) : retour à l'accueil. */
    public static function blockPostScreens()
    {
        global $pagenow;

        if (wp_doing_ajax()) {
            return;
        }

        $type = isset($_GET['post_type']) ? sanitize_key(wp_unslash($_GET['post_type'])) : 'post'; // phpcs:ignore WordPress.Security.NonceVerification
        $blocked = false;

        if (in_array($pagenow, array('edit.php', 'post-new.php'), true)) {
            $blocked = $type === 'post';
        } elseif ($pagenow === 'post.php' && isset($_GET['post'])) { // phpcs:ignore WordPress.Security.NonceVerification
            $blocked = get_post_type(absint($_GET['post'])) === 'post'; // phpcs:ignore WordPress.Security.NonceVerification
        } elseif (in_array($pagenow, array('edit-tags.php', 'term.php'), true)) {
            $taxonomy = isset($_GET['taxonomy']) ? sanitize_key(wp_unslash($_GET['taxonomy'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification
            $blocked = in_array($taxonomy, array('category', 'post_tag'), true) && $type === 'post';
        } elseif ($pagenow === 'options-writing.php') {
            $blocked = true;
        }

        if ($blocked) {
            wp_safe_redirect(admin_url());
            exit;
        }
    }

    public static function dropPostNodes($bar)
    {
        $bar->remove_node('new-post');

        // « + Nouveau » pointait sur « Article » : il pointe maintenant sur sa première entrée restante.
        $top = $bar->get_node('new-content');

        if ($top && strpos((string) $top->href, 'post-new.php') !== false && strpos((string) $top->href, 'post_type=') === false) {
            foreach ($bar->get_nodes() as $node) {
                if ($node->parent === 'new-content' && $node->href) {
                    $bar->add_node(array('id' => 'new-content', 'href' => $node->href));
                    break;
                }
            }
        }
    }

    public static function dropPostWidgets()
    {
        remove_meta_box('dashboard_quick_press', 'dashboard', 'side');
        remove_meta_box('dashboard_recent_drafts', 'dashboard', 'side');
        remove_meta_box('dashboard_activity', 'dashboard', 'normal');
    }

    /** « D'un coup d'œil » compte les articles en dur : on masque la ligne. */
    public static function dashboardCss()
    {
        echo '<style>#dashboard_right_now li.post-count{display:none}</style>' . "\n";
    }

    public static function dropUserPostsColumn($columns)
    {
        unset($columns['posts']);

        return $columns;
    }

    /** Accueil du site = derniers articles : sans blog, il faut une page d'accueil fixe. */
    public static function homeNotice()
    {
        if (!current_user_can('manage_options') || get_option('show_on_front') !== 'posts') {
            return;
        }

        $screen = function_exists('get_current_screen') ? get_current_screen() : null;

        // La page Contenu WP affiche son propre avertissement.
        if (!$screen || !in_array($screen->id, array('dashboard', 'options-reading'), true)) {
            return;
        }

        echo '<div class="notice notice-warning"><p>'
            . esc_html__('Les articles sont coupés (CodeTool › Contenu WP), mais la page d\'accueil du site affiche encore « les derniers articles ». Choisissez une page fixe :', 'smbb-wpcodetool')
            . ' <a href="' . esc_url(admin_url('options-reading.php')) . '">' . esc_html__('Réglages › Lecture', 'smbb-wpcodetool') . '</a></p></div>';
    }

    /** Site : article seul, catégories, étiquettes, archives par date → 404 ; flux → accueil. */
    public static function blockBlogPages()
    {
        if (is_feed()) {
            wp_safe_redirect(home_url('/'), 301);
            exit;
        }

        if (is_singular('post') || is_category() || is_tag() || is_date() || (is_home() && !is_front_page())) {
            self::notFound();
        }
    }

    public static function dropPostSitemap($types)
    {
        unset($types['post']);

        return $types;
    }

    public static function dropTaxonomySitemaps($taxonomies)
    {
        unset($taxonomies['category'], $taxonomies['post_tag']);

        return $taxonomies;
    }

    public static function dropBlogWidgets()
    {
        foreach (array('WP_Widget_Recent_Posts', 'WP_Widget_Archives', 'WP_Widget_Categories', 'WP_Widget_Tag_Cloud', 'WP_Widget_Calendar') as $widget) {
            unregister_widget($widget);
        }
    }

    /* ---------------------------------------------------------------- Commentaires */

    private static function commentsOff()
    {
        // Les contenus ne gèrent plus les commentaires : métaboxes, colonnes, réglages disparaissent.
        add_action('init', array(__CLASS__, 'dropCommentSupport'), 100);

        // Site : rien d'ouvert, rien d'affiché, aucun envoi accepté.
        add_filter('comments_open', '__return_false', 20);
        add_filter('pings_open', '__return_false', 20);
        add_filter('comments_array', '__return_empty_array', 20);
        add_filter('get_comments_number', '__return_zero', 20);
        add_action('pre_comment_on_post', array(__CLASS__, 'refuseComment'));
        add_filter('feed_links_show_comments_feed', '__return_false');
        add_filter('wp_headers', array(__CLASS__, 'dropPingbackHeader'));
        add_filter('xmlrpc_methods', array(__CLASS__, 'dropXmlrpcMethods'));
        add_filter('rest_endpoints', array(__CLASS__, 'dropCommentRoutes'));
        add_action('widgets_init', array(__CLASS__, 'dropCommentWidgetClass'), 20);

        // Admin : menu, réglages, page des commentaires, barre du haut, accueil.
        add_action('admin_menu', array(__CLASS__, 'dropCommentMenus'), 999);
        add_action('admin_init', array(__CLASS__, 'blockCommentScreens'));
        add_action('admin_bar_menu', array(__CLASS__, 'dropCommentNode'), 999);
        add_action('wp_dashboard_setup', array(__CLASS__, 'dropCommentWidget'), 999);
    }

    public static function dropCommentSupport()
    {
        foreach (get_post_types() as $type) {
            if (post_type_supports($type, 'comments')) {
                remove_post_type_support($type, 'comments');
            }

            if (post_type_supports($type, 'trackbacks')) {
                remove_post_type_support($type, 'trackbacks');
            }
        }
    }

    public static function refuseComment()
    {
        wp_die(esc_html__('Les commentaires sont fermés sur ce site.', 'smbb-wpcodetool'), '', array('response' => 403));
    }

    public static function dropPingbackHeader($headers)
    {
        unset($headers['X-Pingback']);

        return $headers;
    }

    public static function dropXmlrpcMethods($methods)
    {
        foreach (array('pingback.ping', 'pingback.extensions.getPingbacks', 'wp.newComment', 'wp.editComment', 'wp.deleteComment') as $method) {
            unset($methods[$method]);
        }

        return $methods;
    }

    public static function dropCommentRoutes($routes)
    {
        foreach (array_keys($routes) as $route) {
            if (strpos($route, '/wp/v2/comments') === 0) {
                unset($routes[$route]);
            }
        }

        return $routes;
    }

    public static function dropCommentWidgetClass()
    {
        unregister_widget('WP_Widget_Recent_Comments');
    }

    public static function dropCommentMenus()
    {
        remove_menu_page('edit-comments.php');
        remove_submenu_page('options-general.php', 'options-discussion.php');
    }

    public static function blockCommentScreens()
    {
        global $pagenow;

        if (in_array($pagenow, array('edit-comments.php', 'comment.php', 'options-discussion.php'), true)) {
            wp_safe_redirect(admin_url());
            exit;
        }
    }

    public static function dropCommentNode($bar)
    {
        $bar->remove_node('comments');
    }

    public static function dropCommentWidget()
    {
        remove_meta_box('dashboard_recent_comments', 'dashboard', 'normal');
    }

    /* ---------------------------------------------------------------- Pièces jointes, auteurs */

    public static function redirectAttachment()
    {
        if (!is_attachment()) {
            return;
        }

        $url = wp_get_attachment_url(get_queried_object_id());

        if ($url) {
            wp_safe_redirect($url, 301);
            exit;
        }

        self::notFound();
    }

    public static function blockAuthorArchive()
    {
        // ?author=1 donne le nom du compte (redirection vers /author/nom/) : on coupe avant.
        if (is_author() || (isset($_GET['author']) && !is_admin())) { // phpcs:ignore WordPress.Security.NonceVerification
            self::notFound();
        }
    }

    public static function authorLink()
    {
        return home_url('/');
    }

    /** Liste des comptes de l'API : seulement pour les personnes connectées (l'éditeur en a besoin). */
    public static function dropUserRoutes($routes)
    {
        if (is_user_logged_in()) {
            return $routes;
        }

        foreach (array_keys($routes) as $route) {
            if (strpos($route, '/wp/v2/users') === 0) {
                unset($routes[$route]);
            }
        }

        return $routes;
    }

    public static function dropUserSitemap($provider, $name)
    {
        return $name === 'users' ? false : $provider;
    }

    /* ---------------------------------------------------------------- Émojis */

    private static function emojisOff()
    {
        remove_action('wp_head', 'print_emoji_detection_script', 7);
        remove_action('admin_print_scripts', 'print_emoji_detection_script');
        remove_action('wp_print_styles', 'print_emoji_styles');
        remove_action('admin_print_styles', 'print_emoji_styles');
        remove_action('wp_enqueue_scripts', 'wp_enqueue_emoji_styles');
        remove_action('admin_enqueue_scripts', 'wp_enqueue_emoji_styles');
        remove_filter('the_content_feed', 'wp_staticize_emoji');
        remove_filter('comment_text_rss', 'wp_staticize_emoji');
        remove_filter('wp_mail', 'wp_staticize_emoji_for_email');
        add_filter('emoji_svg_url', '__return_false');
        add_filter('tiny_mce_plugins', array(__CLASS__, 'dropTinymceEmoji'));
        add_filter('wp_resource_hints', array(__CLASS__, 'dropEmojiHint'), 10, 2);
    }

    public static function dropTinymceEmoji($plugins)
    {
        return is_array($plugins) ? array_values(array_diff($plugins, array('wpemoji'))) : $plugins;
    }

    public static function dropEmojiHint($urls, $relation)
    {
        if ($relation !== 'dns-prefetch') {
            return $urls;
        }

        return array_values(array_filter((array) $urls, static function ($url) {
            $href = is_array($url) ? (string) ($url['href'] ?? '') : (string) $url;

            return strpos($href, 's.w.org/images/core/emoji') === false;
        }));
    }

    /* ---------------------------------------------------------------- Outils */

    private static function notFound()
    {
        global $wp_query;

        $wp_query->set_404();
        status_header(404);
        nocache_headers();
    }
}
