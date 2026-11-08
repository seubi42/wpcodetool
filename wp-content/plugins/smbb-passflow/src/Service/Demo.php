<?php

namespace Smbb\Passflow\Service;

use Smbb\Passflow\Repository\Lookup;
use Smbb\Passflow\Repository\Slots;
use Smbb\Passflow\Support\Prices;
use Smbb\Passflow\Support\Roles;
use Smbb\Passflow\Support\Settings;

defined('ABSPATH') || exit;

/**
 * Données de démo de l'assistant de démarrage : un intervenant, deux clients (un particulier,
 * une société avec SIREN), deux dates (ou un week-end samedi + dimanche avec son pass pour la
 * billetterie VIP), trois réservations (commande en ligne, commande pro, ajout manuel), un mini
 * catalogue sur place et un bon cadeau.
 *
 * Tout ce qui est créé est noté dans l'option smbb_passflow_demo : « Supprimer les données de démo »
 * efface exactement ces objets, rien d'autre. Tant qu'elles existent, le mode test reste forcé
 * (aucun message ne part aux adresses @example.com) et les factures vont dans la série DEMO-.
 */
final class Demo
{
    const OPTION = 'smbb_passflow_demo';
    const DOMAIN = 'example.com';

    /** @var bool|null */
    private static $exists = null;

    public static function exists()
    {
        if (self::$exists === null) {
            self::$exists = is_array(get_option(self::OPTION, null));
        }

        return self::$exists;
    }

    /**
     * @return array<string,mixed>
     */
    public static function record()
    {
        $record = get_option(self::OPTION, array());

        return array_merge(array(
            'created_at' => 0,
            'users' => array(),
            'hosts' => array(),
            'activities' => array(),
            'slots' => array(),
            'bookings' => array(),
            'products' => array(),
            'catalogs' => array(),
            'orders' => array(),
            'coupons' => array(),
            'test_mode_before' => null,
        ), is_array($record) ? $record : array());
    }

    private static function store(array $record)
    {
        update_option(self::OPTION, $record, false);
        self::$exists = true;
    }

    /**
     * Ce que contient le jeu de démo, pour l'écran de l'assistant.
     *
     * @return string[]
     */
    public static function summary()
    {
        $d = 'smbb-passflow';
        $demo = self::profileDemo();
        $weekend = !empty($demo['weekend_pass']);
        $items = array();

        foreach ($demo['catalog'] as $item) {
            $items[] = $item['name'] . ' (' . self::money($item['price']) . ')';
        }

        return array(
            sprintf(__('1 intervenant : %s, avec son compte et son espace.', $d), $demo['host'][0] . ' ' . $demo['host'][1]),
            __('2 clients : Léa Dubois (particulier) et Studio Nori SAS (société, avec un SIREN fictif).', $d),
            $weekend
                ? __('Un week-end : samedi et dimanche, plus un pass 2 jours qui regroupe les deux.', $d)
                : __('2 dates sur votre première offre, dans 3 jours et dans 10 jours.', $d),
            $weekend
                ? __('3 réservations : Léa sur le pass 2 jours (commande payée), Studio Nori 2 places le samedi (facture automatique), un ajout manuel à confirmer le dimanche.', $d)
                : __('3 réservations : Léa (commande payée), Studio Nori 2 places (facture automatique), un ajout manuel à confirmer.', $d),
            sprintf(__('Un catalogue sur place « Comptoir » : %s.', $d), implode(', ', $items)),
            __('Un bon cadeau de 50 €, pas encore utilisé.', $d),
        );
    }

    /**
     * Section « demo » de l'extras.json du profil, complétée.
     *
     * @return array{host:array,catalog:array,weekend_pass:bool,pass_price:float,event:array,time:string}
     */
    private static function profileDemo()
    {
        $extras = Onboarding::memory('extras', array());
        $demo = is_array($extras) && isset($extras['demo']) && is_array($extras['demo']) ? $extras['demo'] : array();

        $catalog = array();

        foreach (isset($demo['catalog']) && is_array($demo['catalog']) ? $demo['catalog'] : array() as $item) {
            if (is_array($item) && !empty($item['name'])) {
                $catalog[] = array('name' => (string) $item['name'], 'price' => (float) (isset($item['price']) ? $item['price'] : 0), 'sku' => isset($item['sku']) ? (string) $item['sku'] : '');
            }
        }

        if (!$catalog) {
            $catalog = array(array('name' => __('Boisson', 'smbb-passflow'), 'price' => 3.0, 'sku' => ''), array('name' => __('Tote bag', 'smbb-passflow'), 'price' => 15.0, 'sku' => ''));
        }

        $host = isset($demo['host']) && is_array($demo['host']) && count($demo['host']) >= 2 ? array_values($demo['host']) : array('Camille', 'Martin');

        return array(
            'host' => array((string) $host[0], (string) $host[1]),
            'catalog' => array_slice($catalog, 0, 4),
            'weekend_pass' => !empty($demo['weekend_pass']),
            'pass_price' => isset($demo['pass_price']) ? (float) $demo['pass_price'] : 0.0,
            'event' => isset($demo['event']) && is_array($demo['event']) ? $demo['event'] : array(),
            'time' => isset($demo['time']) && preg_match('/^\d{2}:\d{2}$/', (string) $demo['time']) ? (string) $demo['time'] : '19:00',
        );
    }

    private static function money($amount)
    {
        return number_format((float) $amount, (float) $amount == (int) $amount ? 0 : 2, ',', ' ') . ' €';
    }

    // ------------------------------------------------------------------
    // Création
    // ------------------------------------------------------------------

    /**
     * @return true|string
     */
    public static function create()
    {
        $d = 'smbb-passflow';

        if (self::exists()) {
            return __('Les données de démo existent déjà : supprimez-les d\'abord pour les recréer.', $d);
        }

        if (!\Smbb\Passflow\Support\Schema::ready()) {
            return __('Les tables de Passflow ne sont pas prêtes.', $d);
        }

        $demo = self::profileDemo();
        $record = self::record();
        $record['created_at'] = time();
        $record['test_mode_before'] = (int) Settings::get('test_mode');

        // Noté d'abord : le mode test et la série de factures DEMO- s'appliquent dès la première commande.
        self::store($record);

        $admin = wp_get_current_user();
        $test = array('test_mode' => 1);

        if (trim((string) Settings::get('test_email')) === '' && trim((string) Settings::get('test_phone')) === '') {
            $test['test_email'] = $admin && $admin->user_email ? $admin->user_email : (string) get_option('admin_email');
        }

        Onboarding::saveSettings($test);

        // Intervenant.
        $host_user = self::user($record, 'demo-' . sanitize_title($demo['host'][0]), $demo['host'][0], $demo['host'][1], Roles::ROLE_HOST, '+33600000001');
        $host_id = Onboarding::saveRow('passflow-host', array(
            'user_id' => $host_user ?: null,
            'first_name' => $demo['host'][0],
            'last_name' => $demo['host'][1],
            'email' => $host_user ? get_userdata($host_user)->user_email : '',
            'phone' => '+33600000001',
            'bio' => __('Intervenant de démonstration.', $d),
            'status' => 'active',
        ));

        if ($host_id) {
            $record['hosts'][] = $host_id;
        }

        // Clients.
        $lea = self::user($record, 'demo-lea', 'Léa', 'Dubois', 'customer', '+33600000002');
        $nori = self::user($record, 'demo-studio-nori', 'Inès', 'Moreau', 'customer', '+33600000003', array(
            'billing_company' => 'Studio Nori SAS',
            'billing_address_1' => '8 rue des Artistes',
            'billing_postcode' => '69002',
            'billing_city' => 'Lyon',
        ));
        self::store($record);

        // Offre : la première offre de l'assistant, sinon une fiche de démo.
        $offer = Onboarding::offer();
        $activity_id = $offer ? (int) $offer['id'] : 0;

        if (!$activity_id || $demo['weekend_pass']) {
            $defaults = Onboarding::offerDefaults();
            $event = array_merge($defaults, $demo['event']);
            $activity_id = Onboarding::saveRow('passflow-activity', array(
                'name' => !empty($event['name']) ? (string) $event['name'] : __('Offre de démonstration', $d),
                'location' => (string) $event['location'],
                'duration_minutes' => (int) $event['duration_minutes'],
                'default_price' => (float) $event['default_price'],
                'default_capacity' => max(4, (int) $event['default_capacity']),
                'color' => sanitize_hex_color((string) $event['color']) ?: '#8c3c34',
                'description' => (string) $event['description'],
                'status' => 'active',
            ));

            if (!$activity_id) {
                self::store($record);

                return __("La fiche de démo n'a pas pu être créée.", $d);
            }

            $record['activities'][] = $activity_id;
        }

        $product_id = Onboarding::ensureProduct($activity_id);

        if ($product_id && in_array($activity_id, $record['activities'], true)) {
            $record['products'][] = $product_id;
        }

        $activity = Lookup::atelier($activity_id);
        $capacity = max(6, (int) $activity['default_capacity']);

        // Dates.
        if ($demo['weekend_pass']) {
            $saturday = strtotime('saturday +1 week', current_time('timestamp'));
            $first = self::slot($record, $activity_id, date('Y-m-d', $saturday), '', '', $capacity, $host_id, true);
            $second = self::slot($record, $activity_id, date('Y-m-d', $saturday + DAY_IN_SECONDS), '', '', $capacity, $host_id, true);
            $pass = $first && $second ? Onboarding::saveRow('passflow-slot', array(
                'activity_id' => $activity_id,
                'kind' => 'pass',
                'pass_slot_ids' => array((string) $first, (string) $second),
                'capacity' => '0',
                'price' => $demo['pass_price'] > 0 ? $demo['pass_price'] : round((float) $activity['default_price'] * 1.8, 2),
                'host_ids' => $host_id ? array((string) $host_id) : array(),
                'status' => 'open',
            )) : 0;

            if ($pass) {
                $record['slots'][] = $pass;
            }

            $lines = array(array($lea, $pass ?: $first, 1), array($nori, $first, 2));
            $manual_slot = $second;
        } else {
            $minutes = max(30, (int) $activity['duration_minutes'] ?: 120);
            $start = $demo['time'] . ':00';
            $end = date('H:i:s', strtotime('2000-01-01 ' . $start) + $minutes * 60);
            $first = self::slot($record, $activity_id, date('Y-m-d', current_time('timestamp') + 3 * DAY_IN_SECONDS), $start, $end, $capacity, $host_id, false);
            $second = self::slot($record, $activity_id, date('Y-m-d', current_time('timestamp') + 10 * DAY_IN_SECONDS), $start, $end, $capacity, $host_id, false);
            $lines = array(array($lea, $first, 1), array($nori, $first, 2));
            $manual_slot = $second;
        }

        self::store($record);

        // Réservations : deux commandes payées, un ajout manuel.
        foreach ($lines as $index => $line) {
            list($user_id, $slot_id, $qty) = $line;

            if (!$slot_id) {
                continue;
            }

            $order_id = $product_id ? self::order($user_id, $slot_id, $product_id, $qty, $index === 1) : 0;

            if ($order_id) {
                $record['orders'][] = $order_id;
            } else {
                $record['bookings'][] = self::manualBooking($slot_id, $user_id, 'confirmed');
            }

            self::store($record);
        }

        if ($manual_slot) {
            $record['bookings'][] = self::manualBooking($manual_slot, 0, 'pending', array('Hugo', 'Lefèvre', 'hugo.demo@' . self::DOMAIN, '+33600000004'));
        }

        // Catalogue sur place.
        if (\Smbb\Passflow\WooCommerce\Module::active()) {
            $products = array();

            foreach ($demo['catalog'] as $item) {
                $product = new \WC_Product_Simple();
                $product->set_name($item['name']);
                $product->set_status('publish');
                $product->set_catalog_visibility('hidden');
                $product->set_regular_price(wc_format_decimal(Prices::forCart($item['price'])));
                $product->set_manage_stock(true);
                $product->set_stock_quantity(20);

                if ($item['sku'] !== '' && !wc_get_product_id_by_sku($item['sku'])) {
                    $product->set_sku($item['sku']);
                }

                $id = (int) $product->save();

                if ($id) {
                    $products[] = (string) $id;
                    $record['products'][] = $id;
                }
            }

            if ($products) {
                $catalog = Onboarding::saveRow('passflow-catalog', array(
                    'name' => __('Comptoir', $d),
                    'parent_id' => null,
                    'position' => 0,
                    'active' => 1,
                    'activity_ids' => array(),
                    'host_ids' => array(),
                    'product_ids' => $products,
                    'notes' => __('Catalogue de démonstration.', $d),
                ));

                if ($catalog) {
                    $record['catalogs'][] = $catalog;
                }
            }

            $gift = \Smbb\Passflow\WooCommerce\GiftCards::create(array('amount' => 50, 'recipient' => __('Démo : à offrir', $d)));

            if (!empty($gift['id'])) {
                $record['coupons'][] = (int) $gift['id'];
            }
        }

        $record['bookings'] = array_values(array_filter(array_map('intval', $record['bookings'])));
        self::store($record);

        return true;
    }

    /**
     * Compte WordPress de démo (adresse @example.com, jamais délivrée).
     */
    private static function user(array &$record, $login, $first, $last, $role, $phone, array $billing = array())
    {
        $login = sanitize_user($login, true);
        $email = $login . '@' . self::DOMAIN;
        $existing = get_user_by('email', $email);

        if ($existing) {
            return (int) $existing->ID;
        }

        $id = wp_insert_user(array(
            'user_login' => username_exists($login) ? $login . '-' . wp_rand(100, 999) : $login,
            'user_email' => $email,
            'user_pass' => wp_generate_password(24),
            'first_name' => $first,
            'last_name' => $last,
            'display_name' => $first . ' ' . $last,
            'role' => get_role($role) ? $role : 'subscriber',
        ));

        if (is_wp_error($id)) {
            return 0;
        }

        $meta = array_merge(array(
            'billing_first_name' => $first,
            'billing_last_name' => $last,
            'billing_email' => $email,
            'billing_phone' => $phone,
            'billing_country' => 'FR',
        ), $billing);

        foreach ($meta as $key => $value) {
            update_user_meta($id, $key, $value);
        }

        $record['users'][] = (int) $id;

        return (int) $id;
    }

    private static function slot(array &$record, $activity_id, $date, $start, $end, $capacity, $host_id, $all_day)
    {
        $id = Onboarding::saveRow('passflow-slot', array(
            'activity_id' => $activity_id,
            'kind' => 'day',
            'start_date' => $date,
            'start_time' => $all_day ? '' : $start,
            'end_time' => $all_day ? '' : $end,
            'all_day' => $all_day ? '1' : '0',
            'capacity' => (string) $capacity,
            'host_ids' => $host_id ? array((string) $host_id) : array(),
            'status' => 'open',
            'internal_note' => __('Date de démonstration.', 'smbb-passflow'),
        ));

        if ($id) {
            $record['slots'][] = $id;
        }

        return $id;
    }

    /**
     * Commande WooCommerce payée (moyen « Démo »), comme un achat sur la fiche produit.
     * $pro : société avec SIREN (facture automatique en mode « SIREN »).
     */
    private static function order($user_id, $slot_id, $product_id, $qty, $pro)
    {
        $slot = Slots::find($slot_id);
        $product = wc_get_product($product_id);

        if (!$slot || !$product || !$user_id) {
            return 0;
        }

        $user = get_userdata($user_id);
        $unit = Prices::ht(\Smbb\Passflow\WooCommerce\Front::price($slot));

        try {
            $order = wc_create_order(array('customer_id' => $user_id, 'created_via' => 'passflow-demo'));
        } catch (\Exception $e) {
            return 0;
        }

        if (is_wp_error($order) || !$order) {
            return 0;
        }

        $item_id = $order->add_product($product, $qty, array('subtotal' => $unit * $qty, 'total' => $unit * $qty));
        $item = $order->get_item($item_id);

        if ($item) {
            $participants = array();

            for ($i = 0; $i < $qty; $i++) {
                $participants[] = $i === 0
                    ? array('first_name' => $user->first_name, 'last_name' => $user->last_name, 'email' => $user->user_email)
                    : array('first_name' => 'Yuki', 'last_name' => 'Sato', 'email' => 'yuki.demo@' . self::DOMAIN);
            }

            $item->update_meta_data(\Smbb\Passflow\WooCommerce\Orders::META_SLOT, (int) $slot_id);
            $item->update_meta_data(\Smbb\Passflow\WooCommerce\Orders::META_PARTICIPANTS, $participants);
            $item->save();
        }

        $billing = array(
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'email' => $user->user_email,
            'phone' => (string) get_user_meta($user_id, 'billing_phone', true),
            'company' => (string) get_user_meta($user_id, 'billing_company', true),
            'address_1' => (string) get_user_meta($user_id, 'billing_address_1', true),
            'postcode' => (string) get_user_meta($user_id, 'billing_postcode', true),
            'city' => (string) get_user_meta($user_id, 'billing_city', true),
            'country' => 'FR',
        );
        $order->set_address($billing, 'billing');
        $order->set_payment_method_title(__('Démo (aucun paiement réel)', 'smbb-passflow'));

        if ($pro) {
            $order->update_meta_data('_billing_siren', '123456789');
        }

        $order->update_meta_data('_passflow_demo', 1);
        $order->calculate_totals();
        $order->save();

        \Smbb\Passflow\WooCommerce\Orders::reserve($order);
        $order->payment_complete('DEMO-' . $order->get_id());

        return (int) $order->get_id();
    }

    private static function manualBooking($slot_id, $user_id, $status, array $person = array())
    {
        $slot = Slots::find($slot_id);

        if (!$slot) {
            return 0;
        }

        $user = $user_id ? get_userdata($user_id) : null;
        $id = Booking::insertBooking(array(
            'slot_id' => (int) $slot_id,
            'activity_id' => (int) $slot['activity_id'],
            'customer_id' => $user ? (int) $user->ID : null,
            'first_name' => $user ? $user->first_name : $person[0],
            'last_name' => $user ? $user->last_name : $person[1],
            'email' => $user ? $user->user_email : $person[2],
            'phone' => $user ? (string) get_user_meta($user->ID, 'billing_phone', true) : $person[3],
            'source' => 'manual',
            'status' => $status,
            'notes' => __('Réservation de démonstration.', 'smbb-passflow'),
        ));

        if ($id && Passes::isPass($slot)) {
            Passes::createChildren($id);
        }

        Capacity::refresh($slot_id);

        return $id;
    }

    // ------------------------------------------------------------------
    // Suppression
    // ------------------------------------------------------------------

    /**
     * Efface exactement ce qui a été créé (et les factures de démo).
     *
     * @return string résumé
     */
    public static function delete()
    {
        global $wpdb;

        $d = 'smbb-passflow';
        $record = self::record();
        $slots = array_map('intval', $record['slots']);
        $counts = array('orders' => 0, 'users' => 0);

        // Commandes d'abord (leurs réservations, leurs factures).
        foreach ($record['orders'] as $order_id) {
            $order = function_exists('wc_get_order') ? wc_get_order($order_id) : null;

            if ($order && $order->get_meta('_passflow_demo')) {
                $order->delete(true);
                $counts['orders']++;
            }
        }

        // Réservations : celles notées, celles des dates de démo et des commandes de démo.
        $booking_ids = array_map('intval', $record['bookings']);

        if ($slots) {
            $in = implode(',', $slots);
            $booking_ids = array_merge($booking_ids, array_map('intval', (array) $wpdb->get_col('SELECT id FROM ' . Lookup::table('passflow_booking') . ' WHERE slot_id IN (' . $in . ')'))); // phpcs:ignore
        }

        if ($record['orders']) {
            $in = implode(',', array_map('intval', $record['orders']));
            $booking_ids = array_merge($booking_ids, array_map('intval', (array) $wpdb->get_col('SELECT id FROM ' . Lookup::table('passflow_booking') . ' WHERE order_id IN (' . $in . ')'))); // phpcs:ignore
        }

        $booking_ids = array_values(array_unique(array_filter($booking_ids)));

        if ($booking_ids) {
            $in = implode(',', $booking_ids);

            // Jours des pass rattachés à ces réservations.
            $booking_ids = array_merge($booking_ids, array_map('intval', (array) $wpdb->get_col('SELECT id FROM ' . Lookup::table('passflow_booking') . ' WHERE pass_id IN (' . $in . ')'))); // phpcs:ignore
            $in = implode(',', array_unique($booking_ids));

            foreach (array('passflow_history', 'passflow_document', 'passflow_delivery') as $table) {
                $wpdb->query('DELETE FROM ' . Lookup::table($table) . ' WHERE booking_id IN (' . $in . ')'); // phpcs:ignore
            }

            $wpdb->query('UPDATE ' . Lookup::table('passflow_waitlist') . ' SET booking_id = NULL WHERE booking_id IN (' . $in . ')'); // phpcs:ignore
            $wpdb->query('DELETE FROM ' . Lookup::table('passflow_booking') . ' WHERE id IN (' . $in . ')'); // phpcs:ignore
        }

        if ($slots) {
            $in = implode(',', $slots);

            foreach (array('passflow_history', 'passflow_waitlist', 'passflow_delivery') as $table) {
                $wpdb->query('DELETE FROM ' . Lookup::table($table) . ' WHERE slot_id IN (' . $in . ')'); // phpcs:ignore
            }

            $wpdb->query('DELETE FROM ' . Lookup::table('passflow_slot') . ' WHERE id IN (' . $in . ')'); // phpcs:ignore
        }

        foreach (array('activities' => 'passflow_activity', 'catalogs' => 'passflow_catalog', 'hosts' => 'passflow_host') as $key => $table) {
            $ids = array_filter(array_map('intval', $record[$key]));

            if ($ids) {
                $wpdb->query('DELETE FROM ' . Lookup::table($table) . ' WHERE id IN (' . implode(',', $ids) . ')'); // phpcs:ignore
            }
        }

        foreach (array_merge($record['products'], $record['coupons']) as $post_id) {
            if (get_post((int) $post_id)) {
                wp_delete_post((int) $post_id, true);
            }
        }

        if ($record['users']) {
            require_once ABSPATH . 'wp-admin/includes/user.php';

            foreach ($record['users'] as $user_id) {
                $user = get_userdata((int) $user_id);

                // Garde-fou : seulement les comptes de démo en @example.com.
                if ($user && substr($user->user_email, -strlen('@' . self::DOMAIN)) === '@' . self::DOMAIN && wp_delete_user((int) $user_id)) {
                    $counts['users']++;
                }
            }
        }

        $invoices = \Smbb\Passflow\WooCommerce\Invoices::purgeDemo();

        if ($record['test_mode_before'] !== null && !(int) $record['test_mode_before']) {
            Onboarding::saveSettings(array('test_mode' => 0));
        }

        delete_option(self::OPTION);
        self::$exists = false;
        Lookup::flush();

        return sprintf(
            __('Données de démo supprimées : %1$d date(s), %2$d réservation(s), %3$d commande(s), %4$d compte(s), %5$d facture(s) de démo.', $d),
            count($slots),
            count($booking_ids),
            $counts['orders'],
            $counts['users'],
            $invoices
        );
    }
}
