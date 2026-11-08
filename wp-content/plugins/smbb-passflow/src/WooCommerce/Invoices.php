<?php

namespace Smbb\Passflow\WooCommerce;

use Smbb\Passflow\Repository\Lookup;
use Smbb\Passflow\Service\Notifier;
use Smbb\Passflow\Support\Settings;

defined('ABSPATH') || exit;

/**
 * Factures et avoirs des commandes WooCommerce.
 *
 * Règles :
 * - une facture émise ne se modifie plus : une erreur se corrige par un avoir, puis une nouvelle facture ;
 * - numérotation continue : le numéro est pris à l'émission, dans la même transaction que l'enregistrement,
 *   par un compteur incrémenté en une seule requête (table passflow_counter) ;
 * - une même vente n'est facturée qu'une fois : chaque « unité » (une place, un participant, une pièce)
 *   est soit à facturer, soit facturée, soit remboursée.
 *
 * Unités d'une commande : clé « id de ligne:rang ». Une ligne d'atelier a une unité par participant ;
 * un produit en quantité, une unité par pièce (découpable entre factures).
 *
 * Tout est figé dans passflow_invoice à l'émission (coordonnées, lignes, totaux par taux, PDF) : le PDF
 * n'est jamais régénéré, on renvoie le fichier d'origine.
 */
final class Invoices
{
    const DOWNLOAD = 'passflow_invoice_download';
    const DIR = 'passflow-invoices';

    public static function hooks()
    {
        add_action('woocommerce_order_refunded', array(__CLASS__, 'onRefund'), 20, 2);
        add_action('woocommerce_order_status_changed', array(__CLASS__, 'onStatus'), 30, 3);
        add_action('init', array(__CLASS__, 'download'));
        add_action('update_option_' . Settings::OPTION, array(__CLASS__, 'applyNext'), 20, 0);
        add_action('add_option_' . Settings::OPTION, array(__CLASS__, 'applyNext'), 20, 0);
        add_filter('woocommerce_billing_fields', array(__CLASS__, 'billingFields'), 20);
        add_filter('woocommerce_admin_billing_fields', array(__CLASS__, 'adminBillingFields'), 20);
        add_action('smbb_wpcodetool_register_cron_tasks', array(__CLASS__, 'registerCron'));
        add_action('update_option_' . Settings::OPTION, array(__CLASS__, 'syncDelayStart'), 20, 0);
        add_action('add_option_' . Settings::OPTION, array(__CLASS__, 'syncDelayStart'), 20, 0);
    }

    /**
     * Paiement : SIREN et n° de TVA facultatifs (entreprises), enregistrés avec la commande.
     * Une commande avec un SIREN reçoit sa facture automatiquement (réglage par défaut).
     *
     * @param array<string,array<string,mixed>> $fields
     */
    public static function billingFields($fields)
    {
        $d = 'smbb-passflow';
        $position = isset($fields['billing_company']['priority']) ? (int) $fields['billing_company']['priority'] : 30;
        $fields['billing_siren'] = array(
            'label' => __('SIREN (entreprise)', $d),
            'required' => false,
            'class' => array('form-row-first'),
            'priority' => $position + 1,
            'custom_attributes' => array('inputmode' => 'numeric', 'maxlength' => 17),
        );
        $fields['billing_vat'] = array(
            'label' => __('N° de TVA intracommunautaire', $d),
            'required' => false,
            'class' => array('form-row-last'),
            'priority' => $position + 2,
        );

        return $fields;
    }

    public static function adminBillingFields($fields)
    {
        $fields['siren'] = array('label' => __('SIREN', 'smbb-passflow'), 'show' => false);
        $fields['vat'] = array('label' => __('N° de TVA', 'smbb-passflow'), 'show' => false);

        return $fields;
    }

    public static function table()
    {
        return Lookup::table('passflow_invoice');
    }

    // ------------------------------------------------------------------
    // Numérotation
    // ------------------------------------------------------------------

    /**
     * Modèle de numéro : {SIGLE} {AAAA} {AA} {MM} {N:5}.
     */
    public static function format($pattern, $n, $time = null)
    {
        $time = $time ?: current_time('timestamp');
        $code = Settings::companyCode();
        // Sans sigle, « {SIGLE}- » disparaît avec son séparateur.
        $pattern = $code === '' ? preg_replace('/\{SIGLE\}[-_\/ ]?/', '', (string) $pattern) : (string) $pattern;
        $out = strtr((string) $pattern, array(
            '{SIGLE}' => $code,
            '{AAAA}' => date('Y', $time),
            '{AA}' => date('y', $time),
            '{MM}' => date('m', $time),
        ));
        $out = preg_replace_callback('/\{N(?::(\d{1,2}))?\}/', function ($m) use ($n) {
            return str_pad((string) (int) $n, isset($m[1]) ? (int) $m[1] : 1, '0', STR_PAD_LEFT);
        }, $out);

        return strpos((string) $pattern, '{N') === false ? $out . '-' . $n : $out;
    }

    public static function pattern($kind)
    {
        $pattern = trim((string) Settings::get($kind === 'credit' ? 'credit_pattern' : 'invoice_pattern'));

        return $pattern !== '' ? $pattern : ($kind === 'credit' ? 'AV-{AAAA}-{N:5}' : 'F-{AAAA}-{N:5}');
    }

    /**
     * Nom du compteur : un par type, et par année si le compteur repart à 1 chaque année.
     */
    public static function counterName($kind, $time = null)
    {
        $time = $time ?: current_time('timestamp');

        return ($kind === 'credit' ? 'avoir' : 'facture') . (Settings::get('invoice_reset') === 'never' ? '' : '-' . date('Y', $time));
    }

    private static function counterValue($name)
    {
        global $wpdb;

        return (int) $wpdb->get_var($wpdb->prepare('SELECT value FROM ' . Lookup::table('passflow_counter') . ' WHERE name = %s', $name));
    }

    /**
     * Prochain numéro, sans le consommer (aperçu dans les réglages).
     */
    public static function nextPreview($kind)
    {
        return self::format(self::pattern($kind), self::counterValue(self::counterName($kind)) + 1);
    }

    /**
     * Factures en mode démo (Réglages › Factures, ou données de démo présentes) : série DEMO- à part,
     * avec son propre compteur. La numérotation réelle ne bouge pas.
     */
    public static function demoMode()
    {
        return (int) Settings::get('invoice_demo') === 1 || \Smbb\Passflow\Service\Demo::exists();
    }

    /**
     * Prend le numéro suivant : à appeler dans la transaction de l'enregistrement.
     */
    private static function takeNumber($kind, $demo = false)
    {
        global $wpdb;

        $table = Lookup::table('passflow_counter');
        $name = ($demo ? 'demo-' : '') . self::counterName($kind);
        $wpdb->query($wpdb->prepare('INSERT IGNORE INTO ' . $table . ' (name, value) VALUES (%s, 0)', $name));
        $wpdb->query($wpdb->prepare('UPDATE ' . $table . ' SET value = LAST_INSERT_ID(value + 1) WHERE name = %s', $name));
        $n = (int) $wpdb->get_var('SELECT LAST_INSERT_ID()');

        return $n > 0 ? self::format(($demo ? 'DEMO-' : '') . self::pattern($kind), $n) : '';
    }

    /**
     * « Prochain numéro » des réglages : le compteur ne peut qu'avancer (reprise d'un ancien logiciel).
     */
    public static function applyNext()
    {
        if (!\Smbb\Passflow\Support\Schema::ready()) {
            return;
        }

        global $wpdb;

        $table = Lookup::table('passflow_counter');

        foreach (array('invoice' => 'invoice_next', 'credit' => 'credit_next') as $kind => $setting) {
            $next = (int) Settings::get($setting);

            if ($next < 2) {
                continue;
            }

            $name = self::counterName($kind);
            $wpdb->query($wpdb->prepare('INSERT IGNORE INTO ' . $table . ' (name, value) VALUES (%s, 0)', $name));
            $wpdb->query($wpdb->prepare('UPDATE ' . $table . ' SET value = %d WHERE name = %s AND value < %d', $next - 1, $name, $next - 1));
        }
    }

    // ------------------------------------------------------------------
    // Lecture
    // ------------------------------------------------------------------

    /**
     * @return array<string,mixed>|null
     */
    public static function find($id)
    {
        global $wpdb;

        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table() . ' WHERE id = %d', (int) $id), ARRAY_A);

        return $row ? self::decode($row) : null;
    }

    /**
     * Factures et avoirs d'une commande, dans l'ordre d'émission.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function forOrder($order_id)
    {
        global $wpdb;

        $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . self::table() . ' WHERE order_id = %d ORDER BY id ASC', (int) $order_id), ARRAY_A);

        return array_map(array(__CLASS__, 'decode'), (array) $rows);
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    public static function decode(array $row)
    {
        foreach (array('billing', 'items', 'taxes') as $key) {
            $value = json_decode((string) $row[$key], true);
            $row[$key] = is_array($value) ? $value : array();
        }

        return $row;
    }

    public static function kindLabel($kind)
    {
        return $kind === 'credit' ? __('Avoir', 'smbb-passflow') : __('Facture', 'smbb-passflow');
    }

    /**
     * Montant TTC encore « actif » d'une facture (moins ses avoirs).
     */
    public static function remaining(array $invoice)
    {
        $left = (float) $invoice['total_ttc'];

        foreach (self::forOrder($invoice['order_id']) as $row) {
            if ($row['kind'] === 'credit' && (int) $row['origin_id'] === (int) $invoice['id']) {
                $left -= (float) $row['total_ttc'];
            }
        }

        return round(max(0, $left), 2);
    }

    // ------------------------------------------------------------------
    // Unités à facturer
    // ------------------------------------------------------------------

    /**
     * Lignes de la commande et leurs unités, avec leur état.
     *
     * @param \WC_Order $order
     * @return array<int,array<string,mixed>> lignes : id, label, qty, unit_ttc, participants (bool), units
     */
    public static function units($order)
    {
        global $wpdb;

        $invoices = self::forOrder($order->get_id());
        $invoiced_qty = array();   // id de ligne => quantité facturée (nette des avoirs)
        $invoiced_people = array(); // id d'inscription => numéro de facture
        $numbers = array();

        foreach ($invoices as $invoice) {
            $sign = $invoice['kind'] === 'credit' ? -1 : 1;

            foreach ($invoice['items'] as $line) {
                $item_id = (int) $line['item_id'];

                if ($item_id <= 0 || (int) $line['qty'] <= 0) {
                    continue; // avoir sur un montant : ne rouvre rien
                }

                $invoiced_qty[$item_id] = (isset($invoiced_qty[$item_id]) ? $invoiced_qty[$item_id] : 0) + $sign * (int) $line['qty'];
                $numbers[$item_id] = $invoice['number'];

                foreach ((array) (isset($line['booking_ids']) ? $line['booking_ids'] : array()) as $booking_id) {
                    if ($sign > 0) {
                        $invoiced_people[(int) $booking_id] = $invoice['number'];
                    } else {
                        unset($invoiced_people[(int) $booking_id]);
                    }
                }
            }
        }

        $people = array();
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT id, order_item_id, activity_id, first_name, last_name, status FROM ' . Lookup::table('passflow_booking') . ' WHERE deleted_flag = 0 AND (pass_id IS NULL OR pass_id = 0) AND order_id = %d ORDER BY id ASC',
            (int) $order->get_id()
        ), ARRAY_A);
        $items = $order->get_items('line_item');

        foreach ((array) $rows as $row) {
            $item_id = (int) $row['order_item_id'];

            // Inscription sans ligne connue (ancienne commande, ajout à la main) : la ligne du produit de son atelier.
            if (!$item_id || !isset($items[$item_id])) {
                $activity = Lookup::atelier($row['activity_id']);
                $item_id = 0;

                foreach ($items as $id => $item) {
                    if ($activity && !empty($activity['product_id']) && (int) $item->get_product_id() === (int) $activity['product_id']) {
                        $item_id = (int) $id;
                        break;
                    }
                }
            }

            if ($item_id) {
                $people[$item_id][] = $row;
            }
        }

        $result = array();

        foreach ($order->get_items(array('line_item', 'fee', 'shipping')) as $item_id => $item) {
            $qty = max(1, (int) $item->get_quantity());
            $total = (float) $item->get_total();
            $tax = (float) $item->get_total_tax();

            // Ligne gratuite (prix normal à 0) : rien à facturer. Une ligne payée entièrement par un bon
            // cadeau reste facturable : prix normal, puis le bon en ligne négative.
            $gross = $item instanceof \WC_Order_Item_Product ? (float) $item->get_subtotal() + (float) $item->get_subtotal_tax() : $total + $tax;

            if (abs($total + $tax) < 0.005 && abs($gross) < 0.005) {
                continue;
            }

            $refunded = $item instanceof \WC_Order_Item_Product ? abs((int) $order->get_qty_refunded_for_item($item_id)) : 0;
            $done = isset($invoiced_qty[$item_id]) ? max(0, (int) $invoiced_qty[$item_id]) : 0;
            $list = isset($people[$item_id]) ? $people[$item_id] : array();

            // Participants : les annulés passent en dernier (ce sont eux, en principe, qui ont été remboursés).
            usort($list, function ($a, $b) {
                return ($a['status'] === 'cancelled') - ($b['status'] === 'cancelled');
            });

            $units = array();
            $invoiced_left = $done;
            $refunded_left = $refunded;

            // 1. Participants déjà facturés, 2. autres unités facturées, 3. remboursées (en partant de la fin), 4. à facturer.
            for ($n = 0; $n < $qty; $n++) {
                $person = isset($list[$n]) ? $list[$n] : null;
                $units[$n] = array(
                    'key' => $item_id . ':' . $n,
                    'item_id' => (int) $item_id,
                    'booking_id' => $person ? (int) $person['id'] : 0,
                    'name' => $person ? trim($person['first_name'] . ' ' . $person['last_name']) : '',
                    'state' => 'open',
                    'number' => '',
                );

                if ($person && isset($invoiced_people[(int) $person['id']]) && $invoiced_left > 0) {
                    $units[$n]['state'] = 'invoiced';
                    $units[$n]['number'] = $invoiced_people[(int) $person['id']];
                    $invoiced_left--;
                }
            }

            for ($n = 0; $n < $qty && $invoiced_left > 0; $n++) {
                if ($units[$n]['state'] === 'open' && (!$units[$n]['booking_id'] || !$invoiced_people)) {
                    $units[$n]['state'] = 'invoiced';
                    $units[$n]['number'] = isset($numbers[$item_id]) ? $numbers[$item_id] : '';
                    $invoiced_left--;
                }
            }

            for ($n = $qty - 1; $n >= 0 && $refunded_left > 0; $n--) {
                if ($units[$n]['state'] === 'open') {
                    $units[$n]['state'] = 'refunded';
                    $refunded_left--;
                }
            }

            $result[] = array(
                'id' => (int) $item_id,
                'label' => $item->get_name(),
                'qty' => $qty,
                'unit_ttc' => round(($total + $tax) / $qty, 2),
                'participants' => (bool) $list,
                'units' => array_values($units),
            );
        }

        return $result;
    }

    /**
     * Unités encore à facturer, par clé.
     *
     * @return array<string,array<string,mixed>>
     */
    public static function openUnits($order)
    {
        $open = array();

        foreach (self::units($order) as $line) {
            foreach ($line['units'] as $unit) {
                if ($unit['state'] === 'open') {
                    $open[$unit['key']] = $unit;
                }
            }
        }

        return $open;
    }

    /**
     * Une commande se facture une fois payée.
     *
     * @param \WC_Order $order
     */
    public static function billable($order)
    {
        return $order && ($order->is_paid() || $order->has_status(array('completed', 'processing', 'refunded')));
    }

    // ------------------------------------------------------------------
    // Coordonnées
    // ------------------------------------------------------------------

    /**
     * Coordonnées de facturation de la commande (point de départ de la première facture).
     *
     * @param \WC_Order $order
     * @return array<string,string>
     */
    public static function orderBilling($order)
    {
        $company = trim((string) $order->get_billing_company());
        $person = trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name());

        return array(
            'type' => $company !== '' ? 'company' : 'person',
            'name' => $company !== '' ? $company : $person,
            'contact' => $company !== '' ? $person : '',
            'address' => trim($order->get_billing_address_1() . "\n" . $order->get_billing_address_2()),
            'postcode' => (string) $order->get_billing_postcode(),
            'city' => (string) $order->get_billing_city(),
            'country' => (string) $order->get_billing_country(),
            'email' => (string) $order->get_billing_email(),
            'siren' => (string) $order->get_meta('_billing_siren'),
            'vat' => (string) $order->get_meta('_billing_vat'),
        );
    }

    /**
     * @param array<string,mixed> $raw
     * @return array<string,string>
     */
    public static function cleanBilling(array $raw)
    {
        $out = array();

        foreach (array('type', 'name', 'contact', 'address', 'postcode', 'city', 'country', 'email', 'siren', 'vat') as $key) {
            $value = isset($raw[$key]) ? (string) $raw[$key] : '';
            $out[$key] = $key === 'address' ? sanitize_textarea_field($value) : ($key === 'email' ? sanitize_email($value) : sanitize_text_field($value));
        }

        $out['type'] = $out['type'] === 'company' ? 'company' : 'person';
        $out['country'] = strtoupper(substr($out['country'], 0, 2));
        $out['siren'] = preg_replace('/[^0-9 ]/', '', $out['siren']);
        $out['vat'] = strtoupper(preg_replace('/\s+/', '', $out['vat']));

        if ($out['type'] === 'person') {
            $out['siren'] = '';
            $out['vat'] = '';
        }

        return $out;
    }

    // ------------------------------------------------------------------
    // Émission
    // ------------------------------------------------------------------

    /**
     * Émet d'un coup les factures d'une commande.
     *
     * @param \WC_Order $order
     * @param array<int,array{billing:array<string,mixed>,units:string[]}> $groups
     * @param string $author customer | team | auto
     * @return array{success:bool,message:string,ids?:int[]}
     */
    public static function issue($order, array $groups, $author = 'team', $send = true)
    {
        $d = 'smbb-passflow';

        if (!self::billable($order)) {
            return array('success' => false, 'message' => __("Cette commande n'est pas payée : pas de facture (la page « Régler votre commande » sert de récapitulatif).", $d));
        }

        if (!self::lock($order->get_id())) {
            return array('success' => false, 'message' => __('Une autre émission est en cours pour cette commande : réessayez dans un instant.', $d));
        }

        $open = self::openUnits($order);
        $prepared = array();
        $used = array();
        $already = self::invoicedCounts($order);

        foreach ($groups as $index => $group) {
            $keys = array_values(array_unique(array_map('strval', isset($group['units']) ? (array) $group['units'] : array())));

            if (!$keys) {
                continue;
            }

            foreach ($keys as $key) {
                if (!isset($open[$key]) || isset($used[$key])) {
                    self::unlock($order->get_id());

                    return array('success' => false, 'message' => __('Une partie de la commande vient déjà d\'être facturée ou remboursée : rechargez la page.', $d));
                }

                $used[$key] = true;
            }

            $billing = self::cleanBilling(isset($group['billing']) ? (array) $group['billing'] : array());

            if ($billing['name'] === '') {
                self::unlock($order->get_id());

                return array('success' => false, 'message' => sprintf(__('Facture %d : indiquez le nom ou la raison sociale.', $d), $index + 1));
            }

            if ($billing['email'] === '' || !is_email($billing['email'])) {
                $billing['email'] = (string) $order->get_billing_email();
            }

            $units = array();

            foreach ($keys as $key) {
                $units[] = $open[$key];
            }

            $prepared[] = array('billing' => $billing, 'items' => self::linesFor($order, $units, $already));
        }

        if (!$prepared) {
            self::unlock($order->get_id());

            return array('success' => false, 'message' => __('Choisissez au moins une ligne à facturer.', $d));
        }

        $ids = self::store($order, 'invoice', $prepared, $author);
        self::unlock($order->get_id());

        if (!$ids) {
            return array('success' => false, 'message' => __("Les factures n'ont pas pu être enregistrées : rien n'a été émis, aucun numéro n'est consommé.", $d));
        }

        $numbers = self::finish($ids, $send);

        $order->add_order_note(sprintf(_n('Passflow : facture %s émise.', 'Passflow : factures %s émises.', count($numbers), $d), implode(', ', $numbers)));

        return array(
            'success' => true,
            'ids' => $ids,
            'message' => sprintf(_n('Facture %s émise.', 'Factures %s émises.', count($numbers), $d), implode(', ', $numbers))
                . ($send ? ' ' . __('Elle part par e-mail avec le PDF.', $d) : ''),
        );
    }

    /**
     * Lignes d'une facture : les unités regroupées par ligne de commande, montants au prorata.
     *
     * Les montants sont pris en cumulé (unités déjà facturées sur la ligne → $already) : la dernière
     * facture d'une ligne prend le reliquat, et 3 × 1/3 de 100 € font bien 100 € et pas 99,99 €.
     *
     * @param \WC_Order $order
     * @param array<int,array<string,mixed>> $units
     * @param array<int,int> $already id de ligne => unités déjà facturées (nettes des avoirs), mis à jour
     * @return array<int,array<string,mixed>>
     */
    private static function linesFor($order, array $units, array &$already = array())
    {
        $by_item = array();

        foreach ($units as $unit) {
            $by_item[$unit['item_id']][] = $unit;
        }

        $lines = array();

        foreach ($by_item as $item_id => $list) {
            $item = $order->get_item($item_id);

            if (!$item) {
                continue;
            }

            $qty_total = max(1, (int) $item->get_quantity());
            $qty = count($list);
            $from = min($qty_total, isset($already[$item_id]) ? max(0, (int) $already[$item_id]) : 0);
            $to = min($qty_total, $from + $qty);
            $already[$item_id] = $to;
            $ht = self::share((float) $item->get_total(), $from, $to, $qty_total);
            $tax = self::share((float) $item->get_total_tax(), $from, $to, $qty_total);
            $names = array_values(array_filter(wp_list_pluck($list, 'name')));
            // Prix avant remise (bon cadeau, code promo) : la remise apparaît en ligne négative sur la facture.
            $gross_ht = $item instanceof \WC_Order_Item_Product ? self::share((float) $item->get_subtotal(), $from, $to, $qty_total) : $ht;
            $gross_tax = $item instanceof \WC_Order_Item_Product ? self::share((float) $item->get_subtotal_tax(), $from, $to, $qty_total) : $tax;

            $lines[] = array(
                'item_id' => (int) $item_id,
                'label' => $item->get_name(),
                'detail' => $names ? implode(', ', $names) : '',
                'booking_ids' => array_values(array_filter(array_map('intval', wp_list_pluck($list, 'booking_id')))),
                'qty' => $qty,
                'unit_ht' => round($ht / $qty, 4),
                'ht' => $ht,
                'tax' => $tax,
                'ttc' => round($ht + $tax, 2),
                'rate' => self::rate($item, $gross_ht, $gross_tax),
                'gross_ht' => max($gross_ht, $ht),
                'gross_tax' => max($gross_tax, $tax),
                'coupons' => $gross_ht - $ht > 0.004 ? implode(', ', array_map('strtoupper', $order->get_coupon_codes())) : '',
            );
        }

        return $lines;
    }

    /**
     * Part d'un montant pour les unités $from à $to sur $total, calculée en cumulé : les parts
     * successives d'une même ligne s'additionnent toujours au montant exact.
     */
    private static function share($amount, $from, $to, $total)
    {
        $total = max(1, (int) $total);

        return round(round($amount * $to / $total, 2) - round($amount * $from / $total, 2), 2);
    }

    /**
     * Unités déjà facturées par ligne de commande (nettes des avoirs).
     *
     * @param \WC_Order $order
     * @return array<int,int>
     */
    private static function invoicedCounts($order)
    {
        $counts = array();

        foreach (self::units($order) as $line) {
            foreach ($line['units'] as $unit) {
                if ($unit['state'] === 'invoiced') {
                    $counts[(int) $line['id']] = (isset($counts[(int) $line['id']]) ? $counts[(int) $line['id']] : 0) + 1;
                }
            }
        }

        return $counts;
    }

    /**
     * Taux de TVA d'une ligne, en pourcentage.
     *
     * @param \WC_Order_Item $item
     */
    private static function rate($item, $ht, $tax)
    {
        $taxes = $item->get_taxes();
        $ids = array_keys(array_filter((array) (isset($taxes['total']) ? $taxes['total'] : array()), function ($v) {
            return (float) $v !== 0.0;
        }));

        if (count($ids) === 1 && class_exists('\\WC_Tax') && method_exists('\\WC_Tax', 'get_rate_percent_value')) {
            return (float) \WC_Tax::get_rate_percent_value((int) $ids[0]);
        }

        return abs($ht) > 0.004 ? round($tax / $ht * 100, 1) : 0.0;
    }

    /**
     * Enregistre les documents dans une transaction (numéros compris) : tout ou rien.
     *
     * @param \WC_Order $order
     * @param array<int,array<string,mixed>> $docs billing, items, origin_id?, note?
     * @return int[] ids créés
     */
    private static function store($order, $kind, array $docs, $author)
    {
        global $wpdb;

        $source = $order->get_meta(OnsiteSales::META_SLOT) ? 'onsite' : 'online';
        $method = (string) $order->get_meta(OnsiteSales::META_METHOD);
        $methods = OnsiteSales::methods();
        $payment = $method !== '' && isset($methods[$method]) ? $methods[$method] : (string) $order->get_payment_method_title();
        $ids = array();

        $wpdb->query('START TRANSACTION');

        $demo_mode = self::demoMode();

        foreach ($docs as $doc) {
            // Un avoir suit sa facture : avoir démo d'une facture démo, même mode démo coupé depuis.
            $origin = !empty($doc['origin_id']) ? self::find($doc['origin_id']) : null;
            $demo = $origin ? !empty($origin['demo']) : $demo_mode;
            $number = self::takeNumber($kind, $demo);
            $taxes = array();
            $ht = 0.0;
            $tax = 0.0;

            foreach ($doc['items'] as $line) {
                $rate = number_format((float) $line['rate'], 2, '.', '');

                if (!isset($taxes[$rate])) {
                    $taxes[$rate] = array('rate' => (float) $line['rate'], 'ht' => 0.0, 'tax' => 0.0);
                }

                $taxes[$rate]['ht'] = round($taxes[$rate]['ht'] + $line['ht'], 2);
                $taxes[$rate]['tax'] = round($taxes[$rate]['tax'] + $line['tax'], 2);
                $ht += $line['ht'];
                $tax += $line['tax'];
            }

            $ok = $number !== '' && $wpdb->insert(self::table(), array(
                'number' => $number,
                'kind' => $kind,
                'demo' => $demo ? 1 : 0,
                'order_id' => (int) $order->get_id(),
                'origin_id' => !empty($doc['origin_id']) ? (int) $doc['origin_id'] : null,
                'customer_id' => $order->get_customer_id() ? (int) $order->get_customer_id() : null,
                'email' => (string) $doc['billing']['email'],
                'name' => (string) $doc['billing']['name'],
                'billing' => wp_json_encode($doc['billing'], JSON_UNESCAPED_UNICODE),
                'items' => wp_json_encode($doc['items'], JSON_UNESCAPED_UNICODE),
                'taxes' => wp_json_encode(array_values($taxes), JSON_UNESCAPED_UNICODE),
                'total_ht' => round($ht, 2),
                'total_tax' => round($tax, 2),
                'total_ttc' => round($ht + $tax, 2),
                'payment' => $payment,
                'source' => $source,
                'note' => isset($doc['note']) ? (string) $doc['note'] : '',
                'author' => $author,
                'issued_at' => current_time('mysql'),
                'created_by' => get_current_user_id() ?: null,
            ));

            if (!$ok) {
                $wpdb->query('ROLLBACK');

                return array();
            }

            $ids[] = (int) $wpdb->insert_id;
        }

        $wpdb->query('COMMIT');

        return $ids;
    }

    /**
     * Après l'enregistrement : PDF figé, puis e-mail.
     *
     * @param int[] $ids
     * @return string[] numéros
     */
    private static function finish(array $ids, $send)
    {
        $numbers = array();

        foreach ($ids as $id) {
            $invoice = self::find($id);

            if (!$invoice) {
                continue;
            }

            self::writePdf($invoice);
            $numbers[] = $invoice['number'];

            if ($send) {
                self::send(self::find($id));
            }
        }

        return $numbers;
    }

    private static function lock($order_id)
    {
        global $wpdb;

        return (int) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 5)', 'passflow_invoice_' . (int) $order_id)) === 1;
    }

    private static function unlock($order_id)
    {
        global $wpdb;

        $wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', 'passflow_invoice_' . (int) $order_id));
    }

    // ------------------------------------------------------------------
    // Avoirs
    // ------------------------------------------------------------------

    /**
     * Avoir sur une facture : toutes ses lignes encore actives, ou certaines ($qty : id de ligne => quantité).
     *
     * @param array<string,mixed>   $invoice
     * @param array<int,int>|null   $qty
     * @return array{success:bool,message:string,id?:int}
     */
    public static function credit(array $invoice, $qty = null, $note = '', $author = 'team', $send = true)
    {
        // Même verrou que l'émission : un remboursement WooCommerce et un « Corriger » simultanés
        // ne peuvent pas créditer deux fois la même ligne. Ce qui reste actif est relu sous verrou.
        if (!self::lock($invoice['order_id'])) {
            return array('success' => false, 'message' => __('Une autre émission est en cours pour cette commande : réessayez dans un instant.', 'smbb-passflow'));
        }

        $fresh = self::find($invoice['id']);
        $result = $fresh ? self::creditLocked($fresh, $qty, $note, $author, $send) : array('success' => false, 'message' => __('Facture introuvable.', 'smbb-passflow'));
        self::unlock($invoice['order_id']);

        return $result;
    }

    /**
     * @param array<string,mixed>   $invoice
     * @param array<int,int>|null   $qty
     */
    private static function creditLocked(array $invoice, $qty, $note, $author, $send)
    {
        $d = 'smbb-passflow';
        $order = wc_get_order($invoice['order_id']);

        if (!$order || $invoice['kind'] !== 'invoice') {
            return array('success' => false, 'message' => __('Facture introuvable.', $d));
        }

        // Ce qui reste actif sur la facture, ligne par ligne.
        $active = array();
        $amount_credited = 0.0;

        foreach ($invoice['items'] as $line) {
            $active[(int) $line['item_id']] = $line + array('left' => (int) $line['qty'], 'people' => (array) $line['booking_ids']);
        }

        foreach (self::forOrder($invoice['order_id']) as $row) {
            if ($row['kind'] !== 'credit' || (int) $row['origin_id'] !== (int) $invoice['id']) {
                continue;
            }

            foreach ($row['items'] as $line) {
                $item_id = (int) $line['item_id'];

                if ((int) $line['qty'] <= 0) {
                    $amount_credited += (float) $line['ttc'];
                    continue;
                }

                if (isset($active[$item_id])) {
                    $active[$item_id]['left'] -= (int) $line['qty'];
                    $active[$item_id]['people'] = array_values(array_diff($active[$item_id]['people'], (array) $line['booking_ids']));
                }
            }
        }

        if ($amount_credited > 0.004) {
            return array('success' => false, 'message' => __('Cette facture a déjà un avoir sur un montant : faites la suite à la main dans WooCommerce, puis contactez le support.', $d));
        }

        $lines = array();

        foreach ($active as $item_id => $line) {
            $wanted = $qty === null ? $line['left'] : (isset($qty[$item_id]) ? min((int) $qty[$item_id], $line['left']) : 0);

            if ($wanted <= 0) {
                continue;
            }

            // En cumulé sur la ligne de la facture : le dernier avoir prend le reliquat exact.
            $line_qty = max(1, (int) $line['qty']);
            $from = max(0, $line_qty - (int) $line['left']);
            $to = min($line_qty, $from + $wanted);
            $ht = self::share((float) $line['ht'], $from, $to, $line_qty);
            $tax = self::share((float) $line['tax'], $from, $to, $line_qty);
            $people = self::creditedPeople($line['people'], $wanted);

            $lines[] = array(
                'item_id' => (int) $item_id,
                'label' => $line['label'],
                'detail' => $people ? self::peopleNames($people) : '',
                'booking_ids' => array_map('intval', $people),
                'qty' => $wanted,
                'unit_ht' => (float) $line['unit_ht'],
                'ht' => $ht,
                'tax' => $tax,
                'ttc' => round($ht + $tax, 2),
                'rate' => (float) $line['rate'],
                'gross_ht' => self::share((float) (isset($line['gross_ht']) ? $line['gross_ht'] : $line['ht']), $from, $to, $line_qty),
                'gross_tax' => self::share((float) (isset($line['gross_tax']) ? $line['gross_tax'] : $line['tax']), $from, $to, $line_qty),
                'coupons' => isset($line['coupons']) ? (string) $line['coupons'] : '',
            );
        }

        if (!$lines) {
            return array('success' => false, 'message' => __('Cette facture est déjà entièrement annulée par avoir.', $d));
        }

        return self::storeCredit($order, $invoice, $lines, $note, $author, $send);
    }

    /**
     * Avoir sur un montant (remboursement partiel sans ligne) : réparti au prorata des lignes de la facture.
     *
     * @param array<string,mixed> $invoice
     */
    public static function creditAmount(array $invoice, $amount, $note = '', $author = 'auto', $send = true)
    {
        if (!self::lock($invoice['order_id'])) {
            return array('success' => false, 'message' => __('Une autre émission est en cours pour cette commande : réessayez dans un instant.', 'smbb-passflow'));
        }

        $fresh = self::find($invoice['id']);
        $result = $fresh ? self::creditAmountLocked($fresh, $amount, $note, $author, $send) : array('success' => false, 'message' => __('Facture introuvable.', 'smbb-passflow'));
        self::unlock($invoice['order_id']);

        return $result;
    }

    private static function creditAmountLocked(array $invoice, $amount, $note, $author, $send)
    {
        $order = wc_get_order($invoice['order_id']);
        $amount = round(min((float) $amount, self::remaining($invoice)), 2);

        if (!$order || $amount <= 0) {
            return array('success' => false, 'message' => __('Rien à créditer.', 'smbb-passflow'));
        }

        $ratio = $amount / max(0.01, (float) $invoice['total_ttc']);
        $lines = array();

        foreach ($invoice['items'] as $line) {
            $ht = round((float) $line['ht'] * $ratio, 2);
            $tax = round((float) $line['tax'] * $ratio, 2);
            $lines[] = array(
                'item_id' => (int) $line['item_id'],
                'label' => sprintf(__('Remboursement partiel : %s', 'smbb-passflow'), $line['label']),
                'detail' => '',
                'booking_ids' => array(),
                'qty' => 0,
                'unit_ht' => $ht,
                'ht' => $ht,
                'tax' => $tax,
                'ttc' => round($ht + $tax, 2),
                'rate' => (float) $line['rate'],
            );
        }

        // Écart d'arrondi : sur la plus grosse ligne, pour que l'avoir fasse exactement le montant remboursé.
        $gap = round($amount - array_sum(array_column($lines, 'ttc')), 2);

        if ($lines && abs($gap) >= 0.005) {
            $big = 0;

            foreach ($lines as $i => $line) {
                $big = abs($line['ttc']) > abs($lines[$big]['ttc']) ? $i : $big;
            }

            $lines[$big]['ht'] = round($lines[$big]['ht'] + $gap, 2);
            $lines[$big]['unit_ht'] = $lines[$big]['ht'];
            $lines[$big]['ttc'] = round($lines[$big]['ht'] + $lines[$big]['tax'], 2);
        }

        return self::storeCredit($order, $invoice, $lines, $note, $author, $send);
    }

    /**
     * Participants nommés sur un avoir partiel : d'abord ceux dont la réservation est annulée
     * (ce sont eux qui ont été remboursés), puis les derniers de la ligne.
     *
     * @param int[] $people
     * @return int[]
     */
    private static function creditedPeople(array $people, $wanted)
    {
        global $wpdb;

        $people = array_values(array_map('intval', $people));
        $wanted = min((int) $wanted, count($people));

        if ($wanted <= 0) {
            return array();
        }

        $cancelled = array_map('intval', (array) $wpdb->get_col(
            'SELECT id FROM ' . Lookup::table('passflow_booking') . " WHERE status = 'cancelled' AND id IN (" . implode(',', $people) . ')' // phpcs:ignore -- ids entiers.
        ));
        $first = array_values(array_intersect($people, $cancelled));
        $rest = array_reverse(array_values(array_diff($people, $cancelled)));

        return array_slice(array_merge($first, $rest), 0, $wanted);
    }

    private static function storeCredit($order, array $invoice, array $lines, $note, $author, $send)
    {
        $d = 'smbb-passflow';
        $ids = self::store($order, 'credit', array(array(
            'billing' => $invoice['billing'],
            'items' => $lines,
            'origin_id' => (int) $invoice['id'],
            'note' => (string) $note,
        )), $author);

        if (!$ids) {
            return array('success' => false, 'message' => __("L'avoir n'a pas pu être enregistré : rien n'a été émis.", $d));
        }

        $numbers = self::finish($ids, $send);
        $order->add_order_note(sprintf(__('Passflow : avoir %1$s émis sur la facture %2$s.', $d), implode(', ', $numbers), $invoice['number']));

        return array('success' => true, 'id' => $ids[0], 'message' => sprintf(__('Avoir %1$s émis sur la facture %2$s.', $d), implode(', ', $numbers), $invoice['number']));
    }

    private static function peopleNames(array $ids)
    {
        global $wpdb;

        $ids = array_map('intval', $ids);

        if (!$ids) {
            return '';
        }

        $rows = $wpdb->get_results('SELECT first_name, last_name FROM ' . Lookup::table('passflow_booking') . ' WHERE id IN (' . implode(',', $ids) . ')', ARRAY_A); // phpcs:ignore

        return implode(', ', array_map(function ($row) {
            return trim($row['first_name'] . ' ' . $row['last_name']);
        }, (array) $rows));
    }

    // ------------------------------------------------------------------
    // Automatismes WooCommerce
    // ------------------------------------------------------------------

    /**
     * Remboursement WooCommerce (y compris un retour en caisse remboursé en bon d'achat) :
     * avoir automatique pour la part déjà facturée. Une ligne pas encore facturée sort simplement
     * de ce qui reste à facturer.
     */
    public static function onRefund($order_id, $refund_id)
    {
        $order = wc_get_order($order_id);
        $refund = wc_get_order($refund_id);

        if (!$order || !$refund || !\Smbb\Passflow\Support\Schema::ready()) {
            return;
        }

        $invoices = array_values(array_filter(self::forOrder($order_id), function ($row) {
            return $row['kind'] === 'invoice';
        }));

        if (!$invoices) {
            return;
        }

        $note = trim((string) $refund->get_reason());
        $by_invoice = array();
        $lines_seen = false;
        $units = array();

        foreach (self::units($order) as $line) {
            $units[$line['id']] = $line;
        }

        foreach ($refund->get_items(array('line_item', 'fee', 'shipping')) as $ritem) {
            $item_id = (int) $ritem->get_meta('_refunded_item_id');
            $qty = abs((int) $ritem->get_quantity());
            $lines_seen = $lines_seen || $item_id > 0;

            if ($item_id <= 0 || $qty <= 0 || !isset($units[$item_id])) {
                continue;
            }

            // Ce qui reste vendu sur la ligne ne peut pas être plus facturé : l'excédent part en avoir.
            // (Une ligne pas encore facturée sort simplement de ce qui reste à facturer.)
            $item = $order->get_item($item_id);
            $sold = max(0, (int) $item->get_quantity() - abs((int) $order->get_qty_refunded_for_item($item_id)));
            $net_invoiced = 0;

            foreach ($invoices as $invoice) {
                $net_invoiced += self::activeQty($invoice, $item_id);
            }

            $to_credit = max(0, $net_invoiced - $sold);

            for ($i = count($invoices) - 1; $i >= 0 && $to_credit > 0; $i--) {
                $left = self::activeQty($invoices[$i], $item_id);

                if ($left > 0) {
                    $take = min($left, $to_credit);
                    $by_invoice[$i][$item_id] = (isset($by_invoice[$i][$item_id]) ? $by_invoice[$i][$item_id] : 0) + $take;
                    $to_credit -= $take;
                }
            }
        }

        foreach ($by_invoice as $i => $qty) {
            self::credit($invoices[$i], $qty, $note, 'auto');
        }

        // Remboursement d'un montant, sans ligne : sur la dernière facture encore active, si tout est facturé.
        if (!$lines_seen && abs((float) $refund->get_amount()) > 0.004 && !self::openUnits($order)) {
            $amount = abs((float) $refund->get_amount());

            for ($i = count($invoices) - 1; $i >= 0 && $amount > 0.004; $i--) {
                $left = self::remaining($invoices[$i]);

                if ($left > 0.004) {
                    $take = min($left, $amount);
                    self::creditAmount($invoices[$i], $take, $note, 'auto');
                    $amount -= $take;
                }
            }
        }
    }

    /**
     * Quantité d'une ligne de commande encore active sur une facture (moins ses avoirs).
     */
    private static function activeQty(array $invoice, $item_id)
    {
        $qty = 0;

        foreach ($invoice['items'] as $line) {
            if ((int) $line['item_id'] === (int) $item_id) {
                $qty += (int) $line['qty'];
            }
        }

        foreach (self::forOrder($invoice['order_id']) as $row) {
            if ($row['kind'] === 'credit' && (int) $row['origin_id'] === (int) $invoice['id']) {
                foreach ($row['items'] as $line) {
                    if ((int) $line['item_id'] === (int) $item_id) {
                        $qty -= max(0, (int) $line['qty']);
                    }
                }
            }
        }

        return max(0, $qty);
    }

    /**
     * Réglage « Facture automatique à chaque commande payée » : une facture aux coordonnées de la commande.
     */
    public static function onStatus($order_id, $from, $to)
    {
        $mode = (string) Settings::get('invoice_auto');

        if (!in_array($mode, array('siren', 'all'), true) || !in_array($to, array('processing', 'completed'), true) || !\Smbb\Passflow\Support\Schema::ready()) {
            return;
        }

        $order = wc_get_order($order_id);

        if (!$order || self::forOrder($order_id) || !self::billable($order) || ($mode === 'siren' && trim((string) $order->get_meta('_billing_siren')) === '')) {
            return;
        }

        $open = array_keys(self::openUnits($order));

        if ($open) {
            self::issue($order, array(array('billing' => self::orderBilling($order), 'units' => $open)), 'auto');
        }
    }

    // ------------------------------------------------------------------
    // Facture globale après le délai (tâche planifiée)
    // ------------------------------------------------------------------

    const CRON = 'smbb_passflow_invoice_delay';
    const DELAY_START = 'smbb_passflow_invoice_delay_start'; // option : date de mise en route du délai
    const META_SWEPT = '_passflow_invoice_swept';            // commande déjà passée par la tâche
    const DELAY_BATCH = 20;                              // factures émises au plus par passage

    /**
     * @param \Smbb\WpCodeTool\Cron\CronRegistry $registry
     */
    public static function registerCron($registry)
    {
        $registry->register(self::CRON, array(
            'label' => __('Passflow : factures après le délai', 'smbb-passflow'),
            'description' => __("Émet une facture globale, aux coordonnées de la commande, pour ce qui n'est pas encore facturé une fois le délai des réglages passé.", 'smbb-passflow'),
            'schedule' => 'hourly',
            'callback' => array(__CLASS__, 'runDelay'),
        ));
    }

    /**
     * Délai en jours (0 : jamais de facture automatique après délai).
     */
    public static function delayDays()
    {
        return min(365, Settings::int('invoice_delay'));
    }

    /**
     * Le délai ne vaut que pour les commandes payées après sa mise en route : l'activer ne facture pas
     * d'un coup, à la date du jour, tout l'historique. Le couper puis le remettre repart de zéro.
     */
    public static function syncDelayStart()
    {
        if (self::delayDays() > 0) {
            if (!get_option(self::DELAY_START)) {
                update_option(self::DELAY_START, time(), false);
            }
        } else {
            delete_option(self::DELAY_START);
        }
    }

    /**
     * Date limite pour que le client établisse ses factures lui-même (0 si pas de délai).
     *
     * @param \WC_Order $order
     */
    public static function delayDeadline($order)
    {
        $days = self::delayDays();
        $start = (int) get_option(self::DELAY_START);
        $paid = $order ? ($order->get_date_paid() ?: $order->get_date_created()) : null;

        if ($days <= 0 || !$start || !$paid || $paid->getTimestamp() < $start) {
            return 0;
        }

        return $paid->getTimestamp() + $days * DAY_IN_SECONDS;
    }

    /**
     * Correction en cours : un avoir a rouvert des lignes (« Corriger » sans nouvelle facture).
     * La tâche n'y touche pas, sinon elle referait la facture avec les coordonnées d'origine.
     * Un avoir de remboursement ne compte pas : sur une ligne, il ne dépasse jamais la quantité remboursée.
     *
     * @param \WC_Order $order
     */
    private static function correctionPending($order)
    {
        $credited = array();

        foreach (self::forOrder($order->get_id()) as $row) {
            if ($row['kind'] !== 'credit') {
                continue;
            }

            foreach ($row['items'] as $line) {
                $item_id = (int) $line['item_id'];

                if ($item_id > 0 && (int) $line['qty'] > 0) {
                    $credited[$item_id] = (isset($credited[$item_id]) ? $credited[$item_id] : 0) + (int) $line['qty'];
                }
            }
        }

        foreach ($credited as $item_id => $qty) {
            if ($qty > abs((int) $order->get_qty_refunded_for_item($item_id))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Toutes les heures : chaque commande payée depuis plus que le délai et pas (entièrement) facturée
     * reçoit une facture globale de ce qui reste, aux coordonnées de la commande, envoyée par e-mail.
     *
     * @return array<string,int>
     */
    public static function runDelay()
    {
        $counts = array('factures' => 0, 'corrections' => 0);
        $days = self::delayDays();

        if ($days <= 0 || !Module::active() || !\Smbb\Passflow\Support\Schema::ready()) {
            return $counts;
        }

        $start = (int) get_option(self::DELAY_START);

        if (!$start) {
            self::syncDelayStart(); // première fois : le délai démarre maintenant.

            return $counts;
        }

        $limit = time() - $days * DAY_IN_SECONDS;

        if ($limit <= $start) {
            return $counts;
        }

        $orders = wc_get_orders(array(
            'status' => array('processing', 'completed'),
            'date_created' => $start . '...' . $limit,
            'type' => 'shop_order',
            'limit' => 100,
            'orderby' => 'date',
            'order' => 'ASC',
            'meta_query' => array(array('key' => self::META_SWEPT, 'compare' => 'NOT EXISTS')),
        ));

        foreach ($orders as $order) {
            if ($counts['factures'] >= self::DELAY_BATCH) {
                break;
            }

            $deadline = self::delayDeadline($order);

            if (!$deadline || $deadline > time()) {
                continue; // payée plus tard que créée : on la reprendra.
            }

            if (!self::billable($order)) {
                continue;
            }

            $open = array_keys(self::openUnits($order));

            if ($open && self::correctionPending($order)) {
                $order->add_order_note(__("Passflow : délai de facturation passé, mais une correction de facture est en cours (avoir sans nouvelle facture). Pas de facture automatique : émettez-la depuis l'encadré Factures.", 'smbb-passflow'));
                $order->update_meta_data(self::META_SWEPT, 'correction');
                $order->save();
                $counts['corrections']++;
                continue;
            }

            if ($open) {
                $result = self::issue($order, array(array('billing' => self::orderBilling($order), 'units' => $open)), 'auto');

                if (empty($result['success'])) {
                    continue; // verrou pris ou erreur passagère : au prochain passage.
                }

                $counts['factures']++;
            }

            $order->update_meta_data(self::META_SWEPT, current_time('mysql'));
            $order->save();
        }

        return $counts;
    }

    // ------------------------------------------------------------------
    // PDF
    // ------------------------------------------------------------------

    private static function dir()
    {
        $upload = wp_upload_dir();
        $dir = trailingslashit($upload['basedir']) . self::DIR;

        if (!is_dir($dir)) {
            wp_mkdir_p($dir);
        }

        if (!file_exists($dir . '/.htaccess')) {
            @file_put_contents($dir . '/.htaccess', "Require all denied\nDeny from all\n"); // phpcs:ignore
            @file_put_contents($dir . '/index.php', "<?php // Silence.\n"); // phpcs:ignore
        }

        return $dir;
    }

    /**
     * Écrit le PDF d'un document émis (une seule fois).
     *
     * @param array<string,mixed> $invoice
     */
    private static function writePdf(array $invoice)
    {
        if (!class_exists('\\TCPDF')) {
            return '';
        }

        $year = substr((string) $invoice['issued_at'], 0, 4) ?: date('Y');
        $folder = self::dir() . '/' . $year;
        wp_mkdir_p($folder);
        $name = $year . '/' . sanitize_file_name($invoice['number']) . '-' . wp_generate_password(8, false, false) . '.pdf';
        $content = self::render($invoice);
        file_put_contents(self::dir() . '/' . $name, $content);

        global $wpdb;
        $wpdb->update(self::table(), array('file' => $name, 'hash' => hash('sha256', $content)), array('id' => (int) $invoice['id']));

        return $name;
    }

    /**
     * Chemin du PDF (écrit à la demande s'il manque, depuis les données figées).
     *
     * @param array<string,mixed> $invoice
     */
    /**
     * Supprime toutes les factures et avoirs de démo (lignes, PDF) et remet leur compteur à zéro.
     *
     * @return int nombre de documents supprimés
     */
    public static function purgeDemo()
    {
        global $wpdb;

        if (!\Smbb\Passflow\Support\Schema::ready()) {
            return 0;
        }

        $rows = (array) $wpdb->get_results('SELECT id, file FROM ' . self::table() . ' WHERE demo = 1', ARRAY_A);

        foreach ($rows as $row) {
            if (!empty($row['file']) && is_file(self::dir() . '/' . $row['file'])) {
                wp_delete_file(self::dir() . '/' . $row['file']);
            }
        }

        $wpdb->query('DELETE FROM ' . self::table() . ' WHERE demo = 1');
        $wpdb->query('DELETE FROM ' . Lookup::table('passflow_counter') . " WHERE name LIKE 'demo-%'");

        return count($rows);
    }

    public static function demoCount()
    {
        global $wpdb;

        return \Smbb\Passflow\Support\Schema::ready() ? (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . self::table() . ' WHERE demo = 1') : 0;
    }

    public static function path(array $invoice)
    {
        $file = $invoice['file'] !== '' ? self::dir() . '/' . $invoice['file'] : '';

        if ($file === '' || !is_readable($file)) {
            $name = self::writePdf($invoice);
            $file = $name !== '' ? self::dir() . '/' . $name : '';
        }

        return $file;
    }

    /**
     * Identité de l'émetteur (Réglages › Factures).
     *
     * @return array<int,string> lignes
     */
    public static function seller()
    {
        $lines = array(
            trim((string) Settings::get('invoice_company')) ?: Settings::businessName(),
            trim((string) Settings::get('invoice_legal')),
            trim((string) Settings::get('invoice_address')),
            trim((string) Settings::get('invoice_siret')) !== '' ? sprintf(__('SIRET %s', 'smbb-passflow'), Settings::get('invoice_siret')) : '',
            trim((string) Settings::get('invoice_rcs')),
            trim((string) Settings::get('invoice_vat_number')) !== '' ? sprintf(__('TVA intracommunautaire %s', 'smbb-passflow'), Settings::get('invoice_vat_number')) : '',
            implode(' · ', array_filter(array(trim((string) Settings::get('contact_email')), trim((string) Settings::get('contact_phone'))))),
        );

        return array_values(array_filter($lines));
    }

    public static function franchise()
    {
        return Settings::get('invoice_vat_mode') === 'franchise';
    }

    private static function money($value)
    {
        return number_format((float) $value, 2, ',', ' ') . ' €';
    }

    /**
     * Contenu PDF d'une facture ou d'un avoir (A4).
     *
     * @param array<string,mixed> $invoice
     */
    public static function render(array $invoice, $preview = false)
    {
        $d = 'smbb-passflow';
        $credit = $invoice['kind'] === 'credit';
        $franchise = self::franchise();
        $color = (string) Settings::get('ticket_color');
        $color = preg_match('/^#[0-9a-fA-F]{6}$/', $color) ? $color : '#8c3c34';
        $rgb = array(hexdec(substr($color, 1, 2)), hexdec(substr($color, 3, 2)), hexdec(substr($color, 5, 2)));

        $pdf = new \TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->SetCreator(Settings::businessName());
        $pdf->SetTitle(self::kindLabel($invoice['kind']) . ' ' . $invoice['number']);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(15, 15, 15);
        $pdf->SetAutoPageBreak(true, 30);
        $pdf->AddPage();

        // Facture de démo : filigrane en travers de la page, sans valeur comptable.
        if (!empty($invoice['demo'])) {
            $pdf->SetAlpha(0.1);
            $pdf->SetFont('dejavusans', 'B', 62);
            $pdf->SetTextColor(160, 30, 30);
            $pdf->StartTransform();
            $pdf->Rotate(45, 105, 160);
            $pdf->Text(12, 150, __('DÉMO · SANS VALEUR', $d));
            $pdf->StopTransform();
            $pdf->SetAlpha(1);
        }

        $pdf->SetTextColor(40, 34, 30);

        // Émetteur (logo des billets s'il y en a un).
        $logo = (int) Settings::get('ticket_logo_id');
        $logo_path = $logo ? get_attached_file($logo) : '';
        $y = 15;

        if ($logo_path && is_readable($logo_path) && preg_match('/\.(png|jpe?g)$/i', $logo_path)) {
            $pdf->Image($logo_path, 15, 15, 0, 18, '', '', '', false, 300, '', false, false, 0, 'LT');
            $y = 36;
        }

        $seller = self::seller();
        $pdf->SetFont('dejavusans', 'B', 11);
        $pdf->SetXY(15, $y);
        $pdf->Cell(90, 6, array_shift($seller), 0, 1);
        $pdf->SetFont('dejavusans', '', 8.5);
        $pdf->SetX(15);
        $pdf->MultiCell(90, 4.2, implode("\n", $seller), 0, 'L');
        $after_seller = $pdf->GetY();

        // Titre et références.
        $pdf->SetFont('dejavusans', 'B', 18);
        $pdf->SetTextColor($rgb[0], $rgb[1], $rgb[2]);
        $pdf->SetXY(110, 15);
        $pdf->Cell(85, 9, ($preview ? __('APERÇU', $d) . ' — ' : (!empty($invoice['demo']) ? __('DÉMO', $d) . ' — ' : '')) . self::kindLabel($invoice['kind']), 0, 1, 'R');
        $pdf->SetTextColor(40, 34, 30);
        $pdf->SetFont('dejavusans', '', 9);
        $refs = array(
            sprintf(__('N° %s', $d), $invoice['number']),
            sprintf(__('Date : %s', $d), mysql2date('d/m/Y', (string) $invoice['issued_at'])),
            sprintf(__('Commande n° %s', $d), self::orderNumber((int) $invoice['order_id'])),
        );

        if ($credit && !empty($invoice['origin_id'])) {
            $origin = self::find($invoice['origin_id']);
            $refs[] = sprintf(__('Sur la facture %1$s du %2$s', $d), $origin ? $origin['number'] : '?', $origin ? mysql2date('d/m/Y', $origin['issued_at']) : '');
        }

        $pdf->SetX(110);
        $pdf->MultiCell(85, 4.8, implode("\n", $refs), 0, 'R');

        // Client.
        $b = $invoice['billing'];
        $client = array_filter(array(
            $b['contact'] !== '' ? sprintf(__("À l'attention de %s", $d), $b['contact']) : '',
            $b['address'],
            trim($b['postcode'] . ' ' . $b['city']),
            $b['country'] !== '' && $b['country'] !== 'FR' && function_exists('WC') ? (isset(WC()->countries->countries[$b['country']]) ? WC()->countries->countries[$b['country']] : $b['country']) : '',
            $b['siren'] !== '' ? sprintf(__('SIREN %s', $d), $b['siren']) : '',
            $b['vat'] !== '' ? sprintf(__('TVA %s', $d), $b['vat']) : '',
            $b['email'],
        ));
        $top = max($after_seller, $pdf->GetY()) + 6;
        $pdf->SetFillColor(247, 244, 241);
        $pdf->SetXY(110, $top);
        $pdf->SetFont('dejavusans', '', 7.5);
        $pdf->Cell(85, 5, mb_strtoupper($credit ? __('Avoir établi pour', $d) : __('Facturé à', $d)), 0, 1, 'L', true);
        $pdf->SetX(110);
        $pdf->SetFont('dejavusans', 'B', 10);
        $pdf->Cell(85, 6, $b['name'], 0, 1, 'L', true);
        $pdf->SetX(110);
        $pdf->SetFont('dejavusans', '', 9);
        $pdf->MultiCell(85, 4.6, implode("\n", $client) . "\n", 0, 'L', true);
        $pdf->SetY(max($pdf->GetY(), $top) + 8);

        // Lignes.
        $w = $franchise ? array(105, 15, 30, 30) : array(80, 13, 25, 17, 25, 20);
        $head = $franchise
            ? array(__('Désignation', $d), __('Qté', $d), __('Prix unitaire', $d), __('Montant', $d))
            : array(__('Désignation', $d), __('Qté', $d), __('PU HT', $d), __('TVA', $d), __('Total HT', $d), __('Total TTC', $d));
        $pdf->SetFillColor($rgb[0], $rgb[1], $rgb[2]);
        $text = (0.299 * $rgb[0] + 0.587 * $rgb[1] + 0.114 * $rgb[2]) / 255 > 0.62 ? array(40, 34, 30) : array(255, 255, 255);
        $pdf->SetTextColor($text[0], $text[1], $text[2]);
        $pdf->SetFont('dejavusans', 'B', 8.5);

        foreach ($head as $i => $label) {
            $pdf->Cell($w[$i], 7, $label, 0, 0, $i === 0 ? 'L' : 'R', true);
        }

        $pdf->Ln();
        $pdf->SetTextColor(40, 34, 30);
        $pdf->SetFont('dejavusans', '', 9);

        // Lignes au prix normal, puis la remise (bon cadeau, code promo) en lignes négatives, par taux.
        $rows = array();
        $discounts = array();

        foreach ($invoice['items'] as $line) {
            $gross_ht = isset($line['gross_ht']) ? (float) $line['gross_ht'] : (float) $line['ht'];
            $gross_tax = isset($line['gross_tax']) ? (float) $line['gross_tax'] : (float) $line['tax'];
            $rows[] = array('label' => $line['label'] . ($line['detail'] !== '' ? "\n" . $line['detail'] : ''), 'qty' => (int) $line['qty'], 'ht' => $gross_ht, 'ttc' => $gross_ht + $gross_tax, 'rate' => (float) $line['rate']);

            if ($gross_ht - (float) $line['ht'] > 0.004) {
                $key = number_format((float) $line['rate'], 2, '.', '') . '|' . (isset($line['coupons']) ? $line['coupons'] : '');

                if (!isset($discounts[$key])) {
                    $discounts[$key] = array('label' => (isset($line['coupons']) && $line['coupons'] !== '' ? sprintf(__('Bon cadeau ou remise %s', $d), $line['coupons']) : __('Remise', $d)), 'qty' => 0, 'ht' => 0.0, 'ttc' => 0.0, 'rate' => (float) $line['rate']);
                }

                $discounts[$key]['ht'] -= $gross_ht - (float) $line['ht'];
                $discounts[$key]['ttc'] -= ($gross_ht + $gross_tax) - (float) $line['ttc'];
            }
        }

        foreach (array_merge($rows, array_values($discounts)) as $line) {
            $label = $line['label'];
            $qty = $line['qty'] > 0 ? (string) $line['qty'] : '';
            $unit_ttc = $line['qty'] > 0 ? $line['ttc'] / $line['qty'] : $line['ttc'];
            $cells = $franchise
                ? array($label, $qty, self::money($unit_ttc), self::money($line['ttc']))
                : array($label, $qty, self::money($line['qty'] > 0 ? $line['ht'] / $line['qty'] : $line['ht']), rtrim(rtrim(number_format($line['rate'], 2, ',', ''), '0'), ',') . ' %', self::money($line['ht']), self::money($line['ttc']));
            $height = max(7, $pdf->getStringHeight($w[0], $label) + 2);

            if ($pdf->GetY() + $height > 260) {
                $pdf->AddPage();
            }

            $y0 = $pdf->GetY();
            $pdf->MultiCell($w[0], $height, $label, 'B', 'L', false, 0, '', '', true, 0, false, true, $height, 'M');

            for ($i = 1; $i < count($cells); $i++) {
                $pdf->Cell($w[$i], $height, $cells[$i], 'B', 0, 'R');
            }

            $pdf->Ln();
            $pdf->SetY(max($pdf->GetY(), $y0 + $height));
        }

        // Totaux.
        $pdf->Ln(4);
        $x = 115;
        $pdf->SetFont('dejavusans', '', 9);

        if (!$franchise) {
            $pdf->SetX($x);
            $pdf->Cell(50, 6, __('Total HT', $d), 0, 0);
            $pdf->Cell(30, 6, self::money($invoice['total_ht']), 0, 1, 'R');

            foreach ($invoice['taxes'] as $tax) {
                $pdf->SetX($x);
                $pdf->Cell(50, 6, sprintf(__('TVA %1$s %% sur %2$s', $d), rtrim(rtrim(number_format((float) $tax['rate'], 2, ',', ''), '0'), ','), self::money($tax['ht'])), 0, 0);
                $pdf->Cell(30, 6, self::money($tax['tax']), 0, 1, 'R');
            }
        }

        $pdf->SetX($x);
        $pdf->SetFont('dejavusans', 'B', 11);
        $pdf->SetFillColor(247, 244, 241);
        $pdf->Cell(50, 8, $credit ? __('Total de l\'avoir TTC', $d) : ($franchise ? __('Total', $d) : __('Total TTC', $d)), 0, 0, 'L', true);
        $pdf->Cell(30, 8, self::money($invoice['total_ttc']), 0, 1, 'R', true);
        $pdf->SetFont('dejavusans', '', 8.5);

        if ($franchise) {
            $pdf->SetX($x);
            $pdf->Cell(80, 5, __('TVA non applicable, article 293 B du CGI', $d), 0, 1);
        }

        $pdf->Ln(4);
        $pdf->SetX(15);
        $info = array();

        if (!$credit && $invoice['payment'] !== '') {
            $info[] = sprintf(__('Réglé le %1$s par %2$s.', $d), self::paidDate((int) $invoice['order_id'], $invoice['issued_at']), $invoice['payment']);
        }

        if ($credit) {
            $info[] = __('Cet avoir annule, pour le montant indiqué, la facture d\'origine.', $d);
        }

        if (!empty($invoice['note'])) {
            $info[] = (string) $invoice['note'];
        }

        if ($info) {
            $pdf->MultiCell(180, 4.6, implode("\n", $info), 0, 'L');
        }

        // Mentions de bas de page.
        $footer = trim((string) Settings::get('invoice_footer'));

        if ($footer !== '') {
            $pdf->SetY(-32);
            $pdf->SetFont('dejavusans', '', 7);
            $pdf->SetTextColor(110, 100, 95);
            $pdf->MultiCell(180, 3.6, $footer, 'T', 'C');
        }

        return $pdf->Output('', 'S');
    }

    private static function orderNumber($order_id)
    {
        $order = $order_id ? wc_get_order($order_id) : null;

        return $order ? (string) $order->get_order_number() : (string) $order_id;
    }

    private static function paidDate($order_id, $fallback)
    {
        $order = $order_id ? wc_get_order($order_id) : null;
        $date = $order ? ($order->get_date_paid() ?: $order->get_date_created()) : null;

        return $date ? $date->date_i18n('d/m/Y') : mysql2date('d/m/Y', (string) $fallback);
    }

    /**
     * Facture d'exemple (Réglages › Factures › Aperçu PDF) : rien n'est enregistré, aucun numéro consommé.
     */
    public static function sample()
    {
        $line = array('item_id' => 0, 'label' => __('Atelier Mochi', 'smbb-passflow'), 'detail' => __('Marie Durand, Paul Martin', 'smbb-passflow'), 'booking_ids' => array(), 'qty' => 2, 'unit_ht' => 70.83, 'ht' => 141.67, 'tax' => 28.33, 'ttc' => 170.0, 'rate' => 20.0);

        return array(
            'id' => 0,
            'number' => self::nextPreview('invoice'),
            'kind' => 'invoice',
            'order_id' => 0,
            'origin_id' => null,
            'billing' => array('type' => 'person', 'name' => 'Marie Durand', 'contact' => '', 'address' => '12 rue des Lilas', 'postcode' => '63000', 'city' => 'Clermont-Ferrand', 'country' => 'FR', 'email' => 'marie@example.com', 'siren' => '', 'vat' => ''),
            'items' => array($line),
            'taxes' => array(array('rate' => 20.0, 'ht' => 141.67, 'tax' => 28.33)),
            'total_ht' => 141.67,
            'total_tax' => 28.33,
            'total_ttc' => 170.0,
            'payment' => __('Carte bancaire', 'smbb-passflow'),
            'note' => '',
            'issued_at' => current_time('mysql'),
            'file' => '',
        );
    }

    // ------------------------------------------------------------------
    // Liens et téléchargement
    // ------------------------------------------------------------------

    public static function token(array $invoice)
    {
        return substr(hash_hmac('sha256', 'facture|' . (int) $invoice['id'] . '|' . $invoice['number'], wp_salt('auth')), 0, 24);
    }

    public static function url(array $invoice)
    {
        return add_query_arg(array(self::DOWNLOAD => (int) $invoice['id'], 'k' => self::token($invoice)), home_url('/'));
    }

    /**
     * Le client voit ses factures (commande à son compte), l'équipe toutes.
     *
     * @param array<string,mixed> $invoice
     */
    public static function canSee(array $invoice)
    {
        if (\Smbb\Passflow\Support\Roles::canManage()) {
            return true;
        }

        $user = get_current_user_id();
        $order = wc_get_order($invoice['order_id']);

        return $user && $order && (int) $order->get_customer_id() === $user;
    }

    public static function download()
    {
        if (empty($_GET[self::DOWNLOAD])) {
            return;
        }

        $invoice = self::find(absint($_GET[self::DOWNLOAD]));
        $token = isset($_GET['k']) ? sanitize_text_field(wp_unslash($_GET['k'])) : '';

        if (!$invoice || !(hash_equals(self::token($invoice), $token) || self::canSee($invoice))) {
            wp_die(esc_html__('Ce document est introuvable ou le lien a expiré.', 'smbb-passflow'), '', array('response' => 404));
        }

        self::output($invoice);
    }

    /**
     * @param array<string,mixed> $invoice
     */
    public static function output(array $invoice)
    {
        $file = self::path($invoice);

        if ($file === '' || !is_readable($file)) {
            wp_die(esc_html__('PDF indisponible : la bibliothèque TCPDF (fournie par SMBB WP CodeTool) est absente.', 'smbb-passflow'));
        }

        nocache_headers();
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="' . sanitize_file_name($invoice['number']) . '.pdf"');
        header('Content-Length: ' . filesize($file));
        readfile($file); // phpcs:ignore
        exit;
    }

    // ------------------------------------------------------------------
    // E-mail
    // ------------------------------------------------------------------

    /**
     * Envoie le PDF à l'adresse de la facture (acheteur en copie, copie compta si réglée).
     *
     * @param array<string,mixed>|null $invoice
     * @return bool
     */
    public static function send($invoice, $to = '')
    {
        if (!$invoice) {
            return false;
        }

        $order = wc_get_order($invoice['order_id']);
        $to = $to !== '' ? $to : (string) $invoice['email'];

        if ($to === '' || !is_email($to)) {
            return false;
        }

        $cc = array();
        $buyer = $order ? strtolower((string) $order->get_billing_email()) : '';

        if ((int) Settings::get('invoice_cc_buyer') === 1 && $buyer !== '' && $buyer !== strtolower($to) && is_email($buyer)) {
            $cc[] = $buyer;
        }

        // Les factures de démo ne partent jamais à la comptabilité.
        $bcc = !empty($invoice['demo']) ? array() : array_filter(array_map('trim', explode(',', (string) Settings::get('invoice_accounting_email'))), 'is_email');
        $file = self::path($invoice);
        $origin = !empty($invoice['origin_id']) ? self::find($invoice['origin_id']) : null;
        $b = $invoice['billing'];
        $first = $b['type'] === 'company' ? ($b['contact'] !== '' ? $b['contact'] : $b['name']) : $b['name'];

        $results = Notifier::toCustomer($invoice['kind'] === 'credit' ? 'credit_note' : 'invoice', array(
            'first_name' => $first,
            'last_name' => '',
            'email' => $to,
            'phone' => '',
        ), null, array(
            'numero_facture' => (string) $invoice['number'],
            'numero_origine' => $origin ? (string) $origin['number'] : '',
            'montant' => self::money($invoice['total_ttc']),
            'commande' => self::orderNumber((int) $invoice['order_id']),
            'lien_facture' => self::url($invoice),
        ), null, array(
            'attachments' => $file !== '' ? array(sanitize_file_name($invoice['number']) . '.pdf' => $file) : array(),
            'cc' => $cc,
            'bcc' => array_values($bcc),
        ));

        foreach ($results as $result) {
            if (in_array($result['status'], array('sent', 'test'), true)) {
                return true;
            }
        }

        return false;
    }
}
