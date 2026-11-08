<?php

namespace Smbb\Passflow\WooCommerce;

use Smbb\Passflow\Repository\Lookup;
use Smbb\Passflow\Service\Notifier;

defined('ABSPATH') || exit;

/**
 * Vente sur place : l'intervenant vend des produits liés à l'atelier (livre, ustensiles, boîte de
 * mochis…) le jour de l'atelier. Chaque vente est une vraie commande WooCommerce :
 * - payée sur place (carte, espèces…) : commande terminée tout de suite, stock décompté ;
 * - à payer en ligne : commande en attente de paiement, lien de paiement envoyé par SMS et e-mail.
 *
 * Méta de commande : _passflow_onsite_slot (créneau), _passflow_onsite_by (qui a vendu).
 */
final class OnsiteSales
{
    const META_SLOT = '_passflow_onsite_slot';
    const META_BY = '_passflow_onsite_by';
    const GATEWAY = 'passflow_onsite';
    const META_BOOKING = '_passflow_onsite_booking';
    const META_METHOD = '_passflow_onsite_method';

    /**
     * @return array<string,string>
     */
    public static function methods()
    {
        return array(
            'card' => __('Carte bancaire (terminal)', 'smbb-passflow'),
            'cash' => __('Espèces', 'smbb-passflow'),
            'check' => __('Chèque', 'smbb-passflow'),
            'other' => __('Autre', 'smbb-passflow'),
        );
    }

    /**
     * Produits vendables sur place pour un atelier, dans l'ordre choisi.
     *
     * @return \WC_Product[]
     */
    public static function products($activity, $host_id = null)
    {
        if (!$activity || !Module::active()) {
            return array();
        }

        // Catalogue sur place (ateliers / intervenants concernés), puis anciens produits de l'atelier.
        $products = \Smbb\Passflow\Repository\Catalogs::productsFor((int) $activity['id'], $host_id);

        foreach (Lookup::ids(isset($activity['extra_product_ids']) ? $activity['extra_product_ids'] : array()) as $id) {
            $product = wc_get_product($id);

            if ($product && !isset($products[(int) $id]) && self::sellable($product)) {
                $products[(int) $id] = $product;
            }
        }

        return array_values($products);
    }

    /**
     * Produit proposable à la caisse : simple ou variable (le choix de la variante se fait à l'ajout),
     * publié et achetable.
     *
     * @param \WC_Product $product
     */
    public static function sellable($product)
    {
        return $product && $product->is_type(array('simple', 'variable')) && $product->get_status() === 'publish' && $product->is_purchasable()
            && ($product->is_type('simple') || self::options($product));
    }

    /**
     * Ce qui se vend réellement d'un produit : lui-même s'il est simple, sinon chacune de ses variantes
     * actives (taille, couleur…). Une variante « n'importe quelle taille » donne une option par valeur
     * possible, pour que la ligne de commande porte toujours la taille vendue.
     *
     * Clé : id du produit ou de la variante, suivie d'un suffixe quand la variante a été dépliée.
     *
     * @param \WC_Product $product
     * @return array<string,array{product:\WC_Product,variation:array<string,string>,label:string}>
     */
    public static function options($product)
    {
        if (!$product->is_type('variable')) {
            return array((string) $product->get_id() => array('product' => $product, 'variation' => array(), 'label' => ''));
        }

        $values = array();

        foreach ($product->get_variation_attributes() as $name => $list) {
            $values['attribute_' . sanitize_title($name)] = array('taxonomy' => taxonomy_exists($name) ? $name : '', 'values' => array_values((array) $list));
        }

        $out = array();

        foreach ($product->get_children() as $child_id) {
            $variation = wc_get_product($child_id);

            if (!$variation || !$variation->is_type('variation') || $variation->get_status() !== 'publish' || !$variation->is_purchasable()) {
                continue;
            }

            $combos = array(array());

            foreach ($variation->get_variation_attributes() as $key => $value) {
                $choices = (string) $value !== '' ? array((string) $value) : (isset($values[$key]) ? $values[$key]['values'] : array());
                $next = array();

                foreach ($combos as $combo) {
                    foreach ($choices as $choice) {
                        $next[] = $combo + array($key => (string) $choice);
                    }
                }

                $combos = array_slice($next, 0, 60);
            }

            $full = !in_array('', array_map('strval', $variation->get_variation_attributes()), true);

            foreach ($combos as $combo) {
                $label = array();

                foreach ($combo as $key => $value) {
                    $taxonomy = isset($values[$key]) ? $values[$key]['taxonomy'] : '';
                    $term = $taxonomy !== '' ? get_term_by('slug', $value, $taxonomy) : null;
                    $label[] = $term && !is_wp_error($term) ? $term->name : $value;
                }

                $key = (string) $variation->get_id() . ($full ? '' : '-' . substr(md5(wp_json_encode($combo)), 0, 8));
                $out[$key] = array(
                    'product' => $variation,
                    'variation' => $combo,
                    'label' => $label ? implode(' · ', $label) : wp_strip_all_tags($variation->get_name()),
                );
            }
        }

        return $out;
    }

    /**
     * Stock affiché à la caisse : quantité si le stock est suivi, 0 si en rupture, null sinon.
     *
     * @param \WC_Product $product
     * @return int|null
     */
    private static function stockOf($product)
    {
        if ($product->managing_stock()) {
            return $product->backorders_allowed() ? null : (int) $product->get_stock_quantity();
        }

        return $product->is_in_stock() ? null : 0;
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

        $activity_products = array_filter(array_map('intval', array_column(Lookup::ateliers(), 'product_id')));
        $choices = array();

        foreach (wc_get_products(array('status' => 'publish', 'limit' => 300, 'orderby' => 'title', 'order' => 'ASC', 'type' => array('simple', 'variation'))) as $product) {
            if (in_array((int) $product->get_id(), $activity_products, true)) {
                continue;
            }

            $choices[(int) $product->get_id()] = $product->get_name() . ' · ' . html_entity_decode(wp_strip_all_tags(wc_price($product->get_price())), ENT_QUOTES, 'UTF-8');
        }

        return $choices;
    }

    /**
     * @param array<string,mixed> $slot
     * @param array<string,int>   $lines    clé d'option (produit ou variante, voir options()) => quantité
     * @param array<string,string> $customer first_name, last_name, email, phone
     * @param string $mode   paid|link
     * @param string $method card|cash|check|other (mode paid)
     * @return array{success:bool,message:string,order_id?:int}
     */
    public static function create(array $slot, array $lines, array $customer, $mode, $method = 'card', $host_id = null, $customer_id = 0, $booking_id = 0)
    {
        $d = 'smbb-passflow';
        $activity = Lookup::atelier($slot['activity_id']);
        $allowed = array();

        foreach (self::products($activity, $host_id) as $product) {
            foreach (self::options($product) as $key => $option) {
                $allowed[(string) $key] = $option;
            }
        }

        $lines = array_filter(array_map('intval', $lines), function ($qty) {
            return $qty > 0;
        });
        $lines = array_intersect_key($lines, $allowed);

        if (!$lines) {
            return array('success' => false, 'message' => __('Choisissez au moins un produit.', $d));
        }

        $email = isset($customer['email']) ? strtolower(sanitize_email($customer['email'])) : '';
        $phone = isset($customer['phone']) ? \Smbb\Passflow\Support\Input::phone($customer['phone']) : '';

        // Stock vérifié par produit qui le porte : des variantes peuvent partager celui du produit parent.
        $needs = array();

        foreach ($lines as $key => $qty) {
            $product = $allowed[$key]['product'];
            $owner = $product->managing_stock() ? (int) $product->get_stock_managed_by_id() : 'p' . $product->get_id();
            $needs[$owner] = array('product' => $product, 'qty' => (isset($needs[$owner]) ? $needs[$owner]['qty'] : 0) + $qty, 'name' => self::lineName($allowed[$key]));
        }

        foreach ($needs as $need) {
            if (!$need['product']->is_in_stock() || !$need['product']->has_enough_stock($need['qty'])) {
                return array('success' => false, 'message' => sprintf(__('Stock insuffisant pour %s.', $d), $need['name']));
            }
        }

        // Pas de commande sans compte : celui du participant, sinon celui qui a cet e-mail, sinon il est créé
        // (la personne reçoit un lien pour choisir son mot de passe et retrouve son historique).
        $customer_id = (int) $customer_id;

        if (!$customer_id) {
            if ($email === '') {
                return array('success' => false, 'message' => __("Indiquez l'e-mail du client : son compte est créé avec, pour qu'il retrouve ses achats.", $d), 'need' => 'email');
            }

            $account = \Smbb\Passflow\Service\Accounts::customer($email, isset($customer['first_name']) ? sanitize_text_field($customer['first_name']) : '', isset($customer['last_name']) ? sanitize_text_field($customer['last_name']) : '', $phone, $slot);

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

        if ($booking_id) {
            $order->update_meta_data(self::META_BOOKING, (int) $booking_id);
        }

        foreach ($lines as $key => $qty) {
            $option = $allowed[$key];
            // Variante : la ligne porte la variation (stock décompté sur elle) et ses attributs (taille…).
            $order->add_product($option['product'], $qty, $option['variation'] ? array('variation' => $option['variation'], 'name' => self::lineName($option)) : array());
        }

        $order->set_billing_first_name(isset($customer['first_name']) ? sanitize_text_field($customer['first_name']) : '');
        $order->set_billing_last_name(isset($customer['last_name']) ? sanitize_text_field($customer['last_name']) : '');
        $order->set_billing_email($email);
        $order->set_billing_phone($phone);
        $order->update_meta_data(self::META_SLOT, (int) $slot['id']);
        $order->update_meta_data(self::META_BY, get_current_user_id());
        $order->calculate_totals();

        $seller = wp_get_current_user()->display_name;
        $where = Lookup::slotLabel($slot);

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
        $sent = self::sendLink($order, $slot);

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
    public static function sendLink($order, $slot = null)
    {
        $d = 'smbb-passflow';
        $items = array();

        foreach ($order->get_items() as $item) {
            $items[] = $item->get_quantity() . ' × ' . $item->get_name();
        }

        $results = Notifier::toCustomer('payment_link', array(
            'first_name' => $order->get_billing_first_name(),
            'last_name' => $order->get_billing_last_name(),
            'email' => $order->get_billing_email(),
            'phone' => $order->get_billing_phone(),
        ), $slot, array(
            // Client avec un compte : le lien le connecte tout seul (10 minutes), sans mot de passe.
            // Page Passflow « Régler votre commande » (bon d'achat possible), puis paiement WooCommerce.
            'lien_paiement' => $order->get_customer_id() ? \Smbb\Passflow\Support\LoginLink::create($order->get_customer_id(), PayPage::url($order)) : PayPage::url($order),
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
    public static function forSlot($slot_id)
    {
        if (!Module::active()) {
            return array();
        }

        return wc_get_orders(array(
            'limit' => -1,
            'orderby' => 'date',
            'order' => 'DESC',
            'meta_key' => self::META_SLOT, // phpcs:ignore WordPress.DB.SlowDBQuery
            'meta_value' => (int) $slot_id, // phpcs:ignore WordPress.DB.SlowDBQuery
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
            $label = isset($labels[$method]) ? $labels[$method] : ($order->get_payment_method() === self::GATEWAY ? __('Sur place', 'smbb-passflow') : __('Paiement en ligne (lien)', 'smbb-passflow'));
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
     * client, et paiement (sur place ou lien par SMS). Le script est assets/cashdesk.js.
     */
    public static function register(array $slot, $host_id = null, array $ajax = array())
    {
        $d = 'smbb-passflow';
        $activity = Lookup::atelier($slot['activity_id']);
        $catalogs = \Smbb\Passflow\Repository\Catalogs::forContext((int) $slot['activity_id'], $host_id);
        $products = array();
        $tabs = array();

        foreach ($catalogs as $catalog) {
            if (!$catalog['products'] && !self::hasChildProducts($catalogs, $catalog['id'])) {
                continue;
            }

            $tabs[] = array('id' => $catalog['id'], 'name' => $catalog['name'], 'depth' => $catalog['depth'], 'parent' => $catalog['parent_id']);

            foreach ($catalog['products'] as $product) {
                self::productData($products, $product, $catalog['id']);
            }
        }

        // Anciens produits de l'atelier, hors catalogue.
        $legacy = array();

        foreach (self::products($activity, $host_id) as $product) {
            if (!isset($products[(int) $product->get_id()])) {
                self::productData($products, $product, -1);
                $legacy[] = 1;
            }
        }

        if ($legacy) {
            $tabs[] = array('id' => -1, 'name' => __('Produits de l\'atelier', $d), 'depth' => 0, 'parent' => 0);
        }

        // Une seule liste « comment le client règle » : lien par SMS, ou payé sur place (espèces, carte, autre).
        $methods = '';
        $choices = array(
            'link' => __('Lien de paiement par SMS', $d),
            'cash' => __('Espèces (sur place)', $d),
            'card' => __('Carte (sur place)', $d),
            'other' => __('Autre', $d),
        );

        foreach ($choices as $key => $label) {
            $methods .= '<option value="' . esc_attr($key) . '"' . selected($key, 'card', false) . '>' . esc_html($label) . '</option>';
        }

        $html = '<div class="passflow-pos" data-passflow-pos hidden role="dialog" aria-modal="true" aria-labelledby="passflow-pos-title">'
            . \Smbb\Passflow\Admin\View::formStart(\Smbb\Passflow\Admin\Actions::ONSITE_SALE, (int) $slot['id'], 'passflow-pos-box')
            . '<input type="hidden" name="inscription" value="" data-pos-booking>'
            . '<input type="hidden" name="hors_date" value="0" data-passflow-force>'
            . '<div class="passflow-pos-head"><h2 id="passflow-pos-title" data-pos-title>' . esc_html__('Nouvelle vente', $d) . '</h2>'
            // Gestion mobile PDA (assets/pda.css) : encaissé du jour et accès aux ventes, dans l'en-tête.
            . '<span class="passflow-pos-day" data-pos-day hidden><small>' . esc_html__("Encaissé aujourd'hui", $d) . '</small><strong data-pos-day-total></strong></span>'
            . '<a class="passflow-pos-sales" href="#" data-passflow-tab="caisse" data-pos-close title="' . esc_attr__('Ventes de la journée', $d) . '"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 6h16M4 12h16M4 18h10"/></svg><span class="screen-reader-text">' . esc_html__('Ventes de la journée', $d) . '</span></a>'
            . '<button type="button" class="passflow-pos-x" data-pos-close aria-label="' . esc_attr__('Fermer', $d) . '">×</button></div>'
            . '<div class="passflow-pos-body">'
            . '<div class="passflow-pos-shop">'
            . '<div class="passflow-pos-tabs" data-pos-tabs role="tablist"></div>'
            . '<input type="search" class="passflow-pos-search" data-pos-search placeholder="' . esc_attr__('Rechercher un produit, une référence…', $d) . '" autocomplete="off">'
            . '<div class="passflow-pos-grid" data-pos-grid></div>'
            . '</div>'
            . '<div class="passflow-pos-cart">'
            . '<h3>' . esc_html__('Panier', $d) . '</h3>'
            // Mobile : barre du panier repliable (nombre d'articles + total), collée en bas avec les boutons de paiement.
            . '<button type="button" class="passflow-pos-sheet" data-pos-sheet aria-expanded="false" aria-controls="passflow-pos-detail">'
            . '<span class="passflow-pos-sheet-title"><span class="dashicons dashicons-cart" aria-hidden="true"></span> ' . esc_html__('Panier', $d) . ' <span class="passflow-pos-sheet-count" data-pos-count>0</span></span>'
            . '<strong class="passflow-pos-sheet-total" data-pos-sheet-total>0</strong><span class="passflow-pos-sheet-chev" aria-hidden="true"></span></button>'
            // PDA : « Encaisser » ouvre la feuille des moyens de paiement (un toucher = vente validée).
            . '<button type="button" class="passflow-pos-checkout" data-pos-checkout><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg>' . esc_html__('Encaisser', $d) . '</button>'
            . '<div class="passflow-pos-detail" id="passflow-pos-detail" data-pos-detail>'
            . '<ul class="passflow-pos-lines" data-pos-lines></ul>'
            . '<p class="passflow-pos-empty" data-pos-empty>' . esc_html__('Touchez un produit pour l\'ajouter.', $d) . '</p>'
            . '<p class="passflow-pos-total">' . esc_html__('Total', $d) . ' <strong data-pos-total>0</strong></p>'
            . '<details class="passflow-pos-customer" data-pos-customer><summary data-pos-customer-label>' . esc_html__('Client', $d) . '</summary>'
            . '<label>' . esc_html__('Prénom', $d) . '<input type="text" name="customer[first_name]" data-pos-field="first"></label>'
            . '<label>' . esc_html__('Nom', $d) . '<input type="text" name="customer[last_name]" data-pos-field="last"></label>'
            . '<label>' . esc_html__('Téléphone', $d) . '<input type="tel" name="customer[phone]" data-pos-field="phone"></label>'
            . '<label>' . esc_html__('E-mail (compte client)', $d) . '<input type="email" name="customer[email]" data-pos-field="email" autocomplete="off"></label>'
            . '</details>'
            . '</div>'
            . '<div class="passflow-pos-pay">'
            . '<input type="hidden" name="mode" value="paid" data-pos-mode>'
            . '<div class="passflow-pos-paid"><select name="method" data-pos-method aria-label="' . esc_attr__('Moyen de paiement', $d) . '">' . $methods . '</select>'
            . '<button type="submit" class="button button-primary button-large" data-pos-submit data-label-paid="' . esc_attr__('Valider', $d) . '" data-label-link="' . esc_attr__('Envoyer le SMS', $d) . '">' . esc_html__('Valider', $d) . '</button></div>'
            . '</div>'
            . '<p class="passflow-pos-error" data-pos-error role="alert" hidden></p>'
            . '<div class="passflow-pos-paysheet" data-pos-paysheet hidden><div class="passflow-pos-paysheet-box" role="dialog" aria-modal="true" aria-labelledby="passflow-pos-pay-who">'
            . '<span class="passflow-pos-grab" aria-hidden="true"></span>'
            . '<h3 id="passflow-pos-pay-who" data-pos-pay-who>' . esc_html__('À encaisser', $d) . '</h3>'
            . '<strong class="passflow-pos-paysheet-total" data-pos-pay-total></strong>'
            . '<p class="passflow-pos-paysheet-lines" data-pos-pay-lines></p>'
            . '<div class="passflow-pos-methods">'
            . '<button type="button" class="passflow-pos-method is-main" data-pos-pay="card"><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20M6 15h4"/></svg><strong>' . esc_html__('Carte', $d) . '</strong><small>' . esc_html__('sur place', $d) . '</small></button>'
            . '<button type="button" class="passflow-pos-method" data-pos-pay="cash"><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="2" y="6" width="20" height="12" rx="2"/><circle cx="12" cy="12" r="2.5"/></svg><strong>' . esc_html__('Espèces', $d) . '</strong><small>' . esc_html__('sur place', $d) . '</small></button>'
            . '<button type="button" class="passflow-pos-method" data-pos-pay="link"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 5h16v11H9l-5 4z"/><path d="M8 10h8"/></svg><strong>' . esc_html__('Lien SMS', $d) . '</strong><small data-pos-pay-sub></small></button>'
            . '<button type="button" class="passflow-pos-method" data-pos-pay="other"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="5" cy="12" r="1.2"/><circle cx="12" cy="12" r="1.2"/><circle cx="19" cy="12" r="1.2"/></svg><strong>' . esc_html__('Autre', $d) . '</strong><small>' . esc_html__('chèque, bon…', $d) . '</small></button>'
            . '</div>'
            . '<button type="button" class="passflow-pos-paysheet-back" data-pos-pay-back>' . esc_html__('Retour au panier', $d) . '</button>'
            . '</div></div>'
            . '<div data-pos-qty></div>'
            . '</div></div></form></div>'
            // Fenêtre de confirmation (retour en bon d'achat) et messages, partagées par les deux onglets.
            . '<div class="passflow-ask" data-passflow-ask hidden role="alertdialog" aria-modal="true" aria-labelledby="passflow-ask-title" aria-describedby="passflow-ask-text">'
            . '<div class="passflow-ask-box"><div class="passflow-ask-icon" aria-hidden="true"><span class="dashicons dashicons-tickets-alt"></span></div>'
            . '<h2 id="passflow-ask-title" data-ask-title></h2><div id="passflow-ask-text" class="passflow-ask-text" data-ask-text></div>'
            . '<div class="passflow-ask-actions"><button type="button" class="button button-large" data-ask-cancel>' . esc_html__('Annuler', $d) . '</button><button type="button" class="button button-primary button-large" data-ask-ok></button></div>'
            . '</div></div>'
            . '<div class="passflow-toasts" data-passflow-toasts aria-live="polite"></div>';

        wp_enqueue_script('passflow-cashdesk', SMBB_PASSFLOW_URL . 'assets/cashdesk.js', array(), SMBB_PASSFLOW_VERSION, true);
        wp_add_inline_script('passflow-cashdesk', 'window.passflowCashdesk=' . wp_json_encode(array(
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
                'from' => __('dès %s', $d),
                'variants' => __('%s choix', $d),
                'pick' => __('Choisissez : %s', $d),
                'back' => __('Retour aux produits', $d),
                'left' => __('reste %s', $d),
                'scanOut' => __('Rupture : %s', $d),
                'scanUnknown' => __('Aucun produit avec la référence %s.', $d),
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

    private static function hasChildProducts(array $catalogs, $id)
    {
        foreach ($catalogs as $catalog) {
            if ((int) $catalog['parent_id'] === (int) $id && ($catalog['products'] || self::hasChildProducts($catalogs, $catalog['id']))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int,array<string,mixed>> $products
     * @param \WC_Product                    $product
     */
    private static function productData(array &$products, $product, $catalog_id)
    {
        $id = (int) $product->get_id();

        if (isset($products[$id])) {
            $products[$id]['cats'][] = (int) $catalog_id;

            return;
        }

        $price = (float) wc_get_price_to_display($product);
        $text = $product->get_short_description() ?: $product->get_description();
        $image = $product->get_image_id() ? wp_get_attachment_image_url((int) $product->get_image_id(), 'woocommerce_thumbnail') : '';
        $variants = null;

        // Produit variable : une tuile, et le choix de la variante (taille…) au moment de l'ajout.
        if ($product->is_type('variable')) {
            $variants = array();

            foreach (self::options($product) as $key => $option) {
                $variation = $option['product'];
                $variants[] = array(
                    'key' => (string) $key,
                    'label' => $option['label'],
                    'sku' => $variation->get_sku() !== $product->get_sku() ? (string) $variation->get_sku() : '',
                    // Référence propre à la variante : un scan la met directement au panier.
                    'code' => $variation->get_sku() !== $product->get_sku() ? (string) $variation->get_sku() : '',
                    'price' => (float) wc_get_price_to_display($variation),
                    'stock' => self::stockOf($variation),
                    // Variantes qui partagent le stock du parent : même réserve.
                    'pool' => $variation->managing_stock() ? (string) $variation->get_stock_managed_by_id() : (string) $key,
                );
            }
        }

        $products[$id] = array(
            'id' => $id,
            'name' => $product->get_name(),
            'sku' => (string) $product->get_sku(),
            'desc' => wp_trim_words(wp_strip_all_tags(strip_shortcodes((string) $text)), 14, '…'),
            'price' => $price,
            'img' => $image ?: '',
            'stock' => $variants === null ? self::stockOf($product) : null,
            'variants' => $variants,
            'cats' => array((int) $catalog_id),
        );
    }

    /**
     * Libellé d'une ligne : « T-shirt – M · Bleu » pour une variante.
     *
     * @param array{product:\WC_Product,variation:array<string,string>,label:string} $option
     */
    private static function lineName(array $option)
    {
        $product = $option['product'];

        if (!$option['variation']) {
            return $product->get_name();
        }

        $parent = wc_get_product($product->get_parent_id());

        return ($parent ? $parent->get_name() : $product->get_name()) . ' – ' . $option['label'];
    }

    /**
     * @param \WC_Order $order
     */
    public static function total($order)
    {
        return html_entity_decode(wp_strip_all_tags(wc_price($order->get_total(), array('currency' => $order->get_currency()))), ENT_QUOTES, 'UTF-8');
    }
}
