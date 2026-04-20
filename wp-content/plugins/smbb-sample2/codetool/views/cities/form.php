<?php

/**
 * Minimal admin form for the "cities" sample resource.
 *
 * @var object $form
 * @var string $button
 */

defined('ABSPATH') || exit;

$button = isset($button) ? $button : 'Save';
$resource_label = isset($resource_label) ? $resource_label : __('City', 'smbb-sample2');

$fields = array(
    $form->section(
        __('City', 'smbb-sample2'),
        __('Minimal resource used to validate a second plugin and namespace.', 'smbb-sample2'),
        array(
            $form->text(__('Name', 'smbb-sample2'))
                ->setName('name')
                ->required(),
            $form->row(
                $form->number(__('Latitude', 'smbb-sample2'), array('step' => 0.000001))
                    ->setName('lat')
                    ->required(),
                $form->number(__('Longitude', 'smbb-sample2'), array('step' => 0.000001))
                    ->setName('lng')
                    ->required()
            )
        )
    )->setIcon('dashicons-location-alt')
);

$html = '<div class="form_container">';
$html .= $form->save(__($button, 'smbb-sample2'))->setFields($fields);
$html .= '</div>';

echo '<div class="wrap smbb-codetool">';

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
