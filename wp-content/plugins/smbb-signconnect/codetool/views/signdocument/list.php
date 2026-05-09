<?php

/**
 * Admin list for SignConnect documents.
 *
 * @var \Smbb\WpCodeTool\Admin\Table $table
 */

defined('ABSPATH') || exit;

if (!isset($table)) {
    $table = new \Smbb\WpCodeTool\Admin\Table(array(
        'admin_url' => isset($admin_url) ? $admin_url : '',
        'create_url' => isset($create_url) ? $create_url : '',
        'primary_key' => isset($primary_key) ? $primary_key : 'id',
        'resource_label' => isset($resource_label) ? $resource_label : 'Sign documents',
        'resource_name' => isset($resource_name) ? $resource_name : 'signdocument',
        'rows' => isset($rows) && is_array($rows) ? $rows : array(),
    ));
}

$table->setColumns(array(
    'filename' => array(
        'label' => __('Filename', 'smbb-signconnect'),
        'sort' => true,
        'actions' => array('edit', 'view', 'delete'),
    ),
    'creation_date' => array(
        'label' => __('Creation date', 'smbb-signconnect'),
        'sort' => true,
    ),
    'creation_by' => array(
        'label' => __('Created by', 'smbb-signconnect'),
        'sort' => true,
    ),
    'storage_id' => array(
        'label' => __('Storage', 'smbb-signconnect'),
        'sort' => true,
    ),
    'storage_path' => array(
        'label' => __('Storage path', 'smbb-signconnect'),
        'sort' => true,
    ),
    'file_size' => array(
        'label' => __('Size', 'smbb-signconnect'),
        'sort' => true,
        'callback' => function ($value) {
            $bytes = max(0, (int) $value);

            return function_exists('size_format') ? size_format($bytes, 1) : number_format_i18n($bytes) . ' B';
        },
    ),
    'document_status' => array(
        'label' => __('Status', 'smbb-signconnect'),
        'sort' => true,
    ),
    'send_channel' => array(
        'label' => __('Channel', 'smbb-signconnect'),
        'sort' => true,
    ),
    'recipient_email' => array(
        'label' => __('Recipient', 'smbb-signconnect'),
        'sort' => true,
        'callback' => function ($value, $row) {
            if (!empty($row['recipient_email'])) {
                return esc_html((string) $row['recipient_email']);
            }

            return !empty($row['recipient_phone']) ? esc_html((string) $row['recipient_phone']) : '&mdash;';
        },
    ),
    'sign_date' => array(
        'label' => __('Sign date', 'smbb-signconnect'),
        'sort' => true,
    ),
    'sign_by' => array(
        'label' => __('Signed by', 'smbb-signconnect'),
        'sort' => true,
    ),
    'token' => array(
        'label' => __('Token', 'smbb-signconnect'),
        'sort' => true,
    ),
), 'filename');

$table->render();
