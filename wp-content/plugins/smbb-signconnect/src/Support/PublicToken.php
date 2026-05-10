<?php

namespace Smbb\SignConnect\Support;

defined('ABSPATH') || exit;

final class PublicToken
{
    public static function create()
    {
        return wp_generate_password(48, false, false);
    }

    public static function hash($token)
    {
        return hash_hmac('sha256', (string) $token, wp_salt('auth'));
    }

    public static function verify($token, $hash)
    {
        $hash = (string) $hash;

        return $hash !== '' && hash_equals($hash, self::hash($token));
    }
}
