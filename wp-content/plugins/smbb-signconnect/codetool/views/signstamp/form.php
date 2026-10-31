<?php

defined('ABSPATH') || exit;

$button = isset($button) ? $button : 'Save';
$resource_label = isset($resource_label) ? $resource_label : __('Sender stamp', 'smbb-signconnect');

$fields = array(
    $form->section(
        __('Stamp', 'smbb-signconnect'),
        __('Image used when a sender stamp field is present on a document.', 'smbb-signconnect'),
        array(
            $form->text(__('Name', 'smbb-signconnect'))->setName('name')->required(),
            $form->image(__('Stamp image', 'smbb-signconnect'))->setName('image_attachment_id')->required(),
            $form->toggle(__('Active', 'smbb-signconnect'))->setName('is_active')
        )
    )->setIcon('dashicons-format-image')
);

$html = '<div class="form_container">';
$html .= $form->save(__($button, 'smbb-signconnect'))->setFields($fields);
$html .= '</div>';

echo '<div class="wrap smbb-codetool smbb-signconnect">';
echo !empty($page_header_html) ? $page_header_html : '<h1>' . esc_html($resource_label) . '</h1>';
echo !empty($notices_html) ? $notices_html : '';
echo $html;
echo '</div>';
