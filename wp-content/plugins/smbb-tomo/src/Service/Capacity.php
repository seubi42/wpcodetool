<?php

namespace Smbb\Tomo\Service;

use Smbb\Tomo\Repository\Creneaux;
use Smbb\Tomo\Repository\Lookup;
use Smbb\Tomo\Support\Choices;

defined('ABSPATH') || exit;

/**
 * Règles de places : toutes les entrées (admin, front, API, WooCommerce) passent par ici.
 */
final class Capacity
{
    /**
     * Places occupées sur un créneau, en ignorant éventuellement une inscription (celle qu'on modifie).
     */
    public static function booked($creneau_id, $exclude_inscription_id = 0)
    {
        global $wpdb;

        $statuses = Choices::seatStatuses();
        $sql = 'SELECT COUNT(*) FROM ' . Lookup::table('tomo_inscription')
            . ' WHERE deleted_flag = 0 AND creneau_id = %d AND id <> %d AND status IN (' . implode(',', array_fill(0, count($statuses), '%s')) . ')';

        return (int) $wpdb->get_var($wpdb->prepare($sql, array_merge(array((int) $creneau_id, (int) $exclude_inscription_id), $statuses)));
    }

    public static function remaining($creneau_id, $exclude_inscription_id = 0)
    {
        $creneau = Creneaux::find($creneau_id);

        if (!$creneau) {
            return 0;
        }

        if (Passes::isPass($creneau)) {
            return (int) $creneau['remaining'];
        }

        return max(0, (int) $creneau['capacity'] - self::booked($creneau_id, $exclude_inscription_id));
    }

    /**
     * Message d'erreur si on ne peut pas ajouter $seats place(s) sur ce créneau, sinon chaîne vide.
     */
    public static function check($creneau_id, $seats = 1, $exclude_inscription_id = 0)
    {
        $creneau = Creneaux::find($creneau_id);

        if (!$creneau) {
            return __('Ce créneau est introuvable.', 'smbb-tomo');
        }

        if ($creneau['status'] === 'cancelled') {
            return __('Ce créneau est annulé.', 'smbb-tomo');
        }

        $remaining = Passes::isPass($creneau)
            ? (int) $creneau['remaining']
            : max(0, (int) $creneau['capacity'] - self::booked($creneau_id, $exclude_inscription_id));

        if ($remaining < $seats) {
            return $remaining > 0
                ? sprintf(_n('Il ne reste que %d place sur ce créneau.', 'Il ne reste que %d places sur ce créneau.', $remaining, 'smbb-tomo'), $remaining)
                : __('Ce créneau est complet.', 'smbb-tomo');
        }

        return '';
    }

    /**
     * Places gardées pour la liste d'attente (personnes prévenues dont le délai court),
     * sauf celles de $email : la personne prévenue peut prendre la place qu'on lui garde.
     */
    public static function held($creneau_id, $email = '')
    {
        global $wpdb;

        if (!\Smbb\Tomo\Support\Settings::waitlistEnabled()) {
            return 0;
        }

        $sql = 'SELECT COALESCE(SUM(places), 0) FROM ' . Lookup::table('tomo_attente')
            . " WHERE deleted_flag = 0 AND creneau_id = %d AND status = 'notified' AND (expires_at IS NULL OR expires_at > %s)";
        $params = array((int) $creneau_id, current_time('mysql'));

        if ($email !== '') {
            $sql .= ' AND LOWER(email) <> %s';
            $params[] = strtolower((string) $email);
        }

        return (int) $wpdb->get_var($wpdb->prepare($sql, $params));
    }

    /**
     * Places vendables en ligne : créneau en vente, à venir, places restantes moins celles gardées.
     */
    public static function forSale(array $creneau, $email = '')
    {
        if ($creneau['status'] !== 'open' || self::startsAt($creneau) <= current_time('timestamp')) {
            return 0;
        }

        if (Passes::isPass($creneau)) {
            // Les places gardées pour la liste d'attente d'un jour ne sont pas vendues dans un pass.
            $available = (int) $creneau['remaining'];

            foreach (Passes::days($creneau) as $day) {
                $available = min($available, max(0, (int) $day['remaining'] - self::held($day['id'], $email)));
            }

            return max(0, $available);
        }

        return max(0, (int) $creneau['remaining'] - self::held($creneau['id'], $email));
    }

    /**
     * Message d'erreur si $seats places ne peuvent pas être vendues en ligne, sinon chaîne vide.
     */
    public static function checkSale($creneau_id, $seats, $email = '')
    {
        $creneau = Creneaux::find($creneau_id);

        if (!$creneau || $creneau['status'] === 'cancelled') {
            return __("Cette date n'est plus disponible.", 'smbb-tomo');
        }

        $available = self::forSale($creneau, $email);

        if ($available >= $seats) {
            return '';
        }

        return $available > 0
            ? sprintf(_n('Il ne reste que %d place à cette date.', 'Il ne reste que %d places à cette date.', $available, 'smbb-tomo'), $available)
            : __('Cette date est complète.', 'smbb-tomo');
    }

    /**
     * Verrou MySQL d'une date, le temps de recompter les places et de créer les réservations.
     * Faux si le verrou n'a pas pu être pris en 5 secondes (on continue alors sans, comme avant).
     */
    public static function lock($creneau_id)
    {
        global $wpdb;

        return (int) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 5)', self::lockName($creneau_id))) === 1;
    }

    public static function unlock($creneau_id)
    {
        global $wpdb;

        $wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', self::lockName($creneau_id)));
    }

    private static function lockName($creneau_id)
    {
        global $wpdb;

        return $wpdb->prefix . 'tomo_creneau_' . (int) $creneau_id;
    }

    public static function startsAt(array $creneau)
    {
        return (int) strtotime($creneau['start_date'] . ' ' . ($creneau['start_time'] ?: '00:00:00'));
    }

    /**
     * Passe un créneau à « complet » ou le remet « en vente » selon ses places.
     * Les brouillons, créneaux terminés et annulés ne bougent pas.
     * Déclenche smbb_tomo_seat_freed quand des places se libèrent (liste d'attente).
     */
    public static function refresh($creneau_id)
    {
        global $wpdb;

        $creneau = Creneaux::find($creneau_id);

        if (!$creneau) {
            return;
        }

        if (!in_array($creneau['status'], array('open', 'full'), true)) {
            if (!Passes::isPass($creneau)) {
                foreach (Passes::passesOf($creneau_id) as $pass_id) {
                    self::refresh($pass_id);
                }
            }

            return;
        }

        $status = $creneau['remaining'] > 0 ? 'open' : 'full';

        if ($status !== $creneau['status']) {
            $wpdb->update(Lookup::table('tomo_creneau'), array('status' => $status), array('id' => (int) $creneau_id));
        }

        if (Passes::isPass($creneau)) {
            return;
        }

        if ($creneau['remaining'] > 0 && $creneau['waiting'] > 0) {
            do_action('smbb_tomo_seat_freed', (int) $creneau_id, (int) $creneau['remaining']);
        }

        // Les pass qui incluent ce jour se remplissent avec lui.
        foreach (Passes::passesOf($creneau_id) as $pass_id) {
            self::refresh($pass_id);
        }
    }
}
