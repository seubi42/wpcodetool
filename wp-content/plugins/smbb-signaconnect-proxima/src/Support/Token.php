<?php

namespace Smbb\SignConnectProxima\Support;

defined('ABSPATH') || exit;

/**
 * Lien public de signature.
 *
 * La base ne garde qu'un secret aléatoire par document. Le jeton du lien en est dérivé
 * avec les clés WordPress (wp_salt) : on peut renvoyer le même lien à chaque relance,
 * et une fuite de la base seule ne suffit pas à reconstruire les liens.
 */
final class Token
{
    public static function newSecret()
    {
        return wp_generate_password(40, false, false);
    }

    public static function forDocument($document_id, $secret)
    {
        $raw = hash_hmac('sha256', absint($document_id) . '|' . (string) $secret, wp_salt('auth'), true);

        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    public static function verify($document_id, $secret, $token)
    {
        if ((string) $secret === '' || (string) $token === '') {
            return false;
        }

        return hash_equals(self::forDocument($document_id, $secret), (string) $token);
    }

    public static function encodeId($document_id)
    {
        return rtrim(strtr(base64_encode('scp' . absint($document_id)), '+/', '-_'), '=');
    }

    public static function decodeId($encoded)
    {
        $encoded = strtr((string) $encoded, '-_', '+/');
        $encoded .= str_repeat('=', (4 - strlen($encoded) % 4) % 4);
        $decoded = base64_decode($encoded, true);

        if ($decoded === false || strpos($decoded, 'scp') !== 0) {
            return 0;
        }

        return absint(substr($decoded, 3));
    }

    public static function signingUrl(array $document)
    {
        $settings = get_option('smbb_scp_settings', array());
        $page_id = is_array($settings) && !empty($settings['signing_page_id']) ? (int) $settings['signing_page_id'] : 0;
        $base = $page_id > 0 ? get_permalink($page_id) : home_url('/');

        return add_query_arg(array(
            'scp_doc' => self::encodeId($document['id']),
            'scp_t' => self::forDocument($document['id'], isset($document['public_token_secret']) ? $document['public_token_secret'] : ''),
        ), $base ?: home_url('/'));
    }

    public static function appUrl(array $args = array())
    {
        $settings = get_option('smbb_scp_settings', array());
        $page_id = is_array($settings) && !empty($settings['app_page_id']) ? (int) $settings['app_page_id'] : 0;
        $base = $page_id > 0 ? get_permalink($page_id) : home_url('/');

        return $args ? add_query_arg($args, $base) : $base;
    }
}
