<?php

defined('ABSPATH') || exit;

$button = isset($button) ? $button : 'Save';
$resource_label = isset($resource_label) ? $resource_label : __('Signature field', 'smbb-signconnect');
$type_options = class_exists('\Smbb\SignConnect\Support\SignatureFieldType')
    ? array_flip(\Smbb\SignConnect\Support\SignatureFieldType::labels())
    : array(__('Signature', 'smbb-signconnect') => 'signature');

$fields = array(
    $form->section(
        __('Signature field', 'smbb-signconnect'),
        __('Signature placement coordinates are stored as page ratios.', 'smbb-signconnect'),
        array(
            $form->number(__('Document ID', 'smbb-signconnect'))->setName('document_id')->required(),
            $form->number(__('Page', 'smbb-signconnect'))->setName('page_number')->required(),
            $form->row(
                $form->number(__('X', 'smbb-signconnect'), array('step' => 0.000001))->setName('x')->required(),
                $form->number(__('Y', 'smbb-signconnect'), array('step' => 0.000001))->setName('y')->required()
            ),
            $form->row(
                $form->number(__('Width', 'smbb-signconnect'), array('step' => 0.000001))->setName('width')->required(),
                $form->number(__('Height', 'smbb-signconnect'), array('step' => 0.000001))->setName('height')->required()
            ),
            $form->select(__('Field type', 'smbb-signconnect'))->setName('field_type')->setOptions($type_options),
            $form->text(__('Label', 'smbb-signconnect'))->setName('label')
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
