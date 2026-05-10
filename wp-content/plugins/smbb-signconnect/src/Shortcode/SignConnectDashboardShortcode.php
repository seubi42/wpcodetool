<?php

namespace Smbb\SignConnect\Shortcode;

use Smbb\SignConnect\Repository\DocumentRepository;
use Smbb\SignConnect\Service\DocumentSendDeliveryService;
use Smbb\SignConnect\Support\FileSizeFormatter;
use Smbb\SignConnect\Support\FrontIcons;
use Smbb\SignConnect\Support\DocumentStatus;
use Smbb\SignConnect\Support\SignConnectSettings;

defined('ABSPATH') || exit;

final class SignConnectDashboardShortcode extends AbstractFrontShortcode
{
    private $documents;
    private $delivery;

    public function __construct(DocumentRepository $documents = null, DocumentSendDeliveryService $delivery = null)
    {
        $this->documents = $documents ?: new DocumentRepository();
        $this->delivery = $delivery ?: new DocumentSendDeliveryService($this->documents);
    }

    public function hooks()
    {
        add_shortcode('signconnect_bashboard', array($this, 'render'));
        add_shortcode('signconnect_dashboard', array($this, 'render'));
        add_action('wp_ajax_smbb_signconnect_resend_document', array($this, 'handleResend'));
    }

    public function render()
    {
        $this->enqueueAssets();

        if (!is_user_logged_in()) {
            $login_url = wp_login_url($this->currentUrl());
            $html = $this->renderNotice('info', __('Please sign in to track your documents.', 'smbb-signconnect'));
            $html .= '<p><a class="button" href="' . esc_url($login_url) . '">' . esc_html__('Sign in', 'smbb-signconnect') . '</a></p>';

            return $this->wrap($html, 'is-dashboard-mode');
        }

        $user_id = get_current_user_id();
        $documents = $this->documents->listOwnedByUser($user_id, 80);
        $active_filter = isset($_GET['signconnect_status']) ? sanitize_key((string) wp_unslash($_GET['signconnect_status'])) : '';
        $documents = $this->filterDocuments($documents, $active_filter);
        $new_document_url = SignConnectSettings::postingPageUrl();
        $html = '<section class="smbb-signconnect-dashboard">';
        $html .= '<header class="smbb-signconnect-dashboard-header"><div><h3>' . esc_html__('Documents', 'smbb-signconnect') . '</h3><p>' . esc_html__('Shipments and reminders tracking.', 'smbb-signconnect') . '</p></div><a class="button" href="' . esc_url($new_document_url) . '">' . FrontIcons::icon('plus') . '<span>' . esc_html__('New document', 'smbb-signconnect') . '</span></a></header>';
        $html .= $this->renderFilters($active_filter);
        $html .= '<div data-signconnect-dashboard-message></div>';

        if (!$documents) {
            $html .= '<div class="smbb-signconnect-empty-state">' . FrontIcons::icon('file') . '<p>' . esc_html__('No documents yet.', 'smbb-signconnect') . '</p></div>';
            $html .= '</section>';

            return $this->wrap($html, 'is-dashboard-mode');
        }

        $html .= '<div class="smbb-signconnect-dashboard-table-wrap"><table class="smbb-signconnect-dashboard-table">';
        $html .= '<thead><tr><th>' . esc_html__('File', 'smbb-signconnect') . '</th><th>' . esc_html__('State', 'smbb-signconnect') . '</th><th>' . esc_html__('Expiration', 'smbb-signconnect') . '</th><th>' . esc_html__('Recipient', 'smbb-signconnect') . '</th><th>' . esc_html__('Action', 'smbb-signconnect') . '</th></tr></thead><tbody>';

        foreach ($documents as $document) {
            $html .= $this->row($document, $user_id);
        }

        $html .= '</tbody></table></div></section>';

        return $this->wrap($html, 'is-dashboard-mode');
    }

    public function handleResend()
    {
        if (!is_user_logged_in()) {
            wp_send_json_error(array('message' => __('Please sign in.', 'smbb-signconnect')), 401);
        }

        $document_id = isset($_POST['document_id']) ? absint($_POST['document_id']) : 0;
        $user_id = get_current_user_id();

        if ($document_id < 1 || !wp_verify_nonce(isset($_POST['_wpnonce']) ? (string) $_POST['_wpnonce'] : '', 'smbb_signconnect_resend_' . $document_id)) {
            wp_send_json_error(array('message' => __('Invalid request.', 'smbb-signconnect')), 400);
        }

        $document = $this->documents->findOwnedByUser($document_id, $user_id);

        if (!$document) {
            wp_send_json_error(array('message' => __('Document not found or inaccessible.', 'smbb-signconnect')), 404);
        }

        if (!$this->canResend($document)) {
            wp_send_json_error(array('message' => __('This document cannot be resent.', 'smbb-signconnect')), 400);
        }

        try {
            $result = $this->delivery->deliver($document, $user_id);
        } catch (\Throwable $exception) {
            wp_send_json_error(array('message' => $exception->getMessage()), 500);
        }

        wp_send_json_success(array(
            'message' => __('Reminder sent.', 'smbb-signconnect'),
            'status' => isset($result['document']['document_status']) ? (string) $result['document']['document_status'] : 'sent',
        ));
    }

    private function row(array $document, $user_id)
    {
        $document_id = isset($document['id']) ? (int) $document['id'] : 0;
        $status = $this->statusLabel($document);
        $recipient = $this->recipient($document);
        $expires = $this->expirationLabel($document);
        $can_resend = $this->canResend($document);
        $nonce = wp_create_nonce('smbb_signconnect_resend_' . $document_id);
        $created = !empty($document['creation_date']) ? mysql2date(get_option('date_format'), (string) $document['creation_date']) : '';
        $sent_count = isset($document['sent_count']) ? (int) $document['sent_count'] : 0;
        $opened_count = isset($document['opened_count']) ? (int) $document['opened_count'] : 0;
        $meta = FileSizeFormatter::format(isset($document['file_size']) ? (int) $document['file_size'] : 0);
        $meta .= $created !== '' ? ' · ' . $created : '';
        $meta .= $sent_count > 0 ? ' · ' . sprintf(_n('%d send', '%d sends', $sent_count, 'smbb-signconnect'), $sent_count) : '';
        $meta .= $opened_count > 0 ? ' · ' . sprintf(_n('%d open', '%d opens', $opened_count, 'smbb-signconnect'), $opened_count) : '';

        $html = '<tr data-signconnect-dashboard-row="' . esc_attr((string) $document_id) . '">';
        $html .= '<td data-label="' . esc_attr__('File', 'smbb-signconnect') . '"><strong>' . esc_html(isset($document['filename']) ? (string) $document['filename'] : '') . '</strong><span>' . esc_html($meta) . '</span></td>';
        $html .= '<td data-label="' . esc_attr__('State', 'smbb-signconnect') . '"><span class="smbb-signconnect-status is-' . esc_attr($status['class']) . '">' . esc_html($status['label']) . '</span></td>';
        $html .= '<td data-label="' . esc_attr__('Expiration', 'smbb-signconnect') . '">' . $expires . '</td>';
        $html .= '<td data-label="' . esc_attr__('Recipient', 'smbb-signconnect') . '">' . esc_html($recipient !== '' ? $recipient : '-') . '</td>';
        $html .= '<td data-label="' . esc_attr__('Action', 'smbb-signconnect') . '">';
        $continue_url = $this->continueUrl($document);
        $download_url = $this->signedDownloadUrl($document);

        if ($continue_url !== '') {
            $html .= '<a class="button is-secondary" href="' . esc_url($continue_url) . '">' . FrontIcons::icon('arrow-right') . '<span>' . esc_html__('Continue', 'smbb-signconnect') . '</span></a>';
        }

        if ($download_url !== '') {
            $html .= '<a class="button is-secondary" href="' . esc_url($download_url) . '">' . FrontIcons::icon('download') . '<span>' . esc_html__('Download', 'smbb-signconnect') . '</span></a>';
        }

        if ($can_resend) {
            $html .= '<button type="button" class="button is-secondary" data-signconnect-resend data-document-id="' . esc_attr((string) $document_id) . '" data-nonce="' . esc_attr($nonce) . '">' . FrontIcons::icon('refresh') . '<span>' . esc_html__('Resend', 'smbb-signconnect') . '</span></button>';
        } elseif ($continue_url === '' && $download_url === '') {
            $html .= '<span class="smbb-signconnect-dashboard-muted">-</span>';
        }

        $html .= '</td></tr>';

        return $html;
    }

    private function statusLabel(array $document)
    {
        if ($this->isExpired($document) && empty($document['sign_date'])) {
            return array('label' => __('Expired', 'smbb-signconnect'), 'class' => 'expired');
        }

        $status = isset($document['document_status']) ? (string) $document['document_status'] : DocumentStatus::DRAFT;

        return array(
            'label' => DocumentStatus::label($status),
            'class' => sanitize_html_class($status),
        );
    }

    private function recipient(array $document)
    {
        if (!empty($document['recipient_email'])) {
            return (string) $document['recipient_email'];
        }

        return !empty($document['recipient_phone']) ? (string) $document['recipient_phone'] : '';
    }

    private function expirationLabel(array $document)
    {
        if (empty($document['link_expires_at'])) {
            return '<span class="smbb-signconnect-dashboard-muted">-</span>';
        }

        $date = mysql2date(get_option('date_format'), (string) $document['link_expires_at']);
        $class = $this->isExpired($document) ? 'is-expired' : 'is-active';

        return '<span class="smbb-signconnect-expiration ' . esc_attr($class) . '">' . FrontIcons::icon('clock') . '<span>' . esc_html($date) . '</span></span>';
    }

    private function canResend(array $document)
    {
        if ($this->isExpired($document) || !empty($document['sign_date'])) {
            return false;
        }

        $status = isset($document['document_status']) ? (string) $document['document_status'] : '';

        return in_array($status, array(DocumentStatus::READY_TO_SEND, DocumentStatus::SENT), true) && $this->recipient($document) !== '';
    }

    private function continueUrl(array $document)
    {
        if ($this->isExpired($document) || !empty($document['sign_date'])) {
            return '';
        }

        $document_id = isset($document['id']) ? (int) $document['id'] : 0;

        if ($document_id < 1) {
            return '';
        }

        $status = isset($document['document_status']) ? (string) $document['document_status'] : DocumentStatus::DRAFT;

        if (in_array($status, array(DocumentStatus::SENT, DocumentStatus::SIGNED, DocumentStatus::REFUSED, DocumentStatus::EXPIRED_DELETED), true)) {
            return '';
        }

        $step = $status === DocumentStatus::READY_TO_SEND ? 'send' : 'zone';

        return add_query_arg(array(
            'signconnect_document' => $document_id,
            'signconnect_step' => $step,
        ), SignConnectSettings::postingPageUrl());
    }

    private function signedDownloadUrl(array $document)
    {
        $document_id = isset($document['id']) ? (int) $document['id'] : 0;
        $status = isset($document['document_status']) ? (string) $document['document_status'] : '';

        if ($document_id < 1 || !in_array($status, array(DocumentStatus::SIGNED, DocumentStatus::REFUSED), true) || empty($document['signed_storage_path'])) {
            return '';
        }

        return wp_nonce_url(
            add_query_arg(array(
                'action' => 'smbb_signconnect_download_document',
                'document_id' => $document_id,
            ), admin_url('admin-post.php')),
            'smbb_signconnect_download_' . $document_id
        );
    }

    private function isExpired(array $document)
    {
        if (empty($document['link_expires_at'])) {
            return false;
        }

        $expires_at = strtotime((string) $document['link_expires_at']);

        return $expires_at > 0 && $expires_at < current_time('timestamp');
    }

    private function filterDocuments(array $documents, $active_filter)
    {
        if ($active_filter === '') {
            return $documents;
        }

        return array_values(array_filter($documents, function ($document) use ($active_filter) {
            if ($active_filter === DocumentStatus::EXPIRED) {
                return $this->isExpired($document) && empty($document['sign_date']);
            }

            $status = isset($document['document_status']) ? (string) $document['document_status'] : DocumentStatus::DRAFT;

            return $status === $active_filter;
        }));
    }

    private function renderFilters($active_filter)
    {
        $filters = array(
            '' => __('All', 'smbb-signconnect'),
            DocumentStatus::DRAFT => __('Drafts', 'smbb-signconnect'),
            DocumentStatus::ZONE_READY => __('Ready areas', 'smbb-signconnect'),
            DocumentStatus::SENT => __('Sent', 'smbb-signconnect'),
            DocumentStatus::SIGNED => __('Signed', 'smbb-signconnect'),
            DocumentStatus::REFUSED => __('Refused', 'smbb-signconnect'),
            DocumentStatus::EXPIRED => __('Expired', 'smbb-signconnect'),
        );
        $base_url = remove_query_arg('signconnect_status', $this->currentUrl());
        $html = '<nav class="smbb-signconnect-dashboard-filters" aria-label="' . esc_attr__('Document filters', 'smbb-signconnect') . '">';

        foreach ($filters as $status => $label) {
            $url = $status === '' ? $base_url : add_query_arg('signconnect_status', $status, $base_url);
            $class = $active_filter === $status ? ' is-active' : '';
            $html .= '<a class="smbb-signconnect-filter' . esc_attr($class) . '" href="' . esc_url($url) . '">' . esc_html($label) . '</a>';
        }

        $html .= '</nav>';

        return $html;
    }

    private function currentUrl()
    {
        $scheme = is_ssl() ? 'https://' : 'http://';
        $host = isset($_SERVER['HTTP_HOST']) ? sanitize_text_field((string) wp_unslash($_SERVER['HTTP_HOST'])) : '';
        $uri = isset($_SERVER['REQUEST_URI']) ? sanitize_text_field((string) wp_unslash($_SERVER['REQUEST_URI'])) : '';

        return $scheme . $host . $uri;
    }
}
