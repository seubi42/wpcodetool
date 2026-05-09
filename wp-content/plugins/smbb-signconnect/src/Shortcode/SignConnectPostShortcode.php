<?php

namespace Smbb\SignConnect\Shortcode;

use Smbb\SignConnect\Handler\DocumentUploadHandler;
use Smbb\SignConnect\Handler\SendStepHandler;
use Smbb\SignConnect\Handler\SignatureFieldHandler;
use Smbb\SignConnect\Repository\DocumentRepository;
use Smbb\SignConnect\Repository\SignatureFieldRepository;
use Smbb\SignConnect\Repository\StorageRepository;
use Smbb\SignConnect\Service\DocumentAccessService;
use Smbb\SignConnect\Support\FileSizeFormatter;
use Smbb\SignConnect\Support\FrontIcons;
use Smbb\SignConnect\Support\UrlHelper;
use Smbb\SignConnect\User\UserStorageProfileFields;

defined('ABSPATH') || exit;

final class SignConnectPostShortcode extends AbstractFrontShortcode
{
    private $storages;
    private $documents;
    private $signature_fields;
    private $send_renderer;
    private $access;
    private $upload_handler;
    private $signature_field_handler;
    private $send_handler;

    public function __construct(
        StorageRepository $storages = null,
        DocumentRepository $documents = null,
        SignatureFieldRepository $signature_fields = null,
        SendStepRenderer $send_renderer = null,
        DocumentAccessService $access = null,
        DocumentUploadHandler $upload_handler = null,
        SignatureFieldHandler $signature_field_handler = null,
        SendStepHandler $send_handler = null
    ) {
        $this->storages = $storages ?: new StorageRepository();
        $this->documents = $documents ?: new DocumentRepository();
        $this->signature_fields = $signature_fields ?: new SignatureFieldRepository();
        $this->send_renderer = $send_renderer ?: new SendStepRenderer();
        $this->access = $access ?: new DocumentAccessService($this->documents, $this->storages);
        $this->upload_handler = $upload_handler ?: new DocumentUploadHandler($this->storages, $this->documents);
        $this->signature_field_handler = $signature_field_handler ?: new SignatureFieldHandler($this->signature_fields, $this->access);
        $this->send_handler = $send_handler ?: new SendStepHandler($this->documents);
    }

    public function hooks()
    {
        add_shortcode('signconnect_post', array($this, 'render'));
        add_action('admin_post_smbb_signconnect_upload_document', array($this, 'handleUpload'));
        add_action('wp_ajax_smbb_signconnect_upload_document', array($this, 'handleAjaxUpload'));
        add_action('admin_post_smbb_signconnect_download_document', array($this, 'handleDownload'));
        add_action('wp_ajax_smbb_signconnect_pdf_document', array($this, 'handlePdfDocument'));
        add_action('wp_ajax_smbb_signconnect_save_signature_field', array($this, 'handleSaveSignatureField'));
        add_action('wp_ajax_smbb_signconnect_prepare_send', array($this, 'handlePrepareSend'));
        add_action('wp_ajax_smbb_signconnect_suggest_send_message', array($this, 'handleSuggestSendMessage'));
    }

    public function render()
    {
        $this->enqueueAssets();

        /*
         * Le shortcode est volontairement pilote par l'URL :
         * - sans document, on affiche l'etape de depot ;
         * - avec signconnect_document, on reprend le parcours au bon endroit ;
         * - avec signconnect_step, on force une etape precise du wizard.
         *
         * Ca permet de rafraichir la page, revenir plus tard sur un document,
         * ou partager un lien interne sans stocker d'etat fragile en session.
         */
        if (!is_user_logged_in()) {
            return $this->renderLoggedOut();
        }

        $user_id = get_current_user_id();
        $storage_id = (int) get_user_meta($user_id, UserStorageProfileFields::META_KEY, true);
        $notice = $this->consumeNotice();

        if ($storage_id < 1) {
            return $this->wrap(
                $this->renderNotice('error', __('No SignConnect storage is configured for your user account.', 'smbb-signconnect'))
            );
        }

        $storage = $this->storages->find($storage_id);

        if (!$storage) {
            return $this->wrap(
                $this->renderNotice('error', __('The SignConnect storage configured for your account is unavailable.', 'smbb-signconnect'))
            );
        }

        $html = '';
        $document_id = isset($_GET['signconnect_document']) ? absint($_GET['signconnect_document']) : 0;
        $step = isset($_GET['signconnect_step']) ? sanitize_key((string) $_GET['signconnect_step']) : '';

        // Normalisation de l'etape : l'ancienne valeur "signature_area" reste acceptee pour ne pas casser les liens existants.
        if ($document_id < 1) {
            $step = 'document';
        } elseif ($step === 'signature_area') {
            $step = 'zone';
        } elseif (!in_array($step, array('document', 'zone', 'send'), true)) {
            $step = 'zone';
        }

        if ($notice) {
            $html .= $this->renderNotice($notice['type'], $notice['message']);
        }

        $html .= $this->renderWizard($step, $document_id);

        if ($document_id > 0) {
            if ($step === 'zone') {
                $html .= $this->renderSignatureEditor($document_id, $user_id);

                return $this->wrap($html, 'is-editor-mode');
            }

            if ($step === 'send') {
                $html .= $this->renderSendStep($document_id, $user_id);

                return $this->wrap($html);
            }

            $html .= $this->renderDocumentDetails($document_id, $user_id);
            $html .= '<p class="smbb-signconnect-secondary-action"><a class="button is-secondary" href="' . esc_url(remove_query_arg(array('signconnect_document', 'signconnect_step'))) . '">' . FrontIcons::icon('plus') . '<span>' . esc_html__('Another document', 'smbb-signconnect') . '</span></a></p>';

            return $this->wrap($html);
        }

        $html .= '<form class="smbb-signconnect-post-form" method="post" action="' . esc_url(admin_url('admin-post.php')) . '" enctype="multipart/form-data" data-signconnect-upload-form>';
        $html .= '<input type="hidden" name="action" value="smbb_signconnect_upload_document">';
        $html .= '<input type="hidden" name="storage_id" value="' . esc_attr((string) $storage_id) . '">';
        $html .= wp_nonce_field('smbb_signconnect_upload_document_' . $user_id, '_wpnonce', true, false);
        $html .= '<label class="smbb-signconnect-file-drop" for="smbb-signconnect-document">';
        $html .= '<span class="smbb-signconnect-file-title">' . esc_html__('PDF upload', 'smbb-signconnect') . '</span>';
        $html .= '<span class="smbb-signconnect-file-subtitle" data-signconnect-file-label>' . esc_html__('Click or drag to upload your file', 'smbb-signconnect') . '</span>';
        $html .= '<input id="smbb-signconnect-document" type="file" name="signconnect_document" accept="application/pdf,.pdf" required data-signconnect-file-input>';
        $html .= '</label>';
        $html .= '<div class="smbb-signconnect-form-actions">';
        $html .= '<small class="smbb-signconnect-storage-note">' . esc_html__('Storage:', 'smbb-signconnect') . ' ' . esc_html((string) $storage['name']) . '</small>';
        $html .= '</div>';
        $html .= '</form>';
        $html .= '<div class="smbb-signconnect-upload-state" data-signconnect-upload-state hidden aria-label="' . esc_attr__('Document upload in progress', 'smbb-signconnect') . '">';
        $html .= '<span class="smbb-signconnect-spinner" aria-hidden="true"></span>';
        $html .= '<p data-signconnect-upload-status></p>';
        $html .= '</div>';
        $html .= '<div data-signconnect-message></div>';

        return $this->wrap($html);
    }

    public function handleUpload()
    {
        $this->upload_handler->handleUpload();
    }

    public function handleAjaxUpload()
    {
        $this->upload_handler->handleAjaxUpload();
    }

    public function handleDownload()
    {
        if (!is_user_logged_in()) {
            wp_safe_redirect(wp_login_url(home_url('/')));
            exit;
        }

        $document_id = isset($_GET['document_id']) ? absint($_GET['document_id']) : 0;

        if ($document_id < 1 || !wp_verify_nonce(isset($_GET['_wpnonce']) ? (string) $_GET['_wpnonce'] : '', 'smbb_signconnect_download_' . $document_id)) {
            wp_die(esc_html__('Invalid download link.', 'smbb-signconnect'));
        }

        $this->access->streamAuthorizedDocument($document_id, 'attachment');
    }

    public function handlePdfDocument()
    {
        if (!is_user_logged_in()) {
            status_header(401);
            wp_die(esc_html__('Please sign in.', 'smbb-signconnect'));
        }

        $document_id = isset($_GET['document_id']) ? absint($_GET['document_id']) : 0;

        if ($document_id < 1 || !wp_verify_nonce(isset($_GET['_wpnonce']) ? (string) $_GET['_wpnonce'] : '', 'smbb_signconnect_view_pdf_' . $document_id)) {
            status_header(403);
            wp_die(esc_html__('Invalid PDF link.', 'smbb-signconnect'));
        }

        $this->access->streamAuthorizedDocument($document_id, 'inline');
    }

    public function handleSaveSignatureField()
    {
        $this->signature_field_handler->handleSaveSignatureField();
    }

    public function handlePrepareSend()
    {
        $this->send_handler->handlePrepareSend();
    }

    public function handleSuggestSendMessage()
    {
        $this->send_handler->handleSuggestSendMessage();
    }

    private function renderLoggedOut()
    {
        $login_url = wp_login_url(UrlHelper::currentUrl());
        $html = $this->renderNotice('info', __('Please sign in to upload a document.', 'smbb-signconnect'));
        $html .= '<p><a class="button" href="' . esc_url($login_url) . '">' . esc_html__('Sign in', 'smbb-signconnect') . '</a></p>';

        return $this->wrap($html);
    }

    private function renderDocumentDetails($document_id, $user_id)
    {
        $document = $this->documents->findOwnedByUser($document_id, $user_id);

        if (!$document) {
            return $this->renderNotice('error', __('Document not found or inaccessible.', 'smbb-signconnect'));
        }

        $download_url = wp_nonce_url(
            add_query_arg(array(
                'action' => 'smbb_signconnect_download_document',
                'document_id' => $document_id,
            ), admin_url('admin-post.php')),
            'smbb_signconnect_download_' . $document_id
        );
        $pdf_url = wp_nonce_url(
            add_query_arg(array(
                'action' => 'smbb_signconnect_pdf_document',
                'document_id' => $document_id,
            ), admin_url('admin-ajax.php')),
            'smbb_signconnect_view_pdf_' . $document_id
        );

        $html = '<section class="smbb-signconnect-document-card">';
        $html .= '<h3 class="smbb-signconnect-document-title">' . esc_html((string) $document['filename']) . '</h3>';
        $html .= '<p class="smbb-signconnect-document-meta">' . esc_html(FileSizeFormatter::format(isset($document['file_size']) ? (int) $document['file_size'] : 0)) . '</p>';
        if (!empty($document['link_expires_at'])) {
            $expires_at = strtotime((string) $document['link_expires_at']);
            $expires_label = mysql2date(get_option('date_format'), (string) $document['link_expires_at']);
            $expires_class = $expires_at > 0 && $expires_at < current_time('timestamp') ? 'is-expired' : 'is-active';
            $html .= '<p class="smbb-signconnect-document-expiration ' . esc_attr($expires_class) . '">' . FrontIcons::icon('clock') . '<span>' . sprintf(esc_html__('Expiration: %s', 'smbb-signconnect'), esc_html($expires_label)) . '</span></p>';
        }
        $html .= '<div class="smbb-signconnect-document-actions">';
        $html .= '<a class="button" href="' . esc_url($download_url) . '">' . FrontIcons::icon('download') . '<span>' . esc_html__('Download', 'smbb-signconnect') . '</span></a>';
        $html .= '<a class="button is-secondary" href="' . esc_url($pdf_url) . '" target="_blank" rel="noopener noreferrer">' . FrontIcons::icon('eye') . '<span>' . esc_html__('View PDF', 'smbb-signconnect') . '</span></a>';
        $html .= '</div>';
        $html .= '</section>';

        return $html;
    }

    private function renderSignatureEditor($document_id, $user_id)
    {
        $document = $this->documents->findOwnedByUser($document_id, $user_id);

        if (!$document) {
            return $this->renderNotice('error', __('Document not found or inaccessible.', 'smbb-signconnect'));
        }

        $pdf_url = wp_nonce_url(
            add_query_arg(array(
                'action' => 'smbb_signconnect_pdf_document',
                'document_id' => $document_id,
            ), admin_url('admin-ajax.php')),
            'smbb_signconnect_view_pdf_' . $document_id
        );
        $fields = $this->signature_fields->listForDocument($document_id);
        $send_url = add_query_arg('signconnect_step', 'send');

        /*
         * L'editeur PDF est charge seulement sur l'etape "Zone".
         * Ca evite de faire porter pdf.js et le CSS specifique aux ecrans qui
         * n'en ont pas besoin, notamment le formulaire de depot.
         */
        wp_enqueue_script(
            'smbb-signconnect-signature-editor',
            SMBB_SIGNCONNECT_URL . 'assets/signature-editor.js',
            array('smbb-signconnect-front'),
            SMBB_SIGNCONNECT_VERSION,
            true
        );
        wp_enqueue_style(
            'smbb-signconnect-signature-editor',
            SMBB_SIGNCONNECT_URL . 'assets/signature-editor.css',
            array('smbb-signconnect-front'),
            SMBB_SIGNCONNECT_VERSION
        );

        $field_json = wp_json_encode(array_map(static function ($field) {
            return array(
                'id' => isset($field['id']) ? (int) $field['id'] : 0,
                'page_number' => (int) $field['page_number'],
                'x' => (float) $field['x'],
                'y' => (float) $field['y'],
                'width' => (float) $field['width'],
                'height' => (float) $field['height'],
            );
        }, $fields));

        $html = '<section class="smbb-signconnect-editor" data-signconnect-signature-editor';
        $html .= ' data-document-id="' . esc_attr((string) $document_id) . '"';
        $html .= ' data-pdf-url="' . esc_url($pdf_url) . '"';
        $html .= ' data-save-nonce="' . esc_attr(wp_create_nonce('smbb_signconnect_signature_field_' . $document_id)) . '"';
        $html .= ' data-existing-fields="' . esc_attr($field_json) . '">';
        $html .= '<header class="smbb-signconnect-editor-header">';
        $html .= '<div><h3>' . esc_html__('Define signature areas', 'smbb-signconnect') . '</h3>';
        $html .= '<p>' . esc_html__('Choose a page, then draw the area(s) where the signer should sign.', 'smbb-signconnect') . '</p>';
        $html .= '<p class="smbb-signconnect-mobile-hint">' . esc_html__('On mobile, scroll normally, then enable drawing mode to draw an area with one finger.', 'smbb-signconnect') . '</p></div>';
        $html .= '</header>';
        $html .= '<div class="smbb-signconnect-editor-message" data-editor-message></div>';
        $html .= '<div class="smbb-signconnect-editor-layout">';
        $html .= '<aside class="smbb-signconnect-thumbs" data-editor-thumbs></aside>';
        $html .= '<main class="smbb-signconnect-page-stage">';
        $html .= '<div class="smbb-signconnect-page-toolbar"><span data-editor-page-label></span><div class="smbb-signconnect-page-tools"><button type="button" data-editor-draw-mode aria-pressed="false">' . esc_html__('Drawing mode', 'smbb-signconnect') . '</button></div></div>';
        $html .= '<div class="smbb-signconnect-page-shell" data-editor-page-shell><canvas data-editor-canvas></canvas><div class="smbb-signconnect-sign-layer" data-editor-layer></div></div>';
        $html .= '<div class="smbb-signconnect-editor-actions"><span data-editor-save-state aria-live="polite"></span><a class="button" href="' . esc_url($send_url) . '"><span>' . esc_html__('Continue', 'smbb-signconnect') . '</span>' . FrontIcons::icon('arrow-right') . '</a></div>';
        $html .= '</main>';
        $html .= '</div>';
        $html .= '</section>';

        return $html;
    }

    private function renderSendStep($document_id, $user_id)
    {
        $document = $this->documents->findOwnedByUser($document_id, $user_id);

        if (!$document) {
            return $this->renderNotice('error', __('Document not found or inaccessible.', 'smbb-signconnect'));
        }

        return $this->send_renderer->render($document_id, $document);
    }

    private function renderWizard($active_step, $document_id)
    {
        // Wizard volontairement simple : trois etapes metier, sans texte d'aide parasite.
        $steps = array(
            'document' => __('Document', 'smbb-signconnect'),
            'zone' => __('Zone', 'smbb-signconnect'),
            'send' => __('Send', 'smbb-signconnect'),
        );
        $keys = array_keys($steps);
        $active_index = array_search($active_step, $keys, true);

        if ($active_index === false) {
            $active_index = 0;
        }

        $html = '<nav class="smbb-signconnect-steps" aria-label="' . esc_attr__('SignConnect steps', 'smbb-signconnect') . '">';

        foreach ($steps as $step => $label) {
            $index = array_search($step, $keys, true);
            $is_active = $step === $active_step;
            $is_done = $index < $active_index;
            $classes = 'smbb-signconnect-step' . ($is_active ? ' is-active' : '') . ($is_done ? ' is-done' : '');
            $url = $this->wizardStepUrl($step, $document_id);
            $content = '<span class="smbb-signconnect-step-dot">' . esc_html((string) ($index + 1)) . '</span><span class="smbb-signconnect-step-label">' . esc_html($label) . '</span>';

            $html .= '<div class="' . esc_attr($classes) . '">';
            $html .= $url !== '' ? '<a href="' . esc_url($url) . '">' . $content . '</a>' : '<span>' . $content . '</span>';
            $html .= '</div>';
        }

        $html .= '</nav>';

        return $html;
    }

    private function wizardStepUrl($step, $document_id)
    {
        if ($step === 'document') {
            return $document_id > 0 ? add_query_arg('signconnect_step', 'document') : '';
        }

        if ($document_id < 1) {
            return '';
        }

        return add_query_arg('signconnect_step', $step);
    }


    private function consumeNotice()
    {
        $key = $this->noticeKey();
        $notice = get_transient($key);

        if ($notice !== false) {
            delete_transient($key);
        }

        return is_array($notice) ? $notice : null;
    }

    private function noticeKey()
    {
        return 'smbb_signconnect_upload_' . get_current_user_id();
    }



}
