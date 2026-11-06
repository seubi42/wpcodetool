<?php

namespace Smbb\Tomo\Service;

use Smbb\Tomo\Repository\Lookup;

defined('ABSPATH') || exit;

/**
 * Trace des mouvements d'une inscription (table tomo_historique).
 */
final class History
{
    public static function record($inscription_id, $action, $message = '', $creneau_id = null, $from_creneau_id = null)
    {
        global $wpdb;

        $wpdb->insert(Lookup::table('tomo_historique'), array(
            'inscription_id' => (int) $inscription_id,
            'creneau_id' => $creneau_id ? (int) $creneau_id : null,
            'from_creneau_id' => $from_creneau_id ? (int) $from_creneau_id : null,
            'action' => substr((string) $action, 0, 30),
            'message' => (string) $message,
            'user_id' => get_current_user_id() ?: null,
            'created_at' => current_time('mysql'),
        ));

        do_action('smbb_tomo_history_recorded', (int) $inscription_id, (string) $action, $creneau_id, $from_creneau_id);
    }
}
