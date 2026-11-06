<?php

namespace Smbb\Tomo\Repository;

defined('ABSPATH') || exit;

/**
 * Lectures du journal des envois (e-mails et SMS).
 */
final class Envois
{
    /**
     * @return array<int,array<string,mixed>>
     */
    public static function forCreneau($creneau_id, $limit = 100)
    {
        return self::where('creneau_id', $creneau_id, $limit);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public static function forInscription($inscription_id, $limit = 50)
    {
        return self::where('inscription_id', $inscription_id, $limit);
    }

    /**
     * Messages liés aux réservations d'une commande WooCommerce (lus, pas recopiés).
     *
     * @return array<int,array<string,mixed>>
     */
    public static function forOrder($order_id, $limit = 100)
    {
        global $wpdb;

        if ((int) $order_id < 1) {
            return array();
        }

        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT e.* FROM ' . Lookup::table('tomo_envoi') . ' e'
            . ' INNER JOIN ' . Lookup::table('tomo_inscription') . ' i ON i.id = e.inscription_id'
            . ' WHERE e.deleted_flag = 0 AND i.order_id = %d ORDER BY e.id DESC LIMIT %d',
            (int) $order_id,
            (int) $limit
        ), ARRAY_A);

        return is_array($rows) ? $rows : array();
    }

    private static function where($column, $id, $limit)
    {
        global $wpdb;

        if ((int) $id < 1) {
            return array();
        }

        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM ' . Lookup::table('tomo_envoi') . ' WHERE deleted_flag = 0 AND ' . $column . ' = %d ORDER BY id DESC LIMIT %d',
            (int) $id,
            (int) $limit
        ), ARRAY_A);

        return is_array($rows) ? $rows : array();
    }
}
