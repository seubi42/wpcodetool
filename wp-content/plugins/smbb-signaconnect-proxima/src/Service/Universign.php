<?php

namespace Smbb\SignConnectProxima\Service;

defined('ABSPATH') || exit;

/**
 * Client Universign API v1 de l'organisation (clé propre à chaque organisation, aucune clé par défaut).
 * Les appels d'écriture sont en form-urlencoded, comme dans la documentation Universign.
 */
final class Universign
{
    private $organization;

    public function __construct(array $organization)
    {
        if (empty($organization['universign_api_key'])) {
            throw new \RuntimeException(__('Aucune clé API Universign enregistrée.', 'smbb-signaconnect-proxima'));
        }

        $this->organization = $organization;
    }

    public static function forOrganization(array $organization)
    {
        return new self($organization);
    }

    public function baseUrl()
    {
        return isset($this->organization['universign_environment']) && $this->organization['universign_environment'] === 'prod'
            ? 'https://api.universign.com'
            : 'https://api.alpha.universign.com';
    }

    /**
     * Appel JSON (lecture) ou form-urlencoded (écriture, $form non nul).
     *
     * @param array<string,mixed>|null $form
     */
    public function request($method, $path, array $form = null, $repeat_lists = false)
    {
        $args = array('method' => $method, 'headers' => array(), 'scp_label' => $method . ' ' . self::shortPath($path));

        if ($form !== null) {
            $args['headers']['Content-Type'] = 'application/x-www-form-urlencoded';
            $args['body'] = self::encode($form, $repeat_lists);
            $args['scp_label'] .= ' [' . urldecode($args['body']) . ']';
        }

        return $this->decode($this->send($path, $args));
    }

    /**
     * Téléversement d'un PDF : renvoie l'identifiant de fichier (file_…).
     */
    public function uploadFile($path, $filename)
    {
        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new \RuntimeException(__('Le PDF à envoyer à Universign est illisible.', 'smbb-signaconnect-proxima'));
        }

        $boundary = 'scp-' . wp_generate_password(24, false, false);
        $filename = str_replace('"', '', sanitize_file_name($filename) ?: 'document.pdf');
        $body = '--' . $boundary . "\r\n"
            . 'Content-Disposition: form-data; name="file"; filename="' . $filename . '"' . "\r\n"
            . "Content-Type: application/pdf\r\n\r\n"
            . $contents . "\r\n"
            . '--' . $boundary . "--\r\n";

        $data = $this->decode($this->send('/v1/files', array(
            'method' => 'POST',
            'headers' => array('Content-Type' => 'multipart/form-data; boundary=' . $boundary),
            'body' => $body,
            'timeout' => 90,
        )));

        if (empty($data['id'])) {
            throw new \RuntimeException(__("Universign n'a pas renvoyé d'identifiant de fichier.", 'smbb-signaconnect-proxima'));
        }

        return (string) $data['id'];
    }

    /**
     * Téléchargement binaire (PDF signé, attestation) vers un fichier.
     */
    public function downloadTo($path, $target)
    {
        $body = $this->send($path, array('method' => 'GET', 'headers' => array('Accept' => 'application/pdf'), 'timeout' => 90));

        if (strncmp($body, '%PDF', 4) !== 0) {
            throw new \RuntimeException(__("Universign n'a pas renvoyé de PDF.", 'smbb-signaconnect-proxima'));
        }

        if (file_put_contents($target, $body) === false) {
            throw new \RuntimeException(__('Le PDF Universign n\'a pas pu être écrit.', 'smbb-signaconnect-proxima'));
        }

        return $target;
    }

    public function transaction($transaction_id)
    {
        return $this->request('GET', '/v1/transactions/' . rawurlencode($transaction_id));
    }

    public function cancel($transaction_id)
    {
        return $this->request('POST', '/v1/transactions/' . rawurlencode($transaction_id) . '/cancel', array());
    }

    public function remind($transaction_id, $email)
    {
        return $this->request('POST', '/v1/transactions/' . rawurlencode($transaction_id) . '/participants/remind', array('email' => $email));
    }

    /**
     * PDF signé : route des archives d'abord (toujours valable), puis route de la transaction (60 jours).
     */
    public function downloadSigned($transaction_id, $document_id, $target)
    {
        try {
            return $this->downloadTo('/v1/archives/' . rawurlencode($transaction_id) . '/documents/' . rawurlencode($document_id) . '/download', $target);
        } catch (\Throwable $exception) {
            return $this->downloadTo('/v1/transactions/' . rawurlencode($transaction_id) . '/archive/documents/' . rawurlencode($document_id) . '/download', $target);
        }
    }

    public function downloadAttestation($transaction_id, $target)
    {
        try {
            return $this->downloadTo('/v1/archives/' . rawurlencode($transaction_id) . '/requester-attestation?lang=fr', $target);
        } catch (\Throwable $exception) {
            return $this->downloadTo('/v1/transactions/' . rawurlencode($transaction_id) . '/requester-attestation?lang=fr', $target);
        }
    }

    /**
     * Test sans effet de bord : liste une transaction.
     */
    public function check()
    {
        $data = $this->request('GET', '/v1/transactions?size=1');
        $environment = $this->baseUrl() === 'https://api.universign.com' ? __('production', 'smbb-signaconnect-proxima') : __('préproduction', 'smbb-signaconnect-proxima');
        $workspace = '';
        $items = isset($data['content']) && is_array($data['content']) ? $data['content'] : (isset($data[0]) ? $data : array());

        if (!empty($items[0]['creator']['workspace_name'])) {
            $workspace = (string) $items[0]['creator']['workspace_name'];
        }

        return $workspace !== ''
            ? sprintf(__('API Universign OK (%1$s, espace « %2$s »).', 'smbb-signaconnect-proxima'), $environment, $workspace)
            : sprintf(__('API Universign OK (%s).', 'smbb-signaconnect-proxima'), $environment);
    }

    /**
     * Les listes passent au format de la documentation Universign : schedule=[0,48,72].
     */
    public static function encode(array $form, $repeat_lists = false)
    {
        $pairs = array();

        foreach ($form as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            if (is_array($value) && $repeat_lists) {
                foreach ($value as $item) {
                    $pairs[] = rawurlencode((string) $key) . '=' . rawurlencode((string) $item);
                }

                continue;
            }

            if (is_array($value)) {
                $value = '[' . implode(',', array_map('strval', $value)) . ']';
            } elseif (is_bool($value)) {
                $value = $value ? 'true' : 'false';
            }

            $pairs[] = rawurlencode((string) $key) . '=' . rawurlencode((string) $value);
        }

        return implode('&', $pairs);
    }

    private function send($path, array $args)
    {
        $label = isset($args['scp_label']) ? (string) $args['scp_label'] : (isset($args['method']) ? $args['method'] : 'GET') . ' ' . self::shortPath($path);
        unset($args['scp_label']);
        $args['timeout'] = isset($args['timeout']) ? $args['timeout'] : 30;
        $args['headers'] = array_merge(array(
            'Authorization' => 'Bearer ' . trim((string) $this->organization['universign_api_key']),
            'Accept' => 'application/json',
        ), $args['headers']);

        $response = wp_remote_request($this->baseUrl() . $path, $args);

        if (is_wp_error($response)) {
            throw new \RuntimeException(sprintf(__('Universign injoignable : %s', 'smbb-signaconnect-proxima'), $response->get_error_message()));
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $body = (string) wp_remote_retrieve_body($response);

        if ($code === 401) {
            throw new \RuntimeException(__('Clé API refusée (401) : clé invalide ou pas pour cet environnement.', 'smbb-signaconnect-proxima'));
        }

        if ($code === 403) {
            throw new \RuntimeException(sprintf(__('Universign refuse cette action (403) : %s', 'smbb-signaconnect-proxima'), self::errorMessage($body, 'droits insuffisants ou option non activée sur le compte')));
        }

        if ($code < 200 || $code >= 300) {
            throw new \RuntimeException(sprintf(__('Universign HTTP %1$d : %2$s — appel %3$s — réponse %4$s', 'smbb-signaconnect-proxima'), $code, self::errorMessage($body, ''), $label, mb_substr(trim($body), 0, 600)));
        }

        return $body;
    }

    /**
     * Chemin lisible dans les messages : identifiants raccourcis.
     */
    private static function shortPath($path)
    {
        return preg_replace('#/(tx|doc|fld|file)_[A-Za-z0-9]+#', '/$1_…', (string) $path);
    }

    private function decode($body)
    {
        $data = json_decode($body, true);

        return is_array($data) ? $data : array();
    }

    private static function errorMessage($body, $fallback)
    {
        $data = json_decode((string) $body, true);

        if (is_array($data)) {
            foreach (array('error_description', 'message', 'error') as $key) {
                if (!empty($data[$key]) && is_string($data[$key])) {
                    return $data[$key];
                }
            }
        }

        $body = trim(wp_strip_all_tags((string) $body));

        return $body !== '' ? mb_substr($body, 0, 300) : $fallback;
    }
}
