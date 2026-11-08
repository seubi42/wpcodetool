<?php

namespace Smbb\Passflow\WooCommerce;

use Smbb\Passflow\Repository\Slots;
use Smbb\Passflow\Repository\Bookings;
use Smbb\Passflow\Repository\Lookup;
use Smbb\Passflow\Service\Booking;
use Smbb\Passflow\Service\History;
use Smbb\Passflow\Support\Prices;

defined('ABSPATH') || exit;

/**
 * Participant imprévu « paiement par SMS avant ajout » : une commande WooCommerce en attente de
 * paiement pour une place de l'atelier, lien de paiement par SMS et e-mail (avec connexion
 * automatique 10 minutes si le client a un compte). Rien n'apparaît sur la liste avant le
 * paiement ; dès qu'il est reçu, la réservation est créée et confirmée, mais
 * pas pointée : l'équipe la pointe à la main sur la feuille d'appel (et fait signer les documents).
 *
 * Méta de commande : _passflow_walkin (créneau). La commande porte aussi les métas de la vente sur
 * place (_passflow_onsite_slot, _passflow_onsite_by) : elle se retrouve dans « Ventes enregistrées »
 * avec « Renvoyer le lien ».
 */
final class Walkins
{
    const META = '_passflow_walkin';
    const META_PERSON = '_passflow_walkin_person';

    public static function hooks()
    {
        // Après Orders::onStatusChanged (priorité 10), qui crée et confirme les réservations.
        add_action('woocommerce_order_status_changed', array(__CLASS__, 'onPaid'), 20, 4);
    }

    /**
     * @param array<string,mixed>  $slot
     * @param array<string,string> $person first_name, last_name, email, phone
     * @return array{success:bool,message:string,order_id?:int}
     */
    public static function createOrder(array $slot, array $person, $customer_id = 0)
    {
        $d = 'smbb-passflow';
        $activity = Lookup::atelier($slot['activity_id']);
        $product = $activity && !empty($activity['product_id']) ? wc_get_product((int) $activity['product_id']) : null;

        if (!$product) {
            return array('success' => false, 'message' => __("Cet atelier n'est lié à aucun produit WooCommerce : impossible d'envoyer un lien de paiement.", $d));
        }

        if ($person['email'] === '' && $person['phone'] === '') {
            return array('success' => false, 'message' => __('Pour envoyer un lien de paiement, il faut un téléphone ou un e-mail.', $d));
        }

        $order = wc_create_order(array('created_via' => OnsiteSales::GATEWAY, 'customer_id' => (int) $customer_id));

        if (is_wp_error($order)) {
            return array('success' => false, 'message' => $order->get_error_message());
        }

        // Prix de la date, hors taxe pour WooCommerce (les taxes sont recalculées).
        $unit = Front::price($slot, $activity);
        $net = Prices::taxesOn() ? Prices::ht($unit) : $unit;
        $item_id = $order->add_product($product, 1, array('subtotal' => $net, 'total' => $net));
        $item = $item_id ? $order->get_item($item_id) : null;

        if ($item) {
            if (Prices::taxesOn()) {
                $item->set_tax_class(Prices::taxClass());
            }

            $item->add_meta_data(Orders::META_SLOT, (int) $slot['id'], true);
            $item->add_meta_data(Orders::META_PARTICIPANTS, array(array(
                'first_name' => $person['first_name'],
                'last_name' => $person['last_name'],
                'email' => $person['email'],
                'phone' => $person['phone'],
            )), true);
            $item->add_meta_data(__('Date', $d), Front::when($slot), true);
            $item->add_meta_data(__('Participant', $d), trim($person['first_name'] . ' ' . $person['last_name']), true);
            $item->save();
        }

        $order->set_billing_first_name($person['first_name']);
        $order->set_billing_last_name($person['last_name']);
        $order->set_billing_email($person['email']);
        $order->set_billing_phone($person['phone']);
        $order->update_meta_data(self::META, (int) $slot['id']);
        $order->update_meta_data(self::META_PERSON, array(
            'first_name' => $person['first_name'],
            'last_name' => $person['last_name'],
            'email' => $person['email'],
            'phone' => $person['phone'],
        ));
        $order->update_meta_data(OnsiteSales::META_SLOT, (int) $slot['id']);
        $order->update_meta_data(OnsiteSales::META_BY, get_current_user_id());
        $order->calculate_totals();
        $order->set_status('pending');
        $order->add_order_note(sprintf(
            __('Participant imprévu (%1$s) accepté sur place par %2$s, %3$s : lien de paiement envoyé. Il sera inscrit au paiement, à pointer ensuite.', $d),
            trim($person['first_name'] . ' ' . $person['last_name']),
            wp_get_current_user()->display_name,
            Lookup::slotLabel($slot)
        ));
        $order->save();

        $sent = OnsiteSales::sendLink($order, $slot);

        return array(
            'success' => true,
            'order_id' => (int) $order->get_id(),
            'message' => sprintf(__('%1$s : commande n° %2$s (%3$s) en attente de paiement. Il ou elle sera ajouté(e) à la liste dès le paiement, à pointer ensuite.', $d), trim($person['first_name'] . ' ' . $person['last_name']), $order->get_order_number(), OnsiteSales::total($order)) . ' ' . $sent,
        );
    }

    /**
     * Paiement reçu : les réservations de la commande (créées et confirmées juste avant par
     * Orders) passent « imprévu ». Elles restent confirmées,
     * non pointées : l'équipe pointe à la main.
     */
    public static function onPaid($order_id, $from, $to, $order = null)
    {
        unset($from);

        if (!in_array($to, array('processing', 'completed'), true)) {
            return;
        }

        $order = $order ?: wc_get_order($order_id);

        if (!$order || !$order->get_meta(self::META) || $order->get_meta('_passflow_walkin_done')) {
            return;
        }

        global $wpdb;
        $done = 0;
        $found = 0;

        foreach ($order->get_items('line_item') as $item) {
            foreach (array_map('intval', (array) $item->get_meta(Orders::META_BOOKINGS)) as $id) {
                $booking = $id ? Bookings::find($id) : null;

                if (!$booking || empty($booking['slot_id']) || $booking['status'] === 'cancelled') {
                    continue;
                }

                $found++;

                // Garde-fou : la place va sur la feuille d'appel d'où l'imprévu a été ajouté.
                $target = (int) $order->get_meta(self::META);

                if ($target && (int) $booking['slot_id'] !== $target && Slots::find($target)) {
                    $wpdb->update(Lookup::table('passflow_booking'), array('slot_id' => $target), array('id' => $id));
                    History::record($id, 'moved', sprintf(__('Placée sur la date de la feuille d\'appel (créneau %1$d au lieu de %2$d).', 'smbb-passflow'), $target, (int) $booking['slot_id']), $target, (int) $booking['slot_id']);
                    \Smbb\Passflow\Service\Capacity::refresh((int) $booking['slot_id']);
                    \Smbb\Passflow\Service\Capacity::refresh($target);
                    $order->add_order_note(sprintf(__('Passflow : réservation de l\'imprévu recalée du créneau %1$d vers le créneau %2$d (celui de la feuille d\'appel).', 'smbb-passflow'), (int) $booking['slot_id'], $target));
                    $booking['slot_id'] = $target;
                }

                $wpdb->update(Lookup::table('passflow_booking'), array('source' => 'walkin'), array('id' => $id));
                History::record($id, 'walkin', sprintf(__('Participant imprévu : paiement reçu (commande n° %s), à pointer.', 'smbb-passflow'), $order->get_order_number()), (int) $booking['slot_id']);
                $done++;
            }
        }

        // Aucune réservation créée par la commande : on la crée ici, sur la date de la feuille d'appel.
        if (!$found) {
            $id = self::createBooking($order);

            if ($id) {
                $done++;
            }
        }

        if ($done) {
            $order->update_meta_data('_passflow_walkin_done', 1);
            $order->add_order_note(__('Passflow : participant imprévu inscrit (à pointer sur la feuille d\'appel).', 'smbb-passflow'));
            $order->save();
        }
    }

    /**
     * Crée directement la réservation payée (confirmée, non pointée) d'une commande d'imprévu.
     *
     * @param \WC_Order $order
     */
    private static function createBooking($order)
    {
        $slot = Slots::find((int) $order->get_meta(self::META));

        if (!$slot) {
            return 0;
        }

        $person = $order->get_meta(self::META_PERSON);
        $person = is_array($person) ? $person : array(
            'first_name' => $order->get_billing_first_name(),
            'last_name' => $order->get_billing_last_name(),
            'email' => $order->get_billing_email(),
            'phone' => $order->get_billing_phone(),
        );
        $item_id = 0;

        foreach ($order->get_items('line_item') as $key => $item) {
            $item_id = (int) $key;
            break;
        }

        $id = Booking::insertBooking(array(
            'slot_id' => (int) $slot['id'],
            'activity_id' => (int) $slot['activity_id'],
            'customer_id' => $order->get_customer_id() ?: null,
            'first_name' => (string) $person['first_name'],
            'last_name' => (string) $person['last_name'],
            'email' => strtolower((string) $person['email']),
            'phone' => (string) $person['phone'],
            'source' => 'walkin',
            'order_id' => (int) $order->get_id(),
            'order_item_id' => $item_id ?: null,
            'price_paid' => round((float) $order->get_total(), 2),
            'status' => 'confirmed',
        ));

        if (!$id) {
            return 0;
        }

        if ($item_id) {
            $item = $order->get_item($item_id);

            if ($item) {
                $item->update_meta_data(Orders::META_SLOT, (int) $slot['id']);
                $item->update_meta_data(Orders::META_BOOKINGS, array($id));
                $item->save();
            }
        }

        History::record($id, 'walkin', sprintf(__('Participant imprévu : paiement reçu (commande n° %s), inscrit, à pointer.', 'smbb-passflow'), $order->get_order_number()), (int) $slot['id']);
        \Smbb\Passflow\Service\Capacity::refresh((int) $slot['id']);
        \Smbb\Passflow\Service\Notifier::onConfirmed($id);

        return $id;
    }

    /**
     * Imprévus d'une date en attente de paiement.
     *
     * @return \WC_Order[]
     */
    public static function pending($slot_id)
    {
        if (!Module::active()) {
            return array();
        }

        return wc_get_orders(array(
            'limit' => 50,
            'status' => array('pending', 'failed'),
            'meta_key' => self::META, // phpcs:ignore WordPress.DB.SlowDBQuery
            'meta_value' => (int) $slot_id, // phpcs:ignore WordPress.DB.SlowDBQuery
        ));
    }
}
