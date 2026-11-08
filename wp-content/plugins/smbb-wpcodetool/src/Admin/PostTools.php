<?php

namespace Smbb\WpCodeTool\Admin;

defined('ABSPATH') || exit;

/**
 * Outils des listes de contenus WordPress (CodeTool › Apparence › « Marque blanche et ménage »).
 *
 * - Dupliquer (interrupteur list_duplicate, indépendant du style moderne : c'est une fonction,
 *   pas de l'habillage) : lien « Dupliquer » sous le titre de chaque ligne, action groupée
 *   (donc aussi dans la barre flottante de ListUx), et « Dupliquer » dans la barre du haut sur
 *   l'écran d'édition et sur le site. La copie est un brouillon de l'utilisateur courant :
 *   contenu, extrait, parent, ordre, commentaires, mot de passe, toutes les métas (image mise en
 *   avant, modèle de page, ACF, Elementor…) et toutes les taxonomies.
 * - Enfants de… (interrupteur list_children, suit le style moderne comme les autres améliorations
 *   des listes) : sur les listes hiérarchiques (pages…), un bouton ouvre une fenêtre avec l'arbre
 *   des contenus qui ont des enfants. On en choisit un : la liste n'affiche plus que ses enfants,
 *   petits-enfants, etc. (paramètre d'URL smbb_parent, gardé par « Filtrer » et la recherche).
 *
 * Filtres : smbb_wpcodetool_duplicate_post_types (types qu'on peut dupliquer),
 * smbb_wpcodetool_duplicate_post_data (champs de la copie), smbb_wpcodetool_duplicate_skip_meta
 * (métas non copiées). Action : smbb_wpcodetool_post_duplicated ($new_id, $original).
 */
final class PostTools
{
    const ACTION = 'smbb_duplicate_post';
    const BULK = 'smbb_duplicate';
    const PARAM = 'smbb_parent';
    const FROM_META = '_smbb_duplicated_from';

    /** Métas jamais copiées : verrous, historique d'URL, corbeille, marque de copie. */
    const SKIP_META = array(
        '_edit_lock',
        '_edit_last',
        '_wp_old_slug',
        '_wp_old_date',
        '_wp_trash_meta_status',
        '_wp_trash_meta_time',
        '_wp_trash_meta_comments_status',
        '_wp_desired_post_slug',
        '_encloseme',
        '_pingme',
        self::FROM_META,
    );

    /** Types exclus : techniques, ou WooCommerce qui a déjà son propre « Dupliquer ». */
    const EXCLUDED = array(
        'attachment', 'revision', 'nav_menu_item', 'custom_css', 'customize_changeset', 'oembed_cache',
        'user_request', 'wp_block', 'wp_template', 'wp_template_part', 'wp_global_styles', 'wp_navigation',
        'wp_font_family', 'wp_font_face', 'product', 'product_variation', 'shop_order', 'shop_order_refund',
        'shop_order_placehold', 'shop_coupon',
    );

    /** @var \WP_Post|false|null contenu choisi dans « Enfants de… » (null = pas encore lu) */
    private static $parent = null;

    /**
     * @param bool $duplicate action « Dupliquer »
     * @param bool $children  filtre « Enfants de… » des listes hiérarchiques
     */
    public static function hooks($duplicate, $children)
    {
        if ($duplicate) {
            add_filter('post_row_actions', array(__CLASS__, 'rowAction'), 20, 2);
            add_filter('page_row_actions', array(__CLASS__, 'rowAction'), 20, 2);
            add_action('admin_action_' . self::ACTION, array(__CLASS__, 'duplicateAction'));
            add_action('load-edit.php', array(__CLASS__, 'bulkHooks'));
            add_action('admin_bar_menu', array(__CLASS__, 'barNode'), 81);
            add_action('admin_notices', array(__CLASS__, 'notice'));
            add_filter('removable_query_args', array(__CLASS__, 'removableArgs'));
        }

        if ($children) {
            add_action('load-edit.php', array(__CLASS__, 'childrenHooks'));
        }
    }

    /* ---------------------------------------------------------------- Dupliquer */

    /** Types de contenus qu'on peut dupliquer. */
    public static function types()
    {
        $types = array();

        foreach (get_post_types(array('show_ui' => true)) as $name) {
            if (!in_array($name, self::EXCLUDED, true)) {
                $types[] = $name;
            }
        }

        return array_values((array) apply_filters('smbb_wpcodetool_duplicate_post_types', $types));
    }

    /** L'utilisateur courant peut-il dupliquer ce contenu ? (le lire/modifier et en créer) */
    public static function canDuplicate($post)
    {
        $post = get_post($post);

        if (!$post instanceof \WP_Post || $post->post_status === 'trash' || !in_array($post->post_type, self::types(), true)) {
            return false;
        }

        $type = get_post_type_object($post->post_type);

        return $type && current_user_can('edit_post', $post->ID) && current_user_can($type->cap->create_posts);
    }

    /** Lien signé de duplication ; $edit = ouvrir la copie juste après. */
    public static function url($id, $edit = false)
    {
        $args = array('action' => self::ACTION, 'post' => (int) $id);

        if ($edit) {
            $args['edit'] = 1;
        }

        return wp_nonce_url(add_query_arg($args, admin_url('admin.php')), self::ACTION . '_' . (int) $id);
    }

    /** Lien « Dupliquer » sous le titre, juste avant « Corbeille ». */
    public static function rowAction($actions, $post)
    {
        if (!$post instanceof \WP_Post || !self::canDuplicate($post)) {
            return $actions;
        }

        $link = '<a href="' . esc_url(self::url($post->ID)) . '" aria-label="'
            /* translators: %s: titre du contenu */
            . esc_attr(sprintf(__('Dupliquer « %s » en brouillon', 'smbb-wpcodetool'), self::title($post))) . '">'
            . esc_html__('Dupliquer', 'smbb-wpcodetool') . '</a>';

        $out = array();

        foreach ((array) $actions as $key => $html) {
            if ($key === 'trash' || $key === 'delete') {
                $out['smbb_duplicate'] = $link;
            }

            $out[$key] = $html;
        }

        if (!isset($out['smbb_duplicate'])) {
            $out['smbb_duplicate'] = $link;
        }

        return $out;
    }

    /** admin.php?action=smbb_duplicate_post&post=ID : crée la copie puis revient à la liste (ou ouvre la copie). */
    public static function duplicateAction()
    {
        $id = isset($_GET['post']) ? absint($_GET['post']) : 0; // phpcs:ignore WordPress.Security.NonceVerification -- vérifié juste après.
        check_admin_referer(self::ACTION . '_' . $id);

        if (!self::canDuplicate($id)) {
            wp_die(esc_html__('Vous ne pouvez pas dupliquer ce contenu.', 'smbb-wpcodetool'), '', array('response' => 403, 'back_link' => true));
        }

        $new = self::duplicate($id);

        if (is_wp_error($new)) {
            wp_die(esc_html($new->get_error_message()), '', array('back_link' => true));
        }

        if (!empty($_GET['edit'])) { // phpcs:ignore WordPress.Security.NonceVerification
            wp_safe_redirect(get_edit_post_link($new, 'raw'));
            exit;
        }

        $post = get_post($id);
        $back = wp_get_referer();

        if (!$back || strpos($back, 'edit.php') === false) {
            $back = admin_url($post->post_type === 'post' ? 'edit.php' : 'edit.php?post_type=' . $post->post_type);
        }

        wp_safe_redirect(add_query_arg(array('smbb_duplicated' => 1, 'smbb_copy' => (int) $new), remove_query_arg(array('smbb_duplicated', 'smbb_copy'), $back)));
        exit;
    }

    /**
     * Crée la copie en brouillon.
     *
     * @return int|\WP_Error identifiant de la copie
     */
    public static function duplicate($id)
    {
        $post = get_post($id);

        if (!$post instanceof \WP_Post) {
            return new \WP_Error('smbb_duplicate', __('Contenu introuvable.', 'smbb-wpcodetool'));
        }

        $data = array(
            'post_type' => $post->post_type,
            'post_status' => 'draft',
            'post_author' => get_current_user_id(),
            'post_title' => self::copyTitle($post->post_title),
            'post_content' => $post->post_content,
            'post_content_filtered' => $post->post_content_filtered,
            'post_excerpt' => $post->post_excerpt,
            'post_parent' => (int) $post->post_parent,
            'menu_order' => (int) $post->menu_order,
            'comment_status' => $post->comment_status,
            'ping_status' => $post->ping_status,
            'post_password' => $post->post_password,
            'post_mime_type' => $post->post_mime_type,
        );

        $data = (array) apply_filters('smbb_wpcodetool_duplicate_post_data', $data, $post);
        $new = wp_insert_post(wp_slash($data), true);

        if (is_wp_error($new)) {
            return $new;
        }

        $new = (int) $new;

        // Métas : toutes, valeurs multiples comprises (sauf verrous, historique, corbeille).
        $skip = (array) apply_filters('smbb_wpcodetool_duplicate_skip_meta', self::SKIP_META, $post);

        foreach ((array) get_post_meta($post->ID) as $key => $values) {
            if (in_array($key, $skip, true) || strpos((string) $key, '_wp_trash_') === 0) {
                continue;
            }

            // Une extension a pu poser sa valeur par défaut pendant wp_insert_post : l'original l'emporte.
            delete_post_meta($new, $key);

            foreach ((array) $values as $value) {
                add_post_meta($new, $key, wp_slash(maybe_unserialize($value)));
            }
        }

        add_post_meta($new, self::FROM_META, (int) $post->ID, true);

        // Taxonomies : catégories, étiquettes, taxonomies des extensions (remplace la catégorie par défaut).
        foreach (get_object_taxonomies($post->post_type) as $taxonomy) {
            $terms = wp_get_object_terms($post->ID, $taxonomy, array('fields' => 'ids'));

            if (!is_wp_error($terms)) {
                wp_set_object_terms($new, array_map('intval', $terms), $taxonomy);
            }
        }

        clean_post_cache($new);

        do_action('smbb_wpcodetool_post_duplicated', $new, $post);

        return $new;
    }

    private static function copyTitle($title)
    {
        $title = trim((string) $title);

        /* translators: %s: titre d'origine */
        return $title === '' ? __('(copie)', 'smbb-wpcodetool') : sprintf(__('%s (copie)', 'smbb-wpcodetool'), $title);
    }

    /** Action groupée « Dupliquer » sur la liste courante (hors corbeille). */
    public static function bulkHooks()
    {
        $screen = get_current_screen();

        if (!$screen || !in_array($screen->post_type, self::types(), true)) {
            return;
        }

        if (isset($_GET['post_status']) && $_GET['post_status'] === 'trash') { // phpcs:ignore WordPress.Security.NonceVerification
            return;
        }

        $type = get_post_type_object($screen->post_type);

        if (!$type || !current_user_can($type->cap->create_posts)) {
            return;
        }

        add_filter('bulk_actions-' . $screen->id, array(__CLASS__, 'bulkAction'));
        add_filter('handle_bulk_actions-' . $screen->id, array(__CLASS__, 'bulkHandle'), 10, 3);
    }

    public static function bulkAction($actions)
    {
        $out = array();

        foreach ((array) $actions as $key => $label) {
            if ($key === 'trash' || $key === 'delete') {
                $out[self::BULK] = __('Dupliquer', 'smbb-wpcodetool');
            }

            $out[$key] = $label;
        }

        if (!isset($out[self::BULK])) {
            $out[self::BULK] = __('Dupliquer', 'smbb-wpcodetool');
        }

        return $out;
    }

    /** Le nonce « bulk-posts » est déjà vérifié par WordPress avant ce filtre. */
    public static function bulkHandle($redirect, $action, $ids)
    {
        if ($action !== self::BULK) {
            return $redirect;
        }

        $count = 0;

        foreach ((array) $ids as $id) {
            if (self::canDuplicate((int) $id) && !is_wp_error(self::duplicate((int) $id))) {
                $count++;
            }
        }

        return add_query_arg('smbb_duplicated', $count, remove_query_arg(array('smbb_duplicated', 'smbb_copy'), $redirect));
    }

    /** « Dupliquer » dans la barre du haut : écran d'édition et contenu affiché sur le site. */
    public static function barNode($bar)
    {
        $id = 0;

        if (is_admin()) {
            $screen = function_exists('get_current_screen') ? get_current_screen() : null;

            if ($screen && $screen->base === 'post' && isset($_GET['post'])) { // phpcs:ignore WordPress.Security.NonceVerification
                $id = absint($_GET['post']); // phpcs:ignore WordPress.Security.NonceVerification
            }
        } elseif (is_singular()) {
            $id = (int) get_queried_object_id();
        }

        if (!$id || !self::canDuplicate($id)) {
            return;
        }

        $bar->add_node(array(
            'id' => 'smbb-duplicate',
            'title' => '<span class="ab-icon dashicons dashicons-admin-page" aria-hidden="true"></span><span class="ab-label">' . esc_html__('Dupliquer', 'smbb-wpcodetool') . '</span>',
            'href' => self::url($id, true),
            'meta' => array('title' => __('Créer une copie en brouillon et l\'ouvrir', 'smbb-wpcodetool')),
        ));
    }

    public static function notice()
    {
        global $pagenow;

        if ($pagenow !== 'edit.php' || !isset($_GET['smbb_duplicated'])) { // phpcs:ignore WordPress.Security.NonceVerification
            return;
        }

        $count = absint($_GET['smbb_duplicated']); // phpcs:ignore WordPress.Security.NonceVerification
        $copy = isset($_GET['smbb_copy']) ? absint($_GET['smbb_copy']) : 0; // phpcs:ignore WordPress.Security.NonceVerification

        if (!$count) {
            echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html__('Rien n\'a été dupliqué.', 'smbb-wpcodetool') . '</p></div>';

            return;
        }

        /* translators: %d: nombre de copies */
        $message = esc_html(sprintf(_n('%d copie créée en brouillon.', '%d copies créées en brouillon.', $count, 'smbb-wpcodetool'), $count));

        if ($count === 1 && $copy && current_user_can('edit_post', $copy)) {
            $message .= ' <a href="' . esc_url(get_edit_post_link($copy)) . '">' . esc_html__('Modifier la copie', 'smbb-wpcodetool') . '</a>';
        }

        echo '<div class="notice notice-success is-dismissible"><p>' . $message . '</p></div>'; // phpcs:ignore -- échappé au-dessus.
    }

    public static function removableArgs($args)
    {
        $args[] = 'smbb_duplicated';
        $args[] = 'smbb_copy';

        return $args;
    }

    /* ---------------------------------------------------------------- Enfants de… */

    public static function childrenHooks()
    {
        $type = isset($_GET['post_type']) ? sanitize_key(wp_unslash($_GET['post_type'])) : 'post'; // phpcs:ignore WordPress.Security.NonceVerification

        if (!post_type_exists($type) || !is_post_type_hierarchical($type)) {
            return;
        }

        add_action('pre_get_posts', array(__CLASS__, 'childrenQuery'));
        add_action('restrict_manage_posts', array(__CLASS__, 'childrenControl'), 20, 2);
        add_action('admin_enqueue_scripts', array(__CLASS__, 'childrenAssets'));
    }

    /** Contenu choisi (paramètre smbb_parent), du type de la liste. */
    private static function currentParent()
    {
        if (self::$parent !== null) {
            return self::$parent;
        }

        self::$parent = false;
        $id = isset($_GET[self::PARAM]) ? absint($_GET[self::PARAM]) : 0; // phpcs:ignore WordPress.Security.NonceVerification
        $type = isset($_GET['post_type']) ? sanitize_key(wp_unslash($_GET['post_type'])) : 'post'; // phpcs:ignore WordPress.Security.NonceVerification
        $post = $id ? get_post($id) : null;

        if ($post instanceof \WP_Post && $post->post_type === $type) {
            self::$parent = $post;
        }

        return self::$parent;
    }

    /** Liste principale : seulement les descendants du contenu choisi. */
    public static function childrenQuery($query)
    {
        if (!is_admin() || !$query->is_main_query()) {
            return;
        }

        $parent = self::currentParent();

        if (!$parent) {
            return;
        }

        $ids = self::descendants($parent->ID, $parent->post_type);
        $query->set('post__in', $ids ? $ids : array(0));
    }

    /** Enfants, petits-enfants… (tous statuts sauf brouillons automatiques ; la liste filtre ensuite par statut). */
    private static function descendants($id, $type)
    {
        global $wpdb;

        $all = array();
        $level = array((int) $id);

        for ($depth = 0; $level && $depth < 30; $depth++) {
            $in = implode(',', array_map('intval', $level));
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $in : entiers.
            $found = $wpdb->get_col($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_status <> 'auto-draft' AND post_parent IN ($in)", $type));
            $level = array_values(array_diff(array_map('intval', $found), $all, array((int) $id)));
            $all = array_merge($all, $level);
        }

        return $all;
    }

    /**
     * Contenus qui ont au moins un enfant (hors corbeille), pour l'arbre de la fenêtre.
     *
     * @return array<int,array{id:int,parent:int,title:string,count:int,status:string}>
     */
    private static function parents($type)
    {
        global $wpdb;

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT post_parent AS id, COUNT(*) AS n FROM {$wpdb->posts} WHERE post_type = %s AND post_parent > 0 AND post_status NOT IN ('trash', 'auto-draft', 'inherit') GROUP BY post_parent",
            $type
        ));

        $counts = array();

        foreach ((array) $rows as $row) {
            $counts[(int) $row->id] = (int) $row->n;
        }

        if (!$counts) {
            return array();
        }

        _prime_post_caches(array_keys($counts), false, false);

        $statuses = get_post_statuses();
        $nodes = array();

        foreach ($counts as $id => $count) {
            $post = get_post($id);

            if (!$post instanceof \WP_Post || $post->post_type !== $type || in_array($post->post_status, array('trash', 'auto-draft'), true)) {
                continue;
            }

            $nodes[] = array(
                'id' => (int) $post->ID,
                'parent' => (int) $post->post_parent,
                'title' => self::title($post),
                'count' => $count,
                'status' => $post->post_status === 'publish' ? '' : (isset($statuses[$post->post_status]) ? $statuses[$post->post_status] : $post->post_status),
                'order' => (int) $post->menu_order,
            );
        }

        // Même ordre que la liste des pages : ordre du menu, puis titre.
        usort($nodes, function ($a, $b) {
            return $a['order'] === $b['order'] ? strnatcasecmp($a['title'], $b['title']) : $a['order'] - $b['order'];
        });

        return $nodes;
    }

    /** Bouton (ou pastille du filtre actif) dans la barre de filtres du haut, et la fenêtre. */
    public static function childrenControl($type, $which = 'top')
    {
        if ($which !== 'top') {
            return;
        }

        $parent = self::currentParent();
        self::childrenAsRoots($parent);

        $nodes = self::parents($type);
        $object = get_post_type_object($type);
        $plural = $object ? $object->labels->name : '';
        ?>
        <span class="smbb-children-filter">
            <?php if ($parent) : ?>
                <input type="hidden" name="<?php echo esc_attr(self::PARAM); ?>" value="<?php echo (int) $parent->ID; ?>">
                <span class="smbb-children-chip">
                    <button type="button" class="smbb-children-chip-label" data-smbb-children-open title="<?php esc_attr_e('Choisir un autre parent', 'smbb-wpcodetool'); ?>">
                        <span class="dashicons dashicons-networking" aria-hidden="true"></span>
                        <?php esc_html_e('Enfants de', 'smbb-wpcodetool'); ?> <strong><?php echo esc_html(self::title($parent)); ?></strong>
                    </button>
                    <a class="smbb-children-clear" href="<?php echo esc_url(remove_query_arg(array(self::PARAM, 'paged'))); ?>" title="<?php esc_attr_e('Retirer ce filtre', 'smbb-wpcodetool'); ?>"><span class="dashicons dashicons-no-alt" aria-hidden="true"></span><span class="screen-reader-text"><?php esc_html_e('Retirer ce filtre', 'smbb-wpcodetool'); ?></span></a>
                </span>
            <?php else : ?>
                <button type="button" class="button smbb-children-open" data-smbb-children-open <?php disabled(!$nodes); ?>
                    title="<?php echo esc_attr($nodes ? __('N\'afficher que les enfants et petits-enfants d\'un contenu', 'smbb-wpcodetool') : __('Aucun contenu n\'a d\'enfant pour l\'instant', 'smbb-wpcodetool')); ?>">
                    <span class="dashicons dashicons-networking" aria-hidden="true"></span>
                    <?php esc_html_e('Enfants de…', 'smbb-wpcodetool'); ?>
                </button>
            <?php endif; ?>
        </span>
        <dialog class="smbb-children-dialog" data-current="<?php echo $parent ? (int) $parent->ID : 0; ?>" aria-labelledby="smbb-children-title">
            <div class="smbb-children-head">
                <h2 id="smbb-children-title"><?php esc_html_e('Afficher les enfants de…', 'smbb-wpcodetool'); ?></h2>
                <button type="button" class="smbb-children-close" data-smbb-children-close><span class="dashicons dashicons-no-alt" aria-hidden="true"></span><span class="screen-reader-text"><?php esc_html_e('Fermer', 'smbb-wpcodetool'); ?></span></button>
            </div>
            <div class="smbb-children-searchbox">
                <span class="dashicons dashicons-search" aria-hidden="true"></span>
                <input type="search" class="smbb-children-search" autocomplete="off" placeholder="<?php esc_attr_e('Chercher un parent…', 'smbb-wpcodetool'); ?>" aria-label="<?php esc_attr_e('Chercher un parent', 'smbb-wpcodetool'); ?>">
            </div>
            <div class="smbb-children-tree"></div>
            <p class="smbb-children-empty" hidden><?php esc_html_e('Rien trouvé.', 'smbb-wpcodetool'); ?></p>
            <p class="smbb-children-foot">
                <?php
                /* translators: %s: nom du type de contenu au pluriel (Pages…) */
                echo esc_html(sprintf(__('%s : seuls ceux qui ont des enfants figurent ici. Après un clic, la liste n\'affiche plus que les enfants, petits-enfants, etc. du choix.', 'smbb-wpcodetool'), $plural !== '' ? $plural : __('Contenus', 'smbb-wpcodetool')));
                ?>
            </p>
            <script type="application/json" class="smbb-children-data"><?php echo wp_json_encode($nodes, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE); ?></script>
        </dialog>
        <?php
    }

    /**
     * Liste hiérarchique (pages triées par défaut) : WordPress n'affiche en arbre que ce qui part
     * d'un contenu de premier niveau ; les autres sortent « orphelins », à plat. Les enfants directs
     * du parent choisi deviennent donc la racine de l'arbre affiché (en mémoire seulement) pour que
     * les petits-enfants restent rangés dessous. Appelé dans la barre du haut, avant les lignes.
     */
    private static function childrenAsRoots($parent)
    {
        global $wp_query;

        if (!$parent || !$wp_query || empty($wp_query->posts)) {
            return;
        }

        foreach ($wp_query->posts as $row) {
            // Requête « id=>parent » (objets simples) : seulement l'affichage hiérarchique.
            if (is_object($row) && !$row instanceof \WP_Post && isset($row->post_parent) && (int) $row->post_parent === (int) $parent->ID) {
                $row->post_parent = 0;
            }
        }
    }

    public static function childrenAssets($hook)
    {
        if ($hook !== 'edit.php') {
            return;
        }

        wp_enqueue_style('smbb-wpcodetool-post-tools', SMBB_WPCODETOOL_URL . 'assets/post-tools.css', array('dashicons'), self::version('post-tools.css'));
        wp_enqueue_script('smbb-wpcodetool-post-tools', SMBB_WPCODETOOL_URL . 'assets/post-tools.js', array(), self::version('post-tools.js'), true);
        wp_localize_script('smbb-wpcodetool-post-tools', 'smbbPostTools', array(
            'param' => self::PARAM,
            'i18n' => array(
                'expand' => __('Déplier', 'smbb-wpcodetool'),
                'collapse' => __('Replier', 'smbb-wpcodetool'),
                'child' => __('%d enfant direct', 'smbb-wpcodetool'),
                'children' => __('%d enfants directs', 'smbb-wpcodetool'),
            ),
        ));
    }

    /* ---------------------------------------------------------------- Outils */

    private static function title($post)
    {
        $title = trim(wp_strip_all_tags(html_entity_decode(get_the_title($post), ENT_QUOTES, 'UTF-8')));

        return $title !== '' ? $title : __('(sans titre)', 'smbb-wpcodetool');
    }

    private static function version($file)
    {
        $path = SMBB_WPCODETOOL_PATH . 'assets/' . $file;

        return is_file($path) ? (string) filemtime($path) : null;
    }
}
