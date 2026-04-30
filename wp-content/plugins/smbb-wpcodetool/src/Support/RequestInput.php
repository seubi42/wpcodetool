<?php

namespace Smbb\WpCodeTool\Support;

defined('ABSPATH') || exit;

/**
 * Helpers simples pour lire les informations brutes de la requete HTTP courante.
 */
final class RequestInput
{
    private $raw_input = null;

    public function get_client_ip()
    {
        $candidates = array();

        foreach (array('HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP', 'HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR') as $key) {
            if (empty($_SERVER[$key])) {
                continue;
            }

            foreach (explode(',', (string) $_SERVER[$key]) as $value) {
                $value = trim($value);

                if ($value !== '') {
                    $candidates[] = $value;
                }
            }
        }

        foreach ($candidates as $candidate) {
            if (filter_var($candidate, FILTER_VALIDATE_IP)) {
                return $candidate;
            }
        }

        return '';
    }

    public function get_input_raw()
    {
        if ($this->raw_input !== null) {
            return $this->raw_input;
        }

        $input = file_get_contents('php://input');
        $this->raw_input = $input === false ? '' : (string) $input;

        return $this->raw_input;
    }

    public function get_input_json($assoc = true)
    {
        $raw = trim($this->get_input_raw());

        if ($raw === '') {
            return null;
        }

        $decoded = json_decode($raw, (bool) $assoc);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : null;
    }

    public function get_bearer_token()
    {
        $header = $this->authorization_header();

        if ($header === '') {
            return '';
        }

        if (!preg_match('/^Bearer\s+(.+)$/i', $header, $matches)) {
            return '';
        }

        return trim((string) $matches[1]);
    }

    private function authorization_header()
    {
        foreach (array('HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION', 'Authorization') as $key) {
            if (!empty($_SERVER[$key])) {
                return trim((string) $_SERVER[$key]);
            }
        }

        if (function_exists('apache_request_headers')) {
            $headers = apache_request_headers();

            if (is_array($headers)) {
                foreach ($headers as $name => $value) {
                    if (strtolower((string) $name) === 'authorization') {
                        return trim((string) $value);
                    }
                }
            }
        }

        return '';
    }
}
