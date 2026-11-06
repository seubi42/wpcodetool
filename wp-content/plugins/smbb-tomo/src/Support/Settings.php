<?php

namespace Smbb\Tomo\Support;

defined('ABSPATH') || exit;

/**
 * Lecture des réglages (option gérée par la ressource CodeTool tomo_settings).
 */
final class Settings
{
    const OPTION = 'smbb_tomo_settings';
    const DOCUMENTS_OPTION = 'smbb_tomo_documents';

    public static function defaults()
    {
        return array(
            'reminder_participant_hours' => 24,
            'reminder_intervenant_days' => 7,
            'waitlist_enabled' => 1,
            'vocabulary_event' => 'atelier',
            'vocabulary_event_singular' => '',
            'vocabulary_event_plural' => '',
            'vocabulary_staff' => 'intervenant',
            'vocabulary_staff_singular' => '',
            'vocabulary_staff_plural' => '',
            'vocabulary_slot' => 'creneau',
            'vocabulary_slot_singular' => '',
            'vocabulary_slot_plural' => '',
            'vocabulary_slot_feminine' => 0,
            'vocabulary_event_feminine' => 0,
            'vocabulary_staff_feminine' => 0,
            'vocabulary_participant' => 'participant',
            'vocabulary_participant_singular' => '',
            'vocabulary_participant_plural' => '',
            'vocabulary_participant_feminine' => 0,
            'vocabulary_booking' => 'origine',
            'vocabulary_booking_singular' => '',
            'vocabulary_booking_plural' => '',
            'vocabulary_booking_feminine' => 1,
            'vocabulary_ticket' => 'billet',
            'vocabulary_ticket_singular' => '',
            'vocabulary_ticket_plural' => '',
            'vocabulary_ticket_feminine' => 0,
            'vocabulary_pass' => 'pass',
            'vocabulary_pass_singular' => '',
            'vocabulary_pass_plural' => '',
            'vocabulary_pass_feminine' => 0,
            'vocabulary_voucher' => 'bon',
            'vocabulary_voucher_singular' => '',
            'vocabulary_voucher_plural' => '',
            'vocabulary_voucher_feminine' => 0,
            'vocabulary_example_name' => '',
            'vocabulary_example_location' => '',
            'waitlist_hold_hours' => 24,
            'ask_allergies' => 1,
            'ask_level' => 1,
            'ask_age' => 1,
            'ask_contact' => 'optional',
            'price_entry' => 'ttc',
            'price_tax_class' => 'standard',
            'documents_onsite_note' => function_exists('__') ? __('Sur place : tendez votre appareil au participant pour qu\'il lise et signe.', 'smbb-tomo') : '',
            'contact_email' => '',
            'gift_prefix' => 'TOMO',
            'gift_instore' => 1,
            'admin_theme' => 'aubergine',
            'admin_accent' => '#b5476b',
            'admin_buttons' => 0,
            'wallet_sync_google' => 0,
            'wallet_sync_apple' => 0,
            'ticket_policy' => '',
            'invoice_company' => '',
            'invoice_legal' => '',
            'invoice_address' => '',
            'invoice_siret' => '',
            'invoice_rcs' => '',
            'invoice_vat_number' => '',
            'invoice_vat_mode' => 'vat',
            'invoice_footer' => function_exists('__') ? __("Paiement comptant à la commande. Pas d'escompte pour paiement anticipé. Clients professionnels : en cas de retard de paiement, pénalités au taux de 3 fois le taux d'intérêt légal et indemnité forfaitaire pour frais de recouvrement de 40 €.", 'smbb-tomo') : '',
            'invoice_pattern' => 'F-{AAAA}-{N:5}',
            'credit_pattern' => 'AV-{AAAA}-{N:5}',
            'invoice_reset' => 'yearly',
            'invoice_next' => '',
            'credit_next' => '',
            'invoice_auto' => 'siren',
            'invoice_customer' => 1,
            'invoice_cc_buyer' => 1,
            'invoice_accounting_email' => '',
            'contact_phone' => '',
            'sender_name' => function_exists('get_bloginfo') ? (string) get_bloginfo('name') : '',
            'sender_email' => '',
            'twilio_sid' => '',
            'twilio_token' => '',
            'twilio_service' => '',
            'test_mode' => 0,
            'test_email' => '',
            'test_phone' => '',
            'waitlist_booking_url' => '',
            'ticket_color_mode' => 'atelier',
            'ticket_color' => '#8c3c34',
            'ticket_logo_id' => 0,
            'apple_pass_type_id' => '',
            'apple_team_id' => '',
            'apple_certificate' => '',
            'apple_private_key' => '',
            'apple_key_password' => '',
            'apple_wwdr' => '',
            'google_issuer_id' => '',
            'google_service_account' => '',
        ) + Templates::defaults();
    }

    /**
     * Nom de la structure dans les textes (« Demandez à … ») : le nom de l'expéditeur, sinon le nom du site.
     */
    public static function businessName()
    {
        $name = trim((string) self::get('sender_name'));

        return $name !== '' ? $name : (function_exists('get_bloginfo') ? (string) get_bloginfo('name') : '');
    }

    /** @var array<string,mixed>|null réglages fusionnés, gardés le temps de la requête */
    private static $cache = null;

    /**
     * Oublie les réglages gardés en mémoire (appelé quand l'une des deux options change).
     */
    public static function flush()
    {
        self::$cache = null;
    }

    public static function all()
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        static $watching = false;

        if (!$watching && function_exists('add_action')) {
            $watching = true;

            foreach (array(self::OPTION, self::DOCUMENTS_OPTION) as $option) {
                foreach (array('add_option_', 'update_option_', 'delete_option_') as $prefix) {
                    add_action($prefix . $option, array(__CLASS__, 'flush'), 1, 0);
                }
            }
        }

        $stored = get_option(self::OPTION, array());
        $all = array_merge(self::defaults(), is_array($stored) ? $stored : array());

        // Menu « Documents » : ses réglages ont leur propre option.
        $docs = get_option(self::DOCUMENTS_OPTION, null);

        if (is_array($docs)) {
            foreach (array('documents', 'documents_onsite_note') as $key) {
                if (array_key_exists($key, $docs)) {
                    $all[$key] = $docs[$key];
                }
            }
        }

        return self::$cache = $all;
    }

    /**
     * Liste d'attente activée ? Sans elle, une date complète n'est plus proposée, point.
     */
    public static function waitlistEnabled()
    {
        return (int) self::get('waitlist_enabled') === 1;
    }

    /**
     * Bons cadeaux utilisables en boutique (écran Bons cadeaux, bouton du tableau de bord) ?
     */
    public static function giftInStore()
    {
        return (int) self::get('gift_instore') === 1;
    }

    public static function get($key)
    {
        $all = self::all();

        return isset($all[$key]) ? $all[$key] : null;
    }

    public static function int($key)
    {
        return max(0, (int) self::get($key));
    }

    /**
     * Texte d'un message ($part : subject, body ou text). Un champ vidé reprend le texte par défaut.
     */
    public static function template($key, $part)
    {
        $value = trim((string) self::get('tpl_' . $key . '_' . $part));

        if ($value !== '') {
            return $value;
        }

        $message = Templates::message($key);

        return $message ? (string) $message[$part] : '';
    }

    public static function channelOn($key, $channel)
    {
        return (bool) (int) self::get('notify_' . $key . '_' . $channel);
    }

    /**
     * Valeur en clair d'un réglage chiffré (clés des wallets…).
     */
    public static function secret($key)
    {
        $value = (string) self::get($key);

        if ($value !== '' && class_exists('\\Smbb\\WpCodeTool\\Support\\Secrets') && \Smbb\WpCodeTool\Support\Secrets::isEncrypted($value)) {
            $plain = \Smbb\WpCodeTool\Support\Secrets::decrypt($value);

            return is_string($plain) ? $plain : '';
        }

        return $value;
    }

    /**
     * Jeton Twilio en clair (il est chiffré en base quand CodeTool sait chiffrer).
     */
    public static function twilioToken()
    {
        $token = (string) self::get('twilio_token');

        if ($token !== '' && class_exists('\\Smbb\\WpCodeTool\\Support\\Secrets') && \Smbb\WpCodeTool\Support\Secrets::isEncrypted($token)) {
            $plain = \Smbb\WpCodeTool\Support\Secrets::decrypt($token);

            return is_string($plain) ? $plain : '';
        }

        return $token;
    }

    public static function smsReady()
    {
        return self::get('twilio_sid') !== '' && self::get('twilio_service') !== '' && self::twilioToken() !== '';
    }
}
