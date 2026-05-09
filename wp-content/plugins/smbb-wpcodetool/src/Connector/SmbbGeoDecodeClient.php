<?php

namespace Smbb\WpCodeTool\Connector;

use RuntimeException;

defined('ABSPATH') || exit;

/**
 * Client tres leger pour le service geo.smbb-logiciel.com.
 */
final class SmbbGeoDecodeClient
{
    private $base_url;

    public function __construct($base_url = 'https://geo.smbb-logiciel.com/geodecode/')
    {
        $this->base_url = rtrim((string) $base_url, '/') . '/';
    }

    public function decode($latitude, $longitude, $app = '')
    {
        $latitude = (string) $latitude;
        $longitude = (string) $longitude;

        if (!is_numeric($latitude) || !is_numeric($longitude)) {
            throw new RuntimeException('Invalid GPS coordinates.');
        }

        if ($app === '' && !empty($_SERVER['HTTP_HOST'])) {
            $app = sanitize_text_field((string) wp_unslash($_SERVER['HTTP_HOST']));
        }

        $url = add_query_arg(array(
            'q' => $latitude . ';' . $longitude,
            'app' => (string) $app,
        ), $this->base_url);
        $response = wp_remote_get($url, array('timeout' => 10));

        if (is_wp_error($response)) {
            throw new RuntimeException($response->get_error_message());
        }

        $status_code = (int) wp_remote_retrieve_response_code($response);
        $body = (string) wp_remote_retrieve_body($response);

        if ($status_code < 200 || $status_code >= 300) {
            throw new RuntimeException('Geo decode error ' . $status_code . ' - ' . $body);
        }

        $data = json_decode($body, true);

        if (!is_array($data) || empty($data['success'])) {
            throw new RuntimeException('Geo decode returned no location.');
        }

        return array(
            'success' => true,
            'city' => isset($data['city']) ? sanitize_text_field((string) $data['city']) : '',
            'raw' => $data,
        );
    }
}
