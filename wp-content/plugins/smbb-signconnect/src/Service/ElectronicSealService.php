<?php

namespace Smbb\SignConnect\Service;

use Smbb\SignConnect\Support\SignConnectSettings;

defined('ABSPATH') || exit;

final class ElectronicSealService
{
    const LEVEL_SIMPLE = 'electronic_signature';
    const LEVEL_ADVANCED = 'advanced_electronic_signature';
    const LEVEL_QUALIFIED = 'qualified_electronic_signature';

    private $api_key;
    private $base_url;
    private $timeout;
    private $poll_interval;
    private $max_wait_seconds;

    public function __construct($api_key = '', $sandbox = true, $timeout = 30, $poll_interval = 1, $max_wait_seconds = 15)
    {
        $this->api_key = (string) $api_key;
        $this->base_url = !empty($sandbox)
            ? 'https://api-sandbox.yousign.app/v3'
            : 'https://api.yousign.app/v3';
        $this->timeout = max(5, min(120, (int) $timeout));
        $this->poll_interval = max(1, min(10, (int) $poll_interval));
        $this->max_wait_seconds = max(5, min(120, (int) $max_wait_seconds));
    }

    public static function fromSettings()
    {
        return new self(
            SignConnectSettings::yousignApiKey(),
            SignConnectSettings::yousignSandbox(),
            SignConnectSettings::yousignTimeout(),
            SignConnectSettings::yousignPollInterval(),
            SignConnectSettings::yousignMaxWaitSeconds()
        );
    }

    public static function levels()
    {
        return array(self::LEVEL_SIMPLE, self::LEVEL_ADVANCED, self::LEVEL_QUALIFIED);
    }

    public function sealPdf($input_pdf_path, $output_pdf_path, $signature_level = self::LEVEL_SIMPLE, $timestamp = false, $external_id = null, array $visible_field = null)
    {
        if ($this->api_key === '') {
            throw new \RuntimeException(__('Yousign API key is missing.', 'smbb-signconnect'));
        }

        if (!is_readable($input_pdf_path)) {
            throw new \RuntimeException(__('The PDF to seal is unavailable.', 'smbb-signconnect'));
        }

        $document = $this->uploadSealDocument($input_pdf_path);
        $seal = $this->createSeal(
            isset($document['id']) ? (string) $document['id'] : '',
            $signature_level,
            $timestamp,
            $external_id,
            $visible_field
        );
        $seal = $this->waitForSealDone(isset($seal['id']) ? (string) $seal['id'] : '');

        $this->downloadSealDocument(isset($document['id']) ? (string) $document['id'] : '', $output_pdf_path);

        return array(
            'document' => $document,
            'seal' => $seal,
            'output_pdf' => $output_pdf_path,
        );
    }

    public function uploadSealDocument($pdf_path)
    {
        $boundary = 'smbb-signconnect-' . wp_generate_password(24, false, false);
        $body = $this->multipartBody($boundary, 'file', $pdf_path, 'application/pdf', $this->pdfUploadFilename($pdf_path));

        return $this->request('POST', '/electronic_seal_documents', null, array(
            'Content-Type' => 'multipart/form-data; boundary=' . $boundary,
        ), $body);
    }

    public function createSeal($document_id, $signature_level = self::LEVEL_SIMPLE, $timestamp = false, $external_id = null, array $visible_field = null)
    {
        if ($document_id === '') {
            throw new \RuntimeException(__('Yousign did not return a seal document id.', 'smbb-signconnect'));
        }

        $payload = array(
            'document_id' => $document_id,
            'signature_level' => in_array($signature_level, self::levels(), true) ? $signature_level : self::LEVEL_SIMPLE,
            'timestamp' => !empty($timestamp),
        );

        if ($external_id !== null && (string) $external_id !== '') {
            $payload['external_id'] = (string) $external_id;
        }

        $payload['fields'] = array($visible_field !== null ? $visible_field : $this->defaultSealField());

        return $this->request('POST', '/electronic_seals', $payload);
    }

    public function getSeal($seal_id)
    {
        if ($seal_id === '') {
            throw new \RuntimeException(__('Yousign did not return a seal id.', 'smbb-signconnect'));
        }

        return $this->request('GET', '/electronic_seals/' . rawurlencode($seal_id));
    }

    public function waitForSealDone($seal_id)
    {
        $started_at = time();

        do {
            $seal = $this->getSeal($seal_id);
            $status = isset($seal['status']) ? (string) $seal['status'] : '';

            if ($status === 'done') {
                return $seal;
            }

            if (in_array($status, array('failed', 'error'), true)) {
                throw new \RuntimeException(sprintf(
                    __('Yousign seal failed: %s', 'smbb-signconnect'),
                    wp_json_encode($seal)
                ));
            }

            sleep($this->poll_interval);
        } while ((time() - $started_at) < $this->max_wait_seconds);

        throw new \RuntimeException(sprintf(
            __('Yousign seal timeout after %d seconds.', 'smbb-signconnect'),
            $this->max_wait_seconds
        ));
    }

    public function downloadSealDocument($document_id, $output_path)
    {
        if ($document_id === '') {
            throw new \RuntimeException(__('Yousign did not return a seal document id.', 'smbb-signconnect'));
        }

        $binary = $this->requestRaw('GET', '/electronic_seal_documents/' . rawurlencode($document_id) . '/download', null, array(
            'Accept' => 'application/pdf',
        ));

        if (file_put_contents($output_path, $binary) === false) {
            throw new \RuntimeException(__('The sealed PDF could not be written.', 'smbb-signconnect'));
        }
    }

    private function request($method, $endpoint, array $json_payload = null, array $headers = array(), $body = null)
    {
        $response = $this->requestRaw($method, $endpoint, $json_payload, $headers, $body);
        $decoded = json_decode($response, true);

        if (!is_array($decoded)) {
            throw new \RuntimeException(sprintf(__('Invalid Yousign JSON response: %s', 'smbb-signconnect'), $response));
        }

        return $decoded;
    }

    private function requestRaw($method, $endpoint, array $json_payload = null, array $headers = array(), $body = null)
    {
        $headers = array_merge(array(
            'Authorization' => 'Bearer ' . $this->api_key,
            'Accept' => 'application/json',
        ), $headers);

        if ($json_payload !== null) {
            $headers['Content-Type'] = 'application/json';
            $body = wp_json_encode($json_payload);
        }

        $response = wp_remote_request($this->base_url . $endpoint, array(
            'method' => $method,
            'headers' => $headers,
            'body' => $body,
            'timeout' => $this->timeout,
        ));

        if (is_wp_error($response)) {
            throw new \RuntimeException(sprintf(__('Yousign request failed: %s', 'smbb-signconnect'), $response->get_error_message()));
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $response_body = (string) wp_remote_retrieve_body($response);

        if ($code < 200 || $code >= 300) {
            throw new \RuntimeException(sprintf(__('Yousign HTTP %1$d: %2$s', 'smbb-signconnect'), $code, $response_body));
        }

        return $response_body;
    }

    private function multipartBody($boundary, $field_name, $path, $mime_type, $filename)
    {
        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new \RuntimeException(__('The PDF to seal could not be read.', 'smbb-signconnect'));
        }

        $eol = "\r\n";

        return '--' . $boundary . $eol
            . 'Content-Disposition: form-data; name="' . $field_name . '"; filename="' . $filename . '"' . $eol
            . 'Content-Type: ' . $mime_type . $eol . $eol
            . $contents . $eol
            . '--' . $boundary . '--' . $eol;
    }

    private function pdfUploadFilename($path)
    {
        $filename = sanitize_file_name(basename((string) $path));

        if ($filename === '' || strtolower(pathinfo($filename, PATHINFO_EXTENSION)) !== 'pdf') {
            return 'signconnect-document.pdf';
        }

        return $filename;
    }

    private function defaultSealField()
    {
        return array(
            'type' => 'seal',
            'page' => 1,
            'x' => 1,
            'y' => 1,
            'width' => 1,
            'height' => 1,
            'reason' => 'SignConnect',
        );
    }
}
