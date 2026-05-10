<?php

namespace Smbb\SignConnect\Shortcode;

use Smbb\SignConnect\Support\SignConnectSettings;
use Smbb\SignConnect\Support\SignatureFieldType;

defined('ABSPATH') || exit;

/**
 * Socle commun des shortcodes front SignConnect.
 *
 * Les deux parcours front ont des responsabilites differentes :
 * - SignConnectPostShortcode : dépôt et préparation par le propriétaire ;
 * - SignConnectSignShortcode : lecture publique et signature par le destinataire.
 *
 * Cette classe garde uniquement le chrome partage : assets, wrapper et notices.
 */
abstract class AbstractFrontShortcode
{
    protected function enqueueAssets()
    {
        $ai_upload_messages = array(
            __('AI analysis...', 'smbb-signconnect'),
            __('Document summary...', 'smbb-signconnect'),
        );

        if (SignConnectSettings::openAiSuggestSignatureZone()) {
            $ai_upload_messages[] = __('Signature area detection...', 'smbb-signconnect');
        }

        wp_enqueue_style(
            'smbb-signconnect-front',
            SMBB_SIGNCONNECT_URL . 'assets/front.css',
            array(),
            SMBB_SIGNCONNECT_VERSION
        );

        wp_enqueue_script(
            'smbb-signconnect-front',
            SMBB_SIGNCONNECT_URL . 'assets/front.js',
            array(),
            SMBB_SIGNCONNECT_VERSION,
            true
        );

        foreach (array(
            'front-upload' => 'front-upload.js',
            'front-send' => 'front-send.js',
            'front-dashboard' => 'front-dashboard.js',
            'front-public-sign' => 'front-public-sign.js',
        ) as $handle_suffix => $filename) {
            wp_enqueue_script(
                'smbb-signconnect-' . $handle_suffix,
                SMBB_SIGNCONNECT_URL . 'assets/' . $filename,
                array('smbb-signconnect-front'),
                SMBB_SIGNCONNECT_VERSION,
                true
            );
        }

        wp_localize_script('smbb-signconnect-front', 'SmbbSignConnect', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'uploading' => __('Document upload in progress...', 'smbb-signconnect'),
            'aiEnabled' => SignConnectSettings::openAiAutoSuggestMessage() || SignConnectSettings::openAiSuggestSignatureZone(),
            'aiUploadMessages' => $ai_upload_messages,
            'redirectDelay' => 250,
            'sendSuccessTitles' => array(
                __('Well done!', 'smbb-signconnect'),
                __('Here we go!', 'smbb-signconnect'),
                __('Request sent!', 'smbb-signconnect'),
                __('Perfect!', 'smbb-signconnect'),
                __('There we go!', 'smbb-signconnect'),
                __('Mission started!', 'smbb-signconnect'),
            ),
            'publicThanksTitles' => array(
                __('Thank you for your response.', 'smbb-signconnect'),
                __('Response sent.', 'smbb-signconnect'),
                __('Your response has been recorded.', 'smbb-signconnect'),
                __('Thanks, it is transmitted.', 'smbb-signconnect'),
            ),
            'i18n' => array(
                'sendSuccessMessage' => __('Your signature request has been sent.', 'smbb-signconnect'),
                'resendSent' => __('Reminder sent.', 'smbb-signconnect'),
                'resendFailed' => __('Reminder impossible.', 'smbb-signconnect'),
                'geolocationUnavailable' => __('Geolocation is not available on this device.', 'smbb-signconnect'),
                'geolocationFailed' => __('Geolocation was refused or failed.', 'smbb-signconnect'),
                'gpsSaved' => __('GPS coordinates saved. The city name will be enriched later.', 'smbb-signconnect'),
                'pleaseSign' => __('Please sign in the expected area.', 'smbb-signconnect'),
                'documentSigned' => __('Document signed.', 'smbb-signconnect'),
                'publicThanksTitle' => __('Thank you for your response.', 'smbb-signconnect'),
                'publicThanksMessage' => __('Your response has been transmitted.', 'smbb-signconnect'),
                'downloadPdf' => __('Download PDF', 'smbb-signconnect'),
                'signatureZoneLabel' => __('Your signature will appear here', 'smbb-signconnect'),
                'drawModeOn' => __('Drawing mode enabled: draw an area with one finger.', 'smbb-signconnect'),
                'drawModeOff' => __('Drawing mode disabled: you can scroll the page.', 'smbb-signconnect'),
                'geolocate' => __('Geolocate', 'smbb-signconnect'),
                'publicPdfError' => __('The PDF cannot be displayed. You can still download it after signing.', 'smbb-signconnect'),
                'pdfLoadError' => __('The PDF cannot be loaded.', 'smbb-signconnect'),
                'areaIgnored' => __('Area ignored: draw a larger rectangle.', 'smbb-signconnect'),
                'areasSaveFailed' => __('The areas could not be saved.', 'smbb-signconnect'),
                'messageSuggestion' => __('Message suggestion...', 'smbb-signconnect'),
                'locating' => __('Locating...', 'smbb-signconnect'),
                'cityLookup' => __('City...', 'smbb-signconnect'),
                'suggestWithAi' => __('Suggest with AI', 'smbb-signconnect'),
                'fieldType' => __('Field type', 'smbb-signconnect'),
                'fieldTypeLabels' => SignatureFieldType::labels(),
                'fieldTypePublicLabels' => array(
                    SignatureFieldType::SIGNATURE => __('Your signature will appear here', 'smbb-signconnect'),
                    SignatureFieldType::LAST_NAME => __('Your last name will appear here', 'smbb-signconnect'),
                    SignatureFieldType::FIRST_NAME => __('Your first name will appear here', 'smbb-signconnect'),
                    SignatureFieldType::FULL_NAME => __('Your full name will appear here', 'smbb-signconnect'),
                    SignatureFieldType::PLACE => __('The signing place will appear here', 'smbb-signconnect'),
                    SignatureFieldType::DATE => __('The signing date will appear here', 'smbb-signconnect'),
                    SignatureFieldType::APPROVAL => __('Good for approval will appear here', 'smbb-signconnect'),
                ),
            ),
        ));
    }

    protected function wrap($html, $class = '')
    {
        $classes = 'smbb-signconnect-post';
        $brand_color = SignConnectSettings::brandColor();

        if ((string) $class !== '') {
            $classes .= ' ' . sanitize_html_class((string) $class);
        }

        return '<div class="' . esc_attr($classes) . '" style="--smbb-signconnect-brand: ' . esc_attr($brand_color) . ';">' . $html . '</div>';
    }

    protected function renderNotice($type, $message)
    {
        $type = in_array($type, array('success', 'error', 'info'), true) ? $type : 'info';

        return '<div class="smbb-signconnect-notice is-' . esc_attr($type) . '"><p>' . esc_html((string) $message) . '</p></div>';
    }
}
