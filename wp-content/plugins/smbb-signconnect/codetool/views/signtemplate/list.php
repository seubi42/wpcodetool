<?php

/**
 * Admin list for SignConnect signature templates.
 *
 * @var \Smbb\WpCodeTool\Admin\Table $table
 */

defined('ABSPATH') || exit;

if (!isset($table)) {
    $table = new \Smbb\WpCodeTool\Admin\Table(array(
        'admin_url' => isset($admin_url) ? $admin_url : '',
        'create_url' => isset($create_url) ? $create_url : '',
        'primary_key' => isset($primary_key) ? $primary_key : 'id',
        'resource_label' => isset($resource_label) ? $resource_label : 'Signature templates',
        'resource_name' => isset($resource_name) ? $resource_name : 'signtemplate',
        'rows' => isset($rows) && is_array($rows) ? $rows : array(),
    ));
}

$zone_count = static function ($value) {
    $decoded = is_string($value) && $value !== '' ? json_decode($value, true) : array();

    return is_array($decoded) ? (string) count($decoded) : '0';
};

$table->setColumns(array(
    'name' => array(
        'label' => __('Name', 'smbb-signconnect'),
        'sort' => true,
        'actions' => array('edit', 'view', 'duplicate', 'delete'),
    ),
    'detection_zones' => array(
        'label' => __('Detection zones', 'smbb-signconnect'),
        'callback' => $zone_count,
    ),
    'signature_zones' => array(
        'label' => __('Signature zones', 'smbb-signconnect'),
        'callback' => $zone_count,
    ),
    'is_active' => array(
        'label' => __('Active', 'smbb-signconnect'),
        'sort' => true,
        'callback' => static function ($value) {
            return !empty($value) ? esc_html__('Yes', 'smbb-signconnect') : esc_html__('No', 'smbb-signconnect');
        },
    ),
    'lastupdate_date' => array(
        'label' => __('Updated', 'smbb-signconnect'),
        'sort' => true,
        'callback' => static function ($value) {
            return $value ? esc_html(mysql2date(get_option('date_format') . ' ' . get_option('time_format'), (string) $value)) : '&mdash;';
        },
    ),
), 'name');

$table->render();
