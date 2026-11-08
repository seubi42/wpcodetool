<?php

namespace Smbb\Passflow\Service;

use Smbb\Passflow\Repository\Slots;
use Smbb\Passflow\Repository\Bookings;
use Smbb\Passflow\Repository\Lookup;
use Smbb\Passflow\Support\Choices;
use Smbb\Passflow\Support\Input;

defined('ABSPATH') || exit;

/**
 * Opérations métier sur les inscriptions et les créneaux.
 *
 * Chaque méthode renvoie array('success' => bool, 'message' => string) et laisse une trace
 * dans l'historique. Les actions WordPress déclenchées (smbb_passflow_*) serviront aux
 * notifications du lot 2.
 */
final class Booking
{
    /**
     * Annule une inscription, ou la reporte ($postpone = true) : elle repasse « à confirmer »
     * sans date et attend un nouveau créneau dans le stock.
     */
    public static function cancel($booking_id, $postpone = false, $reason = '')
    {
        $booking = Bookings::find($booking_id);

        if (!$booking) {
            return self::fail(__('Inscription introuvable.', 'smbb-passflow'));
        }

        if ($booking['status'] === 'cancelled') {
            return self::fail(__('Cette inscription est déjà annulée.', 'smbb-passflow'));
        }

        $old_slot_id = (int) $booking['slot_id'];
        $status = $postpone ? 'pending' : 'cancelled';
        // Un jour de pass annulé seul : remboursement de sa part, calculé avant le changement.
        $day_refund = !$postpone && !empty($booking['pass_id']) && class_exists('\\Smbb\\Passflow\\WooCommerce\\Orders') && function_exists('wc_get_order')
            ? \Smbb\Passflow\WooCommerce\Orders::refundLine($booking)
            : '';
        $data = array('status' => $status, 'checked_at' => null);

        if ($postpone) {
            $data['slot_id'] = null;
        }

        self::updateBooking($booking_id, $data);

        $when = $old_slot_id ? Slots::find($old_slot_id) : null;
        $message = $postpone
            ? sprintf(__('Reportée : retirée du créneau %s, en attente d\'une nouvelle date.', 'smbb-passflow'), $when ? Lookup::slotWhen($when) : '—')
            : __('Inscription annulée.', 'smbb-passflow');

        if ($reason !== '') {
            $message .= ' ' . sprintf(__('Motif : %s', 'smbb-passflow'), $reason);
        }

        if ($day_refund !== '') {
            $message .= ' ' . $day_refund;
            $day = $old_slot_id ? Slots::find($old_slot_id) : null;

            if ($day) {
                \Smbb\Passflow\WooCommerce\Orders::noteDayCancelled($day, array($booking), (string) $reason);
            }
        }

        History::record($booking_id, $postpone ? 'postponed' : 'cancelled', $message, $old_slot_id ?: null);

        if ($old_slot_id) {
            Capacity::refresh($old_slot_id);
        }

        do_action('smbb_passflow_booking_cancelled', (int) $booking_id, $status, $old_slot_id, $reason);

        return self::ok($postpone ? __('Réservation reportée : elle attend une nouvelle date dans le stock.', 'smbb-passflow') : __('Réservation annulée.', 'smbb-passflow'));
    }

    /**
     * Déplace une inscription vers un autre créneau, ou donne une date à une réservation reportée.
     * $force permet de dépasser la jauge (décision de l'équipe). $confirm passe la réservation « confirmée ».
     */
    public static function move($booking_id, $target_slot_id, $force = false, $confirm = true)
    {
        $booking = Bookings::find($booking_id);
        $target = Slots::find($target_slot_id);

        if (!$booking || !$target) {
            return self::fail(__('Inscription ou créneau introuvable.', 'smbb-passflow'));
        }

        $from = (int) $booking['slot_id'];

        if ($from === (int) $target['id'] && in_array($booking['status'], Choices::seatStatuses(), true)) {
            return self::fail(__("L'inscription est déjà sur ce créneau.", 'smbb-passflow'));
        }

        $error = Capacity::check($target['id'], 1, $booking_id);

        if ($error !== '' && !($force && $target['status'] !== 'cancelled')) {
            return self::fail($error);
        }

        self::updateBooking($booking_id, array(
            'slot_id' => (int) $target['id'],
            'activity_id' => (int) $target['activity_id'],
            'status' => $confirm ? 'confirmed' : 'pending',
            'checked_at' => null,
        ));

        $from_row = $from ? Slots::find($from) : null;
        $message = $from_row
            ? sprintf(__('Déplacée de %1$s vers %2$s.', 'smbb-passflow'), Lookup::slotLabel($from_row), Lookup::slotLabel($target))
            : sprintf(__('Placée sur %s.', 'smbb-passflow'), Lookup::slotLabel($target));

        if ($error !== '') {
            $message .= ' ' . __('(jauge dépassée volontairement)', 'smbb-passflow');
        }

        History::record($booking_id, 'moved', $message, (int) $target['id'], $from ?: null);

        if ($from) {
            Capacity::refresh($from);
        }

        Capacity::refresh($target['id']);
        do_action('smbb_passflow_booking_moved', (int) $booking_id, $from, (int) $target['id'], (bool) $confirm);

        return self::ok($from_row ? __('Inscription déplacée.', 'smbb-passflow') : __('Inscription placée sur le créneau.', 'smbb-passflow'));
    }

    /**
     * Confirmation (pending → confirmed) et pointage du jour de l'atelier : present, absent, ou confirmed pour annuler le pointage.
     */
    public static function presence($booking_id, $status)
    {
        $booking = Bookings::find($booking_id);

        if (!$booking || !in_array($status, array('present', 'absent', 'confirmed'), true)) {
            return self::fail(__('Pointage impossible.', 'smbb-passflow'));
        }

        if (empty($booking['slot_id']) || !in_array($booking['status'], Choices::seatStatuses(), true)) {
            return self::fail(__("Seule une inscription placée sur un créneau peut être confirmée ou pointée.", 'smbb-passflow'));
        }

        if ($booking['status'] === $status) {
            return self::ok(sprintf(__('%1$s : %2$s.', 'smbb-passflow'), Lookup::personName($booking), Choices::label('bookingStatuses', $status)));
        }

        self::updateBooking($booking_id, array(
            'status' => $status,
            'checked_at' => $status === 'confirmed' ? null : current_time('mysql'),
        ));

        $is_confirmation = $booking['status'] === 'pending' && $status === 'confirmed';
        History::record($booking_id, $is_confirmation ? 'confirmed' : 'presence', Choices::label('bookingStatuses', $status), (int) $booking['slot_id']);

        if ($is_confirmation) {
            do_action('smbb_passflow_booking_confirmed', (int) $booking_id);
        }

        return self::ok(sprintf(__('%1$s : %2$s.', 'smbb-passflow'), Lookup::personName($booking), Choices::label('bookingStatuses', $status)));
    }

    /**
     * Annule un créneau : ses réservations repassent « à confirmer » sans date, sa liste d'attente est close.
     */
    public static function cancelSlot($slot_id, $reason = '')
    {
        global $wpdb;

        $slot = Slots::find($slot_id);

        if (!$slot) {
            return self::fail(__('Créneau introuvable.', 'smbb-passflow'));
        }

        if ($slot['status'] === 'cancelled') {
            return self::fail(__('Ce créneau est déjà annulé.', 'smbb-passflow'));
        }

        $wpdb->update(Lookup::table('passflow_slot'), array(
            'status' => 'cancelled',
            'cancel_reason' => (string) $reason,
            'lastupdate_date' => current_time('mysql'),
            'lastupdate_by' => get_current_user_id() ?: null,
        ), array('id' => (int) $slot_id));

        $moved = 0;
        $ids = array();
        $label = Lookup::slotLabel($slot);

        $woo = class_exists('\\Smbb\\Passflow\\WooCommerce\\Orders') && function_exists('wc_get_order');
        $affected = Bookings::forSlot($slot_id);

        foreach ($affected as $booking) {
            $ids[] = (int) $booking['id'];
            $refund = $woo ? \Smbb\Passflow\WooCommerce\Orders::refundLine($booking) : '';
            self::updateBooking($booking['id'], array('status' => 'pending', 'slot_id' => null, 'checked_at' => null));
            History::record($booking['id'], 'slot_cancelled', sprintf(__('Créneau %s annulé, réservation en attente d\'une nouvelle date.', 'smbb-passflow'), $label) . ($reason !== '' ? ' ' . sprintf(__('Motif : %s', 'smbb-passflow'), $reason) : '') . ($refund !== '' ? ' ' . $refund : ''), (int) $slot_id);
            $moved++;
        }

        // Note dans chaque commande WooCommerce : journée annulée, remboursement à convenir.
        if ($woo && $affected) {
            \Smbb\Passflow\WooCommerce\Orders::noteDayCancelled($slot, $affected, (string) $reason);
        }

        $wpdb->query($wpdb->prepare('UPDATE ' . Lookup::table('passflow_waitlist') . " SET status = 'cancelled' WHERE slot_id = %d AND status IN ('waiting','notified')", (int) $slot_id));
        Capacity::refresh($slot_id); // un jour annulé rend complets les pass qui l'incluent.

        do_action('smbb_passflow_slot_cancelled', (int) $slot_id, $reason, $ids);

        return self::ok(sprintf(
            _n('Créneau annulé. %d réservation attend une nouvelle date.', 'Créneau annulé. %d réservations attendent une nouvelle date.', $moved, 'smbb-passflow'),
            $moved
        ));
    }

    /**
     * Inscrit une demande de la liste d'attente (une inscription par place demandée).
     */
    public static function bookFromWaitlist($waitlist_id, $force = false)
    {
        global $wpdb;

        $waitlist = Bookings::findWaitlist($waitlist_id);

        if (!$waitlist || !in_array($waitlist['status'], array('waiting', 'notified'), true)) {
            return self::fail(__("Cette demande n'est plus en attente.", 'smbb-passflow'));
        }

        $slot = Slots::find($waitlist['slot_id']);
        $places = max(1, (int) $waitlist['places']);
        $error = Capacity::check($waitlist['slot_id'], $places);

        if (!$slot || ($error !== '' && !($force && $slot['status'] !== 'cancelled'))) {
            return self::fail($error !== '' ? $error : __('Créneau introuvable.', 'smbb-passflow'));
        }

        $first_id = 0;

        for ($i = 0; $i < $places; $i++) {
            $id = self::insertBooking(array(
                'slot_id' => (int) $slot['id'],
                'activity_id' => (int) $slot['activity_id'],
                'customer_id' => $waitlist['customer_id'] ? (int) $waitlist['customer_id'] : null,
                'first_name' => $waitlist['first_name'],
                'last_name' => $waitlist['last_name'] . ($i > 0 ? ' (' . sprintf(__('place %d', 'smbb-passflow'), $i + 1) . ')' : ''),
                'email' => $waitlist['email'],
                'phone' => $waitlist['phone'],
                'source' => 'waitlist',
                'status' => 'confirmed',
            ));

            if (!$id) {
                return self::fail(__("L'inscription n'a pas pu être enregistrée.", 'smbb-passflow'));
            }

            $first_id = $first_id ?: $id;
            History::record($id, 'waitlist', sprintf(__("Inscrite depuis la liste d'attente sur %s.", 'smbb-passflow'), Lookup::slotLabel($slot)), (int) $slot['id']);
        }

        $wpdb->update(Lookup::table('passflow_waitlist'), array(
            'status' => 'booked',
            'booking_id' => $first_id,
            'lastupdate_date' => current_time('mysql'),
            'lastupdate_by' => get_current_user_id() ?: null,
        ), array('id' => (int) $waitlist_id));

        Capacity::refresh($slot['id']);
        do_action('smbb_passflow_waitlist_booked', (int) $waitlist_id, $first_id);

        return self::ok(sprintf(_n('%d place réservée depuis la liste d\'attente.', '%d places réservées depuis la liste d\'attente.', $places, 'smbb-passflow'), $places));
    }

    /**
     * Copie un créneau sur d'autres dates (mêmes horaires, places, prix et intervenants).
     *
     * @param array<int,string> $dates dates Y-m-d
     */
    public static function duplicateSlot($slot_id, array $dates)
    {
        global $wpdb;

        $slot = Slots::find($slot_id);

        if (!$slot) {
            return self::fail(__('Créneau introuvable.', 'smbb-passflow'));
        }

        if (Passes::isPass($slot)) {
            return self::fail(__('Un pass ne se duplique pas : créez les nouvelles dates, puis un nouveau pass qui les regroupe.', 'smbb-passflow'));
        }

        $created = 0;

        foreach (array_unique($dates) as $date) {
            $date = trim((string) $date);

            if (!Input::isDate($date)) {
                continue;
            }

            $ok = $wpdb->insert(Lookup::table('passflow_slot'), array(
                'activity_id' => (int) $slot['activity_id'],
                'start_date' => $date,
                'start_time' => $slot['start_time'],
                'end_time' => $slot['end_time'],
                'all_day' => !empty($slot['all_day']) ? 1 : 0,
                'capacity' => (int) $slot['capacity'],
                'price' => $slot['price'],
                'host_ids' => is_array($slot['host_ids']) ? wp_json_encode($slot['host_ids']) : $slot['host_ids'],
                'status' => in_array($slot['status'], array('open', 'full'), true) ? 'open' : 'draft',
                'internal_note' => $slot['internal_note'],
                'creation_date' => current_time('mysql'),
                'creation_by' => get_current_user_id() ?: null,
                'lastupdate_date' => current_time('mysql'),
                'lastupdate_by' => get_current_user_id() ?: null,
            ));

            if ($ok) {
                $created++;
                $new_ids = Lookup::ids($slot['host_ids']);

                if ($new_ids) {
                    do_action('smbb_passflow_hosts_assigned', (int) $wpdb->insert_id, $new_ids);
                }
            }
        }

        if (!$created) {
            return self::fail(__('Aucune date valide : rien n\'a été créé.', 'smbb-passflow'));
        }

        return self::ok(sprintf(_n('%d créneau créé.', '%d créneaux créés.', $created, 'smbb-passflow'), $created));
    }

    /**
     * Insère une inscription avec ses colonnes d'audit. Renvoie l'id ou 0.
     */
    public static function insertBooking(array $data)
    {
        global $wpdb;

        $now = current_time('mysql');
        $user = get_current_user_id() ?: null;
        $data = array_merge(array(
            'source' => 'manual',
            'status' => 'confirmed',
            'creation_date' => $now,
            'creation_by' => $user,
            'lastupdate_date' => $now,
            'lastupdate_by' => $user,
        ), $data);

        return $wpdb->insert(Lookup::table('passflow_booking'), $data) ? (int) $wpdb->insert_id : 0;
    }

    private static function updateBooking($id, array $data)
    {
        global $wpdb;

        $data['lastupdate_date'] = current_time('mysql');
        $data['lastupdate_by'] = get_current_user_id() ?: null;
        $ok = $wpdb->update(Lookup::table('passflow_booking'), $data, array('id' => (int) $id)) !== false;

        if ($ok) {
            Passes::cascade($id, $data); // les jours d'un pass suivent la réservation principale.
        }

        return $ok;
    }

    private static function ok($message)
    {
        return array('success' => true, 'message' => (string) $message);
    }

    private static function fail($message)
    {
        return array('success' => false, 'message' => (string) $message);
    }
}
