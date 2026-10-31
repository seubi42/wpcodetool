<?php

defined('ABSPATH') || exit;

if (!isset($table)) {
    $table = new \Smbb\WpCodeTool\Admin\Table(array(
        'admin_url' => isset($admin_url) ? $admin_url : '',
        'create_url' => isset($create_url) ? $create_url : '',
        'primary_key' => isset($primary_key) ? $primary_key : 'id',
        'resource_label' => isset($resource_label) ? $resource_label : 'Sender signatures',
        'resource_name' => isset($resource_name) ? $resource_name : 'signusersignature',
        'rows' => isset($rows) && is_array($rows) ? $rows : array(),
    ));
}

$table->setColumns(array(
    'user_id' => array('label' => __('User', 'smbb-signconnect'), 'sort' => true, 'actions' => array('view', 'delete')),
    'document_id' => array('label' => __('Document', 'smbb-signconnect'), 'sort' => true),
    'is_reusable' => array(
        'label' => __('Reusable', 'smbb-signconnect'),
        'sort' => true,
        'callback' => static function ($value) {
            return !empty($value) ? esc_html__('Yes', 'smbb-signconnect') : esc_html__('No', 'smbb-signconnect');
        },
    ),
    'creation_date' => array('label' => __('Created', 'smbb-signconnect'), 'sort' => true),
), 'user_id');

$table->render();
