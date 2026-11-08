<?php

namespace Smbb\Passflow\Repository;

defined('ABSPATH') || exit;

/**
 * Lectures du journal des envois (e-mails et SMS).
 */
final class Deliveries
{
    /**
     * @return array<int,array<string,mixed>>
     */
    public static function forSlot($slot_id, $limit = 100)
    {
        return self::where('slot_id', $slot_id, $limit);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public static function forBooking($booking_id, $limit = 50)
    {
        return self::where('booking_id', $booking_id, $limit);
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
            'SELECT e.* FROM ' . Lookup::table('passflow_delivery') . ' e'
            . ' INNER JOIN ' . Lookup::table('passflow_booking') . ' i ON i.id = e.booking_id'
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
            'SELECT * FROM ' . Lookup::table('passflow_delivery') . ' WHERE deleted_flag = 0 AND ' . $column . ' = %d ORDER BY id DESC LIMIT %d',
            (int) $id,
            (int) $limit
        ), ARRAY_A);

        return is_array($rows) ? $rows : array();
    }
}
