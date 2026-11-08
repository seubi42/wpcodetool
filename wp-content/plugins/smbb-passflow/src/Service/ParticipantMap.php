<?php

namespace Smbb\Passflow\Service;

use Smbb\Passflow\Repository\Lookup;
use Smbb\Passflow\Support\Choices;

defined('ABSPATH') || exit;

/**
 * Carte des participants : d'où viennent les inscrits (France métropolitaine, outre-mer, étranger).
 *
 * Aucun service externe : le code postal de facturation de l'acheteur (commande WooCommerce, sinon
 * le compte client) est placé grâce à une table locale « code postal → position » (data/fr-postcodes.txt),
 * sur un fond SVG des départements (data/fr-departments.php). Le lieu de l'atelier vient de sa
 * latitude/longitude, sinon du code postal de son adresse.
 *
 * Une personne compte une fois par carte (pass sur plusieurs jours, plusieurs dates d'une période).
 */
class ParticipantMap
{
    // Repère commun aux deux fichiers de données (voir l'en-tête de data/fr-departments.php).
    const LON_MIN = -5.3;
    const LAT_MAX = 51.2;
    const LAT_REF = 46.6;
    const WIDTH = 1000;
    const HEIGHT = 970;
    const MAX_ORDERS = 3000;

    /** @var array<string,array{0:float,1:float,2:string}>|null */
    private static $postcodes = null;

    /** @var array|null */
    private static $departments = null;

    /** @var bool */
    private static $assets = false;

    /**
     * Agrège les inscriptions (places occupées) de ces créneaux.
     *
     * @param int[] $slot_ids
     * @return array
     */
    public static function forSlots(array $slot_ids)
    {
        $slot_ids = array_values(array_unique(array_filter(array_map('intval', $slot_ids))));
        $key = 'passflow_map_' . md5(implode(',', $slot_ids) . '|' . self::stamp($slot_ids));
        $cached = $slot_ids ? get_transient($key) : false;

        if (is_array($cached)) {
            return $cached;
        }

        $data = self::aggregate(self::bookings($slot_ids));

        if ($slot_ids) {
            set_transient($key, $data, 10 * MINUTE_IN_SECONDS);
        }

        return $data;
    }

    /**
     * Inscriptions (places) des créneaux, sans les annulées.
     */
    private static function bookings(array $slot_ids)
    {
        global $wpdb;

        if (!$slot_ids) {
            return array();
        }

        $rows = array();
        $statuses = Choices::seatStatuses();

        foreach (array_chunk($slot_ids, 500) as $chunk) {
            $sql = 'SELECT id, slot_id, activity_id, customer_id, order_id, first_name, last_name, email FROM ' . Lookup::table('passflow_booking')
                . ' WHERE deleted_flag = 0 AND slot_id IN (' . implode(',', array_fill(0, count($chunk), '%d')) . ')'
                . ' AND status IN (' . implode(',', array_fill(0, count($statuses), '%s')) . ')';
            $found = $wpdb->get_results($wpdb->prepare($sql, array_merge($chunk, $statuses)), ARRAY_A);
            $rows = array_merge($rows, is_array($found) ? $found : array());
        }

        return $rows;
    }

    /**
     * Change dès qu'une inscription de ces créneaux bouge : sert de clé au cache.
     */
    private static function stamp(array $slot_ids)
    {
        global $wpdb;

        if (!$slot_ids) {
            return '';
        }

        $sql = "SELECT CONCAT(COUNT(*), '-', COALESCE(MAX(lastupdate_date), '')) FROM " . Lookup::table('passflow_booking')
            . ' WHERE slot_id IN (' . implode(',', array_map('intval', array_slice($slot_ids, 0, 2000))) . ')';

        return (string) $wpdb->get_var($sql);
    }

    /**
     * @param array[] $bookings
     * @return array
     */
    public static function aggregate(array $bookings)
    {
        $data = array(
            'people' => 0,
            'located' => 0,
            'unknown' => 0,
            'points' => array(),     // code postal => [x, y, ville, code postal, nombre, département]
            'departments' => array(), // code => nombre
            'overseas' => array(),   // libellé => nombre
            'abroad' => array(),     // pays => nombre
            'venues' => array(),     // [x, y, nom]
            'distances' => array(),  // km, une valeur par personne localisée en métropole
        );

        $addresses = self::addresses($bookings);
        $venues = array();
        $seen = array();

        foreach ($bookings as $b) {
            $person = self::personKey($b);

            if (isset($seen[$person])) {
                continue;
            }

            $seen[$person] = true;
            $data['people']++;
            $aid = (int) $b['activity_id'];

            if (!array_key_exists($aid, $venues)) {
                $venues[$aid] = self::venue($aid);

                if ($venues[$aid]) {
                    $data['venues'][$aid] = $venues[$aid];
                }
            }

            $addr = isset($addresses[(int) $b['id']]) ? $addresses[(int) $b['id']] : null;
            $place = $addr ? self::place($addr['postcode'], $addr['city'], $addr['country']) : null;

            if (!$place) {
                $data['unknown']++;
                continue;
            }

            $data['located']++;

            if ($place['zone'] === 'abroad') {
                $data['abroad'][$place['label']] = (isset($data['abroad'][$place['label']]) ? $data['abroad'][$place['label']] : 0) + 1;
                continue;
            }

            if ($place['zone'] === 'overseas') {
                $data['overseas'][$place['label']] = (isset($data['overseas'][$place['label']]) ? $data['overseas'][$place['label']] : 0) + 1;
                continue;
            }

            // Une commune = un point, même avec plusieurs codes postaux (Clermont 63000 et 63100).
            $k = $place['dep'] . '-' . sanitize_title($place['city']);

            if (!isset($data['points'][$k])) {
                $data['points'][$k] = array($place['x'], $place['y'], $place['city'], $place['postcode'], 0, $place['dep']);
            } else {
                $n = $data['points'][$k][4];
                $data['points'][$k][0] = round(($data['points'][$k][0] * $n + $place['x']) / ($n + 1), 1);
                $data['points'][$k][1] = round(($data['points'][$k][1] * $n + $place['y']) / ($n + 1), 1);

            }

            $data['points'][$k][4]++;
            $data['departments'][$place['dep']] = (isset($data['departments'][$place['dep']]) ? $data['departments'][$place['dep']] : 0) + 1;

            if ($venues[$aid]) {
                $data['distances'][] = self::km($place['x'], $place['y'], $venues[$aid][0], $venues[$aid][1]);
            }
        }

        uasort($data['points'], function ($a, $b) {
            return $b[4] - $a[4];
        });
        arsort($data['departments']);
        arsort($data['overseas']);
        arsort($data['abroad']);
        sort($data['distances']);

        return $data;
    }

    /**
     * Une personne = son e-mail, sinon son nom dans la commande.
     */
    private static function personKey(array $b)
    {
        $email = strtolower(trim((string) $b['email']));

        if ($email !== '') {
            return 'm:' . $email . '|' . strtolower(trim($b['first_name']));
        }

        return 'n:' . (int) $b['order_id'] . '|' . strtolower(trim($b['first_name'] . ' ' . $b['last_name'])) . '|' . ((int) $b['order_id'] ? '' : (int) $b['id']);
    }

    /**
     * Adresse de facturation par inscription : commande WooCommerce, sinon compte client.
     *
     * @return array<int,array{postcode:string,city:string,country:string}>
     */
    private static function addresses(array $bookings)
    {
        $out = array();
        $orders = array();
        $users = array();

        foreach ($bookings as $b) {
            if ((int) $b['order_id'] > 0) {
                $orders[(int) $b['order_id']] = null;
            }

            if ((int) $b['customer_id'] > 0) {
                $users[(int) $b['customer_id']] = null;
            }
        }

        if ($orders && function_exists('wc_get_order')) {
            foreach (array_slice(array_keys($orders), 0, self::MAX_ORDERS) as $order_id) {
                $order = wc_get_order($order_id);

                if ($order && method_exists($order, 'get_billing_postcode')) {
                    $postcode = (string) $order->get_billing_postcode();
                    $country = (string) $order->get_billing_country();

                    // Une vente en caisse sans adresse : on retombe sur le compte client.
                    if ($postcode !== '' || ($country !== '' && $country !== 'FR')) {
                        $orders[$order_id] = array('postcode' => $postcode, 'city' => (string) $order->get_billing_city(), 'country' => $country);
                    }
                }
            }
        }

        if ($users) {
            $users = self::userAddresses(array_keys($users));
        }

        foreach ($bookings as $b) {
            $oid = (int) $b['order_id'];
            $uid = (int) $b['customer_id'];

            if ($oid && !empty($orders[$oid])) {
                $out[(int) $b['id']] = $orders[$oid];
            } elseif ($uid && !empty($users[$uid])) {
                $out[(int) $b['id']] = $users[$uid];
            }
        }

        return $out;
    }

    /**
     * Métas billing_* des comptes, en une requête.
     */
    private static function userAddresses(array $ids)
    {
        global $wpdb;

        $out = array();

        foreach (array_chunk(array_map('intval', $ids), 1000) as $chunk) {
            $rows = $wpdb->get_results(
                "SELECT user_id, meta_key, meta_value FROM {$wpdb->usermeta} WHERE user_id IN (" . implode(',', $chunk) . ")"
                . " AND meta_key IN ('billing_postcode', 'billing_city', 'billing_country')",
                ARRAY_A
            );

            foreach ((array) $rows as $r) {
                $uid = (int) $r['user_id'];
                $out[$uid] = isset($out[$uid]) ? $out[$uid] : array('postcode' => '', 'city' => '', 'country' => '');
                $out[$uid][substr($r['meta_key'], 8)] = (string) $r['meta_value'];
            }
        }

        return array_filter($out, function ($a) {
            return $a['postcode'] !== '' || ($a['country'] !== '' && $a['country'] !== 'FR');
        });
    }

    /**
     * Place une adresse : métropole (point sur la carte), outre-mer ou étranger.
     *
     * @return array|null
     */
    public static function place($postcode, $city = '', $country = '')
    {
        $country = strtoupper(trim((string) $country));
        $city = trim((string) $city);
        $cp = preg_replace('/\D/', '', (string) $postcode);

        if ($country !== '' && $country !== 'FR') {
            $overseas = array('GP' => 'Guadeloupe', 'MQ' => 'Martinique', 'GF' => 'Guyane', 'RE' => 'La Réunion', 'YT' => 'Mayotte', 'PM' => 'Saint-Pierre-et-Miquelon', 'BL' => 'Saint-Barthélemy', 'MF' => 'Saint-Martin', 'NC' => 'Nouvelle-Calédonie', 'PF' => 'Polynésie française', 'WF' => 'Wallis-et-Futuna');

            if (isset($overseas[$country])) {
                return array('zone' => 'overseas', 'label' => $overseas[$country]);
            }

            return array('zone' => 'abroad', 'label' => self::countryName($country));
        }

        if (strlen($cp) === 4) {
            $cp = '0' . $cp; // « 6000 » pour Nice : le zéro tombe souvent.
        }

        if (strlen($cp) !== 5) {
            return null;
        }

        if (in_array(substr($cp, 0, 2), array('97', '98'), true)) {
            $names = array('971' => 'Guadeloupe', '972' => 'Martinique', '973' => 'Guyane', '974' => 'La Réunion', '975' => 'Saint-Pierre-et-Miquelon', '976' => 'Mayotte', '977' => 'Saint-Barthélemy', '978' => 'Saint-Martin', '980' => 'Monaco', '986' => 'Wallis-et-Futuna', '987' => 'Polynésie française', '988' => 'Nouvelle-Calédonie');
            $label = isset($names[substr($cp, 0, 3)]) ? $names[substr($cp, 0, 3)] : __('Outre-mer', 'smbb-passflow');

            return $label === 'Monaco' ? array('zone' => 'abroad', 'label' => 'Monaco') : array('zone' => 'overseas', 'label' => $label);
        }

        $table = self::postcodes();
        $dep = self::departmentOf($cp);

        if (isset($table[$cp])) {
            return array('zone' => 'metro', 'key' => $cp, 'postcode' => $cp, 'x' => $table[$cp][0], 'y' => $table[$cp][1], 'city' => $city !== '' ? self::cityName($city) : $table[$cp][2], 'dep' => $dep);
        }

        // Code postal inconnu (CEDEX, nouveau code) : au centre du département.
        $deps = self::departments();

        if ($dep !== '' && isset($deps[$dep])) {
            return array('zone' => 'metro', 'key' => 'd' . $dep, 'postcode' => $dep, 'x' => $deps[$dep][2], 'y' => $deps[$dep][3], 'city' => $deps[$dep][0], 'dep' => $dep);
        }

        return null;
    }

    /**
     * Département d'un code postal de métropole (Corse : 200-201 → 2A, 202-206 → 2B).
     */
    public static function departmentOf($cp)
    {
        if (strlen($cp) !== 5) {
            return '';
        }

        if (substr($cp, 0, 2) === '20') {
            return in_array(substr($cp, 0, 3), array('200', '201'), true) ? '2A' : '2B';
        }

        return substr($cp, 0, 2);
    }

    /**
     * Lieu d'un atelier sur la carte : latitude/longitude, sinon code postal de l'adresse,
     * sinon la boutique WooCommerce.
     *
     * @return array{0:float,1:float,2:string}|null
     */
    public static function venue($activity_id)
    {
        $a = $activity_id ? Lookup::atelier($activity_id) : null;
        $name = $a ? (string) (!empty($a['location']) ? $a['location'] : $a['name']) : '';

        if ($a) {
            $lat = str_replace(',', '.', trim((string) $a['latitude']));
            $lng = str_replace(',', '.', trim((string) $a['longitude']));

            if (is_numeric($lat) && is_numeric($lng)) {
                list($x, $y) = self::project((float) $lng, (float) $lat);

                if ($x >= 0 && $x <= self::WIDTH && $y >= 0 && $y <= self::HEIGHT) {
                    return array($x, $y, $name);
                }
            }

            if (preg_match('/\b(\d{5})\b/', (string) $a['address'], $m)) {
                $p = self::place($m[1]);

                if ($p && $p['zone'] === 'metro') {
                    return array($p['x'], $p['y'], $name);
                }
            }
        }

        $p = self::place((string) get_option('woocommerce_store_postcode', ''));

        return $p && $p['zone'] === 'metro' ? array($p['x'], $p['y'], $name !== '' ? $name : (string) get_option('woocommerce_store_city', '')) : null;
    }

    /**
     * Longitude/latitude → repère de la carte.
     */
    public static function project($lng, $lat)
    {
        $k = cos(deg2rad(self::LAT_REF));
        $scale = self::WIDTH / ((9.7 - self::LON_MIN) * $k);

        return array(round(($lng - self::LON_MIN) * $k * $scale, 1), round((self::LAT_MAX - $lat) * $scale, 1));
    }

    /**
     * Distance à vol d'oiseau entre deux points de la carte, en km.
     */
    public static function km($x1, $y1, $x2, $y2)
    {
        $k = cos(deg2rad(self::LAT_REF));
        $scale = self::WIDTH / ((9.7 - self::LON_MIN) * $k);
        $lat1 = deg2rad(self::LAT_MAX - $y1 / $scale);
        $lat2 = deg2rad(self::LAT_MAX - $y2 / $scale);
        $dlng = deg2rad(($x2 - $x1) / ($k * $scale));
        $a = pow(sin(($lat2 - $lat1) / 2), 2) + cos($lat1) * cos($lat2) * pow(sin($dlng / 2), 2);

        return 6371 * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    /**
     * @return array<string,array{0:float,1:float,2:string}>
     */
    public static function postcodes()
    {
        if (self::$postcodes !== null) {
            return self::$postcodes;
        }

        self::$postcodes = array();
        $file = __DIR__ . '/data/fr-postcodes.txt';
        $lines = is_readable($file) ? file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : array();

        foreach ((array) $lines as $line) {
            if ($line[0] === '#') {
                continue;
            }

            $p = explode(';', $line, 4);

            if (count($p) === 4) {
                self::$postcodes[$p[0]] = array((float) $p[1], (float) $p[2], $p[3]);
            }
        }

        return self::$postcodes;
    }

    /**
     * @return array<string,array{0:string,1:string,2:float,3:float}> code => [nom, tracé SVG, x, y]
     */
    public static function departments()
    {
        if (self::$departments === null) {
            $file = __DIR__ . '/data/fr-departments.php';
            self::$departments = is_readable($file) ? (array) include $file : array();
        }

        return self::$departments;
    }

    private static function cityName($city)
    {
        $city = preg_replace('/\s+cedex(\s*\d+)?$/i', '', trim($city));

        // « LYON » ou « saint-etienne » : on remet des capitales propres.
        if ($city === strtoupper($city) || $city === strtolower($city)) {
            $city = mb_convert_case(mb_strtolower($city), MB_CASE_TITLE, 'UTF-8');
        }

        return $city;
    }

    private static function countryName($code)
    {
        if (function_exists('WC') && WC()->countries) {
            $list = WC()->countries->get_countries();

            if (isset($list[$code])) {
                return html_entity_decode($list[$code], ENT_QUOTES, 'UTF-8');
            }
        }

        return $code;
    }

    // ------------------------------------------------------------------------------------------
    // Affichage
    // ------------------------------------------------------------------------------------------

    /**
     * Carte + palmarès, prêt à poser dans une carte Passflow.
     *
     * @param array $data résultat de forSlots()
     * @param array $opts compact (bool) : moins de lignes dans les listes
     */
    public static function render(array $data, array $opts = array())
    {
        $d = 'smbb-passflow';
        self::assets();

        if ($data['people'] === 0) {
            return '<p class="passflow-muted">' . esc_html__('Aucun inscrit pour le moment.', $d) . '</p>';
        }

        $compact = !empty($opts['compact']);
        $points = $data['points'];
        $metro = array_sum(array_column($points, 4));
        $max = $points ? max(array_column($points, 4)) : 1;
        $dmax = $data['departments'] ? max($data['departments']) : 1;
        $deps = self::departments();
        $uid = 'pfmap-' . wp_unique_id();

        // Fond : départements, teintés selon le nombre de participants.
        $focus = self::focusBox($points, $data['venues']);
        $svg = '<svg class="pfmap-svg" viewBox="0 0 ' . self::WIDTH . ' ' . self::HEIGHT . '"' . ($focus ? ' data-focus="' . esc_attr(implode(' ', $focus)) . '"' : '') . ' role="img" aria-labelledby="' . esc_attr($uid) . '-t">'
            . '<title id="' . esc_attr($uid) . '-t">' . esc_html(sprintf(_n('Carte de France : %d participant localisé', 'Carte de France : %d participants localisés', $metro, $d), $metro)) . '</title>'
            . '<g class="pfmap-deps">';

        foreach ($deps as $code => $dep) {
            $n = isset($data['departments'][$code]) ? (int) $data['departments'][$code] : 0;
            $level = $n ? max(1, (int) ceil(sqrt($n / $dmax) * 5)) : 0;
            $svg .= '<path class="pfmap-dep' . ($n ? ' has-people l' . $level : '') . '" d="' . esc_attr($dep[1]) . '" data-dep="' . esc_attr($code) . '">'
                . '<title>' . esc_html($dep[0] . ' (' . $code . ')' . ($n ? ' : ' . sprintf(_n('%d participant', '%d participants', $n, $d), $n) : '')) . '</title></path>';
        }

        $svg .= '</g>';

        // Lignes vers le lieu de l'atelier (un seul lieu : sinon la carte devient illisible).
        $venue = count($data['venues']) === 1 ? reset($data['venues']) : null;

        if ($venue) {
            $svg .= '<g class="pfmap-links" aria-hidden="true">';

            foreach (array_slice($points, 0, 40, true) as $p) {
                if (self::km($p[0], $p[1], $venue[0], $venue[1]) < 8) {
                    continue;
                }

                $mx = ($p[0] + $venue[0]) / 2;
                $my = ($p[1] + $venue[1]) / 2 - abs($p[0] - $venue[0]) * 0.18 - 6;
                $svg .= '<path d="M' . $p[0] . ',' . $p[1] . 'Q' . round($mx, 1) . ',' . round($my, 1) . ' ' . $venue[0] . ',' . $venue[1] . '"/>';
            }

            $svg .= '</g>';
        }

        // Points : un cercle par commune, aire proportionnelle au nombre de participants.
        $svg .= '<g class="pfmap-dots">';
        $i = 0;
        $ordered = array_reverse($points, true); // les gros dessous, les petits par-dessus.
        $total = count($ordered);

        foreach ($ordered as $key => $p) {
            $r = round(5 + 17 * sqrt($p[4] / $max), 1);
            $label = $p[2] . (strlen($p[3]) === 2 ? '' : ' (' . $p[3] . ')');
            $delay = min(1.6, ($total - $i) * 0.035);
            // Chaque repère est dessiné autour de (0,0) puis déplacé : au zoom, il garde sa taille à l'écran.
            $svg .= '<g class="pfmap-dot" data-key="' . esc_attr($key) . '" transform="translate(' . $p[0] . ' ' . $p[1] . ')" style="--d:' . esc_attr((string) round($delay, 2)) . 's" tabindex="0">'
                . '<title>' . esc_html($label . ' : ' . sprintf(_n('%d participant', '%d participants', $p[4], $d), $p[4])) . '</title>'
                . '<g class="pfmap-pin"><circle class="pfmap-halo" r="' . $r . '"/>'
                . '<circle class="pfmap-core" r="' . $r . '"/>'
                . ($p[4] > 1 && $r >= 11 ? '<text y="4.5">' . (int) $p[4] . '</text>' : '')
                . '</g></g>';
            $i++;
        }

        $svg .= '</g>';

        // Lieux des ateliers.
        foreach ($data['venues'] as $v) {
            $svg .= '<g class="pfmap-venue" transform="translate(' . $v[0] . ' ' . $v[1] . ')"><title>' . esc_html(sprintf(__("Lieu de l'atelier : %s", $d), $v[2])) . '</title>'
                . '<g class="pfmap-pin"><circle class="pfmap-venue-pulse" r="12"/>'
                . '<path d="M0,-15 L4.4,-6 L14.3,-4.6 L7.1,2.3 L8.8,12.1 L0,7.5 L-8.8,12.1 L-7.1,2.3 L-14.3,-4.6 L-4.4,-6 Z"/></g></g>';
        }

        // Étiquettes des villes principales, sans chevauchement.
        $placed = array();

        foreach (array_slice($points, 0, 8, true) as $p) {
            $r = 5 + 17 * sqrt($p[4] / $max);
            $ly = $p[1] - $r - 6;
            $free = true;

            foreach ($placed as $q) {
                if (abs($q[0] - $p[0]) < 90 && abs($q[1] - $ly) < 22) {
                    $free = false;
                    break;
                }
            }

            if (!$free) {
                continue;
            }

            $placed[] = array($p[0], $ly);
            $svg .= '<g class="pfmap-label" transform="translate(' . $p[0] . ' ' . $p[1] . ')"><text class="pfmap-pin" y="' . round(-$r - 6, 1) . '">' . esc_html($p[2]) . '</text></g>';

            if (count($placed) >= 4) {
                break;
            }
        }

        $svg .= '</svg>';

        // Encadré outre-mer / étranger, sur la carte.
        $far = '';

        foreach (array('overseas' => '🌴', 'abroad' => '✈') as $zone => $icon) {
            foreach ($data[$zone] as $label => $n) {
                $far .= '<li><span aria-hidden="true">' . $icon . '</span> ' . esc_html($label) . ' <strong>' . (int) $n . '</strong></li>';
            }
        }

        $map = '<div class="pfmap-canvas">' . $svg
            . ($focus ? '<button type="button" class="pfmap-zoom" data-zoom-in="' . esc_attr__('Zoomer sur les participants', $d) . '" data-zoom-out="' . esc_attr__('Toute la France', $d) . '"><span class="dashicons dashicons-search" aria-hidden="true"></span> <span>' . esc_html__('Zoomer sur les participants', $d) . '</span></button>' : '')
            . ($far !== '' ? '<ul class="pfmap-far">' . $far . '</ul>' : '')
            . '</div>';

        // Chiffres clés.
        $median = null;

        if ($data['distances']) {
            $n = count($data['distances']);
            $median = $n % 2 ? $data['distances'][($n - 1) / 2] : ($data['distances'][$n / 2 - 1] + $data['distances'][$n / 2]) / 2;
        }

        $near = $data['distances'] ? count(array_filter($data['distances'], function ($km) {
            return $km <= 30;
        })) : 0;
        $stats = '<div class="pfmap-stats">'
            . self::stat(number_format_i18n($data['located']), _n('personne localisée', 'personnes localisées', $data['located'], $d), $data['unknown'] ? sprintf(_n('%d sans adresse', '%d sans adresse', $data['unknown'], $d), $data['unknown']) : '')
            . self::stat(number_format_i18n(count($points)), _n('commune', 'communes', count($points), $d), sprintf(_n('%d département', '%d départements', count($data['departments']), $d), count($data['departments'])))
            . ($median !== null ? self::stat(esc_html(self::kmLabel($median)), __('distance médiane', $d), sprintf(__('%d %% à moins de 30 km', $d), round($near * 100 / max(1, count($data['distances']))))) : '')
            . '</div>';

        // Palmarès des villes.
        $rows = '';
        $limit = $compact ? 6 : 10;

        foreach (array_slice($points, 0, $limit, true) as $key => $p) {
            $km = $venue ? self::km($p[0], $p[1], $venue[0], $venue[1]) : null;
            $rows .= '<li data-key="' . esc_attr($key) . '" tabindex="0">'
                . '<span class="pfmap-city">' . esc_html($p[2]) . ' <small>' . esc_html(strlen($p[3]) === 2 ? __('département', $d) . ' ' . $p[3] : $p[3]) . ($km !== null && $km >= 1 ? ' · ' . esc_html(self::kmLabel($km)) : '') . '</small></span>'
                . '<span class="passflow-share"><span style="width:' . esc_attr((string) max(4, round($p[4] / $max * 100))) . '%"></span></span>'
                . '<strong>' . (int) $p[4] . '</strong></li>';
        }

        if (count($points) > $limit) {
            $rest = array_sum(array_column(array_slice($points, $limit), 4));
            $rows .= '<li class="pfmap-rest"><span class="pfmap-city">' . esc_html(sprintf(_n('%d autre commune', '%d autres communes', count($points) - $limit, $d), count($points) - $limit)) . '</span><span></span><strong>' . (int) $rest . '</strong></li>';
        }

        $side = $stats
            . ($rows !== '' ? '<h3>' . esc_html__('Communes', $d) . '</h3><ol class="pfmap-cities">' . $rows . '</ol>' : '');

        if (!$compact && count($data['departments']) > 1) {
            $list = '';

            foreach (array_slice($data['departments'], 0, 5, true) as $code => $n) {
                $list .= '<li><span>' . esc_html((isset($deps[$code]) ? $deps[$code][0] : $code) . ' (' . $code . ')') . '</span><strong>' . (int) $n . '</strong></li>';
            }

            $side .= '<h3>' . esc_html__('Départements', $d) . '</h3><ul class="pfmap-deplist">' . $list . '</ul>';
        }

        $note = '<p class="passflow-muted pfmap-note">' . esc_html__("Selon le code postal de facturation de l'acheteur (commande, sinon compte client). Une personne compte une fois. ★ = lieu de l'atelier.", $d) . '</p>';

        return '<div class="pfmap' . ($compact ? ' is-compact' : '') . '" id="' . esc_attr($uid) . '">'
            . $map . '<div class="pfmap-side">' . $side . '</div></div>' . $note;
    }

    /**
     * Cadre qui contient 80 % des participants de métropole (et le lieu), pour le zoom.
     * Rien quand la France entière est déjà le bon cadre.
     *
     * @return float[]|null x, y, largeur, hauteur
     */
    private static function focusBox(array $points, array $venues)
    {
        $total = array_sum(array_column($points, 4));

        if ($total < 2) {
            return null;
        }

        $xs = array();
        $ys = array();
        $sum = 0;

        foreach ($points as $p) {
            $xs[] = $p[0];
            $ys[] = $p[1];
            $sum += $p[4];

            if ($sum >= $total * 0.8) {
                break;
            }
        }

        foreach ($venues as $v) {
            $xs[] = $v[0];
            $ys[] = $v[1];
        }

        $w = max(260, max($xs) - min($xs) + 120);
        $h = max(260 * self::HEIGHT / self::WIDTH, max($ys) - min($ys) + 120);

        // Même proportions que la carte.
        if ($w / $h > self::WIDTH / self::HEIGHT) {
            $h = $w * self::HEIGHT / self::WIDTH;
        } else {
            $w = $h * self::WIDTH / self::HEIGHT;
        }

        if ($w > self::WIDTH * 0.6) {
            return null;
        }

        $cx = (max($xs) + min($xs)) / 2;
        $cy = (max($ys) + min($ys)) / 2;
        $x = min(max(0, $cx - $w / 2), self::WIDTH - $w);
        $y = min(max(0, $cy - $h / 2), self::HEIGHT - $h);

        return array(round($x, 1), round($y, 1), round($w, 1), round($h, 1));
    }

    private static function stat($value, $label, $hint = '')
    {
        return '<div class="pfmap-stat"><strong>' . $value . '</strong><span>' . esc_html($label) . '</span>'
            . ($hint !== '' ? '<small>' . esc_html($hint) . '</small>' : '') . '</div>';
    }

    private static function kmLabel($km)
    {
        return $km < 1 ? __('sur place', 'smbb-passflow') : sprintf(__('%s km', 'smbb-passflow'), number_format_i18n($km, $km < 10 ? 1 : 0));
    }

    private static function assets()
    {
        if (self::$assets) {
            return;
        }

        self::$assets = true;
        $css = SMBB_PASSFLOW_PATH . 'assets/participant-map.css';
        $js = SMBB_PASSFLOW_PATH . 'assets/participant-map.js';
        wp_enqueue_style('smbb-passflow-map', SMBB_PASSFLOW_URL . 'assets/participant-map.css', array(), SMBB_PASSFLOW_VERSION . '.' . (int) @filemtime($css));
        wp_enqueue_script('smbb-passflow-map', SMBB_PASSFLOW_URL . 'assets/participant-map.js', array(), SMBB_PASSFLOW_VERSION . '.' . (int) @filemtime($js), true);
    }
}
