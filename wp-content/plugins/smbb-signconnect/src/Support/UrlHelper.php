<?php

namespace Smbb\SignConnect\Support;

defined('ABSPATH') || exit;

final class UrlHelper
{
    public static function currentUrl()
    {
        global $wp;

        if (isset($wp) && isset($wp->request)) {
            return home_url(add_query_arg(array(), $wp->request));
        }

        return home_url('/');
    }

    public static function safeRefererUrl()
    {
        $referer = wp_get_referer();

        if (!$referer) {
            return home_url('/');
        }

        $safe_referer = wp_validate_redirect($referer, home_url('/'));

        return $safe_referer ? $safe_referer : home_url('/');
    }
}
