<?php

namespace Smbb\Passflow\WooCommerce;

use Smbb\Passflow\Admin\View;
use Smbb\Passflow\Repository\Slots;
use Smbb\Passflow\Repository\Lookup;

defined('ABSPATH') || exit;

/**
 * Lien atelier ↔ produit WooCommerce.
 *
 * Passflow pilote le prix, les dates et les places ; la fiche produit WooCommerce ne garde que ce qui
 * sert à la vitrine et au référencement : titre, textes, images, catégories, extension SEO.
 */
final class Products
{
    const META = '_passflow_activity_id';

    public static function hooks()
    {
        add_action('add_meta_boxes_product', array(__CLASS__, 'metaBox'));
        add_action('admin_head-post.php', array(__CLASS__, 'hideProductData'));
        add_action('woocommerce_process_product_meta', array(__CLASS__, 'afterProductSave'), 99);
    }

    /**
     * Atelier lié à un produit, ou null.
     */
    public static function activityForProduct($product_id)
    {
        $product_id = (int) $product_id;

        if ($product_id < 1) {
            return null;
        }

        foreach (Lookup::ateliers() as $activity) {
            if ((int) $activity['product_id'] === $product_id) {
                return $activity;
            }
        }

        return null;
    }

    /**
     * Options du select « Produit » d'un atelier : produits non liés à un autre atelier.
     *
     * @return array<string,string>
     */
    /**
     * Produit WooCommerce lié à l'atelier (même en brouillon), ou null.
     *
     * @return \WC_Product|null
     */
    public static function linked(array $activity)
    {
        if (empty($activity['product_id']) || !function_exists('wc_get_product') || get_post_status((int) $activity['product_id']) === 'trash') {
            return null;
        }

        $product = wc_get_product((int) $activity['product_id']);

        return $product ?: null;
    }

    /**
     * Texte de présentation : celui du produit quand l'atelier en a un, sinon celui de l'atelier.
     *
     * @param bool $short description courte du produit d'abord (cartes, extraits)
     */
    public static function description(array $activity, $short = false)
    {
        $product = self::linked($activity);

        if ($product) {
            $texts = $short
                ? array($product->get_short_description(), $product->get_description())
                : array($product->get_description(), $product->get_short_description());

            foreach ($texts as $text) {
                if (trim(wp_strip_all_tags((string) $text)) !== '') {
                    return (string) $text;
                }
            }
        }

        return isset($activity['description']) ? (string) $activity['description'] : '';
    }

    /**
     * Image : celle du produit quand l'atelier en a un, sinon celle de l'atelier.
     */
    public static function imageId(array $activity)
    {
        $product = self::linked($activity);

        if ($product && (int) $product->get_image_id()) {
            return (int) $product->get_image_id();
        }

        return !empty($activity['image_id']) ? (int) $activity['image_id'] : 0;
    }

    public static function options($activity_id = 0)
    {
        $options = array(__('— Aucun —', 'smbb-passflow') => '');
        $taken = array();

        foreach (Lookup::ateliers() as $activity) {
            if ((int) $activity['id'] !== (int) $activity_id && !empty($activity['product_id'])) {
                $taken[(int) $activity['product_id']] = true;
            }
        }

        $posts = get_posts(array(
            'post_type' => 'product',
            'post_status' => array('publish', 'draft', 'pending', 'private'),
            'numberposts' => 300,
            'orderby' => 'title',
            'order' => 'ASC',
        ));

        foreach ($posts as $post) {
            if (isset($taken[(int) $post->ID])) {
                continue;
            }

            $status = $post->post_status === 'publish' ? '' : ' (' . self::statusLabel($post->post_status) . ')';
            $options[$post->post_title . $status . ' #' . $post->ID] = (string) $post->ID;
        }

        return $options;
    }

    /**
     * Crée le produit d'un atelier (brouillon, à compléter pour le référencement) et le lie.
     *
     * @return array{success:bool,message:string,product_id?:int}
     */
    public static function create(array $activity)
    {
        global $wpdb;

        if (!empty($activity['product_id']) && get_post((int) $activity['product_id'])) {
            return array('success' => false, 'message' => __('Cet atelier a déjà un produit.', 'smbb-passflow'));
        }

        $product = new \WC_Product_Simple();
        $product->set_name((string) $activity['name']);
        $product->set_status('draft');
        $product->set_description((string) $activity['description']);

        if (!empty($activity['image_id'])) {
            $product->set_image_id((int) $activity['image_id']);
        }

        self::applyRules($product, $activity);
        $product_id = (int) $product->save();

        if ($product_id < 1) {
            return array('success' => false, 'message' => __("Le produit n'a pas pu être créé.", 'smbb-passflow'));
        }

        $wpdb->update(Lookup::table('passflow_activity'), array('product_id' => $product_id), array('id' => (int) $activity['id']));
        Lookup::flush();

        return array(
            'success' => true,
            'product_id' => $product_id,
            'message' => __('Produit créé en brouillon : complétez sa fiche (textes, images, référencement) puis publiez-le.', 'smbb-passflow'),
        );
    }

    /**
     * Après l'enregistrement d'un atelier : méta de lien et prix du produit.
     */
    public static function syncActivity(array $activity, array $previous = array())
    {
        $old = !empty($previous['product_id']) ? (int) $previous['product_id'] : 0;
        $new = !empty($activity['product_id']) ? (int) $activity['product_id'] : 0;

        if ($old && $old !== $new) {
            delete_post_meta($old, self::META);
        }

        if (!$new || !function_exists('wc_get_product')) {
            return;
        }

        $product = wc_get_product($new);

        if (!$product) {
            return;
        }

        self::applyRules($product, $activity);
        $product->save();
    }

    /**
     * Produit virtuel, sans stock WooCommerce, au prix par défaut de l'atelier.
     * Le prix réel vient du créneau choisi, au panier.
     *
     * @param \WC_Product $product
     */
    private static function applyRules($product, array $activity)
    {
        $product->set_virtual(true);
        $product->set_downloadable(false);
        $product->set_manage_stock(false);
        $product->set_stock_status('instock');
        $product->set_sold_individually(false);
        $product->set_regular_price($activity['default_price'] !== null && $activity['default_price'] !== '' ? wc_format_decimal($activity['default_price']) : '0');
        $product->set_sale_price('');
        $product->update_meta_data(self::META, (int) $activity['id']);
    }

    /**
     * Le formulaire WooCommerce poste les champs masqués : on remet nos règles après lui.
     */
    public static function afterProductSave($product_id)
    {
        $activity = self::activityForProduct($product_id);

        if ($activity) {
            self::syncActivity($activity);
        }
    }

    // ------------------------------------------------------------------
    // Écrans Passflow
    // ------------------------------------------------------------------

    /**
     * Cellule « Boutique » de la liste des ateliers : état du produit, ou bouton de création.
     */
    public static function listCell(array $activity)
    {
        $d = 'smbb-passflow';
        $post = !empty($activity['product_id']) ? get_post((int) $activity['product_id']) : null;

        if (!$post || $post->post_type !== 'product' || $post->post_status === 'trash') {
            return '<a class="button button-small" href="' . esc_url(View::actionUrl(\Smbb\Passflow\Admin\Actions::PRODUCT_CREATE, array('id' => (int) $activity['id']))) . '">'
                . '<span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span>' . esc_html__('Créer le produit', $d) . '</a>';
        }

        return self::statusBadge($post) . ' <a href="' . esc_url((string) get_edit_post_link($post->ID)) . '">' . esc_html__('Fiche', $d) . '</a>'
            . ($post->post_status === 'publish' ? ' · <a href="' . esc_url((string) get_permalink($post->ID)) . '" target="_blank" rel="noopener">' . esc_html__('Voir', $d) . '</a>' : '');
    }

    /**
     * Carte « Boutique » au-dessus du formulaire d'un atelier.
     */
    public static function formCard(array $activity)
    {
        $d = 'smbb-passflow';

        if (empty($activity['id'])) {
            return View::note(__('Boutique', $d), esc_html__("Enregistrez d'abord l'atelier : vous pourrez ensuite créer son produit WooCommerce ou le lier à un produit existant.", $d), 'dashicons-cart');
        }

        $post = !empty($activity['product_id']) ? get_post((int) $activity['product_id']) : null;

        if (!$post || $post->post_type !== 'product' || $post->post_status === 'trash') {
            $html = '<p>' . esc_html__("Cet atelier n'est pas encore en vente en ligne. Créez son produit (en brouillon), ou choisissez un produit existant dans la section Boutique plus bas.", $d) . '</p>'
                . '<p><a class="button button-primary" href="' . esc_url(View::actionUrl(\Smbb\Passflow\Admin\Actions::PRODUCT_CREATE, array('id' => (int) $activity['id']))) . '">'
                . '<span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span>' . esc_html__('Créer le produit', $d) . '</a></p>';

            return View::card(__('Boutique', $d), $html, 'passflow-shop-card');
        }

        $html = '<p>' . self::statusBadge($post) . ' <strong>' . esc_html($post->post_title) . '</strong></p>'
            . '<p class="description">' . esc_html__('Le prix, les dates et les places viennent de Passflow. Sur la fiche produit, ne restent que les textes, les images, les catégories et le référencement.', $d) . '</p>'
            . '<p><a class="button" href="' . esc_url((string) get_edit_post_link($post->ID)) . '"><span class="dashicons dashicons-edit" aria-hidden="true"></span>' . esc_html__('Compléter la fiche produit', $d) . '</a> ';

        if ($post->post_status === 'publish') {
            $html .= '<a class="button" href="' . esc_url((string) get_permalink($post->ID)) . '" target="_blank" rel="noopener"><span class="dashicons dashicons-external" aria-hidden="true"></span>' . esc_html__('Voir en boutique', $d) . '</a>';
        }

        return View::card(__('Boutique', $d), $html . '</p>', 'passflow-shop-card');
    }

    private static function statusBadge($post)
    {
        $published = $post->post_status === 'publish';
        $label = $published ? __('En ligne', 'smbb-passflow') : ucfirst(self::statusLabel($post->post_status));

        return '<span class="passflow-badge passflow-badge--' . ($published ? 'confirmed' : 'pending') . '">' . esc_html($label) . '</span>';
    }

    /**
     * Crée le produit depuis l'écran Passflow (lien « Créer le produit »).
     */
    public static function createFromAdmin($activity_id)
    {
        $activity = Lookup::atelier($activity_id);

        if (!$activity) {
            return array('success' => false, 'message' => __('Atelier introuvable.', 'smbb-passflow'));
        }

        return self::create($activity);
    }

    // ------------------------------------------------------------------
    // Écran produit
    // ------------------------------------------------------------------

    public static function metaBox($post)
    {
        $activity = self::activityForProduct($post->ID);

        add_meta_box('passflow-activity', __('Atelier Passflow', 'smbb-passflow'), array(__CLASS__, 'renderMetaBox'), 'product', $activity ? 'normal' : 'side', 'high', array('atelier' => $activity));
    }

    public static function renderMetaBox($post, $box)
    {
        $d = 'smbb-passflow';
        $activity = isset($box['args']['atelier']) ? $box['args']['atelier'] : null;

        if (!$activity) {
            echo '<p>' . esc_html__("Ce produit n'est lié à aucun atelier. Pour vendre des places, liez-le depuis la fiche de l'atelier.", $d) . '</p>';
            echo '<p><a class="button" href="' . esc_url(View::url('passflow_activity')) . '">' . esc_html__('Voir les ateliers', $d) . '</a></p>';

            return;
        }

        $slots = Slots::upcoming(0, array('activity_id' => (int) $activity['id'], 'statuses' => array('open', 'full'), 'with_passes' => true));
        $free = 0;

        foreach ($slots as $slot) {
            $free += $slot['status'] === 'open' ? (int) $slot['remaining'] : 0;
        }

        echo '<p><strong>' . esc_html(sprintf(__('Ce produit vend les places de « %s ».', $d), $activity['name'])) . '</strong></p>';
        echo '<p>' . esc_html__("Le prix, les dates et les places se gèrent dans Passflow. Ici, ne reste que la vitrine : titre, textes, images, catégories et référencement.", $d) . '</p>';
        echo '<ul class="ul-disc">'
            . '<li>' . esc_html(sprintf(__('Prix par défaut : %s', $d), $activity['default_price'] !== null && $activity['default_price'] !== '' ? number_format_i18n((float) $activity['default_price'], 2) . ' €' : '—')) . '</li>'
            . '<li>' . esc_html(sprintf(_n('%d date à venir', '%d dates à venir', count($slots), $d), count($slots))) . ', ' . esc_html(sprintf(_n('%d place en vente', '%d places en vente', $free, $d), $free)) . '</li>'
            . '</ul>';
        echo '<p><a class="button button-primary" href="' . esc_url(View::url('passflow_activity', array('view' => 'form', 'id' => (int) $activity['id']))) . '">' . esc_html__("Ouvrir l'atelier", $d) . '</a> '
            . '<a class="button" href="' . esc_url(View::filtered('passflow_slot', 'activity_id', (int) $activity['id'])) . '">' . esc_html__('Gérer les dates', $d) . '</a></p>';
    }

    /**
     * Masque le bloc « Données produit » (prix, stock, expédition…) des produits liés.
     * Le bloc reste dans la page pour que WooCommerce enregistre normalement.
     */
    public static function hideProductData()
    {
        global $post;

        if (!$post || $post->post_type !== 'product' || !self::activityForProduct($post->ID)) {
            return;
        }

        echo '<style>#woocommerce-product-data{display:none!important}#passflow-activity .inside{font-size:13px}</style>';
    }

    private static function statusLabel($status)
    {
        $labels = array(
            'draft' => __('brouillon', 'smbb-passflow'),
            'pending' => __('en attente', 'smbb-passflow'),
            'private' => __('privé', 'smbb-passflow'),
        );

        return isset($labels[$status]) ? $labels[$status] : $status;
    }
}
