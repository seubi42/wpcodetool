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

    public static function certificationEnabled()
    {
        $settings = self::all();

        return true;
    }

    public static function certificationHashProofEnabled()
    {
        $settings = self::all();

        return !empty($settings['certification_hash_proof_enabled']);
    }

    public static function certificationPdfSignatureEnabled()
    {
        return true;
    }

    public static function certificationSelfSignedEnabled()
    {
        $settings = self::all();

        return !empty($settings['certification_self_signed_enabled']);
    }

    public static function certificationImportedEnabled()
    {
        $settings = self::all();

        return !empty($settings['certification_imported_enabled']);
    }

    public static function certificationCertificateFingerprint()
    {
        $settings = self::all();
        $key = self::certificationCertificateMode() === 'imported'
            ? 'certification_imported_certificate_fingerprint'
            : 'certification_self_signed_certificate_fingerprint';

        if (!empty($settings[$key])) {
            return sanitize_text_field((string) $settings[$key]);
        }

        return isset($settings['certification_certificate_fingerprint'])
            ? sanitize_text_field((string) $settings['certification_certificate_fingerprint'])
            : '';
    }

    public static function certificationCertificateMode()
    {
        $settings = self::all();
        $mode = isset($settings['certification_certificate_mode']) ? sanitize_key((string) $settings['certification_certificate_mode']) : 'self_signed';

        return in_array($mode, array('self_signed', 'imported'), true) ? $mode : 'self_signed';
    }

    public static function certificationCertificatePath()
    {
        return self::certificationCertificatePathForMode(self::certificationCertificateMode());
    }

    public static function certificationCertificatePathForMode($mode)
    {
        $settings = self::all();
        $key = $mode === 'imported'
            ? 'certification_imported_certificate_path'
            : 'certification_self_signed_certificate_path';

        if (!empty($settings[$key])) {
            return (string) $settings[$key];
        }

        return isset($settings['certification_certificate_path']) ? (string) $settings['certification_certificate_path'] : '';
    }

    public static function certificationPrivateKeyPath()
    {
        return self::certificationPrivateKeyPathForMode(self::certificationCertificateMode());
    }

    public static function certificationPrivateKeyPathForMode($mode)
    {
        $settings = self::all();
        $key = $mode === 'imported'
            ? 'certification_imported_private_key_path'
            : 'certification_self_signed_private_key_path';

        if (!empty($settings[$key])) {
            return (string) $settings[$key];
        }

        return isset($settings['certification_private_key_path']) ? (string) $settings['certification_private_key_path'] : '';
    }

    public static function certificationPrivateKeyPassphrase()
    {
        return self::certificationPrivateKeyPassphraseForMode(self::certificationCertificateMode());
    }

    public static function certificationPrivateKeyPassphraseForMode($mode)
    {
        $settings = self::all();

        if ($mode === 'imported' && isset($settings['certification_imported_private_key_passphrase'])) {
            return (string) $settings['certification_imported_private_key_passphrase'];
        }

        return isset($settings['certification_private_key_passphrase']) ? (string) $settings['certification_private_key_passphrase'] : '';
    }

    public static function yousignSealEnabled()
    {
        $settings = self::all();

        return !empty($settings['yousign_seal_enabled']);
    }

    public static function yousignSignatureFlowEnabled()
    {
        $settings = self::all();

        return !empty($settings['yousign_signature_flow_enabled']);
    }

    public static function yousignSignatureDisableReturnRedirect()
    {
        $settings = self::all();

        return !empty($settings['yousign_signature_disable_return_redirect']);
    }

    public static function yousignSandbox()
    {
        $settings = self::all();

        return !empty($settings['yousign_sandbox']);
    }

    public static function yousignApiKey()
    {
        $settings = self::all();

        return isset($settings['yousign_api_key'])
            ? sanitize_text_field((string) $settings['yousign_api_key'])
            : '';
    }

    public static function yousignSignatureLevel()
    {
        $settings = self::all();
        $level = isset($settings['yousign_signature_level']) ? sanitize_key((string) $settings['yousign_signature_level']) : 'electronic_signature';
        $levels = array('electronic_signature', 'advanced_electronic_signature', 'qualified_electronic_signature');

        return in_array($level, $levels, true) ? $level : 'electronic_signature';
    }

    public static function yousignTimestamp()
    {
        $settings = self::all();

        return !empty($settings['yousign_timestamp']);
    }

    public static function yousignTimeout()
    {
        $settings = self::all();
        $value = isset($settings['yousign_timeout']) ? absint($settings['yousign_timeout']) : 30;

        return max(5, min(120, $value));
    }

    public static function yousignPollInterval()
    {
        $settings = self::all();
        $value = isset($settings['yousign_poll_interval']) ? absint($settings['yousign_poll_interval']) : 1;

        return max(1, min(10, $value));
    }

    public static function yousignMaxWaitSeconds()
    {
        $settings = self::all();
        $value = isset($settings['yousign_max_wait_seconds']) ? absint($settings['yousign_max_wait_seconds']) : 15;

        return max(5, min(120, $value));
    }

    public static function universignSignatureFlowEnabled()
    {
        $settings = self::all();

        return !empty($settings['universign_signature_flow_enabled']);
    }

    public static function universignDisableReturnRedirect()
    {
        $settings = self::all();

        return !empty($settings['universign_disable_return_redirect']);
    }

    public static function universignSandbox()
    {
        $settings = self::all();

        return !empty($settings['universign_sandbox']);
    }

    public static function universignApiKey()
    {
        $settings = self::all();

        return isset($settings['universign_api_key'])
            ? sanitize_text_field((string) $settings['universign_api_key'])
            : '';
    }

    public static function universignBaseUrl()
    {
        $settings = self::all();
        $url = isset($settings['universign_base_url']) ? esc_url_raw((string) $settings['universign_base_url']) : '';

        if ($url !== '') {
            return rtrim($url, '/');
        }

        return self::universignSandbox()
            ? 'https://api.alpha.universign.com'
            : 'https://api.universign.com';
    }

    public static function universignAuthMode()
    {
        $settings = self::all();
        $mode = isset($settings['universign_auth_mode']) ? sanitize_key((string) $settings['universign_auth_mode']) : 'basic';

        return in_array($mode, array('basic', 'bearer'), true) ? $mode : 'basic';
    }

    public static function universignReturnUrl()
    {
        $settings = self::all();
        $url = isset($settings['universign_return_url']) ? esc_url_raw((string) $settings['universign_return_url']) : '';

        return $url !== '' ? $url : self::signingPageUrl();
    }

    public static function universignMasterAccountId()
    {
        $settings = self::all();

        return isset($settings['universign_master_account_id'])
            ? sanitize_text_field((string) $settings['universign_master_account_id'])
            : '';
    }

    public static function universignWorkspaceId()
    {
        $settings = self::all();

        return isset($settings['universign_workspace_id'])
            ? sanitize_text_field((string) $settings['universign_workspace_id'])
            : '';
    }

    public static function universignPlanId()
    {
        $settings = self::all();

        return isset($settings['universign_plan_id'])
            ? sanitize_text_field((string) $settings['universign_plan_id'])
            : '';
    }

    public static function universignSignatureLevel()
    {
        $settings = self::all();
        $level = isset($settings['universign_signature_level']) ? sanitize_key((string) $settings['universign_signature_level']) : 'level1';
        $levels = array('level0', 'level1', 'level2', 'level3', 'level4');

        return in_array($level, $levels, true) ? $level : 'level1';
    }

    public static function universignTimeout()
    {
        $settings = self::all();
        $value = isset($settings['universign_timeout']) ? absint($settings['universign_timeout']) : 30;

        return max(5, min(120, $value));
    }

    public static function universignPollInterval()
    {
        $settings = self::all();
        $value = isset($settings['universign_poll_interval']) ? absint($settings['universign_poll_interval']) : 1;

        return max(1, min(10, $value));
    }

    public static function universignMaxWaitSeconds()
    {
        $settings = self::all();
        $value = isset($settings['universign_max_wait_seconds']) ? absint($settings['universign_max_wait_seconds']) : 15;

        return max(5, min(120, $value));
    }

    public static function certificationMethodForCriticality($criticality)
    {
        $settings = self::all();
        $criticality = DocumentCriticality::normalize($criticality);
        $key = 'criticality_' . $criticality . '_method';
        $defaults = array(
            DocumentCriticality::LOW => CertificationMethod::NONE,
            DocumentCriticality::MEDIUM => CertificationMethod::YOUSIGN_QUALIFIED_SEAL,
            DocumentCriticality::CRITICAL => CertificationMethod::YOUSIGN_SIGNATURE_FLOW,
        );
        $default = isset($defaults[$criticality]) ? $defaults[$criticality] : CertificationMethod::NONE;

        return isset($settings[$key])
            ? CertificationMethod::normalize((string) $settings[$key], $default)
            : $default;
    }

    public static function documentCriticality(array $document)
    {
        return DocumentCriticality::normalize(isset($document['criticality']) ? (string) $document['criticality'] : DocumentCriticality::LOW);
    }

    public static function documentCertificationMethod(array $document)
    {
        if (isset($document['certification_method']) && (string) $document['certification_method'] !== '') {
            return CertificationMethod::normalize((string) $document['certification_method']);
        }

        return self::certificationMethodForCriticality(self::documentCriticality($document));
    }

    public static function documentUsesYousignSeal(array $document)
    {
        return self::documentCertificationMethod($document) === CertificationMethod::YOUSIGN_QUALIFIED_SEAL;
    }

    public static function documentUsesYousignSignatureFlow(array $document)
    {
        return self::documentCertificationMethod($document) === CertificationMethod::YOUSIGN_SIGNATURE_FLOW;
    }

    public static function documentUsesUniversignSignatureFlow(array $document)
    {
        return self::documentCertificationMethod($document) === CertificationMethod::UNIVERSIGN_SIGNATURE_FLOW;
    }

    public static function documentUsesExternalSignatureFlow(array $document)
    {
        return self::documentUsesYousignSignatureFlow($document) || self::documentUsesUniversignSignatureFlow($document);
    }

    public static function documentUsesSignConnectCertification(array $document)
    {
        return in_array(self::documentCertificationMethod($document), array(CertificationMethod::SELF_SIGNED, CertificationMethod::LOCAL_CERTIFICATE), true);
    }

    public static function documentCertificationCertificateMode(array $document)
    {
        return self::documentCertificationMethod($document) === CertificationMethod::LOCAL_CERTIFICATE
            ? 'imported'
            : 'self_signed';
    }

    public static function certificationMethodAvailable($method)
    {
        $method = CertificationMethod::normalize($method);

        if ($method === CertificationMethod::NONE) {
            return true;
        }

        if ($method === CertificationMethod::SELF_SIGNED) {
            return self::certificationSelfSignedEnabled();
        }

        if ($method === CertificationMethod::LOCAL_CERTIFICATE) {
            return self::certificationImportedEnabled();
        }

        if ($method === CertificationMethod::YOUSIGN_QUALIFIED_SEAL) {
            return self::yousignSealEnabled() && self::yousignApiKey() !== '';
        }

        if ($method === CertificationMethod::YOUSIGN_SIGNATURE_FLOW) {
            return self::yousignSignatureFlowEnabled() && self::yousignApiKey() !== '';
        }

        if ($method === CertificationMethod::UNIVERSIGN_SIGNATURE_FLOW) {
            return self::universignSignatureFlowEnabled() && self::universignApiKey() !== '';
        }

        return false;
    }

    public static function yousignConfigured()
    {
        return self::yousignSealEnabled() && self::yousignApiKey() !== '';
    }

    public static function yousignSealConfigured()
    {
        return self::yousignConfigured() && !self::yousignSignatureFlowEnabled();
    }

    public static function yousignSignatureFlowConfigured()
    {
        return self::yousignSignatureFlowEnabled() && self::yousignApiKey() !== '';
    }

    public static function universignSignatureFlowConfigured()
    {
        return self::universignSignatureFlowEnabled() && self::universignApiKey() !== '';
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

    public static function templateDetectionDebugEnabled()
    {
        $settings = self::all();

        return !empty($settings['template_detection_debug_enabled']);
    }

    public static function templateDetectionPdftotextPath()
    {
        $settings = self::all();

        return isset($settings['template_detection_pdftotext_path'])
            ? trim((string) $settings['template_detection_pdftotext_path'])
            : '';
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
