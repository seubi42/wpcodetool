<?php

namespace Smbb\Tomo\WooCommerce;

use Smbb\Tomo\Repository\Creneaux;
use Smbb\Tomo\Repository\Inscriptions;
use Smbb\Tomo\Repository\Lookup;
use Smbb\Tomo\Service\Booking;
use Smbb\Tomo\Service\History;
use Smbb\Tomo\Support\Prices;

defined('ABSPATH') || exit;

/**
 * Participant imprévu « paiement par SMS avant ajout » : une commande WooCommerce en attente de
 * paiement pour une place de l'atelier, lien de paiement par SMS et e-mail (avec connexion
 * automatique 10 minutes si le client a un compte). Rien n'apparaît sur la liste avant le
 * paiement ; dès qu'il est reçu, la réservation est créée, confirmée et pointée présente.
 *
 * Méta de commande : _tomo_walkin (créneau). La commande porte aussi les métas de la vente sur
 * place (_tomo_onsite_creneau, _tomo_onsite_by) : elle se retrouve dans « Ventes enregistrées »
 * avec « Renvoyer le lien ».
 */
final class Walkins
{
    const META = '_tomo_walkin';
    const META_PERSON = '_tomo_walkin_person';

    public static function hooks()
    {
        // Après Orders::onStatusChanged (priorité 10), qui crée et confirme les réservations.
        add_action('woocommerce_order_status_changed', array(__CLASS__, 'onPaid'), 20, 4);
    }

    /**
     * @param array<string,mixed>  $creneau
     * @param array<string,string> $person first_name, last_name, email, phone
     * @return array{success:bool,message:string,order_id?:int}
     */
    public static function createOrder(array $creneau, array $person, $customer_id = 0)
    {
        $d = 'smbb-tomo';
        $atelier = Lookup::atelier($creneau['atelier_id']);
        $product = $atelier && !empty($atelier['product_id']) ? wc_get_product((int) $atelier['product_id']) : null;

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
        $unit = Front::price($creneau, $atelier);
        $net = Prices::taxesOn() ? Prices::ht($unit) : $unit;
        $item_id = $order->add_product($product, 1, array('subtotal' => $net, 'total' => $net));
        $item = $item_id ? $order->get_item($item_id) : null;

        if ($item) {
            if (Prices::taxesOn()) {
                $item->set_tax_class(Prices::taxClass());
            }

            $item->add_meta_data(Orders::META_CRENEAU, (int) $creneau['id'], true);
            $item->add_meta_data(Orders::META_PARTICIPANTS, array(array(
                'first_name' => $person['first_name'],
                'last_name' => $person['last_name'],
                'email' => $person['email'],
                'phone' => $person['phone'],
            )), true);
            $item->add_meta_data(__('Date', $d), Front::when($creneau), true);
            $item->add_meta_data(__('Participant', $d), trim($person['first_name'] . ' ' . $person['last_name']), true);
            $item->save();
        }

        $order->set_billing_first_name($person['first_name']);
        $order->set_billing_last_name($person['last_name']);
        $order->set_billing_email($person['email']);
        $order->set_billing_phone($person['phone']);
        $order->update_meta_data(self::META, (int) $creneau['id']);
        $order->update_meta_data(self::META_PERSON, array(
            'first_name' => $person['first_name'],
            'last_name' => $person['last_name'],
            'email' => $person['email'],
            'phone' => $person['phone'],
        ));
        $order->update_meta_data(OnsiteSales::META_CRENEAU, (int) $creneau['id']);
        $order->update_meta_data(OnsiteSales::META_BY, get_current_user_id());
        $order->calculate_totals();
        $order->set_status('pending');
        $order->add_order_note(sprintf(
            __('Participant imprévu (%1$s) accepté sur place par %2$s, %3$s : lien de paiement envoyé. Il sera inscrit et pointé présent au paiement.', $d),
            trim($person['first_name'] . ' ' . $person['last_name']),
            wp_get_current_user()->display_name,
            Lookup::creneauLabel($creneau)
        ));
        $order->save();

        $sent = OnsiteSales::sendLink($order, $creneau);

        return array(
            'success' => true,
            'order_id' => (int) $order->get_id(),
            'message' => sprintf(__('%1$s : commande n° %2$s (%3$s) en attente de paiement. Il ou elle sera ajouté(e) et pointé(e) présent(e) dès le paiement.', $d), trim($person['first_name'] . ' ' . $person['last_name']), $order->get_order_number(), OnsiteSales::total($order)) . ' ' . $sent,
        );
    }

    /**
     * Paiement reçu : les réservations de la commande (créées et confirmées juste avant par
     * Orders) passent « imprévu » et présentes.
     */
    public static function onPaid($order_id, $from, $to, $order = null)
    {
        unset($from);

        if (!in_array($to, array('processing', 'completed'), true)) {
            return;
        }

        $order = $order ?: wc_get_order($order_id);

        if (!$order || !$order->get_meta(self::META) || $order->get_meta('_tomo_walkin_done')) {
            return;
        }

        global $wpdb;
        $done = 0;
        $found = 0;

        foreach ($order->get_items('line_item') as $item) {
            foreach (array_map('intval', (array) $item->get_meta(Orders::META_INSCRIPTIONS)) as $id) {
                $inscription = $id ? Inscriptions::find($id) : null;

                if (!$inscription || empty($inscription['creneau_id']) || $inscription['status'] === 'cancelled') {
                    continue;
                }

                $found++;

                // Garde-fou : la place va sur la feuille d'appel d'où l'imprévu a été ajouté.
                $target = (int) $order->get_meta(self::META);

                if ($target && (int) $inscription['creneau_id'] !== $target && Creneaux::find($target)) {
                    $wpdb->update(Lookup::table('tomo_inscription'), array('creneau_id' => $target), array('id' => $id));
                    History::record($id, 'moved', sprintf(__('Placée sur la date de la feuille d\'appel (créneau %1$d au lieu de %2$d).', 'smbb-tomo'), $target, (int) $inscription['creneau_id']), $target, (int) $inscription['creneau_id']);
                    \Smbb\Tomo\Service\Capacity::refresh((int) $inscription['creneau_id']);
                    \Smbb\Tomo\Service\Capacity::refresh($target);
                    $order->add_order_note(sprintf(__('Tomo : réservation de l\'imprévu recalée du créneau %1$d vers le créneau %2$d (celui de la feuille d\'appel).', 'smbb-tomo'), (int) $inscription['creneau_id'], $target));
                    $inscription['creneau_id'] = $target;
                }

                $wpdb->update(Lookup::table('tomo_inscription'), array('source' => 'walkin'), array('id' => $id));
                Booking::presence($id, 'present');
                History::record($id, 'walkin', sprintf(__('Participant imprévu : paiement reçu (commande n° %s), pointé présent.', 'smbb-tomo'), $order->get_order_number()), (int) $inscription['creneau_id']);
                $done++;
            }
        }

        // Aucune réservation créée par la commande : on la crée ici, sur la date de la feuille d'appel.
        if (!$found) {
            $id = self::createInscription($order);

            if ($id) {
                $done++;
            }
        }

        if ($done) {
            $order->update_meta_data('_tomo_walkin_done', 1);
            $order->add_order_note(__('Tomo : participant imprévu inscrit et pointé présent.', 'smbb-tomo'));
            $order->save();
        }
    }

    /**
     * Crée directement la réservation payée et présente d'une commande d'imprévu.
     *
     * @param \WC_Order $order
     */
    private static function createInscription($order)
    {
        $creneau = Creneaux::find((int) $order->get_meta(self::META));

        if (!$creneau) {
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

        $id = Booking::insertInscription(array(
            'creneau_id' => (int) $creneau['id'],
            'atelier_id' => (int) $creneau['atelier_id'],
            'customer_id' => $order->get_customer_id() ?: null,
            'first_name' => (string) $person['first_name'],
            'last_name' => (string) $person['last_name'],
            'email' => strtolower((string) $person['email']),
            'phone' => (string) $person['phone'],
            'source' => 'walkin',
            'order_id' => (int) $order->get_id(),
            'order_item_id' => $item_id ?: null,
            'price_paid' => round((float) $order->get_total(), 2),
            'status' => 'present',
            'checked_at' => current_time('mysql'),
        ));

        if (!$id) {
            return 0;
        }

        if ($item_id) {
            $item = $order->get_item($item_id);

            if ($item) {
                $item->update_meta_data(Orders::META_CRENEAU, (int) $creneau['id']);
                $item->update_meta_data(Orders::META_INSCRIPTIONS, array($id));
                $item->save();
            }
        }

        History::record($id, 'walkin', sprintf(__('Participant imprévu : paiement reçu (commande n° %s), inscrit et pointé présent.', 'smbb-tomo'), $order->get_order_number()), (int) $creneau['id']);
        \Smbb\Tomo\Service\Capacity::refresh((int) $creneau['id']);
        \Smbb\Tomo\Service\Notifier::onConfirmed($id);

        return $id;
    }

    /**
     * Imprévus d'une date en attente de paiement.
     *
     * @return \WC_Order[]
     */
    public static function pending($creneau_id)
    {
        if (!Module::active()) {
            return array();
        }

        return wc_get_orders(array(
            'limit' => 50,
            'status' => array('pending', 'failed'),
            'meta_key' => self::META, // phpcs:ignore WordPress.DB.SlowDBQuery
            'meta_value' => (int) $creneau_id, // phpcs:ignore WordPress.DB.SlowDBQuery
        ));
    }
}
