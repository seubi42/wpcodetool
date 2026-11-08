<?php

namespace Smbb\Passflow\WooCommerce;

use Smbb\Passflow\Service\Notifier;

defined('ABSPATH') || exit;

/**
 * Remboursement d'une ligne de vente sur place en bon d'achat (retour produit) :
 * - un remboursement WooCommerce de la ligne (quantité choisie), sans toucher au paiement d'origine,
 *   produits remis en stock : le chiffre d'affaires de la caisse baisse d'autant ;
 * - un code promo du même montant, usage unique, réservé à l'e-mail du client (son compte), libellé
 *   « Remboursement commande n° … » : il apparaît aussi dans Bons cadeaux ;
 * - le code part par SMS et e-mail, avec un lien qui connecte le client et applique le bon.
 *
 * Méta de ligne : _passflow_voucher_refunds (liste des bons émis pour la ligne).
 */
final class Refunds
{
    const META_ITEM = '_passflow_voucher_refunds';
    const META_COUPON_ORDER = '_passflow_refund_order';

    /**
     * Quantité encore remboursable d'une ligne.
     *
     * @param \WC_Order              $order
     * @param \WC_Order_Item_Product $item
     */
    public static function refundableQty($order, $item)
    {
        return max(0, (int) $item->get_quantity() + (int) $order->get_qty_refunded_for_item($item->get_id()));
    }

    /**
     * Rembourse en un seul bon d'achat les lignes rendues ($lines : id de ligne => quantité rendue).
     *
     * @param \WC_Order       $order
     * @param array<int,int>  $lines
     * @return array{success:bool,message:string}
     */
    public static function toVoucher($order, array $lines, $send = true, $slot = null)
    {
        $d = 'smbb-passflow';
        $decimals = wc_get_price_decimals();

        if (!$order->is_paid()) {
            return array('success' => false, 'message' => __("Cette vente n'est pas payée : rien à rembourser.", $d));
        }

        $refund_lines = array();
        $picked = array();
        $amount = 0.0;

        foreach ($lines as $item_id => $qty) {
            $item = $order->get_item((int) $item_id);
            $qty = (int) $qty;

            if (!$item instanceof \WC_Order_Item_Product || $qty < 1) {
                continue;
            }

            $left = self::refundableQty($order, $item);

            if ($qty > $left) {
                return array('success' => false, 'message' => $left ? sprintf(__('%1$s : quantité à rembourser entre 1 et %2$d.', $d), $item->get_name(), $left) : sprintf(__('%s est déjà entièrement remboursé.', $d), $item->get_name()));
            }

            // Part de la ligne, taxes comprises, au prorata de la quantité.
            $ratio = $qty / max(1, (int) $item->get_quantity());
            $net = round((float) $item->get_total() * $ratio, $decimals);
            $taxes = array();
            $item_taxes = $item->get_taxes();

            foreach ((array) (isset($item_taxes['total']) ? $item_taxes['total'] : array()) as $tax_id => $tax) {
                $taxes[$tax_id] = round((float) $tax * $ratio, $decimals);
            }

            $refund_lines[(int) $item->get_id()] = array('qty' => $qty, 'refund_total' => $net, 'refund_tax' => $taxes);
            $picked[(int) $item->get_id()] = array('item' => $item, 'qty' => $qty, 'amount' => round($net + array_sum($taxes), $decimals));
            $amount += $net + array_sum($taxes);
        }

        if (!$picked) {
            return array('success' => false, 'message' => __('Cochez au moins un article rendu.', $d));
        }

        $amount = min(round($amount, $decimals), (float) $order->get_remaining_refund_amount());

        if ($amount <= 0) {
            return array('success' => false, 'message' => __('Plus rien à rembourser sur cette commande.', $d));
        }

        $person = array(
            'first_name' => $order->get_billing_first_name(),
            'last_name' => $order->get_billing_last_name(),
            'email' => strtolower((string) $order->get_billing_email()),
            'phone' => $order->get_billing_phone(),
        );
        $names = array();

        foreach ($picked as $line) {
            $names[] = $line['item']->get_name() . ' × ' . $line['qty'];
        }

        $label = sprintf(__('Remboursement commande n° %1$s : %2$s', $d), $order->get_order_number(), implode(', ', $names));
        $who = trim($person['first_name'] . ' ' . $person['last_name']);

        // 1. Le bon (code promo WooCommerce, usage unique).
        $created = GiftCards::create(array(
            'amount' => $amount,
            'recipient' => $label . ($who !== '' ? ' — ' . $who : ''),
            'email' => is_email($person['email']) ? $person['email'] : '',
        ));

        if (empty($created['success'])) {
            return $created;
        }

        $coupon = new \WC_Coupon((int) $created['id']);

        // Réservé au compte du client : son e-mail, celui de la commande.
        if (is_email($person['email'])) {
            $coupon->set_email_restrictions(array($person['email']));
        }

        $coupon->update_meta_data(self::META_COUPON_ORDER, (int) $order->get_id());
        $coupon->save();
        $code = strtoupper((string) $coupon->get_code());

        // 2. Le remboursement WooCommerce des lignes (pas de remboursement du paiement d'origine).
        $refund = wc_create_refund(array(
            'order_id' => (int) $order->get_id(),
            'amount' => $amount,
            'reason' => sprintf(__("Remboursé en bon d'achat %1\$s (%2\$s)", $d), $code, implode(', ', $names)),
            'line_items' => $refund_lines,
            'refund_payment' => false,
            'restock_items' => true,
        ));

        if (is_wp_error($refund)) {
            wp_delete_post((int) $coupon->get_id(), true);

            return array('success' => false, 'message' => $refund->get_error_message());
        }

        foreach ($picked as $line) {
            $done = $line['item']->get_meta(self::META_ITEM);
            $done = is_array($done) ? $done : array();
            $done[] = array('code' => $code, 'qty' => $line['qty'], 'amount' => $line['amount'], 'date' => current_time('mysql'), 'by' => get_current_user_id());
            $line['item']->update_meta_data(self::META_ITEM, $done);
            $line['item']->save();
        }

        $money = OnsiteSales::money($amount);
        $sent = '';

        if ($send) {
            $results = Notifier::toCustomer('refund_voucher', $person, $slot, array(
                'code_bon' => $code,
                'montant_bon' => $money,
                'lien_bon' => \Smbb\Passflow\Support\LoginLink::create((int) $order->get_customer_id(), GiftCards::link($code)),
                'commande' => (string) $order->get_order_number(),
                'produits' => implode(', ', $names),
            ));
            $ok = array();

            foreach ($results as $result) {
                if (in_array($result['status'], array('sent', 'test'), true)) {
                    $ok[] = $result['channel'] === 'sms' ? __('SMS', $d) : __('e-mail', $d);
                }
            }

            $sent = $ok ? sprintf(__('Bon envoyé (%s).', $d), implode(', ', $ok)) : __("Le bon n'a pas pu partir : vérifiez le téléphone, l'e-mail et les réglages d'envoi.", $d);
        }

        $order->add_order_note(sprintf(
            __("Passflow : retour de %1\$s, remboursé en bon d'achat %2\$s de %3\$s par %4\$s. %5\$s", $d),
            implode(', ', $names),
            $code,
            $money,
            wp_get_current_user()->display_name,
            $sent
        ));

        return array('success' => true, 'message' => trim(sprintf(__("Bon d'achat %1\$s de %2\$s créé (remboursement commande n° %3\$s).", $d), $code, $money, $order->get_order_number()) . ' ' . $sent));
    }
}
