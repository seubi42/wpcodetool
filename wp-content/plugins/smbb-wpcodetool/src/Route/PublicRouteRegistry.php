<?php

namespace Smbb\WpCodeTool\Route;

defined('ABSPATH') || exit;

/**
 * Collecte les routes publiques pendant l'inclusion d'un fichier public.php.
 */
final class PublicRouteRegistry
{
    private $routes = array();
    private $plugin_dir = '';
    private $file = '';

    public function __construct($plugin_dir = '', $file = '')
    {
        $this->plugin_dir = rtrim((string) $plugin_dir, '/\\');
        $this->file = (string) $file;
    }

    public function route()
    {
        return new PublicRouteBuilder($this);
    }

    public function add($method, $pattern, $callback)
    {
        if (!is_callable($callback)) {
            return null;
        }

        $route = new PublicRouteDefinition($method, $pattern, $callback, $this->plugin_dir, $this->file);
        $this->routes[] = $route;

        return $route;
    }

    public function routes()
    {
        return $this->routes;
    }
}
