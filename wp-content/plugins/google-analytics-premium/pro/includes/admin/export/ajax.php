<?php
/**
 * Export Module AJAX Handlers
 *
 * Handles all AJAX requests for the Report Export Module.
 *
 * @package MonsterInsights
 * @subpackage Pro/Export
 * @since 9.0.0
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * AJAX handler for creating a new export.
 *
 * Action: monsterinsights_export_report
 *
 * @return void Sends JSON response and exits.
 */
function monsterinsights_ajax_export_report() {
	// Verify nonce
	check_ajax_referer( 'mi-admin-nonce', 'nonce' );

	// Check capability
	if ( ! current_user_can( 'monsterinsights_save_settings' ) ) {
		wp_send_json_error( array(
			'message' => __( 'You do not have permission to export reports.', 'google-analytics-premium' ),
		) );
	}

	// Sanitize and validate input
	$report_type = isset( $_POST['report_type'] ) ? sanitize_text_field( wp_unslash( $_POST['report_type'] ) ) : '';
	$start_date  = isset( $_POST['start_date'] ) ? sanitize_text_field( wp_unslash( $_POST['start_date'] ) ) : '';
	$end_date    = isset( $_POST['end_date'] ) ? sanitize_text_field( wp_unslash( $_POST['end_date'] ) ) : '';
	$format      = isset( $_POST['format'] ) ? sanitize_text_field( wp_unslash( $_POST['format'] ) ) : 'csv';

	// Optional: relay API filters (JSON string from Vue 3 overview report).
	$filters = '';
	if ( isset( $_POST['filters'] ) ) {
		$raw_filters = sanitize_text_field( wp_unslash( $_POST['filters'] ) );
		$decoded     = json_decode( $raw_filters, true );
		if ( is_array( $decoded ) ) {
			$filters = $raw_filters;
		}
	}

	// Create export
	$result = monsterinsights_export_manager()->create_export( array(
		'report_type' => $report_type,
		'start_date'  => $start_date,
		'end_date'    => $end_date,
		'format'      => $format,
		'filters'     => $filters,
	) );

	if ( $result['success'] ) {
		wp_send_json_success( $result );
	} else {
		wp_send_json_error( $result );
	}
}
add_action( 'wp_ajax_monsterinsights_export_report', 'monsterinsights_ajax_export_report' );

/**
 * AJAX handler for getting export status.
 *
 * Action: monsterinsights_get_export_status
 *
 * @since 9.0.0
 * @return void Sends JSON response and exits.
 */
function monsterinsights_ajax_get_export_status() {
	// Verify nonce
	check_ajax_referer( 'mi-admin-nonce', 'nonce' );

	// Check capability
	if ( ! current_user_can( 'monsterinsights_save_settings' ) ) {
		wp_send_json_error( array(
			'message' => __( 'You do not have permission to view export status.', 'google-analytics-premium' ),
		) );
	}

	$export_id = isset( $_POST['export_id'] ) ? absint( $_POST['export_id'] ) : 0;

	// If specific export ID provided, return just that one
	if ( $export_id > 0 ) {
		$export = monsterinsights_export_manager()->get_export( $export_id );

		if ( ! $export ) {
			wp_send_json_error( array(
				'message' => __( 'Export not found.', 'google-analytics-premium' ),
			) );
		}

		// Verify user ownership or admin capability
		if ( $export->user_id != get_current_user_id() && ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array(
				'message' => __( 'You do not have permission to view this export.', 'google-analytics-premium' ),
			) );
		}

		wp_send_json_success( array(
			'export' => monsterinsights_format_export_for_response( $export ),
		) );
	}

	// Otherwise, return all user's exports
	$exports = monsterinsights_export_manager()->get_user_exports( get_current_user_id() );

	$formatted_exports = array_map( 'monsterinsights_format_export_for_response', $exports );

	wp_send_json_success( array(
		'exports' => $formatted_exports,
	) );
}
add_action( 'wp_ajax_monsterinsights_get_export_status', 'monsterinsights_ajax_get_export_status' );

/**
 * AJAX handler for deleting an export.
 *
 * Action: monsterinsights_delete_export
 *
 * @since 9.0.0
 * @return void Sends JSON response and exits.
 */
function monsterinsights_ajax_delete_export() {
	// Verify nonce
	check_ajax_referer( 'mi-admin-nonce', 'nonce' );

	// Check capability
	if ( ! current_user_can( 'monsterinsights_save_settings' ) ) {
		wp_send_json_error( array(
			'message' => __( 'You do not have permission to delete exports.', 'google-analytics-premium' ),
		) );
	}

	$export_id = isset( $_POST['export_id'] ) ? absint( $_POST['export_id'] ) : 0;

	if ( ! $export_id ) {
		wp_send_json_error( array(
			'message' => __( 'Invalid export ID.', 'google-analytics-premium' ),
		) );
	}

	$deleted = monsterinsights_export_manager()->delete_export( $export_id );

	if ( $deleted ) {
		wp_send_json_success( array(
			'message' => __( 'Export deleted successfully.', 'google-analytics-premium' ),
		) );
	} else {
		wp_send_json_error( array(
			'message' => __( 'Failed to delete export. Please try again.', 'google-analytics-premium' ),
		) );
	}
}
add_action( 'wp_ajax_monsterinsights_delete_export', 'monsterinsights_ajax_delete_export' );

/**
 * Format export record for API response.
 *
 * @since 9.0.0
 *
 * @param object $export Export database record.
 * @return array Formatted export data.
 */
function monsterinsights_format_export_for_response( $export ) {
	// Calculate time elapsed for in-progress exports
	$elapsed = null;
	if ( 'in_progress' === $export->status && $export->started_at ) {
		$start_time = strtotime( $export->started_at );
		$elapsed    = time() - $start_time;
	}

	// Calculate time until expiration for completed exports
	$expires_in = null;
	if ( 'complete' === $export->status && $export->expires_at ) {
		$expires_time = strtotime( $export->expires_at );
		$expires_in   = $expires_time - time();

		// Convert to days
		$expires_in_days = ceil( $expires_in / DAY_IN_SECONDS );
	}

	// Generate download URL for completed exports
	$download_url = null;
	if ( 'complete' === $export->status && ! empty( $export->file_name ) ) {
		$download_url = monsterinsights_export_manager()->get_download_url( $export->id );
	}

	return array(
		'id'           => absint( $export->id ),
		'report_type'  => sanitize_text_field( $export->report_type ),
		'report_name'  => monsterinsights_get_export_report_display_name( $export->report_type ),
		'date_range'   => isset( $export->date_range_display ) ? $export->date_range_display : '',
		'format'       => sanitize_text_field( $export->export_format ),
		'status'       => sanitize_text_field( $export->status ),
		'created'      => $export->export_date,
		'started'      => $export->started_at,
		'completed'    => $export->completed_at,
		'expires'      => $export->expires_at,
		'expires_in'   => isset( $expires_in_days ) ? $expires_in_days : null,
		'elapsed'      => $elapsed,
		'error'        => $export->error_message,
		'download_url' => $download_url,
		'file_name'    => $export->file_name,
	);
}

/**
 * Get report display name from report type.
 *
 * @param string $report_type Report type identifier.
 * @return string Report display name.
 */
function monsterinsights_get_export_report_display_name( $report_type ) {
	$report_names = array(
		'overview'              => __( 'Overview', 'google-analytics-premium' ),
		'ecommerce'             => __( 'eCommerce Report', 'google-analytics-premium' ),
		'cart_abandonment'      => __( 'Cart Abandonment', 'google-analytics-premium' ),
		'ecommerce_coupons'     => __( 'Coupons Report', 'google-analytics-premium' ),
		'ecommerce_funnel'      => __( 'eCommerce Funnel', 'google-analytics-premium' ),
		'ecommerce_purchases_by_location' => __( 'Orders by Location', 'google-analytics-premium' ),
		'ecommerce_spend_by_day' => __( 'Spend by Day', 'google-analytics-premium' ),
		'ecommerce_spend_by_hour' => __( 'Spend by Hour', 'google-analytics-premium' ),
		'ecommerce_refunds'     => __( 'Refunds', 'google-analytics-premium' ),
		'ecommerce_refunds_by_geo' => __( 'Refunds by Location', 'google-analytics-premium' ),
		'publisher'             => __( 'Publishers', 'google-analytics-premium' ),
		'queries'               => __( 'Search Console', 'google-analytics-premium' ),
		'dimensions'            => __( 'Dimensions', 'google-analytics-premium' ),
		'forms'                 => __( 'Forms Report', 'google-analytics-premium' ),
		'realtime'              => __( 'Real-Time', 'google-analytics-premium' ),
		'site-speed'            => __( 'Site Speed', 'google-analytics-premium' ),
		'year-in-review'        => __( 'Year in Review', 'google-analytics-premium' ),
		'popularposts'          => __( 'Popular Posts', 'google-analytics-premium' ),
		'countries'             => __( 'Countries', 'google-analytics-premium' ),
		'media'                 => __( 'Media Report', 'google-analytics-premium' ),
		'engagement_pages'      => __( 'Publishers Pages', 'google-analytics-premium' ),
		'custom_events'         => __( 'Custom Events', 'google-analytics-premium' ),
		'traffic_overview'      => __( 'Traffic Overview', 'google-analytics-premium' ),
		'traffic_landing_pages' => __( 'Top Landing Pages', 'google-analytics-premium' ),
		'traffic_social'        => __( 'Social Media', 'google-analytics-premium' ),
		'traffic_campaign'      => __( 'Campaigns', 'google-analytics-premium' ),
		'traffic_source_medium' => __( 'Source / Medium Details', 'google-analytics-premium' ),
		'traffic_technology'    => __( 'Technology Report', 'google-analytics-premium' ),
		'traffic_ai'            => __( 'AI Traffic Report', 'google-analytics-premium' ),
	);

	return isset( $report_names[ $report_type ] ) ? $report_names[ $report_type ] : ucfirst( $report_type );
}
