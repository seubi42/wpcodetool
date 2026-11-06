<?php

namespace Smbb\SignConnectProxima\Service;

use Smbb\SignConnectProxima\Repository\Organizations;

defined('ABSPATH') || exit;

/**
 * Envoi de SMS par le compte Twilio de l'organisation.
 * « Numéro ou service d'envoi » : un numéro (+33…) ou un Messaging Service (MG…).
 */
final class Sms
{
    public static function send(array $organization, $to, $body)
    {
        if (!Organizations::hasTwilio($organization)) {
            throw new \RuntimeException(__("Twilio n'est pas configuré pour cette organisation.", 'smbb-signaconnect-proxima'));
        }

        $to = self::normalizePhone($to);

        if ($to === '') {
            throw new \RuntimeException(__('Numéro de téléphone invalide.', 'smbb-signaconnect-proxima'));
        }

        $from = trim((string) $organization['twilio_from']);
        $payload = array('To' => $to, 'Body' => (string) $body);

        if (strpos($from, 'MG') === 0) {
            $payload['MessagingServiceSid'] = $from;
        } else {
            $payload['From'] = $from;
        }

        $response = wp_remote_post(
            'https://api.twilio.com/2010-04-01/Accounts/' . rawurlencode(trim((string) $organization['twilio_sid'])) . '/Messages.json',
            array('timeout' => 20, 'headers' => self::auth($organization), 'body' => $payload)
        );

        return self::decode($response);
    }

    /**
     * Vérifie les identifiants sans envoyer de SMS.
     */
    public static function checkAccount(array $organization)
    {
        if (!Organizations::hasTwilio($organization)) {
            throw new \RuntimeException(__('Renseignez Account SID, Auth token et numéro ou service d\'envoi.', 'smbb-signaconnect-proxima'));
        }

        $response = wp_remote_get(
            'https://api.twilio.com/2010-04-01/Accounts/' . rawurlencode(trim((string) $organization['twilio_sid'])) . '.json',
            array('timeout' => 15, 'headers' => self::auth($organization))
        );

        return self::decode($response);
    }

    /**
     * Numéro au format international (+33612345678), exigé par Universign et Twilio.
     * Accepte 06 12 34 56 78, 06.12.34.56.78, 0033 6…, +33 (0)6…, 33612345678.
     * Renvoie '' si le numéro n'est pas exploitable.
     */
    public static function normalizePhone($phone)
    {
        $phone = str_replace('(0)', '', (string) $phone);
        $phone = preg_replace('/[^\d+]/', '', $phone);

        if (strpos($phone, '00') === 0) {
            $phone = '+' . substr($phone, 2);
        }

        if (preg_match('/^0\d{9}$/', $phone)) {
            return '+33' . substr($phone, 1);
        }

        if (preg_match('/^33[1-9]\d{8}$/', $phone)) {
            $phone = '+' . $phone;
        }

        // +33 suivi du 0 national (+330612345678) : on retire le 0.
        if (preg_match('/^\+330(\d{9})$/', $phone, $match)) {
            $phone = '+33' . $match[1];
        }

        return preg_match('/^\+[1-9]\d{7,14}$/', $phone) ? $phone : '';
    }

    private static function auth(array $organization)
    {
        return array('Authorization' => 'Basic ' . base64_encode(trim((string) $organization['twilio_sid']) . ':' . trim((string) $organization['twilio_token'])));
    }

    private static function decode($response)
    {
        if (is_wp_error($response)) {
            throw new \RuntimeException($response->get_error_message());
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $body = json_decode((string) wp_remote_retrieve_body($response), true);

        if ($code < 200 || $code >= 300) {
            $message = is_array($body) && !empty($body['message']) ? (string) $body['message'] : 'HTTP ' . $code;
            throw new \RuntimeException($message);
        }

        return is_array($body) ? $body : array();
    }
}
