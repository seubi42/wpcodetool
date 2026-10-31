<?php

defined('ABSPATH') || exit;

$button = isset($button) ? $button : 'Save';
$resource_label = isset($resource_label) ? $resource_label : __('Sender signature', 'smbb-signconnect');

$fields = array(
    $form->section(
        __('Signature', 'smbb-signconnect'),
        __('Stored sender signature drawing.', 'smbb-signconnect'),
        array(
            $form->number(__('User ID', 'smbb-signconnect'))->setName('user_id')->required(),
            $form->number(__('Document ID', 'smbb-signconnect'))->setName('document_id'),
            $form->toggle(__('Reusable', 'smbb-signconnect'))->setName('is_reusable'),
            $form->textarea(__('Signature data', 'smbb-signconnect'), array('rows' => 4))->setName('signature_data')->required()
        )
    )->setIcon('dashicons-edit')
);

$html = '<div class="form_container">';
$html .= $form->save(__($button, 'smbb-signconnect'))->setFields($fields);
$html .= '</div>';

echo '<div class="wrap smbb-codetool smbb-signconnect">';
echo !empty($page_header_html) ? $page_header_html : '<h1>' . esc_html($resource_label) . '</h1>';
echo !empty($notices_html) ? $notices_html : '';
echo $html;
echo '</div>';
