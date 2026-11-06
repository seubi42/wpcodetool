<?php

namespace Smbb\WpCodeTool\Support;

defined('ABSPATH') || exit;

/**
 * Recherche de produits WooCommerce pour le champ relationnel search() :
 *
 *     $form->search('Produit', array('resource' => 'wc_product'))->setName('product_id')
 *     $form->search('Produits', array('resource' => 'wc_product', 'multiple' => true, 'productTypes' => array('simple')))->setName('product_ids')
 *
 * On cherche dans le nom, la description, la référence (SKU) et l'ID. Chaque résultat porte sa
 * vignette, sa référence et son prix. Droit requis : edit_products, ou le filtre
 * smbb_wpcodetool_product_lookup_allowed.
 *
 * Filtres :
 * - smbb_wpcodetool_product_lookup_allowed (bool $allowed) ;
 * - smbb_wpcodetool_product_lookup_item (array $item, \WC_Product $product) : value, label, meta, image.
 */
final class ProductLookup
{
    const RESOURCE = 'wc_product';

    public static function available()
    {
        return function_exists('wc_get_product') && class_exists('\WC_Data_Store');
    }

    public static function allowed()
    {
        return (bool) apply_filters('smbb_wpcodetool_product_lookup_allowed', current_user_can('edit_products') || current_user_can('manage_woocommerce'));
    }

    /**
     * @param string[] $types types de produit acceptés (simple, variable, variation…) ; vide = tous
     * @return array<int,array{value:string,label:string,meta:string,image:string}>
     */
    public static function items($search, $limit = 12, $exclude = '', array $types = array())
    {
        if (!self::available()) {
            return array();
        }

        $search = trim((string) $search);
        $limit = max(1, min(50, (int) $limit));

        if ($search === '') {
            return array();
        }

        $ids = array();
        $exact = ltrim($search, '#');

        if (ctype_digit($exact)) {
            $ids[] = (int) $exact;
        }

        $sku_id = function_exists('wc_get_product_id_by_sku') ? (int) wc_get_product_id_by_sku($search) : 0;

        if ($sku_id) {
            $ids[] = $sku_id;
        }

        $store = \WC_Data_Store::load('product');
        $ids = array_merge($ids, array_map('intval', (array) $store->search_products($search, '', in_array('variation', $types, true), false, $limit * 3)));
        $items = array();

        foreach (array_unique(array_filter($ids)) as $id) {
            if ($exclude !== '' && (string) $id === (string) $exclude) {
                continue;
            }

            $item = self::item($id, $types);

            if ($item) {
                $items[] = $item;
            }

            if (count($items) >= $limit) {
                break;
            }
        }

        return $items;
    }

    /**
     * @return array{value:string,label:string,meta:string,image:string}|null
     */
    public static function item($product_id, array $types = array())
    {
        $product = self::available() ? wc_get_product((int) $product_id) : null;

        if (!$product || $product->get_status() === 'trash' || ($types && !in_array($product->get_type(), $types, true))) {
            return null;
        }

        $meta = array();

        if ($product->get_sku() !== '') {
            $meta[] = sprintf(__('Réf. %s', 'smbb-wpcodetool'), $product->get_sku());
        }

        if ($product->get_price() !== '') {
            $meta[] = html_entity_decode(wp_strip_all_tags(wc_price(wc_get_price_to_display($product))), ENT_QUOTES, 'UTF-8');
        }

        if ($product->get_status() !== 'publish') {
            $meta[] = get_post_status_object($product->get_status()) ? get_post_status_object($product->get_status())->label : $product->get_status();
        }

        if ($product->managing_stock()) {
            $meta[] = sprintf(__('Stock : %d', 'smbb-wpcodetool'), (int) $product->get_stock_quantity());
        } elseif (!$product->is_in_stock()) {
            $meta[] = __('Rupture', 'smbb-wpcodetool');
        }

        $image = $product->get_image_id() ? wp_get_attachment_image_url((int) $product->get_image_id(), 'thumbnail') : '';

        return apply_filters('smbb_wpcodetool_product_lookup_item', array(
            'value' => (string) $product->get_id(),
            'label' => wp_strip_all_tags($product->get_name()) ?: '#' . $product->get_id(),
            'meta' => implode(' · ', $meta) ?: '#' . $product->get_id(),
            'image' => $image ? (string) $image : '',
        ), $product);
    }
}
