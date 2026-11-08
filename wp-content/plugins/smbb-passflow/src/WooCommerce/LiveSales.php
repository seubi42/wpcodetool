<?php

namespace Smbb\Passflow\WooCommerce;

use Smbb\Passflow\Admin\Cashdesk;
use Smbb\Passflow\Admin\View;
use Smbb\Passflow\Repository\Lookup;
use Smbb\Passflow\Repository\Slots;
use Smbb\Passflow\Support\Roles;

defined('ABSPATH') || exit;

/**
 * Ventes en direct : Passflow habille la bulle « nouvelle commande » de CodeTool (Live\LiveFeed).
 *
 * - réservation en ligne : « Nouvelle réservation », client · atelier · date · nombre de places,
 *   lien vers la fiche de la date ;
 * - vente en caisse payée sur place : « Vente en caisse », moyen de paiement, lien vers la caisse.
 *   Le vendeur ne la reçoit pas (c'est lui qui vient d'encaisser) ;
 * - lien de paiement réglé (vente à payer en ligne, participant imprévu) : « Paiement reçu »,
 *   montré aussi à l'intervenant qui a envoyé le lien, même sans droit sur les commandes.
 *
 * Gestionnaires Passflow : toutes les ventes Passflow. Intervenants : seulement les liens qu'ils ont envoyés.
 * Une commande sans rien de Passflow garde la bulle de CodeTool.
 */
final class LiveSales
{
    public static function hooks()
    {
        add_filter('smbb_wpcodetool_live_order_event', array(__CLASS__, 'event'), 10, 2);
        add_filter('smbb_wpcodetool_live_capabilities', array(__CLASS__, 'capabilities'));
    }

    /**
     * @param array<int,string> $caps
     * @return array<int,string>
     */
    public static function capabilities($caps)
    {
        $caps = (array) $caps;
        $caps[] = Roles::CAP_MANAGE;
        $caps[] = Roles::CAP_HOST; // intervenants : leurs liens de paiement réglés

        return $caps;
    }

    /**
     * @param array<string,mixed>|false $event
     * @param \WC_Order                 $order
     * @return array<string,mixed>|false
     */
    public static function event($event, $order)
    {
        if (!is_array($event) || !$order instanceof \WC_Order) {
            return $event;
        }

        $d = 'smbb-passflow';
        $onsite_slot = (int) $order->get_meta(OnsiteSales::META_SLOT);
        $seller = (int) $order->get_meta(OnsiteSales::META_BY);
        $slots = array();
        $places = 0;

        foreach ($order->get_items('line_item') as $item) {
            $slot_id = (int) $item->get_meta(Orders::META_SLOT);

            if ($slot_id) {
                $slots[$slot_id] = true;
                $places += max(1, (int) $item->get_quantity());
            }
        }

        if (!$onsite_slot && !$slots) {
            return $event;
        }

        $slot_id = $onsite_slot ?: (int) key($slots);
        $slot = Slots::find($slot_id);
        $customer = \Smbb\WpCodeTool\Live\LiveFeed::customerName($order);
        $event['caps'] = array_values(array_unique(array_merge((array) (isset($event['caps']) ? $event['caps'] : array()), array(Roles::CAP_MANAGE))));
        $event['source'] = 'passflow';

        if ($onsite_slot) {
            $activity = $slot ? Lookup::activityName($slot['activity_id']) : '';
            $event['url'] = Cashdesk::url($onsite_slot);

            if ($order->get_payment_method() === OnsiteSales::GATEWAY) {
                // Payé sur place : le vendeur vient de le faire, les autres sont prévenus.
                $methods = OnsiteSales::methods();
                $method = (string) $order->get_meta(OnsiteSales::META_METHOD);
                $event['title'] = __('Vente en caisse', $d);
                $event['badge'] = isset($methods[$method]) ? $methods[$method] : __('Sur place', $d);
                $event['icon'] = 'cashdesk';
                $event['text'] = implode(' · ', array_filter(array($customer, self::items($event, $customer), $activity)));
                $event['user'] = $seller ?: (isset($event['user']) ? $event['user'] : 0);

                return $event;
            }

            // Lien de paiement réglé par le client : le vendeur (intervenant) le voit aussi.
            $event['title'] = __('Paiement reçu', $d);
            $event['badge'] = __('Lien de paiement', $d);
            $event['icon'] = 'link';
            $event['text'] = implode(' · ', array_filter(array(
                $customer,
                $order->get_meta(Walkins::META) ? __('place à l\'atelier', $d) : self::items($event, $customer),
                $activity,
            )));
            $event['users'] = $seller ? array($seller) : array();
            $event['user'] = 0;

            return $event;
        }

        // Réservation en ligne.
        $event['title'] = count($slots) > 1 ? __('Nouvelle réservation (plusieurs dates)', $d) : __('Nouvelle réservation', $d);
        $event['badge'] = __('En ligne', $d);
        $event['icon'] = 'ticket';
        $event['text'] = implode(' · ', array_filter(array(
            $customer,
            $slot ? Lookup::slotLabel($slot) : '',
            sprintf(_n('%d place', '%d places', $places, $d), $places),
        )));
        $event['url'] = View::url('passflow_slot', array('view' => 'details', 'id' => $slot_id));

        return $event;
    }

    /** Articles de la bulle d'origine (« client · articles ») sans le nom du client. */
    private static function items(array $event, $customer)
    {
        $text = isset($event['text']) ? (string) $event['text'] : '';
        $prefix = $customer . ' · ';

        return $customer !== '' && strpos($text, $prefix) === 0 ? substr($text, strlen($prefix)) : ($text === $customer ? '' : $text);
    }
}
