<?php
/**
 * Plugin Name: SignaConnect
 * Description: Signature électronique multi-organisations (interne et Universign), forfaits et facturation. Bâti sur SMBB WP CodeTool.
 * Version: 0.11.3
 * Author: SMBB
 * Text Domain: smbb-signaconnect-proxima
 * Requires at least: 6.0
 * Requires PHP: 7.4
 */

defined('ABSPATH') || exit;

define('SMBB_SCP_FILE', __FILE__);
define('SMBB_SCP_PATH', plugin_dir_path(__FILE__));
define('SMBB_SCP_URL', plugin_dir_url(__FILE__));
define('SMBB_SCP_VERSION', '0.11.3');

spl_autoload_register('smbb_scp_autoload');

/**
 * Autoloader : Smbb\SignConnectProxima\Support\Levels -> src/Support/Levels.php.
 *
 * @param string $class
 */
function smbb_scp_autoload($class)
{
    $prefix = 'Smbb\\SignConnectProxima\\';

    if (strpos($class, $prefix) !== 0) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $file = SMBB_SCP_PATH . 'src/' . str_replace('\\', DIRECTORY_SEPARATOR, $relative) . '.php';

    if (is_readable($file)) {
        require_once $file;
    }
}

add_action('plugins_loaded', 'smbb_scp_loaded', 20);
register_deactivation_hook(__FILE__, 'smbb_scp_deactivate');

function smbb_scp_deactivate()
{
    wp_clear_scheduled_hook('scp_hourly');
}

function smbb_scp_loaded()
{
    load_plugin_textdomain('smbb-signaconnect-proxima', false, dirname(plugin_basename(__FILE__)) . '/languages');

    if (!defined('SMBB_WPCODETOOL_VERSION')) {
        add_action('admin_notices', 'smbb_scp_missing_codetool_notice');
        return;
    }

    (new \Smbb\SignConnectProxima\Plugin())->hooks();
}

function smbb_scp_missing_codetool_notice()
{
    if (!current_user_can('activate_plugins')) {
        return;
    }

    echo '<div class="notice notice-error"><p>'
        . esc_html__('SignaConnect nécessite le plugin SMBB WP CodeTool, qui doit être activé.', 'smbb-signaconnect-proxima')
        . '</p></div>';
}
