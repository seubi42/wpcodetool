<?php

namespace Smbb\Passflow\Service;

use Smbb\Passflow\Repository\Lookup;

defined('ABSPATH') || exit;

/**
 * Trace des mouvements d'une inscription (table passflow_history).
 */
final class History
{
    public static function record($booking_id, $action, $message = '', $slot_id = null, $from_slot_id = null)
    {
        global $wpdb;

        $wpdb->insert(Lookup::table('passflow_history'), array(
            'booking_id' => (int) $booking_id,
            'slot_id' => $slot_id ? (int) $slot_id : null,
            'from_slot_id' => $from_slot_id ? (int) $from_slot_id : null,
            'action' => substr((string) $action, 0, 30),
            'message' => (string) $message,
            'user_id' => get_current_user_id() ?: null,
            'created_at' => current_time('mysql'),
        ));

        do_action('smbb_passflow_history_recorded', (int) $booking_id, (string) $action, $slot_id, $from_slot_id);
    }
}
