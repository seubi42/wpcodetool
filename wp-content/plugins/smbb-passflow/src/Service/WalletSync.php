<?php

namespace Smbb\Passflow\Service;

use Smbb\Passflow\Repository\Bookings;
use Smbb\Passflow\Repository\Lookup;
use Smbb\Passflow\Support\Settings;

defined('ABSPATH') || exit;

/**
 * Suivi de l'état des billets dans les Wallet (présent, absent, annulé, déplacé), activable séparément
 * pour Google et Apple dans Réglages › Billets.
 *
 * - Google : le billet est mis à jour par l'API Google Wallet ; le téléphone le reçoit seul.
 * - Apple : service web PassKit (l'iPhone s'inscrit à l'ajout du billet, table passflow_wallet), puis une
 *   notification APNs à chaque changement ; l'iPhone retélécharge alors le billet à jour.
 *
 * Déclencheur : chaque mouvement de l'historique d'une inscription (pointage, annulation, déplacement…).
 * Les envois partent après la réponse, sans ralentir le pointage.
 */
final class WalletSync
{
    const ROUTE = 'smbb-passflow/apple';
    const LAST = 'smbb_passflow_wallet_last';

    /** @var array<int,bool> */
    private static $queue = array();

    /** @var array<int,string> messages de la veille à envoyer (Google) */
    private static $eve = array();

    /** @var array<int,array<string,mixed>> anciens billets à barrer (transfert) */
    private static $void = array();

    public static function hooks()
    {
        add_action('smbb_passflow_history_recorded', array(__CLASS__, 'queue'), 20, 1);
        add_action('shutdown', array(__CLASS__, 'flush'), 30);
        add_action('rest_api_init', array(__CLASS__, 'routes'));
    }

    public static function queue($booking_id)
    {
        if (Tickets::syncOn('google') || Tickets::syncOn('apple')) {
            self::$queue[(int) $booking_id] = true;
        }
    }

    /**
     * Rappel de la veille (appelé par le rappel participant) : une seule fois par billet et par jour d'atelier.
     */
    public static function eve(array $booking, array $slot)
    {
        if (!Tickets::syncOn('google') && !Tickets::syncOn('apple')) {
            return;
        }

        $root = Passes::root($booking);
        $key = 'smbb_passflow_wallet_eve_' . (int) $root['id'] . '_' . (int) $slot['id'];

        if (get_transient($key)) {
            return;
        }

        set_transient($key, 1, 3 * DAY_IN_SECONDS);
        $text = Tickets::eveText($root);

        if ($text !== '') {
            self::$queue[(int) $root['id']] = true;
            self::$eve[(int) $root['id']] = $text;
        }
    }

    /**
     * Transfert : l'ancien billet (nom de l'ancien participant, ancienne version) est barré dans les Wallet
     * où il a été ajouté. Le nouveau billet a un autre numéro de série : il ne touche pas l'ancien téléphone.
     *
     * @param array<string,mixed> $old Transfers::previous()
     */
    public static function voidPrevious(array $old)
    {
        if (Tickets::syncOn('google') || Tickets::syncOn('apple')) {
            self::$void[] = $old;
        }
    }

    public static function flush()
    {
        if (!self::$queue && !self::$void) {
            return;
        }

        $ids = array_keys(self::$queue);
        $eve = self::$eve;
        $void = self::$void;
        self::$queue = array();
        self::$eve = array();
        self::$void = array();
        $done = array();

        if (function_exists('fastcgi_finish_request') && !wp_doing_cron()) {
            fastcgi_finish_request();
        }

        foreach ($void as $old) {
            if (Tickets::syncOn('google')) {
                $result = Tickets::googleSync($old);
                self::remember('google', $result === true ? '' : (string) $result);
            }

            if (Tickets::syncOn('apple')) {
                self::applePush(Tickets::serial($old));
            }
        }

        foreach ($ids as $id) {
            $booking = Bookings::find($id);

            if (!$booking) {
                continue;
            }

            // Jour d'un pass : c'est le billet de la mère qui change (liste des jours).
            $booking = Passes::root($booking);
            $id = (int) $booking['id'];

            if (isset($done[$id])) {
                continue;
            }

            $done[$id] = true;

            if (Tickets::syncOn('google')) {
                $result = Tickets::googleSync($booking);

                if ($result === true && isset($eve[$id])) {
                    $result = Tickets::googleNotify($booking, $eve[$id]);
                }

                self::remember('google', $result === true ? '' : (string) $result);
            }

            if (Tickets::syncOn('apple')) {
                self::applePush(Tickets::serial($booking));
            }
        }
    }

    /**
     * Dernier résultat d'envoi par Wallet, affiché dans les réglages.
     */
    private static function remember($wallet, $error)
    {
        $last = get_option(self::LAST);
        $last = is_array($last) ? $last : array();
        $last[$wallet] = array('time' => current_time('mysql'), 'error' => (string) $error);
        update_option(self::LAST, $last, false);
    }

    /**
     * Résumé pour Réglages › Billets.
     */
    public static function status($wallet)
    {
        $d = 'smbb-passflow';
        $last = get_option(self::LAST);
        $parts = array();

        if ($wallet === 'apple' && \Smbb\Passflow\Support\Schema::ready()) {
            global $wpdb;
            $count = (int) $wpdb->get_var('SELECT COUNT(DISTINCT device_id) FROM ' . Lookup::table('passflow_wallet')); // phpcs:ignore
            $parts[] = sprintf(_n('%d iPhone inscrit', '%d iPhones inscrits', $count, $d), $count);
        }

        if (is_array($last) && isset($last[$wallet])) {
            $parts[] = $last[$wallet]['error'] === ''
                ? sprintf(__('dernière mise à jour envoyée le %s', $d), mysql2date('d/m/Y H:i', $last[$wallet]['time']))
                : sprintf(__('dernier envoi en erreur le %1$s : %2$s', $d), mysql2date('d/m/Y H:i', $last[$wallet]['time']), $last[$wallet]['error']);
        }

        return implode(' · ', $parts);
    }

    // ------------------------------------------------------------------
    // Apple : service web PassKit
    // ------------------------------------------------------------------

    public static function appleToken($serial)
    {
        return substr(hash_hmac('sha256', 'passflow-wallet|' . $serial, wp_salt('auth')), 0, 40);
    }

    public static function routes()
    {
        $device = '(?P<device>[A-Za-z0-9._-]+)';
        $type = '(?P<type>[A-Za-z0-9._-]+)';
        $serial = '(?P<serial>[A-Za-z0-9._-]+)';

        register_rest_route(self::ROUTE, '/v1/devices/' . $device . '/registrations/' . $type . '/' . $serial, array(
            array('methods' => 'POST', 'callback' => array(__CLASS__, 'register'), 'permission_callback' => '__return_true'),
            array('methods' => 'DELETE', 'callback' => array(__CLASS__, 'unregister'), 'permission_callback' => '__return_true'),
        ));
        register_rest_route(self::ROUTE, '/v1/devices/' . $device . '/registrations/' . $type, array(
            'methods' => 'GET', 'callback' => array(__CLASS__, 'updated'), 'permission_callback' => '__return_true',
        ));
        register_rest_route(self::ROUTE, '/v1/passes/' . $type . '/' . $serial, array(
            'methods' => 'GET', 'callback' => array(__CLASS__, 'pass'), 'permission_callback' => '__return_true',
        ));
        register_rest_route(self::ROUTE, '/v1/log', array(
            'methods' => 'POST', 'callback' => array(__CLASS__, 'log'), 'permission_callback' => '__return_true',
        ));
    }

    /**
     * En-tête « Authorization: ApplePass <jeton> » (certains serveurs le déplacent dans REDIRECT_HTTP_AUTHORIZATION).
     *
     * @param \WP_REST_Request $request
     */
    private static function authorized($request, $serial)
    {
        $header = (string) $request->get_header('authorization');

        if ($header === '') {
            foreach (array('HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION') as $key) {
                if (!empty($_SERVER[$key])) {
                    $header = (string) wp_unslash($_SERVER[$key]); // phpcs:ignore
                    break;
                }
            }
        }

        return Tickets::syncOn('apple') && preg_match('/^ApplePass\s+(\S+)$/', trim($header), $m) && hash_equals(self::appleToken($serial), $m[1]);
    }

    private static function validType($type)
    {
        return $type === (string) Settings::get('apple_pass_type_id');
    }

    /**
     * @param \WP_REST_Request $request
     */
    public static function register($request)
    {
        global $wpdb;
        $serial = (string) $request['serial'];

        if (!self::validType((string) $request['type']) || !self::authorized($request, $serial)) {
            return new \WP_REST_Response(null, 401);
        }

        $body = json_decode((string) $request->get_body(), true);
        $push = is_array($body) && isset($body['pushToken']) ? preg_replace('/[^A-Za-z0-9]/', '', (string) $body['pushToken']) : '';
        $table = Lookup::table('passflow_wallet');
        $existing = (int) $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . $table . ' WHERE device_id = %s AND serial = %s', (string) $request['device'], $serial)); // phpcs:ignore

        if ($existing) {
            $wpdb->update($table, array('push_token' => $push), array('id' => $existing));

            return new \WP_REST_Response(null, 200);
        }

        $wpdb->insert($table, array(
            'device_id' => (string) $request['device'],
            'push_token' => $push,
            'serial' => $serial,
            'pass_type' => (string) $request['type'],
            'pass_updated' => time(),
            'created_at' => current_time('mysql'),
        ));

        return new \WP_REST_Response(null, 201);
    }

    /**
     * @param \WP_REST_Request $request
     */
    public static function unregister($request)
    {
        global $wpdb;
        $serial = (string) $request['serial'];

        if (!self::authorized($request, $serial)) {
            return new \WP_REST_Response(null, 401);
        }

        $wpdb->delete(Lookup::table('passflow_wallet'), array('device_id' => (string) $request['device'], 'serial' => $serial));

        return new \WP_REST_Response(null, 200);
    }

    /**
     * Billets de cet iPhone modifiés depuis « passesUpdatedSince ».
     *
     * @param \WP_REST_Request $request
     */
    public static function updated($request)
    {
        global $wpdb;

        if (!self::validType((string) $request['type'])) {
            return new \WP_REST_Response(null, 404);
        }

        $since = (int) $request->get_param('passesUpdatedSince');
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT serial, pass_updated FROM ' . Lookup::table('passflow_wallet') . ' WHERE device_id = %s AND pass_type = %s AND pass_updated > %d', // phpcs:ignore
            (string) $request['device'],
            (string) $request['type'],
            $since
        ), ARRAY_A);

        if (!$rows) {
            return new \WP_REST_Response(null, 204);
        }

        return new \WP_REST_Response(array(
            'serialNumbers' => array_values(array_unique(array_column($rows, 'serial'))),
            'lastUpdated' => (string) max(array_map('intval', array_column($rows, 'pass_updated'))),
        ), 200);
    }

    /**
     * Le billet à jour (.pkpass), servi tel quel.
     *
     * @param \WP_REST_Request $request
     */
    public static function pass($request)
    {
        global $wpdb;
        $serial = (string) $request['serial'];

        if (!self::validType((string) $request['type']) || !self::authorized($request, $serial)) {
            return new \WP_REST_Response(null, 401);
        }

        $booking = preg_match('/^passflow-(\d+)(?:-r(\d+))?$/', $serial, $m) ? Bookings::find((int) $m[1]) : null;

        // Billet d'avant un transfert : servi barré, au nom de l'ancien participant.
        if ($booking && (isset($m[2]) ? (int) $m[2] : 0) !== Transfers::rev($booking)) {
            $booking = Transfers::previous($booking, isset($m[2]) ? (int) $m[2] : 0);
        }

        if (!$booking) {
            return new \WP_REST_Response(null, 404);
        }

        $updated = (int) $wpdb->get_var($wpdb->prepare('SELECT MAX(pass_updated) FROM ' . Lookup::table('passflow_wallet') . ' WHERE serial = %s', $serial)); // phpcs:ignore
        $updated = $updated ?: time();
        $since = strtotime((string) $request->get_header('if_modified_since'));

        if ($since && $since >= $updated) {
            return new \WP_REST_Response(null, 304);
        }

        $content = Tickets::applePass($booking);

        if ($content === '') {
            return new \WP_REST_Response(null, 500);
        }

        status_header(200);
        header('Content-Type: application/vnd.apple.pkpass');
        header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $updated) . ' GMT');
        header('Content-Length: ' . strlen($content));
        echo $content; // phpcs:ignore -- fichier binaire.
        exit;
    }

    /**
     * @param \WP_REST_Request $request
     */
    public static function log($request)
    {
        $body = json_decode((string) $request->get_body(), true);

        if (is_array($body) && !empty($body['logs'])) {
            self::remember('apple', 'iPhone : ' . substr(implode(' | ', array_map('strval', (array) $body['logs'])), 0, 400));
        }

        return new \WP_REST_Response(null, 200);
    }

    /**
     * Prévient les iPhones qui portent ce billet (APNs, certificat du Pass Type ID, HTTP/2).
     */
    public static function applePush($serial)
    {
        global $wpdb;

        if (!Tickets::appleReady()) {
            return;
        }

        $table = Lookup::table('passflow_wallet');
        $rows = $wpdb->get_results($wpdb->prepare('SELECT id, push_token, pass_type FROM ' . $table . ' WHERE serial = %s', $serial), ARRAY_A); // phpcs:ignore

        if (!$rows) {
            return;
        }

        $wpdb->query($wpdb->prepare('UPDATE ' . $table . ' SET pass_updated = %d WHERE serial = %s', time(), $serial)); // phpcs:ignore

        if (!function_exists('curl_init') || !defined('CURL_HTTP_VERSION_2_0') || !(curl_version()['features'] & CURL_VERSION_HTTP2)) {
            self::remember('apple', __("le serveur ne sait pas envoyer de notification Apple (cURL sans HTTP/2) : demandez à l'hébergeur d'activer HTTP/2 pour cURL.", 'smbb-passflow'));

            return;
        }

        $cert = tempnam(get_temp_dir(), 'passflow-apns');
        $key = tempnam(get_temp_dir(), 'passflow-apns');
        file_put_contents($cert, (string) Settings::get('apple_certificate'));
        file_put_contents($key, Settings::secret('apple_private_key'));
        $error = '';

        foreach ($rows as $row) {
            if ($row['push_token'] === '') {
                continue;
            }

            $ch = curl_init('https://api.push.apple.com/3/device/' . $row['push_token']);
            curl_setopt_array($ch, array(
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_2_0,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => '{}',
                CURLOPT_HTTPHEADER => array('apns-topic: ' . $row['pass_type']),
                CURLOPT_SSLCERT => $cert,
                CURLOPT_SSLKEY => $key,
                CURLOPT_SSLKEYPASSWD => Settings::secret('apple_key_password'),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 10,
            ));
            $response = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $curl_error = curl_error($ch);
            curl_close($ch);

            if ($code === 410) {
                $wpdb->delete($table, array('id' => (int) $row['id'])); // billet retiré de cet iPhone.
            } elseif ($code !== 200) {
                $error = $curl_error !== '' ? $curl_error : 'APNs HTTP ' . $code . ' ' . (string) $response;
            }
        }

        @unlink($cert); // phpcs:ignore
        @unlink($key); // phpcs:ignore
        self::remember('apple', $error);
    }
}

