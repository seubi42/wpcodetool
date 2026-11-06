<?php

namespace Smbb\Tomo\WooCommerce;

use Smbb\Tomo\Repository\Creneaux;
use Smbb\Tomo\Repository\Inscriptions;
use Smbb\Tomo\Repository\Lookup;
use Smbb\Tomo\Service\Booking;
use Smbb\Tomo\Service\Capacity;
use Smbb\Tomo\Service\History;
use Smbb\Tomo\Service\Notifier;
use Smbb\Tomo\Service\Waitlist;
use Smbb\Tomo\Support\Input;

defined('ABSPATH') || exit;

/**
 * Commande WooCommerce → réservations Tomo.
 *
 * - commande passée : une réservation « à confirmer » par participant, qui bloque la place ;
 * - commande payée (en cours / terminée) : réservations confirmées, un seul message au client ;
 * - commande annulée, remboursée ou échouée : réservations annulées, places rendues à la liste d'attente ;
 * - paiement jamais finalisé : places libérées au bout d'une heure par la tâche planifiée.
 */
final class Orders
{
    const META_CRENEAU = '_tomo_creneau_id';
    const META_PARTICIPANTS = '_tomo_participants';
    const META_INSCRIPTIONS = '_tomo_inscription_ids';
    const META_RELEASED = '_tomo_released_ids';

    /** Délai avant de libérer les places d'une commande non payée, en minutes. */
    const ABANDON_MINUTES = 60;

    public static function hooks()
    {
        add_action('woocommerce_checkout_order_processed', array(__CLASS__, 'onCheckout'), 10, 3);
        add_action('woocommerce_store_api_checkout_order_processed', array(__CLASS__, 'onStoreApiCheckout'), 10, 1);
        add_action('woocommerce_order_status_changed', array(__CLASS__, 'onStatusChanged'), 10, 4);
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
                throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException('tomo_seats_unavailable', $e->getMessage(), 409);
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
            $creneau_id = (int) $item->get_meta(self::META_CRENEAU);

            if (!$creneau_id || $item->get_meta(self::META_INSCRIPTIONS)) {
                continue;
            }

            $lines[] = array($item, $creneau_id);
            $seats[$creneau_id] = (isset($seats[$creneau_id]) ? $seats[$creneau_id] : 0) + max(1, (int) $item->get_quantity());
        }

        if (!$lines) {
            return;
        }

        // Un verrou par date, toujours pris dans le même ordre : deux paiements sur la dernière place
        // passent l'un après l'autre, et le second voit la date complète.
        ksort($seats);
        $locked = array();

        foreach (array_keys($seats) as $creneau_id) {
            if (Capacity::lock($creneau_id)) {
                $locked[] = $creneau_id;
            }
        }

        try {
            if ($strict) {
                foreach ($seats as $creneau_id => $count) {
                    $error = Capacity::checkSale($creneau_id, $count, strtolower((string) $order->get_billing_email()));

                    if ($error !== '') {
                        $creneau = Creneaux::find($creneau_id);
                        $label = $creneau ? Lookup::creneauLabel($creneau) : '';
                        $order->add_order_note(sprintf(__('Tomo : commande arrêtée avant paiement, plus assez de places (%s).', 'smbb-tomo'), trim($label . ' : ' . $error, ' :')));

                        throw new \Exception(trim($label . ' : ' . $error, ' :') . ' ' . __('Modifiez votre panier puis validez de nouveau.', 'smbb-tomo'));
                    }
                }
            }

            self::createAll($order, $lines);
        } finally {
            foreach ($locked as $creneau_id) {
                Capacity::unlock($creneau_id);
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
            list($item, $creneau_id) = $line;
            $creneau = Creneaux::find($creneau_id);

            if (!$creneau) {
                continue;
            }

            $ids = self::createInscriptions($order, $item, $creneau);
            $item->update_meta_data(self::META_INSCRIPTIONS, $ids);
            $item->save();
            $created += count($ids);

            Capacity::refresh($creneau_id);

            if (!\Smbb\Tomo\Service\Passes::isPass($creneau) && Capacity::booked($creneau_id) > (int) $creneau['capacity']) {
                $order->add_order_note(sprintf(__('Tomo : la jauge de %s est dépassée, vérifiez les places.', 'smbb-tomo'), Lookup::creneauLabel($creneau)));
            }
        }

        if ($created) {
            $order->add_order_note(sprintf(_n('Tomo : %d réservation créée, à confirmer au paiement.', 'Tomo : %d réservations créées, à confirmer au paiement.', $created, 'smbb-tomo'), $created));

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

        if (!$order || !self::inscriptionIds($order, true)) {
            // Commande créée à la main dans l'admin avec des lignes Tomo : on réserve maintenant.
            if ($order && in_array($to, array('processing', 'completed', 'on-hold'), true)) {
                self::reserve($order);
            }

            return;
        }

        if (in_array($to, array('processing', 'completed'), true)) {
            self::confirm($order);
        } elseif (in_array($to, array('cancelled', 'refunded', 'failed'), true)) {
            self::release($order, $to === 'refunded' ? __('Commande remboursée.', 'smbb-tomo') : ($to === 'failed' ? __('Paiement échoué.', 'smbb-tomo') : __('Commande annulée.', 'smbb-tomo')));
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

        foreach (self::inscriptionIds($order) as $item_id => $ids) {
            $item = $order->get_item($item_id);
            $released = $item ? array_map('intval', (array) $item->get_meta(self::META_RELEASED)) : array();

            foreach ($ids as $id) {
                $inscription = Inscriptions::find($id);

                if (!$inscription || empty($inscription['creneau_id'])) {
                    continue;
                }

                if ($inscription['status'] === 'pending') {
                    Booking::presence($id, 'confirmed');
                } elseif ($inscription['status'] === 'cancelled' && in_array((int) $id, $released, true)) {
                    if (Capacity::check($inscription['creneau_id'], 1, $id) !== '') {
                        $over[(int) $inscription['creneau_id']] = true;
                    }

                    self::update($id, array('status' => 'confirmed', 'checked_at' => null));
                    History::record($id, 'confirmed', __('Paiement reçu après libération de la place : réservation reprise.', 'smbb-tomo'), (int) $inscription['creneau_id']);
                    Capacity::refresh($inscription['creneau_id']);
                } else {
                    continue;
                }

                // Un message par participant ayant ses propres coordonnées ; plusieurs places sur la même adresse = un seul message.
                $fresh = Inscriptions::find($id);
                $address = strtolower(trim((string) ($fresh ? $fresh['email'] : '') . '|' . (string) ($fresh ? $fresh['phone'] : '')));
                $first[$item_id . '|' . $address] = isset($first[$item_id . '|' . $address]) ? $first[$item_id . '|' . $address] : (int) $id;

                self::closeWaitlist($inscription, $id);
            }
        }

        Notifier::unmute();

        // Un message par date réservée et par adresse (acheteur, ou chaque participant qui a donné la sienne).
        foreach ($first as $id) {
            Notifier::onConfirmed($id);
        }

        foreach (array_keys($over) as $creneau_id) {
            $creneau = Creneaux::find($creneau_id);
            $order->add_order_note(sprintf(__('Tomo : paiement reçu après libération des places, la jauge de %s est dépassée.', 'smbb-tomo'), $creneau ? Lookup::creneauLabel($creneau) : '#' . $creneau_id));
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

        $creneaux = array();
        $count = 0;

        Notifier::mute();

        foreach (self::inscriptionIds($order) as $item_id => $ids) {
            $released = array();

            foreach ($ids as $id) {
                $inscription = Inscriptions::find($id);

                if (!$inscription || $inscription['status'] === 'cancelled') {
                    continue;
                }

                $result = Booking::cancel($id, false, $reason);

                if ($result['success']) {
                    $count++;
                    $released[] = (int) $id;

                    if (!empty($inscription['creneau_id'])) {
                        $creneaux[(int) $inscription['creneau_id']] = true;
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

        foreach (array_keys($creneaux) as $creneau_id) {
            Waitlist::notifyNext($creneau_id);
        }

        if ($count) {
            $order->add_order_note(sprintf(_n('Tomo : %d réservation annulée.', 'Tomo : %d réservations annulées.', $count, 'smbb-tomo'), $count) . ' ' . $reason);
        }

        return $count;
    }

    /**
     * Part payée d'une inscription pour une journée : son prix, ou pour un jour de pass le prix du
     * pass divisé par son nombre de jours. 0 si la commande n'est pas payée.
     */
    public static function dayShare(array $inscription)
    {
        $order = !empty($inscription['order_id']) && function_exists('wc_get_order') ? wc_get_order((int) $inscription['order_id']) : null;

        if (!$order || !$order->is_paid()) {
            return 0.0;
        }

        if (!empty($inscription['pass_id'])) {
            $mother = Inscriptions::find((int) $inscription['pass_id']);
            $pass = $mother && !empty($mother['creneau_id']) ? Creneaux::find((int) $mother['creneau_id']) : null;
            $days = $pass ? count(\Smbb\Tomo\Service\Passes::dayIds($pass)) : 0;

            return $mother && $days ? round((float) $mother['price_paid'] / $days, 2) : 0.0;
        }

        return (float) $inscription['price_paid'];
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
    public static function refundLine(array $inscription)
    {
        $amount = self::dayShare($inscription);

        if ($amount <= 0) {
            return '';
        }

        return sprintf(__('Remboursement de %1$s à convenir avec le client (commande n° %2$s).', 'smbb-tomo'), self::money($amount), (string) (int) $inscription['order_id']);
    }

    /**
     * Journée annulée : une note par commande WooCommerce concernée, avec les participants et le
     * montant à rembourser (à convenir avec le client, rien n'est remboursé automatiquement).
     *
     * @param array<int,array<string,mixed>> $inscriptions
     */
    public static function noteDayCancelled(array $creneau, array $inscriptions, $reason = '')
    {
        if (!function_exists('wc_get_order')) {
            return;
        }

        $by_order = array();

        foreach ($inscriptions as $inscription) {
            if (!empty($inscription['order_id'])) {
                $by_order[(int) $inscription['order_id']][] = $inscription;
            }
        }

        $date = !empty($creneau['start_date']) ? date_i18n('l j F Y', strtotime($creneau['start_date'] . ' 12:00:00')) : '';
        $atelier = Lookup::atelierName($creneau['atelier_id']);

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

            $note = sprintf(__('Tomo : journée du %1$s annulée (%2$s), pour %3$s.', 'smbb-tomo'), $date, $atelier, implode(', ', $names));
            $note .= ' ' . ($amount > 0
                ? sprintf(__('Remboursement de %s à convenir avec le client.', 'smbb-tomo'), self::money($amount))
                : __('Commande pas encore payée : rien à rembourser.', 'smbb-tomo'));

            if ($reason !== '') {
                $note .= ' ' . sprintf(__('Motif : %s', 'smbb-tomo'), $reason);
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
            'SELECT DISTINCT order_id FROM ' . Lookup::table('tomo_inscription')
            . " WHERE deleted_flag = 0 AND source = 'shop' AND status = 'pending' AND order_id IS NOT NULL AND creation_date < %s",
            $limit
        ));
        $count = 0;

        foreach ($order_ids as $order_id) {
            $order = self::order((int) $order_id);

            if ($order && in_array($order->get_status(), array('pending', 'checkout-draft', 'failed'), true)) {
                $count += self::release($order, __('Paiement non finalisé, place libérée.', 'smbb-tomo'));
            }
        }

        return $count;
    }

    // ------------------------------------------------------------------
    // Interne
    // ------------------------------------------------------------------

    /**
     * @param \WC_Order               $order
     * @param \WC_Order_Item_Product $item
     *
     * @return int[]
     */
    private static function createInscriptions($order, $item, array $creneau)
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
                $last_name = (string) $order->get_billing_last_name() . ($i > 0 ? ' (' . sprintf(__('place %d', 'smbb-tomo'), $i + 1) . ')' : '');
            }

            $id = Booking::insertInscription(array(
                'creneau_id' => (int) $creneau['id'],
                'atelier_id' => (int) $creneau['atelier_id'],
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
                History::record($id, 'created', sprintf(__('Commande en ligne n° %s, en attente du paiement.', 'smbb-tomo'), $order->get_order_number()), (int) $creneau['id']);
                \Smbb\Tomo\Service\Passes::createChildren($id); // pass : une place par jour inclus.
            }
        }

        return $ids;
    }

    /**
     * Ids des réservations par ligne de commande.
     *
     * @return array<int,int[]>
     */
    private static function inscriptionIds($order, $any = false)
    {
        $result = array();

        foreach ($order->get_items('line_item') as $item_id => $item) {
            $ids = $item->get_meta(self::META_INSCRIPTIONS);

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
    private static function closeWaitlist(array $inscription, $inscription_id)
    {
        global $wpdb;

        if (empty($inscription['email'])) {
            return;
        }

        $wpdb->query($wpdb->prepare(
            'UPDATE ' . Lookup::table('tomo_attente')
            . " SET status = 'booked', inscription_id = %d, lastupdate_date = %s WHERE deleted_flag = 0 AND creneau_id = %d AND LOWER(email) = %s AND status IN ('waiting', 'notified')",
            (int) $inscription_id,
            current_time('mysql'),
            (int) $inscription['creneau_id'],
            strtolower($inscription['email'])
        ));
    }

    private static function update($id, array $data)
    {
        global $wpdb;

        $data['lastupdate_date'] = current_time('mysql');
        $result = $wpdb->update(Lookup::table('tomo_inscription'), $data, array('id' => (int) $id));

        if ($result !== false) {
            \Smbb\Tomo\Service\Passes::cascade($id, $data);
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
