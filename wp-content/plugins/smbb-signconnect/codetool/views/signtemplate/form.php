<?php

/**
 * Admin form for SignConnect signature templates.
 *
 * @var object $form
 * @var string $button
 */

defined('ABSPATH') || exit;

$button = isset($button) ? $button : 'Save';
$resource_label = isset($resource_label) ? $resource_label : __('Signature template', 'smbb-signconnect');
$item = isset($item) && is_array($item) ? $item : array();

$sample_pdf_attachment_id = isset($item['sample_pdf_attachment_id']) ? absint($item['sample_pdf_attachment_id']) : 0;
$sample_pdf_url = isset($item['sample_pdf_url']) ? (string) $item['sample_pdf_url'] : '';

if ($sample_pdf_url === '' && $sample_pdf_attachment_id > 0) {
    $attachment_url = wp_get_attachment_url($sample_pdf_attachment_id);
    $sample_pdf_url = $attachment_url ? (string) $attachment_url : '';
}

$detection_zones = isset($item['detection_zones']) && (string) $item['detection_zones'] !== '' ? (string) $item['detection_zones'] : '[]';
$signature_zones = isset($item['signature_zones']) && (string) $item['signature_zones'] !== '' ? (string) $item['signature_zones'] : '[]';

wp_enqueue_media();
wp_enqueue_script(
    'smbb-signconnect-admin-template-editor',
    SMBB_SIGNCONNECT_URL . 'assets/admin-template-editor.js',
    array(),
    SMBB_SIGNCONNECT_VERSION,
    true
);
wp_enqueue_style(
    'smbb-signconnect-admin-template-editor',
    SMBB_SIGNCONNECT_URL . 'assets/admin-template-editor.css',
    array(),
    SMBB_SIGNCONNECT_VERSION
);
wp_print_styles('smbb-signconnect-admin-template-editor');

$field_type_labels = class_exists('\Smbb\SignConnect\Support\SignatureFieldType')
    ? \Smbb\SignConnect\Support\SignatureFieldType::labels()
    : array('signature' => __('Signature', 'smbb-signconnect'));
$criticality_options = array(
    __('No override', 'smbb-signconnect') => '',
    __('Low', 'smbb-signconnect') => 'low',
    __('Medium', 'smbb-signconnect') => 'medium',
    __('Critical', 'smbb-signconnect') => 'critical',
);

wp_localize_script('smbb-signconnect-admin-template-editor', 'SmbbSignTemplateEditor', array(
    'fieldTypeLabels' => $field_type_labels,
    'i18n' => array(
        'loading' => __('Loading PDF...', 'smbb-signconnect'),
        'drawDetection' => __('Draw detection zone', 'smbb-signconnect'),
        'drawSignature' => __('Draw signature zone', 'smbb-signconnect'),
        'missingPdf' => __('Choose or enter a sample PDF URL.', 'smbb-signconnect'),
        'pdfLoadError' => __('The sample PDF could not be loaded.', 'smbb-signconnect'),
    ),
));

$editor_html = '<section class="smbb-signconnect-template-editor" data-signconnect-template-editor data-pdf-url="' . esc_url($sample_pdf_url) . '">';
$editor_html .= '<input type="hidden" name="sample_pdf_attachment_id" value="' . esc_attr((string) $sample_pdf_attachment_id) . '" data-template-pdf-attachment>';
$editor_html .= '<input type="hidden" name="detection_zones" value="' . esc_attr($detection_zones) . '" data-template-detection-input>';
$editor_html .= '<input type="hidden" name="signature_zones" value="' . esc_attr($signature_zones) . '" data-template-signature-input>';
$editor_html .= '<header class="smbb-signconnect-template-editor-header">';
$editor_html .= '<div><h3>' . esc_html__('PDF template editor', 'smbb-signconnect') . '</h3><p>' . esc_html__('Choose a sample PDF, then draw detection zones and signature zones on the same preview.', 'smbb-signconnect') . '</p></div>';
$editor_html .= '</header>';
$editor_html .= '<div class="smbb-signconnect-template-pdf-row">';
$editor_html .= '<label class="smbb-signconnect-template-pdf-url"><span>' . esc_html__('Sample PDF URL', 'smbb-signconnect') . '</span><input type="url" name="sample_pdf_url" value="' . esc_attr($sample_pdf_url) . '" placeholder="https://..." data-template-pdf-url></label>';
$editor_html .= '<div class="smbb-signconnect-template-pdf-actions">';
$editor_html .= '<button type="button" class="button" data-template-pdf-select><span class="dashicons dashicons-media-document" aria-hidden="true"></span> ' . esc_html__('Choose PDF', 'smbb-signconnect') . '</button>';
$editor_html .= '<button type="button" class="button button-primary" data-template-pdf-load>' . esc_html__('Preview', 'smbb-signconnect') . '</button>';
$editor_html .= '</div>';
$editor_html .= '</div>';
$editor_html .= '<div class="smbb-signconnect-template-message" data-template-message></div>';
$editor_html .= '<div class="smbb-signconnect-template-toolbar" role="toolbar">';
$editor_html .= '<button type="button" class="button button-primary is-active" data-template-mode="detection">' . esc_html__('Detection zones', 'smbb-signconnect') . '</button>';
$editor_html .= '<button type="button" class="button" data-template-mode="signature">' . esc_html__('Signature zones', 'smbb-signconnect') . '</button>';
$editor_html .= '<span data-template-page-label></span>';
$editor_html .= '</div>';
$editor_html .= '<div class="smbb-signconnect-template-layout">';
$editor_html .= '<aside class="smbb-signconnect-template-thumbs" data-template-thumbs></aside>';
$editor_html .= '<main class="smbb-signconnect-template-stage">';
$editor_html .= '<div class="smbb-signconnect-template-page-shell"><canvas data-template-canvas></canvas><div class="smbb-signconnect-template-layer" data-template-layer></div></div>';
$editor_html .= '</main>';
$editor_html .= '<aside class="smbb-signconnect-template-sidebar">';
$editor_html .= '<h4>' . esc_html__('Zones', 'smbb-signconnect') . '</h4>';
$editor_html .= '<div data-template-zone-list></div>';
$editor_html .= '</aside>';
$editor_html .= '</div>';
$editor_html .= '</section>';

$fields = array(
    $form->section(
        __('Template', 'smbb-signconnect'),
        __('Name this layout and keep it active when it should be used for automatic matching.', 'smbb-signconnect'),
        array(
            $form->text(__('Name', 'smbb-signconnect'))->setName('name')->required(),
            $form->textarea(__('Description', 'smbb-signconnect'), array('rows' => 3))->setName('description'),
            $form->select(__('Document criticality override', 'smbb-signconnect'))
                ->setName('criticality')
                ->setOptions($criticality_options)
                ->setHelp(__('Optional. When this template matches a document, this criticality is imposed and the sender cannot change it.', 'smbb-signconnect')),
            $form->toggle(__('Active', 'smbb-signconnect'))->setName('is_active')
        )
    )->setIcon('dashicons-layout'),
    $editor_html
);

$html = '<div class="form_container">';
$html .= $form->save(__($button, 'smbb-signconnect'))->setFields($fields);
$html .= '</div>';

echo '<div class="wrap smbb-codetool smbb-signconnect">';

if (!empty($page_header_html)) {
    echo $page_header_html;
} else {
    echo '<div class="smbb-codetool-page-header">';
    echo '<div class="smbb-codetool-page-header-main">';
    echo '<h1 class="smbb-codetool-page-title">' . esc_html($resource_label) . '</h1>';
    echo '</div>';
    echo '</div>';
}

if (!empty($notices_html)) {
    echo $notices_html;
}

echo $html;
echo '</div>';
