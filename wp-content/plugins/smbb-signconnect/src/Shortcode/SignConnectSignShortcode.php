<?php

namespace Smbb\SignConnect\Shortcode;

use Smbb\SignConnect\Repository\DocumentRepository;
use Smbb\SignConnect\Repository\SignatureFieldRepository;
use Smbb\SignConnect\Repository\StorageRepository;
use Smbb\SignConnect\Service\PublicSignatureService;
use Smbb\SignConnect\Service\S3DocumentStreamer;
use Smbb\SignConnect\Support\PublicSigningLink;
use Smbb\SignConnect\Support\SignConnectSettings;

defined('ABSPATH') || exit;

/**
 * Shortcode public [signconnect_sign].
 *
 * Ce shortcode est destine a la page envoyee au signataire. Il ne demande pas
 * de connexion WordPress : toute la sécurité repose sur le couple id encodé +
 * token, puis sur l'expiration du lien.
 */
final class SignConnectSignShortcode extends AbstractFrontShortcode
{
    private $storages;
    private $documents;
    private $signature_fields;
    private $streamer;
    private $public_signature;

    public function __construct(
        StorageRepository $storages = null,
        DocumentRepository $documents = null,
        S3DocumentStreamer $streamer = null,
        PublicSignatureService $public_signature = null,
        SignatureFieldRepository $signature_fields = null
    ) {
        $this->storages = $storages ?: new StorageRepository();
        $this->documents = $documents ?: new DocumentRepository();
        $this->signature_fields = $signature_fields ?: new SignatureFieldRepository();
        $this->streamer = $streamer ?: new S3DocumentStreamer();
        $this->public_signature = $public_signature ?: new PublicSignatureService($this->documents);
    }

    public function hooks()
    {
        add_shortcode('signconnect_sign', array($this, 'render'));
        add_action('wp_ajax_smbb_signconnect_public_pdf_document', array($this, 'handlePublicPdfDocument'));
        add_action('wp_ajax_nopriv_smbb_signconnect_public_pdf_document', array($this, 'handlePublicPdfDocument'));
        add_action('wp_ajax_smbb_signconnect_public_sign_document', array($this, 'handlePublicSignDocument'));
        add_action('wp_ajax_nopriv_smbb_signconnect_public_sign_document', array($this, 'handlePublicSignDocument'));
        add_action('wp_ajax_smbb_signconnect_geodecode', array($this, 'handleGeodecode'));
        add_action('wp_ajax_nopriv_smbb_signconnect_geodecode', array($this, 'handleGeodecode'));
    }

    public function render()
    {
        $this->enqueueAssets();

        return $this->wrap($this->renderPublicSigning(), 'is-public-sign-mode');
    }

    public function handlePublicPdfDocument()
    {
        $document_id = isset($_GET['document_id']) ? PublicSigningLink::decodeDocumentId((string) $_GET['document_id']) : 0;
        $token = isset($_GET['token']) ? sanitize_text_field((string) wp_unslash($_GET['token'])) : '';
        $document = $this->publicDocument($document_id, $token);

        if (!$document) {
            status_header(403);
            wp_die(esc_html__('Invalid PDF link.', 'smbb-signconnect'));
        }

        if ($this->publicDocumentExpired($document)) {
            status_header(410);
            wp_die(esc_html__('This signature link has expired.', 'smbb-signconnect'));
        }

        $storage = $this->storages->find(isset($document['storage_id']) ? (int) $document['storage_id'] : 0);

        if (!$storage || empty($document['storage_path'])) {
            wp_die(esc_html__('The associated file is unavailable.', 'smbb-signconnect'));
        }

        $disposition = !empty($_GET['download']) ? 'attachment' : 'inline';

        try {
            $this->streamer->stream($storage, $document, $disposition);
        } catch (\Throwable $exception) {
            wp_die(esc_html($exception->getMessage()));
        }
    }

    public function handlePublicSignDocument()
    {
        $document_id = isset($_POST['document_id']) ? PublicSigningLink::decodeDocumentId((string) wp_unslash($_POST['document_id'])) : 0;
        $token = isset($_POST['token']) ? sanitize_text_field((string) wp_unslash($_POST['token'])) : '';
        $nonce = isset($_POST['_wpnonce']) ? (string) wp_unslash($_POST['_wpnonce']) : '';

        if ($document_id < 1 || $token === '' || !wp_verify_nonce($nonce, 'smbb_signconnect_public_sign_' . $document_id . '_' . $token)) {
            wp_send_json_error(array('message' => __('Invalid signature link.', 'smbb-signconnect')), 403);
        }

        $result = $this->public_signature->signFromPost($document_id, $token, $_POST, $_FILES);

        if (empty($result['success'])) {
            wp_send_json_error(
                array('message' => isset($result['message']) ? $result['message'] : __('Signature failed.', 'smbb-signconnect')),
                isset($result['status_code']) ? (int) $result['status_code'] : 400
            );
        }

        wp_send_json_success(array(
            'message' => isset($result['message']) ? $result['message'] : __('Document signed successfully.', 'smbb-signconnect'),
        ));
    }

    public function handleGeodecode()
    {
        $document_id = isset($_POST['document_id']) ? PublicSigningLink::decodeDocumentId((string) wp_unslash($_POST['document_id'])) : 0;
        $token = isset($_POST['token']) ? sanitize_text_field((string) wp_unslash($_POST['token'])) : '';
        $nonce = isset($_POST['_wpnonce']) ? (string) wp_unslash($_POST['_wpnonce']) : '';

        if ($document_id < 1 || $token === '' || !wp_verify_nonce($nonce, 'smbb_signconnect_public_sign_' . $document_id . '_' . $token)) {
            wp_send_json_error(array('message' => __('Invalid signature link.', 'smbb-signconnect')), 403);
        }

        if (!SignConnectSettings::geodecodeEnabled()) {
            wp_send_json_error(array('message' => __('The geolocation service is disabled.', 'smbb-signconnect')), 400);
        }

        $document = $this->publicDocument($document_id, $token);

        if (!$document || $this->publicDocumentExpired($document)) {
            wp_send_json_error(array('message' => __('Invalid or expired signature link.', 'smbb-signconnect')), 403);
        }

        $latitude = isset($_POST['latitude']) ? sanitize_text_field((string) wp_unslash($_POST['latitude'])) : '';
        $longitude = isset($_POST['longitude']) ? sanitize_text_field((string) wp_unslash($_POST['longitude'])) : '';

        if (!class_exists('\Smbb\WpCodeTool\Connector\SmbbGeoDecodeClient')) {
            wp_send_json_error(array('message' => __('The geolocation connector is unavailable.', 'smbb-signconnect')), 500);
        }

        try {
            $client = new \Smbb\WpCodeTool\Connector\SmbbGeoDecodeClient();
            $result = $client->decode($latitude, $longitude);
        } catch (\Throwable $exception) {
            wp_send_json_error(array('message' => $exception->getMessage()), 500);
        }

        wp_send_json_success(array(
            'city' => isset($result['city']) && $result['city'] !== '' ? (string) $result['city'] : 'gps',
        ));
    }

    private function renderPublicSigning()
    {
        $encoded_id = isset($_GET['signconnect_sign']) ? sanitize_text_field((string) wp_unslash($_GET['signconnect_sign'])) : '';
        $token = isset($_GET['signconnect_token']) ? sanitize_text_field((string) wp_unslash($_GET['signconnect_token'])) : '';
        $document_id = PublicSigningLink::decodeDocumentId($encoded_id);
        $document = $this->publicDocument($document_id, $token);

        if (!$document) {
            return $this->renderNotice('error', __('Invalid signature link.', 'smbb-signconnect'));
        }

        if ($this->publicDocumentExpired($document)) {
            return $this->renderNotice('error', __('This signature link has expired.', 'smbb-signconnect'));
        }

        $pdf_url = add_query_arg(array(
            'action' => 'smbb_signconnect_public_pdf_document',
            'document_id' => $encoded_id,
            'token' => rawurlencode($token),
        ), admin_url('admin-ajax.php'));
        $download_url = add_query_arg('download', '1', $pdf_url);
        $fields = $this->publicSignatureFields($document_id);

        if (!empty($document['sign_date'])) {
            $html = '<section class="smbb-signconnect-public-sign">';
            $html .= '<section class="smbb-signconnect-thank-you">';
            $html .= '<h3>' . esc_html__('Thank you, the document is signed.', 'smbb-signconnect') . '</h3>';
            $html .= '<p>' . esc_html__('You can download the PDF below.', 'smbb-signconnect') . '</p>';
            $html .= '<a class="button" href="' . esc_url($download_url) . '">' . esc_html__('Download PDF', 'smbb-signconnect') . '</a>';
            $html .= '</section>';
            $html .= '</section>';

            return $html;
        }
        $nonce = wp_create_nonce('smbb_signconnect_public_sign_' . $document_id . '_' . $token);

        $html = '<section class="smbb-signconnect-public-sign">';
        $html .= '<h3>' . esc_html((string) $document['filename']) . '</h3>';
        $html .= '<div class="smbb-signconnect-public-pdf-viewer" data-signconnect-public-pdf data-pdf-url="' . esc_url($pdf_url) . '" data-signature-fields="' . esc_attr(wp_json_encode($fields)) . '">';
        $html .= '<div class="smbb-signconnect-public-pdf-status" data-signconnect-public-pdf-status><span class="smbb-signconnect-spinner" aria-hidden="true"></span><span>' . esc_html__('PDF loading...', 'smbb-signconnect') . '</span></div>';
        $html .= '<div class="smbb-signconnect-public-pdf-pages" data-signconnect-public-pdf-pages></div>';
        $html .= '</div>';
        $return_expected = isset($document['return_expected']) && (string) $document['return_expected'] === 'approval_refusal' ? 'approval_refusal' : 'signature';
        $html .= '<form class="smbb-signconnect-public-sign-form" data-signconnect-public-sign-form data-download-url="' . esc_url($download_url) . '" data-geodecode-enabled="' . esc_attr(SignConnectSettings::geodecodeEnabled() ? '1' : '0') . '" data-return-expected="' . esc_attr($return_expected) . '">';
        $html .= '<input type="hidden" name="document_id" value="' . esc_attr($encoded_id) . '">';
        $html .= '<input type="hidden" name="token" value="' . esc_attr($token) . '">';
        $html .= '<input type="hidden" name="_wpnonce" value="' . esc_attr($nonce) . '">';
        $html .= '<input type="hidden" name="signature_data" data-signconnect-signature-data>';
        if ($return_expected === 'approval_refusal') {
            $html .= '<div class="smbb-signconnect-field"><span>' . esc_html__('Your response', 'smbb-signconnect') . '</span><div class="smbb-signconnect-channel-grid">';
            $html .= '<label class="smbb-signconnect-channel-card"><input type="radio" name="signer_return_status" value="approved" checked data-signconnect-return-choice><span>' . esc_html__('Good for approval', 'smbb-signconnect') . '</span></label>';
            $html .= '<label class="smbb-signconnect-channel-card"><input type="radio" name="signer_return_status" value="refused" data-signconnect-return-choice><span>' . esc_html__('Refusal', 'smbb-signconnect') . '</span></label>';
            $html .= '</div></div>';
            $html .= '<label class="smbb-signconnect-field" data-signconnect-refusal-message hidden><span>' . esc_html__('Refusal message', 'smbb-signconnect') . '</span><textarea name="signer_return_message" rows="4"></textarea></label>';
        } else {
            $html .= '<input type="hidden" name="signer_return_status" value="signed">';
        }
        $html .= '<div class="smbb-signconnect-signer-grid">';
        $html .= '<label class="smbb-signconnect-field"><span>' . esc_html__('First name', 'smbb-signconnect') . '</span><input type="text" name="signer_first_name" autocomplete="given-name" required></label>';
        $html .= '<label class="smbb-signconnect-field"><span>' . esc_html__('Last name', 'smbb-signconnect') . '</span><input type="text" name="signer_last_name" autocomplete="family-name" required></label>';
        $html .= '</div>';
        $html .= '<div class="smbb-signconnect-location-row" data-signconnect-location-row>';
        $html .= '<label class="smbb-signconnect-field"><span>' . esc_html__('Place', 'smbb-signconnect') . '</span><input type="text" name="signer_place" data-signconnect-place placeholder="' . esc_attr__('City or signing place', 'smbb-signconnect') . '" required></label>';
        if (SignConnectSettings::geodecodeEnabled()) {
            $html .= '<button type="button" class="button is-secondary" data-signconnect-geolocate>' . esc_html__('Geolocate', 'smbb-signconnect') . '</button>';
        }
        $html .= '</div>';
        $html .= '<input type="hidden" name="signer_latitude" data-signconnect-latitude>';
        $html .= '<input type="hidden" name="signer_longitude" data-signconnect-longitude>';
        if (!empty($document['require_identity_photo'])) {
            $photo_input_id = 'smbb-signconnect-identity-photo-' . absint($document_id);
            $html .= '<div class="smbb-signconnect-field smbb-signconnect-photo-field" data-signconnect-identity-photo-field>';
            $html .= '<span>' . esc_html__('Identity photo', 'smbb-signconnect') . '</span>';
            $html .= '<input id="' . esc_attr($photo_input_id) . '" class="smbb-signconnect-photo-native-input" type="file" name="identity_photo" accept="image/png,image/jpeg" required data-signconnect-identity-photo-input>';
            $html .= '<small data-signconnect-identity-photo-label>' . esc_html__('JPG or PNG photo, added as an appendix to the signed PDF.', 'smbb-signconnect') . '</small>';
            $html .= '<img class="smbb-signconnect-photo-preview" alt="' . esc_attr__('Identity photo preview', 'smbb-signconnect') . '" data-signconnect-identity-photo-preview hidden>';
            $html .= '</div>';
        }
        $html .= '<label class="smbb-signconnect-field" data-signconnect-signature-field><span>' . esc_html__('Signature', 'smbb-signconnect') . '</span><canvas class="smbb-signconnect-signature-pad" width="720" height="220" tabindex="0" data-signconnect-signature-pad></canvas></label>';
        $html .= '<div class="smbb-signconnect-document-actions"><button type="button" class="button is-secondary" data-signconnect-clear-signature>' . esc_html__('Clear', 'smbb-signconnect') . '</button><button type="submit" class="button">' . esc_html__('Sign document', 'smbb-signconnect') . '</button></div>';
        $html .= '<div data-signconnect-public-sign-message></div>';
        $html .= '</form>';
        $html .= '</section>';

        return $html;
    }

    private function publicDocument($document_id, $token)
    {
        if ($document_id < 1 || $token === '') {
            return null;
        }

        return $this->documents->findByPublicToken($document_id, $token);
    }

    private function publicDocumentExpired(array $document)
    {
        if (empty($document['link_expires_at'])) {
            return false;
        }

        $expires_at = strtotime((string) $document['link_expires_at']);

        return $expires_at && $expires_at <= current_time('timestamp');
    }

    private function publicSignatureFields($document_id)
    {
        return array_map(function ($field) {
            return array(
                'id' => (int) $field['id'],
                'page_number' => (int) $field['page_number'],
                'x' => (float) $field['x'],
                'y' => (float) $field['y'],
                'width' => (float) $field['width'],
                'height' => (float) $field['height'],
                'label' => isset($field['label']) ? (string) $field['label'] : __('Signature', 'smbb-signconnect'),
            );
        }, $this->signature_fields->listForDocument($document_id));
    }
}
