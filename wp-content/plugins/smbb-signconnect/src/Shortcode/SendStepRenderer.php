<?php

namespace Smbb\SignConnect\Shortcode;

use Smbb\SignConnect\Support\FrontIcons;
use Smbb\SignConnect\Support\SignConnectSettings;

defined('ABSPATH') || exit;

/**
 * Rendu de l'etape 3 du wizard front.
 *
 * Le renderer ne sauvegarde rien : il transforme seulement un document en HTML.
 * Cela garde une separation nette entre :
 * - presentation : cette classe ;
 * - validation/sauvegarde : DocumentSendPreparationService ;
 * - routage AJAX : SignConnectPostShortcode.
 */
final class SendStepRenderer
{
    public function render($document_id, array $document)
    {
        $sms_enabled = SignConnectSettings::twilioConfigured();
        $send_channel = $sms_enabled && !empty($document['send_channel']) && $document['send_channel'] === 'sms' ? 'sms' : 'email';
        $expiration_days = $this->expirationDays($document);
        $has_document_message = isset($document['send_message']) && (string) $document['send_message'] !== '';
        $auto_suggest = false;
        $auto_mode = SignConnectSettings::openAiAutoSuggestMessage();

        $html = '<section class="smbb-signconnect-send-step">';
        $html .= '<h3>' . esc_html__('Send', 'smbb-signconnect') . '</h3>';
        $html .= '<form class="smbb-signconnect-send-form" data-signconnect-send-form data-ai-auto-suggest="' . esc_attr($auto_suggest ? '1' : '0') . '" data-ai-auto-replace="' . esc_attr($has_document_message ? '0' : '1') . '">';
        $html .= '<input type="hidden" name="document_id" value="' . esc_attr((string) $document_id) . '">';
        $html .= '<input type="hidden" name="_wpnonce" value="' . esc_attr(wp_create_nonce('smbb_signconnect_prepare_send_' . $document_id)) . '">';
        $html .= $this->channelFields($document, $send_channel, $sms_enabled);
        $html .= $this->messageField($document, $auto_mode);
        $html .= $this->returnExpectedFields($document);
        $html .= $this->securityFields($document, $expiration_days);
        $html .= '<div class="smbb-signconnect-send-actions"><button type="submit" class="button"><span>' . esc_html__('Envoyer', 'smbb-signconnect') . '</span>' . FrontIcons::icon('arrow-right') . '</button></div>';
        $html .= '<div data-signconnect-send-message></div>';
        $html .= '</form>';
        $html .= '</section>';

        return $html;
    }

    private function channelFields(array $document, $send_channel, $sms_enabled)
    {
        $html = '<div class="smbb-signconnect-channel-grid">';
        $html .= '<label class="smbb-signconnect-channel-card smbb-signconnect-radio-card">';
        $html .= '<input type="radio" name="send_channel" value="email"' . checked($send_channel, 'email', false) . ' data-send-channel>';
        $html .= '<span class="smbb-signconnect-choice-dot" aria-hidden="true"></span>';
        $html .= '<span class="smbb-signconnect-card-title">' . FrontIcons::icon('mail') . esc_html__('Email', 'smbb-signconnect') . '</span>';
        $html .= '<input type="email" name="recipient_email" value="' . esc_attr(isset($document['recipient_email']) ? (string) $document['recipient_email'] : '') . '" placeholder="' . esc_attr__('email@example.com', 'smbb-signconnect') . '" data-send-email>';
        $html .= '</label>';

        if ($sms_enabled) {
            $html .= '<label class="smbb-signconnect-channel-card smbb-signconnect-radio-card">';
            $html .= '<input type="radio" name="send_channel" value="sms"' . checked($send_channel, 'sms', false) . ' data-send-channel>';
            $html .= '<span class="smbb-signconnect-choice-dot" aria-hidden="true"></span>';
            $html .= '<span class="smbb-signconnect-card-title">' . FrontIcons::icon('message') . esc_html__('SMS', 'smbb-signconnect') . '</span>';
            $html .= '<input type="tel" name="recipient_phone" value="' . esc_attr(isset($document['recipient_phone']) ? (string) $document['recipient_phone'] : '') . '" placeholder="' . esc_attr__('Phone number', 'smbb-signconnect') . '" data-send-phone>';
            $html .= '</label>';
        }

        $html .= '</div>';

        return $html;
    }

    private function returnExpectedFields(array $document)
    {
        $return_expected = isset($document['return_expected']) && (string) $document['return_expected'] === 'approval_refusal' ? 'approval_refusal' : 'signature';

        $html = '<div class="smbb-signconnect-field">';
        $html .= '<span>' . esc_html__('Expected response', 'smbb-signconnect') . '</span>';
        $html .= '<div class="smbb-signconnect-channel-grid">';
        $html .= '<label class="smbb-signconnect-channel-card smbb-signconnect-radio-card">';
        $html .= '<input type="radio" name="return_expected" value="signature"' . checked($return_expected, 'signature', false) . '>';
        $html .= '<span class="smbb-signconnect-choice-dot" aria-hidden="true"></span>';
        $html .= '<span class="smbb-signconnect-card-title">' . FrontIcons::icon('check') . esc_html__('Signature', 'smbb-signconnect') . '</span>';
        $html .= '</label>';
        $html .= '<label class="smbb-signconnect-channel-card smbb-signconnect-radio-card">';
        $html .= '<input type="radio" name="return_expected" value="approval_refusal"' . checked($return_expected, 'approval_refusal', false) . '>';
        $html .= '<span class="smbb-signconnect-choice-dot" aria-hidden="true"></span>';
        $html .= '<span class="smbb-signconnect-card-title">' . FrontIcons::icon('message') . esc_html__('Good for approval/refusal', 'smbb-signconnect') . '</span>';
        $html .= '</label>';
        $html .= '</div>';
        $html .= '</div>';

        return $html;
    }

    private function messageField(array $document, $auto_suggest)
    {
        /*
         * Tant que le document n\'a pas son propre message, on injecte le message
         * par défaut configure dans les réglages SignConnect.
         */
        if (isset($document['send_message']) && (string) $document['send_message'] !== '') {
            $message = (string) $document['send_message'];
        } elseif ($auto_suggest) {
            $message = '';
        } else {
            $message = SignConnectSettings::defaultSendMessage();
        }

        $html = '<div class="smbb-signconnect-field">';
        $html .= '<div class="smbb-signconnect-field-header"><span>' . esc_html__('Message', 'smbb-signconnect') . '</span>';

        if (SignConnectSettings::openAiConfigured() && !SignConnectSettings::openAiAutoSuggestMessage()) {
            $html .= '<button type="button" class="button is-secondary" data-signconnect-ai-message>' . esc_html__('Suggest with AI', 'smbb-signconnect') . '</button>';
        }

        $html .= '</div>';
        $html .= '<textarea name="send_message" rows="5" data-signconnect-send-textarea>' . esc_textarea($message) . '</textarea>';
        $html .= '</div>';

        return $html;
    }

    private function securityFields(array $document, $expiration_days)
    {
        $html = '<div class="smbb-signconnect-security-box">';
        $html .= '<h4>' . esc_html__('Security', 'smbb-signconnect') . '</h4>';
        $html .= '<label class="smbb-signconnect-checkbox-card">';
        $html .= '<input type="checkbox" name="require_identity_photo" value="1"' . checked(!empty($document['require_identity_photo']), true, false) . '>';
        $html .= '<span class="smbb-signconnect-checkmark" aria-hidden="true"></span>';
        $html .= '<span class="smbb-signconnect-checkbox-copy"><strong>' . esc_html__('Identity photo', 'smbb-signconnect') . '</strong><small>' . esc_html__('Require a photo when signing.', 'smbb-signconnect') . '</small></span>';
        $html .= '</label>';
        $html .= '<label class="smbb-signconnect-field is-inline"><span>' . esc_html__('Link expiration, in days', 'smbb-signconnect') . '</span><input type="number" name="expiration_days" min="' . esc_attr((string) SignConnectSettings::minExpirationDays()) . '" max="' . esc_attr((string) SignConnectSettings::maxExpirationDays()) . '" value="' . esc_attr((string) $expiration_days) . '"></label>';
        $html .= '</div>';

        return $html;
    }

    private function expirationDays(array $document)
    {
        if (empty($document['link_expires_at'])) {
            return SignConnectSettings::defaultExpirationDays();
        }

        $expires_at = strtotime((string) $document['link_expires_at']);
        $now = current_time('timestamp');

        if (!$expires_at || $expires_at <= $now) {
            return SignConnectSettings::defaultExpirationDays();
        }

        return SignConnectSettings::clampExpiration((int) ceil(($expires_at - $now) / DAY_IN_SECONDS));
    }
}
