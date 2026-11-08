<?php

namespace Smbb\Passflow\WooCommerce;

use Smbb\Passflow\Repository\Slots;
use Smbb\Passflow\Repository\Bookings;
use Smbb\Passflow\Repository\Lookup;
use Smbb\Passflow\Service\Booking;
use Smbb\Passflow\Service\Capacity;
use Smbb\Passflow\Service\History;
use Smbb\Passflow\Service\Notifier;
use Smbb\Passflow\Service\Passes;
use Smbb\Passflow\Service\Waitlist;
use Smbb\Passflow\Support\Input;
use Smbb\Passflow\Support\Settings;

defined('ABSPATH') || exit;

/**
 * Commande WooCommerce → réservations Passflow.
 *
 * - commande passée : une réservation « à confirmer » par participant, qui bloque la place ;
 * - commande payée (en cours / terminée) : réservations confirmées, un seul message au client ;
 * - commande annulée, remboursée ou échouée : réservations annulées, places rendues à la liste d'attente ;
 * - paiement jamais finalisé : places libérées au bout d'une heure par la tâche planifiée.
 */
final class Orders
{
    const META_SLOT = '_passflow_slot_id';
    const META_PARTICIPANTS = '_passflow_participants';
    const META_BOOKINGS = '_passflow_booking_ids';
    const META_RELEASED = '_passflow_released_ids';

    /** Délai avant de libérer les places d'une commande non payée, en minutes. */
    const ABANDON_MINUTES = 60;

    public static function hooks()
    {
        add_action('woocommerce_checkout_order_processed', array(__CLASS__, 'onCheckout'), 10, 3);
        add_action('woocommerce_store_api_checkout_order_processed', array(__CLASS__, 'onStoreApiCheckout'), 10, 1);
        add_action('woocommerce_order_status_changed', array(__CLASS__, 'onStatusChanged'), 10, 4);
        add_filter('woocommerce_order_actions', array(__CLASS__, 'orderActions'), 10, 2);
        add_action('woocommerce_order_action_passflow_resend_tickets', array(__CLASS__, 'resendTickets'), 10, 1);
    }

    /**
     * Fiche commande › Actions de commande : « Renvoyer les billets par e-mail » si la commande a des réservations.
     *
     * @param array<string,string> $actions
     */
    public static function orderActions($actions, $order = null)
    {
        if (!$order && isset($GLOBALS['theorder'])) {
            $order = $GLOBALS['theorder'];
        }

        $order = self::order($order);

        if ($order && self::ticketBookingIds($order)) {
            $actions['passflow_resend_tickets'] = __('Renvoyer les billets par e-mail', 'smbb-passflow');
        }

        return $actions;
    }

    /**
     * Réservations de la commande : celles notées sur ses lignes, et celles qui portent son numéro
     * (vente en caisse, ajout depuis le back-office…), comme l'encadré Passflow de la fiche commande.
     *
     * @return array<int,int>
     */
    private static function ticketBookingIds($order)
    {
        global $wpdb;

        $ids = array();

        foreach (self::bookingIds($order) as $item_ids) {
            $ids = array_merge($ids, $item_ids);
        }

        $rows = $wpdb->get_col($wpdb->prepare(
            'SELECT id FROM ' . Lookup::table('passflow_booking') . ' WHERE deleted_flag = 0 AND order_id = %d ORDER BY id ASC',
            (int) $order->get_id()
        ));

        return array_values(array_unique(array_map('intval', array_merge($ids, (array) $rows))));
    }

    /**
     * Renvoi à la demande : e-mails seulement (pas de SMS), mêmes règles que l'envoi au paiement.
     * Envoyé tout de suite, pour que la note de commande soit là au rechargement de la page.
     */
    public static function resendTickets($order)
    {
        $order = self::order($order);

        if (!$order) {
            return;
        }

        $tickets = array();

        foreach (self::ticketBookingIds($order) as $id) {
            $booking = Bookings::find($id);

            if (!$booking || !\Smbb\Passflow\Service\Tickets::available($booking)) {
                continue;
            }

            $root = Passes::root($booking);

            if (!isset($tickets[(int) $root['id']])) {
                $tickets[(int) $root['id']] = $booking;
            }
        }

        if (!$tickets) {
            $order->add_order_note(__("Passflow : aucun billet à renvoyer (réservations pas encore payées, annulées ou sans date).", 'smbb-passflow'));

            return;
        }

        self::sendTickets($order, $tickets, true);
        Notifier::flush();
    }

    /**
     * Paiement classique : une exception arrête la commande avant le paiement et s'affiche au client.
     */
    public static function onCheckout($order_id, $posted = array(), $order = null)
    {
        unset($posted);
        self::reserve($order ?: $order_id, true);
    }

    /**
     * Paiement en blocs (Store API) : même contrôle, l'erreur revient au formulaire.
     */
    public static function onStoreApiCheckout($order)
    {
        try {
            self::reserve($order, true);
        } catch (\Exception $e) {
            if (class_exists('\\Automattic\\WooCommerce\\StoreApi\\Exceptions\\RouteException')) {
                throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException('passflow_seats_unavailable', $e->getMessage(), 409);
            }

            throw $e;
        }
    }

    /**
     * Crée les réservations d'une commande (une seule fois par ligne).
     *
     * $strict (passage en caisse, avant paiement) : les places sont recomptées sous verrou, date par date,
     * et une date devenue complète entre-temps arrête la commande (exception) au lieu d'être surbookée.
     * Sans $strict (commande déjà payée, créée dans l'admin…) : on réserve quand même et on signale.
     *
     * @param \WC_Order|int $order
     * @throws \Exception en mode strict, si une date n'a plus assez de places
     */
    public static function reserve($order, $strict = false)
    {
        $order = self::order($order);

        if (!$order) {
            return;
        }

        $strict = $strict && !$order->is_paid();
        $lines = array();
        $seats = array();

        foreach ($order->get_items('line_item') as $item) {
            $slot_id = (int) $item->get_meta(self::META_SLOT);

            if (!$slot_id || $item->get_meta(self::META_BOOKINGS)) {
                continue;
            }

            $lines[] = array($item, $slot_id);
            $seats[$slot_id] = (isset($seats[$slot_id]) ? $seats[$slot_id] : 0) + max(1, (int) $item->get_quantity());
        }

        if (!$lines) {
            return;
        }

        // Un verrou par date, toujours pris dans le même ordre : deux paiements sur la dernière place
        // passent l'un après l'autre, et le second voit la date complète.
        ksort($seats);
        $locked = array();

        foreach (array_keys($seats) as $slot_id) {
            if (Capacity::lock($slot_id)) {
                $locked[] = $slot_id;
            }
        }

        try {
            if ($strict) {
                foreach ($seats as $slot_id => $count) {
                    $error = Capacity::checkSale($slot_id, $count, strtolower((string) $order->get_billing_email()));

                    if ($error !== '') {
                        $slot = Slots::find($slot_id);
                        $label = $slot ? Lookup::slotLabel($slot) : '';
                        $order->add_order_note(sprintf(__('Passflow : commande arrêtée avant paiement, plus assez de places (%s).', 'smbb-passflow'), trim($label . ' : ' . $error, ' :')));

                        throw new \Exception(trim($label . ' : ' . $error, ' :') . ' ' . __('Modifiez votre panier puis validez de nouveau.', 'smbb-passflow'));
                    }
                }
            }

            self::createAll($order, $lines);
        } finally {
            foreach ($locked as $slot_id) {
                Capacity::unlock($slot_id);
            }
        }
    }

    /**
     * @param \WC_Order $order
     * @param array<int,array{0:\WC_Order_Item_Product,1:int}> $lines
     */
    private static function createAll($order, array $lines)
    {
        $created = 0;

        foreach ($lines as $line) {
            list($item, $slot_id) = $line;
            $slot = Slots::find($slot_id);

            if (!$slot) {
                continue;
            }

            $ids = self::createBookings($order, $item, $slot);
            $item->update_meta_data(self::META_BOOKINGS, $ids);
            $item->save();
            $created += count($ids);

            Capacity::refresh($slot_id);

            if (!\Smbb\Passflow\Service\Passes::isPass($slot) && Capacity::booked($slot_id) > (int) $slot['capacity']) {
                $order->add_order_note(sprintf(__('Passflow : la jauge de %s est dépassée, vérifiez les places.', 'smbb-passflow'), Lookup::slotLabel($slot)));
            }
        }

        if ($created) {
            $order->add_order_note(sprintf(_n('Passflow : %d réservation créée, à confirmer au paiement.', 'Passflow : %d réservations créées, à confirmer au paiement.', $created, 'smbb-passflow'), $created));

            // Commande déjà payée au moment où on la voit (paiement immédiat, création manuelle…).
            if ($order->is_paid()) {
                self::confirm($order);
            }
        }
    }

    /**
     * @param int       $order_id
     * @param string    $from
     * @param string    $to
     * @param \WC_Order $order
     */
    public static function onStatusChanged($order_id, $from, $to, $order = null)
    {
        unset($from);
        $order = self::order($order ?: $order_id);

        // Imprévu payé par lien : rien sur la liste tant que ce n'est pas payé (chèque en attente…).
        if ($order && $to === 'on-hold' && $order->get_meta(Walkins::META)) {
            return;
        }

        if (!$order || !self::bookingIds($order, true)) {
            // Commande créée à la main dans l'admin avec des lignes Passflow : on réserve maintenant.
            if ($order && in_array($to, array('processing', 'completed', 'on-hold'), true)) {
                self::reserve($order);
            }

            return;
        }

        if (in_array($to, array('processing', 'completed'), true)) {
            self::confirm($order);
        } elseif (in_array($to, array('cancelled', 'refunded', 'failed'), true)) {
            self::release($order, $to === 'refunded' ? __('Commande remboursée.', 'smbb-passflow') : ($to === 'failed' ? __('Paiement échoué.', 'smbb-passflow') : __('Commande annulée.', 'smbb-passflow')));
        }
    }

    /**
     * Commande payée : les réservations « à confirmer » passent confirmées.
     * Une réservation libérée entre-temps (paiement tardif) est reprise, même si la jauge est pleine.
     */
    public static function confirm($order)
    {
        $order = self::order($order);

        if (!$order) {
            return;
        }

        $first = array();
        $over = array();

        Notifier::mute();

        foreach (self::bookingIds($order) as $item_id => $ids) {
            $item = $order->get_item($item_id);
            $released = $item ? array_map('intval', (array) $item->get_meta(self::META_RELEASED)) : array();

            foreach ($ids as $id) {
                $booking = Bookings::find($id);

                if (!$booking || empty($booking['slot_id'])) {
                    continue;
                }

                if ($booking['status'] === 'pending') {
                    Booking::presence($id, 'confirmed');
                } elseif ($booking['status'] === 'cancelled' && in_array((int) $id, $released, true)) {
                    if (Capacity::check($booking['slot_id'], 1, $id) !== '') {
                        $over[(int) $booking['slot_id']] = true;
                    }

                    self::update($id, array('status' => 'confirmed', 'checked_at' => null));
                    History::record($id, 'confirmed', __('Paiement reçu après libération de la place : réservation reprise.', 'smbb-passflow'), (int) $booking['slot_id']);
                    Capacity::refresh($booking['slot_id']);
                } else {
                    continue;
                }

                // Un ticket par participant (un pass = un seul ticket pour tous ses jours).
                $fresh = Bookings::find($id);
                $root = $fresh ? Passes::root($fresh) : null;

                if ($root && !isset($first[(int) $root['id']])) {
                    $first[(int) $root['id']] = $fresh;
                }

                self::closeWaitlist($booking, $id);
            }
        }

        Notifier::unmute();
        self::sendTickets($order, $first);

        foreach (array_keys($over) as $slot_id) {
            $slot = Slots::find($slot_id);
            $order->add_order_note(sprintf(__('Passflow : paiement reçu après libération des places, la jauge de %s est dépassée.', 'smbb-passflow'), $slot ? Lookup::slotLabel($slot) : '#' . $slot_id));
        }
    }

    /**
     * Commande annulée, remboursée, échouée ou abandonnée : on annule ses réservations sans prévenir
     * le client (WooCommerce s'en charge), puis on propose les places à la liste d'attente.
     */
    public static function release($order, $reason)
    {
        $order = self::order($order);

        if (!$order) {
            return 0;
        }

        $slots = array();
        $count = 0;

        Notifier::mute();

        foreach (self::bookingIds($order) as $item_id => $ids) {
            $released = array();

            foreach ($ids as $id) {
                $booking = Bookings::find($id);

                if (!$booking || $booking['status'] === 'cancelled') {
                    continue;
                }

                $result = Booking::cancel($id, false, $reason);

                if ($result['success']) {
                    $count++;
                    $released[] = (int) $id;

                    if (!empty($booking['slot_id'])) {
                        $slots[(int) $booking['slot_id']] = true;
                    }
                }
            }

            $item = $released ? $order->get_item($item_id) : null;

            if ($item) {
                // Si le paiement arrive quand même, ces réservations-là seront reprises.
                $item->update_meta_data(self::META_RELEASED, array_values(array_unique(array_merge(array_map('intval', (array) $item->get_meta(self::META_RELEASED)), $released))));
                $item->save();
            }
        }

        Notifier::unmute();

        foreach (array_keys($slots) as $slot_id) {
            Waitlist::notifyNext($slot_id);
        }

        if ($count) {
            $order->add_order_note(sprintf(_n('Passflow : %d réservation annulée.', 'Passflow : %d réservations annulées.', $count, 'smbb-passflow'), $count) . ' ' . $reason);
        }

        return $count;
    }

    /**
     * Part payée d'une inscription pour une journée : son prix, ou pour un jour de pass le prix du
     * pass divisé par son nombre de jours. 0 si la commande n'est pas payée.
     */
    public static function dayShare(array $booking)
    {
        $order = !empty($booking['order_id']) && function_exists('wc_get_order') ? wc_get_order((int) $booking['order_id']) : null;

        if (!$order || !$order->is_paid()) {
            return 0.0;
        }

        if (!empty($booking['pass_id'])) {
            $mother = Bookings::find((int) $booking['pass_id']);
            $pass = $mother && !empty($mother['slot_id']) ? Slots::find((int) $mother['slot_id']) : null;
            $days = $pass ? count(\Smbb\Passflow\Service\Passes::dayIds($pass)) : 0;

            return $mother && $days ? round((float) $mother['price_paid'] / $days, 2) : 0.0;
        }

        return (float) $booking['price_paid'];
    }

    public static function money($amount)
    {
        return function_exists('wc_price')
            ? html_entity_decode(wp_strip_all_tags(wc_price($amount)), ENT_QUOTES, 'UTF-8')
            : number_format_i18n($amount, 2) . ' €';
    }

    /**
     * Phrase de remboursement pour l'historique d'une inscription (vide sans commande payée).
     */
    public static function refundLine(array $booking)
    {
        $amount = self::dayShare($booking);

        if ($amount <= 0) {
            return '';
        }

        return sprintf(__('Remboursement de %1$s à convenir avec le client (commande n° %2$s).', 'smbb-passflow'), self::money($amount), (string) (int) $booking['order_id']);
    }

    /**
     * Journée annulée : une note par commande WooCommerce concernée, avec les participants et le
     * montant à rembourser (à convenir avec le client, rien n'est remboursé automatiquement).
     *
     * @param array<int,array<string,mixed>> $bookings
     */
    public static function noteDayCancelled(array $slot, array $bookings, $reason = '')
    {
        if (!function_exists('wc_get_order')) {
            return;
        }

        $by_order = array();

        foreach ($bookings as $booking) {
            if (!empty($booking['order_id'])) {
                $by_order[(int) $booking['order_id']][] = $booking;
            }
        }

        $date = !empty($slot['start_date']) ? date_i18n('l j F Y', strtotime($slot['start_date'] . ' 12:00:00')) : '';
        $activity = Lookup::activityName($slot['activity_id']);

        foreach ($by_order as $order_id => $rows) {
            $order = wc_get_order($order_id);

            if (!$order) {
                continue;
            }

            $amount = 0.0;
            $names = array();

            foreach ($rows as $row) {
                $amount += self::dayShare($row);
                $names[] = Lookup::personName($row);
            }

            $note = sprintf(__('Passflow : journée du %1$s annulée (%2$s), pour %3$s.', 'smbb-passflow'), $date, $activity, implode(', ', $names));
            $note .= ' ' . ($amount > 0
                ? sprintf(__('Remboursement de %s à convenir avec le client.', 'smbb-passflow'), self::money($amount))
                : __('Commande pas encore payée : rien à rembourser.', 'smbb-passflow'));

            if ($reason !== '') {
                $note .= ' ' . sprintf(__('Motif : %s', 'smbb-passflow'), $reason);
            }

            $order->add_order_note($note);
        }
    }

    /**
     * Tâche planifiée : libère les places des commandes restées « en attente de paiement ».
     */
    public static function releaseAbandoned()
    {
        global $wpdb;

        $limit = gmdate('Y-m-d H:i:s', current_time('timestamp') - self::ABANDON_MINUTES * MINUTE_IN_SECONDS);
        $order_ids = $wpdb->get_col($wpdb->prepare(
            'SELECT DISTINCT order_id FROM ' . Lookup::table('passflow_booking')
            . " WHERE deleted_flag = 0 AND source = 'shop' AND status = 'pending' AND order_id IS NOT NULL AND creation_date < %s",
            $limit
        ));
        $count = 0;

        foreach ($order_ids as $order_id) {
            $order = self::order((int) $order_id);

            if ($order && in_array($order->get_status(), array('pending', 'checkout-draft', 'failed'), true)) {
                $count += self::release($order, __('Paiement non finalisé, place libérée.', 'smbb-passflow'));
            }
        }

        return $count;
    }

    // ------------------------------------------------------------------
    // Interne
    // ------------------------------------------------------------------

    /**
     * Commande payée : l'acheteur reçoit tous les tickets (pièces jointes), chaque participant le sien.
     *
     * - E-mail acheteur « Les tickets de votre commande » : dès qu'il y a plusieurs tickets, ou un seul
     *   au nom de quelqu'un d'autre.
     * - Ticket personnel : un e-mail par ticket, sauf à l'adresse de l'acheteur quand il les a déjà tous ;
     *   un SMS par numéro (plusieurs places sur le même téléphone : un seul SMS).
     *
     * @param \WC_Order                        $order
     * @param array<int,array<string,mixed>>   $tickets réservation (ou pass) => réservation confirmée
     */
    private static function sendTickets($order, array $tickets, $resend = false)
    {
        if (!$tickets) {
            return;
        }

        Notifier::forOrder($order->get_id(), $resend);

        $buyer_email = strtolower(trim((string) $order->get_billing_email()));
        $single = reset($tickets);
        $own = count($tickets) === 1 && strtolower(trim((string) $single['email'])) === $buyer_email;
        $to_buyer = is_email($buyer_email) && !$own && ($resend || Settings::channelOn('order_tickets', 'email'));
        $phones = array();

        foreach ($tickets as $booking) {
            $channels = array();

            if (($resend || Settings::channelOn('confirmed', 'email')) && !($to_buyer && strtolower(trim((string) $booking['email'])) === $buyer_email)) {
                $channels[] = 'email';
            }

            $phone = preg_replace('/\D+/', '', (string) $booking['phone']);

            if (!$resend && Settings::channelOn('confirmed', 'sms') && $phone !== '' && !isset($phones[$phone])) {
                $phones[$phone] = true;
                $channels[] = 'sms';
            }

            if ($channels) {
                Notifier::onConfirmed((int) $booking['id'], $channels);
            }
        }

        if ($to_buyer) {
            Notifier::toBuyer('order_tickets', array(
                'first_name' => (string) $order->get_billing_first_name(),
                'last_name' => (string) $order->get_billing_last_name(),
                'email' => (string) $order->get_billing_email(),
                'phone' => '',
            ), array_map(function ($booking) { return (int) $booking['id']; }, array_values($tickets)), array(
                'commande' => (string) $order->get_order_number(),
            ));
        }

        Notifier::forOrder(0);
    }

    /**
     * @param \WC_Order               $order
     * @param \WC_Order_Item_Product $item
     *
     * @return int[]
     */
    private static function createBookings($order, $item, array $slot)
    {
        $quantity = max(1, (int) $item->get_quantity());
        $participants = $item->get_meta(self::META_PARTICIPANTS);
        $participants = is_array($participants) ? array_values($participants) : array();
        $price = ((float) $item->get_total() + (float) $item->get_total_tax()) / $quantity;
        $email = strtolower((string) $order->get_billing_email());
        $phone = Input::phone((string) $order->get_billing_phone());
        $ids = array();

        for ($i = 0; $i < $quantity; $i++) {
            $participant = isset($participants[$i]) && is_array($participants[$i]) ? $participants[$i] : array();
            $first_name = isset($participant['first_name']) ? (string) $participant['first_name'] : '';
            $last_name = isset($participant['last_name']) ? (string) $participant['last_name'] : '';

            if ($first_name === '' && $last_name === '') {
                $first_name = (string) $order->get_billing_first_name();
                $last_name = (string) $order->get_billing_last_name() . ($i > 0 ? ' (' . sprintf(__('place %d', 'smbb-passflow'), $i + 1) . ')' : '');
            }

            $id = Booking::insertBooking(array(
                'slot_id' => (int) $slot['id'],
                'activity_id' => (int) $slot['activity_id'],
                'customer_id' => $order->get_customer_id() ?: null,
                'first_name' => $first_name,
                'last_name' => $last_name,
                'email' => !empty($participant['email']) ? (string) $participant['email'] : $email,
                'phone' => !empty($participant['phone']) ? (string) $participant['phone'] : $phone,
                'allergies' => isset($participant['allergies']) ? (string) $participant['allergies'] : null,
                'level' => isset($participant['level']) ? (string) $participant['level'] : null,
                'age' => isset($participant['age']) && $participant['age'] !== '' ? (int) $participant['age'] : null,
                'source' => 'shop',
                'order_id' => (int) $order->get_id(),
                'order_item_id' => (int) $item->get_id(),
                'price_paid' => round($price, 2),
                'status' => 'pending',
            ));

            if ($id) {
                $ids[] = $id;
                History::record($id, 'created', sprintf(__('Commande en ligne n° %s, en attente du paiement.', 'smbb-passflow'), $order->get_order_number()), (int) $slot['id']);
                \Smbb\Passflow\Service\Passes::createChildren($id); // pass : une place par jour inclus.
            }
        }

        return $ids;
    }

    /**
     * Ids des réservations par ligne de commande.
     *
     * @return array<int,int[]>
     */
    private static function bookingIds($order, $any = false)
    {
        $result = array();

        foreach ($order->get_items('line_item') as $item_id => $item) {
            $ids = $item->get_meta(self::META_BOOKINGS);

            if (is_array($ids) && $ids) {
                $result[(int) $item_id] = array_map('intval', $ids);

                if ($any) {
                    return $result;
                }
            }
        }

        return $result;
    }

    /**
     * La personne avait une demande en liste d'attente pour cette date : elle est servie.
     */
    private static function closeWaitlist(array $booking, $booking_id)
    {
        global $wpdb;

        if (empty($booking['email'])) {
            return;
        }

        $wpdb->query($wpdb->prepare(
            'UPDATE ' . Lookup::table('passflow_waitlist')
            . " SET status = 'booked', booking_id = %d, lastupdate_date = %s WHERE deleted_flag = 0 AND slot_id = %d AND LOWER(email) = %s AND status IN ('waiting', 'notified')",
            (int) $booking_id,
            current_time('mysql'),
            (int) $booking['slot_id'],
            strtolower($booking['email'])
        ));
    }

    private static function update($id, array $data)
    {
        global $wpdb;

        $data['lastupdate_date'] = current_time('mysql');
        $result = $wpdb->update(Lookup::table('passflow_booking'), $data, array('id' => (int) $id));

        if ($result !== false) {
            \Smbb\Passflow\Service\Passes::cascade($id, $data);
        }

        return $result;
    }

    /**
     * @return \WC_Order|null
     */
    private static function order($order)
    {
        if ($order instanceof \WC_Order) {
            return $order;
        }

        $order = function_exists('wc_get_order') ? wc_get_order((int) $order) : null;

        return $order instanceof \WC_Order ? $order : null;
    }
}
