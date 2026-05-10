<?php

defined('ABSPATH') || exit;

if (!isset($table)) {
    $table = new \Smbb\WpCodeTool\Admin\Table(array(
        'admin_url' => isset($admin_url) ? $admin_url : '',
        'create_url' => isset($create_url) ? $create_url : '',
        'primary_key' => isset($primary_key) ? $primary_key : 'id',
        'resource_label' => isset($resource_label) ? $resource_label : 'Signature fields',
        'resource_name' => isset($resource_name) ? $resource_name : 'signdocumentfield',
        'rows' => isset($rows) && is_array($rows) ? $rows : array(),
    ));
}

$table->setColumns(array(
    'document_id' => array(
        'label' => __('Document', 'smbb-signconnect'),
        'sort' => true,
        'actions' => array('edit', 'view', 'delete'),
    ),
    'page_number' => array(
        'label' => __('Page', 'smbb-signconnect'),
        'sort' => true,
    ),
    'field_type' => array(
        'label' => __('Type', 'smbb-signconnect'),
        'sort' => true,
    ),
    'label' => array(
        'label' => __('Label', 'smbb-signconnect'),
        'sort' => true,
    ),
), 'document_id');

$table->render();
