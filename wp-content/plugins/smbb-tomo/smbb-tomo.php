<?php
/**
 * Plugin Name: Tomo – Ateliers
 * Description: Réservation et organisation d'ateliers, stages ou événements (créneaux, intervenants, participants, liste d'attente). Bâti sur SMBB WP CodeTool.
 * Version: 0.13.1
 * Author: SMBB
 * Text Domain: smbb-tomo
 * Requires at least: 6.0
 * Requires PHP: 7.4
 */

defined('ABSPATH') || exit;

define('SMBB_TOMO_FILE', __FILE__);
define('SMBB_TOMO_PATH', plugin_dir_path(__FILE__));
define('SMBB_TOMO_URL', plugin_dir_url(__FILE__));
define('SMBB_TOMO_VERSION', '0.13.1');

spl_autoload_register('smbb_tomo_autoload');

/**
 * Autoloader : Smbb\Tomo\Service\Booking -> src/Service/Booking.php.
 *
 * @param string $class
 */
function smbb_tomo_autoload($class)
{
    $prefix = 'Smbb\\Tomo\\';

    if (strpos($class, $prefix) !== 0) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $file = SMBB_TOMO_PATH . 'src/' . str_replace('\\', DIRECTORY_SEPARATOR, $relative) . '.php';

    if (is_readable($file)) {
        require_once $file;
    }
}

register_activation_hook(__FILE__, 'smbb_tomo_activate');
add_action('plugins_loaded', 'smbb_tomo_loaded', 20);
add_action('before_woocommerce_init', array('Smbb\\Tomo\\WooCommerce\\Module', 'declareCompatibility'));

/**
 * Activation : rôles et capacités. Les tables sont créées au premier passage dans l'admin.
 */
function smbb_tomo_activate()
{
    \Smbb\Tomo\Support\Roles::install();
}

function smbb_tomo_loaded()
{
    load_plugin_textdomain('smbb-tomo', false, dirname(plugin_basename(__FILE__)) . '/languages');

    // CodeTool n'embarque pas encore de traduction : on fournit la française tant qu'il n'en charge pas une.
    $codetool_mo = SMBB_TOMO_PATH . 'languages/smbb-wpcodetool-' . determine_locale() . '.mo';

    if (!is_textdomain_loaded('smbb-wpcodetool') && is_readable($codetool_mo)) {
        load_textdomain('smbb-wpcodetool', $codetool_mo);
    }

    if (!defined('SMBB_WPCODETOOL_VERSION')) {
        add_action('admin_notices', 'smbb_tomo_missing_codetool_notice');
        return;
    }

    (new \Smbb\Tomo\Plugin())->hooks();
}

function smbb_tomo_missing_codetool_notice()
{
    if (!current_user_can('activate_plugins')) {
        return;
    }

    echo '<div class="notice notice-error"><p>'
        . esc_html__('Tomo nécessite le plugin SMBB WP CodeTool, qui doit être activé.', 'smbb-tomo')
        . '</p></div>';
}
