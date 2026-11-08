<?php
/**
 * Export Module Loader
 *
 * Loads all export module components and initializes the module.
 *
 * @package MonsterInsights
 * @subpackage Pro/Export
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Load export module files.
 *
 * This function includes all necessary files for the export module.
 * Files are loaded immediately when this loader is included.
 *
 * @return void
 */
function monsterinsights_pro_export_load_files() {
	$export_dir = plugin_dir_path( __FILE__ );

	// Core classes
	require_once $export_dir . 'class-export-queue-store.php';
	require_once $export_dir . 'SimpleXLSXGen.php';
	require_once $export_dir . 'class-export-manager.php';
	require_once $export_dir . 'class-export-processor.php';
	require_once $export_dir . 'class-export-formatter.php';

	// Generic relay API export class (used by Vue 3 reports)
	require_once MONSTERINSIGHTS_PLUGIN_DIR . 'includes/api/class-monsterinsights-api-export-report.php';

	// Installation and database
	require_once $export_dir . 'install.php';

	// AJAX handlers
	require_once $export_dir . 'ajax.php';

	// WP Cron and cleanup
	require_once $export_dir . 'cleanup.php';

	// Notifications
	require_once $export_dir . 'class-export-notification.php';
}

// Load files immediately when this loader is included
monsterinsights_pro_export_load_files();

/**
 * Initialize export module.
 *
 * This function runs after all plugins are loaded and initializes
 * the export module components.
 *
 * @return void
 */
function monsterinsights_pro_export_init() {
	// Check if Pro version is active
	if ( ! monsterinsights_is_pro_version() ) {
		return;
	}

	// Initialize the Export Manager singleton
	monsterinsights_export_manager();

	// Register relay API query builders for Vue 3 report exports.
	monsterinsights_register_export_query_builders();

	// Allow other components to hook in after export module init
	do_action( 'monsterinsights_export_module_loaded' );
}
add_action( 'init', 'monsterinsights_pro_export_init', 15 );

/**
 * Handle export download requests.
 *
 * Intercepts download requests and routes them to the secure download handler.
 * Supports both old-style (page=monsterinsights_tools) and new notification-style requests.
 *
 * @return void
 */
function monsterinsights_export_handle_download_request() {
	// Check if this is a download request
	if ( ! is_admin() ) {
		return;
	}

	// Check for notification-style download request
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	if ( isset( $_GET['monsterinsights_download_export'] ) ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$export_id = absint( $_GET['monsterinsights_download_export'] );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$token = isset( $_GET['token'] ) ? sanitize_text_field( wp_unslash( $_GET['token'] ) ) : '';

		if ( $export_id && $token ) {
			// Route to download handler (this will exit)
			monsterinsights_export_manager()->handle_download( $export_id, $token );
		}

		return;
	}

	// Check for old-style download request (from tools page)
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	if ( ! isset( $_GET['page'] ) || 'monsterinsights_tools' !== $_GET['page'] ) {
		return;
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	if ( ! isset( $_GET['tab'] ) || 'export' !== $_GET['tab'] ) {
		return;
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	if ( ! isset( $_GET['action'] ) || 'download' !== $_GET['action'] ) {
		return;
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$export_id = isset( $_GET['export_id'] ) ? absint( $_GET['export_id'] ) : 0;
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$token = isset( $_GET['token'] ) ? sanitize_text_field( wp_unslash( $_GET['token'] ) ) : '';

	if ( ! $export_id || ! $token ) {
		return;
	}

	// Route to download handler (this will exit)
	monsterinsights_export_manager()->handle_download( $export_id, $token );
}
add_action( 'admin_init', 'monsterinsights_export_handle_download_request', 5 );

/**
 * Register relay API query builders for report types that use the
 * MonsterInsights_API_Export_Report class (Vue 3 reports).
 *
 * @return void
 */
function monsterinsights_register_export_query_builders() {
	if ( ! class_exists( 'MonsterInsights_API_Export_Report' ) ) {
		return;
	}

	// Overview report — mirrors the queries sent by the Vue 3 frontend.
	MonsterInsights_API_Export_Report::register_query_builder( 'overview', 'monsterinsights_build_overview_export_queries' );
}

/**
 * Build relay API queries for the overview report export.
 *
 * @param array $filters Relay API filters { operator, conditions } or empty.
 * @return array Array of query definitions.
 */
function monsterinsights_build_overview_export_queries( $filters ) {
	$apply = function ( $query ) use ( $filters ) {
		if ( ! empty( $filters['conditions'] ) ) {
			$query['filters'] = $filters;
		}
		return $query;
	};

	return array(
		// Overview metrics split into two queries (API allows max 10 metrics per query).
		$apply( array(
			'id'         => 'overview_traffic',
			'dimensions' => array( 'date' ),
			'metrics'    => array(
				'totalUsers', 'screenPageViews', 'sessions', 'bounceRate',
				'newUsers', 'engagementRate', 'averageSessionDuration',
			),
			'groupBy'    => 'date',
			'limit'      => 100,
		) ),
		$apply( array(
			'id'         => 'overview_ecommerce',
			'dimensions' => array( 'date' ),
			'metrics'    => array(
				'ecommercePurchases', 'averagePurchaseRevenue',
				'totalRevenue', 'addToCarts',
			),
			'groupBy'    => 'date',
			'limit'      => 100,
		) ),
		// Marketing campaigns (5 tabs) — matches MARKETING_CAMPAIGNS_METRICS_FULL
		$apply( array(
			'id'         => 'marketing_campaigns_campaign',
			'dimensions' => array( 'sessionCampaignName' ),
			'metrics'    => array( 'sessions', 'totalUsers', 'engagementRate', 'totalRevenue', 'ecommercePurchases', 'averagePurchaseRevenue' ),
			'limit'      => 100,
		) ),
		$apply( array(
			'id'         => 'marketing_campaigns_source',
			'dimensions' => array( 'sessionSource' ),
			'metrics'    => array( 'sessions', 'totalUsers', 'engagementRate', 'totalRevenue', 'ecommercePurchases', 'averagePurchaseRevenue' ),
			'limit'      => 100,
		) ),
		$apply( array(
			'id'         => 'marketing_campaigns_medium',
			'dimensions' => array( 'sessionMedium' ),
			'metrics'    => array( 'sessions', 'totalUsers', 'engagementRate', 'totalRevenue', 'ecommercePurchases', 'averagePurchaseRevenue' ),
			'limit'      => 100,
		) ),
		$apply( array(
			'id'         => 'marketing_campaigns_term',
			'dimensions' => array( 'sessionManualTerm' ),
			'metrics'    => array( 'sessions', 'totalUsers', 'engagementRate', 'totalRevenue', 'ecommercePurchases', 'averagePurchaseRevenue' ),
			'limit'      => 100,
		) ),
		$apply( array(
			'id'         => 'marketing_campaigns_content',
			'dimensions' => array( 'sessionManualAdContent' ),
			'metrics'    => array( 'sessions', 'totalUsers', 'engagementRate', 'totalRevenue', 'ecommercePurchases', 'averagePurchaseRevenue' ),
			'limit'      => 100,
		) ),
		// Demographics (2 tabs)
		$apply( array(
			'id'         => 'demographics_country',
			'dimensions' => array( 'country' ),
			'metrics'    => array( 'sessions', 'totalRevenue', 'engagementRate' ),
			'limit'      => 100,
		) ),
		$apply( array(
			'id'         => 'demographics_state',
			'dimensions' => array( 'region' ),
			'metrics'    => array( 'sessions', 'totalRevenue', 'engagementRate' ),
			'limit'      => 100,
		) ),
		// Devices (3 tabs)
		$apply( array(
			'id'         => 'devices_browser',
			'dimensions' => array( 'browser' ),
			'metrics'    => array( 'sessions', 'engagementRate', 'totalRevenue' ),
			'limit'      => 100,
		) ),
		$apply( array(
			'id'         => 'devices_os',
			'dimensions' => array( 'operatingSystem' ),
			'metrics'    => array( 'sessions', 'engagementRate', 'totalRevenue' ),
			'limit'      => 100,
		) ),
		$apply( array(
			'id'         => 'devices_size',
			'dimensions' => array( 'deviceCategory' ),
			'metrics'    => array( 'sessions', 'engagementRate', 'totalRevenue' ),
			'limit'      => 100,
		) ),
		// eCommerce Log (single query; sort order irrelevant for spreadsheet export)
		$apply( array(
			'id'         => 'ecommerce_log',
			'dimensions' => array( 'dateHour', 'transactionId', 'sessionSource', 'sessionMedium', 'sessionCampaignName' ),
			'metrics'    => array( 'purchaseRevenue' ),
			'orderBy'    => array( array( 'field' => 'dateHour', 'desc' => true ) ),
			'limit'      => 200,
		) ),
		// eCommerce Data (products + coupons)
		$apply( array(
			'id'         => 'ecommerce_data',
			'dimensions' => array( 'itemName', 'orderCoupon' ),
			'metrics'    => array( 'itemRevenue', 'itemsPurchased' ),
			'limit'      => 200,
		) ),
	);
}
