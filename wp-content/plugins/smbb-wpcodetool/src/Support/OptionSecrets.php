<?php

namespace Smbb\WpCodeTool\Support;

defined('ABSPATH') || exit;

/**
 * Secrets rangés dans des options WordPress (et non dans une colonne de ressource) : mot de passe SMTP,
 * jeton Twilio… Ils apparaissent dans CodeTool › Chiffrement et suivent « Tout chiffrer / Tout déchiffrer »
 * et la migration automatique après un changement de clé.
 *
 * Déclarer un secret d'option (plugin métier) :
 *
 *     add_filter('smbb_wpcodetool_option_secrets', function ($secrets) {
 *         $secrets[] = array('label' => 'Passflow', 'name' => 'Jeton Twilio', 'option' => 'smbb_passflow_settings', 'path' => array('twilio_token'));
 *         return $secrets;
 *     });
 */
final class OptionSecrets
{
    /**
     * @return array<int,array{label:string,name:string,option:string,path:string[]}>
     */
    public static function all()
    {
        $secrets = apply_filters('smbb_wpcodetool_option_secrets', array(
            array('label' => __('E-mails (SMTP)', 'smbb-wpcodetool'), 'name' => __('Mot de passe SMTP', 'smbb-wpcodetool'), 'option' => 'smbb_codetool_mail', 'path' => array('transports', 'smtp', 'pass')),
            array('label' => __('SMS (Twilio)', 'smbb-wpcodetool'), 'name' => __('Auth Token Twilio', 'smbb-wpcodetool'), 'option' => 'smbb_codetool_sms', 'path' => array('providers', 'twilio', 'token')),
            array('label' => __('Sécurité (Turnstile)', 'smbb-wpcodetool'), 'name' => __('Clé secrète Cloudflare Turnstile', 'smbb-wpcodetool'), 'option' => 'smbb_codetool_security', 'path' => array('turnstile', 'secret')),
        ));
        $clean = array();

        foreach ((array) $secrets as $secret) {
            if (is_array($secret) && !empty($secret['option']) && !empty($secret['path']) && is_array($secret['path'])) {
                $clean[] = array(
                    'label' => isset($secret['label']) ? (string) $secret['label'] : (string) $secret['option'],
                    'name' => isset($secret['name']) ? (string) $secret['name'] : implode('.', $secret['path']),
                    'option' => (string) $secret['option'],
                    'path' => array_values(array_map('strval', $secret['path'])),
                );
            }
        }

        return $clean;
    }

    private static function read(array $secret)
    {
        $value = get_option($secret['option']);

        foreach ($secret['path'] as $key) {
            if (!is_array($value) || !array_key_exists($key, $value)) {
                return null;
            }

            $value = $value[$key];
        }

        return is_string($value) ? $value : null;
    }

    private static function write(array $secret, $new)
    {
        $option = get_option($secret['option']);

        if (!is_array($option)) {
            return false;
        }

        $ref = &$option;

        foreach ($secret['path'] as $key) {
            if (!is_array($ref) || !array_key_exists($key, $ref)) {
                return false;
            }

            $ref = &$ref[$key];
        }

        $ref = $new;
        unset($ref);

        return update_option($secret['option'], $option);
    }

    /**
     * Lignes pour le tableau de CodeTool › Chiffrement (même forme que les colonnes de ressources).
     */
    public static function inventory()
    {
        $rows = array();

        foreach (self::all() as $secret) {
            $value = self::read($secret);
            $entry = array(
                'label' => $secret['label'],
                'column' => $secret['name'],
                'option' => $secret['option'] . ' › ' . implode(' › ', $secret['path']),
                'length' => 0,
                'plain' => 0,
                'current' => 0,
                'known' => 0,
                'unknown' => 0,
                'too_short' => 0,
            );

            if ($value !== null && $value !== '') {
                $state = Secrets::state($value);

                if (isset($entry[$state])) {
                    $entry[$state]++;
                }
            }

            $rows[] = $entry;
        }

        return $rows;
    }

    /**
     * Chiffre (encrypt) ou déchiffre (decrypt) les secrets d'options. Renvoie le nombre de valeurs changées.
     */
    public static function process($op)
    {
        $changed = 0;

        foreach (self::all() as $secret) {
            $value = self::read($secret);

            if ($value === null || $value === '') {
                continue;
            }

            $state = Secrets::state($value);
            $new = null;

            if ($op === 'encrypt' && ($state === 'plain' || $state === 'known')) {
                $new = Secrets::encrypt($value);
            } elseif ($op === 'decrypt' && ($state === 'current' || $state === 'known')) {
                $new = Secrets::decrypt($value);
            }

            if ($new !== null && $new !== $value && self::write($secret, $new)) {
                $changed++;
            }
        }

        return $changed;
    }
}
