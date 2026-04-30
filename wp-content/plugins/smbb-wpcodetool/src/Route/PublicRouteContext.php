<?php

namespace Smbb\WpCodeTool\Route;

defined('ABSPATH') || exit;

/**
 * Contexte courant utilise par la fonction globale codetool_route().
 */
final class PublicRouteContext
{
    private static $registry;

    public static function start(PublicRouteRegistry $registry)
    {
        self::$registry = $registry;
    }

    public static function stop()
    {
        self::$registry = null;
    }

    public static function route()
    {
        if (!self::$registry) {
            self::$registry = new PublicRouteRegistry();
        }

        return self::$registry->route();
    }
}
