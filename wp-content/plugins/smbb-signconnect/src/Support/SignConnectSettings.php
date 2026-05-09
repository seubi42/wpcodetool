<?php

namespace Smbb\SignConnect\Support;

defined('ABSPATH') || exit;

/**
 * Lecture centralisée des réglages SignConnect.
 *
 * Le but est d'eviter de disperser des get_option('smbb_signconnect_settings')
 * partout dans le plugin. Si demain on renomme une cle, ou si on\'ajoute une
 * validation plus stricte, on le fait ici et le front reste coherent.
 */
final class SignConnectSettings
{
    const OPTION_NAME = 'smbb_signconnect_settings';

    public static function all()
    {
        $settings = get_option(self::OPTION_NAME, array());

        return is_array($settings) ? $settings : array();
    }

    public static function brandColor()
    {
        $settings = self::all();
        $brand_color = isset($settings['brand_color'])
            ? sanitize_hex_color((string) $settings['brand_color'])
            : '';

        return $brand_color ? $brand_color : '#2271b1';
    }

    public static function defaultSendMessage()
    {
        $settings = self::all();

        return isset($settings['default_send_message'])
            ? sanitize_textarea_field((string) $settings['default_send_message'])
            : '';
    }

    public static function signingPageUrl()
    {
        $settings = self::all();
        $page_id = isset($settings['signing_page_id']) ? absint($settings['signing_page_id']) : 0;

        if ($page_id > 0) {
            $url = get_permalink($page_id);

            if ($url) {
                return $url;
            }
        }

        $url = isset($settings['signing_page_url']) ? esc_url_raw((string) $settings['signing_page_url']) : '';

        return $url !== '' ? $url : home_url('/');
    }

    public static function postingPageUrl()
    {
        $settings = self::all();
        $page_id = isset($settings['posting_page_id']) ? absint($settings['posting_page_id']) : 0;

        if ($page_id > 0) {
            $url = get_permalink($page_id);

            if ($url) {
                return $url;
            }
        }

        return home_url('/');
    }

    public static function openAiApiKey()
    {
        $settings = self::all();

        return isset($settings['openai_api_key'])
            ? sanitize_text_field((string) $settings['openai_api_key'])
            : '';
    }

    public static function geodecodeEnabled()
    {
        $settings = self::all();

        return !empty($settings['geodecode_enabled']);
    }

    public static function openAiEnabled()
    {
        $settings = self::all();

        return !empty($settings['openai_enabled']);
    }

    public static function openAiConfigured()
    {
        return self::openAiEnabled() && self::openAiApiKey() !== '';
    }

    public static function openAiAutoSuggestMessage()
    {
        $settings = self::all();

        return self::openAiConfigured() && !empty($settings['openai_auto_suggest_message']);
    }

    public static function openAiSuggestSignatureZone()
    {
        $settings = self::all();

        return self::openAiConfigured() && !empty($settings['openai_suggest_signature_zone']);
    }

    public static function twilioService()
    {
        $settings = self::all();

        return isset($settings['twilio_service'])
            ? sanitize_text_field((string) $settings['twilio_service'])
            : '';
    }

    public static function twilioSid()
    {
        $settings = self::all();

        return isset($settings['twilio_sid'])
            ? sanitize_text_field((string) $settings['twilio_sid'])
            : '';
    }

    public static function twilioToken()
    {
        $settings = self::all();

        return isset($settings['twilio_token'])
            ? sanitize_text_field((string) $settings['twilio_token'])
            : '';
    }

    public static function twilioConfigured()
    {
        /*
         * Pour l'instant, on considere Twilio utilisable uniquement si les trois
         * informations indispensables sont renseignees et si le toggle est actif.
         * Ca pilote l'affichage SMS et protege aussi la validation serveur de
         * l'etape 3.
         */
        $settings = self::all();

        return !empty($settings['twilio_enabled'])
            && self::twilioService() !== ''
            && self::twilioSid() !== ''
            && self::twilioToken() !== '';
    }

    public static function defaultExpirationDays()
    {
        $settings = self::all();
        $value = isset($settings['default_expiration_days']) ? absint($settings['default_expiration_days']) : 7;

        return self::clampExpiration($value);
    }

    public static function minExpirationDays()
    {
        $settings = self::all();
        $value = isset($settings['min_expiration_days']) ? absint($settings['min_expiration_days']) : 1;

        return max(1, $value);
    }

    public static function maxExpirationDays()
    {
        $settings = self::all();
        $value = isset($settings['max_expiration_days']) ? absint($settings['max_expiration_days']) : 90;
        $min = self::minExpirationDays();

        return max($min, $value);
    }

    public static function clampExpiration($days)
    {
        /*
         * On borne toujours côté serveur, même si le champ HTML indique déjà
         * min/max. Le HTML aide l'UX, cette methode protege la donnee.
         */
        return max(self::minExpirationDays(), min(self::maxExpirationDays(), absint($days)));
    }
}
