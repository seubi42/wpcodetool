<?php
/**
 * Plugin Name: SignConnect
 * Description: SMBB business plugin for managing documents to sign with CodeTool.
 * Version: 0.1.44
 * Author: SMBB
 * Text Domain: smbb-signconnect
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 7.4
 */

defined('ABSPATH') || exit;

define('SMBB_SIGNCONNECT_FILE', __FILE__);
define('SMBB_SIGNCONNECT_PATH', plugin_dir_path(__FILE__));
define('SMBB_SIGNCONNECT_URL', plugin_dir_url(__FILE__));
define('SMBB_SIGNCONNECT_VERSION', '0.1.44');

if (!defined('SMBB_SIGNCONNECT_KEEP_AI_TEMP_FILES')) {
    define('SMBB_SIGNCONNECT_KEEP_AI_TEMP_FILES', true);
}

spl_autoload_register('smbb_signconnect_autoload');

function smbb_signconnect_autoload($class)
{
    $prefix = 'Smbb\\SignConnect\\';

    if (strpos($class, $prefix) !== 0) {
        return;
    }

    $relative_class = substr($class, strlen($prefix));
    $file = SMBB_SIGNCONNECT_PATH . 'src/' . str_replace('\\', DIRECTORY_SEPARATOR, $relative_class) . '.php';

    if (is_readable($file)) {
        require_once $file;
    }
}

add_action('plugins_loaded', 'smbb_signconnect_loaded');
add_action('smbb_wpcodetool_register_cron_tasks', 'smbb_signconnect_register_cron_tasks');
add_action('admin_post_smbb_signconnect_test_storage', 'smbb_signconnect_test_storage');
add_action('admin_post_smbb_signconnect_test_twilio', 'smbb_signconnect_test_twilio');
add_action('admin_post_smbb_signconnect_generate_certificate', 'smbb_signconnect_generate_certificate');
add_action('admin_post_smbb_signconnect_import_certificate', 'smbb_signconnect_import_certificate');

function smbb_signconnect_loaded()
{
    load_plugin_textdomain('smbb-signconnect', false, dirname(plugin_basename(__FILE__)) . '/languages');
    (new \Smbb\SignConnect\Plugin())->hooks();
}

function smbb_signconnect_register_cron_tasks($registry)
{
    if (!is_object($registry) || !method_exists($registry, 'register')) {
        return;
    }

    $registry->register('smbb_signconnect_purge_expired_documents', array(
        'label' => __('SignConnect - expired document purge', 'smbb-signconnect'),
        'description' => __('Deletes unsigned documents whose link has expired, including S3 cleanup.', 'smbb-signconnect'),
        'schedule' => 'smbb_every_15_minutes',
        'callback' => array(new \Smbb\SignConnect\Service\ExpiredDocumentPurgeService(), 'run'),
    ));
}

function smbb_signconnect_test_storage()
{
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('You are not allowed to test SignConnect storages.', 'smbb-signconnect'));
    }

    $storage_id = isset($_POST['storage_id']) ? absint($_POST['storage_id']) : 0;

    check_admin_referer('smbb_signconnect_test_storage_' . $storage_id);

    $redirect_url = add_query_arg(array(
        'page' => 'smbb-codetool-signstorage',
        'view' => 'details',
        'id' => $storage_id,
    ), admin_url('admin.php'));

    if ($storage_id < 1) {
        smbb_signconnect_redirect_storage_test($redirect_url, $storage_id, 'error', __('Missing storage id.', 'smbb-signconnect'));
    }

    $storage = smbb_signconnect_get_storage($storage_id);

    if (!$storage) {
        smbb_signconnect_redirect_storage_test($redirect_url, $storage_id, 'error', __('Storage not found.', 'smbb-signconnect'));
    }

    try {
        $client = \Smbb\WpCodeTool\Connector\SmbbS3Client::fromSettings($storage);
        $client->testConnection();

        smbb_signconnect_redirect_storage_test(
            $redirect_url,
            $storage_id,
            'success',
            sprintf(
                /* translators: %s: bucket name. */
                __('Connection successful. Bucket "%s" is reachable.', 'smbb-signconnect'),
                isset($storage['bucket']) ? (string) $storage['bucket'] : ''
            )
        );
    } catch (\Throwable $exception) {
        smbb_signconnect_redirect_storage_test(
            $redirect_url,
            $storage_id,
            'error',
            sprintf(
                /* translators: %s: error message. */
                __('Connection failed: %s', 'smbb-signconnect'),
                $exception->getMessage()
            )
        );
    }
}

function smbb_signconnect_test_twilio()
{
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('You are not allowed to test SignConnect Twilio settings.', 'smbb-signconnect'));
    }

    check_admin_referer('smbb_signconnect_test_twilio', '_wpnonce_twilio_test');

    $phone = isset($_POST['twilio_test_phone']) ? sanitize_text_field((string) wp_unslash($_POST['twilio_test_phone'])) : '';
    $message = isset($_POST['twilio_test_message']) ? sanitize_textarea_field((string) wp_unslash($_POST['twilio_test_message'])) : '';
    $redirect_url = add_query_arg(array(
        'page' => 'smbb-codetool-signconnect_settings',
    ), admin_url('admin.php'));

    if ($phone === '') {
        smbb_signconnect_redirect_twilio_test($redirect_url, 'error', __('Please enter a test phone number.', 'smbb-signconnect'));
    }

    if ($message === '') {
        $message = __('Test SMS SignConnect.', 'smbb-signconnect');
    }

    $settings = get_option('smbb_signconnect_settings', array());
    $settings = is_array($settings) ? $settings : array();

    /*
     * The test button lives in the same form as the settings.
     * It can test the values currently entered before saving them.
     * If the test succeeds, the admin can then click Save.
     */
    foreach (array('twilio_enabled', 'twilio_service', 'twilio_sid', 'twilio_token') as $setting_key) {
        if (isset($_POST[$setting_key])) {
            $settings[$setting_key] = sanitize_text_field((string) wp_unslash($_POST[$setting_key]));
        }
    }

    try {
        if (empty($settings['twilio_enabled'])) {
            throw new \RuntimeException(__('Twilio is disabled in settings.', 'smbb-signconnect'));
        }

        if (!class_exists('\Smbb\WpCodeTool\Connector\SmbbTwilioSmsClient')) {
            throw new \RuntimeException(__('The SMBB WP CodeTool Twilio connector is unavailable.', 'smbb-signconnect'));
        }

        $client = \Smbb\WpCodeTool\Connector\SmbbTwilioSmsClient::fromSettings($settings);
        $result = $client->send($phone, $message);

        smbb_signconnect_redirect_twilio_test(
            $redirect_url,
            'success',
            sprintf(
                /* translators: 1: Twilio message SID, 2: Twilio message status. */
                __('Test SMS sent. SID: %1$s. Status: %2$s.', 'smbb-signconnect'),
                isset($result['sid']) && $result['sid'] !== '' ? $result['sid'] : '-',
                isset($result['status']) && $result['status'] !== '' ? $result['status'] : '-'
            )
        );
    } catch (\Throwable $exception) {
        smbb_signconnect_redirect_twilio_test(
            $redirect_url,
            'error',
            sprintf(
                /* translators: %s: Twilio error message. */
                __('Twilio test failed: %s', 'smbb-signconnect'),
                $exception->getMessage()
            )
        );
    }
}

function smbb_signconnect_generate_certificate()
{
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('You are not allowed to manage SignConnect certification settings.', 'smbb-signconnect'));
    }

    check_admin_referer('smbb_signconnect_generate_certificate', '_wpnonce_certification');

    $redirect_url = add_query_arg(array(
        'page' => 'smbb-codetool-signconnect_settings',
    ), admin_url('admin.php'));

    try {
        $service = new \Smbb\SignConnect\Service\CertificationService();
        $result = $service->generateSelfSignedCertificate(array(
            'common_name' => isset($_POST['certification_common_name']) ? sanitize_text_field((string) wp_unslash($_POST['certification_common_name'])) : '',
            'organization' => isset($_POST['certification_organization']) ? sanitize_text_field((string) wp_unslash($_POST['certification_organization'])) : '',
            'email' => isset($_POST['certification_email']) ? sanitize_email((string) wp_unslash($_POST['certification_email'])) : '',
            'country' => isset($_POST['certification_country']) ? strtoupper(substr(sanitize_text_field((string) wp_unslash($_POST['certification_country'])), 0, 2)) : 'FR',
            'valid_days' => isset($_POST['certification_valid_days']) ? absint($_POST['certification_valid_days']) : 365,
        ));

        smbb_signconnect_redirect_certification($redirect_url, 'success', sprintf(
            /* translators: %s: certificate fingerprint. */
            __('Self-signed certificate generated. Fingerprint: %s', 'smbb-signconnect'),
            isset($result['fingerprint']) ? (string) $result['fingerprint'] : ''
        ));
    } catch (\Throwable $exception) {
        smbb_signconnect_redirect_certification($redirect_url, 'error', sprintf(
            /* translators: %s: error message. */
            __('Certificate generation failed: %s', 'smbb-signconnect'),
            $exception->getMessage()
        ));
    }
}

function smbb_signconnect_import_certificate()
{
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('You are not allowed to manage SignConnect certification settings.', 'smbb-signconnect'));
    }

    check_admin_referer('smbb_signconnect_import_certificate', '_wpnonce_certification_import');

    $redirect_url = add_query_arg(array(
        'page' => 'smbb-codetool-signconnect_settings',
    ), admin_url('admin.php'));

    try {
        $service = new \Smbb\SignConnect\Service\CertificationService();
        $result = $service->importExternalCertificate(
            isset($_FILES['certification_import_certificate']) && is_array($_FILES['certification_import_certificate']) ? $_FILES['certification_import_certificate'] : array(),
            isset($_FILES['certification_import_private_key']) && is_array($_FILES['certification_import_private_key']) ? $_FILES['certification_import_private_key'] : array(),
            isset($_POST['certification_import_private_key_passphrase']) ? (string) wp_unslash($_POST['certification_import_private_key_passphrase']) : ''
        );

        smbb_signconnect_redirect_certification($redirect_url, 'success', sprintf(
            /* translators: %s: certificate fingerprint. */
            __('External certificate imported. Fingerprint: %s', 'smbb-signconnect'),
            isset($result['fingerprint']) ? (string) $result['fingerprint'] : ''
        ));
    } catch (\Throwable $exception) {
        smbb_signconnect_redirect_certification($redirect_url, 'error', sprintf(
            /* translators: %s: error message. */
            __('Certificate import failed: %s', 'smbb-signconnect'),
            $exception->getMessage()
        ));
    }
}

function smbb_signconnect_get_storage($storage_id)
{
    global $wpdb;

    $table = $wpdb->prefix . 'signstorage';
    $sql = $wpdb->prepare("SELECT * FROM {$table} WHERE id = %d LIMIT 1", (int) $storage_id);
    $storage = $wpdb->get_row($sql, ARRAY_A);

    return is_array($storage) ? $storage : null;
}

function smbb_signconnect_redirect_storage_test($redirect_url, $storage_id, $type, $message)
{
    $notice_key = smbb_signconnect_storage_test_notice_key($storage_id);

    set_transient($notice_key, array(
        'type' => $type === 'success' ? 'success' : 'error',
        'message' => (string) $message,
    ), 60);

    wp_safe_redirect(add_query_arg('smbb_signconnect_storage_test', '1', $redirect_url));
    exit;
}

function smbb_signconnect_storage_test_notice($storage_id)
{
    $notice_key = smbb_signconnect_storage_test_notice_key($storage_id);
    $notice = get_transient($notice_key);

    if ($notice !== false) {
        delete_transient($notice_key);
    }

    return is_array($notice) ? $notice : null;
}

function smbb_signconnect_storage_test_notice_key($storage_id)
{
    return 'smbb_signconnect_storage_test_' . get_current_user_id() . '_' . absint($storage_id);
}

function smbb_signconnect_redirect_twilio_test($redirect_url, $type, $message)
{
    set_transient(smbb_signconnect_twilio_test_notice_key(), array(
        'type' => $type === 'success' ? 'success' : 'error',
        'message' => (string) $message,
    ), 60);

    wp_safe_redirect(add_query_arg('smbb_signconnect_twilio_test', '1', $redirect_url));
    exit;
}

function smbb_signconnect_twilio_test_notice()
{
    $notice_key = smbb_signconnect_twilio_test_notice_key();
    $notice = get_transient($notice_key);

    if ($notice !== false) {
        delete_transient($notice_key);
    }

    return is_array($notice) ? $notice : null;
}

function smbb_signconnect_twilio_test_notice_key()
{
    return 'smbb_signconnect_twilio_test_' . get_current_user_id();
}

function smbb_signconnect_redirect_certification($redirect_url, $type, $message)
{
    set_transient(smbb_signconnect_certification_notice_key(), array(
        'type' => $type === 'success' ? 'success' : 'error',
        'message' => (string) $message,
    ), 60);

    wp_safe_redirect(add_query_arg('smbb_signconnect_certification', '1', $redirect_url));
    exit;
}

function smbb_signconnect_certification_notice()
{
    $notice_key = smbb_signconnect_certification_notice_key();
    $notice = get_transient($notice_key);

    if ($notice !== false) {
        delete_transient($notice_key);
    }

    return is_array($notice) ? $notice : null;
}

function smbb_signconnect_certification_notice_key()
{
    return 'smbb_signconnect_certification_' . get_current_user_id();
}
