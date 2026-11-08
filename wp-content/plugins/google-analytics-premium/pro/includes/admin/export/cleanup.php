<?php
/**
 * Export Cleanup and Cron Setup
 *
 * Handles WP Cron setup for queue processing and expired export cleanup.
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
 * Initialize export cron jobs.
 */
function monsterinsights_export_init_cron() {
	// Schedule queue processor if not already scheduled
	if ( ! wp_next_scheduled( 'monsterinsights_process_export_queue' ) ) {
		wp_schedule_event( time(), 'monsterinsights_export_5min', 'monsterinsights_process_export_queue' );
	}

	// Schedule cleanup if not already scheduled
	if ( ! wp_next_scheduled( 'monsterinsights_cleanup_expired_exports' ) ) {
		wp_schedule_event( time(), 'daily', 'monsterinsights_cleanup_expired_exports' );
	}
}
add_action( 'init', 'monsterinsights_export_init_cron' );

/**
 * Add custom cron interval for export processing (5 minutes).
 *
 * @param array $schedules Existing cron schedules.
 * @return array Modified schedules.
 */
function monsterinsights_export_cron_schedules( $schedules ) {
	if ( ! isset( $schedules['monsterinsights_export_5min'] ) ) {
		$schedules['monsterinsights_export_5min'] = array(
			'interval' => 5 * MINUTE_IN_SECONDS,
			'display'  => __( 'Every 5 Minutes (MonsterInsights Export)', 'google-analytics-premium' ),
		);
	}
	return $schedules;
}
add_filter( 'cron_schedules', 'monsterinsights_export_cron_schedules' );

/**
 * Process export queue via cron.
 */
function monsterinsights_export_process_queue() {
	// Get processor instance
	$processor = monsterinsights_export_processor();

	// Process next export
	$processor->process_next_export();
}
add_action( 'monsterinsights_process_export_queue', 'monsterinsights_export_process_queue' );

/**
 * Clean up expired exports.
 *
 * Deletes export records and files that have passed their expiration date.
 */
function monsterinsights_export_cleanup_expired() {
	// Get expired exports
	$expired = monsterinsights_export_queue_store()->get_expired( current_time( 'mysql' ) );

	if ( empty( $expired ) ) {
		return;
	}

	$upload_dir = wp_upload_dir();
	$export_dir = trailingslashit( $upload_dir['basedir'] ) . 'monsterinsights/exports/';

	$deleted_count = 0;
	$failed_count  = 0;

	foreach ( $expired as $export ) {
		// Delete file if it exists
		if ( ! empty( $export->file_name ) ) {
			$filename   = basename( $export->file_name );
			$subdir     = isset( $export->file_subdir ) ? (string) $export->file_subdir : '';
			$has_subdir = ( '' !== $subdir && basename( $subdir ) === $subdir );
			$file_path  = $has_subdir ? trailingslashit( $export_dir . $subdir ) . $filename : $export_dir . $filename;

			if ( file_exists( $file_path ) ) {
				if ( wp_delete_file( $file_path ) ) {
					$deleted_count++;
				} else {
					$failed_count++;
				}
			}

			// Remove the now-empty per-bundle directory.
			if ( $has_subdir && is_dir( $export_dir . $subdir ) ) {
				// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.directory_rmdir, WordPress.PHP.NoSilencedErrors.Discouraged
				@rmdir( $export_dir . $subdir );
			}
		}

		monsterinsights_export_queue_store()->delete( $export->id );
	}

	// Log cleanup results
	if ( function_exists( 'monsterinsights_debug_log' ) ) {
		monsterinsights_debug_log(
			sprintf(
				'Export cleanup completed. Deleted: %d files, Failed: %d, Total records: %d',
				$deleted_count,
				$failed_count,
				count( $expired )
			)
		);
	}

	do_action( 'monsterinsights_export_cleanup_completed', $deleted_count, $failed_count, count( $expired ) );
}
add_action( 'monsterinsights_cleanup_expired_exports', 'monsterinsights_export_cleanup_expired' );

/**
 * Clear scheduled cron events on plugin deactivation.
 *
 * @since 9.0.0
 */
function monsterinsights_export_clear_cron() {
	$timestamp = wp_next_scheduled( 'monsterinsights_process_export_queue' );
	if ( $timestamp ) {
		wp_unschedule_event( $timestamp, 'monsterinsights_process_export_queue' );
	}

	$timestamp = wp_next_scheduled( 'monsterinsights_cleanup_expired_exports' );
	if ( $timestamp ) {
		wp_unschedule_event( $timestamp, 'monsterinsights_cleanup_expired_exports' );
	}
}
register_deactivation_hook( MONSTERINSIGHTS_PLUGIN_FILE, 'monsterinsights_export_clear_cron' );

/**
 * Get export system debug information.
 *
 * Provides comprehensive diagnostic information about the export system
 * including queue status, system checks, and configuration.
 *
 * @since 9.0.1
 *
 * @return array Debug information array.
 */
function monsterinsights_export_get_debug_info() {
	$upload_dir = wp_upload_dir();
	$export_dir = trailingslashit( $upload_dir['basedir'] ) . 'monsterinsights/exports/';

	// Get queue statistics
	$queue_stats = monsterinsights_export_queue_store()->get_queue_stats();

	// Get lock status
	$lock_key = 'monsterinsights_export_processor_lock';
	$lock = get_transient( $lock_key );
	$lock_status = array(
		'is_locked' => (bool) $lock,
		'lock_time' => $lock ? gmdate( 'Y-m-d H:i:s', (int) $lock ) : null,
		'age'       => $lock ? time() - (int) $lock . ' seconds' : null,
	);

	// Get next scheduled cron runs
	$next_process = wp_next_scheduled( 'monsterinsights_process_export_queue' );
	$next_cleanup = wp_next_scheduled( 'monsterinsights_cleanup_expired_exports' );

	$cron_status = array(
		'process_queue' => $next_process ? gmdate( 'Y-m-d H:i:s', $next_process ) : 'Not scheduled',
		'cleanup'       => $next_cleanup ? gmdate( 'Y-m-d H:i:s', $next_cleanup ) : 'Not scheduled',
	);

	// System checks
	$system_checks = array(
		'php_version'                => phpversion(),
		'wordpress_version'          => get_bloginfo( 'version' ),
		'monsterinsights_available'  => function_exists( 'MonsterInsights' ),
		'reporting_api_available'    => function_exists( 'MonsterInsights' ) && isset( MonsterInsights()->reporting ),
		'zip_archive_available'      => class_exists( 'ZipArchive' ),
		'export_dir_exists'          => file_exists( $export_dir ),
		'export_dir_writable'        => file_exists( $export_dir ) && is_writable( $export_dir ),
		'export_dir_permissions'     => file_exists( $export_dir ) ? substr( sprintf( '%o', fileperms( $export_dir ) ), -4 ) : 'N/A',
		'wp_debug'                   => defined( 'WP_DEBUG' ) && WP_DEBUG,
		'wp_debug_log'               => defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG,
		'memory_limit'               => ini_get( 'memory_limit' ),
		'max_execution_time'         => ini_get( 'max_execution_time' ),
	);

	// Get recent exports
	$recent_exports = monsterinsights_export_queue_store()->get_recent( 10 );

	// Get next pending export
	$next_pending = monsterinsights_export_queue_store()->get_next_pending_ready( current_time( 'mysql' ) );

	return array(
		'timestamp'      => current_time( 'mysql' ),
		'queue_stats'    => $queue_stats,
		'lock_status'    => $lock_status,
		'cron_status'    => $cron_status,
		'system_checks'  => $system_checks,
		'next_pending'   => $next_pending,
		'recent_exports' => $recent_exports,
	);
}

/**
 * Display export debug information.
 *
 * Outputs formatted debug information to the screen or log.
 * Useful for troubleshooting export issues.
 *
 * Usage: Add this to functions.php temporarily:
 * add_action( 'init', function() {
 *     if ( current_user_can( 'manage_options' ) ) {
 *         echo '<pre>';
 *         print_r( monsterinsights_export_get_debug_info() );
 *         echo '</pre>';
 *     }
 * });
 *
 * @since 9.0.1
 */
function monsterinsights_export_display_debug_info() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$debug_info = monsterinsights_export_get_debug_info();

	echo '<div style="background: #f0f0f1; border: 1px solid #c3c4c7; padding: 20px; margin: 20px; font-family: monospace; font-size: 12px;">';
	echo '<h2 style="margin-top: 0;">MonsterInsights Export Debug Information</h2>';
	echo '<pre style="white-space: pre-wrap; word-wrap: break-word;">';
	print_r( $debug_info );
	echo '</pre>';
	echo '</div>';
}

/**
 * Clear processor lock (for debugging stuck exports).
 *
 * Use this function if exports are stuck and you need to manually release the lock.
 * Only call this if you're sure no export is currently processing.
 *
 * Usage: Run in browser console or functions.php:
 * do_action( 'init', 'monsterinsights_export_clear_lock' );
 *
 * @since 9.0.1
 */
function monsterinsights_export_clear_lock() {
	delete_transient( 'monsterinsights_export_processor_lock' );
	// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	error_log( '[MonsterInsights Export] Processor lock manually cleared at ' . current_time( 'mysql' ) );
	return true;
}
