<?php

namespace Smbb\WpCodeTool\Route;

defined('ABSPATH') || exit;

/**
 * Execute les routes publiques hors REST avant le rendu du theme.
 */
final class PublicRouteDispatcher
{
    private $scanner;

    public function __construct(PublicRouteScanner $scanner = null)
    {
        $this->scanner = $scanner ?: new PublicRouteScanner();
    }

    public function hooks()
    {
        add_action('template_redirect', array($this, 'dispatch'), 0);
    }

    public function dispatch()
    {
        $method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper((string) $_SERVER['REQUEST_METHOD']) : 'GET';
        $path = $this->requestPath();

        foreach ($this->scanner->scan() as $route) {
            $params = array();

            if (!$route->matches($method, $path, $params)) {
                continue;
            }

            $this->sendResponse(call_user_func_array($route->callback(), $params));
            exit;
        }
    }

    private function requestPath()
    {
        $request_uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '/';
        $path = (string) parse_url($request_uri, PHP_URL_PATH);
        $home_path = (string) parse_url(home_url('/'), PHP_URL_PATH);

        $path = trim($path, '/');
        $home_path = trim($home_path, '/');

        if ($home_path !== '' && strpos($path, $home_path . '/') === 0) {
            $path = substr($path, strlen($home_path) + 1);
        } elseif ($home_path !== '' && $path === $home_path) {
            $path = '';
        }

        return $path;
    }

    private function sendResponse($response)
    {
        if ($response === null) {
            return;
        }

        if ($response instanceof \WP_Error) {
            status_header(500);
            wp_send_json(array(
                'code' => 'codetool_route_error',
                'message' => $response->get_error_message(),
            ));
        }

        if ($response instanceof \WP_REST_Response) {
            status_header($response->get_status());
            wp_send_json($response->get_data());
        }

        if (is_array($response) || is_object($response)) {
            wp_send_json($response);
        }

        echo (string) $response;
    }
}
