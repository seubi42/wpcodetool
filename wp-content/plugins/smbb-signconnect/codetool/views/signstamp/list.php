<?php

defined('ABSPATH') || exit;

if (!isset($table)) {
    $table = new \Smbb\WpCodeTool\Admin\Table(array(
        'admin_url' => isset($admin_url) ? $admin_url : '',
        'create_url' => isset($create_url) ? $create_url : '',
        'primary_key' => isset($primary_key) ? $primary_key : 'id',
        'resource_label' => isset($resource_label) ? $resource_label : 'Sender stamps',
        'resource_name' => isset($resource_name) ? $resource_name : 'signstamp',
        'rows' => isset($rows) && is_array($rows) ? $rows : array(),
    ));
}

$table->setColumns(array(
    'name' => array(
        'label' => __('Name', 'smbb-signconnect'),
        'sort' => true,
        'actions' => array('edit', 'view', 'duplicate', 'delete'),
    ),
    'image_attachment_id' => array(
        'label' => __('Image', 'smbb-signconnect'),
        'callback' => static function ($value) {
            $id = absint($value);
            $image = $id > 0 ? wp_get_attachment_image($id, 'thumbnail', false, array('style' => 'max-height:54px;width:auto;')) : '';

            return $image !== '' ? $image : '&mdash;';
        },
    ),
    'is_active' => array(
        'label' => __('Active', 'smbb-signconnect'),
        'sort' => true,
        'callback' => static function ($value) {
            return !empty($value) ? esc_html__('Yes', 'smbb-signconnect') : esc_html__('No', 'smbb-signconnect');
        },
    ),
), 'name');

$table->render();
