<?php

namespace Smbb\WpCodeTool\Sms;

use Smbb\WpCodeTool\Sms\Provider\TrackingInterface;

defined('ABSPATH') || exit;

/**
 * Suivi de remise et coût des SMS.
 *
 * - Webhook : POST /wp-json/smbb-wpcodetool/v1/sms/status/<connecteur>, donné au fournisseur à chaque
 *   envoi (Twilio : StatusCallback). La signature est vérifiée par le connecteur.
 * - Relevé : quelques minutes après un statut définitif, puis chaque heure (tâche cron
 *   « Relevé des coûts SMS »), CodeTool relit chez le fournisseur les SMS dont le prix manque.
 *   C'est aussi ce qui donne le statut quand le webhook ne peut pas arriver (site local).
 */
final class SmsTracking
{
    const REST_NAMESPACE = 'smbb-wpcodetool/v1';
    const CRON = 'smbb_codetool_sms_costs';
    const FETCH_HOOK = 'smbb_codetool_sms_fetch';

    public static function hooks()
    {
        add_action('rest_api_init', array(__CLASS__, 'registerRoute'));
        add_action('smbb_wpcodetool_register_cron_tasks', array(__CLASS__, 'registerCron'));
        add_action(self::FETCH_HOOK, array(__CLASS__, 'fetchOne'), 10, 2);
    }

    public static function registerRoute()
    {
        register_rest_route(self::REST_NAMESPACE, '/sms/status/(?P<provider>[a-z0-9_-]+)', array(
            'methods' => 'POST',
            // Appelé par le fournisseur, sans session WordPress : l'authentification est la signature du webhook.
            'permission_callback' => '__return_true',
            'callback' => array(__CLASS__, 'callback'),
        ));
    }

    public static function registerCron($registry)
    {
        $registry->register(self::CRON, array(
            'label' => __('Relevé des coûts SMS', 'smbb-wpcodetool'),
            'description' => __('Demande au fournisseur le prix (et le statut de remise) des SMS récents qui ne l\'ont pas encore.', 'smbb-wpcodetool'),
            'schedule' => 'hourly',
            'callback' => array(__CLASS__, 'fillPending'),
        ));
    }

    /**
     * Adresse du webhook pour un connecteur ('' si le suivi par webhook est coupé ou le site injoignable).
     * Filtre smbb_wpcodetool_sms_status_callback_url (ex. tunnel ngrok pour un site local).
     */
    public static function callbackUrl($provider)
    {
        $settings = Sms::settings();
        $url = !empty($settings['tracking']) && self::publicSite() ? self::routeUrl($provider) : '';

        return (string) apply_filters('smbb_wpcodetool_sms_status_callback_url', $url, $provider);
    }

    public static function routeUrl($provider)
    {
        return rest_url(self::REST_NAMESPACE . '/sms/status/' . sanitize_key($provider));
    }

    /**
     * Le fournisseur peut-il joindre le site ? Non pour localhost, *.local, *.test, IP privées…
     */
    public static function publicSite()
    {
        $host = strtolower((string) wp_parse_url(home_url(), PHP_URL_HOST));

        if ($host === '' || $host === 'localhost' || preg_match('/\.(local|localhost|test|example|invalid|lan|internal)$/', $host)) {
            return false;
        }

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return (bool) filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
        }

        return strpos($host, '.') !== false;
    }

    /**
     * @param \WP_REST_Request $request
     */
    public static function callback($request)
    {
        $key = sanitize_key((string) $request->get_param('provider'));
        $providers = Sms::providers();
        $provider = isset($providers[$key]) ? $providers[$key] : null;

        if (!$provider instanceof TrackingInterface) {
            return new \WP_Error('smbb_sms_unknown_provider', 'Connecteur inconnu.', array('status' => 404));
        }

        $settings = Sms::settings();
        $own = isset($settings['providers'][$key]) && is_array($settings['providers'][$key]) ? $settings['providers'][$key] : array();
        $params = $request->get_body_params();
        $headers = array();

        foreach ((array) $request->get_headers() as $name => $values) {
            $headers[str_replace('_', '-', strtolower($name))] = is_array($values) ? (string) reset($values) : (string) $values;
        }

        $parsed = $provider->parseCallback(array_merge($provider->defaults(), $own), self::requestUrls($key), (array) $params, $headers);

        if (is_wp_error($parsed)) {
            return $parsed;
        }

        // Même adresse collée dans « Incoming messages » (ou Fallback) : c'est un SMS reçu, pas un statut.
        // On le signale aux plugins (action smbb_wpcodetool_sms_received) et on répond un TwiML vide :
        // pas de réponse automatique.
        if ($parsed['status'] === 'received' || (isset($params['Body']) && !isset($params['MessageStatus']))) {
            do_action('smbb_wpcodetool_sms_received', isset($params['From']) ? (string) $params['From'] : '', isset($params['Body']) ? (string) $params['Body'] : '', (array) $params, $key);

            if (!headers_sent()) {
                header('Content-Type: text/xml; charset=utf-8');
            }

            echo '<?xml version="1.0" encoding="UTF-8"?><Response></Response>';
            exit;
        }

        if ($parsed['id'] !== '') {
            $row = SmsCosts::apply($key, $parsed['id'], array('status' => $parsed['status'], 'error_code' => $parsed['error_code']));

            // Statut définitif : le prix arrive en général dans les minutes qui suivent.
            if ($row && $row['priced_at'] === null && in_array($row['status'], SmsCosts::FINAL_STATUSES, true)
                && !wp_next_scheduled(self::FETCH_HOOK, array($key, $parsed['id']))) {
                wp_schedule_single_event(time() + 3 * MINUTE_IN_SECONDS, self::FETCH_HOOK, array($key, $parsed['id']));
            }
        }

        return new \WP_REST_Response(null, 204);
    }

    /**
     * Adresses possibles de l'appel : celle donnée au fournisseur, et celle reconstruite depuis la requête
     * (proxy, http/https, permaliens « simples » avec ?rest_route=).
     */
    private static function requestUrls($key)
    {
        $urls = array(self::callbackUrl($key), self::routeUrl($key));

        if (!empty($_SERVER['HTTP_HOST']) && !empty($_SERVER['REQUEST_URI'])) {
            $host = sanitize_text_field(wp_unslash($_SERVER['HTTP_HOST']));
            $uri = (string) wp_unslash($_SERVER['REQUEST_URI']); // phpcs:ignore -- sert seulement au calcul de la signature, jamais affiché.
            $urls[] = (is_ssl() ? 'https://' : 'http://') . $host . $uri;
            $urls[] = 'https://' . $host . $uri;
        }

        return array_values(array_unique(array_filter($urls)));
    }

    /**
     * Relève un SMS chez son fournisseur (événement unique planifié après un statut définitif).
     */
    public static function fetchOne($key, $id)
    {
        $providers = Sms::providers();
        $provider = isset($providers[$key]) ? $providers[$key] : null;

        if (!$provider instanceof TrackingInterface) {
            return false;
        }

        $settings = Sms::settings();
        $own = isset($settings['providers'][$key]) && is_array($settings['providers'][$key]) ? $settings['providers'][$key] : array();
        $own = array_merge($provider->defaults(), $own);

        if ($provider->ready($own) !== true) {
            return false;
        }

        try {
            $data = $provider->fetch($own, $id);
        } catch (\Throwable $exception) {
            SmsCosts::apply($key, $id, array(), true);

            return false;
        }

        return (bool) SmsCosts::apply($key, $id, $data, true);
    }

    /**
     * Tâche cron horaire : relève les SMS récents sans prix.
     *
     * @return array{checked:int,priced:int}
     */
    public static function fillPending()
    {
        $result = array('checked' => 0, 'priced' => 0);

        foreach (SmsCosts::pending(50) as $row) {
            self::fetchOne($row['provider'], $row['provider_id']);
            $result['checked']++;
            $after = SmsCosts::find($row['provider'], $row['provider_id']);

            if ($after && $after['priced_at'] !== null) {
                $result['priced']++;
            }
        }

        return $result;
    }

    /**
     * Libellé et ton d'un statut de remise, pour le journal.
     *
     * @return array{0:string,1:string} [libellé, classe de badge]
     */
    public static function label($status)
    {
        $labels = array(
            'delivered' => array(__('Remis', 'smbb-wpcodetool'), 'is-success'),
            'read' => array(__('Lu', 'smbb-wpcodetool'), 'is-success'),
            'undelivered' => array(__('Non remis', 'smbb-wpcodetool'), 'is-error'),
            'failed' => array(__('Échec opérateur', 'smbb-wpcodetool'), 'is-error'),
            'canceled' => array(__('Annulé', 'smbb-wpcodetool'), 'is-warning'),
            'delivery_unknown' => array(__('Remise inconnue', 'smbb-wpcodetool'), 'is-warning'),
            'sent' => array(__('Chez l\'opérateur', 'smbb-wpcodetool'), 'is-info'),
        );

        return isset($labels[$status]) ? $labels[$status] : array(__('En cours', 'smbb-wpcodetool'), 'is-info');
    }
}
