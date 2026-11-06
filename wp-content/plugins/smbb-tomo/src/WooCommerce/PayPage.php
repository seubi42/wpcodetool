<?php

namespace Smbb\Tomo\WooCommerce;

use Smbb\Tomo\Support\Settings;

defined('ABSPATH') || exit;

/**
 * Page « Régler votre commande » du lien de paiement (ventes sur place, imprévus) :
 * récapitulatif, champ bon d'achat / code promo, puis bouton vers le paiement WooCommerce.
 *
 * Page autonome (pas de dépendance au thème ni au bloc de paiement) : ?tomo_payer=ID&key=wc_order_…
 * Un bon qui couvre toute la commande la règle directement. Les bons Tomo gardent leur solde
 * (VoucherBalance) au paiement.
 */
final class PayPage
{
    const PARAM = 'tomo_payer';

    public static function hooks()
    {
        add_action('template_redirect', array(__CLASS__, 'render'), 1);
    }

    /**
     * @param \WC_Order $order
     */
    public static function url($order)
    {
        return add_query_arg(array(self::PARAM => (int) $order->get_id(), 'key' => $order->get_order_key()), home_url('/'));
    }

    public static function render()
    {
        if (empty($_GET[self::PARAM])) { // phpcs:ignore WordPress.Security.NonceVerification
            return;
        }

        $d = 'smbb-tomo';
        $order = wc_get_order(absint($_GET[self::PARAM])); // phpcs:ignore WordPress.Security.NonceVerification
        $key = isset($_GET['key']) ? wc_clean(wp_unslash($_GET['key'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification

        if (!$order || !hash_equals($order->get_order_key(), (string) $key)) {
            wp_safe_redirect(home_url('/'));
            exit;
        }

        nocache_headers();
        $message = '';
        $kind = '';

        // Bon d'achat ou code promo.
        if (isset($_POST['tomo_code']) && $order->needs_payment()) {
            if (!isset($_POST['_tomo_pay']) || !wp_verify_nonce(sanitize_key(wp_unslash($_POST['_tomo_pay'])), 'tomo_pay_' . $order->get_id())) {
                $message = __('La page a expiré, réessayez.', $d);
                $kind = 'error';
            } else {
                $code = strtoupper(preg_replace('/[^A-Za-z0-9_-]/', '', (string) wp_unslash($_POST['tomo_code'])));

                if ($code === '') {
                    $message = __('Saisissez le code de votre bon.', $d);
                    $kind = 'error';
                } elseif (in_array(wc_format_coupon_code($code), array_map('wc_format_coupon_code', $order->get_coupon_codes()), true)) {
                    $message = __('Ce code est déjà appliqué.', $d);
                    $kind = 'error';
                } else {
                    $result = $order->apply_coupon(wc_format_coupon_code($code));

                    if (is_wp_error($result)) {
                        $message = wp_strip_all_tags($result->get_error_message());
                        $kind = 'error';
                    } else {
                        $order->add_order_note(sprintf(__('Code %s appliqué par le client avant paiement.', $d), $code));
                        $order->save();
                        $order = wc_get_order($order->get_id());

                        if ((float) $order->get_total() <= 0) {
                            $order->payment_complete();
                            $order = wc_get_order($order->get_id());
                            $message = __('Votre bon couvre toute la commande : elle est réglée, merci !', $d);
                        } else {
                            $message = sprintf(__('Bon appliqué : il reste %s à régler.', $d), self::money($order->get_total(), $order));
                        }

                        $kind = 'success';
                    }
                }
            }
        }

        // Retirer un code (avant paiement).
        if (isset($_POST['tomo_remove']) && $order->needs_payment() && isset($_POST['_tomo_pay']) && wp_verify_nonce(sanitize_key(wp_unslash($_POST['_tomo_pay'])), 'tomo_pay_' . $order->get_id())) {
            $order->remove_coupon(wc_format_coupon_code(sanitize_text_field(wp_unslash($_POST['tomo_remove']))));
            $order->save();
            $order = wc_get_order($order->get_id());
            $message = __('Code retiré.', $d);
            $kind = 'success';
        }

        self::output($order, $message, $kind);
        exit;
    }

    /**
     * @param \WC_Order $order
     */
    private static function output($order, $message, $kind)
    {
        $d = 'smbb-tomo';
        $paid = !$order->needs_payment() && $order->is_paid();
        $cancelled = in_array($order->get_status(), array('cancelled', 'refunded', 'trash'), true);
        $rows = '';

        foreach ($order->get_items() as $item) {
            $rows .= '<tr><td>' . esc_html($item->get_name()) . ' <span class="q">× ' . (int) $item->get_quantity() . '</span></td><td class="n">' . esc_html(self::money((float) $item->get_total() + (float) $item->get_total_tax(), $order)) . '</td></tr>';
        }

        $codes = '';

        foreach ($order->get_items('coupon') as $coupon) {
            $codes .= '<tr class="disc"><td>' . esc_html(sprintf(__('Bon %s', $d), strtoupper($coupon->get_code())))
                . (!$paid && !$cancelled ? ' <button type="submit" form="tomo-pay-form" name="tomo_remove" value="' . esc_attr($coupon->get_code()) . '" class="link">' . esc_html__('retirer', $d) . '</button>' : '')
                . '</td><td class="n">− ' . esc_html(self::money((float) $coupon->get_discount() + (float) $coupon->get_discount_tax(), $order)) . '</td></tr>';
        }

        $title = $paid ? __('Commande réglée', $d) : ($cancelled ? __('Commande annulée', $d) : __('Régler votre commande', $d));
        $name = trim($order->get_billing_first_name());

        status_header(200);
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><html lang="' . esc_attr(get_bloginfo('language')) . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex">'
            . '<title>' . esc_html($title . ' · ' . Settings::businessName()) . '</title>'
            . '<style>'
            . ':root{--brand:' . \Smbb\Tomo\Support\Theme::accent() . ';--ink:#1d2327;--muted:#646970;--line:#e2e4e7}*{box-sizing:border-box}'
            . 'body{margin:0;background:#f6f4f2;color:var(--ink);font:16px/1.45 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif}'
            . '.w{max-width:480px;margin:0 auto;padding:20px 16px 40px}.b{font-weight:700;color:var(--brand);margin:0 0 14px;font-size:15px}'
            . '.c{background:#fff;border:1px solid var(--line);border-radius:16px;padding:20px;margin-bottom:14px}h1{font-size:22px;margin:0 0 4px}.m{color:var(--muted);font-size:14px;margin:0}'
            . 'table{width:100%;border-collapse:collapse;margin:14px 0 0}td{padding:8px 0;border-bottom:1px solid var(--line);vertical-align:top}.n{text-align:right;white-space:nowrap;padding-left:12px}.q{color:var(--muted)}'
            . '.disc td{color:#1e6b22}.tot td{border:0;padding-top:12px;font-size:20px;font-weight:700}'
            . 'label{display:block;font-weight:600;margin:0 0 8px}.row{display:flex;gap:8px}input[type=text]{flex:1;min-width:0;font-size:17px;padding:12px;border:1px solid #c3c4c7;border-radius:10px;text-transform:uppercase;letter-spacing:.04em}'
            . 'button,.btn{font:inherit;border-radius:10px;padding:12px 16px;border:1px solid var(--ink);background:#fff;color:var(--ink);cursor:pointer;text-decoration:none;display:inline-block;text-align:center}'
            . '.btn.p{display:block;width:100%;background:var(--brand);border-color:var(--brand);color:#fff;font-weight:700;font-size:18px;padding:16px}'
            . '.link{border:0;background:none;padding:0;color:var(--muted);text-decoration:underline;font-size:13px}'
            . '.msg{border-radius:12px;padding:12px 14px;margin-bottom:14px;font-weight:600}.msg.success{background:#e5f4e4;color:#1e6b22}.msg.error{background:#fbebeb;color:#9b1c1c}'
            . '.ok{font-size:44px;line-height:1;margin-bottom:8px}'
            . '</style></head><body><div class="w">'
            . '<p class="b">' . esc_html(Settings::businessName()) . '</p>';

        if ($message !== '') {
            echo '<div class="msg ' . esc_attr($kind) . '" role="' . ($kind === 'error' ? 'alert' : 'status') . '">' . esc_html($message) . '</div>';
        }

        echo '<div class="c">' . ($paid ? '<div class="ok" aria-hidden="true">✓</div>' : '')
            . '<h1>' . esc_html($title) . '</h1>'
            . '<p class="m">' . esc_html(trim(($name !== '' ? sprintf(__('Bonjour %s', $d), $name) . ' · ' : '') . sprintf(__('Commande n° %s', $d), $order->get_order_number()))) . '</p>'
            . '<table>' . $rows . $codes . '<tr class="tot"><td>' . esc_html($paid ? __('Réglé', $d) : __('À régler', $d)) . '</td><td class="n">' . esc_html(self::money($paid ? $order->get_total() : $order->get_total(), $order)) . '</td></tr></table></div>';

        if (!$paid && !$cancelled) {
            echo '<form method="post" class="c" id="tomo-pay-form">' . wp_nonce_field('tomo_pay_' . $order->get_id(), '_tomo_pay', false, false)
                . '<label for="tomo-code">' . esc_html__("Vous avez un bon d'achat ou un code promo ?", $d) . '</label>'
                . '<div class="row"><input type="text" id="tomo-code" name="tomo_code" autocomplete="off" autocapitalize="characters" placeholder="' . esc_attr(GiftCards::prefix() !== '' ? GiftCards::prefix() . '-XXXX-XXXX' : 'XXXX-XXXX') . '"><button type="submit">' . esc_html__('Appliquer', $d) . '</button></div>'
                . '</form>'
                . '<a class="btn p" href="' . esc_url($order->get_checkout_payment_url()) . '">' . esc_html(sprintf(__('Payer %s', $d), self::money($order->get_total(), $order))) . '</a>';
        } elseif ($paid) {
            echo '<p class="m" style="text-align:center">' . esc_html__('Merci ! Vous recevez la confirmation par e-mail.', $d) . '</p>';
            echo '<p class="m" style="text-align:center"><a href="' . esc_url(InvoiceScreen::accountUrl($order)) . '">' . esc_html__("Besoin d'une facture ?", $d) . '</a></p>';
        }

        echo '</div></body></html>';
    }

    /**
     * @param \WC_Order $order
     */
    private static function money($amount, $order)
    {
        return html_entity_decode(wp_strip_all_tags(wc_price((float) $amount, array('currency' => $order->get_currency()))), ENT_QUOTES, 'UTF-8');
    }
}
