<?php

namespace Smbb\SignConnectProxima\Front;

use Smbb\SignConnectProxima\Repository\AuditLog;
use Smbb\SignConnectProxima\Repository\Memberships;
use Smbb\SignConnectProxima\Repository\Organizations;
use Smbb\SignConnectProxima\Service\SendService;
use Smbb\SignConnectProxima\Support\Token;

defined('ABSPATH') || exit;

/**
 * API du client Windows (imprimante PDF → SignaConnect → navigateur).
 *
 *   POST /wp-json/signconnect/v1/client/login   login, password, device  → jeton du poste
 *   GET  /wp-json/signconnect/v1/client/me                                → utilisateur et équipes
 *   POST /wp-json/signconnect/v1/client/upload  file, team_id             → brouillon + lien navigateur
 *   POST /wp-json/signconnect/v1/client/logout                            → révoque le jeton du poste
 *
 * Le mot de passe ne sert qu'une fois, à la connexion : le poste garde ensuite un jeton
 * (en-tête X-SignConnect-Token), révocable depuis « Mon profil ». La base ne garde que
 * l'empreinte du jeton. Le lien navigateur est à usage unique et valable 2 minutes : il
 * ouvre la session WordPress puis l'app sur le brouillon, à l'étape des zones.
 */
final class ClientApi
{
    const META = 'scp_client_devices';
    const HEADER = 'X-SignConnect-Token';
    const HANDOFF_TTL = 120;
    const MAX_DEVICES = 20;

    public static function hooks()
    {
        add_action('rest_api_init', array(__CLASS__, 'routes'));
        add_action('init', array(__CLASS__, 'handoff'), 1);
    }

    public static function routes()
    {
        $ns = Webhook::NAMESPACE_V1;

        register_rest_route($ns, '/client/login', array(
            'methods' => 'POST',
            'callback' => array(__CLASS__, 'login'),
            'permission_callback' => '__return_true',
        ));

        register_rest_route($ns, '/client/me', array(
            'methods' => 'GET',
            'callback' => array(__CLASS__, 'me'),
            'permission_callback' => '__return_true',
        ));

        register_rest_route($ns, '/client/upload', array(
            'methods' => 'POST',
            'callback' => array(__CLASS__, 'upload'),
            'permission_callback' => '__return_true',
        ));

        register_rest_route($ns, '/client/logout', array(
            'methods' => 'POST',
            'callback' => array(__CLASS__, 'logout'),
            'permission_callback' => '__return_true',
        ));
    }

    /* ------------------------------------------------------------------ Routes */

    public static function login(\WP_REST_Request $request)
    {
        $ip = self::ip();
        $throttle_key = 'scp_client_fail_' . md5($ip);
        $failures = (int) get_transient($throttle_key);

        if ($failures >= 10) {
            return self::error('too_many', __('Trop de tentatives. Réessayez dans 15 minutes.', 'smbb-signaconnect-proxima'), 429);
        }

        $login = trim((string) $request->get_param('login'));
        $password = (string) $request->get_param('password');
        $user = wp_authenticate($login, $password);

        if (is_wp_error($user)) {
            set_transient($throttle_key, $failures + 1, 15 * MINUTE_IN_SECONDS);

            return self::error('bad_login', __('Identifiant ou mot de passe incorrect.', 'smbb-signaconnect-proxima'), 401);
        }

        delete_transient($throttle_key);

        if (!Memberships::forUser($user->ID)) {
            return self::error('no_org', __("Votre compte n'est rattaché à aucune organisation.", 'smbb-signaconnect-proxima'), 403);
        }

        $device = mb_substr(sanitize_text_field((string) $request->get_param('device')), 0, 80) ?: __('Poste Windows', 'smbb-signaconnect-proxima');
        $token = self::issue($user->ID, $device);
        AuditLog::record('client_login', sprintf(__('Client Windows connecté : %s.', 'smbb-signaconnect-proxima'), $device), array('ip' => $ip), null, null, 'user', $user->ID);

        return rest_ensure_response(array_merge(array('token' => $token), self::profile($user->ID)));
    }

    public static function me(\WP_REST_Request $request)
    {
        $user_id = self::authenticate($request);

        return is_wp_error($user_id) ? $user_id : rest_ensure_response(self::profile($user_id));
    }

    public static function upload(\WP_REST_Request $request)
    {
        $user_id = self::authenticate($request);

        if (is_wp_error($user_id)) {
            return $user_id;
        }

        $files = $request->get_file_params();
        $file = isset($files['file']) && is_array($files['file']) ? $files['file'] : array();
        $team_id = absint($request->get_param('team_id'));

        // Le nom réel arrive dans un champ texte (UTF-8) : .NET encode les noms accentués de
        // l'en-tête multipart en « =?utf-8?B?…?= », que PHP ne décode pas (extension perdue).
        if ($file) {
            $name = trim(sanitize_file_name(wp_unslash((string) $request->get_param('filename'))));
            $name = $name !== '' ? $name : (string) $file['name'];

            if (strtolower(pathinfo($name, PATHINFO_EXTENSION)) !== 'pdf') {
                $name = ($name !== '' ? $name : 'document') . '.pdf';
            }

            $file['name'] = $name;
        }

        if ($team_id < 1) {
            $teams = self::teams($user_id);
            $team_id = count($teams) === 1 ? (int) $teams[0]['id'] : 0;
        }

        try {
            $document = SendService::upload($user_id, $team_id, $file);
        } catch (\Throwable $exception) {
            return self::error('upload_failed', $exception->getMessage(), 400);
        }

        return rest_ensure_response(array(
            'document_id' => (int) $document['id'],
            'filename' => (string) $document['filename'],
            'page_count' => (int) $document['page_count'],
            'open_url' => self::handoffUrl($user_id, (int) $document['id']),
        ));
    }

    public static function logout(\WP_REST_Request $request)
    {
        $user_id = self::authenticate($request);

        if (is_wp_error($user_id)) {
            return $user_id;
        }

        $parts = self::parse(self::headerToken($request));
        self::revoke($user_id, array($parts['device']));

        return rest_ensure_response(array('ok' => true));
    }

    /* ------------------------------------------------------------------ Lien navigateur */

    public static function handoffUrl($user_id, $document_id)
    {
        $secret = wp_generate_password(43, false, false);
        set_transient('scp_handoff_' . hash('sha256', $secret), array(
            'user_id' => (int) $user_id,
            'document_id' => (int) $document_id,
        ), self::HANDOFF_TTL);

        return add_query_arg('scp_handoff', $secret, home_url('/'));
    }

    /**
     * ?scp_handoff=… : ouvre la session du poste dans le navigateur, puis l'app sur le brouillon.
     * Lien inconnu ou expiré : on va quand même sur l'app (écran de connexion si besoin).
     */
    public static function handoff()
    {
        if (empty($_GET['scp_handoff'])) {
            return;
        }

        $secret = sanitize_text_field(wp_unslash($_GET['scp_handoff']));
        $key = 'scp_handoff_' . hash('sha256', $secret);
        $data = get_transient($key);
        delete_transient($key);
        nocache_headers();

        if (!is_array($data) || empty($data['user_id']) || !get_userdata((int) $data['user_id'])) {
            wp_safe_redirect(Token::appUrl());
            exit;
        }

        $user_id = (int) $data['user_id'];

        if (get_current_user_id() !== $user_id) {
            wp_clear_auth_cookie();
            wp_set_current_user($user_id);
            wp_set_auth_cookie($user_id, false, is_ssl());
            do_action('wp_login', get_userdata($user_id)->user_login, get_userdata($user_id));
        }

        wp_safe_redirect(Token::appUrl(array('scp_draft' => (int) $data['document_id'])));
        exit;
    }

    /* ------------------------------------------------------------------ Jetons des postes */

    /**
     * @return array<string,array{name:string,created:string,last_used:string,hash:string}>
     */
    public static function devices($user_id)
    {
        $devices = get_user_meta($user_id, self::META, true);

        return is_array($devices) ? $devices : array();
    }

    public static function revoke($user_id, array $device_ids)
    {
        $devices = self::devices($user_id);
        $removed = 0;

        foreach ($device_ids as $device_id) {
            $device_id = sanitize_key((string) $device_id);

            if (isset($devices[$device_id])) {
                unset($devices[$device_id]);
                $removed++;
            }
        }

        if ($removed) {
            update_user_meta($user_id, self::META, $devices);
            AuditLog::record('client_revoked', __('Accès d\'un client Windows révoqué.', 'smbb-signaconnect-proxima'), array('count' => $removed), null, null, 'user', $user_id);
        }

        return $removed;
    }

    private static function issue($user_id, $name)
    {
        $devices = self::devices($user_id);
        $device_id = strtolower(wp_generate_password(12, false, false));
        $secret = wp_generate_password(40, false, false);
        $now = current_time('mysql');
        $devices[$device_id] = array('name' => $name, 'created' => $now, 'last_used' => $now, 'hash' => hash('sha256', $secret));

        // Les plus anciens partent au-delà de MAX_DEVICES.
        uasort($devices, function ($a, $b) {
            return strcmp((string) $b['last_used'], (string) $a['last_used']);
        });
        update_user_meta($user_id, self::META, array_slice($devices, 0, self::MAX_DEVICES, true));

        return 'scpc.' . (int) $user_id . '.' . $device_id . '.' . $secret;
    }

    /**
     * @return int|\WP_Error identifiant de l'utilisateur
     */
    private static function authenticate(\WP_REST_Request $request)
    {
        $parts = self::parse(self::headerToken($request));
        $devices = $parts ? self::devices($parts['user_id']) : array();
        $device = $parts && isset($devices[$parts['device']]) ? $devices[$parts['device']] : null;

        if (!$device || !hash_equals((string) $device['hash'], hash('sha256', $parts['secret'])) || !get_userdata($parts['user_id'])) {
            return self::error('bad_token', __('Ce poste n\'est plus connecté : reconnectez-vous.', 'smbb-signaconnect-proxima'), 401);
        }

        // Dernière utilisation, à la minute près, sans réécrire la méta à chaque appel.
        $now = current_time('mysql');

        if (substr((string) $device['last_used'], 0, 16) !== substr($now, 0, 16)) {
            $devices[$parts['device']]['last_used'] = $now;
            update_user_meta($parts['user_id'], self::META, $devices);
        }

        wp_set_current_user($parts['user_id']);

        return (int) $parts['user_id'];
    }

    private static function headerToken(\WP_REST_Request $request)
    {
        return trim((string) $request->get_header('x_signconnect_token'));
    }

    /**
     * @return array{user_id:int,device:string,secret:string}|null
     */
    private static function parse($token)
    {
        if (!preg_match('/^scpc\.(\d+)\.([a-z0-9]{12})\.([A-Za-z0-9]{40})$/', (string) $token, $match)) {
            return null;
        }

        return array('user_id' => (int) $match[1], 'device' => $match[2], 'secret' => $match[3]);
    }

    /* ------------------------------------------------------------------ Outils */

    /**
     * Équipes depuis lesquelles l'utilisateur peut envoyer (même règle que l'app).
     *
     * @return array<int,array{id:int,label:string}>
     */
    public static function teams($user_id)
    {
        $memberships = Memberships::forUser($user_id);
        $teams = array();

        foreach ($memberships as $membership) {
            $organization = Organizations::find($membership['organization_id']);

            if (!$organization || !Organizations::canSend($organization) || !Organizations::availableLevels($organization)) {
                continue;
            }

            foreach ($membership['teams'] as $team_id => $team_name) {
                $teams[] = array(
                    'id' => (int) $team_id,
                    'label' => count($memberships) > 1 ? $membership['organization_name'] . ' → ' . $team_name : $team_name,
                );
            }
        }

        return $teams;
    }

    private static function profile($user_id)
    {
        $user = get_userdata($user_id);

        return array(
            'user' => array(
                'name' => $user ? (string) $user->display_name : '',
                'email' => $user ? (string) $user->user_email : '',
            ),
            'teams' => self::teams($user_id),
            'app_url' => Token::appUrl(),
            'max_upload_mb' => (int) floor(SendService::MAX_UPLOAD_BYTES / 1048576),
        );
    }

    private static function ip()
    {
        return isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '';
    }

    private static function error($code, $message, $status)
    {
        return new \WP_Error('scp_client_' . $code, $message, array('status' => (int) $status));
    }
}
