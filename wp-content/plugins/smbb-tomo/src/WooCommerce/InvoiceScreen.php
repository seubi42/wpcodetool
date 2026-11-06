<?php

namespace Smbb\Tomo\WooCommerce;

use Smbb\Tomo\Admin\View;
use Smbb\Tomo\Service\Notifier;
use Smbb\Tomo\Support\Roles;
use Smbb\Tomo\Support\Settings;

defined('ABSPATH') || exit;

/**
 * Écrans des factures :
 * - « Établir mes factures » : répartition des lignes (ou des participants) entre factures, coordonnées
 *   de chaque facture, émission groupée en Ajax. Le même écran sert dans Mon compte et dans l'admin ;
 * - Mon compte › Factures (liste, PDF, demande de correction) et bouton « Factures » sur chaque commande ;
 * - encadré « Factures » dans la commande WooCommerce (émettre, corriger, renvoyer) ;
 * - actions de l'admin : PDF, renvoi, correction par avoir, export CSV, ZIP du mois, aperçu.
 */
final class InvoiceScreen
{
    const AJAX = 'tomo_invoice';
    const PDF = 'tomo_invoice_pdf';
    const RESEND = 'tomo_invoice_resend';
    const CORRECT = 'tomo_invoice_correct';
    const CSV = 'tomo_invoice_csv';
    const ZIP = 'tomo_invoice_zip';
    const PREVIEW = 'tomo_invoice_preview';
    const ENDPOINT = 'factures';
    const PROFILES = 'smbb_tomo_billing_profiles';
    const MAX_GROUPS = 6;

    public static function hooks()
    {
        add_action('wp_ajax_' . self::AJAX, array(__CLASS__, 'ajax'));

        foreach (array(self::PDF => 'pdf', self::RESEND => 'resend', self::CORRECT => 'correct', self::CSV => 'csv', self::ZIP => 'zip', self::PREVIEW => 'preview') as $action => $method) {
            add_action('admin_post_' . $action, array(__CLASS__, $method));
        }

        add_action('add_meta_boxes', array(__CLASS__, 'register'), 31);

        // Mon compte.
        add_action('init', array(__CLASS__, 'endpoint'), 5); // avant le vidage des règles d'URL (Account::endpoint)
        add_filter('woocommerce_get_query_vars', array(__CLASS__, 'queryVars'));
        add_filter('woocommerce_account_menu_items', array(__CLASS__, 'menu'), 20);
        add_filter('woocommerce_endpoint_' . self::ENDPOINT . '_title', array(__CLASS__, 'title'));
        add_action('woocommerce_account_' . self::ENDPOINT . '_endpoint', array(__CLASS__, 'account'));
        add_filter('woocommerce_my_account_my_orders_actions', array(__CLASS__, 'orderAction'), 20, 2);
        add_action('template_redirect', array(__CLASS__, 'request'));
        add_action('woocommerce_order_details_after_order_table', array(__CLASS__, 'orderLink'), 20);
    }

    /**
     * L'équipe sur toute commande ; le client sur les siennes, si le réglage l'y autorise.
     *
     * @param \WC_Order $order
     */
    public static function canIssue($order)
    {
        if (Roles::canManage()) {
            return true;
        }

        return (int) Settings::get('invoice_customer') === 1 && self::owns($order);
    }

    /**
     * @param \WC_Order|null $order
     */
    private static function owns($order)
    {
        return $order && is_user_logged_in() && (int) $order->get_customer_id() === get_current_user_id();
    }

    public static function accountUrl($order = null)
    {
        $url = wc_get_account_endpoint_url(self::ENDPOINT);

        return $order ? add_query_arg('commande', (int) $order->get_id(), $url) : $url;
    }

    public static function adminUrl($order_id, array $args = array())
    {
        return View::url('tomo_factures', array_merge(array('commande' => (int) $order_id), $args));
    }

    // ------------------------------------------------------------------
    // Écran de répartition
    // ------------------------------------------------------------------

    /**
     * @param \WC_Order                 $order
     * @param string                    $context admin | account
     * @param array<string,mixed>|null  $prefill facture corrigée : ses coordonnées et ses lignes en « Facture 1 »
     */
    public static function screen($order, $context, $prefill = null)
    {
        $d = 'smbb-tomo';
        $lines = Invoices::units($order);
        $open = 0;

        foreach ($lines as $line) {
            foreach ($line['units'] as $unit) {
                $open += $unit['state'] === 'open' ? 1 : 0;
            }
        }

        if (!$open) {
            $states = array('invoiced' => 0, 'refunded' => 0);

            foreach ($lines as $line) {
                foreach ($line['units'] as $unit) {
                    if (isset($states[$unit['state']])) {
                        $states[$unit['state']]++;
                    }
                }
            }

            $text = !$lines ? __("Cette commande n'a aucune ligne à facturer (montant nul).", $d)
                : ($states['invoiced'] ? __('Tout est déjà facturé sur cette commande.', $d) : __('Tout a été remboursé sur cette commande : il ne reste rien à facturer.', $d));

            return '<p class="tomo-inv-done">' . esc_html($text) . '</p>';
        }

        $preselect = $prefill ? self::preselect($lines, $prefill) : null;
        $billing = $prefill ? $prefill['billing'] : Invoices::orderBilling($order);
        $profiles = $context === 'account' ? self::profiles(get_current_user_id()) : array();

        wp_enqueue_style('smbb-tomo-factures', SMBB_TOMO_URL . 'assets/factures.css', array(), SMBB_TOMO_VERSION);
        wp_enqueue_script('smbb-tomo-factures', SMBB_TOMO_URL . 'assets/factures.js', array(), SMBB_TOMO_VERSION, true);
        wp_localize_script('smbb-tomo-factures', 'tomoInvoiceText', array(
            'confirmOne' => __('Émettre la facture ?', $d),
            'confirmHelp' => __("C'est définitif : une facture émise ne se modifie plus. Une erreur se corrigera par un avoir.", $d),
            'confirm' => __('Émettre', $d),
            'cancel' => __('Annuler', $d),
            'none' => __('Cochez au moins une ligne à facturer.', $d),
            'name' => __('Indiquez le nom ou la raison sociale.', $d),
            'error' => __('La demande n\'a pas abouti : vérifiez la connexion et réessayez.', $d),
        ));

        $html = '<div class="tomo-inv" data-tomo-inv data-url="' . esc_url(admin_url('admin-ajax.php')) . '" data-order="' . (int) $order->get_id() . '" data-context="' . esc_attr($context) . '"'
            . ' data-nonce="' . esc_attr(wp_create_nonce(self::AJAX . '_' . (int) $order->get_id())) . '" data-profiles="' . esc_attr(wp_json_encode($profiles)) . '">';

        // 1. Choisir les lignes.
        $html .= '<h3 class="tomo-inv-step"><span>1</span> ' . esc_html__('Cochez ce que vous voulez facturer', $d) . '</h3>'
            . '<p class="tomo-inv-help">' . esc_html__('Une facture par personne ou par société ? Décochez ce qui ira sur une autre facture : ces lignes restent disponibles ensuite.', $d) . '</p>'
            . '<table class="tomo-inv-lines"><tbody>';

        foreach ($lines as $line) {
            $open_units = array_values(array_filter($line['units'], function ($unit) {
                return $unit['state'] === 'open';
            }));
            $closed = array();

            foreach ($line['units'] as $unit) {
                if ($unit['state'] === 'invoiced') {
                    $closed[] = ($unit['name'] !== '' ? $unit['name'] . ' : ' : '') . sprintf(__('facturé (%s)', $d), $unit['number']);
                } elseif ($unit['state'] === 'refunded') {
                    $closed[] = ($unit['name'] !== '' ? $unit['name'] . ' : ' : '') . __('remboursé', $d);
                }
            }

            $price = OnsiteSales::money($line['unit_ttc']);

            if ($line['participants']) {
                // Une ligne par participant.
                $html .= '<tr class="tomo-inv-item"><th colspan="3"><strong>' . esc_html($line['label']) . '</strong> <span class="tomo-inv-price">' . esc_html(sprintf(__('%s par personne', $d), $price)) . '</span>'
                    . ($closed ? '<br><span class="tomo-inv-muted">' . esc_html(implode(' · ', array_unique($closed))) . '</span>' : '') . '</th></tr>';

                foreach ($open_units as $unit) {
                    $html .= '<tr><td class="tomo-inv-cb"><input type="checkbox" id="tomo-u-' . esc_attr(str_replace(':', '-', $unit['key'])) . '" data-tomo-inv-unit data-keys="' . esc_attr($unit['key']) . '" data-ttc="' . esc_attr((string) $line['unit_ttc']) . '"' . checked(self::chosen($unit, $preselect), true, false) . '></td>'
                        . '<td><label for="tomo-u-' . esc_attr(str_replace(':', '-', $unit['key'])) . '">' . esc_html($unit['name'] !== '' ? $unit['name'] : $line['label']) . '</label></td>'
                        . '<td class="num">' . esc_html($price) . '</td></tr>';
                }

                continue;
            }

            if (!$open_units) {
                $html .= '<tr class="is-closed"><td class="tomo-inv-cb"></td><td>' . esc_html($line['label']) . ($closed ? '<br><span class="tomo-inv-muted">' . esc_html(implode(' · ', array_unique($closed))) . '</span>' : '') . '</td><td></td></tr>';
                continue;
            }

            // Produit : une ligne, avec la quantité à facturer si elle est découpable.
            $keys = implode(',', wp_list_pluck($open_units, 'key'));
            $count = count($open_units);
            $chosen = self::chosen($open_units[0], $preselect);
            $qty = '';

            if ($count > 1) {
                $qty = ' <select class="tomo-inv-qty" data-tomo-inv-qty aria-label="' . esc_attr__('Quantité', $d) . '">';

                for ($n = $count; $n >= 1; $n--) {
                    $qty .= '<option value="' . $n . '">' . esc_html(sprintf(__('%1$d sur %2$d', $d), $n, $count)) . '</option>';
                }

                $qty .= '</select>';
            }

            $id = 'tomo-u-' . (int) $line['id'];
            $html .= '<tr><td class="tomo-inv-cb"><input type="checkbox" id="' . esc_attr($id) . '" data-tomo-inv-unit data-keys="' . esc_attr($keys) . '" data-ttc="' . esc_attr((string) $line['unit_ttc']) . '"' . checked($chosen, true, false) . '></td>'
                . '<td><label for="' . esc_attr($id) . '">' . esc_html($line['label']) . '</label>' . $qty
                . ($closed ? '<br><span class="tomo-inv-muted">' . esc_html(implode(' · ', array_unique($closed))) . '</span>' : '') . '</td>'
                . '<td class="num">' . esc_html($count > 1 ? sprintf(__('%s l\'unité', $d), $price) : $price) . '</td></tr>';
        }

        $html .= '</tbody><tfoot><tr><th colspan="2">' . esc_html__('Total de la facture', $d) . '</th><th class="num" data-tomo-inv-sum></th></tr></tfoot></table>'
            . '<p><button type="button" class="button button-primary tomo-inv-next" data-tomo-inv-next>' . esc_html__('Générer la facture', $d) . '</button></p>';

        // 2. Coordonnées (après « Générer la facture »).
        $html .= '<div class="tomo-inv-billing" data-tomo-inv-billing hidden>'
            . '<h3 class="tomo-inv-step"><span>2</span> ' . esc_html__('Au nom de qui ?', $d) . '</h3>'
            . self::group(1, $billing, $profiles, $context)
            . '<p class="tomo-inv-help">' . esc_html__('La facture part par e-mail, PDF joint, à cette adresse.', $d) . '</p>'
            . '<p><button type="button" class="button button-primary tomo-inv-submit" data-tomo-inv-submit>' . esc_html__('Émettre la facture', $d) . '</button> '
            . '<button type="button" class="tomo-inv-link" data-tomo-inv-back>' . esc_html__('Modifier les lignes', $d) . '</button></p></div>'
            . '<div class="tomo-inv-msg" data-tomo-inv-msg role="status"></div></div>';

        return $html;
    }

    /**
     * Coché par défaut : tout, sauf après une correction (seules les lignes de la facture corrigée).
     *
     * @param array<string,bool>|null $preselect
     */
    private static function chosen(array $unit, $preselect)
    {
        return $preselect === null || isset($preselect[$unit['key']]);
    }

    /**
     * Unités d'une facture corrigée (rouvertes par l'avoir) : présélectionnées en « Facture 1 ».
     *
     * @return array<string,bool>
     */
    private static function preselect(array $lines, array $invoice)
    {
        $wanted = array();

        foreach ($invoice['items'] as $line) {
            if ((int) $line['qty'] > 0) {
                $wanted[(int) $line['item_id']] = array('qty' => (int) $line['qty'], 'people' => array_map('intval', (array) $line['inscription_ids']));
            }
        }

        $keys = array();

        foreach ($lines as $line) {
            if (!isset($wanted[$line['id']])) {
                continue;
            }

            $left = $wanted[$line['id']]['qty'];
            $open = array_filter($line['units'], function ($unit) {
                return $unit['state'] === 'open';
            });

            usort($open, function ($a, $b) use ($wanted, $line) {
                return (int) !in_array($a['inscription_id'], $wanted[$line['id']]['people'], true) - (int) !in_array($b['inscription_id'], $wanted[$line['id']]['people'], true);
            });

            foreach ($open as $unit) {
                if ($left-- <= 0) {
                    break;
                }

                $keys[$unit['key']] = true;
            }
        }

        return $keys;
    }

    private static function group($g, array $b, array $profiles, $context)
    {
        $d = 'smbb-tomo';
        $b = array_merge(array('type' => 'person', 'name' => '', 'contact' => '', 'address' => '', 'postcode' => '', 'city' => '', 'country' => 'FR', 'email' => '', 'siren' => '', 'vat' => ''), $b);
        $field = function ($key, $label, $attrs = '') use ($g, $b) {
            return '<label class="tomo-inv-field tomo-inv-f-' . esc_attr($key) . '"><span>' . esc_html($label) . '</span><input type="text" name="g[' . $g . '][' . $key . ']" value="' . esc_attr($b[$key]) . '" ' . $attrs . '></label>';
        };

        $html = '<fieldset class="tomo-inv-group" data-tomo-inv-group="' . $g . '">'
            . '<legend>' . esc_html__('Coordonnées de facturation', $d) . ' <span class="tomo-inv-total" data-tomo-inv-total></span></legend>';

        if ($profiles) {
            $html .= '<label class="tomo-inv-field tomo-inv-wide"><span>' . esc_html__('Mes coordonnées enregistrées', $d) . '</span><select data-tomo-inv-profile><option value="">—</option>';

            foreach ($profiles as $index => $profile) {
                $html .= '<option value="' . (int) $index . '">' . esc_html($profile['name']) . '</option>';
            }

            $html .= '</select></label>';
        }

        $html .= '<label class="tomo-inv-field"><span>' . esc_html__('Facture au nom', $d) . '</span><select name="g[' . $g . '][type]" data-tomo-inv-type>'
            . '<option value="person"' . selected($b['type'], 'person', false) . '>' . esc_html__("d'une personne", $d) . '</option>'
            . '<option value="company"' . selected($b['type'], 'company', false) . '>' . esc_html__("d'une entreprise ou d'un organisme", $d) . '</option></select></label>'
            . $field('name', __('Nom ou raison sociale', $d), 'required maxlength="190"')
            . $field('contact', __("À l'attention de (facultatif)", $d), 'maxlength="190"')
            . '<label class="tomo-inv-field tomo-inv-wide"><span>' . esc_html__('Adresse', $d) . '</span><textarea name="g[' . $g . '][address]" rows="2">' . esc_textarea($b['address']) . '</textarea></label>'
            . $field('postcode', __('Code postal', $d), 'maxlength="12"')
            . $field('city', __('Ville', $d), 'maxlength="100"')
            . $field('country', __('Pays (code)', $d), 'maxlength="2"')
            . '<label class="tomo-inv-field"><span>' . esc_html__('E-mail (reçoit la facture)', $d) . '</span><input type="email" name="g[' . $g . '][email]" value="' . esc_attr($b['email']) . '" maxlength="190"></label>'
            . $field('siren', __('SIREN', $d), 'inputmode="numeric" maxlength="17" data-tomo-inv-company')
            . $field('vat', __('N° de TVA intracommunautaire', $d), 'maxlength="20" data-tomo-inv-company');

        if ($context === 'account') {
            $html .= '<label class="tomo-inv-check"><input type="checkbox" name="g[' . $g . '][remember]" value="1"> ' . esc_html__('Mémoriser ces coordonnées pour mes prochaines factures', $d) . '</label>';
        }

        return $html . '</fieldset>';
    }

    /**
     * Émission (Ajax), pour l'équipe et pour le client.
     */
    public static function ajax()
    {
        $d = 'smbb-tomo';
        $order_id = isset($_POST['order']) ? absint($_POST['order']) : 0;

        if (!check_ajax_referer(self::AJAX . '_' . $order_id, 'nonce', false)) {
            wp_send_json(array('success' => false, 'message' => __('La page a expiré : rechargez-la.', $d)));
        }

        $order = wc_get_order($order_id);

        if (!$order || !self::canIssue($order)) {
            wp_send_json(array('success' => false, 'message' => __('Action non autorisée.', $d)));
        }

        $choices = isset($_POST['u']) && is_array($_POST['u']) ? wp_unslash($_POST['u']) : array();
        $raw = isset($_POST['g']) && is_array($_POST['g']) ? wp_unslash($_POST['g']) : array();
        $groups = array();

        foreach ($choices as $key => $g) {
            $g = (int) $g;

            if ($g >= 1 && $g <= self::MAX_GROUPS && preg_match('/^\d+:\d+$/', (string) $key)) {
                $groups[$g]['units'][] = (string) $key;
            }
        }

        ksort($groups);
        $remember = array();

        foreach ($groups as $g => $group) {
            $groups[$g]['billing'] = isset($raw[$g]) && is_array($raw[$g]) ? $raw[$g] : array();

            if (!empty($raw[$g]['remember'])) {
                $remember[] = Invoices::cleanBilling($groups[$g]['billing']);
            }
        }

        $result = Invoices::issue($order, array_values($groups), Roles::canManage() && !self::owns($order) ? 'team' : 'customer');

        if ($result['success'] && $remember && is_user_logged_in()) {
            foreach ($remember as $billing) {
                self::remember(get_current_user_id(), $billing);
            }
        }

        $result['redirect'] = (isset($_POST['context']) && $_POST['context'] === 'account') ? self::accountUrl($order) : self::adminUrl($order_id);

        if ($result['success'] && !(isset($_POST['context']) && $_POST['context'] === 'account')) {
            View::flash(array('success' => true, 'message' => $result['message']));
        } elseif ($result['success']) {
            wc_add_notice($result['message']);
        }

        wp_send_json($result);
    }

    // ------------------------------------------------------------------
    // Profils de facturation (Mon compte)
    // ------------------------------------------------------------------

    /**
     * @return array<int,array<string,string>>
     */
    public static function profiles($user_id)
    {
        $list = get_user_meta((int) $user_id, self::PROFILES, true);

        return is_array($list) ? array_values($list) : array();
    }

    private static function remember($user_id, array $billing)
    {
        $list = self::profiles($user_id);

        foreach ($list as $index => $profile) {
            if (strtolower($profile['name']) === strtolower($billing['name'])) {
                unset($list[$index]);
            }
        }

        array_unshift($list, $billing);
        update_user_meta((int) $user_id, self::PROFILES, array_slice(array_values($list), 0, 8));
    }

    // ------------------------------------------------------------------
    // Liste des documents
    // ------------------------------------------------------------------

    /**
     * Tableau des factures et avoirs d'une commande.
     *
     * @param array<int,array<string,mixed>> $docs
     */
    public static function table(array $docs, $context)
    {
        $d = 'smbb-tomo';

        if (!$docs) {
            return '';
        }

        $html = '<table class="tomo-inv-docs widefat striped"><thead><tr><th>' . esc_html__('Document', $d) . '</th><th>' . esc_html__('Date', $d) . '</th><th>' . esc_html__('Au nom de', $d) . '</th><th class="num">' . esc_html__('Montant TTC', $d) . '</th><th></th></tr></thead><tbody>';

        foreach ($docs as $doc) {
            $credit = $doc['kind'] === 'credit';
            $actions = '<a class="button button-small" target="_blank" href="' . esc_url($context === 'admin' ? View::actionUrl(self::PDF, array('id' => (int) $doc['id'])) : Invoices::url($doc)) . '">' . esc_html__('PDF', $d) . '</a>';

            if ($context === 'admin') {
                $actions .= ' <a class="button button-small" href="' . esc_url(View::actionUrl(self::RESEND, array('id' => (int) $doc['id']))) . '" title="' . esc_attr(sprintf(__('Renvoyer à %s', $d), $doc['email'])) . '">' . esc_html__('Renvoyer', $d) . '</a>';

                if (!$credit && Invoices::remaining($doc) > 0) {
                    $actions .= ' <a class="button button-small" href="' . esc_url(View::actionUrl(self::CORRECT, array('id' => (int) $doc['id']))) . '" onclick="return confirm(this.dataset.confirm)" data-confirm="' . esc_attr__("Corriger cette facture ? Un avoir l'annule (définitif), puis ses lignes reviennent à facturer avec ses coordonnées, à modifier.", $d) . '">' . esc_html__('Corriger', $d) . '</a>';
                }
            }

            $html .= '<tr><td><strong>' . esc_html(Invoices::kindLabel($doc['kind']) . ' ' . $doc['number']) . '</strong>'
                . ($doc['note'] !== '' && $doc['note'] !== null ? '<br><span class="tomo-inv-muted">' . esc_html($doc['note']) . '</span>' : '') . '</td>'
                . '<td>' . esc_html(mysql2date('d/m/Y', $doc['issued_at'])) . '</td>'
                . '<td>' . esc_html($doc['name']) . '<br><span class="tomo-inv-muted">' . esc_html($doc['email']) . '</span></td>'
                . '<td class="num">' . esc_html(($credit ? '− ' : '') . OnsiteSales::money($doc['total_ttc'])) . '</td>'
                . '<td class="tomo-actions">' . $actions . '</td></tr>';
        }

        return $html . '</tbody></table>';
    }

    // ------------------------------------------------------------------
    // Encadré de la commande
    // ------------------------------------------------------------------

    public static function register()
    {
        if (!Roles::canManage()) {
            return;
        }

        $screen = function_exists('wc_get_page_screen_id') ? wc_get_page_screen_id('shop-order') : 'shop_order';

        foreach (array_unique(array($screen, 'shop_order', 'woocommerce_page_wc-orders')) as $id) {
            add_meta_box('tomo-invoices', __('Factures', 'smbb-tomo'), array(__CLASS__, 'box'), $id, 'normal', 'default');
        }
    }

    /**
     * @param \WP_Post|\WC_Order $object
     */
    public static function box($object)
    {
        $d = 'smbb-tomo';
        $order = $object instanceof \WC_Order ? $object : wc_get_order($object->ID);

        if (!$order || !\Smbb\Tomo\Support\Schema::ready()) {
            return;
        }

        wp_enqueue_style('smbb-tomo-admin', SMBB_TOMO_URL . 'assets/admin.css', array(), SMBB_TOMO_VERSION);
        wp_enqueue_style('smbb-tomo-factures', SMBB_TOMO_URL . 'assets/factures.css', array(), SMBB_TOMO_VERSION);
        $docs = Invoices::forOrder($order->get_id());
        $open = Invoices::billable($order) ? count(Invoices::openUnits($order)) : 0;

        echo '<div class="tomo-admin">';
        echo self::table($docs, 'admin'); // phpcs:ignore -- HTML échappé.

        if (!Invoices::billable($order)) {
            echo '<p class="tomo-muted">' . esc_html__('Commande pas encore payée : pas de facture.', $d) . '</p>';
        } elseif ($open) {
            echo '<p>' . esc_html(sprintf(_n('%d élément reste à facturer.', '%d éléments restent à facturer.', $open, $d), $open))
                . ' <a class="button button-primary" href="' . esc_url(self::adminUrl($order->get_id())) . '">' . esc_html__('Établir des factures', $d) . '</a></p>';
        } elseif ($docs) {
            echo '<p class="tomo-muted">' . esc_html__('Tout est facturé.', $d) . '</p>';
        }

        echo '</div>';
    }

    // ------------------------------------------------------------------
    // Actions de l'admin
    // ------------------------------------------------------------------

    private static function guard($action)
    {
        $id = isset($_GET['id']) ? absint($_GET['id']) : 0;
        check_admin_referer($action . '_' . $id);

        if (!Roles::canManage()) {
            wp_die(esc_html__('Action non autorisée.', 'smbb-tomo'), '', array('response' => 403));
        }

        return $id;
    }

    private static function back(array $result, $url = '')
    {
        View::flash($result);
        wp_safe_redirect($url !== '' ? $url : (wp_get_referer() ?: View::url('tomo_factures')));
        exit;
    }

    public static function pdf()
    {
        $invoice = Invoices::find(self::guard(self::PDF));

        if (!$invoice) {
            wp_die(esc_html__('Document introuvable.', 'smbb-tomo'));
        }

        Invoices::output($invoice);
    }

    public static function resend()
    {
        $invoice = Invoices::find(self::guard(self::RESEND));
        $to = isset($_GET['to']) ? sanitize_email(wp_unslash($_GET['to'])) : '';

        if (!$invoice) {
            self::back(array('success' => false, 'message' => __('Document introuvable.', 'smbb-tomo')));
        }

        $ok = Invoices::send($invoice, $to);
        self::back(array(
            'success' => $ok,
            'message' => $ok ? sprintf(__('%1$s renvoyé à %2$s.', 'smbb-tomo'), $invoice['number'], $to !== '' ? $to : $invoice['email'])
                : __("L'e-mail n'est pas parti : vérifiez l'adresse, le message « Facture émise » (Réglages › Messages) et le journal des envois.", 'smbb-tomo'),
        ));
    }

    /**
     * Corriger : avoir sur toute la facture, puis l'écran de répartition avec ses coordonnées à modifier.
     */
    public static function correct()
    {
        $invoice = Invoices::find(self::guard(self::CORRECT));

        if (!$invoice) {
            self::back(array('success' => false, 'message' => __('Facture introuvable.', 'smbb-tomo')));
        }

        $result = Invoices::credit($invoice, null, sprintf(__('Correction de la facture %s.', 'smbb-tomo'), $invoice['number']), 'team');
        self::back($result, $result['success'] ? self::adminUrl($invoice['order_id'], array('prefill' => (int) $invoice['id'])) : '');
    }

    public static function preview()
    {
        self::guard(self::PREVIEW);

        if (!class_exists('\\TCPDF')) {
            wp_die(esc_html__('PDF indisponible : la bibliothèque TCPDF (fournie par SMBB WP CodeTool) est absente.', 'smbb-tomo'));
        }

        nocache_headers();
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="apercu-facture.pdf"');
        echo Invoices::render(Invoices::sample(), true); // phpcs:ignore -- PDF.
        exit;
    }

    /**
     * Documents d'un mois (AAAA-MM).
     *
     * @return array<int,array<string,mixed>>
     */
    public static function month($month, $kind = '', $source = '', $search = '')
    {
        global $wpdb;

        $month = preg_match('/^\d{4}-\d{2}$/', (string) $month) ? $month : current_time('Y-m');
        $where = array($wpdb->prepare('issued_at >= %s AND issued_at < %s', $month . '-01 00:00:00', date('Y-m-01 00:00:00', strtotime($month . '-01 +1 month'))));

        if (in_array($kind, array('invoice', 'credit'), true)) {
            $where[] = $wpdb->prepare('kind = %s', $kind);
        }

        if (in_array($source, array('online', 'onsite'), true)) {
            $where[] = $wpdb->prepare('source = %s', $source);
        }

        if ($search !== '') {
            // Recherche : tous les mois, mais le type et la source choisis restent appliqués.
            $like = '%' . $wpdb->esc_like($search) . '%';
            $where[0] = $wpdb->prepare('(number LIKE %s OR name LIKE %s OR email LIKE %s)', $like, $like, $like);
        }

        $rows = $wpdb->get_results('SELECT * FROM ' . Invoices::table() . ' WHERE ' . implode(' AND ', $where) . ' ORDER BY id ASC', ARRAY_A); // phpcs:ignore

        return array_map(array(Invoices::class, 'decode'), (array) $rows);
    }

    /**
     * Journal des ventes pour la comptabilité (avoirs en négatif).
     */
    public static function csv()
    {
        self::guard(self::CSV);
        $month = isset($_GET['mois']) ? sanitize_text_field(wp_unslash($_GET['mois'])) : current_time('Y-m');
        $docs = self::month($month);
        $rates = array();

        foreach ($docs as $doc) {
            foreach ($doc['taxes'] as $tax) {
                $rates[number_format((float) $tax['rate'], 2, '.', '')] = true;
            }
        }

        ksort($rates);
        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="factures-' . sanitize_file_name($month) . '.csv"');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        $head = array('Numéro', 'Date', 'Type', 'Origine', 'Commande', 'Client', 'E-mail', 'SIREN', 'Total HT');

        foreach (array_keys($rates) as $rate) {
            $head[] = 'TVA ' . str_replace('.', ',', rtrim(rtrim($rate, '0'), '.')) . ' %';
        }

        fputcsv($out, array_merge($head, array('Total TTC', 'Paiement', 'Vente')), ';');

        foreach ($docs as $doc) {
            $sign = $doc['kind'] === 'credit' ? -1 : 1;
            $origin = !empty($doc['origin_id']) ? Invoices::find($doc['origin_id']) : null;
            $order = wc_get_order($doc['order_id']);
            $row = array(
                $doc['number'],
                mysql2date('d/m/Y', $doc['issued_at']),
                Invoices::kindLabel($doc['kind']),
                $origin ? $origin['number'] : '',
                $order ? $order->get_order_number() : $doc['order_id'],
                $doc['name'],
                $doc['email'],
                isset($doc['billing']['siren']) ? $doc['billing']['siren'] : '',
                self::num($sign * $doc['total_ht']),
            );

            foreach (array_keys($rates) as $rate) {
                $amount = 0.0;

                foreach ($doc['taxes'] as $tax) {
                    if (number_format((float) $tax['rate'], 2, '.', '') === $rate) {
                        $amount += (float) $tax['tax'];
                    }
                }

                $row[] = self::num($sign * $amount);
            }

            fputcsv($out, array_merge($row, array(self::num($sign * $doc['total_ttc']), $doc['payment'], $doc['source'] === 'onsite' ? 'Sur place' : 'En ligne')), ';');
        }

        fclose($out);
        exit;
    }

    private static function num($value)
    {
        return number_format((float) $value, 2, ',', '');
    }

    public static function zip()
    {
        self::guard(self::ZIP);
        $month = isset($_GET['mois']) ? sanitize_text_field(wp_unslash($_GET['mois'])) : current_time('Y-m');

        if (!class_exists('\\ZipArchive')) {
            wp_die(esc_html__("L'extension PHP Zip est absente du serveur.", 'smbb-tomo'));
        }

        $docs = self::month($month);
        $file = wp_tempnam('tomo-factures');
        $zip = new \ZipArchive();
        $zip->open($file, \ZipArchive::OVERWRITE);

        foreach ($docs as $doc) {
            $path = Invoices::path($doc);

            if ($path !== '' && is_readable($path)) {
                $zip->addFile($path, sanitize_file_name($doc['number']) . '.pdf');
            }
        }

        $zip->close();
        nocache_headers();
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="factures-' . sanitize_file_name($month) . '.zip"');
        header('Content-Length: ' . filesize($file));
        readfile($file); // phpcs:ignore
        @unlink($file); // phpcs:ignore
        exit;
    }

    // ------------------------------------------------------------------
    // Mon compte › Factures
    // ------------------------------------------------------------------

    public static function endpoint()
    {
        add_rewrite_endpoint(self::ENDPOINT, EP_ROOT | EP_PAGES);
    }

    public static function queryVars($vars)
    {
        $vars[self::ENDPOINT] = self::ENDPOINT;

        return $vars;
    }

    public static function menu($items)
    {
        $result = array();

        foreach ((array) $items as $key => $label) {
            $result[$key] = $label;

            if ($key === 'orders') {
                $result[self::ENDPOINT] = __('Factures', 'smbb-tomo');
            }
        }

        if (!isset($result[self::ENDPOINT])) {
            $result[self::ENDPOINT] = __('Factures', 'smbb-tomo');
        }

        return $result;
    }

    public static function title()
    {
        return __('Factures', 'smbb-tomo');
    }

    /**
     * Bouton « Factures » sur chaque commande payée (Mon compte › Commandes).
     *
     * @param array<string,array<string,string>> $actions
     * @param \WC_Order $order
     */
    public static function orderAction($actions, $order)
    {
        if ($order && Invoices::billable($order)) {
            $actions['tomo_factures'] = array('url' => self::accountUrl($order), 'name' => __('Factures', 'smbb-tomo'));
        }

        return $actions;
    }

    /**
     * Lien sous le détail d'une commande (Mon compte, page de remerciement).
     *
     * @param \WC_Order $order
     */
    public static function orderLink($order)
    {
        if ($order && Invoices::billable($order) && self::owns($order)) {
            echo '<p class="tomo-inv-order-link"><a class="button" href="' . esc_url(self::accountUrl($order)) . '">' . esc_html__('Besoin d\'une facture ?', 'smbb-tomo') . '</a></p>';
        }
    }

    public static function account()
    {
        $d = 'smbb-tomo';
        $user = get_current_user_id();
        $order_id = isset($_GET['commande']) ? absint($_GET['commande']) : 0;

        if (!\Smbb\Tomo\Support\Schema::ready()) {
            return;
        }

        wp_enqueue_style('smbb-tomo-factures', SMBB_TOMO_URL . 'assets/factures.css', array(), SMBB_TOMO_VERSION);

        if ($order_id) {
            $order = wc_get_order($order_id);

            if (!$order || !self::owns($order)) {
                echo '<p>' . esc_html__('Commande introuvable.', $d) . '</p>';

                return;
            }

            echo '<p><a href="' . esc_url(self::accountUrl()) . '">← ' . esc_html__('Toutes mes factures', $d) . '</a></p>';
            echo '<h3>' . esc_html(sprintf(__('Commande n° %s', $d), $order->get_order_number())) . '</h3>';
            $docs = Invoices::forOrder($order_id);
            echo self::table($docs, 'account'); // phpcs:ignore -- HTML échappé.

            if (!Invoices::billable($order)) {
                echo '<p>' . esc_html__("Cette commande n'est pas encore payée : la facture sera disponible après le paiement.", $d) . '</p>';
            } elseif ((int) Settings::get('invoice_customer') === 1) {
                echo self::screen($order, 'account'); // phpcs:ignore -- HTML échappé.
            } elseif (Invoices::openUnits($order)) {
                echo '<p>' . esc_html__('Pour obtenir une facture, contactez-nous.', $d) . '</p>';
            }

            if ($docs) {
                echo self::requestForm($docs); // phpcs:ignore -- HTML échappé.
            }

            return;
        }

        global $wpdb;
        $ids = $wpdb->get_col($wpdb->prepare('SELECT id FROM ' . Invoices::table() . ' WHERE customer_id = %d ORDER BY id DESC LIMIT 200', $user));
        $docs = array_filter(array_map(array(Invoices::class, 'find'), (array) $ids));
        $orders = wc_get_orders(array('customer_id' => $user, 'limit' => 20, 'status' => array('processing', 'completed')));
        $todo = array();

        foreach ($orders as $order) {
            if (Invoices::openUnits($order)) {
                $todo[] = $order;
            }
        }

        if ($todo && (int) Settings::get('invoice_customer') === 1) {
            echo '<h3>' . esc_html__('À facturer', $d) . '</h3><ul class="tomo-inv-todo">';

            foreach ($todo as $order) {
                echo '<li>' . esc_html(sprintf(__('Commande n° %1$s du %2$s (%3$s)', $d), $order->get_order_number(), $order->get_date_created() ? $order->get_date_created()->date_i18n('d/m/Y') : '', OnsiteSales::total($order)))
                    . ' <a class="button" href="' . esc_url(self::accountUrl($order)) . '">' . esc_html__('Établir ma facture', $d) . '</a></li>';
            }

            echo '</ul>';
        }

        echo '<h3>' . esc_html__('Mes factures et avoirs', $d) . '</h3>';
        echo $docs ? self::table(array_values($docs), 'account') : '<p>' . esc_html__('Aucune facture pour le moment.', $d) . '</p>'; // phpcs:ignore -- HTML échappé.
    }

    /**
     * « Demander une correction » : après émission, le client ne modifie plus rien lui-même.
     *
     * @param array<int,array<string,mixed>> $docs
     */
    private static function requestForm(array $docs)
    {
        $d = 'smbb-tomo';
        $options = '';

        foreach ($docs as $doc) {
            if ($doc['kind'] === 'invoice' && Invoices::remaining($doc) > 0) {
                $options .= '<option value="' . (int) $doc['id'] . '">' . esc_html($doc['number'] . ' — ' . $doc['name']) . '</option>';
            }
        }

        if ($options === '') {
            return '';
        }

        return '<details class="tomo-inv-request"><summary>' . esc_html__('Une erreur sur une facture ? Demander une correction', $d) . '</summary>'
            . '<form method="post"><input type="hidden" name="tomo_invoice_request" value="1">' . wp_nonce_field('tomo_invoice_request', '_tomo_nonce', true, false)
            . '<p><label>' . esc_html__('Facture', $d) . '<br><select name="invoice_id">' . $options . '</select></label></p>'
            . '<p><label>' . esc_html__('Ce qu\'il faut corriger', $d) . '<br><textarea name="message" rows="3" required maxlength="2000"></textarea></label></p>'
            . '<p><button type="submit" class="button">' . esc_html__('Envoyer la demande', $d) . '</button></p></form></details>';
    }

    public static function request()
    {
        if (empty($_POST['tomo_invoice_request']) || !is_user_logged_in()) {
            return;
        }

        $d = 'smbb-tomo';

        if (!isset($_POST['_tomo_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_tomo_nonce'])), 'tomo_invoice_request')) {
            wc_add_notice(__('La page a expiré : réessayez.', $d), 'error');

            return;
        }

        $invoice = Invoices::find(isset($_POST['invoice_id']) ? absint($_POST['invoice_id']) : 0);
        $order = $invoice ? wc_get_order($invoice['order_id']) : null;
        $message = isset($_POST['message']) ? sanitize_textarea_field(wp_unslash($_POST['message'])) : '';

        if (!$invoice || !self::owns($order) || $message === '') {
            wc_add_notice(__('Choisissez la facture et décrivez la correction.', $d), 'error');

            return;
        }

        $user = wp_get_current_user();
        $to = trim((string) Settings::get('contact_email')) ?: (string) get_option('admin_email');
        $results = Notifier::toCustomer('invoice_request', array('first_name' => Settings::businessName(), 'last_name' => '', 'email' => $to, 'phone' => ''), null, array(
            'numero_facture' => $invoice['number'],
            'commande' => (string) $order->get_order_number(),
            'client' => trim($user->display_name . ' (' . $user->user_email . ')'),
            'demande' => $message,
            'lien_commande_admin' => self::adminUrl($order->get_id()),
        ), null);
        $order->add_order_note(sprintf(__('Tomo : le client demande une correction de la facture %1$s : %2$s', $d), $invoice['number'], $message));
        wc_add_notice($results ? __('Votre demande est envoyée : nous corrigeons la facture et vous recevrez la nouvelle par e-mail.', $d) : __('Votre demande est enregistrée sur la commande ; nous revenons vers vous.', $d));
        wp_safe_redirect(self::accountUrl($order));
        exit;
    }
}
