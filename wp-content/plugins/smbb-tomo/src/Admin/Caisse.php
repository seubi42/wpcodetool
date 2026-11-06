<?php

namespace Smbb\Tomo\Admin;

use Smbb\Tomo\Repository\Creneaux;
use Smbb\Tomo\Repository\Lookup;
use Smbb\Tomo\Support\Roles;
use Smbb\Tomo\WooCommerce\Module;
use Smbb\Tomo\WooCommerce\OnsiteSales;

defined('ABSPATH') || exit;

/**
 * Onglet « Caisse » de la gestion mobile : chiffres du jour, ventes (recherche, pages), retours en
 * bon d'achat. Tout passe en Ajax (assets/caisse.js) : recherche, pages, nouvelle vente, retour ;
 * sans JavaScript, les mêmes formulaires passent par admin-post et la page se recharge.
 */
final class Caisse
{
    const AJAX = 'tomo_caisse';

    public static function hooks()
    {
        add_action('wp_ajax_' . self::AJAX, array(__CLASS__, 'ajax'));
    }

    public static function nonce($creneau_id)
    {
        return wp_create_nonce(self::AJAX . '_' . (int) $creneau_id);
    }

    public static function url($creneau_id, array $args = array())
    {
        return View::url('tomo_espace', array_merge(array('creneau' => (int) $creneau_id, 'onglet' => 'caisse'), $args));
    }

    /**
     * Catalogues visibles : ceux de l'intervenant connecté ; le gestionnaire voit tout (null).
     */
    public static function context()
    {
        if (Roles::canManage()) {
            return null;
        }

        $me = Lookup::intervenantForUser(get_current_user_id());

        return $me ? (int) $me['id'] : 0;
    }

    /**
     * @return array{orders:\WC_Order[],summary:array<string,mixed>}
     */
    public static function data($creneau_id)
    {
        $orders = Module::active() ? OnsiteSales::forCreneau($creneau_id) : array();

        return array('orders' => $orders, 'summary' => OnsiteSales::summary($orders));
    }

    /**
     * Contenu de l'onglet (remplacé tel quel après chaque action Ajax).
     *
     * @param \WC_Order[]         $sale_orders
     * @param array<string,mixed> $summary
     */
    public static function panel(array $creneau, array $sale_orders, array $summary, $manager, $sale_products, $query = '', $page_no = 1, $forced = false)
    {
        $d = 'smbb-tomo';
        // Hors du jour de l'atelier : vente et retours fermés, sauf « Accès forcé ».
        $lock_attr = Actions::canPoint($creneau, $forced) ? ' data-tomo-lockable' : ' disabled data-tomo-lockable';
        $force_input = '<input type="hidden" name="hors_date" value="' . ($forced ? '1' : '0') . '" data-tomo-force>';
        $creneau_id = (int) $creneau['id'];
        $html = '';

        $money = array('\Smbb\Tomo\WooCommerce\OnsiteSales', 'money');
        $kpi = '<div class="tomo-caisse-kpis">'
            . '<div class="tomo-caisse-kpi is-main"><span>' . esc_html__('Encaissé', $d) . '</span><strong>' . esc_html(call_user_func($money, $summary['paid'])) . '</strong></div>'
            . '<div class="tomo-caisse-kpi"><span>' . esc_html__('En attente de paiement', $d) . '</span><strong>' . esc_html(call_user_func($money, $summary['pending'])) . '</strong></div>'
            . '<div class="tomo-caisse-kpi"><span>' . esc_html__('Ventes', $d) . '</span><strong>' . (int) $summary['count'] . '</strong></div>'
            . '<div class="tomo-caisse-kpi"><span>' . esc_html__('Total de la journée', $d) . '</span><strong>' . esc_html(call_user_func($money, $summary['paid'] + $summary['pending'])) . '</strong></div>'
            . ($summary['vouchers'] > 0 ? '<div class="tomo-caisse-kpi"><span>' . esc_html__("Remboursé en bons d'achat", $d) . '</span><strong>' . esc_html(call_user_func($money, $summary['vouchers'])) . '</strong></div>' : '')
            . '</div>';

        if ($sale_products) {
            $kpi .= '<p><button type="button" class="button button-primary button-large" data-tomo-sale' . $lock_attr . '><span class="dashicons dashicons-cart" aria-hidden="true"></span> ' . esc_html__('Nouvelle vente', $d) . '</button></p>';
        }

        $html .= View::card(__('Caisse de la journée', $d), $kpi, 'tomo-caisse-card');

        if ($summary['methods'] || $summary['products']) {
            $split = '<div class="tomo-caisse-split">';

            if ($summary['methods']) {
                $rows = '';
                arsort($summary['methods']);

                foreach ($summary['methods'] as $label => $amount) {
                    $rows .= '<tr><td>' . esc_html($label) . '</td><td class="num">' . esc_html(call_user_func($money, $amount)) . '</td></tr>';
                }

                $split .= '<div><h3>' . esc_html__('Par moyen de paiement', $d) . '</h3><table class="tomo-caisse-table"><tbody>' . $rows . '</tbody></table></div>';
            }

            if ($summary['products']) {
                $rows = '';
                uasort($summary['products'], function ($a, $b) {
                    return $b['total'] <=> $a['total'];
                });

                foreach ($summary['products'] as $name => $line) {
                    $rows .= '<tr><td>' . esc_html($name) . (!empty($line['sku']) ? ' <span class="tomo-caisse-sku">' . esc_html(sprintf(__('Réf. %s', $d), $line['sku'])) . '</span>' : '') . '</td><td class="num">× ' . (int) $line['qty'] . '</td><td class="num">' . esc_html(call_user_func($money, $line['total'])) . '</td></tr>';
                }

                $split .= '<div><h3>' . esc_html__('Produits vendus (payés)', $d) . '</h3><table class="tomo-caisse-table"><tbody>' . $rows . '</tbody></table></div>';
            }

            $html .= View::card('', $split . '</div>');
        }

        // Ventes de la date, la plus récente en haut : recherche (nom, e-mail, fin du téléphone, n° de commande) et pages de 20.
        $query = trim((string) $query);
        $page_no = max(1, (int) $page_no);
        $per_page = 20;
        // Ventes annulées (lien jamais payé) : hors de la liste.
        $sale_orders = array_values(array_filter($sale_orders, function ($order) {
            return !in_array($order->get_status(), array('cancelled', 'trash'), true);
        }));
        $found = $sale_orders;

        if ($query !== '') {
            $needle = strtolower(remove_accents($query));
            $digits = preg_replace('/\D/', '', $query);
            $found = array_values(array_filter($sale_orders, function ($order) use ($needle, $digits) {
                $refs = '';

                foreach ($order->get_items() as $item) {
                    $product = $item instanceof \WC_Order_Item_Product ? $item->get_product() : null;
                    $refs .= ' ' . $item->get_name() . ($product ? ' ' . $product->get_sku() : '');
                }

                $text = strtolower(remove_accents($order->get_billing_first_name() . ' ' . $order->get_billing_last_name() . ' ' . $order->get_billing_last_name() . ' ' . $order->get_billing_first_name() . ' ' . $order->get_billing_email() . ' ' . $order->get_order_number() . $refs));

                if (strpos($text, $needle) !== false) {
                    return true;
                }

                if (strlen($digits) >= 2) {
                    $phone = preg_replace('/\D/', '', (string) $order->get_billing_phone());
                    $local = preg_replace('/^33/', '0', $phone);

                    return strpos($phone, $digits) !== false || strpos($local, $digits) !== false || (string) $order->get_order_number() === $digits;
                }

                return false;
            }));
        }

        $pages = max(1, (int) ceil(count($found) / $per_page));
        $page_no = min($page_no, $pages);
        $shown = array_slice($found, ($page_no - 1) * $per_page, $per_page);
        $list = '';
        $methods = \Smbb\Tomo\WooCommerce\OnsiteSales::methods();

        foreach ($shown as $order) {
            $status = $order->get_status();
            $gone = in_array($status, array('cancelled', 'refunded', 'failed', 'trash'), true);
            $paid = $order->is_paid();
            $can_return = $paid && !$gone;
            $items = '';
            $returnable = 0;

            // Lignes de la vente : une case par ligne encore remboursable ; une ligne rendue est rayée.
            foreach ($order->get_items() as $item_id => $item) {
                $qty = (int) $item->get_quantity();
                $left = $item instanceof \WC_Order_Item_Product ? \Smbb\Tomo\WooCommerce\Refunds::refundableQty($order, $item) : 0;
                $vouchers = $item->get_meta(\Smbb\Tomo\WooCommerce\Refunds::META_ITEM);
                $vouchers = is_array($vouchers) ? $vouchers : array();
                $done = $paid && $left < $qty;
                $notes = array();

                foreach ($vouchers as $voucher) {
                    if (is_array($voucher) && isset($voucher['code'])) {
                        $notes[] = sprintf(__('%1$d rendu(s) : bon %2$s, %3$s', $d), (int) $voucher['qty'], $voucher['code'], \Smbb\Tomo\WooCommerce\OnsiteSales::money($voucher['amount']));
                    }
                }

                if (!$notes && $done) {
                    $notes[] = sprintf(__('%d remboursé(s)', $d), $qty - $left);
                }

                $item_product = $item instanceof \WC_Order_Item_Product ? $item->get_product() : null;
                $sku = $item_product ? (string) $item_product->get_sku() : '';
                $line = '<span class="tomo-caisse-item-name">' . esc_html($item->get_name()) . ' × ' . $qty . '</span>'
                    . ($sku !== '' ? ' <span class="tomo-caisse-sku">' . esc_html(sprintf(__('Réf. %s', $d), $sku)) . '</span>' : '');

                if ($can_return && $left > 0) {
                    $returnable++;
                    $pick = '';

                    if ($left > 1) {
                        $options = '';

                        for ($q = $left; $q >= 1; $q--) {
                            $options .= '<option value="' . $q . '">' . $q . '</option>';
                        }

                        $pick = ' <select name="lines[' . (int) $item_id . ']" class="tomo-caisse-qty" aria-label="' . esc_attr__('Quantité rendue', $d) . '" disabled hidden>' . $options . '</select>';
                    } else {
                        $pick = '<input type="hidden" name="lines[' . (int) $item_id . ']" value="1" disabled>';
                    }

                    $unit = $qty > 0 ? ((float) $item->get_total() + (float) $item->get_total_tax()) / $qty : 0;
                    $line = '<label class="tomo-caisse-pick"><input type="checkbox" data-tomo-return-line' . $lock_attr . ' data-unit="' . esc_attr((string) round($unit, 4)) . '" data-name="' . esc_attr($item->get_name()) . '"> ' . $line . '</label>' . $pick;
                } else {
                    $line = '<span class="tomo-caisse-pick is-static">' . $line . '</span>';
                }

                $items .= '<li class="' . ($done && $left === 0 ? 'is-returned' : ($done ? 'is-partial' : '')) . '">' . $line
                    . ($notes ? ' <span class="tomo-caisse-returned">' . esc_html(implode(' · ', $notes)) . '</span>' : '')
                    . '</li>';
            }

            $method = (string) $order->get_meta(\Smbb\Tomo\WooCommerce\OnsiteSales::META_METHOD);
            $how = $paid ? (isset($methods[$method]) ? $methods[$method] : ($order->get_payment_method() === \Smbb\Tomo\WooCommerce\OnsiteSales::GATEWAY ? __('Sur place', $d) : __('Payée en ligne (lien)', $d))) : '';
            $seller = get_userdata((int) $order->get_meta(\Smbb\Tomo\WooCommerce\OnsiteSales::META_BY));
            $created = $order->get_date_created();
            $refunded = (float) $order->get_total_refunded();
            $badge = $gone ? '<span class="tomo-badge tomo-badge--cancelled">' . esc_html(wc_get_order_status_name($status)) . '</span>'
                : '<span class="tomo-badge tomo-badge--' . ($paid ? 'confirmed' : 'pending') . '">' . esc_html($paid ? __('Payée', $d) : __('En attente de paiement', $d)) . '</span>';
            $phone = (string) $order->get_billing_phone();
            $email = (string) $order->get_billing_email();
            $contact = ($phone !== '' ? '<a href="tel:' . esc_attr(preg_replace('/[^0-9+]/', '', $phone)) . '">' . esc_html($phone) . '</a>' : '')
                . ($phone !== '' && $email !== '' ? ' · ' : '')
                . ($email !== '' ? '<a href="mailto:' . esc_attr($email) . '">' . esc_html($email) . '</a>' : '');

            $body = '<div class="tomo-caisse-sale-main"><strong>' . esc_html(trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name()) ?: __('Client', $d)) . '</strong>'
                . ($order->get_meta(\Smbb\Tomo\WooCommerce\Walkins::META) ? ' <span class="tomo-badge tomo-badge--walkin">' . esc_html__('Imprévu', $d) . '</span>' : '')
                . ($contact !== '' ? ' <span class="tomo-caisse-contact">' . $contact . '</span>' : '')
                . '<ul class="tomo-caisse-items">' . $items . '</ul>'
                . '<div class="tomo-muted">' . esc_html(implode(' · ', array_filter(array(
                    $created ? $created->date_i18n('H:i') : '',
                    sprintf(__('N° %s', $d), $order->get_order_number()),
                    $how,
                    $seller ? sprintf(__('par %s', $d), $seller->display_name) : '',
                )))) . '</div></div>'
                . '<div class="tomo-caisse-sale-side"><div class="tomo-caisse-sale-top">'
                . '<strong>' . esc_html(\Smbb\Tomo\WooCommerce\OnsiteSales::total($order)) . '</strong>'
                . ($refunded > 0 && !$gone ? ' <small class="tomo-muted">' . esc_html(sprintf(__('dont %s rendus', $d), \Smbb\Tomo\WooCommerce\OnsiteSales::money($refunded))) . '</small>' : '')
                . ' ' . $badge
                . ($paid && \Smbb\Tomo\Support\Roles::canManage() ? ' <a class="button button-small" href="' . esc_url(\Smbb\Tomo\WooCommerce\InvoiceScreen::adminUrl($order->get_id())) . '">' . esc_html__('Facture', $d) . '</a>' : '')
                . (!$paid && in_array($status, array('pending', 'failed'), true) ? ' <a class="button button-small" href="' . esc_url(View::actionUrl(Actions::ONSITE_RESEND, array('id' => (int) $order->get_id()))) . '">' . esc_html__('Renvoyer le lien', $d) . '</a>'
                    . ' <a class="button button-small tomo-caisse-cancel" href="' . esc_url(View::actionUrl(Actions::ONSITE_CANCEL, array('id' => (int) $order->get_id()))) . '" data-tomo-cancel-order="' . (int) $order->get_id() . '" data-client="' . esc_attr(trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name())) . '" data-order="' . esc_attr((string) $order->get_order_number()) . '" data-total="' . esc_attr(OnsiteSales::total($order)) . '">' . esc_html__('Annuler', $d) . '</a>' : '')
                . ($manager ? ' <a class="button button-small" href="' . esc_url($order->get_edit_order_url()) . '">' . esc_html__('Commande', $d) . '</a>' : '')
                . '</div>'
                . ($returnable ? '<div class="tomo-caisse-return" data-tomo-return-actions hidden>'
                    . '<label class="tomo-caisse-send"><input type="checkbox" name="send" value="1" checked> ' . esc_html__('Envoyer le code par SMS et e-mail', $d) . '</label>'
                    . '<button type="submit" class="button button-primary" data-tomo-return-submit>' . esc_html__("Retour en bon d'achat", $d) . '</button>'
                    . '</div>' : '')
                . '</div>';

            $list .= $returnable
                ? '<li class="tomo-caisse-sale' . ($gone ? ' is-gone' : '') . '">' . str_replace('<form ', '<form data-tomo-return-form data-client="' . esc_attr(trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name())) . '" data-order="' . esc_attr((string) $order->get_order_number()) . '" data-contact="' . esc_attr($order->get_billing_phone() ?: $order->get_billing_email()) . '" ', View::formStart(Actions::ONSITE_REFUND, (int) $order->get_id(), 'tomo-caisse-sale-form')) . $force_input . $body . '</form></li>'
                : '<li class="tomo-caisse-sale' . ($gone ? ' is-gone' : '') . '"><div class="tomo-caisse-sale-form">' . $body . '</div></li>';
        }

        $search_form = '<form method="get" action="' . esc_url(admin_url('admin.php')) . '" class="tomo-caisse-find" role="search" data-tomo-caisse-find>'
            . '<input type="hidden" name="page" value="smbb-codetool-tomo_espace">'
            . '<input type="hidden" name="creneau" value="' . (int) $creneau_id . '"><input type="hidden" name="onglet" value="caisse">'
            . '<span class="dashicons dashicons-search" aria-hidden="true"></span>'
            . '<input type="search" name="q" data-tomo-caisse-q value="' . esc_attr($query) . '" placeholder="' . esc_attr__('Nom, e-mail, fin du téléphone, n° de commande ou réf. article…', $d) . '" aria-label="' . esc_attr__('Rechercher une vente', $d) . '">'
            . '<button type="submit" class="button">' . esc_html__('Rechercher', $d) . '</button>'
            . ($query !== '' ? ' <a class="button-link" href="' . esc_url(self::url($creneau_id)) . '" data-tomo-caisse-page="1" data-tomo-caisse-clear>' . esc_html__('Tout afficher', $d) . '</a>' : '')
            . '</form>';

        $pager = '';

        if ($pages > 1) {
            $pager = '<nav class="tomo-caisse-pager" aria-label="' . esc_attr__('Pages des ventes', $d) . '">';

            for ($n = 1; $n <= $pages; $n++) {
                $url = add_query_arg(array_filter(array('q' => $query !== '' ? $query : null, 'p' => $n > 1 ? $n : null)), self::url($creneau_id));
                $pager .= $n === $page_no ? '<span class="is-current" aria-current="page">' . $n . '</span>' : '<a href="' . esc_url($url) . '" data-tomo-caisse-page="' . $n . '">' . $n . '</a>';
            }

            $pager .= '</nav>';
        }

        $count_line = '<p class="tomo-muted tomo-caisse-count">' . esc_html($query !== ''
            ? sprintf(_n('%d vente trouvée', '%d ventes trouvées', count($found), $d), count($found))
            : sprintf(_n('%d vente', '%d ventes', count($found), $d), count($found))) . '</p>';

        $html .= View::card(__('Ventes de la journée', $d), $search_form . ($sale_orders ? $count_line : '')
            . ($list ? '<ul class="tomo-caisse-sales">' . $list . '</ul>' : '<p class="tomo-muted">' . esc_html($query !== '' ? __('Aucune vente ne correspond.', $d) : __('Aucune vente pour le moment.', $d)) . '</p>')
            . $pager);


        return $html;
    }

    /**
     * Ajax : panel (recherche, page), sale (nouvelle vente), refund (retour en bon d'achat).
     * Réponse : message, panneau à jour, libellés de l'onglet et de la barre de vente.
     */
    public static function ajax()
    {
        $creneau_id = isset($_REQUEST['creneau']) ? absint($_REQUEST['creneau']) : 0; // phpcs:ignore WordPress.Security.NonceVerification
        check_ajax_referer(self::AJAX . '_' . $creneau_id, 'nonce');
        $creneau = Creneaux::find($creneau_id);

        if (!$creneau || !Actions::canSeeCreneau($creneau_id) || !Module::active()) {
            wp_send_json_error(array('message' => __("Vous n'avez pas accès à cette date.", 'smbb-tomo')), 403);
        }

        $op = isset($_REQUEST['op']) ? sanitize_key(wp_unslash($_REQUEST['op'])) : 'panel'; // phpcs:ignore WordPress.Security.NonceVerification
        $result = array('success' => true, 'message' => '');

        if (in_array($op, array('sale', 'refund'), true) && !Actions::canPoint($creneau, Actions::forced())) {
            $result = array('success' => false, 'message' => __("Ce n'est pas le jour de l'atelier : la caisse est fermée. Activez « Accès forcé » pour l'ouvrir.", 'smbb-tomo'));
            $op = 'panel';
        }

        if ($op === 'cancel') {
            $result = self::cancel(isset($_POST['id']) ? absint($_POST['id']) : 0, $creneau_id);
        }

        if ($op === 'sale') {
            $result = Actions::saleFromRequest($creneau);
        } elseif ($op === 'refund') {
            $order_id = isset($_POST['id']) ? absint($_POST['id']) : 0;
            $order = wc_get_order($order_id);

            if (!$order || (int) $order->get_meta(OnsiteSales::META_CRENEAU) !== $creneau_id) {
                wp_send_json_error(array('message' => __('Vente introuvable.', 'smbb-tomo')), 404);
            }

            $result = Actions::refundFromRequest($order, $creneau);
        }

        $data = self::data($creneau_id);
        $query = isset($_REQUEST['q']) ? sanitize_text_field(wp_unslash($_REQUEST['q'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification
        $page = isset($_REQUEST['p']) ? absint($_REQUEST['p']) : 1; // phpcs:ignore WordPress.Security.NonceVerification
        $sale_products = (bool) OnsiteSales::products(Lookup::atelier($creneau['atelier_id']), self::context());

        wp_send_json(array(
            'success' => !empty($result['success']),
            'data' => array(
                'message' => (string) $result['message'],
                'panel' => self::panel($creneau, $data['orders'], $data['summary'], Roles::canManage(), $sale_products, $query, $page, Actions::forced()),
                'tab' => OnsiteSales::money($data['summary']['paid']),
                'bar' => self::barLabel($data['summary']),
            ),
        ));
    }

    /**
     * Annule une vente pas encore payée (le lien de paiement ne marche plus, le stock revient).
     *
     * @return array{success:bool,message:string}
     */
    public static function cancel($order_id, $creneau_id)
    {
        $d = 'smbb-tomo';
        $order = $order_id && function_exists('wc_get_order') ? wc_get_order($order_id) : null;

        if (!$order || (int) $order->get_meta(OnsiteSales::META_CRENEAU) !== (int) $creneau_id) {
            return array('success' => false, 'message' => __('Vente introuvable.', $d));
        }

        if ($order->is_paid() || !in_array($order->get_status(), array('pending', 'failed', 'on-hold'), true)) {
            return array('success' => false, 'message' => __('Seule une vente pas encore payée peut être annulée.', $d));
        }

        $order->update_status('cancelled', sprintf(__('Tomo : vente sur place annulée avant paiement par %s.', $d), wp_get_current_user()->display_name));

        return array('success' => true, 'message' => sprintf(__('Vente n° %s annulée.', $d), $order->get_order_number()));
    }

    /**
     * @param array<string,mixed> $summary
     */
    public static function barLabel(array $summary)
    {
        return sprintf(_n('Caisse : %1$d vente, %2$s encaissés', 'Caisse : %1$d ventes, %2$s encaissés', (int) $summary['count'], 'smbb-tomo'), (int) $summary['count'], OnsiteSales::money($summary['paid']));
    }
}
