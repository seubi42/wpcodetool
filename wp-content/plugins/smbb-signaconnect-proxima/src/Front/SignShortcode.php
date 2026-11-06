<?php

namespace Smbb\SignConnectProxima\Front;

use Smbb\SignConnectProxima\Repository\Documents;
use Smbb\SignConnectProxima\Repository\Organizations;
use Smbb\SignConnectProxima\Service\SigningService;

defined('ABSPATH') || exit;

/**
 * [signaconnect_signature] : page publique où le signataire lit, signe ou refuse.
 */
final class SignShortcode
{
    const TAG = 'signaconnect_signature';

    /** Ancien nom (avant 0.9.1), toujours reconnu dans les pages existantes. */
    const LEGACY_TAG = 'signconnect_signature';

    public static function hooks()
    {
        // Le lien contient un jeton : pas d'indexation, pas de fuite par le referer.
        add_action('wp_head', array(__CLASS__, 'privacyHeaders'), 1);
    }

    public static function privacyHeaders()
    {
        if (isset($_GET['scp_doc'])) {
            echo '<meta name="robots" content="noindex, nofollow">' . "\n" . '<meta name="referrer" content="no-referrer">' . "\n";
        }
    }

    public static function render()
    {
        $source = array(
            'scp_doc' => isset($_GET['scp_doc']) ? sanitize_text_field(wp_unslash($_GET['scp_doc'])) : '',
            'scp_t' => isset($_GET['scp_t']) ? sanitize_text_field(wp_unslash($_GET['scp_t'])) : '',
        );

        if ($source['scp_doc'] === '') {
            return Assets::wrap(Assets::message(__('Signature électronique', 'smbb-signaconnect-proxima'), __('Ouvrez le lien reçu par email ou SMS pour accéder à votre document.', 'smbb-signaconnect-proxima')), 'scp-sign');
        }

        $resolved = SigningService::resolve($source['scp_doc'], $source['scp_t']);
        $document = $resolved['document'];
        $organization = $resolved['organization'];
        $color = $organization ? Organizations::brandColor($organization) : '';

        switch ($resolved['state']) {
            case 'invalid':
                return Assets::wrap(Assets::message(__('Lien invalide', 'smbb-signaconnect-proxima'), __('Ce lien de signature est incomplet ou n\'existe plus. Vérifiez que vous avez copié le lien en entier.', 'smbb-signaconnect-proxima'), 'error'), 'scp-sign');

            case 'blocked':
                return Assets::wrap(self::header($organization, $document) . Assets::message(__('Document momentanément indisponible', 'smbb-signaconnect-proxima'), __("Contactez la personne qui vous l'a envoyé.", 'smbb-signaconnect-proxima'), 'warning'), 'scp-sign', $color);

            case 'expired':
                return Assets::wrap(self::header($organization, $document) . Assets::message(__('Ce lien a expiré', 'smbb-signaconnect-proxima'), __("Demandez un nouvel envoi à la personne qui vous l'a adressé.", 'smbb-signaconnect-proxima'), 'warning'), 'scp-sign', $color);

            case 'signed':
                return Assets::wrap(self::header($organization, $document) . Assets::message(__('Document signé', 'smbb-signaconnect-proxima'), sprintf(__('Signé le %s. Une copie vous a été envoyée par email.', 'smbb-signaconnect-proxima'), mysql2date('d/m/Y à H:i', $document['signed_at'])), 'success')
                    . '<div class="scp-actions scp-actions--center"><a class="scp-button" href="' . esc_url(Ajax::publicPdfUrl($source, true)) . '">' . esc_html__('Télécharger le document signé', 'smbb-signaconnect-proxima') . '</a></div>', 'scp-sign', $color);

            case 'refused':
                return Assets::wrap(self::header($organization, $document) . Assets::message(__('Signature refusée', 'smbb-signaconnect-proxima'), __("Votre refus a été transmis à l'expéditeur.", 'smbb-signaconnect-proxima')), 'scp-sign', $color);

            case 'closed':
                return Assets::wrap(self::header($organization, $document) . Assets::message(__('Ce document n\'attend plus de signature', 'smbb-signaconnect-proxima'), ''), 'scp-sign', $color);
        }

        SigningService::markOpened($document);
        $fields = array_map(function ($field) {
            return array(
                'page_number' => (int) $field['page_number'],
                'x' => (float) $field['x'],
                'y' => (float) $field['y'],
                'width' => (float) $field['width'],
                'height' => (float) $field['height'],
                'field_type' => (string) $field['field_type'],
            );
        }, Documents::fields($document['id']));
        $signer_name = Documents::recipientName($document);
        $place = $organization ? Organizations::city($organization, isset($document['team_id']) ? (int) $document['team_id'] : 0) : '';

        Assets::enqueue('sign', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'doc' => $source['scp_doc'],
            'token' => $source['scp_t'],
            'pdfUrl' => Ajax::publicPdfUrl($source),
            'fields' => $fields,
            'i18n' => array(
                'signHere' => __('Signez ici', 'smbb-signaconnect-proxima'),
                'name' => __('Votre nom', 'smbb-signaconnect-proxima'),
                'date' => __('Date', 'smbb-signaconnect-proxima'),
                'place' => $place !== '' ? $place : __('Lieu', 'smbb-signaconnect-proxima'),
                'stamp' => __('Tampon', 'smbb-signaconnect-proxima'),
                'signing' => __('Signature en cours…', 'smbb-signaconnect-proxima'),
                'error' => __('Une erreur est survenue. Réessayez.', 'smbb-signaconnect-proxima'),
                'page' => __('Page', 'smbb-signaconnect-proxima'),
            ),
        ));

        $html = self::header($organization, $document);
        $html .= '<div class="scp-viewer scp-viewer--read" data-scp-viewer></div>';
        $html .= '<section class="scp-card scp-sign-panel" data-scp-sign-panel>';
        $html .= '<h2>' . esc_html__('Signer ce document', 'smbb-signaconnect-proxima') . '</h2>';
        $html .= '<form data-scp-sign-form novalidate>';
        $html .= '<label class="scp-field"><span>' . esc_html__('Nom complet', 'smbb-signaconnect-proxima') . '</span><input name="signer_name" value="' . esc_attr($signer_name) . '" autocomplete="name" required></label>';
        $html .= '<div class="scp-field"><span>' . esc_html__('Votre signature', 'smbb-signaconnect-proxima') . '</span>'
            . '<div class="scp-pad"><canvas data-scp-pad aria-label="' . esc_attr__('Zone de signature', 'smbb-signaconnect-proxima') . '"></canvas><button type="button" class="scp-link" data-scp-clear>' . esc_html__('Effacer', 'smbb-signaconnect-proxima') . '</button></div>'
            . '<small class="scp-muted">' . esc_html__('Dessinez avec la souris ou le doigt.', 'smbb-signaconnect-proxima') . '</small></div>';
        $html .= '<label class="scp-check"><input type="checkbox" name="consent" value="1"> <span>' . esc_html__("J'ai lu le document et j'accepte de le signer électroniquement.", 'smbb-signaconnect-proxima') . '</span></label>';
        $html .= '<div class="scp-alert" data-scp-message hidden></div>';
        $html .= '<div class="scp-actions"><button type="button" class="scp-button scp-button--ghost" data-scp-refuse-toggle>' . esc_html__('Refuser', 'smbb-signaconnect-proxima') . '</button>'
            . '<button type="submit" class="scp-button">' . esc_html__('Signer', 'smbb-signaconnect-proxima') . '</button></div>';
        $html .= '</form>';
        $html .= '<form class="scp-refuse" data-scp-refuse-form hidden novalidate>';
        $html .= '<label class="scp-field"><span>' . esc_html__('Pourquoi refusez-vous de signer ?', 'smbb-signaconnect-proxima') . '</span><textarea name="message" rows="3" required></textarea></label>';
        $html .= '<div class="scp-actions"><button type="button" class="scp-button scp-button--ghost" data-scp-refuse-toggle>' . esc_html__('Retour', 'smbb-signaconnect-proxima') . '</button>'
            . '<button type="submit" class="scp-button scp-button--danger">' . esc_html__('Confirmer le refus', 'smbb-signaconnect-proxima') . '</button></div>';
        $html .= '</form>';
        $html .= '</section>';
        $html .= '<section class="scp-card" data-scp-sign-done hidden>' . Assets::message(__('Merci, c\'est signé !', 'smbb-signaconnect-proxima'), __('Une copie du document signé vous a été envoyée par email.', 'smbb-signaconnect-proxima'), 'success')
            . '<div class="scp-actions scp-actions--center"><a class="scp-button" data-scp-download href="#">' . esc_html__('Télécharger le document signé', 'smbb-signaconnect-proxima') . '</a></div></section>';
        $html .= '<section class="scp-card" data-scp-refuse-done hidden>' . Assets::message(__('Refus transmis', 'smbb-signaconnect-proxima'), __("L'expéditeur a été prévenu.", 'smbb-signaconnect-proxima')) . '</section>';

        return Assets::wrap($html, 'scp-sign', $color);
    }

    private static function header($organization, $document)
    {
        if (!$organization || !$document) {
            return '';
        }

        $logo = !empty($organization['logo_attachment_id']) ? wp_get_attachment_image_url((int) $organization['logo_attachment_id'], 'medium') : '';
        $sender = !empty($document['sender_id']) ? get_userdata((int) $document['sender_id']) : false;

        return '<header class="scp-sign-header">'
            . ($logo ? '<img class="scp-logo" src="' . esc_url($logo) . '" alt="' . esc_attr($organization['name']) . '">' : '<span class="scp-org">' . esc_html($organization['name']) . '</span>')
            . '<div><h1>' . esc_html($document['filename']) . '</h1>'
            . ($sender ? '<p class="scp-muted">' . esc_html(sprintf(__('Envoyé par %1$s · %2$s', 'smbb-signaconnect-proxima'), $sender->display_name, $organization['name'])) . '</p>' : '')
            . '</div></header>';
    }
}
