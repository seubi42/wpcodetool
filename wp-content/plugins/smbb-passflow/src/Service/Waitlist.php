<?php

namespace Smbb\Passflow\Service;

use Smbb\Passflow\Repository\Slots;
use Smbb\Passflow\Repository\Lookup;
use Smbb\Passflow\Support\Settings;

defined('ABSPATH') || exit;

/**
 * Liste d'attente : quand une place se libère, la première personne (dans l'ordre d'arrivée)
 * est prévenue et a un délai pour réserver. Passé ce délai, la suivante est prévenue.
 */
final class Waitlist
{
    /**
     * Prévient la personne suivante si personne n'est déjà en train de réserver.
     *
     * @return bool vrai si quelqu'un a été prévenu
     */
    public static function notifyNext($slot_id)
    {
        global $wpdb;

        if (!Settings::waitlistEnabled()) {
            return false;
        }

        $slot = Slots::find($slot_id);

        if (!$slot || !in_array($slot['status'], array('open', 'full'), true) || Slots::isPast($slot) || (int) $slot['remaining'] < 1) {
            return false;
        }

        // Réservations pas encore ouvertes : pas de liste d'attente avant l'ouverture.
        if (SaleWindow::pending($slot)) {
            return false;
        }

        $table = Lookup::table('passflow_waitlist');
        $now = current_time('mysql');

        // Une personne a déjà la main sur ce créneau.
        $holding = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE deleted_flag = 0 AND slot_id = %d AND status = 'notified' AND (expires_at IS NULL OR expires_at > %s)",
            (int) $slot_id,
            $now
        ));

        if ($holding > 0) {
            return false;
        }

        // Première demande servie par les places libres, dans l'ordre d'arrivée.
        $next = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE deleted_flag = 0 AND slot_id = %d AND status = 'waiting' AND places <= %d ORDER BY id ASC LIMIT 1",
            (int) $slot_id,
            (int) $slot['remaining']
        ), ARRAY_A);

        if (!$next) {
            return false;
        }

        self::offer($next, $slot, 'waitlist_offer');

        return true;
    }

    /**
     * Garde la place d'une demande le délai réglé et la prévient.
     */
    private static function offer(array $row, array $slot, $template)
    {
        global $wpdb;

        $now = current_time('mysql');
        $hours = max(1, Settings::int('waitlist_hold_hours'));

        $wpdb->update(Lookup::table('passflow_waitlist'), array(
            'status' => 'notified',
            'notified_at' => $now,
            'expires_at' => date('Y-m-d H:i:s', strtotime($now) + $hours * HOUR_IN_SECONDS),
            'lastupdate_date' => $now,
        ), array('id' => (int) $row['id']));

        Notifier::toWaitlist($template, $row, $slot, array('delai' => self::delay($hours)));
    }

    /**
     * Tâche planifiée : les délais dépassés expirent et la personne suivante est prévenue.
     */
    public static function expire()
    {
        global $wpdb;

        $table = Lookup::table('passflow_waitlist');
        $now = current_time('mysql');
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id, slot_id FROM {$table} WHERE deleted_flag = 0 AND status = 'notified' AND expires_at IS NOT NULL AND expires_at <= %s",
            $now
        ), ARRAY_A);

        $slots = array();

        foreach ((array) $rows as $row) {
            $wpdb->update($table, array('status' => 'expired', 'lastupdate_date' => $now), array('id' => (int) $row['id']));
            $slots[(int) $row['slot_id']] = true;
        }

        foreach (array_keys($slots) as $slot_id) {
            self::notifyNext($slot_id);
        }

        return count((array) $rows);
    }

    /**
     * « 24 heures », « 2 jours ».
     */
    public static function delay($hours)
    {
        $hours = (int) $hours;

        if ($hours >= 24 && $hours % 24 === 0) {
            $days = $hours / 24;

            return sprintf(_n('%d jour', '%d jours', $days, 'smbb-passflow'), $days);
        }

        return sprintf(_n('%d heure', '%d heures', $hours, 'smbb-passflow'), $hours);
    }
}
