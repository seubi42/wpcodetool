<?php

namespace Smbb\Tomo\WooCommerce;

use Smbb\Tomo\Repository\Lookup;
use Smbb\Tomo\Service\Notifier;

defined('ABSPATH') || exit;

/**
 * Vente sur place : l'intervenant vend des produits liés à l'atelier (livre, ustensiles, boîte de
 * mochis…) le jour de l'atelier. Chaque vente est une vraie commande WooCommerce :
 * - payée sur place (carte, espèces…) : commande terminée tout de suite, stock décompté ;
 * - à payer en ligne : commande en attente de paiement, lien de paiement envoyé par SMS et e-mail.
 *
 * Méta de commande : _tomo_onsite_creneau (créneau), _tomo_onsite_by (qui a vendu).
 */
final class OnsiteSales
{
    const META_CRENEAU = '_tomo_onsite_creneau';
    const META_BY = '_tomo_onsite_by';
    const GATEWAY = 'tomo_onsite';
    const META_INSCRIPTION = '_tomo_onsite_inscription';
    const META_METHOD = '_tomo_onsite_method';

    /**
     * @return array<string,string>
     */
    public static function methods()
    {
        return array(
            'card' => __('Carte bancaire (terminal)', 'smbb-tomo'),
            'cash' => __('Espèces', 'smbb-tomo'),
            'check' => __('Chèque', 'smbb-tomo'),
            'other' => __('Autre', 'smbb-tomo'),
        );
    }

    /**
     * Produits vendables sur place pour un atelier, dans l'ordre choisi.
     *
     * @return \WC_Product[]
     */
    public static function products($atelier, $intervenant_id = null)
    {
        if (!$atelier || !Module::active()) {
            return array();
        }

        // Catalogue sur place (ateliers / intervenants concernés), puis anciens produits de l'atelier.
        $products = \Smbb\Tomo\Repository\Catalogues::productsFor((int) $atelier['id'], $intervenant_id);

        foreach (Lookup::ids(isset($atelier['extra_product_ids']) ? $atelier['extra_product_ids'] : array()) as $id) {
            $product = wc_get_product($id);

            if ($product && !isset($products[(int) $id]) && $product->is_purchasable() && $product->get_status() === 'publish' && !$product->is_type('variable')) {
                $products[(int) $id] = $product;
            }
        }

        return array_values($products);
    }

    /**
     * Produits proposés dans la fiche atelier (tous sauf ceux qui vendent des places d'atelier).
     *
     * @return array<int,string> id => libellé
     */
    public static function choices()
    {
        if (!Module::active()) {
            return array();
        }

        $atelier_products = array_filter(array_map('intval', array_column(Lookup::ateliers(), 'product_id')));
        $choices = array();

        foreach (wc_get_products(array('status' => 'publish', 'limit' => 300, 'orderby' => 'title', 'order' => 'ASC', 'type' => array('simple', 'variation'))) as $product) {
            if (in_array((int) $product->get_id(), $atelier_products, true)) {
                continue;
            }

            $choices[(int) $product->get_id()] = $product->get_name() . ' · ' . html_entity_decode(wp_strip_all_tags(wc_price($product->get_price())), ENT_QUOTES, 'UTF-8');
        }

        return $choices;
    }

    /**
     * @param array<string,mixed> $creneau
     * @param array<int,int>      $lines    product_id => quantité
     * @param array<string,string> $customer first_name, last_name, email, phone
     * @param string $mode   paid|link
     * @param string $method card|cash|check|other (mode paid)
     * @return array{success:bool,message:string,order_id?:int}
     */
    public static function create(array $creneau, array $lines, array $customer, $mode, $method = 'card', $intervenant_id = null, $customer_id = 0, $inscription_id = 0)
    {
        $d = 'smbb-tomo';
        $atelier = Lookup::atelier($creneau['atelier_id']);
        $allowed = array();

        foreach (self::products($atelier, $intervenant_id) as $product) {
            $allowed[(int) $product->get_id()] = $product;
        }

        $lines = array_filter(array_map('intval', $lines), function ($qty) {
            return $qty > 0;
        });
        $lines = array_intersect_key($lines, $allowed);

        if (!$lines) {
            return array('success' => false, 'message' => __('Choisissez au moins un produit.', $d));
        }

        $email = isset($customer['email']) ? strtolower(sanitize_email($customer['email'])) : '';
        $phone = isset($customer['phone']) ? \Smbb\Tomo\Support\Input::phone($customer['phone']) : '';

        foreach ($lines as $id => $qty) {
            if (!$allowed[$id]->has_enough_stock($qty)) {
                return array('success' => false, 'message' => sprintf(__('Stock insuffisant pour %s.', $d), $allowed[$id]->get_name()));
            }
        }

        // Pas de commande sans compte : celui du participant, sinon celui qui a cet e-mail, sinon il est créé
        // (la personne reçoit un lien pour choisir son mot de passe et retrouve son historique).
        $customer_id = (int) $customer_id;

        if (!$customer_id) {
            if ($email === '') {
                return array('success' => false, 'message' => __("Indiquez l'e-mail du client : son compte est créé avec, pour qu'il retrouve ses achats.", $d), 'need' => 'email');
            }

            $account = \Smbb\Tomo\Service\Accounts::customer($email, isset($customer['first_name']) ? sanitize_text_field($customer['first_name']) : '', isset($customer['last_name']) ? sanitize_text_field($customer['last_name']) : '', $phone, $creneau);

            if (is_wp_error($account)) {
                return array('success' => false, 'message' => $account->get_error_message(), 'need' => 'email');
            }

            $customer_id = (int) $account['id'];
        }

        // Coordonnées manquantes : reprises du compte.
        if ($email === '' && $customer_id) {
            $user = get_userdata($customer_id);
            $email = $user ? strtolower((string) (get_user_meta($customer_id, 'billing_email', true) ?: $user->user_email)) : '';
        }

        if ($phone === '' && $customer_id) {
            $phone = (string) get_user_meta($customer_id, 'billing_phone', true);
        }

        if ($mode === 'link' && $email === '' && $phone === '') {
            return array('success' => false, 'message' => __('Pour envoyer un lien de paiement, il faut un téléphone ou un e-mail.', $d));
        }

        $order = wc_create_order(array('created_via' => self::GATEWAY, 'customer_id' => $customer_id));

        if (is_wp_error($order)) {
            return array('success' => false, 'message' => $order->get_error_message());
        }

        if ($inscription_id) {
            $order->update_meta_data(self::META_INSCRIPTION, (int) $inscription_id);
        }

        foreach ($lines as $id => $qty) {
            $order->add_product($allowed[$id], $qty);
        }

        $order->set_billing_first_name(isset($customer['first_name']) ? sanitize_text_field($customer['first_name']) : '');
        $order->set_billing_last_name(isset($customer['last_name']) ? sanitize_text_field($customer['last_name']) : '');
        $order->set_billing_email($email);
        $order->set_billing_phone($phone);
        $order->update_meta_data(self::META_CRENEAU, (int) $creneau['id']);
        $order->update_meta_data(self::META_BY, get_current_user_id());
        $order->calculate_totals();

        $seller = wp_get_current_user()->display_name;
        $where = Lookup::creneauLabel($creneau);

        if ($mode === 'paid') {
            $methods = self::methods();
            $method = isset($methods[$method]) ? $method : 'other';
            $order->set_payment_method(self::GATEWAY);
            $order->set_payment_method_title(sprintf(__('Sur place : %s', $d), $methods[$method]));
            $order->update_meta_data(self::META_METHOD, $method);
            $order->save();
            $order->payment_complete();
            $order->update_status('completed', sprintf(__('Vente sur place (%1$s) par %2$s, %3$s.', $d), $methods[$method], $seller, $where));

            return array('success' => true, 'order_id' => (int) $order->get_id(), 'message' => sprintf(__('Vente enregistrée : commande n° %1$s, %2$s, payée sur place.', $d), $order->get_order_number(), self::total($order)));
        }

        $order->set_status('pending');
        $order->add_order_note(sprintf(__('Vente sur place par %1$s, %2$s : lien de paiement envoyé au client.', $d), $seller, $where));
        $order->save();
        $sent = self::sendLink($order, $creneau);

        return array(
            'success' => true,
            'order_id' => (int) $order->get_id(),
            'message' => sprintf(__('Commande n° %1$s (%2$s) créée, en attente de paiement.', $d), $order->get_order_number(), self::total($order)) . ' ' . $sent,
        );
    }

    /**
     * Envoie (ou renvoie) le lien de paiement par SMS et par e-mail.
     *
     * @param \WC_Order $order
     */
    public static function sendLink($order, $creneau = null)
    {
        $d = 'smbb-tomo';
        $items = array();

        foreach ($order->get_items() as $item) {
            $items[] = $item->get_quantity() . ' × ' . $item->get_name();
        }

        $results = Notifier::toCustomer('payment_link', array(
            'first_name' => $order->get_billing_first_name(),
            'last_name' => $order->get_billing_last_name(),
            'email' => $order->get_billing_email(),
            'phone' => $order->get_billing_phone(),
        ), $creneau, array(
            // Client avec un compte : le lien le connecte tout seul (10 minutes), sans mot de passe.
            // Page Tomo « Régler votre commande » (bon d'achat possible), puis paiement WooCommerce.
            'lien_paiement' => $order->get_customer_id() ? \Smbb\Tomo\Support\LoginLink::create($order->get_customer_id(), PayPage::url($order)) : PayPage::url($order),
            'montant' => self::total($order),
            'produits' => implode("\n", $items),
            'commande' => (string) $order->get_order_number(),
        ));

        $ok = array();

        foreach ($results as $result) {
            if (in_array($result['status'], array('sent', 'test'), true)) {
                $ok[] = $result['channel'] === 'sms' ? __('SMS', $d) : __('e-mail', $d);
            }
        }

        return $ok
            ? sprintf(__('Lien de paiement envoyé (%s).', $d), implode(', ', $ok))
            : __("Le lien n'a pas pu partir : vérifiez le téléphone, l'e-mail et les réglages d'envoi.", $d);
    }

    /**
     * Ventes sur place d'un créneau, de la plus récente à la plus ancienne.
     *
     * @return \WC_Order[]
     */
    public static function forCreneau($creneau_id)
    {
        if (!Module::active()) {
            return array();
        }

        return wc_get_orders(array(
            'limit' => -1,
            'orderby' => 'date',
            'order' => 'DESC',
            'meta_key' => self::META_CRENEAU, // phpcs:ignore WordPress.DB.SlowDBQuery
            'meta_value' => (int) $creneau_id, // phpcs:ignore WordPress.DB.SlowDBQuery
        ));
    }

    /**
     * Caisse du jour : ventes payées (CA encaissé, par moyen de paiement) et en attente.
     *
     * @param \WC_Order[] $orders
     * @return array{paid:float,pending:float,count:int,methods:array<string,float>,products:array<string,array{qty:int,total:float}>}
     */
    public static function summary(array $orders)
    {
        $out = array('paid' => 0.0, 'pending' => 0.0, 'count' => 0, 'methods' => array(), 'products' => array(), 'vouchers' => 0.0);
        $labels = self::methods();

        foreach ($orders as $order) {
            // Bons d'achat émis en remboursement, même si la commande est entièrement remboursée.
            foreach ($order->get_items() as $item) {
                foreach ((array) $item->get_meta(Refunds::META_ITEM) as $voucher) {
                    $out['vouchers'] += is_array($voucher) && isset($voucher['amount']) ? (float) $voucher['amount'] : 0;
                }
            }

            if (in_array($order->get_status(), array('cancelled', 'refunded', 'failed', 'trash'), true)) {
                continue;
            }

            $total = (float) $order->get_total() - (float) $order->get_total_refunded();
            $out['count']++;


            if (!$order->is_paid()) {
                $out['pending'] += $total;
                continue;
            }

            $out['paid'] += $total;
            $method = (string) $order->get_meta(self::META_METHOD);
            $label = isset($labels[$method]) ? $labels[$method] : ($order->get_payment_method() === self::GATEWAY ? __('Sur place', 'smbb-tomo') : __('Paiement en ligne (lien)', 'smbb-tomo'));
            $out['methods'][$label] = (isset($out['methods'][$label]) ? $out['methods'][$label] : 0) + $total;

            // Lignes nettes des remboursements (retours produit remboursés en bon d'achat).
            foreach ($order->get_items() as $item_id => $item) {
                $name = $item->get_name();
                $qty = (int) $item->get_quantity() + (int) $order->get_qty_refunded_for_item($item_id);
                $amount = (float) $item->get_total() + (float) $item->get_total_tax() - (float) $order->get_total_refunded_for_item($item_id);

                foreach ((array) $item->get_taxes()['total'] as $tax_id => $tax) {
                    $amount -= (float) $order->get_tax_refunded_for_item($item_id, $tax_id);
                }

                if ($qty <= 0 && $amount <= 0) {
                    continue;
                }

                $product = $item instanceof \WC_Order_Item_Product ? $item->get_product() : null;
                $out['products'][$name]['sku'] = $product ? (string) $product->get_sku() : '';
                $out['products'][$name]['qty'] = (isset($out['products'][$name]['qty']) ? $out['products'][$name]['qty'] : 0) + $qty;
                $out['products'][$name]['total'] = (isset($out['products'][$name]['total']) ? $out['products'][$name]['total'] : 0) + $amount;
            }
        }

        return $out;
    }

    public static function money($amount)
    {
        return html_entity_decode(wp_strip_all_tags(wc_price($amount)), ENT_QUOTES, 'UTF-8');
    }

    /**
     * Fenêtre « caisse » de la feuille d'appel : catalogues en onglets, produits en tuiles, panier,
     * client, et paiement (sur place ou lien par SMS). Le script est assets/caisse.js.
     */
    public static function register(array $creneau, $intervenant_id = null, array $ajax = array())
    {
        $d = 'smbb-tomo';
        $atelier = Lookup::atelier($creneau['atelier_id']);
        $catalogues = \Smbb\Tomo\Repository\Catalogues::forContext((int) $creneau['atelier_id'], $intervenant_id);
        $products = array();
        $tabs = array();

        foreach ($catalogues as $catalogue) {
            if (!$catalogue['products'] && !self::hasChildProducts($catalogues, $catalogue['id'])) {
                continue;
            }

            $tabs[] = array('id' => $catalogue['id'], 'name' => $catalogue['name'], 'depth' => $catalogue['depth'], 'parent' => $catalogue['parent_id']);

            foreach ($catalogue['products'] as $product) {
                self::productData($products, $product, $catalogue['id']);
            }
        }

        // Anciens produits de l'atelier, hors catalogue.
        $legacy = array();

        foreach (self::products($atelier, $intervenant_id) as $product) {
            if (!isset($products[(int) $product->get_id()])) {
                self::productData($products, $product, -1);
                $legacy[] = 1;
            }
        }

        if ($legacy) {
            $tabs[] = array('id' => -1, 'name' => __('Produits de l\'atelier', $d), 'depth' => 0, 'parent' => 0);
        }

        $methods = '';

        foreach (self::methods() as $key => $label) {
            $methods .= '<option value="' . esc_attr($key) . '">' . esc_html($label) . '</option>';
        }

        $html = '<div class="tomo-pos" data-tomo-pos hidden role="dialog" aria-modal="true" aria-labelledby="tomo-pos-title">'
            . \Smbb\Tomo\Admin\View::formStart(\Smbb\Tomo\Admin\Actions::ONSITE_SALE, (int) $creneau['id'], 'tomo-pos-box')
            . '<input type="hidden" name="inscription" value="" data-pos-inscription>'
            . '<input type="hidden" name="hors_date" value="0" data-tomo-force>'
            . '<div class="tomo-pos-head"><h2 id="tomo-pos-title" data-pos-title>' . esc_html__('Nouvelle vente', $d) . '</h2><button type="button" class="tomo-pos-x" data-pos-close aria-label="' . esc_attr__('Fermer', $d) . '">×</button></div>'
            . '<div class="tomo-pos-body">'
            . '<div class="tomo-pos-shop">'
            . '<div class="tomo-pos-tabs" data-pos-tabs role="tablist"></div>'
            . '<input type="search" class="tomo-pos-search" data-pos-search placeholder="' . esc_attr__('Rechercher un produit, une référence…', $d) . '" autocomplete="off">'
            . '<div class="tomo-pos-grid" data-pos-grid></div>'
            . '</div>'
            . '<div class="tomo-pos-cart">'
            . '<h3>' . esc_html__('Panier', $d) . '</h3>'
            . '<ul class="tomo-pos-lines" data-pos-lines></ul>'
            . '<p class="tomo-pos-empty" data-pos-empty>' . esc_html__('Touchez un produit pour l\'ajouter.', $d) . '</p>'
            . '<p class="tomo-pos-total">' . esc_html__('Total', $d) . ' <strong data-pos-total>0</strong></p>'
            . '<details class="tomo-pos-customer" data-pos-customer><summary data-pos-customer-label>' . esc_html__('Client', $d) . '</summary>'
            . '<label>' . esc_html__('Prénom', $d) . '<input type="text" name="customer[first_name]" data-pos-field="first"></label>'
            . '<label>' . esc_html__('Nom', $d) . '<input type="text" name="customer[last_name]" data-pos-field="last"></label>'
            . '<label>' . esc_html__('Téléphone', $d) . '<input type="tel" name="customer[phone]" data-pos-field="phone"></label>'
            . '<label>' . esc_html__('E-mail (compte client)', $d) . '<input type="email" name="customer[email]" data-pos-field="email" autocomplete="off"></label>'
            . '</details>'
            . '<div class="tomo-pos-pay">'
            . '<div class="tomo-pos-paid"><select name="method" aria-label="' . esc_attr__('Moyen de paiement', $d) . '">' . $methods . '</select>'
            . '<button type="submit" name="mode" value="paid" class="button button-primary button-large" data-pos-submit>' . esc_html__('Payé sur place', $d) . '</button></div>'
            . '<button type="submit" name="mode" value="link" class="button button-large" data-pos-submit><span class="dashicons dashicons-smartphone" aria-hidden="true"></span> ' . esc_html__('Envoyer le lien de paiement par SMS', $d) . '</button>'
            . '</div>'
            . '<p class="tomo-pos-error" data-pos-error role="alert" hidden></p>'
            . '<div data-pos-qty></div>'
            . '</div></div></form></div>'
            // Fenêtre de confirmation (retour en bon d'achat) et messages, partagées par les deux onglets.
            . '<div class="tomo-ask" data-tomo-ask hidden role="alertdialog" aria-modal="true" aria-labelledby="tomo-ask-title" aria-describedby="tomo-ask-text">'
            . '<div class="tomo-ask-box"><div class="tomo-ask-icon" aria-hidden="true"><span class="dashicons dashicons-tickets-alt"></span></div>'
            . '<h2 id="tomo-ask-title" data-ask-title></h2><div id="tomo-ask-text" class="tomo-ask-text" data-ask-text></div>'
            . '<div class="tomo-ask-actions"><button type="button" class="button button-large" data-ask-cancel>' . esc_html__('Annuler', $d) . '</button><button type="button" class="button button-primary button-large" data-ask-ok></button></div>'
            . '</div></div>'
            . '<div class="tomo-toasts" data-tomo-toasts aria-live="polite"></div>';

        wp_enqueue_script('tomo-caisse', SMBB_TOMO_URL . 'assets/caisse.js', array(), SMBB_TOMO_VERSION, true);
        wp_add_inline_script('tomo-caisse', 'window.tomoCaisse=' . wp_json_encode(array(
            'tabs' => $tabs,
            'products' => array_values($products),
            'currency' => get_woocommerce_currency(),
            'ajax' => $ajax,
            'texts' => array(
                'all' => __('Tout', $d),
                'newSale' => __('Nouvelle vente', $d),
                'saleFor' => __('Vente · %s', $d),
                'client' => __('Client', $d),
                'empty' => __('Aucun produit ici.', $d),
                'pickOne' => __('Choisissez au moins un produit.', $d),
                'needEmail' => __("Indiquez l'e-mail du client : son compte est créé avec, pour qu'il retrouve ses achats.", $d),
                'needContact' => __('Pour envoyer un lien de paiement, il faut un téléphone ou un e-mail.', $d),
                'noStock' => __('Rupture', $d),
                'ref' => __('Réf. %s', $d),
                'sending' => __('Enregistrement…', $d),
                'network' => __('Pas de réseau : rien n\'a été enregistré, réessayez.', $d),
                'askTitle' => __("Retour en bon d'achat", $d),
                'askOk' => __('Créer le bon', $d),
                'askIntro' => __('%1$s, commande n° %2$s', $d),
                'askTotal' => __("Bon d'achat de %s, à usage unique, sur le compte du client.", $d),
                'askSend' => __('Le code part par SMS et e-mail (%s).', $d),
                'askNoSend' => __("Le code n'est pas envoyé : donnez-le au client.", $d),
                'askStock' => __('Les articles reviennent en stock.', $d),
                'returned' => __('Retour enregistré', $d),
                'cancelTitle' => __('Annuler cette vente ?', $d),
                'cancelOk' => __('Annuler la vente', $d),
                'cancelText' => __("Elle n'est pas payée : le lien de paiement ne fonctionnera plus, les articles reviennent en stock et la vente disparaît de la liste.", $d),
            ),
        )) . ';', 'before');

        return $html;
    }

    private static function hasChildProducts(array $catalogues, $id)
    {
        foreach ($catalogues as $catalogue) {
            if ((int) $catalogue['parent_id'] === (int) $id && ($catalogue['products'] || self::hasChildProducts($catalogues, $catalogue['id']))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int,array<string,mixed>> $products
     * @param \WC_Product                    $product
     */
    private static function productData(array &$products, $product, $catalogue_id)
    {
        $id = (int) $product->get_id();

        if (isset($products[$id])) {
            $products[$id]['cats'][] = (int) $catalogue_id;

            return;
        }

        $price = (float) wc_get_price_to_display($product);
        $text = $product->get_short_description() ?: $product->get_description();
        $image = $product->get_image_id() ? wp_get_attachment_image_url((int) $product->get_image_id(), 'woocommerce_thumbnail') : '';

        $products[$id] = array(
            'id' => $id,
            'name' => $product->get_name(),
            'sku' => (string) $product->get_sku(),
            'desc' => wp_trim_words(wp_strip_all_tags(strip_shortcodes((string) $text)), 14, '…'),
            'price' => $price,
            'img' => $image ?: '',
            'stock' => $product->managing_stock() ? (int) $product->get_stock_quantity() : null,
            'cats' => array((int) $catalogue_id),
        );
    }

    /**
     * @param \WC_Order $order
     */
    public static function total($order)
    {
        return html_entity_decode(wp_strip_all_tags(wc_price($order->get_total(), array('currency' => $order->get_currency()))), ENT_QUOTES, 'UTF-8');
    }
}
