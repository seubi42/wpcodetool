<?php

namespace Smbb\Passflow\CodeTool;

use Smbb\Passflow\Support\Input;

defined('ABSPATH') || exit;

/**
 * Réglages : nettoyage des saisies et chiffrement du jeton Twilio.
 */
final class SettingsHooks
{
    public function beforeValidate(array $data, array $context = array())
    {
        // Champs propres à l'assistant de démarrage (taux de TVA, première offre) : pas des réglages.
        foreach (array_keys($data) as $key) {
            if (strpos((string) $key, 'wizard_') === 0 || strpos((string) $key, 'offer_') === 0) {
                unset($data[$key]);
            }
        }

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

        if (isset($data['gift_prefix'])) {
            $data['gift_prefix'] = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $data['gift_prefix']));
        }

        if (isset($data['test_phone'])) {
            $data['test_phone'] = Input::phone($data['test_phone']);
        }

        if (isset($data['broadcast_batch'])) {
            $data['broadcast_batch'] = min(500, max(1, (int) $data['broadcast_batch']));
        }

        if (isset($data['review_delay'])) {
            $data['review_delay'] = min(168, max(0, (int) $data['review_delay']));
        }

        if (isset($data['review_form_id'])) {
            $data['review_form_id'] = absint($data['review_form_id']);
        }

        if (isset($data['scan_capture'])) {
            $data['scan_capture'] = $data['scan_capture'] === 'field' ? 'field' : 'keys';
        }

        if (isset($data['review_target'])) {
            $data['review_target'] = $data['review_target'] === 'present' ? 'present' : 'attended';
        }

        foreach (array('review_subject', 'review_sms_text') as $key) {
            if (isset($data[$key])) {
                $data[$key] = sanitize_text_field($data[$key]);
            }
        }

        if (isset($data['review_body'])) {
            $data['review_body'] = sanitize_textarea_field($data['review_body']);
        }

        if (isset($data['ticket_transfer_hours'])) {
            $data['ticket_transfer_hours'] = min(720, max(0, (int) $data['ticket_transfer_hours']));
        }

        if (isset($data['broadcast_every'])) {
            $data['broadcast_every'] = min(60, max(1, (int) $data['broadcast_every']));
        }

        foreach (array('reminder_participant_hours', 'reminder_host_days', 'waitlist_hold_hours') as $key) {
            if (isset($data[$key])) {
                $data[$key] = max(1, (int) $data[$key]);
            }
        }

        // Rappel du jour même : désactivé, à heure fixe le matin, ou X heures avant le début.
        if (isset($data['reminder_day_mode']) && !in_array($data['reminder_day_mode'], array('off', 'morning', 'hours'), true)) {
            $data['reminder_day_mode'] = 'off';
        }

        if (isset($data['reminder_day_hours'])) {
            $data['reminder_day_hours'] = min(23, max(1, (int) $data['reminder_day_hours']));
        }

        if (isset($data['reminder_day_time'])) {
            $data['reminder_day_time'] = preg_match('/^([01]?\d|2[0-3]):([0-5]\d)/', trim((string) $data['reminder_day_time']), $m)
                ? sprintf('%02d:%02d', (int) $m[1], (int) $m[2])
                : '08:00';
        }

        // Les textes d'un canal désactivé ne sont pas postés : on garde ceux déjà enregistrés.
        $current = isset($context['current']) && is_array($context['current']) ? $context['current'] : array();

        foreach ($current as $key => $value) {
            if (strpos((string) $key, 'tpl_') === 0 && !array_key_exists($key, $data)) {
                $data[$key] = $value;
            }
        }

        // Identifiants Twilio : plus affichés quand CodeTool gère les SMS. On les garde tels quels
        // (CodeTool › Communication › SMS propose de les reprendre).
        foreach (array('twilio_sid', 'twilio_token', 'twilio_service') as $key) {
            if (!array_key_exists($key, $data) && array_key_exists($key, $current)) {
                $data[$key] = $current[$key];
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

        if (!empty($data['gift_prefix']) && (strlen($data['gift_prefix']) < 2 || strlen($data['gift_prefix']) > 4)) {
            $errors['gift_prefix'] = __('Le sigle compte de 2 à 4 lettres ou chiffres.', 'smbb-passflow');
        }

        foreach (array('contact_email', 'sender_email', 'test_email') as $key) {
            if (!empty($data[$key]) && !Input::isEmail($data[$key])) {
                $errors[$key] = __('Adresse e-mail invalide.', 'smbb-passflow');
            }
        }

        if (!empty($data['test_mode']) && empty($data['test_email']) && empty($data['test_phone'])) {
            $errors['test_email'] = __('En mode test, indiquez au moins un e-mail ou un téléphone qui recevra les messages.', 'smbb-passflow');
        }

        if (!empty($data['waitlist_booking_url']) && !wp_http_validate_url($data['waitlist_booking_url'])) {
            $errors['waitlist_booking_url'] = __('Adresse web invalide.', 'smbb-passflow');
        }

        foreach (array('apple_certificate' => 'CERTIFICATE', 'apple_wwdr' => 'CERTIFICATE') as $key => $label) {
            if (!empty($data[$key]) && strpos((string) $data[$key], '-----BEGIN ' . $label . '-----') === false) {
                $errors[$key] = __('Collez le certificat au format PEM (il commence par -----BEGIN CERTIFICATE-----).', 'smbb-passflow');
            }
        }

        unset($context);

        return $errors;
    }
}
