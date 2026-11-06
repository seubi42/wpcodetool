<?php

namespace Smbb\Tomo\CodeTool;

use Smbb\Tomo\Support\Input;

defined('ABSPATH') || exit;

/**
 * Réglages : nettoyage des saisies et chiffrement du jeton Twilio.
 */
final class SettingsHooks
{
    public function beforeValidate(array $data, array $context = array())
    {
        foreach (array('contact_email', 'sender_email', 'test_email', 'sender_name', 'twilio_sid', 'twilio_service', 'waitlist_booking_url') as $key) {
            if (isset($data[$key])) {
                $data[$key] = trim((string) $data[$key]);
            }
        }

        foreach (array('contact_email', 'sender_email', 'test_email') as $key) {
            if (isset($data[$key])) {
                $data[$key] = strtolower($data[$key]);
            }
        }

        if (isset($data['test_phone'])) {
            $data['test_phone'] = Input::phone($data['test_phone']);
        }

        foreach (array('reminder_participant_hours', 'reminder_intervenant_days', 'waitlist_hold_hours') as $key) {
            if (isset($data[$key])) {
                $data[$key] = max(1, (int) $data[$key]);
            }
        }

        // Les textes d'un canal désactivé ne sont pas postés : on garde ceux déjà enregistrés.
        $current = isset($context['current']) && is_array($context['current']) ? $context['current'] : array();

        foreach ($current as $key => $value) {
            if (strpos((string) $key, 'tpl_') === 0 && !array_key_exists($key, $data)) {
                $data[$key] = $value;
            }
        }

        foreach (array('apple_pass_type_id', 'apple_team_id', 'google_issuer_id') as $key) {
            if (isset($data[$key])) {
                $data[$key] = trim((string) $data[$key]);
            }
        }

        foreach (array('apple_certificate', 'apple_wwdr') as $key) {
            if (isset($data[$key])) {
                $data[$key] = trim(str_replace("\r\n", "\n", (string) $data[$key]));
            }
        }

        // Secrets (jeton Twilio, clés des wallets) : champ vide = on garde l'ancien ; sinon chiffré si CodeTool le permet.
        foreach (array('twilio_token', 'apple_private_key', 'apple_key_password', 'google_service_account') as $key) {
            if (!array_key_exists($key, $data)) {
                continue;
            }

            $value = trim(str_replace("\r\n", "\n", (string) $data[$key]));
            $current = isset($context['current'][$key]) ? (string) $context['current'][$key] : '';

            if ($value === '') {
                $data[$key] = $current;
            } elseif (class_exists('\\Smbb\\WpCodeTool\\Support\\Secrets')) {
                $data[$key] = \Smbb\WpCodeTool\Support\Secrets::encrypt($value);
            } else {
                $data[$key] = $value;
            }
        }

        return $data;
    }

    public function validate(array $data, array $context = array())
    {
        $errors = array();

        foreach (array('contact_email', 'sender_email', 'test_email') as $key) {
            if (!empty($data[$key]) && !Input::isEmail($data[$key])) {
                $errors[$key] = __('Adresse e-mail invalide.', 'smbb-tomo');
            }
        }

        if (!empty($data['test_mode']) && empty($data['test_email']) && empty($data['test_phone'])) {
            $errors['test_email'] = __('En mode test, indiquez au moins un e-mail ou un téléphone qui recevra les messages.', 'smbb-tomo');
        }

        if (!empty($data['waitlist_booking_url']) && !wp_http_validate_url($data['waitlist_booking_url'])) {
            $errors['waitlist_booking_url'] = __('Adresse web invalide.', 'smbb-tomo');
        }

        foreach (array('apple_certificate' => 'CERTIFICATE', 'apple_wwdr' => 'CERTIFICATE') as $key => $label) {
            if (!empty($data[$key]) && strpos((string) $data[$key], '-----BEGIN ' . $label . '-----') === false) {
                $errors[$key] = __('Collez le certificat au format PEM (il commence par -----BEGIN CERTIFICATE-----).', 'smbb-tomo');
            }
        }

        unset($context);

        return $errors;
    }
}
