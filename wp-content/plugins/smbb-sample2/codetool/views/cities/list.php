<?php

/**
 * Minimal admin list for the "cities" sample resource.
 *
 * @var \Smbb\WpCodeTool\Admin\Table $table
 */

defined('ABSPATH') || exit;

if (!isset($table)) {
    $table = new \Smbb\WpCodeTool\Admin\Table(array(
        'admin_url' => isset($admin_url) ? $admin_url : '',
        'create_url' => isset($create_url) ? $create_url : '',
        'primary_key' => isset($primary_key) ? $primary_key : 'id',
        'resource_label' => isset($resource_label) ? $resource_label : 'Cities',
        'resource_name' => isset($resource_name) ? $resource_name : 'cities',
        'rows' => isset($rows) && is_array($rows) ? $rows : array(),
    ));
}

$table->setColumns(array(
    'name' => array(
        'label' => __('Name', 'smbb-sample2'),
        'sort' => true,
        'actions' => array('edit', 'view', 'delete'),
    ),
    'lat' => array(
        'label' => __('Latitude', 'smbb-sample2'),
        'sort' => true,
    ),
    'lng' => array(
        'label' => __('Longitude', 'smbb-sample2'),
        'sort' => true,
    ),
), 'name');

$table->render();
