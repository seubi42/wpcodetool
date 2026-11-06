<?php

namespace Smbb\SignConnectProxima\Service;

defined('ABSPATH') || exit;

/**
 * Accès OpenAI de l'organisation (clé et modèle portés par l'organisation).
 */
final class OpenAi
{
    const DEFAULT_MODEL = 'gpt-4.1-mini';

    public static function model(array $organization)
    {
        return !empty($organization['openai_model']) ? (string) $organization['openai_model'] : self::DEFAULT_MODEL;
    }

    /**
     * Vérifie la clé et l'accès au modèle, sans consommer de jetons.
     */
    public static function checkModel(array $organization)
    {
        if (empty($organization['openai_api_key'])) {
            throw new \RuntimeException(__('Aucune clé OpenAI enregistrée.', 'smbb-signaconnect-proxima'));
        }

        $model = self::model($organization);
        $response = wp_remote_get('https://api.openai.com/v1/models/' . rawurlencode($model), array(
            'timeout' => 15,
            'headers' => array('Authorization' => 'Bearer ' . trim((string) $organization['openai_api_key'])),
        ));

        if (is_wp_error($response)) {
            throw new \RuntimeException($response->get_error_message());
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $body = json_decode((string) wp_remote_retrieve_body($response), true);

        if ($code < 200 || $code >= 300) {
            $message = is_array($body) && !empty($body['error']['message']) ? (string) $body['error']['message'] : 'HTTP ' . $code;
            throw new \RuntimeException($message);
        }

        return $model;
    }
}
