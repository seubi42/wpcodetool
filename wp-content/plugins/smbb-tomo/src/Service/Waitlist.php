<?php

namespace Smbb\Tomo\Service;

use Smbb\Tomo\Repository\Creneaux;
use Smbb\Tomo\Repository\Lookup;
use Smbb\Tomo\Support\Settings;

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
    public static function notifyNext($creneau_id)
    {
        global $wpdb;

        if (!Settings::waitlistEnabled()) {
            return false;
        }

        $creneau = Creneaux::find($creneau_id);

        if (!$creneau || !in_array($creneau['status'], array('open', 'full'), true) || Creneaux::isPast($creneau) || (int) $creneau['remaining'] < 1) {
            return false;
        }

        $table = Lookup::table('tomo_attente');
        $now = current_time('mysql');

        // Une personne a déjà la main sur ce créneau.
        $holding = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE deleted_flag = 0 AND creneau_id = %d AND status = 'notified' AND (expires_at IS NULL OR expires_at > %s)",
            (int) $creneau_id,
            $now
        ));

        if ($holding > 0) {
            return false;
        }

        // Première demande servie par les places libres, dans l'ordre d'arrivée.
        $next = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE deleted_flag = 0 AND creneau_id = %d AND status = 'waiting' AND places <= %d ORDER BY id ASC LIMIT 1",
            (int) $creneau_id,
            (int) $creneau['remaining']
        ), ARRAY_A);

        if (!$next) {
            return false;
        }

        $hours = max(1, Settings::int('waitlist_hold_hours'));

        $wpdb->update($table, array(
            'status' => 'notified',
            'notified_at' => $now,
            'expires_at' => date('Y-m-d H:i:s', strtotime($now) + $hours * HOUR_IN_SECONDS),
            'lastupdate_date' => $now,
        ), array('id' => (int) $next['id']));

        Notifier::toWaitlist('waitlist_offer', $next, $creneau, array('delai' => self::delay($hours)));

        return true;
    }

    /**
     * Tâche planifiée : les délais dépassés expirent et la personne suivante est prévenue.
     */
    public static function expire()
    {
        global $wpdb;

        $table = Lookup::table('tomo_attente');
        $now = current_time('mysql');
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id, creneau_id FROM {$table} WHERE deleted_flag = 0 AND status = 'notified' AND expires_at IS NOT NULL AND expires_at <= %s",
            $now
        ), ARRAY_A);

        $creneaux = array();

        foreach ((array) $rows as $row) {
            $wpdb->update($table, array('status' => 'expired', 'lastupdate_date' => $now), array('id' => (int) $row['id']));
            $creneaux[(int) $row['creneau_id']] = true;
        }

        foreach (array_keys($creneaux) as $creneau_id) {
            self::notifyNext($creneau_id);
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

            return sprintf(_n('%d jour', '%d jours', $days, 'smbb-tomo'), $days);
        }

        return sprintf(_n('%d heure', '%d heures', $hours, 'smbb-tomo'), $hours);
    }
}
