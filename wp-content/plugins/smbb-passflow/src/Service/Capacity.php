<?php

namespace Smbb\Passflow\Service;

use Smbb\Passflow\Repository\Slots;
use Smbb\Passflow\Repository\Lookup;
use Smbb\Passflow\Support\Choices;

defined('ABSPATH') || exit;

/**
 * Règles de places : toutes les entrées (admin, front, API, WooCommerce) passent par ici.
 */
final class Capacity
{
    /**
     * Places occupées sur un créneau, en ignorant éventuellement une inscription (celle qu'on modifie).
     */
    public static function booked($slot_id, $exclude_booking_id = 0)
    {
        global $wpdb;

        $statuses = Choices::seatStatuses();
        $sql = 'SELECT COUNT(*) FROM ' . Lookup::table('passflow_booking')
            . ' WHERE deleted_flag = 0 AND slot_id = %d AND id <> %d AND status IN (' . implode(',', array_fill(0, count($statuses), '%s')) . ')';

        return (int) $wpdb->get_var($wpdb->prepare($sql, array_merge(array((int) $slot_id, (int) $exclude_booking_id), $statuses)));
    }

    public static function remaining($slot_id, $exclude_booking_id = 0)
    {
        $slot = Slots::find($slot_id);

        if (!$slot) {
            return 0;
        }

        if (Passes::isPass($slot)) {
            return (int) $slot['remaining'];
        }

        return max(0, (int) $slot['capacity'] - self::booked($slot_id, $exclude_booking_id));
    }

    /**
     * Message d'erreur si on ne peut pas ajouter $seats place(s) sur ce créneau, sinon chaîne vide.
     */
    public static function check($slot_id, $seats = 1, $exclude_booking_id = 0)
    {
        $slot = Slots::find($slot_id);

        if (!$slot) {
            return __('Ce créneau est introuvable.', 'smbb-passflow');
        }

        if ($slot['status'] === 'cancelled') {
            return __('Ce créneau est annulé.', 'smbb-passflow');
        }

        $remaining = Passes::isPass($slot)
            ? (int) $slot['remaining']
            : max(0, (int) $slot['capacity'] - self::booked($slot_id, $exclude_booking_id));

        if ($remaining < $seats) {
            return $remaining > 0
                ? sprintf(_n('Il ne reste que %d place sur ce créneau.', 'Il ne reste que %d places sur ce créneau.', $remaining, 'smbb-passflow'), $remaining)
                : __('Ce créneau est complet.', 'smbb-passflow');
        }

        return '';
    }

    /**
     * Places gardées pour la liste d'attente (personnes prévenues dont le délai court),
     * sauf celles de $email : la personne prévenue peut prendre la place qu'on lui garde.
     */
    public static function held($slot_id, $email = '')
    {
        global $wpdb;

        if (!\Smbb\Passflow\Support\Settings::waitlistEnabled()) {
            return 0;
        }

        $sql = 'SELECT COALESCE(SUM(places), 0) FROM ' . Lookup::table('passflow_waitlist')
            . " WHERE deleted_flag = 0 AND slot_id = %d AND status = 'notified' AND (expires_at IS NULL OR expires_at > %s)";
        $params = array((int) $slot_id, current_time('mysql'));

        if ($email !== '') {
            $sql .= ' AND LOWER(email) <> %s';
            $params[] = strtolower((string) $email);
        }

        return (int) $wpdb->get_var($wpdb->prepare($sql, $params));
    }

    /**
     * Places vendables en ligne : créneau en vente, à venir, places restantes moins celles gardées.
     */
    public static function forSale(array $slot, $email = '')
    {
        if ($slot['status'] !== 'open' || self::startsAt($slot) <= current_time('timestamp')) {
            return 0;
        }

        // Mise en vente différée : affiché, pas encore réservable en ligne.
        if (SaleWindow::pending($slot)) {
            return 0;
        }

        if (Passes::isPass($slot)) {
            // Les places gardées pour la liste d'attente d'un jour ne sont pas vendues dans un pass.
            $available = (int) $slot['remaining'];

            foreach (Passes::days($slot) as $day) {
                $available = min($available, max(0, (int) $day['remaining'] - self::held($day['id'], $email)));
            }

            return max(0, $available);
        }

        return max(0, (int) $slot['remaining'] - self::held($slot['id'], $email));
    }

    /**
     * Message d'erreur si $seats places ne peuvent pas être vendues en ligne, sinon chaîne vide.
     */
    public static function checkSale($slot_id, $seats, $email = '')
    {
        $slot = Slots::find($slot_id);

        if (!$slot || $slot['status'] === 'cancelled') {
            return __("Cette date n'est plus disponible.", 'smbb-passflow');
        }

        $available = self::forSale($slot, $email);

        if ($available >= $seats) {
            return '';
        }

        if ($slot['status'] === 'open' && SaleWindow::pending($slot)) {
            return SaleWindow::label($slot) . '.';
        }

        return $available > 0
            ? sprintf(_n('Il ne reste que %d place à cette date.', 'Il ne reste que %d places à cette date.', $available, 'smbb-passflow'), $available)
            : __('Cette date est complète.', 'smbb-passflow');
    }

    /**
     * Verrou MySQL d'une date, le temps de recompter les places et de créer les réservations.
     * Faux si le verrou n'a pas pu être pris en 5 secondes (on continue alors sans, comme avant).
     */
    public static function lock($slot_id)
    {
        global $wpdb;

        return (int) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 5)', self::lockName($slot_id))) === 1;
    }

    public static function unlock($slot_id)
    {
        global $wpdb;

        $wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', self::lockName($slot_id)));
    }

    private static function lockName($slot_id)
    {
        global $wpdb;

        return $wpdb->prefix . 'passflow_slot_' . (int) $slot_id;
    }

    public static function startsAt(array $slot)
    {
        return (int) strtotime($slot['start_date'] . ' ' . ($slot['start_time'] ?: '00:00:00'));
    }

    /**
     * Passe un créneau à « complet » ou le remet « en vente » selon ses places.
     * Les brouillons, créneaux terminés et annulés ne bougent pas.
     * Déclenche smbb_passflow_seat_freed quand des places se libèrent (liste d'attente).
     */
    public static function refresh($slot_id)
    {
        global $wpdb;

        $slot = Slots::find($slot_id);

        if (!$slot) {
            return;
        }

        if (!in_array($slot['status'], array('open', 'full'), true)) {
            if (!Passes::isPass($slot)) {
                foreach (Passes::passesOf($slot_id) as $pass_id) {
                    self::refresh($pass_id);
                }
            }

            return;
        }

        $status = $slot['remaining'] > 0 ? 'open' : 'full';

        if ($status !== $slot['status']) {
            $wpdb->update(Lookup::table('passflow_slot'), array('status' => $status), array('id' => (int) $slot_id));
        }

        if (Passes::isPass($slot)) {
            return;
        }

        if ($slot['remaining'] > 0 && $slot['waiting'] > 0) {
            do_action('smbb_passflow_seat_freed', (int) $slot_id, (int) $slot['remaining']);
        }

        // Les pass qui incluent ce jour se remplissent avec lui.
        foreach (Passes::passesOf($slot_id) as $pass_id) {
            self::refresh($pass_id);
        }
    }
}
