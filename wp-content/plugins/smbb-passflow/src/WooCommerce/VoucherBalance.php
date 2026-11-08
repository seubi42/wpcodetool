<?php

namespace Smbb\Passflow\WooCommerce;

defined('ABSPATH') || exit;

/**
 * Bons Passflow (bons cadeaux et bons de remboursement) utilisables en plusieurs fois.
 *
 * WooCommerce plafonne une remise fixe au montant du panier : un bon de 85 € sur une commande de
 * 50 € n'en consomme que 50. Au paiement de la commande (site, lien SMS, page « Payer la
 * commande »), on garde la différence sur le bon :
 * - montant du code promo = solde restant, et une utilisation de plus autorisée ;
 * - description « … — Solde : 35,00 € sur 85,00 € » ;
 * - historique (créé, utilisé, rendu si la commande est annulée ou remboursée, corrigé à la main),
 *   affiché dans un panneau de la fiche du code promo.
 *
 * Métas du code promo : _passflow_voucher_initial, _passflow_voucher_balance, _passflow_voucher_label,
 * _passflow_voucher_history. Méta de commande : _passflow_voucher_used (code => montant utilisé).
 */
final class VoucherBalance
{
    const META_INITIAL = '_passflow_voucher_initial';
    const META_BALANCE = '_passflow_voucher_balance';
    const META_LABEL = '_passflow_voucher_label';
    const META_HISTORY = '_passflow_voucher_history';
    const ORDER_USED = '_passflow_voucher_used';
    const ORDER_RESTORED = '_passflow_voucher_restored';

    public static function hooks()
    {
        // Après WooCommerce, qui compte les utilisations des codes au changement de statut.
        add_action('woocommerce_order_status_changed', array(__CLASS__, 'onStatus'), 30, 4);
        add_action('add_meta_boxes_shop_coupon', array(__CLASS__, 'metaBox'));
        add_action('woocommerce_coupon_options_save', array(__CLASS__, 'onAdminSave'), 20, 2);
    }

    /**
     * Bon géré par Passflow, à montant fixe.
     *
     * @param \WC_Coupon $coupon
     */
    public static function isVoucher($coupon)
    {
        return $coupon instanceof \WC_Coupon && $coupon->get_id() && $coupon->get_meta(GiftCards::META) && $coupon->get_discount_type() === 'fixed_cart';
    }

    /**
     * @param \WC_Coupon $coupon
     */
    public static function initial($coupon)
    {
        $initial = $coupon->get_meta(self::META_INITIAL);

        return $initial !== '' ? (float) $initial : (float) $coupon->get_amount();
    }

    /**
     * @param \WC_Coupon $coupon
     */
    public static function balance($coupon)
    {
        $balance = $coupon->get_meta(self::META_BALANCE);

        if ($balance !== '') {
            return (float) $balance;
        }

        // Ancien bon, jamais suivi : entier s'il n'a pas servi, épuisé sinon.
        $limit = (int) $coupon->get_usage_limit();

        return $limit > 0 && (int) $coupon->get_usage_count() >= $limit ? 0.0 : (float) $coupon->get_amount();
    }

    /**
     * @param \WC_Coupon $coupon
     * @return array<int,array<string,mixed>>
     */
    public static function history($coupon)
    {
        $history = $coupon->get_meta(self::META_HISTORY);

        return is_array($history) ? $history : array();
    }

    /**
     * À la création d'un bon (bon cadeau, bon de remboursement).
     *
     * @param \WC_Coupon $coupon
     */
    public static function created($coupon, $note = '')
    {
        $amount = (float) $coupon->get_amount();
        $coupon->update_meta_data(self::META_INITIAL, $amount);
        $coupon->update_meta_data(self::META_BALANCE, $amount);
        $coupon->update_meta_data(self::META_LABEL, (string) $coupon->get_description());
        self::log($coupon, 'created', $amount, $amount, 0, $note);
        $coupon->save();
    }

    /**
     * Utilisé en entier en boutique (Bons cadeaux › Utiliser).
     *
     * @param \WC_Coupon $coupon
     */
    public static function redeemedInStore($coupon, $note = '')
    {
        if (!self::isVoucher($coupon)) {
            return;
        }

        self::init($coupon);
        $used = self::balance($coupon);
        $coupon->update_meta_data(self::META_BALANCE, 0);
        self::log($coupon, 'store', $used, 0, 0, $note);
        self::describe($coupon);
        $coupon->save();
    }

    /**
     * Commande payée : on déduit ce qui a servi et on garde le reste. Commande annulée ou
     * remboursée ensuite : on rend au bon ce qu'elle avait pris.
     */
    public static function onStatus($order_id, $from, $to, $order = null)
    {
        $order = $order instanceof \WC_Order ? $order : wc_get_order($order_id);

        if (!$order || !$order->get_coupon_codes()) {
            return;
        }

        if (in_array($to, array('processing', 'completed'), true)) {
            self::consume($order);
        } elseif (in_array($to, array('cancelled', 'refunded', 'failed'), true)) {
            self::restore($order);
        }
    }

    /**
     * @param \WC_Order $order
     */
    private static function consume($order)
    {
        $d = 'smbb-passflow';
        $done = $order->get_meta(self::ORDER_USED);

        if (is_array($done) && $done && !$order->get_meta(self::ORDER_RESTORED)) {
            return; // Déjà déduit (passage processing → completed).
        }

        $used_by_code = array();

        foreach ($order->get_items('coupon') as $item) {
            $coupon = new \WC_Coupon($item->get_code());

            if (!self::isVoucher($coupon)) {
                continue;
            }

            self::init($coupon);
            $used = round((float) $item->get_discount() + (float) $item->get_discount_tax(), wc_get_price_decimals());
            $before = self::balance($coupon);
            $after = round(max(0, $before - $used), wc_get_price_decimals());
            self::setBalance($coupon, $after);
            self::log($coupon, 'used', $used, $after, (int) $order->get_id());
            self::describe($coupon);
            $coupon->save();

            $used_by_code[strtoupper($coupon->get_code())] = $used;
            $order->add_order_note($after > 0
                ? sprintf(__("Passflow : bon %1\$s, %2\$s utilisés, il reste %3\$s sur le bon (toujours valable).", $d), strtoupper($coupon->get_code()), self::money($used), self::money($after))
                : sprintf(__('Passflow : bon %1$s, %2$s utilisés, bon épuisé.', $d), strtoupper($coupon->get_code()), self::money($used)));
        }

        if ($used_by_code) {
            $order->update_meta_data(self::ORDER_USED, $used_by_code);
            $order->delete_meta_data(self::ORDER_RESTORED);
            $order->save();
        }
    }

    /**
     * @param \WC_Order $order
     */
    private static function restore($order)
    {
        $d = 'smbb-passflow';
        $done = $order->get_meta(self::ORDER_USED);

        if (!is_array($done) || !$done || $order->get_meta(self::ORDER_RESTORED)) {
            return;
        }

        foreach ($done as $code => $used) {
            $coupon = new \WC_Coupon((string) $code);

            if (!self::isVoucher($coupon)) {
                continue;
            }

            $after = round(self::balance($coupon) + (float) $used, wc_get_price_decimals());
            self::setBalance($coupon, $after);
            self::log($coupon, 'restored', (float) $used, $after, (int) $order->get_id());
            self::describe($coupon);
            $coupon->save();
            $order->add_order_note(sprintf(__('Passflow : commande %1$s, %2$s rendus au bon %3$s (solde %4$s).', $d), wc_get_order_status_name($order->get_status()), self::money($used), $code, self::money($after)));
        }

        $order->update_meta_data(self::ORDER_RESTORED, 1);
        $order->save();
    }

    /**
     * Montant changé à la main dans la fiche du code promo : il devient le solde.
     *
     * @param int        $post_id
     * @param \WC_Coupon $coupon
     */
    public static function onAdminSave($post_id, $coupon = null)
    {
        $coupon = $coupon instanceof \WC_Coupon ? new \WC_Coupon($coupon->get_id()) : new \WC_Coupon((int) $post_id);

        if (!self::isVoucher($coupon)) {
            return;
        }

        self::init($coupon);
        $amount = (float) $coupon->get_amount();
        $balance = self::balance($coupon);

        // Description retouchée à la main : elle devient le libellé (sans la mention du solde).
        $description = (string) $coupon->get_description();
        $label = preg_replace('/ — ' . preg_quote(__('Solde', 'smbb-passflow'), '/') . ' : .*$/u', '', $description);
        $coupon->update_meta_data(self::META_LABEL, $label);

        if (abs($amount - $balance) > 0.009 && !($balance <= 0 && (int) $coupon->get_usage_limit() > 0 && (int) $coupon->get_usage_count() >= (int) $coupon->get_usage_limit())) {
            self::setBalance($coupon, $amount);
            self::log($coupon, 'adjusted', $amount - $balance, $amount, 0, wp_get_current_user()->display_name);
        }

        self::describe($coupon);
        $coupon->save();
    }

    // ------------------------------------------------------------------
    // Panneau « Historique du bon » (fiche du code promo WooCommerce)
    // ------------------------------------------------------------------

    public static function metaBox($post)
    {
        $coupon = new \WC_Coupon((int) $post->ID);

        if (!self::isVoucher($coupon)) {
            return;
        }

        add_meta_box('passflow-voucher-history', __('Historique du bon (Passflow)', 'smbb-passflow'), array(__CLASS__, 'renderBox'), 'shop_coupon', 'normal', 'default');
    }

    public static function renderBox($post)
    {
        $d = 'smbb-passflow';
        $coupon = new \WC_Coupon((int) $post->ID);
        $initial = self::initial($coupon);
        $balance = self::balance($coupon);
        $labels = array(
            'created' => __('Créé', $d),
            'used' => __('Utilisé sur une commande', $d),
            'store' => __('Utilisé en boutique', $d),
            'restored' => __('Rendu (commande annulée ou remboursée)', $d),
            'adjusted' => __('Montant corrigé à la main', $d),
        );

        echo '<style>.passflow-vh-head{display:flex;gap:24px;margin:4px 0 12px}.passflow-vh-head div{display:flex;flex-direction:column}.passflow-vh-head span{color:#646970;font-size:12px}.passflow-vh-head strong{font-size:18px}.passflow-vh-bar{height:8px;border-radius:999px;background:#e8e9eb;overflow:hidden;margin-bottom:12px}.passflow-vh-bar i{display:block;height:100%;background:#1e8a4c}.passflow-vh td,.passflow-vh th{padding:6px 8px}.passflow-vh .num{text-align:right;white-space:nowrap}</style>';
        echo '<div class="passflow-vh-head"><div><span>' . esc_html__('Valeur de départ', $d) . '</span><strong>' . esc_html(self::money($initial)) . '</strong></div>'
            . '<div><span>' . esc_html__('Solde', $d) . '</span><strong>' . esc_html(self::money($balance)) . '</strong></div>'
            . '<div><span>' . esc_html__('Déjà utilisé', $d) . '</span><strong>' . esc_html(self::money(max(0, $initial - $balance))) . '</strong></div></div>';
        echo '<div class="passflow-vh-bar" title="' . esc_attr__('Solde restant', $d) . '"><i style="width:' . esc_attr((string) ($initial > 0 ? round(min(1, $balance / $initial) * 100, 1) : 0)) . '%"></i></div>';

        $history = array_reverse(self::history($coupon));

        if (!$history) {
            echo '<p class="description">' . esc_html__("Pas encore d'historique : il commence à la prochaine utilisation.", $d) . '</p>';
            return;
        }

        echo '<table class="widefat striped passflow-vh"><thead><tr><th>' . esc_html__('Date', $d) . '</th><th>' . esc_html__('Événement', $d) . '</th><th>' . esc_html__('Commande', $d) . '</th><th class="num">' . esc_html__('Montant', $d) . '</th><th class="num">' . esc_html__('Solde après', $d) . '</th></tr></thead><tbody>';

        foreach ($history as $row) {
            $order = !empty($row['order']) ? wc_get_order((int) $row['order']) : null;
            $sign = in_array($row['type'], array('used', 'store'), true) ? '− ' : (in_array($row['type'], array('restored'), true) ? '+ ' : '');
            echo '<tr><td>' . esc_html(mysql2date('j M Y, H\hi', (string) $row['at'])) . '</td>'
                . '<td>' . esc_html(isset($labels[$row['type']]) ? $labels[$row['type']] : $row['type']) . (!empty($row['note']) ? '<br><small>' . esc_html((string) $row['note']) . '</small>' : '') . '</td>'
                . '<td>' . ($order ? '<a href="' . esc_url($order->get_edit_order_url()) . '">n° ' . esc_html((string) $order->get_order_number()) . '</a>' : '—') . '</td>'
                . '<td class="num">' . esc_html($sign . self::money(abs((float) $row['amount']))) . '</td>'
                . '<td class="num"><strong>' . esc_html(self::money((float) $row['balance'])) . '</strong></td></tr>';
        }

        echo '</tbody></table>';
    }

    // ------------------------------------------------------------------
    // Utilitaires
    // ------------------------------------------------------------------

    /**
     * Ancien bon : on fixe sa valeur de départ, son solde et son libellé la première fois.
     *
     * @param \WC_Coupon $coupon
     */
    private static function init($coupon)
    {
        if ($coupon->get_meta(self::META_INITIAL) === '') {
            $coupon->update_meta_data(self::META_INITIAL, (float) $coupon->get_amount());
        }

        if ($coupon->get_meta(self::META_BALANCE) === '') {
            $coupon->update_meta_data(self::META_BALANCE, self::balance($coupon));
        }

        if ($coupon->get_meta(self::META_LABEL) === '') {
            $coupon->update_meta_data(self::META_LABEL, (string) $coupon->get_description());
        }
    }

    /**
     * Solde > 0 : le code reste valable pour ce montant (une utilisation de plus). Solde nul : épuisé.
     *
     * @param \WC_Coupon $coupon
     */
    private static function setBalance($coupon, $balance)
    {
        $coupon->update_meta_data(self::META_BALANCE, $balance);

        if ($balance > 0.009) {
            $coupon->set_amount(wc_format_decimal($balance));
            $coupon->set_usage_limit((int) $coupon->get_usage_count() + 1);
        } else {
            $coupon->set_usage_limit(max(1, (int) $coupon->get_usage_count()));
        }
    }

    /**
     * @param \WC_Coupon $coupon
     */
    private static function describe($coupon)
    {
        $label = (string) $coupon->get_meta(self::META_LABEL);
        $initial = self::initial($coupon);
        $balance = self::balance($coupon);
        $suffix = abs($balance - $initial) < 0.01 ? '' : ' — ' . sprintf(__('Solde : %1$s sur %2$s', 'smbb-passflow'), self::money($balance), self::money($initial));
        $coupon->set_description(trim($label . $suffix, ' —'));
    }

    /**
     * @param \WC_Coupon $coupon
     */
    private static function log($coupon, $type, $amount, $balance, $order_id = 0, $note = '')
    {
        $history = self::history($coupon);
        $history[] = array(
            'at' => current_time('mysql'),
            'type' => $type,
            'amount' => round((float) $amount, 2),
            'balance' => round((float) $balance, 2),
            'order' => (int) $order_id,
            'by' => get_current_user_id(),
            'note' => (string) $note,
        );
        $coupon->update_meta_data(self::META_HISTORY, array_slice($history, -200));
    }

    private static function money($amount)
    {
        return html_entity_decode(wp_strip_all_tags(wc_price((float) $amount)), ENT_QUOTES, 'UTF-8');
    }
}
