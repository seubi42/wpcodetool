<?php

/**
 * Admin form for SignConnect documents.
 *
 * @var object $form
 * @var string $button
 */

defined('ABSPATH') || exit;

$button = isset($button) ? $button : 'Save';
$resource_label = isset($resource_label) ? $resource_label : __('Sign document', 'smbb-signconnect');

$fields = array(
    $form->section(
        __('Document', 'smbb-signconnect'),
        __('Signature tracking information.', 'smbb-signconnect'),
        array(
            $form->text(__('Filename', 'smbb-signconnect'))
                ->setName('filename')
                ->required(),
            $form->row(
                $form->search(__('Storage', 'smbb-signconnect'), array(
                    'resource' => 'signstorage',
                    'labelField' => 'name',
                    'valueField' => 'id',
                    'searchFields' => array('name', 'bucket', 'endpoint'),
                    'placeholder' => __('Search storage...', 'smbb-signconnect'),
                ))
                    ->setName('storage_id'),
                $form->text(__('Storage path', 'smbb-signconnect'))
                    ->setName('storage_path')
            ),
            $form->number(__('File size (bytes)', 'smbb-signconnect'))
                ->setName('file_size'),
            $form->text(__('Status', 'smbb-signconnect'))
                ->setName('document_status'),
            $form->text(__('Token', 'smbb-signconnect'))
                ->setName('token'),
            $form->text(__('Signature token', 'smbb-signconnect'))
                ->setName('signature_token'),
            $form->section(
                __('Sending', 'smbb-signconnect'),
                __('Remote signature delivery settings.', 'smbb-signconnect'),
                array(
                    $form->row(
                        $form->select(__('Channel', 'smbb-signconnect'))
                            ->setName('send_channel')
                            ->setOptions(array(
                                '' => __('Not selected', 'smbb-signconnect'),
                                'email' => __('Email', 'smbb-signconnect'),
                                'sms' => __('SMS', 'smbb-signconnect'),
                            )),
                        $form->datetime(__('Link expires at', 'smbb-signconnect'))
                            ->setName('link_expires_at')
                    ),
                    $form->row(
                        $form->text(__('Recipient email', 'smbb-signconnect'))
                            ->setName('recipient_email'),
                        $form->text(__('Recipient phone', 'smbb-signconnect'))
                            ->setName('recipient_phone')
                    ),
                    $form->textarea(__('Message', 'smbb-signconnect'))
                        ->setName('send_message'),
                    $form->toggle(__('Require identity photo', 'smbb-signconnect'))
                        ->setName('require_identity_photo')
                )
            )->setIcon('dashicons-email-alt'),
            $form->row(
                $form->datetime(__('Sign date', 'smbb-signconnect'))
                    ->setName('sign_date'),
                $form->number(__('Signed by', 'smbb-signconnect'))
                    ->setName('sign_by')
            )
        )
    )->setIcon('dashicons-media-document')
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
