<?php

namespace Smbb\WpCodeTool\Connector;

use RuntimeException;

defined('ABSPATH') || exit;

/**
 * Petit connecteur Twilio pour les plugins metier SMBB.
 *
 * La classe ne connait aucune table metier : elle se contente d'envoyer un SMS
 * via l'API Twilio et de retourner la reponse normalisee. Chaque plugin peut
 * ensuite persister le SID, le status ou l'erreur comme il le souhaite.
 */
final class SmbbTwilioSmsClient
{
    private $service_sid;
    private $account_sid;
    private $auth_token;

    public function __construct($service_sid, $account_sid, $auth_token)
    {
        $this->service_sid = trim((string) $service_sid);
        $this->account_sid = trim((string) $account_sid);
        $this->auth_token = trim((string) $auth_token);

        if ($this->service_sid === '' || $this->account_sid === '' || $this->auth_token === '') {
            throw new RuntimeException('Twilio settings are incomplete.');
        }
    }

    public static function fromSettings(array $settings)
    {
        return new self(
            isset($settings['twilio_service']) ? $settings['twilio_service'] : '',
            isset($settings['twilio_sid']) ? $settings['twilio_sid'] : '',
            isset($settings['twilio_token']) ? $settings['twilio_token'] : ''
        );
    }

    public function send($to, $body)
    {
        $to = $this->normalizePhoneNumber($to);
        $body = trim((string) $body);

        if ($to === '') {
            throw new RuntimeException('Twilio recipient phone is required.');
        }

        if ($body === '') {
            throw new RuntimeException('Twilio message body is required.');
        }

        $response = wp_remote_post($this->endpoint(), array(
            'timeout' => 20,
            'headers' => array(
                'Authorization' => 'Basic ' . base64_encode($this->account_sid . ':' . $this->auth_token),
            ),
            'body' => array(
                'Body' => $body,
                'To' => $to,
                'MessagingServiceSid' => $this->service_sid,
            ),
        ));

        if (is_wp_error($response)) {
            throw new RuntimeException($response->get_error_message());
        }

        $status_code = (int) wp_remote_retrieve_response_code($response);
        $raw_body = (string) wp_remote_retrieve_body($response);
        $payload = json_decode($raw_body, true);

        if (!is_array($payload)) {
            $payload = array('raw_body' => $raw_body);
        }

        if ($status_code < 200 || $status_code >= 300) {
            $message = isset($payload['message']) ? (string) $payload['message'] : 'Twilio request failed.';

            throw new RuntimeException($message);
        }

        return array(
            'sid' => isset($payload['sid']) ? (string) $payload['sid'] : '',
            'status' => isset($payload['status']) ? (string) $payload['status'] : '',
            'to' => isset($payload['to']) ? (string) $payload['to'] : $to,
            'raw' => $payload,
        );
    }

    private function normalizePhoneNumber($phone)
    {
        $phone = trim((string) $phone);

        /*
         * Confort FR : si l'utilisateur saisit un mobile/fixe national du type
         * 0612345678, Twilio attend plutot le format E.164 +33612345678.
         *
         * On reste volontairement conservateur :
         * - exactement 10 chiffres ;
         * - premier chiffre = 0 ;
         * - aucun autre caractere significatif.
         *
         * Les numeros deja internationaux (+33..., +41..., etc.) restent tels quels.
         */
        if (preg_match('/^0\d{9}$/', $phone)) {
            return '+33' . substr($phone, 1);
        }

        return $phone;
    }

    private function endpoint()
    {
        return 'https://api.twilio.com/2010-04-01/Accounts/' . rawurlencode($this->account_sid) . '/Messages.json';
    }
}
