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

echo $html;
echo '</div>';
