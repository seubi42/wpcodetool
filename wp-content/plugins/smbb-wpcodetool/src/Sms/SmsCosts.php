<?php

namespace Smbb\WpCodeTool\Sms;

defined('ABSPATH') || exit;

/**
 * Registre des coûts SMS, gardé à vie (jamais purgé, contrairement au journal SmsLog).
 *
 * Une ligne par SMS accepté par le fournisseur : date, connecteur, identifiant chez le fournisseur,
 * origine (plugin), statut de remise, code d'erreur, segments, prix et devise. Volontairement sans
 * numéro ni texte : rien de personnel n'y reste une fois le journal purgé.
 *
 * Table {prefix}smbb_sms_costs, remplie par Sms::send, complétée par SmsTracking (webhook + relevé).
 */
final class SmsCosts
{
    const DB_VERSION = '1';
    const DB_OPTION = 'smbb_codetool_sms_costs_db';

    /** Statuts définitifs (plus rien ne bougera, à part le prix). */
    const FINAL_STATUSES = array('delivered', 'undelivered', 'failed', 'canceled', 'read');

    public static function hooks()
    {
        add_action('admin_init', array(__CLASS__, 'install'));
    }

    public static function table()
    {
        global $wpdb;

        return $wpdb->prefix . 'smbb_sms_costs';
    }

    public static function ready()
    {
        return get_option(self::DB_OPTION) === self::DB_VERSION;
    }

    public static function install()
    {
        if (self::ready()) {
            return;
        }

        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $table = self::table();
        $charset = $wpdb->get_charset_collate();

        dbDelta("CREATE TABLE {$table} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  created_at datetime NOT NULL,
  provider varchar(40) NOT NULL DEFAULT '',
  provider_id varchar(80) NOT NULL DEFAULT '',
  source varchar(20) NOT NULL DEFAULT '',
  status varchar(20) NOT NULL DEFAULT 'accepted',
  error_code varchar(10) NOT NULL DEFAULT '',
  segments smallint(5) unsigned NOT NULL DEFAULT 0,
  price decimal(12,5) DEFAULT NULL,
  currency varchar(3) NOT NULL DEFAULT '',
  status_at datetime DEFAULT NULL,
  priced_at datetime DEFAULT NULL,
  checks tinyint(3) unsigned NOT NULL DEFAULT 0,
  log_id bigint(20) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  UNIQUE KEY provider_message (provider,provider_id),
  KEY created_at (created_at),
  KEY pending (priced_at,checks)
) {$charset};");

        update_option(self::DB_OPTION, self::DB_VERSION, true);
    }

    /**
     * Rang d'un statut : un webhook arrivé en retard ne doit pas faire reculer « remis » en « envoyé ».
     */
    public static function rank($status)
    {
        if (in_array($status, self::FINAL_STATUSES, true)) {
            return 6;
        }

        $ranks = array('accepted' => 1, 'scheduled' => 1, 'queued' => 2, 'sending' => 3, 'sent' => 4, 'delivery_unknown' => 5);

        return isset($ranks[$status]) ? $ranks[$status] : 0;
    }

    /**
     * Note un SMS accepté (appelé par Sms::send).
     */
    public static function record($provider, $provider_id, $source, $status, $segments, $log_id)
    {
        if (!self::ready() || (string) $provider_id === '') {
            return 0;
        }

        global $wpdb;
        $wpdb->insert(self::table(), array(
            'created_at' => current_time('mysql', true),
            'provider' => substr((string) $provider, 0, 40),
            'provider_id' => substr((string) $provider_id, 0, 80),
            'source' => substr((string) $source, 0, 20),
            'status' => substr(sanitize_key((string) $status) ?: 'accepted', 0, 20),
            'segments' => max(0, (int) $segments),
            'log_id' => (int) $log_id,
        ));

        return (int) $wpdb->insert_id;
    }

    public static function find($provider, $provider_id)
    {
        if (!self::ready()) {
            return null;
        }

        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table() . ' WHERE provider = %s AND provider_id = %s', $provider, $provider_id), ARRAY_A); // phpcs:ignore

        return is_array($row) ? $row : null;
    }

    /**
     * Met à jour un SMS avec ce que dit le fournisseur (webhook ou relevé).
     *
     * @param array $data status, error_code, price, currency, segments (tous facultatifs)
     * @param bool  $checked Relevé par l'API (compte la tentative).
     * @return array|null La ligne à jour.
     */
    public static function apply($provider, $provider_id, array $data, $checked = false)
    {
        $row = self::find($provider, $provider_id);

        if (!$row) {
            return null;
        }

        $now = current_time('mysql', true);
        $set = array();

        if (!empty($data['status']) && self::rank($data['status']) >= self::rank($row['status']) && $data['status'] !== $row['status']) {
            $set['status'] = substr((string) $data['status'], 0, 20);
            $set['status_at'] = $now;
        }

        if (isset($data['error_code']) && (string) $data['error_code'] !== '' && (string) $data['error_code'] !== '0') {
            $set['error_code'] = substr((string) $data['error_code'], 0, 10);
        }

        if (isset($data['segments']) && (int) $data['segments'] > 0) {
            $set['segments'] = (int) $data['segments'];
        }

        if (array_key_exists('price', $data) && $data['price'] !== null) {
            $set['price'] = round((float) $data['price'], 5);
            $set['priced_at'] = $now;

            if (!empty($data['currency'])) {
                $set['currency'] = (string) $data['currency'];
            }
        }

        if ($checked) {
            $set['checks'] = min(255, (int) $row['checks'] + 1);
        }

        if ($set) {
            global $wpdb;
            $wpdb->update(self::table(), $set, array('id' => (int) $row['id']));
            $row = array_merge($row, $set);
        }

        return $row;
    }

    /**
     * SMS dont le prix n'est pas encore connu, à relever chez le fournisseur.
     */
    public static function pending($limit = 50, $max_checks = 8)
    {
        if (!self::ready()) {
            return array();
        }

        global $wpdb;
        $recent = gmdate('Y-m-d H:i:s', time() - 5 * MINUTE_IN_SECONDS);
        $oldest = gmdate('Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS);

        return (array) $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM ' . self::table() . ' WHERE priced_at IS NULL AND checks < %d AND created_at <= %s AND created_at >= %s ORDER BY checks ASC, id ASC LIMIT %d', // phpcs:ignore
            $max_checks,
            $recent,
            $oldest,
            $limit
        ), ARRAY_A);
    }

    /**
     * Coûts par mois (heure du site), du plus ancien au plus récent, mois vides compris.
     *
     * @param int $months 0 = depuis le premier SMS.
     * @return array{months:array,currency:string,others:array,first:string}
     */
    public static function monthly($months = 12)
    {
        $out = array('months' => array(), 'currency' => '', 'others' => array(), 'first' => '');

        if (!self::ready()) {
            return $out;
        }

        global $wpdb;
        $table = self::table();
        $offset = (int) round((float) get_option('gmt_offset') * HOUR_IN_SECONDS);
        $first = $wpdb->get_var("SELECT MIN(created_at) FROM {$table}"); // phpcs:ignore

        if (!$first) {
            return $out;
        }

        $out['first'] = (string) $first;
        $now = time() + $offset;
        $start = $months > 0
            ? gmmktime(0, 0, 0, (int) gmdate('n', $now) - $months + 1, 1, (int) gmdate('Y', $now))
            : gmmktime(0, 0, 0, (int) gmdate('n', strtotime($first . ' UTC') + $offset), 1, (int) gmdate('Y', strtotime($first . ' UTC') + $offset));

        $rows = (array) $wpdb->get_results($wpdb->prepare(
            "SELECT DATE_FORMAT(DATE_ADD(created_at, INTERVAL %d SECOND), '%%Y-%%m') AS ym, currency,
                COUNT(*) AS n, SUM(segments) AS segments, SUM(price) AS cost, SUM(price IS NULL) AS unpriced,
                SUM(status = 'delivered' OR status = 'read') AS delivered,
                SUM(status IN ('undelivered','failed','canceled')) AS lost
             FROM {$table} WHERE created_at >= %s GROUP BY ym, currency", // phpcs:ignore
            $offset,
            gmdate('Y-m-d H:i:s', $start - $offset)
        ), ARRAY_A);

        // Devise principale : celle qui a le plus de SMS chiffrés (une seule en pratique).
        $by_currency = array();

        foreach ($rows as $row) {
            if ($row['currency'] !== '') {
                $by_currency[$row['currency']] = (isset($by_currency[$row['currency']]) ? $by_currency[$row['currency']] : 0) + (int) $row['n'];
            }
        }

        arsort($by_currency);
        $main = $by_currency ? (string) key($by_currency) : '';
        $out['currency'] = $main;

        for ($t = $start; $t <= $now; $t = gmmktime(0, 0, 0, (int) gmdate('n', $t) + 1, 1, (int) gmdate('Y', $t))) {
            $out['months'][gmdate('Y-m', $t)] = array('n' => 0, 'segments' => 0, 'cost' => 0.0, 'unpriced' => 0, 'delivered' => 0, 'lost' => 0);
        }

        foreach ($rows as $row) {
            $ym = $row['ym'];

            if (!isset($out['months'][$ym])) {
                continue;
            }

            $m = &$out['months'][$ym];
            $m['n'] += (int) $row['n'];
            $m['segments'] += (int) $row['segments'];
            $m['delivered'] += (int) $row['delivered'];
            $m['lost'] += (int) $row['lost'];
            $m['unpriced'] += (int) $row['unpriced'];

            if ($row['currency'] === $main || $row['currency'] === '') {
                $m['cost'] += (float) $row['cost'];
            } else {
                $out['others'][$row['currency']] = (isset($out['others'][$row['currency']]) ? $out['others'][$row['currency']] : 0) + (float) $row['cost'];
            }

            unset($m);
        }

        return $out;
    }

    /**
     * Répartition par origine (plugin) depuis une date UTC.
     */
    public static function bySource($since)
    {
        if (!self::ready()) {
            return array();
        }

        global $wpdb;

        return (array) $wpdb->get_results($wpdb->prepare(
            'SELECT source, COUNT(*) AS n, SUM(segments) AS segments, SUM(price) AS cost FROM ' . self::table() . ' WHERE created_at >= %s GROUP BY source ORDER BY cost DESC, n DESC', // phpcs:ignore
            $since
        ), ARRAY_A);
    }

    /**
     * Toutes les lignes, pour l'export CSV (par paquets pour ne pas tout charger d'un coup).
     */
    public static function each(callable $callback, $batch = 1000)
    {
        if (!self::ready()) {
            return;
        }

        global $wpdb;
        $last = 0;

        do {
            $rows = (array) $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . self::table() . ' WHERE id > %d ORDER BY id ASC LIMIT %d', $last, $batch), ARRAY_A); // phpcs:ignore

            foreach ($rows as $row) {
                $callback($row);
                $last = (int) $row['id'];
            }
        } while (count($rows) === $batch);
    }

    /**
     * Montant lisible : 3,35 € / 3,35 $ / 0,0795 €.
     */
    public static function money($amount, $currency, $decimals = 2)
    {
        $symbols = array('EUR' => '€', 'USD' => '$', 'GBP' => '£', 'CHF' => 'CHF', 'CAD' => '$ CA');
        $symbol = isset($symbols[$currency]) ? $symbols[$currency] : (string) $currency;

        return trim(number_format_i18n((float) $amount, $decimals) . ' ' . $symbol);
    }
}
