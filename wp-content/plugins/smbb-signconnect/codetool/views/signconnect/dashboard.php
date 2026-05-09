<?php

/**
 * SignConnect admin dashboard.
 *
 * @var \Smbb\WpCodeTool\Admin\Dashboard $dashboard_ui
 * @var string $page_header_html
 * @var string $notices_html
 */

defined('ABSPATH') || exit;

$documents_url = admin_url('admin.php?page=smbb-codetool-signdocument');
$storages_url = admin_url('admin.php?page=smbb-codetool-signstorage');
$document_count = 0;
$document_total_size = 0;

global $wpdb;

if (isset($wpdb)) {
    $documents_table = $wpdb->prefix . 'signdocument';
    $table_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $documents_table)) === $documents_table;

    if ($table_exists) {
        $document_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$documents_table} WHERE deleted_flag = 0");

        if ($wpdb->get_var("SHOW COLUMNS FROM {$documents_table} LIKE 'file_size'")) {
            $document_total_size = (int) $wpdb->get_var("SELECT COALESCE(SUM(file_size), 0) FROM {$documents_table} WHERE deleted_flag = 0");
        }
    }
}

$document_total_size_label = function_exists('size_format')
    ? size_format($document_total_size, 1)
    : number_format_i18n($document_total_size) . ' B';

echo '<div class="wrap smbb-codetool smbb-signconnect">';
echo $page_header_html;

if (!empty($notices_html)) {
    echo $notices_html;
}

echo $dashboard_ui->hero(array(
    'eyebrow' => __('SignConnect', 'smbb-signconnect'),
    'badge' => __('Admin', 'smbb-signconnect'),
    'title' => __('Signature operations', 'smbb-signconnect'),
    'description' => __('Manage documents to sign and the S3-compatible storages used to keep their files.', 'smbb-signconnect'),
    'actions' => array(
        array(
            'label' => __('Documents', 'smbb-signconnect'),
            'url' => $documents_url,
            'variant' => 'primary',
        ),
        array(
            'label' => __('Storages', 'smbb-signconnect'),
            'url' => $storages_url,
            'variant' => 'secondary',
        ),
    ),
    'tiles' => array(
        array(
            'icon' => 'dashicons-media-document',
            'label' => __('Documents', 'smbb-signconnect'),
            'meta' => sprintf(
                /* translators: 1: document count, 2: total file size. */
                _n('%1$s document, %2$s stored', '%1$s documents, %2$s stored', $document_count, 'smbb-signconnect'),
                number_format_i18n($document_count),
                $document_total_size_label
            ),
        ),
        array(
            'icon' => 'dashicons-cloud',
            'label' => __('Storages', 'smbb-signconnect'),
            'meta' => __('S3 endpoint, bucket, credentials', 'smbb-signconnect'),
        ),
    ),
));

echo $dashboard_ui->split(array(
    $dashboard_ui->panel(
        __('Documents', 'smbb-signconnect'),
        __('Access the document table and link each record to a storage path.', 'smbb-signconnect'),
        '<p><a class="button button-primary" href="' . esc_url($documents_url) . '">' . esc_html__('Open documents', 'smbb-signconnect') . '</a></p>'
    ),
    $dashboard_ui->panel(
        __('Storages', 'smbb-signconnect'),
        __('Manage and test S3-compatible storage connections.', 'smbb-signconnect'),
        '<p><a class="button" href="' . esc_url($storages_url) . '">' . esc_html__('Open storages', 'smbb-signconnect') . '</a></p>'
    ),
));

echo '</div>';
