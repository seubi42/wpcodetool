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
            throw new RuntimeException('Les réglages Twilio sont incomplets.');
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

    /**
     * @param array $extra Paramètres Twilio en plus (StatusCallback…), facultatif.
     */
    public function send($to, $body, array $extra = array())
    {
        $to = $this->normalizePhoneNumber($to);
        $body = trim((string) $body);

        if ($to === '') {
            throw new RuntimeException('Le numéro du destinataire Twilio est obligatoire.');
        }

        if ($body === '') {
            throw new RuntimeException('Le texte du message Twilio est obligatoire.');
        }

        $response = wp_remote_post($this->endpoint(), array(
            'timeout' => 20,
            'headers' => array(
                'Authorization' => 'Basic ' . base64_encode($this->account_sid . ':' . $this->auth_token),
            ),
            'body' => array_merge(array_filter($extra, 'is_scalar'), array(
                'Body' => $body,
                'To' => $to,
                'MessagingServiceSid' => $this->service_sid,
            )),
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

    /**
     * Relit un message chez Twilio (statut de remise, prix, nombre de segments).
     *
     * @return array Réponse brute de l'API (status, error_code, price, price_unit, num_segments…).
     * @throws RuntimeException
     */
    public function fetch($message_sid)
    {
        $message_sid = preg_replace('/[^A-Za-z0-9]/', '', (string) $message_sid);

        if ($message_sid === '') {
            throw new RuntimeException('Identifiant de message Twilio manquant.');
        }

        $response = wp_remote_get('https://api.twilio.com/2010-04-01/Accounts/' . rawurlencode($this->account_sid) . '/Messages/' . rawurlencode($message_sid) . '.json', array(
            'timeout' => 15,
            'headers' => array(
                'Authorization' => 'Basic ' . base64_encode($this->account_sid . ':' . $this->auth_token),
            ),
        ));

        if (is_wp_error($response)) {
            throw new RuntimeException($response->get_error_message());
        }

        $payload = json_decode((string) wp_remote_retrieve_body($response), true);
        $code = (int) wp_remote_retrieve_response_code($response);

        if ($code < 200 || $code >= 300 || !is_array($payload)) {
            throw new RuntimeException(is_array($payload) && isset($payload['message']) ? (string) $payload['message'] : 'Twilio request failed.');
        }

        return $payload;
    }

    /**
     * Vérifie l'en-tête X-Twilio-Signature d'un webhook : base64(HMAC-SHA1(jeton, URL + clés/valeurs triées)).
     */
    public static function validSignature($auth_token, $url, array $params, $signature)
    {
        if ((string) $signature === '' || (string) $auth_token === '') {
            return false;
        }

        ksort($params, SORT_STRING);
        $data = (string) $url;

        foreach ($params as $key => $value) {
            $data .= $key . (is_scalar($value) ? (string) $value : '');
        }

        return hash_equals(base64_encode(hash_hmac('sha1', $data, (string) $auth_token, true)), (string) $signature);
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
