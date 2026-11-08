<?php
/**
 * Handles all admin ajax interactions for the MonsterInsights plugin.
 *
 * @since 6.0.0
 *
 * @package MonsterInsights
 * @subpackage Ajax
 * @author  Chris Christoff
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once MONSTERINSIGHTS_PLUGIN_DIR . 'includes/cache/allowed-groups.php';

/**
 * Stores a user setting for the logged-in WordPress User
 *
 * @access public
 * @since 6.0.0
 */
function monsterinsights_ajax_set_user_setting() {

	// Run a security check first.
	check_ajax_referer( 'monsterinsights-set-user-setting', 'nonce' );

	// Prepare variables.
	$name  = stripslashes( !empty($_POST['name']) ? sanitize_text_field($_POST['name']) : '' );
	$value = stripslashes( !empty($_POST['value']) ? sanitize_text_field($_POST['value']) : '' );

	// Set user setting.
	set_user_setting( $name, $value );

	// Send back the response.
	wp_send_json_success();
	wp_die();

}

add_action( 'wp_ajax_monsterinsights_install_addon', 'monsterinsights_ajax_install_addon' );

/**
 * Installs a MonsterInsights addon.
 *
 * @access public
 * @since 6.0.0
 */
function monsterinsights_ajax_install_addon() {

	// Run a security check first.
	check_ajax_referer( 'monsterinsights-install', 'nonce' );

	if ( ! monsterinsights_can_install_plugins() ) {
		wp_send_json( array(
			'error' => esc_html__( 'You are not allowed to install plugins', 'google-analytics-premium' ),
		) );
	}

	// Install the addon.
	if ( isset( $_POST['plugin'] ) ) {
		$download_url = $_POST['plugin'];
		global $hook_suffix;

		// Set the current screen to avoid undefined notices.
		set_current_screen();

		// Prepare variables.
		$method = '';
		$url    = add_query_arg(
			array(
				'page' => 'monsterinsights-settings'
			),
			admin_url( 'admin.php' )
		);
		$url    = esc_url( $url );

		// Start output bufferring to catch the filesystem form if credentials are needed.
		ob_start();
		if ( false === ( $creds = request_filesystem_credentials( $url, $method, false, false, null ) ) ) {
			$form = ob_get_clean();
			echo wp_json_encode( array( 'form' => $form ) );
			wp_die();
		}

		// If we are not authenticated, make it happen now.
		if ( ! WP_Filesystem( $creds ) ) {
			ob_start();
			request_filesystem_credentials( $url, $method, true, false, null );
			$form = ob_get_clean();
			echo wp_json_encode( array( 'form' => $form ) );
			wp_die();
		}

		// We do not need any extra credentials if we have gotten this far, so let's install the plugin.
		monsterinsights_require_upgrader( false );

		// Create the plugin upgrader with our custom skin.
		$installer = new Plugin_Upgrader( $skin = new MonsterInsights_Skin() );
		$installer->install( $download_url );

		// Flush the cache and return the newly installed plugin basename.
		wp_cache_flush();
		if ( $installer->plugin_info() ) {
			$plugin_basename = $installer->plugin_info();
			echo wp_json_encode( array( 'plugin' => $plugin_basename ) );
			wp_die();
		}
	}

	// Send back a response.
	echo wp_json_encode( true );
	wp_die();

}
add_action( 'wp_ajax_monsterinsights_activate_addon', 'monsterinsights_ajax_activate_addon' );

/**
 * Activates a MonsterInsights addon.
 *
 * @access public
 * @since 6.0.0
 */
function monsterinsights_ajax_activate_addon() {

	// Run a security check first.
	check_ajax_referer( 'monsterinsights-activate', 'nonce' );

	if ( ! current_user_can( 'activate_plugins' ) ) {
		wp_send_json( array(
			'error' => esc_html__( 'You are not allowed to activate plugins', 'google-analytics-premium' ),
		) );
	}

	// Activate the addon.
	if ( isset( $_POST['plugin'] ) ) {
		$plugin = esc_attr( $_POST['plugin'] );

		// $_POST['isnetwork'] arrives as a string, so a literal "false" (sent by the
		// frontend when not in network admin) is still truthy in PHP. Left unchecked
		// that forces network-wide activation, which on a single site writes the plugin
		// to active_sitewide_plugins and never loads it — activate_plugin() returns no
		// error, so the caller sees success while the plugin stays inactive. Coerce to
		// a real boolean so single-site activation takes the normal path.
		$is_network = isset( $_POST['isnetwork'] ) && filter_var( wp_unslash( $_POST['isnetwork'] ), FILTER_VALIDATE_BOOLEAN );

		if ( $is_network ) {
			$activate = activate_plugin( $plugin, null, true );
		} else {
			$activate = activate_plugin( $plugin );
		}

		/* Restrict thirt-party redirections on activation */
		if ( "userfeedback-lite/userfeedback.php" === $plugin ) {
			delete_transient( '_userfeedback_activation_redirect' );
		}

		if ( is_wp_error( $activate ) ) {
			echo wp_json_encode( array( 'error' => $activate->get_error_message() ) );
			wp_die();
		}

		// This activation is deliberately not silent: skipping a plugin's activation
		// hook can leave it without its tables, options or cron events. WPForms' own
		// first-run redirect is disarmed on the next admin page load instead.
		monsterinsights_suppress_wpforms_first_run( $plugin );

		do_action( 'monsterinsights_after_ajax_activate_addon', sanitize_text_field( $_POST['plugin'] ) );

		// Flush report caches so the newly activated addon's data is fetched fresh.
		monsterinsights_cache_flush_group( 'reports' );
		monsterinsights_cache_flush_group( 'overview' );
		monsterinsights_flag_flush_cache_registry();

		// FunnelKit Stripe Woo Payment Gateway activation.
		if ( 'funnelkit-stripe-woo-payment-gateway/funnelkit-stripe-woo-payment-gateway.php' === $plugin ) {
			monsterinsights_activate_plugin_funnelkit_stripe_woo_gateway();
		}
	}

	echo wp_json_encode( true );
	wp_die();
}

add_action( 'wp_ajax_monsterinsights_deactivate_addon', 'monsterinsights_ajax_deactivate_addon' );
/**
 * Deactivates a MonsterInsights addon.
 *
 * @access public
 * @since 6.0.0
 */
function monsterinsights_ajax_deactivate_addon() {

	// Run a security check first.
	check_ajax_referer( 'monsterinsights-deactivate', 'nonce' );

	if ( ! current_user_can( 'deactivate_plugins' ) ) {
		wp_send_json( array(
			'error' => esc_html__( 'You are not allowed to deactivate plugins', 'google-analytics-premium' ),
		) );
	}

	// Deactivate the addon.
	if ( isset( $_POST['plugin'] ) ) {
		if ( isset( $_POST['isnetwork'] ) && $_POST['isnetwork'] ) {
			$deactivate = deactivate_plugins( $_POST['plugin'], false, true );
		} else {
			$deactivate = deactivate_plugins( $_POST['plugin'] );
		}
	}

	do_action( 'monsterinsights_after_ajax_deactivate_addon', sanitize_text_field( $_POST['plugin'] ) );

	echo wp_json_encode( true );
	wp_die();
}

/**
 * Called whenever a notice is dismissed in MonsterInsights or its Addons.
 *
 * Updates a key's value in the options table to mark the notice as dismissed,
 * preventing it from displaying again
 *
 * @access public
 * @since 6.0.0
 */
function monsterinsights_ajax_dismiss_notice() {

	// Run a security check first.
	check_ajax_referer( 'monsterinsights-dismiss-notice', 'nonce' );

	if ( ! current_user_can( 'monsterinsights_save_settings' ) ) {
		echo wp_json_encode( false );
		wp_die();
	}

	// Deactivate the notice
	if ( isset( $_POST['notice'] ) ) {
		// Init the notice class and mark notice as deactivated
		MonsterInsights()->notices->dismiss( sanitize_key( wp_unslash( $_POST['notice'] ) ) );

		// Return true
		echo wp_json_encode( true );
		wp_die();
	}

	// If here, an error occurred
	echo wp_json_encode( false );
	wp_die();

}

add_action( 'wp_ajax_monsterinsights_ajax_dismiss_notice', 'monsterinsights_ajax_dismiss_notice' );

/**
 * Dismiss SEOBoost CTA
 *
 * @access public
 * @since 7.12.3
 */
function monsterinsights_ajax_dismiss_seoboost_cta() {
	check_ajax_referer( 'mi-admin-nonce', 'nonce' );

	if ( ! current_user_can( 'monsterinsights_save_settings' ) ) {
		return;
	}

	// Deactivate the notice
	if ( update_option( 'monsterinsights_dismiss_seoboost_cta', 'yes' ) ) {
		// Return true
		wp_send_json( array(
			'dismissed' => 'yes',
		) );
		wp_die();
	}

	// If here, an error occurred
	wp_send_json( array(
		'dismissed' => 'no',
	) );
	wp_die();
}

add_action( 'wp_ajax_monsterinsights_vue_dismiss_seoboost_cta', 'monsterinsights_ajax_dismiss_seoboost_cta' );


/**
 * Dismiss AISEO plugin call-to-action
 *
 * @access public
 * @since 8.22.1
 */
function monsterinsights_vue_dismiss_aiseo_cta() {
	check_ajax_referer( 'mi-admin-nonce', 'nonce' );

	if ( ! current_user_can( 'monsterinsights_save_settings' ) ) {
		return;
	}

	// Deactivate the notice
	if ( update_option( 'monsterinsights_dismiss_aiseo_cta', 'yes' ) ) {
		// Return true
		wp_send_json( array(
			'dismissed' => 'yes',
		) );
		wp_die();
	}

	// If here, an error occurred
	wp_send_json( array(
		'dismissed' => 'no',
	) );
	wp_die();
}

add_action( 'wp_ajax_monsterinsights_vue_dismiss_aiseo_cta', 'monsterinsights_vue_dismiss_aiseo_cta' );

/**
 * Get the sem rush cta dismiss status value
 */
function monsterinsights_get_seo_boost_cta_status() {
	check_ajax_referer( 'mi-admin-nonce', 'nonce' );

	if ( ! current_user_can( 'monsterinsights_view_dashboard' ) ) {
		wp_send_json_error();
	}

	$dismissed_cta = get_option( 'monsterinsights_dismiss_seoboost_cta', 'no' );

	wp_send_json( array(
		'dismissed' => $dismissed_cta,
	) );
}

add_action( 'wp_ajax_monsterinsights_get_seo_boost_cta_status', 'monsterinsights_get_seo_boost_cta_status' );

/**
 * Checks if AISEO call-to-action is dismissed.
 *
 * @since 8.22.1
 * @return void
 */
function monsterinsights_get_aiseo_cta_status() {
	check_ajax_referer( 'mi-admin-nonce', 'nonce' );

	if ( ! current_user_can( 'monsterinsights_view_dashboard' ) ) {
		wp_send_json_error();
	}

	$dismissed_cta = get_option( 'monsterinsights_dismiss_aiseo_cta', 'no' );

	wp_send_json( array(
		'dismissed' => $dismissed_cta,
	) );
}

add_action( 'wp_ajax_monsterinsights_get_aiseo_cta_status', 'monsterinsights_get_aiseo_cta_status' );

function monsterinsights_handle_get_plugin_info() {

	$auth = MonsterInsights()->auth;

	//  Authenticate with public key
	$key = !empty($_REQUEST['key']) ? sanitize_text_field($_REQUEST['key']) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

	$site_key = is_network_admin() ? $auth->get_network_key() : $auth->get_key();

	if ( !hash_equals( $site_key, $key ) ) {
		wp_send_json_error([
			'error'     => __( 'Invalid site key.', 'google-analytics-premium' )
		], 401);
	}

	$v4 = is_network_admin() ? $auth->get_network_v4_id() :  $auth->get_v4_id();
	$has_secret = is_network_admin() ?
		!empty( $auth->get_network_measurement_protocol_secret() ) :
		!empty( $auth->get_measurement_protocol_secret() );

	wp_send_json([
		'v4'                => $v4,
		'has_mp_secret'     => $has_secret,
		'plugin_version'    => MonsterInsights()->version
	]);
}

add_action( 'wp_ajax_nopriv_monsterinsights_get_plugin_info', 'monsterinsights_handle_get_plugin_info' );

/**
 * User journey report show demo report for not licensed.
 *
 * @return void
 * @since 8.16
 */
function monsterinsights_user_journey_demo_report_ajax() {
	$report = array();

	$sources   = array( 'google', 'newsletter', 'billboard' );
	$mediums   = array( 'cpc', 'banner', 'email' );
	$campaigns = array( 'campaign-name', 'slogan', 'promo-code' );

	for ( $i = 1; $i <= 13; $i ++ ) {
		$rand_key = array_rand( $sources );

		$report[] = array(
			'transaction_id' => wp_rand( 12, 30 ),
			'steps'          => wp_rand( 3, 8 ),
			'order_total'    => wp_rand( 10, 50 ),
			'utm_source'     => $sources[ $rand_key ],
			'utm_medium'     => $mediums[ $rand_key ],
			'utm_campaign'   => $campaigns[ $rand_key ],
			'purchase_date'  => '--',
		);
	}

	wp_send_json( array( 'items' => $report, 'demo'  => true ) );
}

$license_type = MonsterInsights()->license->get_license_type();

// If it is not a pro licensed.
if ( ! ( $license_type === 'master' || $license_type === 'pro' ) ) {
	add_action( 'wp_ajax_monsterinsights_user_journey_report', 'monsterinsights_user_journey_demo_report_ajax' );
	add_action( 'wp_ajax_monsterinsights_user_journey_report_filter_params', '__return_false' );
}

/**
 * Plugin FunnelKit Stripe Woo Payment Gateway activation.
 *
 * @return void
 */
function monsterinsights_activate_plugin_funnelkit_stripe_woo_gateway() {
	// Add FunnelKit partner ID. For MonsterInsights is 3f6c515da4bdcb59afc860b305a0cc3e .
	update_option( 'fkwcs_wp_stripe', '3f6c515da4bdcb59afc860b305a0cc3e', false );
}

/**
 * Plugin FunnelKit Stripe Woo Payment Gateway, check if Stripe is connected.
 *
 * @access public
 * @since 6.0.0
 */
function monsterinsights_check_plugin_funnelkit_funnelkit_stripe_woo_gateway_configured() {
	// Run a security check first.
	check_ajax_referer( 'monsterinsights-funnelkit-stripe-woo-nonce', 'nonce' );

	$fkwcs_con_status = get_option('fkwcs_con_status');

	if ( 'success' === $fkwcs_con_status ) {
		echo wp_json_encode( true );
		wp_die();
	}

	echo wp_json_encode( false );
	wp_die();

}
add_action( 'wp_ajax_monsterinsights_funnelkit_stripe_woo_gateway_configured', 'monsterinsights_check_plugin_funnelkit_funnelkit_stripe_woo_gateway_configured' );

/**
 * AJAX handler to dismiss WPConsent notice.
 */
function monsterinsights_ajax_dismiss_wpconsent_notice() {
	// Check nonce for security
	check_ajax_referer( 'monsterinsights-dismiss-notice', 'nonce' );

	// Check if user has proper capabilities
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => 'Insufficient permissions' ) );
		return;
	}

	// Save the dismissal
	update_option( 'monsterinsights_wpconsent_notice_dismissed', true );
	wp_send_json_success( array( 'dismissed' => true ) );
}
add_action( 'wp_ajax_monsterinsights_dismiss_wpconsent_notice', 'monsterinsights_ajax_dismiss_wpconsent_notice' );

/**
 * Generic cache backfill via AJAX.
 *
 * Stores data in the MonsterInsights cache system (object cache with DB fallback).
 * Used by the useCachedFetch composable's backfill path after a direct Relay fetch.
 *
 * @since 9.11.0
 *
 * @global string $_POST['cache_group'] Cache group name (e.g., 'overview_report').
 * @global string $_POST['cache_key']   Cache key identifier.
 * @global string $_POST['data']        JSON-encoded data to cache.
 * @global int    $_POST['ttl']         Optional. Cache TTL in seconds (default 3600).
 * @global string $_POST['nonce']       Security nonce.
 */
function monsterinsights_ajax_backfill_cache() {
	check_ajax_referer( 'mi-admin-nonce', 'nonce' );

	if ( ! current_user_can( 'monsterinsights_view_dashboard' ) ) {
		wp_send_json_error( array( 'message' => __( 'You do not have permission to perform this action.', 'google-analytics-premium' ) ) );
	}

	$allowed_groups = monsterinsights_backfill_cache_allowed_groups();

	$cache_group = ! empty( $_POST['cache_group'] ) ? sanitize_text_field( wp_unslash( $_POST['cache_group'] ) ) : '';
	$cache_key   = ! empty( $_POST['cache_key'] ) ? sanitize_text_field( wp_unslash( $_POST['cache_key'] ) ) : '';

	if ( empty( $cache_group ) || empty( $cache_key ) ) {
		wp_send_json_error( array( 'message' => __( 'Missing required cache parameters.', 'google-analytics-premium' ) ) );
	}

	if ( ! in_array( $cache_group, $allowed_groups, true ) ) {
		wp_send_json_error( array( 'message' => __( 'Invalid cache group.', 'google-analytics-premium' ) ) );
	}

	// Vue 3 reports fetch Relay data directly and only reach WP to backfill or
	// read the cache, so this -- not the Vue 2-era routes.php handlers -- is
	// where those views actually need to be counted.
	$report_view_slug = monsterinsights_report_view_slug_for_cache_group( $cache_group );
	if ( $report_view_slug ) {
		MonsterInsights_Report_Views::maybe_record( $report_view_slug );
	}

	$raw_data = ! empty( $_POST['data'] ) ? wp_unslash( $_POST['data'] ) : '';
	if ( strlen( $raw_data ) > 500000 ) {
		wp_send_json_error( array( 'message' => __( 'Data payload too large.', 'google-analytics-premium' ) ) );
	}

	$data = ! empty( $raw_data ) ? json_decode( $raw_data, true ) : null;
	$ttl  = ! empty( $_POST['ttl'] ) ? absint( $_POST['ttl'] ) : HOUR_IN_SECONDS;

	if ( $data === null && $raw_data !== '' ) {
		wp_send_json_error( array( 'message' => __( 'Invalid JSON in data parameter.', 'google-analytics-premium' ) ) );
	}

	if ( $data === null ) {
		wp_send_json_error( array( 'message' => __( 'Missing required cache parameters.', 'google-analytics-premium' ) ) );
	}

	$stored = monsterinsights_cache_set( $cache_key, $data, $cache_group, $ttl );

	// Report an actual storage failure instead of masking it as success. A
	// silent failure here makes the client register the cache key even though
	// nothing was stored, so every later read misses and reports re-fetch
	// forever with no visible error.
	if ( ! $stored ) {
		wp_send_json_error( array( 'message' => __( 'Unable to store cache data.', 'google-analytics-premium' ) ) );
	}

	wp_send_json_success();
}
add_action( 'wp_ajax_monsterinsights_backfill_cache', 'monsterinsights_ajax_backfill_cache' );

/**
 * Get cached data that was stored via backfill (monsterinsights_backfill_cache).
 * Used by the useCachedFetch composable when the localStorage registry indicates
 * the data is cached in WP.
 *
 * @since 9.11.0
 *
 * @global string $_POST['cache_group'] Cache group name (e.g., 'overview').
 * @global string $_POST['cache_key']   Cache key identifier.
 * @global string $_POST['nonce']       Security nonce.
 */
function monsterinsights_ajax_get_backfill_cache() {
	check_ajax_referer( 'mi-admin-nonce', 'nonce' );

	if ( ! current_user_can( 'monsterinsights_view_dashboard' ) ) {
		wp_send_json_error( array( 'message' => __( 'You do not have permission to perform this action.', 'google-analytics-premium' ) ) );
	}

	$allowed_groups = monsterinsights_backfill_cache_allowed_groups();

	$cache_group = ! empty( $_POST['cache_group'] ) ? sanitize_text_field( wp_unslash( $_POST['cache_group'] ) ) : '';
	$cache_key   = ! empty( $_POST['cache_key'] ) ? sanitize_text_field( wp_unslash( $_POST['cache_key'] ) ) : '';

	if ( empty( $cache_group ) || empty( $cache_key ) ) {
		wp_send_json_error( array( 'message' => __( 'Missing required cache parameters.', 'google-analytics-premium' ) ) );
	}

	if ( ! in_array( $cache_group, $allowed_groups, true ) ) {
		wp_send_json_error( array( 'message' => __( 'Invalid cache group.', 'google-analytics-premium' ) ) );
	}

	// Recorded above the cache lookup below so a cache hit still counts as a
	// view, matching the rule the routes.php recorders already follow.
	$report_view_slug = monsterinsights_report_view_slug_for_cache_group( $cache_group );
	if ( $report_view_slug ) {
		MonsterInsights_Report_Views::maybe_record( $report_view_slug );
	}

	// Extract additional parameters for sample data filtering
	$extra_params = array();
	if ( ! empty( $_POST['selected_metrics'] ) ) {
		$metrics_raw = wp_unslash( $_POST['selected_metrics'] );
		$extra_params['selected_metrics'] = is_string( $metrics_raw ) ? json_decode( $metrics_raw, true ) : $metrics_raw;
	}
	if ( ! empty( $_POST['active_tab'] ) ) {
		$extra_params['active_tab'] = sanitize_text_field( wp_unslash( $_POST['active_tab'] ) );
	}
	if ( isset( $_POST['compare'] ) ) {
		$extra_params['compare'] = filter_var( wp_unslash( $_POST['compare'] ), FILTER_VALIDATE_BOOLEAN );
	}
	if ( ! empty( $_POST['api_filters'] ) ) {
		$api_filters_raw = wp_unslash( $_POST['api_filters'] );
		if ( is_string( $api_filters_raw ) ) {
			$decoded = json_decode( $api_filters_raw, true );
			if ( is_array( $decoded ) ) {
				$extra_params['api_filters'] = $decoded;
			}
		} elseif ( is_array( $api_filters_raw ) ) {
			$extra_params['api_filters'] = $api_filters_raw;
		}
	}

	/**
	 * Filter to intercept backfill cache requests with sample data.
	 *
	 * When sample data mode is enabled via _monsterinsights-utils plugin,
	 * this filter returns sample data instead of fetching from cache/API.
	 *
	 * @since 9.11.0
	 *
	 * @param mixed  $data         The cached data (null to continue normal flow).
	 * @param string $cache_key    The cache key identifier.
	 * @param string $cache_group  The cache group (e.g., 'overview').
	 * @param array  $extra_params Additional parameters (selected_metrics, active_tab, compare, api_filters).
	 */
	$sample_data = apply_filters( 'monsterinsights_get_backfill_cache', null, $cache_key, $cache_group, $extra_params );

	if ( null !== $sample_data ) {
		wp_send_json_success( $sample_data );
	}

	$data = monsterinsights_cache_get( $cache_key, $cache_group );

	if ( false === $data ) {
		wp_send_json_error( array( 'message' => __( 'Cache miss.', 'google-analytics-premium' ) ) );
	}

	wp_send_json_success( $data );
}
add_action( 'wp_ajax_monsterinsights_get_backfill_cache', 'monsterinsights_ajax_get_backfill_cache' );
