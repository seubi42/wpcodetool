<?php

/**
 * Admin form for SignConnect storages.
 *
 * @var object $form
 * @var string $button
 */

defined('ABSPATH') || exit;

$button = isset($button) ? $button : 'Save';
$resource_label = isset($resource_label) ? $resource_label : __('Sign storage', 'smbb-signconnect');

$fields = array(
    $form->section(
        __('Storage', 'smbb-signconnect'),
        __('S3-compatible connection settings.', 'smbb-signconnect'),
        array(
            $form->text(__('Name', 'smbb-signconnect'))
                ->setName('name')
                ->required(),
            $form->row(
                $form->text(__('Endpoint', 'smbb-signconnect'))
                    ->setName('endpoint')
                    ->required(),
                $form->text(__('Region', 'smbb-signconnect'))
                    ->setName('region')
                    ->required()
            ),
            $form->row(
                $form->text(__('Bucket', 'smbb-signconnect'))
                    ->setName('bucket')
                    ->required(),
                $form->text(__('Base prefix', 'smbb-signconnect'))
                    ->setName('base_prefix')
            )
        )
    )->setIcon('dashicons-cloud'),
    $form->section(
        __('Credentials', 'smbb-signconnect'),
        __('Access keys used to connect to the S3-compatible storage.', 'smbb-signconnect'),
        array(
            $form->row(
                $form->text(__('Access key', 'smbb-signconnect'))
                    ->setName('access_key')
                    ->required(),
                $form->password(__('Secret key', 'smbb-signconnect'))
                    ->setName('secret_key')
                    ->required()
            ),
            $form->row(
                $form->toggle(__('Use path style endpoint', 'smbb-signconnect'))
                    ->setName('use_path_style_endpoint'),
                $form->toggle(__('Active', 'smbb-signconnect'))
                    ->setName('is_active')
            )
        )
    )->setIcon('dashicons-admin-network')
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
