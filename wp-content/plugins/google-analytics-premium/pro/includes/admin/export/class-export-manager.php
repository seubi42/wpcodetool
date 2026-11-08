<?php
/**
 * Export Manager Class
 *
 * Central coordinator for all export operations. Handles export creation,
 * validation, queue management, and status checking.
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
 * MonsterInsights_Export_Manager Class
 *
 * Main orchestration class for the Report Export Module.
 *
 * @since 9.0.0
 */
final class MonsterInsights_Export_Manager {

	/**
	 * Singleton instance.
	 *
	 * @since 9.0.0
	 * @var MonsterInsights_Export_Manager
	 */
	private static $instance;

	/**
	 * Export queue table name (without prefix).
	 *
	 * @since 9.0.0
	 * @var string
	 */
	private $table_name = 'monsterinsights_export_queue';

	/**
	 * Get singleton instance.
	 *
	 * @since 9.0.0
	 * @return MonsterInsights_Export_Manager
	 */
	public static function get_instance() {
		if ( ! isset( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 *
	 * @since 9.0.0
	 */
	private function __construct() {
		// Private constructor to enforce singleton
	}

	/**
	 * Create a new export job.
	 *
	 * Validates input, checks permissions, creates database record,
	 * and returns the export ID.
	 *
	 * @since 9.0.0
	 *
	 * @param array $args {
	 *     Export configuration arguments.
	 *
	 *     @type string $report_type Report type identifier (e.g., 'overview', 'ecommerce').
	 *     @type string $start_date  Start date in Y-m-d format.
	 *     @type string $end_date    End date in Y-m-d format.
	 *     @type string $format      Export format ('csv' or 'excel').
	 *     @type string $triggered_from Source of the export request ('report_screen' or 'tools_page').
	 * }
	 * @return array {
	 *     Result array.
	 *
	 *     @type bool   $success    Whether the export was created successfully.
	 *     @type int    $export_id  The created export ID (on success).
	 *     @type string $message    Success or error message.
	 *     @type string $status     Export status (on success).
	 * }
	 */
	public function create_export( $args ) {
		// Validate required arguments
		$validation = $this->validate_export_args( $args );
		if ( ! $validation['valid'] ) {
			return array(
				'success' => false,
				'message' => $validation['message'],
			);
		}

		// Check if user has permission
		if ( ! current_user_can( 'monsterinsights_save_settings' ) ) {
			return array(
				'success' => false,
				'message' => __( 'You do not have permission to export reports.', 'google-analytics-premium' ),
			);
		}

		// Check rate limiting
		$rate_check = $this->check_rate_limit( get_current_user_id() );
		if ( ! $rate_check['allowed'] ) {
			return array(
				'success' => false,
				'message' => $rate_check['message'],
			);
		}

		$date_range_display = $this->format_date_range_display( $args['start_date'], $args['end_date'] );

		// Calculate expiration date (7 days from now by default)
		$expiration_days = apply_filters( 'monsterinsights_export_expiration_days', 7 );
		$expires_at      = gmdate( 'Y-m-d H:i:s', strtotime( "+{$expiration_days} days" ) );

		$export_id = monsterinsights_export_queue_store()->insert(
			array(
				'user_id'            => get_current_user_id(),
				'report_type'        => $args['report_type'],
				'export_date'        => current_time( 'mysql' ),
				'start_date'         => $args['start_date'],
				'end_date'           => $args['end_date'],
				'date_range_display' => $date_range_display,
				'export_format'      => $args['format'],
				'status'             => 'pending',
				'expires_at'         => $expires_at,
				'filters'            => isset( $args['filters'] ) ? $args['filters'] : '',
			)
		);

		if ( ! $export_id ) {
			return array(
				'success' => false,
				'message' => __( 'Failed to create export. Please try again.', 'google-analytics-premium' ),
			);
		}

		// Trigger action for other components to hook into
		do_action( 'monsterinsights_export_created', $export_id, $args );

		return array(
			'success'   => true,
			'export_id' => $export_id,
			'message'   => __( 'Export started. You will receive a notification when it is ready.', 'google-analytics-premium' ),
			'status'    => 'pending',
		);
	}

	/**
	 * Get a single export by ID.
	 *
	 * @since 9.0.0
	 *
	 * @param int $export_id Export ID.
	 * @return object|null Export record or null if not found.
	 */
	public function get_export( $export_id ) {
		return monsterinsights_export_queue_store()->get( $export_id );
	}

	/**
	 * Get all exports for a user.
	 *
	 * @since 9.0.0
	 *
	 * @param int $user_id User ID. Defaults to current user.
	 * @param int $limit   Number of exports to retrieve. Default 50.
	 * @return array Array of export objects.
	 */
	public function get_user_exports( $user_id = 0, $limit = 50 ) {
		if ( ! $user_id ) {
			$user_id = get_current_user_id();
		}
		return monsterinsights_export_queue_store()->get_user_exports( $user_id, $limit );
	}

	/**
	 * Update export status.
	 *
	 * @since 9.0.0
	 *
	 * @param int    $export_id Export ID.
	 * @param string $status    New status ('pending', 'in_progress', 'complete', 'failed').
	 * @param array  $data      Additional data to update (optional).
	 * @return bool True on success, false on failure.
	 */
	public function update_export_status( $export_id, $status, $data = array() ) {
		// Debug logging
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( sprintf(
				'[MonsterInsights Export] update_export_status called: ID=%d, Status=%s, Data=%s',
				$export_id,
				$status,
				wp_json_encode( $data )
			) );
		}

		// Prepare update data
		$update_data = array( 'status' => $status );

		// Add timestamp based on status
		if ( 'in_progress' === $status ) {
			$update_data['started_at'] = current_time( 'mysql' );
		} elseif ( 'complete' === $status || 'failed' === $status ) {
			$update_data['completed_at'] = current_time( 'mysql' );
		}

		// Generate download token when export completes
		if ( 'complete' === $status ) {
			$export = $this->get_export( $export_id );
			if ( $export ) {
				// Only generate token if not already set
				if ( empty( $export->download_token ) ) {
					$update_data['download_token'] = $this->generate_download_token( $export_id, $export->user_id, $export->expires_at );
				}
			}
		}

		// Merge additional data
		if ( ! empty( $data ) ) {
			foreach ( $data as $key => $value ) {
				if ( 'error' === $key ) {
					$key = 'error_message';
				}
				$update_data[ $key ] = $value;
			}
		}

		$updated = monsterinsights_export_queue_store()->update( $export_id, $update_data );
		if ( $updated ) {
			// Clear cached status
			$cache_key = 'mi_export_status_' . $export_id;
			delete_transient( $cache_key );

			// Trigger action for status change
			do_action( 'monsterinsights_export_status_changed', $export_id, $status, $data );

			return true;
		}

		return false;
	}

	/**
	 * Delete an export record and its associated file.
	 *
	 * @since 9.0.0
	 *
	 * @param int $export_id Export ID.
	 * @return bool True on success, false on failure.
	 */
	public function delete_export( $export_id ) {
		// Get export details first
		$export = $this->get_export( $export_id );
		if ( ! $export ) {
			return false;
		}

		// Check permission - user must own the export or be an admin
		if ( $export->user_id != get_current_user_id() && ! current_user_can( 'manage_options' ) ) {
			return false;
		}

		// Delete file if it exists
		if ( ! empty( $export->file_name ) ) {
			$export_base = WP_CONTENT_DIR . '/uploads/monsterinsights/exports/';
			$filename    = basename( $export->file_name );
			$subdir      = isset( $export->file_subdir ) ? (string) $export->file_subdir : '';
			$has_subdir  = ( '' !== $subdir && basename( $subdir ) === $subdir );
			$file_path   = $has_subdir ? trailingslashit( $export_base . $subdir ) . $filename : $export_base . $filename;

			if ( file_exists( $file_path ) ) {
				wp_delete_file( $file_path );
			}

			// Remove the now-empty per-bundle directory.
			if ( $has_subdir && is_dir( $export_base . $subdir ) ) {
				// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.directory_rmdir, WordPress.PHP.NoSilencedErrors.Discouraged
				@rmdir( $export_base . $subdir );
			}
		}

		$deleted = monsterinsights_export_queue_store()->delete( $export_id );
		if ( $deleted ) {
			// Clear cached status
			$cache_key = 'mi_export_status_' . $export_id;
			delete_transient( $cache_key );

			// Trigger action
			do_action( 'monsterinsights_export_deleted', $export_id );

			return true;
		}

		return false;
	}

	/**
	 * Validate export arguments.
	 *
	 * @since 9.0.0
	 *
	 * @param array $args Export arguments.
	 * @return array Validation result with 'valid' boolean and 'message' string.
	 */
	private function validate_export_args( $args ) {
		// Check required fields
		$required_fields = array( 'report_type', 'start_date', 'end_date', 'format' );
		foreach ( $required_fields as $field ) {
			if ( empty( $args[ $field ] ) ) {
				return array(
					'valid'   => false,
					'message' => sprintf(
						// Translators: %s is the field name
						__( 'Missing required field: %s', 'google-analytics-premium' ),
						$field
					),
				);
			}
		}

		// Validate format
		if ( ! in_array( $args['format'], array( 'csv', 'excel' ) ) ) {
			return array(
				'valid'   => false,
				'message' => __( 'Invalid export format. Must be "csv" or "excel".', 'google-analytics-premium' ),
			);
		}

		// Validate report type.
		if ( ! in_array( $args['report_type'], $this->get_allowed_report_types(), true ) ) {
			return array(
				'valid'   => false,
				'message' => __( 'Export is currently available for selected reports. More reports will be supported soon.', 'google-analytics-premium' ),
			);
		}

		// Validate dates
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $args['start_date'] ) ) {
			return array(
				'valid'   => false,
				'message' => __( 'Invalid start date format. Must be YYYY-MM-DD.', 'google-analytics-premium' ),
			);
		}

		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $args['end_date'] ) ) {
			return array(
				'valid'   => false,
				'message' => __( 'Invalid end date format. Must be YYYY-MM-DD.', 'google-analytics-premium' ),
			);
		}

		// Check date range validity
		$start = strtotime( $args['start_date'] );
		$end   = strtotime( $args['end_date'] );

		if ( $start > $end ) {
			return array(
				'valid'   => false,
				'message' => __( 'Start date must not be after end date.', 'google-analytics-premium' ),
			);
		}

		// Check maximum date range (1 year)
		$max_days = apply_filters( 'monsterinsights_export_max_days', 365 );
		$diff     = ( $end - $start ) / DAY_IN_SECONDS;

		if ( $diff > $max_days ) {
			return array(
				'valid'   => false,
				'message' => sprintf(
					// Translators: %d is the maximum number of days
					__( 'Date range cannot exceed %d days.', 'google-analytics-premium' ),
					$max_days
				),
			);
		}

		// Check future dates (buffer by 1 day to account for user timezone offsets from UTC).
		if ( $end > time() + DAY_IN_SECONDS ) {
			return array(
				'valid'   => false,
				'message' => __( 'End date cannot be in the future.', 'google-analytics-premium' ),
			);
		}

		return array(
			'valid'   => true,
			'message' => '',
		);
	}

	/**
	 * Check if user has exceeded rate limit for exports.
	 *
	 * @since 9.0.0
	 *
	 * @param int $user_id User ID.
	 * @return array Result with 'allowed' boolean and 'message' string.
	 */
	private function check_rate_limit( $user_id ) {
		$count = monsterinsights_export_queue_store()->count_user_exports_last_24h( $user_id );

		$limit = apply_filters( 'monsterinsights_export_rate_limit', 50 );

		if ( $count >= $limit ) {
			return array(
				'allowed' => false,
				'message' => sprintf(
					// Translators: %d is the rate limit number
					__( 'You have reached the maximum of %d exports per day. Please try again tomorrow.', 'google-analytics-premium' ),
					$limit
				),
			);
		}

		// Check for pending/in-progress exports (prevent queue overflow)
		$active_count = monsterinsights_export_queue_store()->count_user_active( $user_id );

		$max_active = apply_filters( 'monsterinsights_export_max_active', 10 );

		if ( $active_count >= $max_active ) {
			return array(
				'allowed' => false,
				'message' => sprintf(
					// Translators: %d is the maximum active exports number
					__( 'You have %d exports in progress. Please wait for some to complete before starting new ones.', 'google-analytics-premium' ),
					$max_active
				),
			);
		}

		return array(
			'allowed' => true,
			'message' => '',
		);
	}

	/**
	 * Format date range for display.
	 *
	 * @since 9.0.0
	 *
	 * @param string $start_date Start date (Y-m-d).
	 * @param string $end_date   End date (Y-m-d).
	 * @return string Formatted date range (e.g., "Oct 1 - Oct 31, 2025").
	 */
	private function format_date_range_display( $start_date, $end_date ) {
		$start = strtotime( $start_date );
		$end   = strtotime( $end_date );

		// Same month
		if ( gmdate( 'Y-m', $start ) === gmdate( 'Y-m', $end ) ) {
			return sprintf(
				'%s - %s',
				gmdate( 'M j', $start ),
				gmdate( 'j, Y', $end )
			);
		}

		// Different months
		return sprintf(
			'%s - %s',
			gmdate( 'M j', $start ),
			gmdate( 'M j, Y', $end )
		);
	}

	/**
	 * Get table name with prefix.
	 *
	 * @since 9.0.0
	 *
	 * @return string Full table name.
	 */
	public function get_table_name() {
		return '';
	}

	/**
	 * Generate a secure download token for an export.
	 *
	 * Creates a hash-based token that doesn't expire (unlike nonces).
	 * Token is based on export_id, user_id, expires_at, and a secret key.
	 *
	 * @since 9.0.0
	 *
	 * @param int    $export_id Export ID.
	 * @param int    $user_id   User ID who owns the export.
	 * @param string $expires_at Expiration date/time.
	 * @return string Secure download token.
	 */
	private function generate_download_token( $export_id, $user_id, $expires_at ) {
		// Use WordPress salt keys for security
		$secret = wp_salt( 'nonce' ) . wp_salt( 'auth' );

		// Create token based on export_id, user_id, expires_at, and secret
		$token_data = $export_id . '|' . $user_id . '|' . $expires_at . '|' . $secret;
		$token = hash_hmac( 'sha256', $token_data, $secret );

		return $token;
	}

	/**
	 * Verify a download token.
	 *
	 * @since 9.0.0
	 *
	 * @param string $token     Token to verify.
	 * @param int    $export_id Export ID.
	 * @param int    $user_id   User ID who owns the export.
	 * @param string $expires_at Expiration date/time.
	 * @return bool True if token is valid, false otherwise.
	 */
	private function verify_download_token( $token, $export_id, $user_id, $expires_at ) {
		if ( empty( $token ) ) {
			return false;
		}

		$expected_token = $this->generate_download_token( $export_id, $user_id, $expires_at );

		// Use hash_equals for timing-safe comparison
		return hash_equals( $expected_token, $token );
	}

	/**
	 * Handle secure file download.
	 *
	 * Verifies permissions, checks file ownership, and streams file to browser
	 * with proper headers. Includes directory traversal protection.
	 *
	 * @since 9.0.0
	 *
	 * @param int    $export_id Export ID.
	 * @param string $token     Security token (replaces nonce for long-lived URLs).
	 * @return void Exits after sending file or error.
	 */
	public function handle_download( $export_id, $token ) {
		// Get export record first to verify token
		$export = $this->get_export( $export_id );
		if ( ! $export ) {
			wp_die(
				esc_html__( 'Export not found.', 'google-analytics-premium' ),
				esc_html__( 'Not Found', 'google-analytics-premium' ),
				array( 'response' => 404 )
			);
		}

		// Verify token
		if ( empty( $export->download_token ) ) {
			wp_die(
				esc_html__( 'Export download token not found. Please request a new export.', 'google-analytics-premium' ),
				esc_html__( 'Invalid Export', 'google-analytics-premium' ),
				array( 'response' => 400 )
			);
		}

		$token_valid = $this->verify_download_token( $token, $export_id, $export->user_id, $export->expires_at );

		if ( ! $token_valid ) {
			wp_die(
				esc_html__( 'Security check failed. Please refresh the page and try again.', 'google-analytics-premium' ),
				esc_html__( 'Security Error', 'google-analytics-premium' ),
				array( 'response' => 403 )
			);
		}

		// Check capability
		if ( ! current_user_can( 'monsterinsights_view_dashboard' ) ) {
			wp_die(
				esc_html__( 'You do not have permission to download exports.', 'google-analytics-premium' ),
				esc_html__( 'Permission Denied', 'google-analytics-premium' ),
				array( 'response' => 403 )
			);
		}

		// Check ownership
		if ( $export->user_id != get_current_user_id() && ! current_user_can( 'manage_options' ) ) {
			wp_die(
				esc_html__( 'You do not have permission to download this export.', 'google-analytics-premium' ),
				esc_html__( 'Permission Denied', 'google-analytics-premium' ),
				array( 'response' => 403 )
			);
		}

		// Check if export is complete
		if ( 'complete' !== $export->status ) {
			wp_die(
				esc_html__( 'This export is not yet complete.', 'google-analytics-premium' ),
				esc_html__( 'Export Not Ready', 'google-analytics-premium' ),
				array( 'response' => 400 )
			);
		}

		// Check if export has expired
		if ( ! empty( $export->expires_at ) ) {
			$expires_ts = strtotime( $export->expires_at );
			if ( $expires_ts && $expires_ts < time() ) {
				wp_die(
					esc_html__( 'This export has expired and is no longer available for download.', 'google-analytics-premium' ),
					esc_html__( 'Export Expired', 'google-analytics-premium' ),
					array( 'response' => 410 )
				);
			}
		}

		// Check if file exists
		if ( empty( $export->file_name ) ) {
			wp_die(
				esc_html__( 'Export file not found.', 'google-analytics-premium' ),
				esc_html__( 'File Not Found', 'google-analytics-premium' ),
				array( 'response' => 404 )
			);
		}

		// Sanitize filename to prevent directory traversal
		$filename = basename( $export->file_name );
		if ( $filename !== $export->file_name ) {
			wp_die(
				esc_html__( 'Invalid file name.', 'google-analytics-premium' ),
				esc_html__( 'Security Error', 'google-analytics-premium' ),
				array( 'response' => 400 )
			);
		}

		// Resolve the per-bundle subdirectory. It must be a single path segment.
		// Exports created before per-bundle subdirectories have none.
		$subdir = isset( $export->file_subdir ) ? (string) $export->file_subdir : '';
		if ( '' !== $subdir && basename( $subdir ) !== $subdir ) {
			wp_die(
				esc_html__( 'Invalid file path.', 'google-analytics-premium' ),
				esc_html__( 'Security Error', 'google-analytics-premium' ),
				array( 'response' => 400 )
			);
		}

		// Build file path
		$upload_dir = wp_upload_dir();
		$export_dir = trailingslashit( $upload_dir['basedir'] ) . 'monsterinsights/exports/';
		$file_path  = '' !== $subdir ? trailingslashit( $export_dir . $subdir ) . $filename : $export_dir . $filename;

		// Verify file exists
		if ( ! file_exists( $file_path ) ) {
			wp_die(
				esc_html__( 'Export file no longer exists. It may have been deleted or expired.', 'google-analytics-premium' ),
				esc_html__( 'File Not Found', 'google-analytics-premium' ),
				array( 'response' => 404 )
			);
		}

		// Verify file is within exports directory (additional security check)
		$real_file_path   = realpath( $file_path );
		$real_export_dir  = realpath( $export_dir );
		if ( false === $real_file_path || false === $real_export_dir || strpos( $real_file_path, $real_export_dir ) !== 0 ) {
			wp_die(
				esc_html__( 'Invalid file path.', 'google-analytics-premium' ),
				esc_html__( 'Security Error', 'google-analytics-premium' ),
				array( 'response' => 400 )
			);
		}

		// Set headers for download
		$file_size = filesize( $file_path );
		$mime_type = 'application/zip';

		// Determine mime type based on extension if not a zip
		if ( ! preg_match( '/\.zip$/i', $filename ) ) {
			if ( preg_match( '/\.csv$/i', $filename ) ) {
				$mime_type = 'text/csv';
			} elseif ( preg_match( '/\.xlsx$/i', $filename ) ) {
				$mime_type = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
			}
		}

		// Clear any previous output
		if ( ob_get_level() ) {
			ob_end_clean();
		}

		// Set headers
		header( 'Content-Type: ' . $mime_type );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Content-Length: ' . $file_size );
		header( 'Content-Transfer-Encoding: binary' );
		header( 'Cache-Control: must-revalidate, post-check=0, pre-check=0' );
		header( 'Pragma: public' );
		header( 'Expires: 0' );

		// Stream file to output
		$handle = fopen( $file_path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( false === $handle ) {
			wp_die(
				esc_html__( 'Failed to read export file.', 'google-analytics-premium' ),
				esc_html__( 'File Read Error', 'google-analytics-premium' ),
				array( 'response' => 500 )
			);
		}

		// Stream in chunks to handle large files
		while ( ! feof( $handle ) ) {
			echo fread( $handle, 8192 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped, WordPress.WP.AlternativeFunctions.file_system_operations_fread
			flush();
		}

		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
	}

	/**
	 * Get download URL for export.
	 * Uses a secure token instead of nonce for long-lived URLs stored in database.
	 *
	 * @param int $export_id Export ID.
	 * @return string Download URL.
	 */
	public function get_download_url( $export_id ) {
		$export = $this->get_export( $export_id );

		if ( ! $export || empty( $export->download_token ) ) {
			// Token should always be available for completed exports
			// If not, return empty string to prevent invalid URLs
			return '';
		}

		return add_query_arg(
			array(
				'monsterinsights_download_export' => $export_id,
				'token'                           => $export->download_token,
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * Get allowed report types.
	 * @return array Array of allowed report types.
	 */
	private function get_allowed_report_types() {
		return array(
			'overview',
			'traffic_overview',
			'traffic_landing_pages',
			'traffic_source_medium',
			'traffic_ai',
			'traffic_technology',
			'traffic_campaign',
			'traffic_social',
			'publisher',
			'countries',
			'engagement_pages',
			'custom_events',
			'ecommerce',
			'cart_abandonment',
			'ecommerce_coupons',
			'ecommerce_funnel',
			'ecommerce_purchases_by_location',
			'ecommerce_spend_by_day',
			'ecommerce_spend_by_hour',
			'ecommerce_refunds',
			'ecommerce_refunds_by_geo',
			'forms',
			'media',
			'dimensions',
		);
	}
}

/**
 * Get the Export Manager instance.
 *
 * @since 9.0.0
 * @return MonsterInsights_Export_Manager
 */
function monsterinsights_export_manager() {
	return MonsterInsights_Export_Manager::get_instance();
}
