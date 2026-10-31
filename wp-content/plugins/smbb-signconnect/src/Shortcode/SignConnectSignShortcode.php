<?php

namespace Smbb\SignConnect\Shortcode;

use Smbb\SignConnect\Repository\DocumentRepository;
use Smbb\SignConnect\Repository\DocumentAuditRepository;
use Smbb\SignConnect\Repository\SenderSignatureRepository;
use Smbb\SignConnect\Repository\SignatureFieldRepository;
use Smbb\SignConnect\Repository\StampRepository;
use Smbb\SignConnect\Repository\StorageRepository;
use Smbb\SignConnect\Service\PublicSignatureService;
use Smbb\SignConnect\Service\S3DocumentStreamer;
use Smbb\SignConnect\Service\UniversignSignatureFlowService;
use Smbb\SignConnect\Service\YousignSignatureFlowService;
use Smbb\SignConnect\Support\PublicSigningLink;
use Smbb\SignConnect\Support\SignConnectSettings;
use Smbb\SignConnect\Support\SignatureFieldType;
use Smbb\SignConnect\User\UserStorageProfileFields;

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
    private $sender_signatures;
    private $stamps;
    private $streamer;
    private $public_signature;
    private $yousign_signature;
    private $universign_signature;
    private $audit;

    public function __construct(
        StorageRepository $storages = null,
        DocumentRepository $documents = null,
        S3DocumentStreamer $streamer = null,
        PublicSignatureService $public_signature = null,
        YousignSignatureFlowService $yousign_signature = null,
        UniversignSignatureFlowService $universign_signature = null,
        SignatureFieldRepository $signature_fields = null,
        SenderSignatureRepository $sender_signatures = null,
        StampRepository $stamps = null,
        DocumentAuditRepository $audit = null
    ) {
        $this->storages = $storages ?: new StorageRepository();
        $this->documents = $documents ?: new DocumentRepository();
        $this->signature_fields = $signature_fields ?: new SignatureFieldRepository();
        $this->sender_signatures = $sender_signatures ?: new SenderSignatureRepository();
        $this->stamps = $stamps ?: new StampRepository();
        $this->streamer = $streamer ?: new S3DocumentStreamer();
        $this->audit = $audit ?: new DocumentAuditRepository();
        $this->public_signature = $public_signature ?: new PublicSignatureService($this->documents);
        $this->yousign_signature = $yousign_signature ?: new YousignSignatureFlowService($this->documents, $this->storages, $this->signature_fields, $this->audit);
        $this->universign_signature = $universign_signature ?: new UniversignSignatureFlowService($this->documents, $this->storages, $this->signature_fields, $this->audit);
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

        $document = $this->publicDocument($document_id, $token);

        if (!$document || $this->publicDocumentExpired($document)) {
            wp_send_json_error(array('message' => __('Invalid or expired signature link.', 'smbb-signconnect')), 403);
        }

        if (SignConnectSettings::documentUsesYousignSignatureFlow($document)) {
            try {
                $result = $this->yousign_signature->start($document, isset($_POST['document_id']) ? sanitize_text_field((string) wp_unslash($_POST['document_id'])) : '', $token, $_POST);
                wp_send_json_success(array(
                    'redirect_url' => isset($result['signature_link']) ? (string) $result['signature_link'] : '',
                    'message' => __('You will be redirected to Yousign to sign the document.', 'smbb-signconnect'),
                ));
            } catch (\Throwable $exception) {
                $this->documents->appendSystemLog($document_id, 'yousign_signature_start_error', array(
                    'message' => $exception->getMessage(),
                    'class' => get_class($exception),
                ));
                $this->audit->record($document_id, 'yousign_signature_start_error', array(
                    'message' => $exception->getMessage(),
                    'class' => get_class($exception),
                ), 'system', null, __('Yousign signing flow start failed.', 'smbb-signconnect'));
                wp_send_json_error(array(
                    'message' => __('The Yousign signing flow could not be started. Please try again later or contact the sender.', 'smbb-signconnect'),
                ), 500);
            }
        }

        if (SignConnectSettings::documentUsesUniversignSignatureFlow($document)) {
            try {
                $result = $this->universign_signature->start($document, isset($_POST['document_id']) ? sanitize_text_field((string) wp_unslash($_POST['document_id'])) : '', $token, $_POST);
                wp_send_json_success(array(
                    'redirect_url' => isset($result['signature_link']) ? (string) $result['signature_link'] : '',
                    'message' => __('You will be redirected to Universign to sign the document.', 'smbb-signconnect'),
                ));
            } catch (\Throwable $exception) {
                $this->documents->appendSystemLog($document_id, 'universign_signature_start_error', array(
                    'message' => $exception->getMessage(),
                    'class' => get_class($exception),
                ));
                $this->audit->record($document_id, 'universign_signature_start_error', array(
                    'message' => $exception->getMessage(),
                    'class' => get_class($exception),
                ), 'system', null, __('Universign signing flow start failed.', 'smbb-signconnect'));
                wp_send_json_error(array(
                    'message' => __('The Universign signing flow could not be started. Please try again later or contact the sender.', 'smbb-signconnect'),
                ), 500);
            }
        }

        $result = $this->public_signature->signFromPost($document_id, $token, $_POST, $_FILES);

        if (empty($result['success'])) {
            wp_send_json_error(
                array('message' => isset($result['message']) ? $result['message'] : __('Signature failed.', 'smbb-signconnect')),
                isset($result['status_code']) ? (int) $result['status_code'] : 400
            );
        }

        wp_send_json_success(array(
            'message' => isset($result['message']) ? $result['message'] : __('Your response has been transmitted.', 'smbb-signconnect'),
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

        $this->documents->notePublicOpen($document_id);
        $this->audit->record($document_id, 'public_opened', array(
            'filename' => isset($document['filename']) ? (string) $document['filename'] : '',
        ), 'signer', null, __('Public signature page opened.', 'smbb-signconnect'));

        $pdf_url = add_query_arg(array(
            'action' => 'smbb_signconnect_public_pdf_document',
            'document_id' => $encoded_id,
            'token' => rawurlencode($token),
        ), admin_url('admin-ajax.php'));
        $download_url = add_query_arg('download', '1', $pdf_url);
        $fields = $this->publicSignatureFields($document_id, $document);

        if (SignConnectSettings::documentUsesYousignSignatureFlow($document) && !empty($_GET['signconnect_yousign_return']) && empty($document['sign_date'])) {
            $return_key = isset($_GET['signconnect_yousign_key']) ? sanitize_text_field((string) wp_unslash($_GET['signconnect_yousign_key'])) : '';

            try {
                $document = $this->yousign_signature->finalize($document, $token, $return_key);
            } catch (\Throwable $exception) {
                $this->documents->appendSystemLog($document_id, 'yousign_signature_finalize_error', array(
                    'message' => $exception->getMessage(),
                    'class' => get_class($exception),
                ));

                return $this->renderNotice('info', __('The Yousign signature is being finalized. Please refresh this page in a few seconds.', 'smbb-signconnect'));
            }
        }

        if (SignConnectSettings::documentUsesUniversignSignatureFlow($document) && !empty($_GET['signconnect_universign_return']) && empty($document['sign_date'])) {
            $return_key = isset($_GET['signconnect_universign_key']) ? sanitize_text_field((string) wp_unslash($_GET['signconnect_universign_key'])) : '';

            try {
                $document = $this->universign_signature->finalize($document, $token, $return_key);
            } catch (\Throwable $exception) {
                $this->documents->appendSystemLog($document_id, 'universign_signature_finalize_error', array(
                    'message' => $exception->getMessage(),
                    'class' => get_class($exception),
                ));

                return $this->renderNotice('info', __('The Universign signature is being finalized. Please refresh this page in a few seconds.', 'smbb-signconnect'));
            }
        }

        if (!empty($document['sign_date'])) {
            $html = '<section class="smbb-signconnect-public-sign">';
            $html .= '<section class="smbb-signconnect-send-success smbb-signconnect-public-thanks" role="status" aria-live="polite">';
            $html .= '<div class="smbb-signconnect-send-success-mark" aria-hidden="true"><span></span><i></i><i></i><i></i><i></i></div>';
            $html .= '<h3>' . esc_html__('Thank you for your response.', 'smbb-signconnect') . '</h3>';
            $html .= '<p>' . esc_html__('Your response has already been transmitted. You can download the PDF below.', 'smbb-signconnect') . '</p>';
            $html .= $this->renderProofList($document);
            $html .= '<p><a class="button" href="' . esc_url($download_url) . '">' . esc_html__('Download PDF', 'smbb-signconnect') . '</a></p>';
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
        $uses_yousign_signature = SignConnectSettings::documentUsesYousignSignatureFlow($document);
        $uses_universign_signature = SignConnectSettings::documentUsesUniversignSignatureFlow($document);
        $uses_external_signature = $uses_yousign_signature || $uses_universign_signature;
        $html .= '<form class="smbb-signconnect-public-sign-form" data-signconnect-public-sign-form data-download-url="' . esc_url($download_url) . '" data-geodecode-enabled="' . esc_attr(SignConnectSettings::geodecodeEnabled() ? '1' : '0') . '" data-return-expected="' . esc_attr($return_expected) . '" data-yousign-signature-flow="' . esc_attr($uses_external_signature ? '1' : '0') . '">';
        $html .= '<input type="hidden" name="document_id" value="' . esc_attr($encoded_id) . '">';
        $html .= '<input type="hidden" name="token" value="' . esc_attr($token) . '">';
        $html .= '<input type="hidden" name="_wpnonce" value="' . esc_attr($nonce) . '">';
        $html .= '<input type="hidden" name="signature_data" data-signconnect-signature-data>';
        if (!$uses_external_signature && $return_expected === 'approval_refusal') {
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
        if (!$uses_external_signature && !empty($document['require_identity_photo'])) {
            $photo_input_id = 'smbb-signconnect-identity-photo-' . absint($document_id);
            $html .= '<div class="smbb-signconnect-field smbb-signconnect-photo-field" data-signconnect-identity-photo-field>';
            $html .= '<span>' . esc_html__('Identity photo', 'smbb-signconnect') . '</span>';
            $html .= '<input id="' . esc_attr($photo_input_id) . '" class="smbb-signconnect-photo-native-input" type="file" name="identity_photo" accept="image/png,image/jpeg" required data-signconnect-identity-photo-input>';
            $html .= '<small data-signconnect-identity-photo-label>' . esc_html__('JPG or PNG photo, added as an appendix to the signed PDF.', 'smbb-signconnect') . '</small>';
            $html .= '<img class="smbb-signconnect-photo-preview" alt="' . esc_attr__('Identity photo preview', 'smbb-signconnect') . '" data-signconnect-identity-photo-preview hidden>';
            $html .= '</div>';
        }
        if ($uses_external_signature) {
            $provider_name = $uses_universign_signature ? 'Universign' : 'Yousign';
            $disable_return_redirect = $uses_universign_signature
                ? SignConnectSettings::universignDisableReturnRedirect()
                : SignConnectSettings::yousignSignatureDisableReturnRedirect();
            $external_redirect_message = $disable_return_redirect
                ? __('After confirmation, you will be redirected to Yousign to sign this document. Because return redirects are disabled, you will not automatically come back to this page after signing.', 'smbb-signconnect')
                : __('After confirmation, you will be redirected to Yousign to sign this document. You will then return here to download the signed PDF.', 'smbb-signconnect');
            $html .= '<section class="smbb-signconnect-yousign-redirect-box">';
            $html .= '<h4>' . esc_html(sprintf(__('Secure signature with %s', 'smbb-signconnect'), $provider_name)) . '</h4>';
            $html .= '<p>' . esc_html(str_replace('Yousign', $provider_name, $external_redirect_message)) . '</p>';
            $html .= '</section>';
            $html .= '<div class="smbb-signconnect-document-actions"><button type="submit" class="button">' . esc_html(sprintf(__('Continue to %s', 'smbb-signconnect'), $provider_name)) . '</button></div>';
        } else {
            $html .= '<label class="smbb-signconnect-field" data-signconnect-signature-field><span>' . esc_html__('Signature', 'smbb-signconnect') . '</span><canvas class="smbb-signconnect-signature-pad" width="720" height="220" tabindex="0" data-signconnect-signature-pad></canvas></label>';
            $html .= '<div class="smbb-signconnect-document-actions"><button type="button" class="button is-secondary" data-signconnect-clear-signature>' . esc_html__('Clear', 'smbb-signconnect') . '</button><button type="submit" class="button">' . esc_html__('Submit response', 'smbb-signconnect') . '</button></div>';
        }
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

    private function publicSignatureFields($document_id, array $document)
    {
        $sender_identity = $this->senderIdentity($document);
        $sender_signature = $this->senderSignatureData($document);
        $sender_stamp_url = $this->senderStampUrl($document);

        return array_map(function ($field) use ($sender_identity, $sender_signature, $sender_stamp_url) {
            $field_type = SignatureFieldType::normalize(isset($field['field_type']) ? (string) $field['field_type'] : 'signature');
            $public_field = array(
                'id' => (int) $field['id'],
                'page_number' => (int) $field['page_number'],
                'x' => (float) $field['x'],
                'y' => (float) $field['y'],
                'width' => (float) $field['width'],
                'height' => (float) $field['height'],
                'label' => isset($field['label']) ? (string) $field['label'] : __('Signature', 'smbb-signconnect'),
                'field_type' => $field_type,
            );

            $preview = $this->senderPreview($field_type, $sender_identity, $sender_signature, $sender_stamp_url);

            if ($preview) {
                $public_field['sender_preview'] = $preview;
            }

            return $public_field;
        }, $this->signature_fields->listForDocument($document_id));
    }

    private function senderPreview($field_type, array $identity, $signature_data, $stamp_url)
    {
        switch (SignatureFieldType::normalize($field_type)) {
            case SignatureFieldType::SENDER_SIGNATURE:
                return $signature_data !== ''
                    ? array('type' => 'image', 'src' => $signature_data, 'alt' => __('Sender signature', 'smbb-signconnect'))
                    : null;
            case SignatureFieldType::SENDER_STAMP:
                return $stamp_url !== ''
                    ? array('type' => 'image', 'src' => $stamp_url, 'alt' => __('Sender stamp', 'smbb-signconnect'))
                    : null;
            case SignatureFieldType::SENDER_LAST_NAME:
                return $identity['last_name'] !== '' ? array('type' => 'text', 'text' => $identity['last_name']) : null;
            case SignatureFieldType::SENDER_FIRST_NAME:
                return $identity['first_name'] !== '' ? array('type' => 'text', 'text' => $identity['first_name']) : null;
            case SignatureFieldType::SENDER_FULL_NAME:
                $name = trim($identity['first_name'] . ' ' . $identity['last_name']);
                return $name !== '' ? array('type' => 'text', 'text' => $name) : null;
            default:
                return null;
        }
    }

    private function senderSignatureData(array $document)
    {
        $user_id = isset($document['creation_by']) ? (int) $document['creation_by'] : 0;
        $document_id = isset($document['id']) ? (int) $document['id'] : 0;

        if ($user_id < 1) {
            return '';
        }

        $row = $document_id > 0 ? $this->sender_signatures->findForDocument($document_id, $user_id) : null;

        if (!$row) {
            $row = $this->sender_signatures->findReusableForUser($user_id);
        }

        return $row && !empty($row['signature_data']) ? (string) $row['signature_data'] : '';
    }

    private function senderStampUrl(array $document)
    {
        $user_id = isset($document['creation_by']) ? (int) $document['creation_by'] : 0;
        $stamp_id = $user_id > 0 ? (int) get_user_meta($user_id, UserStorageProfileFields::STAMP_META_KEY, true) : 0;
        $stamp = $stamp_id > 0 ? $this->stamps->find($stamp_id) : null;
        $attachment_id = $stamp && !empty($stamp['image_attachment_id']) ? (int) $stamp['image_attachment_id'] : 0;
        $url = $attachment_id > 0 ? wp_get_attachment_url($attachment_id) : '';

        return is_string($url) ? $url : '';
    }

    private function senderIdentity(array $document)
    {
        $user_id = isset($document['creation_by']) ? (int) $document['creation_by'] : 0;
        $user = $user_id > 0 ? get_userdata($user_id) : false;

        if (!$user) {
            return array('first_name' => '', 'last_name' => '');
        }

        $first_name = get_user_meta($user_id, 'first_name', true);
        $last_name = get_user_meta($user_id, 'last_name', true);

        if ($first_name === '' && $last_name === '') {
            $parts = preg_split('/\s+/', trim((string) $user->display_name));
            $first_name = isset($parts[0]) ? (string) $parts[0] : '';
            $last_name = count($parts) > 1 ? implode(' ', array_slice($parts, 1)) : '';
        }

        return array(
            'first_name' => (string) $first_name,
            'last_name' => (string) $last_name,
        );
    }

    private function renderProofList(array $document)
    {
        $signed_at = !empty($document['sign_date'])
            ? mysql2date(get_option('date_format') . ' ' . get_option('time_format'), (string) $document['sign_date'])
            : '';
        $name = trim((isset($document['signer_first_name']) ? (string) $document['signer_first_name'] : '') . ' ' . (isset($document['signer_last_name']) ? (string) $document['signer_last_name'] : ''));
        $status = isset($document['signer_return_status']) ? (string) $document['signer_return_status'] : '';
        $status_label = $status === 'approved'
            ? __('Good for approval', 'smbb-signconnect')
            : ($status === 'refused' ? __('Refusal', 'smbb-signconnect') : __('Signature', 'smbb-signconnect'));
        $rows = array(
            __('Response', 'smbb-signconnect') => $status_label,
            __('Signer', 'smbb-signconnect') => $name,
            __('Signed on', 'smbb-signconnect') => $signed_at,
            __('Place', 'smbb-signconnect') => isset($document['signer_place']) ? (string) $document['signer_place'] : '',
        );
        $html = '<dl class="smbb-signconnect-public-proof">';

        foreach ($rows as $label => $value) {
            if ($value === '') {
                continue;
            }

            $html .= '<dt>' . esc_html($label) . '</dt><dd>' . esc_html($value) . '</dd>';
        }

        $html .= '</dl>';

        return $html;
    }
}
