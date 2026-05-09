<?php

/**
 * Admin list for SignConnect storages.
 *
 * @var \Smbb\WpCodeTool\Admin\Table $table
 */

defined('ABSPATH') || exit;

if (!isset($table)) {
    $table = new \Smbb\WpCodeTool\Admin\Table(array(
        'admin_url' => isset($admin_url) ? $admin_url : '',
        'create_url' => isset($create_url) ? $create_url : '',
        'primary_key' => isset($primary_key) ? $primary_key : 'id',
        'resource_label' => isset($resource_label) ? $resource_label : 'Sign storages',
        'resource_name' => isset($resource_name) ? $resource_name : 'signstorage',
        'rows' => isset($rows) && is_array($rows) ? $rows : array(),
    ));
}

$table->setColumns(array(
    'name' => array(
        'label' => __('Name', 'smbb-signconnect'),
        'sort' => true,
        'actions' => array('edit', 'view', 'delete'),
    ),
    'endpoint' => array(
        'label' => __('Endpoint', 'smbb-signconnect'),
        'sort' => true,
    ),
    'region' => array(
        'label' => __('Region', 'smbb-signconnect'),
        'sort' => true,
    ),
    'bucket' => array(
        'label' => __('Bucket', 'smbb-signconnect'),
        'sort' => true,
    ),
    'base_prefix' => array(
        'label' => __('Prefix', 'smbb-signconnect'),
        'sort' => true,
    ),
    'is_active' => array(
        'label' => __('Active', 'smbb-signconnect'),
        'sort' => true,
    ),
), 'name');

$table->render();
