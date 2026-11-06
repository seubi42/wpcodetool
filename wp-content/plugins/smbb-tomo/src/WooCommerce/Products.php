<?php

namespace Smbb\Tomo\WooCommerce;

use Smbb\Tomo\Admin\View;
use Smbb\Tomo\Repository\Creneaux;
use Smbb\Tomo\Repository\Lookup;

defined('ABSPATH') || exit;

/**
 * Lien atelier ↔ produit WooCommerce.
 *
 * Tomo pilote le prix, les dates et les places ; la fiche produit WooCommerce ne garde que ce qui
 * sert à la vitrine et au référencement : titre, textes, images, catégories, extension SEO.
 */
final class Products
{
    const META = '_tomo_atelier_id';

    public static function hooks()
    {
        add_action('add_meta_boxes_product', array(__CLASS__, 'metaBox'));
        add_action('admin_head-post.php', array(__CLASS__, 'hideProductData'));
        add_action('woocommerce_process_product_meta', array(__CLASS__, 'afterProductSave'), 99);
    }

    /**
     * Atelier lié à un produit, ou null.
     */
    public static function atelierForProduct($product_id)
    {
        $product_id = (int) $product_id;

        if ($product_id < 1) {
            return null;
        }

        foreach (Lookup::ateliers() as $atelier) {
            if ((int) $atelier['product_id'] === $product_id) {
                return $atelier;
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
    public static function linked(array $atelier)
    {
        if (empty($atelier['product_id']) || !function_exists('wc_get_product') || get_post_status((int) $atelier['product_id']) === 'trash') {
            return null;
        }

        $product = wc_get_product((int) $atelier['product_id']);

        return $product ?: null;
    }

    /**
     * Texte de présentation : celui du produit quand l'atelier en a un, sinon celui de l'atelier.
     *
     * @param bool $short description courte du produit d'abord (cartes, extraits)
     */
    public static function description(array $atelier, $short = false)
    {
        $product = self::linked($atelier);

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

        return isset($atelier['description']) ? (string) $atelier['description'] : '';
    }

    /**
     * Image : celle du produit quand l'atelier en a un, sinon celle de l'atelier.
     */
    public static function imageId(array $atelier)
    {
        $product = self::linked($atelier);

        if ($product && (int) $product->get_image_id()) {
            return (int) $product->get_image_id();
        }

        return !empty($atelier['image_id']) ? (int) $atelier['image_id'] : 0;
    }

    public static function options($atelier_id = 0)
    {
        $options = array(__('— Aucun —', 'smbb-tomo') => '');
        $taken = array();

        foreach (Lookup::ateliers() as $atelier) {
            if ((int) $atelier['id'] !== (int) $atelier_id && !empty($atelier['product_id'])) {
                $taken[(int) $atelier['product_id']] = true;
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
    public static function create(array $atelier)
    {
        global $wpdb;

        if (!empty($atelier['product_id']) && get_post((int) $atelier['product_id'])) {
            return array('success' => false, 'message' => __('Cet atelier a déjà un produit.', 'smbb-tomo'));
        }

        $product = new \WC_Product_Simple();
        $product->set_name((string) $atelier['name']);
        $product->set_status('draft');
        $product->set_description((string) $atelier['description']);

        if (!empty($atelier['image_id'])) {
            $product->set_image_id((int) $atelier['image_id']);
        }

        self::applyRules($product, $atelier);
        $product_id = (int) $product->save();

        if ($product_id < 1) {
            return array('success' => false, 'message' => __("Le produit n'a pas pu être créé.", 'smbb-tomo'));
        }

        $wpdb->update(Lookup::table('tomo_atelier'), array('product_id' => $product_id), array('id' => (int) $atelier['id']));
        Lookup::flush();

        return array(
            'success' => true,
            'product_id' => $product_id,
            'message' => __('Produit créé en brouillon : complétez sa fiche (textes, images, référencement) puis publiez-le.', 'smbb-tomo'),
        );
    }

    /**
     * Après l'enregistrement d'un atelier : méta de lien et prix du produit.
     */
    public static function syncAtelier(array $atelier, array $previous = array())
    {
        $old = !empty($previous['product_id']) ? (int) $previous['product_id'] : 0;
        $new = !empty($atelier['product_id']) ? (int) $atelier['product_id'] : 0;

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

        self::applyRules($product, $atelier);
        $product->save();
    }

    /**
     * Produit virtuel, sans stock WooCommerce, au prix par défaut de l'atelier.
     * Le prix réel vient du créneau choisi, au panier.
     *
     * @param \WC_Product $product
     */
    private static function applyRules($product, array $atelier)
    {
        $product->set_virtual(true);
        $product->set_downloadable(false);
        $product->set_manage_stock(false);
        $product->set_stock_status('instock');
        $product->set_sold_individually(false);
        $product->set_regular_price($atelier['default_price'] !== null && $atelier['default_price'] !== '' ? wc_format_decimal($atelier['default_price']) : '0');
        $product->set_sale_price('');
        $product->update_meta_data(self::META, (int) $atelier['id']);
    }

    /**
     * Le formulaire WooCommerce poste les champs masqués : on remet nos règles après lui.
     */
    public static function afterProductSave($product_id)
    {
        $atelier = self::atelierForProduct($product_id);

        if ($atelier) {
            self::syncAtelier($atelier);
        }
    }

    // ------------------------------------------------------------------
    // Écrans Tomo
    // ------------------------------------------------------------------

    /**
     * Cellule « Boutique » de la liste des ateliers : état du produit, ou bouton de création.
     */
    public static function listCell(array $atelier)
    {
        $d = 'smbb-tomo';
        $post = !empty($atelier['product_id']) ? get_post((int) $atelier['product_id']) : null;

        if (!$post || $post->post_type !== 'product' || $post->post_status === 'trash') {
            return '<a class="button button-small" href="' . esc_url(View::actionUrl(\Smbb\Tomo\Admin\Actions::PRODUCT_CREATE, array('id' => (int) $atelier['id']))) . '">'
                . '<span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span>' . esc_html__('Créer le produit', $d) . '</a>';
        }

        return self::statusBadge($post) . ' <a href="' . esc_url((string) get_edit_post_link($post->ID)) . '">' . esc_html__('Fiche', $d) . '</a>'
            . ($post->post_status === 'publish' ? ' · <a href="' . esc_url((string) get_permalink($post->ID)) . '" target="_blank" rel="noopener">' . esc_html__('Voir', $d) . '</a>' : '');
    }

    /**
     * Carte « Boutique » au-dessus du formulaire d'un atelier.
     */
    public static function formCard(array $atelier)
    {
        $d = 'smbb-tomo';

        if (empty($atelier['id'])) {
            return View::note(__('Boutique', $d), esc_html__("Enregistrez d'abord l'atelier : vous pourrez ensuite créer son produit WooCommerce ou le lier à un produit existant.", $d), 'dashicons-cart');
        }

        $post = !empty($atelier['product_id']) ? get_post((int) $atelier['product_id']) : null;

        if (!$post || $post->post_type !== 'product' || $post->post_status === 'trash') {
            $html = '<p>' . esc_html__("Cet atelier n'est pas encore en vente en ligne. Créez son produit (en brouillon), ou choisissez un produit existant dans la section Boutique plus bas.", $d) . '</p>'
                . '<p><a class="button button-primary" href="' . esc_url(View::actionUrl(\Smbb\Tomo\Admin\Actions::PRODUCT_CREATE, array('id' => (int) $atelier['id']))) . '">'
                . '<span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span>' . esc_html__('Créer le produit', $d) . '</a></p>';

            return View::card(__('Boutique', $d), $html, 'tomo-shop-card');
        }

        $html = '<p>' . self::statusBadge($post) . ' <strong>' . esc_html($post->post_title) . '</strong></p>'
            . '<p class="description">' . esc_html__('Le prix, les dates et les places viennent de Tomo. Sur la fiche produit, ne restent que les textes, les images, les catégories et le référencement.', $d) . '</p>'
            . '<p><a class="button" href="' . esc_url((string) get_edit_post_link($post->ID)) . '"><span class="dashicons dashicons-edit" aria-hidden="true"></span>' . esc_html__('Compléter la fiche produit', $d) . '</a> ';

        if ($post->post_status === 'publish') {
            $html .= '<a class="button" href="' . esc_url((string) get_permalink($post->ID)) . '" target="_blank" rel="noopener"><span class="dashicons dashicons-external" aria-hidden="true"></span>' . esc_html__('Voir en boutique', $d) . '</a>';
        }

        return View::card(__('Boutique', $d), $html . '</p>', 'tomo-shop-card');
    }

    private static function statusBadge($post)
    {
        $published = $post->post_status === 'publish';
        $label = $published ? __('En ligne', 'smbb-tomo') : ucfirst(self::statusLabel($post->post_status));

        return '<span class="tomo-badge tomo-badge--' . ($published ? 'confirmed' : 'pending') . '">' . esc_html($label) . '</span>';
    }

    /**
     * Crée le produit depuis l'écran Tomo (lien « Créer le produit »).
     */
    public static function createFromAdmin($atelier_id)
    {
        $atelier = Lookup::atelier($atelier_id);

        if (!$atelier) {
            return array('success' => false, 'message' => __('Atelier introuvable.', 'smbb-tomo'));
        }

        return self::create($atelier);
    }

    // ------------------------------------------------------------------
    // Écran produit
    // ------------------------------------------------------------------

    public static function metaBox($post)
    {
        $atelier = self::atelierForProduct($post->ID);

        add_meta_box('tomo-atelier', __('Atelier Tomo', 'smbb-tomo'), array(__CLASS__, 'renderMetaBox'), 'product', $atelier ? 'normal' : 'side', 'high', array('atelier' => $atelier));
    }

    public static function renderMetaBox($post, $box)
    {
        $d = 'smbb-tomo';
        $atelier = isset($box['args']['atelier']) ? $box['args']['atelier'] : null;

        if (!$atelier) {
            echo '<p>' . esc_html__("Ce produit n'est lié à aucun atelier. Pour vendre des places, liez-le depuis la fiche de l'atelier.", $d) . '</p>';
            echo '<p><a class="button" href="' . esc_url(View::url('tomo_atelier')) . '">' . esc_html__('Voir les ateliers', $d) . '</a></p>';

            return;
        }

        $creneaux = Creneaux::upcoming(0, array('atelier_id' => (int) $atelier['id'], 'statuses' => array('open', 'full'), 'with_passes' => true));
        $free = 0;

        foreach ($creneaux as $creneau) {
            $free += $creneau['status'] === 'open' ? (int) $creneau['remaining'] : 0;
        }

        echo '<p><strong>' . esc_html(sprintf(__('Ce produit vend les places de « %s ».', $d), $atelier['name'])) . '</strong></p>';
        echo '<p>' . esc_html__("Le prix, les dates et les places se gèrent dans Tomo. Ici, ne reste que la vitrine : titre, textes, images, catégories et référencement.", $d) . '</p>';
        echo '<ul class="ul-disc">'
            . '<li>' . esc_html(sprintf(__('Prix par défaut : %s', $d), $atelier['default_price'] !== null && $atelier['default_price'] !== '' ? number_format_i18n((float) $atelier['default_price'], 2) . ' €' : '—')) . '</li>'
            . '<li>' . esc_html(sprintf(_n('%d date à venir', '%d dates à venir', count($creneaux), $d), count($creneaux))) . ', ' . esc_html(sprintf(_n('%d place en vente', '%d places en vente', $free, $d), $free)) . '</li>'
            . '</ul>';
        echo '<p><a class="button button-primary" href="' . esc_url(View::url('tomo_atelier', array('view' => 'form', 'id' => (int) $atelier['id']))) . '">' . esc_html__("Ouvrir l'atelier", $d) . '</a> '
            . '<a class="button" href="' . esc_url(View::filtered('tomo_creneau', 'atelier_id', (int) $atelier['id'])) . '">' . esc_html__('Gérer les dates', $d) . '</a></p>';
    }

    /**
     * Masque le bloc « Données produit » (prix, stock, expédition…) des produits liés.
     * Le bloc reste dans la page pour que WooCommerce enregistre normalement.
     */
    public static function hideProductData()
    {
        global $post;

        if (!$post || $post->post_type !== 'product' || !self::atelierForProduct($post->ID)) {
            return;
        }

        echo '<style>#woocommerce-product-data{display:none!important}#tomo-atelier .inside{font-size:13px}</style>';
    }

    private static function statusLabel($status)
    {
        $labels = array(
            'draft' => __('brouillon', 'smbb-tomo'),
            'pending' => __('en attente', 'smbb-tomo'),
            'private' => __('privé', 'smbb-tomo'),
        );

        return isset($labels[$status]) ? $labels[$status] : $status;
    }
}
