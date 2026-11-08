<?php

namespace Smbb\WpCodeTool\Sms;

use Smbb\WpCodeTool\Sms\Provider\ProviderInterface;
use Smbb\WpCodeTool\Sms\Provider\TrackingInterface;
use Smbb\WpCodeTool\Sms\Provider\TwilioProvider;
use Smbb\WpCodeTool\Support\Secrets;

defined('ABSPATH') || exit;

/**
 * Envoi de SMS commun à tous les plugins, par le connecteur choisi dans CodeTool › Communication › SMS.
 *
 *     $result = \Smbb\WpCodeTool\Sms\Sms::send('0612345678', 'Votre atelier commence à 14 h.');
 *     if (is_wp_error($result)) { ... } else { $result['id']; }
 *
 * Actions : smbb_wpcodetool_sms_sent ($result, $to, $body, $context), smbb_wpcodetool_sms_failed
 * ($error, $to, $body, $context). Option : smbb_codetool_sms.
 */
final class Sms
{
    const OPTION = 'smbb_codetool_sms';

    /** @var array<string,ProviderInterface>|null */
    private static $providers = null;

    public static function hooks()
    {
        SmsLog::hooks();
        SmsCosts::hooks();
        SmsTracking::hooks();
        SmsHealth::hooks();
    }

    public static function defaults()
    {
        return array(
            'provider' => 'twilio',
            'providers' => array(),
            'log' => true,
            'log_days' => 15,
            'alerts' => true,
            // Webhook de statut donné au fournisseur à chaque envoi (suivi de remise).
            'tracking' => true,
            // État de santé (mis à jour à chaque envoi).
            'last_ok' => 0,
            'last_fail' => 0,
            'last_error' => '',
            'fails' => 0,
            'snooze_until' => 0,
        );
    }

    public static function settings()
    {
        $stored = get_option(self::OPTION, array());
        $settings = array_merge(self::defaults(), is_array($stored) ? $stored : array());

        if (!is_array($settings['providers'])) {
            $settings['providers'] = array();
        }

        return $settings;
    }

    public static function save(array $settings)
    {
        update_option(self::OPTION, array_merge(self::defaults(), $settings), false);
    }

    /**
     * @return array<string,ProviderInterface>
     */
    public static function providers()
    {
        if (self::$providers === null) {
            $list = apply_filters('smbb_wpcodetool_sms_providers', array('twilio' => new TwilioProvider()));
            self::$providers = array();

            foreach ((array) $list as $provider) {
                if ($provider instanceof ProviderInterface) {
                    self::$providers[$provider->key()] = $provider;
                }
            }
        }

        return self::$providers;
    }

    /**
     * @return ProviderInterface|null
     */
    public static function provider(array $settings = null)
    {
        $settings = $settings === null ? self::settings() : $settings;
        $all = self::providers();

        return isset($all[$settings['provider']]) ? $all[$settings['provider']] : null;
    }

    public static function providerSettings(array $settings = null)
    {
        $settings = $settings === null ? self::settings() : $settings;
        $provider = self::provider($settings);

        if (!$provider) {
            return array();
        }

        $own = isset($settings['providers'][$provider->key()]) && is_array($settings['providers'][$provider->key()])
            ? $settings['providers'][$provider->key()]
            : array();

        return array_merge($provider->defaults(), $own);
    }

    /**
     * true si un SMS peut partir, sinon le message d'erreur.
     *
     * @return true|string
     */
    public static function ready()
    {
        $provider = self::provider();

        return $provider ? $provider->ready(self::providerSettings()) : __('Aucun connecteur SMS.', 'smbb-wpcodetool');
    }

    /**
     * Envoie un SMS.
     *
     * @param string $to
     * @param string $body
     * @param array  $context Libre (plugin, événement…), transmis aux actions.
     * @return array{id:string,status:string,to:string,raw:array}|\WP_Error
     */
    public static function send($to, $body, array $context = array())
    {
        $ready = self::ready();
        $provider = self::provider();
        $key = $provider ? $provider->key() : '';

        if ($ready !== true) {
            // Rien n'est parti : noté au journal (pour le voir), sans compter comme une panne du connecteur.
            SmsLog::record('failed', $to, $body, $key, '', (string) $ready, $context);

            return new \WP_Error('smbb_sms_not_ready', (string) $ready);
        }

        $provider_settings = self::providerSettings();

        if ($provider instanceof TrackingInterface) {
            $callback = SmsTracking::callbackUrl($key);

            if ($callback !== '') {
                $provider_settings['_status_callback'] = $callback;
            }
        }

        try {
            $result = $provider->send($provider_settings, $to, $body);
        } catch (\Throwable $exception) {
            $error = new \WP_Error('smbb_sms_failed', $exception->getMessage());
            self::remember(false, $exception->getMessage());
            SmsLog::record('failed', $to, $body, $key, '', $exception->getMessage(), $context);
            do_action('smbb_wpcodetool_sms_failed', $error, $to, $body, $context);

            return $error;
        }

        self::remember(true, '');
        $log_id = SmsLog::record('sent', $result['to'] !== '' ? $result['to'] : $to, $body, $key, $result['id'], '', $context);
        // Registre des coûts, gardé à vie même si le journal est coupé.
        SmsCosts::record(
            $key,
            $result['id'],
            isset($context['source']) ? (string) $context['source'] : (isset($context['plugin']) ? (string) $context['plugin'] : ''),
            $result['status'],
            isset($result['raw']['num_segments']) ? (int) $result['raw']['num_segments'] : 0,
            $log_id
        );
        do_action('smbb_wpcodetool_sms_sent', $result, $to, $body, $context);

        return $result;
    }

    private static function remember($ok, $error)
    {
        $settings = self::settings();
        $settings[$ok ? 'last_ok' : 'last_fail'] = time();
        $settings['fails'] = $ok ? 0 : (int) $settings['fails'] + 1;

        if (!$ok) {
            $settings['last_error'] = (string) $error;
        }

        self::save($settings);
    }

    /**
     * Identifiants Twilio déjà saisis dans un plugin métier (Passflow, Tomo…), pour les reprendre.
     * Filtre smbb_wpcodetool_sms_import_sources : [clé => [label, option, sid, token, service]].
     *
     * @return array<string,array{label:string,account_sid:string,service_sid:string,token:string}>
     */
    public static function importSources()
    {
        $sources = apply_filters('smbb_wpcodetool_sms_import_sources', array(
            'passflow' => array('label' => 'Passflow', 'option' => 'smbb_passflow_settings', 'sid' => 'twilio_sid', 'token' => 'twilio_token', 'service' => 'twilio_service'),
            'tomo' => array('label' => 'Tomo', 'option' => 'smbb_tomo_settings', 'sid' => 'twilio_sid', 'token' => 'twilio_token', 'service' => 'twilio_service'),
        ));
        $found = array();

        foreach ((array) $sources as $key => $source) {
            $option = get_option($source['option']);

            if (!is_array($option) || empty($option[$source['sid']]) || empty($option[$source['service']])) {
                continue;
            }

            $found[$key] = array(
                'label' => (string) $source['label'],
                'account_sid' => (string) $option[$source['sid']],
                'service_sid' => (string) $option[$source['service']],
                // Même mécanisme de chiffrement (Support\Secrets) : la valeur est reprise telle quelle.
                'token' => isset($option[$source['token']]) ? (string) $option[$source['token']] : '',
            );
        }

        return $found;
    }

    public static function import($key)
    {
        $sources = self::importSources();

        if (!isset($sources[$key])) {
            return false;
        }

        $source = $sources[$key];
        $token = $source['token'];

        // En clair chez le plugin source : on chiffre en reprenant.
        if ($token !== '' && !Secrets::isEncrypted($token)) {
            $token = TwilioProvider::protect($token);
        }

        $settings = self::settings();
        $settings['provider'] = 'twilio';
        $settings['providers']['twilio'] = array(
            'account_sid' => $source['account_sid'],
            'service_sid' => $source['service_sid'],
            'token' => $token,
        );
        self::save($settings);

        return true;
    }
}
