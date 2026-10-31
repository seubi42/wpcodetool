<?php

/**
 * SignConnect settings.
 *
 * @var object $form
 * @var string $button
 */

defined('ABSPATH') || exit;

$button = isset($button) ? $button : 'Save settings';
$resource_label = isset($resource_label) ? $resource_label : __('SignConnect settings', 'smbb-signconnect');
$certification_method_options = class_exists('\Smbb\SignConnect\Support\CertificationMethod')
    ? \Smbb\SignConnect\Support\CertificationMethod::labels()
    : array(
        'none' => __('No certification', 'smbb-signconnect'),
        'self_signed_certificate' => __('Self-signed certificate', 'smbb-signconnect'),
        'local_certificate' => __('Local certificate', 'smbb-signconnect'),
        'yousign_qualified_seal' => __('Qualified Yousign seal', 'smbb-signconnect'),
        'yousign_signature_flow' => __('Yousign signing flow', 'smbb-signconnect'),
        'universign_signature_flow' => __('Universign signing flow', 'smbb-signconnect'),
    );

$page_options = array(__('Select a page', 'smbb-signconnect') => '0');
$pages = get_pages(array(
    'sort_column' => 'post_title',
    'sort_order' => 'ASC',
    'post_status' => array('publish', 'private', 'draft'),
));

foreach ($pages as $page) {
    $page_options[$page->post_title . ' (#' . $page->ID . ')'] = (string) $page->ID;
}

$twilio_test_html = '<div class="smbb-codetool-section">';
$twilio_test_html .= '<header class="smbb-codetool-section-header">';
$twilio_test_html .= '<span class="smbb-codetool-section-icon" aria-hidden="true"><span class="dashicons dashicons-smartphone"></span></span>';
$twilio_test_html .= '<div class="smbb-codetool-section-heading">';
$twilio_test_html .= '<h3>' . esc_html__('Test SMS sending', 'smbb-signconnect') . '</h3>';
$twilio_test_html .= '<p class="description">' . esc_html__('Uses the Twilio fields currently entered on this screen. Remember to save afterwards if the test is successful.', 'smbb-signconnect') . '</p>';
$twilio_test_html .= '</div></header>';
$twilio_test_html .= '<div class="smbb-codetool-section-body">';
$twilio_test_html .= wp_nonce_field('smbb_signconnect_test_twilio', '_wpnonce_twilio_test', true, false);
$twilio_test_html .= '<div class="smbb-codetool-row">';
$twilio_test_html .= '<div class="smbb-codetool-field"><label class="smbb-codetool-field-label" for="smbb-signconnect-twilio-test-phone">' . esc_html__('Phone', 'smbb-signconnect') . '</label><input type="tel" id="smbb-signconnect-twilio-test-phone" name="twilio_test_phone" class="regular-text" placeholder="+33600000000"></div>';
$twilio_test_html .= '<div class="smbb-codetool-field"><label class="smbb-codetool-field-label" for="smbb-signconnect-twilio-test-message">' . esc_html__('Message', 'smbb-signconnect') . '</label><input type="text" id="smbb-signconnect-twilio-test-message" name="twilio_test_message" class="regular-text" value="' . esc_attr__('Test SMS SignConnect.', 'smbb-signconnect') . '"></div>';
$twilio_test_html .= '</div>';
$twilio_test_html .= '<p><button type="submit" class="button button-secondary" name="action" value="smbb_signconnect_test_twilio" formaction="' . esc_url(admin_url('admin-post.php')) . '" formmethod="post">' . esc_html__('Send test SMS', 'smbb-signconnect') . '</button></p>';
$twilio_test_html .= '</div></div>';

$certification_status = class_exists('\Smbb\SignConnect\Service\CertificationService')
    ? (new \Smbb\SignConnect\Service\CertificationService())->status()
    : array();
$signconnect_settings = class_exists('\Smbb\SignConnect\Support\SignConnectSettings')
    ? \Smbb\SignConnect\Support\SignConnectSettings::all()
    : array();

$manual_toggle = function ($name, $label, $help = '') use ($signconnect_settings) {
    $checked = !empty($signconnect_settings[$name]);
    $field_id = 'smbb-signconnect-' . str_replace('_', '-', $name);
    $state_label = $checked ? __('Enabled', 'smbb-wpcodetool') : __('Disabled', 'smbb-wpcodetool');

    $html = '<div class="smbb-codetool-field">';
    $html .= '<label class="smbb-codetool-field-label" for="' . esc_attr($field_id) . '">' . esc_html($label) . '</label>';
    $html .= '<input type="hidden" name="' . esc_attr($name) . '" value="0">';
    $html .= '<label class="smbb-codetool-toggle" for="' . esc_attr($field_id) . '">';
    $html .= '<input class="smbb-codetool-toggle-input" type="checkbox" id="' . esc_attr($field_id) . '" name="' . esc_attr($name) . '" value="1"' . checked($checked, true, false) . '>';
    $html .= '<span class="smbb-codetool-toggle-track" aria-hidden="true"><span class="smbb-codetool-toggle-thumb"></span></span>';
    $html .= '<span class="smbb-codetool-toggle-state" data-on="' . esc_attr__('Enabled', 'smbb-wpcodetool') . '" data-off="' . esc_attr__('Disabled', 'smbb-wpcodetool') . '">' . esc_html($state_label) . '</span>';
    $html .= '</label>';

    if ($help !== '') {
        $html .= '<p class="description">' . esc_html($help) . '</p>';
    }

    $html .= '</div>';

    return $html;
};

$certification_html = '<div class="smbb-codetool-section">';
$certification_html .= '<header class="smbb-codetool-section-header">';
$certification_html .= '<span class="smbb-codetool-section-icon" aria-hidden="true"><span class="dashicons dashicons-shield-alt"></span></span>';
$certification_html .= '<div class="smbb-codetool-section-heading">';
$certification_html .= '<h3>' . esc_html__('Self-signed certificate', 'smbb-signconnect') . '</h3>';
$certification_html .= '<p class="description">' . esc_html__('Creates a local self-signed certificate for SignConnect integrity proofs. This does not replace a qualified third-party certificate.', 'smbb-signconnect') . '</p>';
$certification_html .= '</div></header>';
$certification_html .= '<div class="smbb-codetool-section-body">';
$certification_html .= wp_nonce_field('smbb_signconnect_generate_certificate', '_wpnonce_certification', true, false);
$certification_html .= $manual_toggle(
    'certification_self_signed_enabled',
    __('Enable self-signed certificate method', 'smbb-signconnect'),
    __('Makes the self-signed local certificate available in certification strategy.', 'smbb-signconnect')
);
$certification_html .= '<p class="description"><strong>' . esc_html__('Current mode:', 'smbb-signconnect') . '</strong> ' . esc_html(!empty($certification_status['mode']) && (string) $certification_status['mode'] === 'imported' ? __('Imported certificate', 'smbb-signconnect') : __('Self-signed certificate', 'smbb-signconnect')) . '</p>';
$certification_html .= '<div class="smbb-codetool-row">';
$certification_html .= '<div class="smbb-codetool-field"><label class="smbb-codetool-field-label" for="smbb-signconnect-cert-cn">' . esc_html__('Common name', 'smbb-signconnect') . '</label><input type="text" id="smbb-signconnect-cert-cn" name="certification_common_name" class="regular-text" value="' . esc_attr(wp_parse_url(home_url('/'), PHP_URL_HOST)) . '"></div>';
$certification_html .= '<div class="smbb-codetool-field"><label class="smbb-codetool-field-label" for="smbb-signconnect-cert-org">' . esc_html__('Organization', 'smbb-signconnect') . '</label><input type="text" id="smbb-signconnect-cert-org" name="certification_organization" class="regular-text" value="' . esc_attr(get_bloginfo('name')) . '"></div>';
$certification_html .= '</div>';
$certification_html .= '<div class="smbb-codetool-row">';
$certification_html .= '<div class="smbb-codetool-field"><label class="smbb-codetool-field-label" for="smbb-signconnect-cert-email">' . esc_html__('Email', 'smbb-signconnect') . '</label><input type="email" id="smbb-signconnect-cert-email" name="certification_email" class="regular-text" value="' . esc_attr(get_option('admin_email')) . '"></div>';
$certification_html .= '<div class="smbb-codetool-field"><label class="smbb-codetool-field-label" for="smbb-signconnect-cert-country">' . esc_html__('Country', 'smbb-signconnect') . '</label><input type="text" id="smbb-signconnect-cert-country" name="certification_country" class="small-text" maxlength="2" value="FR"></div>';
$certification_html .= '<div class="smbb-codetool-field"><label class="smbb-codetool-field-label" for="smbb-signconnect-cert-days">' . esc_html__('Validity, in days', 'smbb-signconnect') . '</label><input type="number" id="smbb-signconnect-cert-days" name="certification_valid_days" class="small-text" min="1" max="3650" value="365"></div>';
$certification_html .= '</div>';

if (!empty($certification_status['has_certificate'])) {
    $certification_html .= '<p class="description"><strong>' . esc_html__('Current certificate fingerprint:', 'smbb-signconnect') . '</strong> <code>' . esc_html((string) $certification_status['fingerprint']) . '</code></p>';
}

$readable_label = __('Readable', 'smbb-signconnect');
$missing_label = __('Missing or unreadable', 'smbb-signconnect');
$certification_html .= '<p class="description"><strong>' . esc_html__('Certificate file:', 'smbb-signconnect') . '</strong> ' . esc_html(!empty($certification_status['certificate_readable']) ? $readable_label : $missing_label) . '</p>';
$certification_html .= '<p class="description"><strong>' . esc_html__('Private key:', 'smbb-signconnect') . '</strong> ' . esc_html(!empty($certification_status['private_key_readable']) ? $readable_label : $missing_label) . '</p>';
$certification_html .= '<p class="description"><strong>' . esc_html__('PDF cryptographic signature:', 'smbb-signconnect') . '</strong> ' . esc_html(!empty($certification_status['tcpdf_available']) ? __('TCPDF is available.', 'smbb-signconnect') : __('TCPDF is not available yet; hashes and certificate proof remain active.', 'smbb-signconnect')) . '</p>';

foreach (array(
    'certification_certificate_mode',
    'certification_certificate_path',
    'certification_private_key_path',
    'certification_private_key_passphrase',
    'certification_certificate_fingerprint',
    'certification_certificate_subject',
    'certification_certificate_organization',
    'certification_certificate_email',
    'certification_certificate_created_at',
    'certification_certificate_expires_at',
    'certification_self_signed_certificate_path',
    'certification_self_signed_private_key_path',
    'certification_self_signed_certificate_fingerprint',
    'certification_self_signed_certificate_subject',
    'certification_self_signed_certificate_organization',
    'certification_self_signed_certificate_email',
    'certification_self_signed_certificate_created_at',
    'certification_self_signed_certificate_expires_at',
    'certification_imported_certificate_path',
    'certification_imported_private_key_path',
    'certification_imported_private_key_passphrase',
    'certification_imported_certificate_fingerprint',
    'certification_imported_certificate_subject',
    'certification_imported_certificate_organization',
    'certification_imported_certificate_email',
    'certification_imported_certificate_created_at',
    'certification_imported_certificate_expires_at',
) as $certification_hidden_key) {
    $certification_html .= '<input type="hidden" name="' . esc_attr($certification_hidden_key) . '" value="' . esc_attr(isset($signconnect_settings[$certification_hidden_key]) ? (string) $signconnect_settings[$certification_hidden_key] : '') . '">';
}

$certification_html .= '<p><button type="submit" class="button button-secondary" name="action" value="smbb_signconnect_generate_certificate" formaction="' . esc_url(admin_url('admin-post.php')) . '" formmethod="post">' . esc_html__('Generate self-signed certificate', 'smbb-signconnect') . '</button></p>';
$certification_html .= '</div></div>';

$import_certificate_html = '<div class="smbb-codetool-section">';
$import_certificate_html .= '<header class="smbb-codetool-section-header">';
$import_certificate_html .= '<span class="smbb-codetool-section-icon" aria-hidden="true"><span class="dashicons dashicons-upload"></span></span>';
$import_certificate_html .= '<div class="smbb-codetool-section-heading">';
$import_certificate_html .= '<h3>' . esc_html__('Imported certificate', 'smbb-signconnect') . '</h3>';
$import_certificate_html .= '<p class="description">' . esc_html__('Imports an external PEM certificate and matching private key for PDF cryptographic signatures.', 'smbb-signconnect') . '</p>';
$import_certificate_html .= '</div></header>';
$import_certificate_html .= '<div class="smbb-codetool-section-body">';
$import_certificate_html .= wp_nonce_field('smbb_signconnect_import_certificate', '_wpnonce_certification_import', true, false);
$import_certificate_html .= $manual_toggle(
    'certification_imported_enabled',
    __('Enable local certificate method', 'smbb-signconnect'),
    __('Makes the imported PEM certificate available in certification strategy.', 'smbb-signconnect')
);
$import_certificate_html .= '<div class="smbb-codetool-row">';
$import_certificate_html .= '<div class="smbb-codetool-field"><label class="smbb-codetool-field-label" for="smbb-signconnect-import-cert">' . esc_html__('Certificate PEM', 'smbb-signconnect') . '</label><input type="file" id="smbb-signconnect-import-cert" name="certification_import_certificate" accept=".pem,.crt,.cer,text/plain,application/x-pem-file"></div>';
$import_certificate_html .= '<div class="smbb-codetool-field"><label class="smbb-codetool-field-label" for="smbb-signconnect-import-key">' . esc_html__('Private key PEM', 'smbb-signconnect') . '</label><input type="file" id="smbb-signconnect-import-key" name="certification_import_private_key" accept=".pem,.key,text/plain,application/x-pem-file"></div>';
$import_certificate_html .= '</div>';
$import_certificate_html .= '<div class="smbb-codetool-field"><label class="smbb-codetool-field-label" for="smbb-signconnect-import-passphrase">' . esc_html__('Private key passphrase', 'smbb-signconnect') . '</label><input type="password" id="smbb-signconnect-import-passphrase" name="certification_import_private_key_passphrase" class="regular-text" autocomplete="new-password"><p class="description">' . esc_html__('Leave empty if the private key is not encrypted.', 'smbb-signconnect') . '</p></div>';
$import_certificate_html .= '<p><button type="submit" class="button button-secondary" name="action" value="smbb_signconnect_import_certificate" formaction="' . esc_url(admin_url('admin-post.php')) . '" formmethod="post">' . esc_html__('Import external certificate', 'smbb-signconnect') . '</button></p>';
$import_certificate_html .= '</div></div>';

$integrity_proof_html = '<div class="smbb-codetool-section">';
$integrity_proof_html .= '<header class="smbb-codetool-section-header">';
$integrity_proof_html .= '<span class="smbb-codetool-section-icon" aria-hidden="true"><span class="dashicons dashicons-shield"></span></span>';
$integrity_proof_html .= '<div class="smbb-codetool-section-heading">';
$integrity_proof_html .= '<h3>' . esc_html__('Integrity evidence', 'smbb-signconnect') . '</h3>';
$integrity_proof_html .= '<p class="description">' . esc_html__('Optional evidence stored with the signed document, regardless of the certification method selected by strategy.', 'smbb-signconnect') . '</p>';
$integrity_proof_html .= '</div></header>';
$integrity_proof_html .= '<div class="smbb-codetool-section-body">';
$integrity_proof_html .= $manual_toggle(
    'certification_hash_proof_enabled',
    __('Record PDF SHA-256 hashes', 'smbb-signconnect'),
    __('Stores the original PDF hash and the final signed PDF hash when the files are available. Useful to prove that a downloaded document matches the archived proof.', 'smbb-signconnect')
);
$integrity_proof_html .= '</div></div>';

$strategy_select = function ($name, $label) use ($signconnect_settings, $certification_method_options) {
    $current = isset($signconnect_settings[$name]) ? \Smbb\SignConnect\Support\CertificationMethod::normalize((string) $signconnect_settings[$name]) : 'none';
    $html = '<div class="smbb-codetool-field">';
    $html .= '<label class="smbb-codetool-field-label" for="smbb-signconnect-' . esc_attr($name) . '">' . esc_html($label) . '</label>';
    $html .= '<select id="smbb-signconnect-' . esc_attr($name) . '" name="' . esc_attr($name) . '">';

    foreach ($certification_method_options as $value => $option_label) {
        $available = \Smbb\SignConnect\Support\SignConnectSettings::certificationMethodAvailable($value);
        $html .= '<option value="' . esc_attr($value) . '"' . selected($current, $value, false) . disabled(!$available && $current !== $value, true, false) . '>';
        $html .= esc_html($option_label);
        if (!$available) {
            $html .= ' - ' . esc_html__('not enabled', 'smbb-signconnect');
        }
        $html .= '</option>';
    }

    $html .= '</select>';
    $html .= '</div>';

    return $html;
};

$strategy_html = '<div class="smbb-codetool-section">';
$strategy_html .= '<header class="smbb-codetool-section-header">';
$strategy_html .= '<span class="smbb-codetool-section-icon" aria-hidden="true"><span class="dashicons dashicons-admin-settings"></span></span>';
$strategy_html .= '<div class="smbb-codetool-section-heading">';
$strategy_html .= '<h3>' . esc_html__('Criticality strategy', 'smbb-signconnect') . '</h3>';
$strategy_html .= '<p class="description">' . esc_html__('Choose the certification method applied to each document criticality. Disabled methods are shown but cannot be selected until enabled in Certification methods.', 'smbb-signconnect') . '</p>';
$strategy_html .= '</div></header>';
$strategy_html .= '<div class="smbb-codetool-section-body">';
$strategy_html .= '<div class="smbb-codetool-row">';
$strategy_html .= $strategy_select('criticality_low_method', __('Low', 'smbb-signconnect'));
$strategy_html .= $strategy_select('criticality_medium_method', __('Medium', 'smbb-signconnect'));
$strategy_html .= $strategy_select('criticality_critical_method', __('Critical', 'smbb-signconnect'));
$strategy_html .= '</div>';
$strategy_html .= '</div></div>';

$fields = array(
    $form->tabs(
        $form->tab(
            __('General', 'smbb-signconnect'),
            '',
            array(
                $form->section(
                    __('Appearance', 'smbb-signconnect'),
                    __('Visual preferences for the front-end flow.', 'smbb-signconnect'),
                    array(
                        $form->color(__('Primary color', 'smbb-signconnect'), array('default' => '#2271b1'))
                            ->setName('brand_color')
                    )
                )->setIcon('dashicons-art'),
            )
        ),
        $form->tab(
            __('Pages', 'smbb-signconnect'),
            '',
            array(
                $form->section(
                    __('Flow pages', 'smbb-signconnect'),
                    __('WordPress pages used by the front-end shortcodes.', 'smbb-signconnect'),
                    array(
                        $form->select(__('Posting page', 'smbb-signconnect'))
                            ->setName('posting_page_id')
                            ->setOptions($page_options)
                            ->setHelp(__('WordPress page containing the [signconnect_post] shortcode.', 'smbb-signconnect')),
                        $form->select(__('Public signing page', 'smbb-signconnect'))
                            ->setName('signing_page_id')
                            ->setOptions($page_options)
                            ->setHelp(__('WordPress page containing the [signconnect_sign] shortcode.', 'smbb-signconnect'))
                    )
                )->setIcon('dashicons-admin-page')
            )
        ),
        $form->tab(
            __('Sending', 'smbb-signconnect'),
            '',
            array(
                $form->section(
                    __('Sending preferences', 'smbb-signconnect'),
                    __('Default values used in the sending step.', 'smbb-signconnect'),
                    array(
                        $form->textarea(__('Default message', 'smbb-signconnect'), array('rows' => 5))
                            ->setName('default_send_message')
                            ->setHelp(__('Used when the document does not yet have a custom message.', 'smbb-signconnect')),
                        $form->row(
                            $form->number(__('Default expiration', 'smbb-signconnect'), array('min' => 1, 'step' => 1))
                                ->setName('default_expiration_days')
                                ->setHelp(__('Number of days pre-filled in the sending form.', 'smbb-signconnect')),
                            $form->number(__('Expiration minimum', 'smbb-signconnect'), array('min' => 1, 'step' => 1))
                                ->setName('min_expiration_days')
                                ->setHelp(__('Lower bound accepted on both the front end and server side.', 'smbb-signconnect')),
                            $form->number(__('Expiration maximum', 'smbb-signconnect'), array('min' => 1, 'step' => 1))
                                ->setName('max_expiration_days')
                                ->setHelp(__('Upper bound accepted on both the front end and server side.', 'smbb-signconnect'))
                        )
                    )
                )->setIcon('dashicons-email-alt'),
            )
        ),
        $form->tab(
            __('AI', 'smbb-signconnect'),
            '',
            array(
                $form->section(
                    __('OpenAI', 'smbb-signconnect'),
                    __('Configuration reserved for document intelligence features.', 'smbb-signconnect'),
                    array(
                        $form->toggle(__('Enable AI', 'smbb-signconnect'))
                            ->setName('openai_enabled')
                            ->setHelp(__('When disabled, the key remains stored but no AI suggestion is offered.', 'smbb-signconnect')),
                        $form->toggle(__('Automatic message suggestion', 'smbb-signconnect'))
                            ->setName('openai_auto_suggest_message')
                            ->setHelp(__('When enabled, the message is prepared server-side just after the PDF upload.', 'smbb-signconnect')),
                        $form->toggle(__('Suggest signature area', 'smbb-signconnect'))
                            ->setName('openai_suggest_signature_zone')
                            ->setHelp(__('When enabled, a likely signature area is created automatically after upload.', 'smbb-signconnect')),
                        $form->password(__('OpenAI API key', 'smbb-signconnect'), array('autocomplete' => 'new-password'))
                            ->setName('openai_api_key')
                            ->setHelp(__('Stored in WordPress options. Server-side use only.', 'smbb-signconnect'))
                    )
                )->setIcon('dashicons-superhero')
            )
        ),
        $form->tab(
            __('Debug', 'smbb-signconnect'),
            '',
            array(
                $form->section(
                    __('Template detection', 'smbb-signconnect'),
                    __('Diagnostics written to the document audit trail after upload.', 'smbb-signconnect'),
                    array(
                        $form->toggle(__('Log template detection details', 'smbb-signconnect'))
                            ->setName('template_detection_debug_enabled')
                            ->setHelp(__('When enabled, every upload records the tested templates, resolved pages, expected texts and extracted texts. Disable it after diagnosis.', 'smbb-signconnect')),
                        $form->text(__('pdftotext path', 'smbb-signconnect'))
                            ->setName('template_detection_pdftotext_path')
                            ->setHelp(__('Optional. Leave empty to use pdftotext from PATH, or set the full executable path if PHP cannot find it.', 'smbb-signconnect'))
                    )
                )->setIcon('dashicons-search')
            )
        ),
        $form->tab(
            __('Geolocation', 'smbb-signconnect'),
            '',
            array(
                $form->section(
                    __('Geolocation', 'smbb-signconnect'),
                    __('Settings used during public signing.', 'smbb-signconnect'),
                    array(
                        $form->toggle(__('Enable geo.smbb-logiciel.com', 'smbb-signconnect'))
                            ->setName('geodecode_enabled')
                            ->setHelp(__('When enabled, the geolocation button converts GPS coordinates into a city name through the SMBB service.', 'smbb-signconnect'))
                    )
                )->setIcon('dashicons-location-alt')
            )
        ),
        $form->tab(
            __('Certification methods', 'smbb-signconnect'),
            '',
            array(
                $form->section(
                    __('Yousign', 'smbb-signconnect'),
                    __('Technical settings shared by Yousign seal and Yousign signing flow methods.', 'smbb-signconnect'),
                    array(
                        $form->row(
                            $form->toggle(__('Enable qualified Yousign seal method', 'smbb-signconnect'))
                                ->setName('yousign_seal_enabled')
                                ->setHelp(__('Makes the Yousign qualified seal available in certification strategy.', 'smbb-signconnect')),
                            $form->toggle(__('Enable Yousign signing flow method', 'smbb-signconnect'))
                                ->setName('yousign_signature_flow_enabled')
                                ->setHelp(__('Makes the Yousign signing journey available in certification strategy.', 'smbb-signconnect')),
                            $form->toggle(__('Disable return redirect', 'smbb-signconnect'))
                                ->setName('yousign_signature_disable_return_redirect')
                                ->setHelp(__('Trial Yousign subscriptions can require this option. The signer will not automatically return to SignConnect after signing.', 'smbb-signconnect'))
                        ),
                        $form->toggle(__('Use sandbox', 'smbb-signconnect'))
                            ->setName('yousign_sandbox')
                            ->setHelp(__('Uses https://api-sandbox.yousign.app/v3 when enabled, production otherwise.', 'smbb-signconnect')),
                        $form->password(__('Yousign API key', 'smbb-signconnect'), array('autocomplete' => 'new-password'))
                            ->setName('yousign_api_key')
                            ->setHelp(__('Stored in WordPress options. Server-side use only.', 'smbb-signconnect')),
                        $form->select(__('Signature level', 'smbb-signconnect'))
                            ->setName('yousign_signature_level')
                            ->setOptions(array(
                                __('Simple signature or seal', 'smbb-signconnect') => 'electronic_signature',
                                __('Advanced signature or seal', 'smbb-signconnect') => 'advanced_electronic_signature',
                                __('Qualified seal only', 'smbb-signconnect') => 'qualified_electronic_signature',
                            )),
                        $form->toggle(__('Timestamp', 'smbb-signconnect'))
                            ->setName('yousign_timestamp')
                            ->setHelp(__('Requests a timestamped seal from Yousign.', 'smbb-signconnect')),
                        $form->row(
                            $form->number(__('HTTP timeout', 'smbb-signconnect'), array('min' => 5, 'max' => 120, 'step' => 1))
                                ->setName('yousign_timeout'),
                            $form->number(__('Poll interval', 'smbb-signconnect'), array('min' => 1, 'max' => 10, 'step' => 1))
                                ->setName('yousign_poll_interval'),
                            $form->number(__('Max wait', 'smbb-signconnect'), array('min' => 5, 'max' => 120, 'step' => 1))
                                ->setName('yousign_max_wait_seconds')
                        )
                    )
                )->setIcon('dashicons-awards'),
                $form->section(
                    __('Universign', 'smbb-signconnect'),
                    __('Technical settings for the Universign transaction signing flow.', 'smbb-signconnect'),
                    array(
                        $form->row(
                            $form->toggle(__('Enable Universign signing flow method', 'smbb-signconnect'))
                                ->setName('universign_signature_flow_enabled')
                                ->setHelp(__('Makes the Universign signing journey available in certification strategy.', 'smbb-signconnect')),
                            $form->toggle(__('Disable return redirect', 'smbb-signconnect'))
                                ->setName('universign_disable_return_redirect')
                                ->setHelp(__('When enabled, the signer will not automatically return to SignConnect after signing.', 'smbb-signconnect'))
                        ),
                        $form->toggle(__('Use alpha/preprod', 'smbb-signconnect'))
                            ->setName('universign_sandbox')
                            ->setHelp(__('Uses https://api.alpha.universign.com when enabled, unless a custom API base URL is provided.', 'smbb-signconnect')),
                        $form->text(__('API base URL', 'smbb-signconnect'))
                            ->setName('universign_base_url')
                            ->setHelp(__('Optional. Leave empty to use Universign default API URLs, or set your tenant API URL if Universign provided one.', 'smbb-signconnect')),
                        $form->select(__('Authentication mode', 'smbb-signconnect'))
                            ->setName('universign_auth_mode')
                            ->setOptions(array(
                                __('Basic auth API key', 'smbb-signconnect') => 'basic',
                                __('Bearer token', 'smbb-signconnect') => 'bearer',
                            )),
                        $form->text(__('Return URL', 'smbb-signconnect'))
                            ->setName('universign_return_url')
                            ->setHelp(__('Optional HTTPS SignConnect signing page URL to whitelist in Universign redirection URLs. Leave empty to use the configured signing page.', 'smbb-signconnect')),
                        $form->password(__('Universign API key', 'smbb-signconnect'), array('autocomplete' => 'new-password'))
                            ->setName('universign_api_key')
                            ->setHelp(__('Stored in WordPress options. Server-side use only.', 'smbb-signconnect')),
                        $form->text(__('Master Account ID', 'smbb-signconnect'))
                            ->setName('universign_master_account_id')
                            ->setHelp(__('Stored for Universign master console operations. The signing flow uses the workspace API key.', 'smbb-signconnect')),
                        $form->row(
                            $form->text(__('Workspace ID', 'smbb-signconnect'))
                                ->setName('universign_workspace_id')
                                ->setHelp(__('Workspace shown in Universign. Transaction API keys are scoped to a workspace.', 'smbb-signconnect')),
                            $form->text(__('Plan ID', 'smbb-signconnect'))
                                ->setName('universign_plan_id')
                                ->setHelp(__('Plan shown in Universign. Stored for diagnostics; not sent with transaction requests.', 'smbb-signconnect'))
                        ),
                        $form->select(__('Signature level', 'smbb-signconnect'))
                            ->setName('universign_signature_level')
                            ->setOptions(array(
                                __('Level 0 - simple without authentication', 'smbb-signconnect') => 'level0',
                                __('Level 1 - simple', 'smbb-signconnect') => 'level1',
                                __('Level 2 - advanced', 'smbb-signconnect') => 'level2',
                                __('Level 3 - advanced qualified certificate', 'smbb-signconnect') => 'level3',
                                __('Level 4 - qualified', 'smbb-signconnect') => 'level4',
                            )),
                        $form->row(
                            $form->number(__('HTTP timeout', 'smbb-signconnect'), array('min' => 5, 'max' => 120, 'step' => 1))
                                ->setName('universign_timeout'),
                            $form->number(__('Poll interval', 'smbb-signconnect'), array('min' => 1, 'max' => 10, 'step' => 1))
                                ->setName('universign_poll_interval'),
                            $form->number(__('Max wait', 'smbb-signconnect'), array('min' => 5, 'max' => 120, 'step' => 1))
                                ->setName('universign_max_wait_seconds')
                        )
                    )
                )->setIcon('dashicons-media-document'),
                $certification_html,
                $import_certificate_html,
                $integrity_proof_html
            )
        ),
        $form->tab(
            __('Certification strategy', 'smbb-signconnect'),
            '',
            array(
                $strategy_html
            )
        ),
        $form->tab(
            __('Twilio', 'smbb-signconnect'),
            '',
            array(
                $form->section(
                    __('Twilio configuration', 'smbb-signconnect'),
                    __('Enables the SMS option in the sending form when the three fields are filled in.', 'smbb-signconnect'),
                    array(
                        $form->toggle(__('Enable Twilio', 'smbb-signconnect'))
                            ->setName('twilio_enabled')
                            ->setHelp(__('When disabled, the SMS channel is hidden and SMS sending is blocked.', 'smbb-signconnect')),
                        $form->text(__('Service', 'smbb-signconnect'))
                            ->setName('twilio_service')
                            ->setHelp(__('Twilio service identifier used for SMS sending.', 'smbb-signconnect')),
                        $form->text(__('SID', 'smbb-signconnect'))
                            ->setName('twilio_sid')
                            ->setHelp(__('Account SID Twilio.', 'smbb-signconnect')),
                        $form->password(__('Token', 'smbb-signconnect'), array('autocomplete' => 'new-password'))
                            ->setName('twilio_token')
                            ->setHelp(__('Twilio auth token. If this field is empty, SMS remains unavailable.', 'smbb-signconnect'))
                    )
                )->setIcon('dashicons-smartphone'),
                $twilio_test_html
            )
        )
    )
);

$html = '<div class="form_container">';
$html .= $form->save(__($button, 'smbb-signconnect'))->setFields($fields);
$html .= '</div>';

$twilio_notice = function_exists('smbb_signconnect_twilio_test_notice') ? smbb_signconnect_twilio_test_notice() : null;
$certification_notice = function_exists('smbb_signconnect_certification_notice') ? smbb_signconnect_certification_notice() : null;

echo '<div class="wrap smbb-codetool smbb-signconnect">';

if (!empty($page_header_html)) {
    echo $page_header_html;
} else {
    echo '<h1>' . esc_html($resource_label) . '</h1>';
}

if (!empty($notices_html)) {
    echo $notices_html;
}

if (is_array($twilio_notice)) {
    $notice_type = isset($twilio_notice['type']) && $twilio_notice['type'] === 'success' ? 'success' : 'error';
    echo '<div class="notice notice-' . esc_attr($notice_type) . '"><p>' . esc_html(isset($twilio_notice['message']) ? $twilio_notice['message'] : '') . '</p></div>';
}

if (is_array($certification_notice)) {
    $notice_type = isset($certification_notice['type']) && $certification_notice['type'] === 'success' ? 'success' : 'error';
    echo '<div class="notice notice-' . esc_attr($notice_type) . '"><p>' . esc_html(isset($certification_notice['message']) ? $certification_notice['message'] : '') . '</p></div>';
}

echo $html;
echo '</div>';
