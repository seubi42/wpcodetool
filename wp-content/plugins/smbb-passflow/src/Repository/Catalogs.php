<?php

namespace Smbb\Passflow\Repository;

defined('ABSPATH') || exit;

/**
 * Catalogue sur place : catalogues (principaux et sous-catalogues) et leurs produits.
 *
 * Un catalogue vaut pour les ateliers et les intervenants cochés ; aucune case cochée = tous.
 * Un sous-catalogue n'est proposé que si son parent l'est aussi.
 */
final class Catalogs
{
    /**
     * @return array<int,array<string,mixed>> par id, triés par position puis nom
     */
    public static function all($active_only = false)
    {
        global $wpdb;

        static $cache = null;

        if ($cache === null) {
            $rows = $wpdb->get_results('SELECT * FROM ' . Lookup::table('passflow_catalog') . ' WHERE deleted_flag = 0 ORDER BY position ASC, name ASC, id ASC', ARRAY_A);
            $cache = array();

            foreach (is_array($rows) ? $rows : array() as $row) {
                $cache[(int) $row['id']] = $row;
            }
        }

        return $active_only ? array_filter($cache, function ($row) {
            return (int) $row['active'] === 1;
        }) : $cache;
    }

    public static function find($id)
    {
        $all = self::all();

        return isset($all[(int) $id]) ? $all[(int) $id] : null;
    }

    /**
     * @return int[]
     */
    public static function descendantIds($id)
    {
        $out = array();

        foreach (self::all() as $row) {
            if ((int) $row['parent_id'] === (int) $id) {
                $out[] = (int) $row['id'];
                $out = array_merge($out, self::descendantIds($row['id']));
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * Chemin lisible : « Boutique › Ustensiles ».
     */
    public static function path($id)
    {
        $parts = array();
        $guard = 0;
        $row = self::find($id);

        while ($row && $guard++ < 10) {
            array_unshift($parts, (string) $row['name']);
            $row = !empty($row['parent_id']) ? self::find($row['parent_id']) : null;
        }

        return implode(' › ', $parts);
    }

    /**
     * Options pour « Rangé dans » : catalogues sauf $exclude et ses sous-catalogues.
     *
     * @return array<string,string> libellé => valeur
     */
    public static function parentOptions($exclude = 0)
    {
        $skip = $exclude ? array_merge(array((int) $exclude), self::descendantIds($exclude)) : array();
        $options = array(__('— Catalogue principal —', 'smbb-passflow') => '');

        foreach (self::all() as $id => $row) {
            if (!in_array((int) $id, $skip, true)) {
                $options[self::path($id)] = (string) $id;
            }
        }

        return $options;
    }

    public static function matches(array $row, $activity_id, $host_id)
    {
        $activities = array_map('intval', Lookup::ids($row['activity_ids']));
        $hosts = array_map('intval', Lookup::ids($row['host_ids']));

        if ($activities && !in_array((int) $activity_id, $activities, true)) {
            return false;
        }

        // $host_id null : gestionnaire, il voit les catalogues de tous les intervenants.
        if ($hosts && $host_id !== null && !in_array((int) $host_id, $hosts, true)) {
            return false;
        }

        return true;
    }

    /**
     * Catalogues proposés pour un atelier et un intervenant, en arbre à plat (ordre d'affichage),
     * chacun avec sa profondeur et ses produits vendables.
     *
     * @return array<int,array{id:int,name:string,path:string,depth:int,parent_id:int,products:\WC_Product[]}>
     */
    public static function forContext($activity_id, $host_id = null)
    {
        if (!function_exists('wc_get_product')) {
            return array();
        }

        $all = self::all(true);
        $out = array();
        $walk = function ($parent, $depth) use (&$walk, &$out, $all, $activity_id, $host_id) {
            foreach ($all as $id => $row) {
                if ((int) $row['parent_id'] !== (int) $parent || !self::matches($row, $activity_id, $host_id)) {
                    continue;
                }

                $products = array();

                foreach (Lookup::ids($row['product_ids']) as $product_id) {
                    $product = wc_get_product((int) $product_id);

                    // Simples et variables : la variante (taille…) se choisit à la caisse.
                    if ($product && \Smbb\Passflow\WooCommerce\OnsiteSales::sellable($product)) {
                        $products[] = $product;
                    }
                }

                $out[] = array('id' => (int) $id, 'name' => (string) $row['name'], 'path' => self::path($id), 'depth' => $depth, 'parent_id' => (int) $row['parent_id'], 'products' => $products);
                $walk($id, $depth + 1);
            }
        };
        $walk(0, 0);

        return $out;
    }

    /**
     * Produits vendables dans un contexte, par id.
     *
     * @return array<int,\WC_Product>
     */
    public static function productsFor($activity_id, $host_id = null)
    {
        $out = array();

        foreach (self::forContext($activity_id, $host_id) as $catalog) {
            foreach ($catalog['products'] as $product) {
                $out[(int) $product->get_id()] = $product;
            }
        }

        return $out;
    }
}
