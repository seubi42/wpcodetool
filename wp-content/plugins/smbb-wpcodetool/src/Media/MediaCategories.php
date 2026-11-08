<?php

namespace Smbb\WpCodeTool\Media;

defined('ABSPATH') || exit;

/**
 * Catégories de médias (réglage CodeTool › Contenu WP › Médiathèque).
 *
 * Une taxonomie hiérarchique « smbb_media_cat » sur les pièces jointes : un média peut être
 * dans plusieurs catégories (ce ne sont pas des dossiers). Chaque catégorie a une couleur
 * (méta smbb_color) et une position parmi ses sœurs (méta smbb_order).
 *
 * - Médiathèque (upload.php, grille et liste) : panneau d'arborescence à gauche (bascule à droite
 *   possible, retenue par le navigateur), créer / renommer / colorer / supprimer / réordonner
 *   par glisser-déposer, filtre au clic, classement en glissant des médias sur une catégorie
 *   ou en masse avec « Classer… ».
 * - Vignettes (médiathèque et fenêtre « Ajouter un média ») : une pastille par catégorie, son nom au survol.
 * - Détails du média (fenêtre et écran d'édition) : champ « Catégories » à pastilles cliquables.
 * - Vue liste : colonne « Catégories » et liste déroulante de filtre.
 *
 * Rien n'est touché aux fichiers ni aux URL. Couper le réglage cache tout, les catégories restent en base.
 *
 * Droits (filtrables) : classer = upload_files + edit_post sur chaque média ;
 * gérer l'arborescence = manage_categories (filtre smbb_wpcodetool_media_cats_manage_cap).
 */
final class MediaCategories
{
    const TAXONOMY = 'smbb_media_cat';
    const OPTION = 'smbb_codetool_media_cats';
    const NONCE = 'smbb_media_cats';
    const META_COLOR = 'smbb_color';
    const META_ORDER = 'smbb_order';
    const MAX_DEPTH = 6;

    /**
     * Nom du champ des détails du média. Surtout PAS le nom de la taxonomie : pour un champ qui porte
     * le nom d'une taxonomie, WordPress (wp_ajax_save_attachment_compat) enregistre lui-même la valeur
     * comme des NOMS de termes, et « 20 » créait une catégorie « 20 » au lieu de cocher la n° 20.
     */
    const FIELD = 'smbb_mcat_ids';

    /** Couleurs proposées (la dernière case du sélecteur permet n'importe quelle couleur). */
    const PALETTE = array('#e5484d', '#f76b15', '#ffc53d', '#46a758', '#12a594', '#0090ff', '#3e63dd', '#8e4ec6', '#d6409f', '#8d8d8d');

    /** @var bool */
    private static $enqueued = false;

    /** @var array|null Catégories sans compteurs (champ des détails, appelé pour chaque média listé). */
    private static $light = null;

    /* ---------------------------------------------------------------- Réglage */

    public static function enabled()
    {
        return (int) get_option(self::OPTION, 1) === 1;
    }

    public static function setEnabled($on)
    {
        update_option(self::OPTION, $on ? 1 : 0, true);
    }

    public static function manageCap()
    {
        return (string) apply_filters('smbb_wpcodetool_media_cats_manage_cap', 'manage_categories');
    }

    public static function canManage()
    {
        return current_user_can(self::manageCap());
    }

    /* ---------------------------------------------------------------- Branchements */

    /**
     * Appelé sur init (priorité 0).
     */
    public static function hooks()
    {
        if (!self::enabled()) {
            return;
        }

        self::register();

        if (!is_admin()) {
            return;
        }

        add_action('admin_enqueue_scripts', array(__CLASS__, 'adminAssets'), 45);
        add_action('wp_enqueue_media', array(__CLASS__, 'assets'));

        // Filtres : grille et fenêtre des médias (AJAX), vue liste (requête principale).
        add_filter('ajax_query_attachments_args', array(__CLASS__, 'gridQuery'));
        add_action('pre_get_posts', array(__CLASS__, 'listQuery'));
        add_action('restrict_manage_posts', array(__CLASS__, 'listDropdown'), 20, 1);

        // Vue liste : colonne « Catégories ».
        add_filter('manage_media_columns', array(__CLASS__, 'columns'));
        add_action('manage_media_custom_column', array(__CLASS__, 'column'), 10, 2);

        // Pastilles des vignettes et champ des détails du média.
        add_filter('wp_prepare_attachment_for_js', array(__CLASS__, 'prepareJs'), 10, 2);
        add_filter('attachment_fields_to_edit', array(__CLASS__, 'fieldsToEdit'), 20, 2);
        add_filter('attachment_fields_to_save', array(__CLASS__, 'fieldsToSave'), 20, 2);

        foreach (array('tree', 'save', 'delete', 'order', 'assign') as $action) {
            add_action('wp_ajax_smbb_mcat_' . $action, array(__CLASS__, 'ajax' . ucfirst($action)));
        }
    }

    public static function register()
    {
        $manage = self::manageCap();

        register_taxonomy(self::TAXONOMY, 'attachment', array(
            'labels' => array(
                'name' => __('Catégories de médias', 'smbb-wpcodetool'),
                'singular_name' => __('Catégorie de médias', 'smbb-wpcodetool'),
            ),
            'public' => false,
            'publicly_queryable' => false,
            'hierarchical' => true,
            'show_ui' => false,          // notre panneau remplace l'écran des termes et le champ texte de WordPress
            'show_in_menu' => false,
            'show_in_nav_menus' => false,
            'show_in_rest' => false,
            'show_tagcloud' => false,
            'show_admin_column' => false,
            'query_var' => false,
            'rewrite' => false,
            // Les médias sont « inherit » : le comptage par défaut des articles les ignorerait.
            'update_count_callback' => '_update_generic_term_count',
            'capabilities' => array(
                'manage_terms' => $manage,
                'edit_terms' => $manage,
                'delete_terms' => $manage,
                'assign_terms' => 'upload_files',
            ),
        ));
    }

    /* ---------------------------------------------------------------- Données */

    /**
     * Arborescence à plat (le JS la remonte) + compteurs.
     * count = médias distincts de la catégorie ET de ses sous-catégories (comme le filtre).
     */
    public static function tree()
    {
        global $wpdb;

        self::$light = null;

        $terms = get_terms(array(
            'taxonomy' => self::TAXONOMY,
            'hide_empty' => false,
            'update_term_meta_cache' => true,
        ));
        $terms = is_array($terms) ? $terms : array();

        $children = array();
        $tt = array();

        foreach ($terms as $term) {
            $children[(int) $term->parent][] = (int) $term->term_id;
            $tt[(int) $term->term_id] = (int) $term->term_taxonomy_id;
        }

        $out = array();

        foreach ($terms as $term) {
            $id = (int) $term->term_id;
            $ids = array($tt[$id]);
            $stack = isset($children[$id]) ? $children[$id] : array();

            while ($stack) {
                $child = array_pop($stack);
                $ids[] = $tt[$child];

                if (isset($children[$child])) {
                    $stack = array_merge($stack, $children[$child]);
                }
            }

            $count = (int) $term->count;

            if (count($ids) > 1) {
                $in = implode(',', array_map('intval', $ids));
                $count = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                    "SELECT COUNT(DISTINCT tr.object_id) FROM {$wpdb->term_relationships} tr
                     INNER JOIN {$wpdb->posts} p ON p.ID = tr.object_id AND p.post_type = 'attachment' AND p.post_status <> 'trash'
                     WHERE tr.term_taxonomy_id IN ({$in})" // phpcs:ignore WordPress.DB.PreparedSQL -- entiers
                );
            }

            $out[] = array(
                'id' => $id,
                'parent' => (int) $term->parent,
                'name' => html_entity_decode($term->name, ENT_QUOTES, 'UTF-8'),
                'color' => self::color($id),
                'order' => (int) get_term_meta($id, self::META_ORDER, true),
                'count' => $count,
            );
        }

        $all = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_status <> 'trash'"
        );
        $none = (int) $wpdb->get_var($wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            "SELECT COUNT(*) FROM {$wpdb->posts} p
             WHERE p.post_type = 'attachment' AND p.post_status <> 'trash'
             AND NOT EXISTS (
                SELECT 1 FROM {$wpdb->term_relationships} tr
                INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
                WHERE tr.object_id = p.ID AND tt.taxonomy = %s
             )",
            self::TAXONOMY
        ));

        return array('terms' => $out, 'all' => $all, 'none' => $none);
    }

    /**
     * Catégories sans compteurs, gardées pour la requête : get_compat_media_markup() appelle
     * fieldsToEdit() pour chaque média renvoyé à la grille.
     */
    public static function lightTerms()
    {
        if (self::$light !== null) {
            return self::$light;
        }

        $terms = get_terms(array('taxonomy' => self::TAXONOMY, 'hide_empty' => false, 'update_term_meta_cache' => true));
        self::$light = array();

        foreach (is_array($terms) ? $terms : array() as $term) {
            self::$light[] = array(
                'id' => (int) $term->term_id,
                'parent' => (int) $term->parent,
                'name' => html_entity_decode($term->name, ENT_QUOTES, 'UTF-8'),
                'color' => self::color((int) $term->term_id),
                'order' => (int) get_term_meta((int) $term->term_id, self::META_ORDER, true),
            );
        }

        return self::$light;
    }

    public static function color($term_id)
    {
        $color = sanitize_hex_color((string) get_term_meta((int) $term_id, self::META_COLOR, true));

        return $color ? $color : self::PALETTE[((int) $term_id) % count(self::PALETTE)];
    }

    /** @return int[] */
    public static function termIds($attachment_id)
    {
        $terms = get_the_terms((int) $attachment_id, self::TAXONOMY);

        return is_array($terms) ? array_map('intval', wp_list_pluck($terms, 'term_id')) : array();
    }

    /* ---------------------------------------------------------------- Scripts */

    public static function adminAssets($hook = '')
    {
        global $pagenow;

        if ($pagenow === 'upload.php') {
            self::assets();
            return;
        }

        // Écran d'édition d'un média : le champ « Catégories » a besoin du script.
        if ($pagenow === 'post.php' && isset($_GET['post']) && get_post_type((int) $_GET['post']) === 'attachment') { // phpcs:ignore WordPress.Security.NonceVerification
            self::assets();
        }
    }

    public static function assets()
    {
        global $pagenow;

        if (self::$enqueued || !is_admin() || !current_user_can('upload_files')) {
            return;
        }

        self::$enqueued = true;
        $panel = $pagenow === 'upload.php';
        $mode = 'grid';

        if ($panel) {
            $mode = isset($_GET['mode']) ? sanitize_key(wp_unslash($_GET['mode'])) : (string) get_user_option('media_library_mode'); // phpcs:ignore WordPress.Security.NonceVerification
            $mode = $mode === 'list' ? 'list' : 'grid';
        }

        $current = isset($_GET['smbb_media_cat']) ? sanitize_key(wp_unslash($_GET['smbb_media_cat'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification

        wp_enqueue_style('smbb-wpcodetool-media-cats', SMBB_WPCODETOOL_URL . 'assets/media-cats.css', array(), self::version('media-cats.css'));
        wp_enqueue_script('smbb-wpcodetool-media-cats', SMBB_WPCODETOOL_URL . 'assets/media-cats.js', array('jquery'), self::version('media-cats.js'), true);
        wp_localize_script('smbb-wpcodetool-media-cats', 'smbbMediaCats', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce(self::NONCE),
            'panel' => $panel,
            'mode' => $mode,
            'current' => $current === 'none' || ctype_digit($current) ? $current : '',
            'canManage' => self::canManage(),
            'palette' => self::PALETTE,
            'tree' => self::tree(),
            'i18n' => array(
                'title' => __('Catégories', 'smbb-wpcodetool'),
                'all' => __('Tous les médias', 'smbb-wpcodetool'),
                'none' => __('Sans catégorie', 'smbb-wpcodetool'),
                'new' => __('Nouvelle catégorie', 'smbb-wpcodetool'),
                'newName' => __('Nouvelle catégorie', 'smbb-wpcodetool'),
                'child' => __('Ajouter une sous-catégorie', 'smbb-wpcodetool'),
                'rename' => __('Renommer', 'smbb-wpcodetool'),
                'color' => __('Couleur', 'smbb-wpcodetool'),
                'otherColor' => __('Autre couleur…', 'smbb-wpcodetool'),
                'delete' => __('Supprimer', 'smbb-wpcodetool'),
                'actions' => __('Actions', 'smbb-wpcodetool'),
                'side' => __('Mettre le panneau de l\'autre côté', 'smbb-wpcodetool'),
                'search' => __('Chercher une catégorie', 'smbb-wpcodetool'),
                'empty' => __('Aucune catégorie pour l\'instant. Créez-en une avec le bouton +.', 'smbb-wpcodetool'),
                'noMatch' => __('Aucune catégorie ne correspond.', 'smbb-wpcodetool'),
                'hint' => __('Pour ranger des médias : sélectionnez-les (« Sélection groupée » en grille, cases à cocher en liste) puis « Catégoriser ». Le menu « … » d\'une catégorie la déplace.', 'smbb-wpcodetool'),
                'categorize' => __('Catégoriser', 'smbb-wpcodetool'),
                /* translators: %d: nombre de médias */
                'categorizeN' => __('Catégoriser (%d)', 'smbb-wpcodetool'),
                'cancel' => __('Annuler', 'smbb-wpcodetool'),
                'apply' => __('Appliquer', 'smbb-wpcodetool'),
                /* translators: %d: nombre de médias */
                'done' => __('Catégories mises à jour pour %d média(s)', 'smbb-wpcodetool'),
                'up' => __('Monter', 'smbb-wpcodetool'),
                'down' => __('Descendre', 'smbb-wpcodetool'),
                'moveTo' => __('Déplacer dans…', 'smbb-wpcodetool'),
                'toRoot' => __('Remettre au premier niveau', 'smbb-wpcodetool'),
                /* translators: %s: nom de la catégorie */
                'confirmDelete' => __('Supprimer la catégorie « %s » ? Les médias restent dans la médiathèque, ils sont seulement retirés de cette catégorie. Ses sous-catégories remontent d\'un niveau.', 'smbb-wpcodetool'),
                'classify' => __('Classer…', 'smbb-wpcodetool'),
                /* translators: %d: nombre de médias */
                'selected' => __('%d sélectionné(s)', 'smbb-wpcodetool'),
                /* translators: %d: nombre de médias */
                'dragCount' => __('%d média(s)', 'smbb-wpcodetool'),
                /* translators: 1: nombre de médias, 2: nom de la catégorie */
                'added' => __('%1$d média(s) ajouté(s) à « %2$s »', 'smbb-wpcodetool'),
                /* translators: 1: nombre de médias, 2: nom de la catégorie */
                'removed' => __('%1$d média(s) retiré(s) de « %2$s »', 'smbb-wpcodetool'),
                /* translators: %d: nombre de médias */
                'cleared' => __('%d média(s) sortis de toutes leurs catégories', 'smbb-wpcodetool'),
                'error' => __('L\'opération n\'a pas abouti. Rechargez la page et réessayez.', 'smbb-wpcodetool'),
                'fieldEmpty' => __('Aucune catégorie : créez-en depuis la médiathèque.', 'smbb-wpcodetool'),
                'filterBy' => __('Afficher cette catégorie', 'smbb-wpcodetool'),
                'collapse' => __('Replier / déplier', 'smbb-wpcodetool'),
            ),
        ));
    }

    private static function version($file)
    {
        $path = SMBB_WPCODETOOL_PATH . 'assets/' . $file;

        return SMBB_WPCODETOOL_VERSION . (file_exists($path) ? '.' . filemtime($path) : '');
    }

    /* ---------------------------------------------------------------- Filtres */

    /** Valeur du filtre : '' (tout), 'none' (sans catégorie) ou l'ID d'une catégorie. */
    private static function filterValue($raw)
    {
        $raw = is_scalar($raw) ? sanitize_key((string) $raw) : '';

        if ($raw === 'none') {
            return 'none';
        }

        return ctype_digit($raw) && (int) $raw > 0 ? (int) $raw : '';
    }

    private static function taxQuery($value)
    {
        if ($value === 'none') {
            return array('taxonomy' => self::TAXONOMY, 'operator' => 'NOT EXISTS');
        }

        return array('taxonomy' => self::TAXONOMY, 'field' => 'term_id', 'terms' => array((int) $value), 'include_children' => true);
    }

    /** Grille et fenêtre des médias : WordPress ne laisse passer que ses clés, on lit la nôtre à part. */
    public static function gridQuery($query)
    {
        $raw = isset($_REQUEST['query']['smbb_media_cat']) ? wp_unslash($_REQUEST['query']['smbb_media_cat']) : ''; // phpcs:ignore WordPress.Security
        $value = self::filterValue($raw);

        if ($value !== '') {
            $query['tax_query'] = isset($query['tax_query']) && is_array($query['tax_query']) ? $query['tax_query'] : array();
            $query['tax_query'][] = self::taxQuery($value);
        }

        return $query;
    }

    /** Vue liste : ?smbb_media_cat=… sur upload.php. */
    public static function listQuery($query)
    {
        global $pagenow;

        if (!is_admin() || $pagenow !== 'upload.php' || !$query->is_main_query() || !isset($_GET['smbb_media_cat'])) { // phpcs:ignore WordPress.Security.NonceVerification
            return;
        }

        $value = self::filterValue(wp_unslash($_GET['smbb_media_cat'])); // phpcs:ignore WordPress.Security.NonceVerification

        if ($value === '') {
            return;
        }

        $tax = $query->get('tax_query');
        $tax = is_array($tax) ? $tax : array();
        $tax[] = self::taxQuery($value);
        $query->set('tax_query', $tax);
    }

    /** Vue liste : liste déroulante à côté de « Toutes les dates » (et garde le filtre au clic sur Filtrer). */
    public static function listDropdown($post_type)
    {
        global $pagenow;

        if ($post_type !== 'attachment' || $pagenow !== 'upload.php') {
            return;
        }

        $current = isset($_GET['smbb_media_cat']) ? self::filterValue(wp_unslash($_GET['smbb_media_cat'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification
        $terms = self::lightTerms();
        ?>
        <label for="smbb-media-cat-filter" class="screen-reader-text"><?php esc_html_e('Filtrer par catégorie', 'smbb-wpcodetool'); ?></label>
        <select name="smbb_media_cat" id="smbb-media-cat-filter" class="smbb-mcat-select">
            <option value=""><?php esc_html_e('Toutes les catégories', 'smbb-wpcodetool'); ?></option>
            <option value="none" <?php selected($current, 'none'); ?>><?php esc_html_e('Sans catégorie', 'smbb-wpcodetool'); ?></option>
            <?php foreach (self::flat($terms) as $row) : ?>
                <option value="<?php echo (int) $row['id']; ?>" <?php selected($current, (int) $row['id']); ?>><?php echo esc_html(str_repeat('— ', $row['depth']) . $row['name']); ?></option>
            <?php endforeach; ?>
        </select>
        <?php
    }

    /**
     * Arborescence aplatie dans l'ordre d'affichage, avec la profondeur.
     *
     * @return array<int,array>
     */
    public static function flat(array $terms)
    {
        $by_parent = array();

        foreach ($terms as $t) {
            $by_parent[(int) $t['parent']][] = $t;
        }

        foreach ($by_parent as &$list) {
            usort($list, function ($a, $b) {
                return $a['order'] === $b['order'] ? strnatcasecmp($a['name'], $b['name']) : $a['order'] - $b['order'];
            });
        }
        unset($list);

        $out = array();
        $walk = function ($parent, $depth) use (&$walk, &$out, $by_parent) {
            if (empty($by_parent[$parent]) || $depth > 20) {
                return;
            }

            foreach ($by_parent[$parent] as $t) {
                $t['depth'] = $depth;
                $out[] = $t;
                $walk((int) $t['id'], $depth + 1);
            }
        };
        $walk(0, 0);

        return $out;
    }

    /* ---------------------------------------------------------------- Vue liste : colonne */

    public static function columns($columns)
    {
        $out = array();

        foreach ($columns as $key => $label) {
            $out[$key] = $label;

            if ($key === 'title') {
                $out['smbb_media_cat'] = __('Catégories', 'smbb-wpcodetool');
            }
        }

        if (!isset($out['smbb_media_cat'])) {
            $out['smbb_media_cat'] = __('Catégories', 'smbb-wpcodetool');
        }

        return $out;
    }

    public static function column($column, $post_id)
    {
        if ($column !== 'smbb_media_cat') {
            return;
        }

        $ids = self::termIds($post_id);
        echo '<span class="smbb-mcat-cell" data-id="' . (int) $post_id . '" data-cats="' . esc_attr(implode(',', $ids)) . '">';

        foreach ($ids as $id) {
            $term = get_term($id, self::TAXONOMY);

            if ($term && !is_wp_error($term)) {
                echo '<span class="smbb-mcat-chip is-static" style="--smbb-mcat-c:' . esc_attr(self::color($id)) . '"><i class="smbb-mcat-dot" aria-hidden="true"></i>' . esc_html($term->name) . '</span>';
            }
        }

        echo '</span>';
    }

    /* ---------------------------------------------------------------- Vignettes et détails */

    public static function prepareJs($response, $attachment)
    {
        if (is_array($response) && $attachment instanceof \WP_Post) {
            $response['smbbCats'] = self::termIds($attachment->ID);
        }

        return $response;
    }

    public static function fieldsToEdit($fields, $post)
    {
        if (!$post instanceof \WP_Post || !current_user_can('upload_files')) {
            return $fields;
        }

        $terms = self::lightTerms();
        $mine = self::termIds($post->ID);
        $name = 'attachments[' . (int) $post->ID . '][' . self::FIELD . ']';
        $html = '<div class="smbb-mcat-field" data-id="' . (int) $post->ID . '">'
            . '<input type="hidden" class="smbb-mcat-field-input" name="' . esc_attr($name) . '" value="' . esc_attr(implode(',', $mine)) . '">';

        if (!$terms) {
            $html .= '<p class="description">' . esc_html__('Aucune catégorie : créez-en depuis la médiathèque.', 'smbb-wpcodetool') . '</p>';
        }

        foreach (self::flat($terms) as $row) {
            $on = in_array((int) $row['id'], $mine, true);
            $html .= '<button type="button" class="smbb-mcat-chip' . ($on ? ' is-on' : '') . '" data-cat="' . (int) $row['id'] . '" aria-pressed="' . ($on ? 'true' : 'false') . '"'
                . ' style="--smbb-mcat-c:' . esc_attr($row['color']) . '">'
                . ($row['depth'] ? '<span class="smbb-mcat-chip-depth" aria-hidden="true">' . esc_html(str_repeat('›', $row['depth'])) . '</span>' : '')
                . '<i class="smbb-mcat-dot" aria-hidden="true"></i>' . esc_html($row['name']) . '</button>';
        }

        $html .= '</div>';

        $fields[self::FIELD] = array(
            'label' => __('Catégories', 'smbb-wpcodetool'),
            'input' => 'html',
            'html' => $html,
            'show_in_edit' => true,
            'show_in_modal' => true,
        );

        return $fields;
    }

    public static function fieldsToSave($post, $attachment)
    {
        if (!isset($attachment[self::FIELD]) || empty($post['ID']) || !current_user_can('edit_post', (int) $post['ID'])) {
            return $post;
        }

        $ids = self::ids($attachment[self::FIELD]);
        $valid = array();

        foreach ($ids as $id) {
            if (term_exists($id, self::TAXONOMY)) {
                $valid[] = $id;
            }
        }

        wp_set_object_terms((int) $post['ID'], $valid, self::TAXONOMY, false);

        return $post;
    }

    /* ---------------------------------------------------------------- AJAX */

    private static function check($manage)
    {
        if (!check_ajax_referer(self::NONCE, 'nonce', false)) {
            wp_send_json_error(array('message' => __('Session expirée : rechargez la page.', 'smbb-wpcodetool')), 403);
        }

        if (!current_user_can('upload_files') || ($manage && !self::canManage())) {
            wp_send_json_error(array('message' => __('Vous n\'avez pas le droit de faire ça.', 'smbb-wpcodetool')), 403);
        }
    }

    /** @return int[] */
    private static function ids($raw)
    {
        $raw = is_array($raw) ? implode(',', $raw) : (string) $raw;

        return array_values(array_unique(array_filter(array_map('intval', explode(',', $raw)))));
    }

    private static function post($key, $default = '')
    {
        return isset($_POST[$key]) ? wp_unslash($_POST[$key]) : $default; // phpcs:ignore WordPress.Security
    }

    public static function ajaxTree()
    {
        self::check(false);
        wp_send_json_success(array('tree' => self::tree()));
    }

    /** Créer (id = 0) ou modifier : nom, parent, couleur. */
    public static function ajaxSave()
    {
        self::check(true);

        $id = (int) self::post('id', 0);
        $name = sanitize_text_field((string) self::post('name'));
        $color = sanitize_hex_color((string) self::post('color'));
        $has_parent = isset($_POST['parent']); // phpcs:ignore WordPress.Security.NonceVerification
        $parent = (int) self::post('parent', 0);

        if ($parent && (!term_exists($parent, self::TAXONOMY) || ($id && self::isDescendant($parent, $id)))) {
            wp_send_json_error(array('message' => __('Catégorie parente invalide.', 'smbb-wpcodetool')));
        }

        if ($id === 0) {
            if ($name === '') {
                wp_send_json_error(array('message' => __('Donnez un nom à la catégorie.', 'smbb-wpcodetool')));
            }

            if ($parent && self::depth($parent) >= self::MAX_DEPTH - 1) {
                wp_send_json_error(array('message' => __('Arborescence trop profonde.', 'smbb-wpcodetool')));
            }

            $result = wp_insert_term($name, self::TAXONOMY, array('parent' => $parent));

            if (is_wp_error($result)) {
                wp_send_json_error(array('message' => $result->get_error_code() === 'term_exists'
                    ? __('Une catégorie porte déjà ce nom à cet endroit.', 'smbb-wpcodetool')
                    : $result->get_error_message()));
            }

            $id = (int) $result['term_id'];
            update_term_meta($id, self::META_COLOR, $color ? $color : self::PALETTE[$id % count(self::PALETTE)]);
            update_term_meta($id, self::META_ORDER, self::nextOrder($parent, $id));
            self::log('content.media_category_created', __('Catégorie de médias créée', 'smbb-wpcodetool'), $id, $name);
        } else {
            $term = get_term($id, self::TAXONOMY);

            if (!$term || is_wp_error($term)) {
                wp_send_json_error(array('message' => __('Catégorie introuvable.', 'smbb-wpcodetool')));
            }

            $args = array();

            if ($name !== '' && $name !== $term->name) {
                $args['name'] = $name;
            }

            if ($has_parent && $parent !== (int) $term->parent) {
                $args['parent'] = $parent;
            }

            if ($args) {
                $result = wp_update_term($id, self::TAXONOMY, $args);

                if (is_wp_error($result)) {
                    wp_send_json_error(array('message' => $result->get_error_code() === 'duplicate_term_slug' || $result->get_error_code() === 'term_exists'
                        ? __('Une catégorie porte déjà ce nom à cet endroit.', 'smbb-wpcodetool')
                        : $result->get_error_message()));
                }

                if (isset($args['name'])) {
                    self::log('content.media_category_renamed', __('Catégorie de médias renommée', 'smbb-wpcodetool'), $id, $name, array('name' => array($term->name, $name)));
                }
            }

            if ($color) {
                update_term_meta($id, self::META_COLOR, $color);
            }
        }

        wp_send_json_success(array('id' => $id, 'tree' => self::tree()));
    }

    public static function ajaxDelete()
    {
        self::check(true);

        $id = (int) self::post('id', 0);
        $term = get_term($id, self::TAXONOMY);

        if (!$term || is_wp_error($term)) {
            wp_send_json_error(array('message' => __('Catégorie introuvable.', 'smbb-wpcodetool')));
        }

        // WordPress fait remonter les sous-catégories d'un niveau ; les médias restent.
        $result = wp_delete_term($id, self::TAXONOMY);

        if (is_wp_error($result) || !$result) {
            wp_send_json_error(array('message' => __('Suppression impossible.', 'smbb-wpcodetool')));
        }

        self::log('content.media_category_deleted', __('Catégorie de médias supprimée', 'smbb-wpcodetool'), $id, $term->name, array(), 'notice');

        wp_send_json_success(array('tree' => self::tree()));
    }

    /**
     * Déplacement / tri : items = JSON [{id, parent, order}, …] (les sœurs du niveau touché).
     */
    public static function ajaxOrder()
    {
        self::check(true);

        $items = json_decode((string) self::post('items', '[]'), true);

        if (!is_array($items)) {
            wp_send_json_error(array('message' => __('Données invalides.', 'smbb-wpcodetool')));
        }

        foreach (array_slice($items, 0, 500) as $item) {
            $id = isset($item['id']) ? (int) $item['id'] : 0;
            $parent = isset($item['parent']) ? (int) $item['parent'] : 0;
            $term = $id ? get_term($id, self::TAXONOMY) : null;

            if (!$term || is_wp_error($term)) {
                continue;
            }

            if ($parent !== (int) $term->parent) {
                if ($parent && (!term_exists($parent, self::TAXONOMY) || $parent === $id || self::isDescendant($parent, $id))) {
                    wp_send_json_error(array('message' => __('Une catégorie ne peut pas aller dans une de ses sous-catégories.', 'smbb-wpcodetool')));
                }

                $result = wp_update_term($id, self::TAXONOMY, array('parent' => $parent));

                if (is_wp_error($result)) {
                    wp_send_json_error(array('message' => __('Une catégorie porte déjà ce nom à cet endroit.', 'smbb-wpcodetool')));
                }
            }

            update_term_meta($id, self::META_ORDER, isset($item['order']) ? (int) $item['order'] : 0);
        }

        wp_send_json_success(array('tree' => self::tree()));
    }

    /**
     * Classer des médias : ids = « 12,13 », term = catégorie, mode = add | remove | clear,
     * ou mode = batch avec add = « 3,5 » et remove = « 7 » (fenêtre « Catégoriser »).
     */
    public static function ajaxAssign()
    {
        self::check(false);

        $ids = array_slice(self::ids(self::post('ids')), 0, 1000);
        $term = (int) self::post('term', 0);
        $mode = sanitize_key((string) self::post('mode', 'add'));

        $add = array();
        $remove = array();

        if ($mode === 'batch') {
            foreach (self::ids(self::post('add')) as $tid) {
                if (term_exists($tid, self::TAXONOMY)) {
                    $add[] = $tid;
                }
            }

            foreach (self::ids(self::post('remove')) as $tid) {
                if (term_exists($tid, self::TAXONOMY)) {
                    $remove[] = $tid;
                }
            }
        } elseif ($mode !== 'clear' && (!$term || !term_exists($term, self::TAXONOMY))) {
            wp_send_json_error(array('message' => __('Catégorie introuvable.', 'smbb-wpcodetool')));
        }

        $items = array();

        foreach ($ids as $id) {
            if (get_post_type($id) !== 'attachment' || !current_user_can('edit_post', $id)) {
                continue;
            }

            if ($mode === 'batch') {
                // Toujours des ID entiers : une chaîne serait prise pour un NOM de catégorie.
                if ($add) {
                    wp_set_object_terms($id, array_map('intval', $add), self::TAXONOMY, true);
                }

                if ($remove) {
                    wp_remove_object_terms($id, array_map('intval', $remove), self::TAXONOMY);
                }
            } elseif ($mode === 'remove') {
                wp_remove_object_terms($id, $term, self::TAXONOMY);
            } elseif ($mode === 'clear') {
                wp_set_object_terms($id, array(), self::TAXONOMY, false);
            } else {
                wp_set_object_terms($id, array($term), self::TAXONOMY, true);
            }

            clean_object_term_cache($id, 'attachment');
            $items[$id] = self::termIds($id);
        }

        wp_send_json_success(array('items' => (object) $items, 'count' => count($items), 'tree' => self::tree()));
    }

    /* ---------------------------------------------------------------- Outils */

    /** $maybe est-elle sous $ancestor (à n'importe quelle profondeur) ? */
    private static function isDescendant($maybe, $ancestor)
    {
        $ancestors = get_ancestors((int) $maybe, self::TAXONOMY, 'taxonomy');

        return (int) $maybe === (int) $ancestor || in_array((int) $ancestor, array_map('intval', $ancestors), true);
    }

    private static function depth($term_id)
    {
        return count(get_ancestors((int) $term_id, self::TAXONOMY, 'taxonomy'));
    }

    private static function nextOrder($parent, $except)
    {
        $siblings = get_terms(array('taxonomy' => self::TAXONOMY, 'hide_empty' => false, 'parent' => (int) $parent, 'fields' => 'ids', 'exclude' => array((int) $except)));
        $max = 0;

        foreach (is_array($siblings) ? $siblings : array() as $sid) {
            $max = max($max, (int) get_term_meta((int) $sid, self::META_ORDER, true));
        }

        return $max + 1;
    }

    private static function log($event, $message, $id, $label, array $changes = array(), $level = 'info')
    {
        do_action('smbb_wpcodetool_activity', $event, array(
            'category' => 'content',
            'level' => $level,
            'message' => $message,
            'object_type' => self::TAXONOMY,
            'object_id' => (int) $id,
            'object_label' => (string) $label,
            'context' => $changes ? array('changes' => $changes) : array(),
            'source' => 'smbb-wpcodetool',
        ));
    }
}
