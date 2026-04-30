<?php

namespace Smbb\WpCodeTool\Route;

defined('ABSPATH') || exit;

/**
 * Scanne les fichiers codetool/routes/public.php des plugins actifs.
 */
final class PublicRouteScanner
{
    private $errors = array();

    public function scan()
    {
        $this->errors = array();
        $routes = array();

        foreach ($this->activePluginDirs() as $plugin_dir) {
            $file = $this->routeFile($plugin_dir);

            if (!is_readable($file)) {
                continue;
            }

            $registry = new PublicRouteRegistry($plugin_dir, $file);

            try {
                PublicRouteContext::start($registry);
                include $file;
            } catch (\Throwable $exception) {
                $this->errors[] = array(
                    'file' => $file,
                    'message' => $exception->getMessage(),
                );
            } finally {
                PublicRouteContext::stop();
            }

            foreach ($registry->routes() as $route) {
                $routes[] = $route;
            }
        }

        return $routes;
    }

    public function errors()
    {
        return $this->errors;
    }

    private function routeFile($plugin_dir)
    {
        return rtrim((string) $plugin_dir, '/\\') . DIRECTORY_SEPARATOR . 'codetool' . DIRECTORY_SEPARATOR . 'routes' . DIRECTORY_SEPARATOR . 'public.php';
    }

    private function activePluginDirs()
    {
        $plugins = (array) get_option('active_plugins', array());

        if (is_multisite()) {
            $network_plugins = array_keys((array) get_site_option('active_sitewide_plugins', array()));
            $plugins = array_merge($plugins, $network_plugins);
        }

        $dirs = array();

        foreach (array_unique($plugins) as $plugin_file) {
            $relative_dir = dirname((string) $plugin_file);

            if ($relative_dir === '.' || $relative_dir === DIRECTORY_SEPARATOR) {
                $plugin_dir = WP_PLUGIN_DIR;
            } else {
                $plugin_dir = WP_PLUGIN_DIR . DIRECTORY_SEPARATOR . str_replace(array('/', '\\'), DIRECTORY_SEPARATOR, $relative_dir);
            }

            if (is_dir($plugin_dir)) {
                $dirs[$plugin_dir] = $plugin_dir;
            }
        }

        return array_values($dirs);
    }
}
