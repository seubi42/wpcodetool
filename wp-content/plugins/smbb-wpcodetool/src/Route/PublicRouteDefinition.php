<?php

namespace Smbb\WpCodeTool\Route;

defined('ABSPATH') || exit;

/**
 * Route publique declaree par un plugin consommateur dans codetool/routes/public.php.
 */
final class PublicRouteDefinition
{
    private $method;
    private $pattern;
    private $callback;
    private $plugin_dir;
    private $file;

    public function __construct($method, $pattern, $callback, $plugin_dir = '', $file = '')
    {
        $this->method = strtoupper((string) $method);
        $this->pattern = $this->normalizePattern($pattern);
        $this->callback = $callback;
        $this->plugin_dir = rtrim((string) $plugin_dir, '/\\');
        $this->file = (string) $file;
    }

    public function method()
    {
        return $this->method;
    }

    public function pattern()
    {
        return $this->pattern;
    }

    public function callback()
    {
        return $this->callback;
    }

    public function pluginDir()
    {
        return $this->plugin_dir;
    }

    public function file()
    {
        return $this->file;
    }

    public function displayUrl()
    {
        return home_url('/' . $this->pattern);
    }

    public function matches($method, $path, &$params = array())
    {
        $params = array();

        if ($this->method !== strtoupper((string) $method)) {
            return false;
        }

        $path = $this->normalizePattern($path);
        $pattern_parts = $this->segments($this->pattern);
        $path_parts = $this->segments($path);

        if (count($pattern_parts) !== count($path_parts)) {
            return false;
        }

        foreach ($pattern_parts as $index => $pattern_part) {
            $path_part = isset($path_parts[$index]) ? $path_parts[$index] : '';

            if ($this->segmentMatches($pattern_part, $path_part, $segment_params)) {
                foreach ($segment_params as $segment_param) {
                    $params[] = rawurldecode($segment_param);
                }

                continue;
            }

            return false;
        }

        return true;
    }

    private function segmentMatches($pattern_part, $path_part, &$params)
    {
        $params = array();

        if ($pattern_part === $path_part) {
            return true;
        }

        if (strpos($pattern_part, '*') === false) {
            return false;
        }

        $quoted = preg_quote($pattern_part, '#');
        $regex = '#^' . str_replace('\\*', '([^/]+)', $quoted) . '$#';

        if (!preg_match($regex, $path_part, $matches)) {
            return false;
        }

        array_shift($matches);
        $params = $matches;

        return true;
    }

    private function normalizePattern($pattern)
    {
        $pattern = trim(str_replace('\\', '/', (string) $pattern));
        $pattern = trim($pattern, '/');
        $parts = array();

        foreach (explode('/', $pattern) as $part) {
            $part = trim($part);

            if ($part !== '') {
                $parts[] = $part;
            }
        }

        return implode('/', $parts);
    }

    private function segments($pattern)
    {
        if ((string) $pattern === '') {
            return array();
        }

        return explode('/', (string) $pattern);
    }
}
