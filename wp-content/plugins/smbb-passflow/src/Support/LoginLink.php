<?php

namespace Smbb\Passflow\Support;

defined('ABSPATH') || exit;

/**
 * Lien de connexion automatique, valable 10 minutes : envoyé par SMS avec un lien de paiement,
 * il évite au client sur place le « je ne sais plus mon mot de passe ».
 *
 * - jeton aléatoire, seul son empreinte est gardée (méta utilisateur), avec l'échéance et la page de
 *   destination ; le lien reste utilisable jusqu'à l'échéance (les aperçus de liens des messageries
 *   l'ouvrent parfois avant le client) ;
 * - jamais pour un compte de l'équipe (intervenant, gestionnaire, rédacteur, admin) : lien normal ;
 * - lien expiré : on envoie vers la page prévue, WooCommerce demande alors de se connecter.
 */
final class LoginLink
{
    const PARAM = 'passflow_login';
    const META = '_smbb_passflow_login_link';
    const TTL = 600;

    public static function hooks()
    {
        add_action('init', array(__CLASS__, 'handle'), 1);
    }

    /**
     * Lien qui connecte $user_id puis l'envoie sur $redirect (ou $redirect seul si refusé).
     */
    public static function create($user_id, $redirect, $ttl = self::TTL)
    {
        $user_id = (int) $user_id;

        if (!$user_id || !self::allowed($user_id)) {
            return $redirect;
        }

        $token = wp_generate_password(32, false, false);
        update_user_meta($user_id, self::META, array(
            'hash' => wp_hash($token, 'nonce'),
            'expires' => time() + max(60, (int) $ttl),
            'redirect' => esc_url_raw($redirect),
        ));

        return add_query_arg(self::PARAM, $user_id . '.' . $token, home_url('/'));
    }

    /**
     * Comptes clients uniquement.
     */
    public static function allowed($user_id)
    {
        return !user_can($user_id, 'edit_posts') && !user_can($user_id, 'manage_options') && !user_can($user_id, Roles::CAP_HOST) && !user_can($user_id, Roles::CAP_MANAGE);
    }

    public static function handle()
    {
        if (empty($_GET[self::PARAM])) {
            return;
        }

        $raw = sanitize_text_field(wp_unslash($_GET[self::PARAM]));
        $parts = explode('.', $raw, 2);
        $user_id = isset($parts[0]) ? absint($parts[0]) : 0;
        $token = isset($parts[1]) ? (string) $parts[1] : '';
        $data = $user_id ? get_user_meta($user_id, self::META, true) : null;
        $fallback = function_exists('wc_get_page_permalink') ? wc_get_page_permalink('myaccount') : home_url('/');
        $redirect = is_array($data) && !empty($data['redirect']) ? (string) $data['redirect'] : $fallback;
        $valid = is_array($data) && $token !== '' && !empty($data['hash'])
            && hash_equals((string) $data['hash'], wp_hash($token, 'nonce'))
            && (int) $data['expires'] >= time()
            && self::allowed($user_id);

        if ($valid && get_current_user_id() !== $user_id) {
            wp_clear_auth_cookie();
            wp_set_current_user($user_id);
            wp_set_auth_cookie($user_id, false, is_ssl());
            do_action('wp_login', (string) get_userdata($user_id)->user_login, get_userdata($user_id));
        }

        // Pas de cache sur cette adresse, qui porte un jeton.
        nocache_headers();
        wp_safe_redirect($redirect ?: $fallback);
        exit;
    }
}
