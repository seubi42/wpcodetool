<?php
/**
 * Export Processor Class
 *
 * Handles background processing of export jobs. Processes the queue one at a time,
 * fetches data from the MonsterInsights API, generates files, and updates status.
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
 * MonsterInsights_Export_Processor Class
 *
 * Processes export jobs in the background via WP Cron.
 *
 * @since 9.0.0
 */
final class MonsterInsights_Export_Processor {

	/**
	 * Singleton instance.
	 *
	 * @since 9.0.0
	 * @var MonsterInsights_Export_Processor
	 */
	private static $instance;

	/**
	 * Job lock option key.
	 *
	 * @since 9.0.0
	 * @var string
	 */
	private $lock_key = 'monsterinsights_export_processor_lock';

	/**
	 * Maximum processing time (10 minutes in seconds).
	 *
	 * @since 9.0.0
	 * @var int
	 */
	private $max_execution_time = 600;

	/**
	 * Maximum retry attempts for failed exports.
	 *
	 * @since 9.0.0
	 * @var int
	 */
	private $max_retry_attempts = 3;

	/**
	 * Get singleton instance.
	 *
	 * @since 9.0.0
	 * @return MonsterInsights_Export_Processor
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
	 * Process the next export in the queue.
	 *
	 * Main entry point for WP Cron. Checks for pending exports,
	 * acquires a lock, and processes one export at a time.
	 *
	 * @return bool True if an export was processed, false otherwise.
	 */
	public function process_next_export() {
		// Check if already processing
		if ( $this->is_locked() ) {
			$this->log( 'Processor is locked, another export is currently processing', 0, 'WARNING' );
			return false;
		}

		// Get next pending export
		$export = $this->get_next_pending_export();
		if ( ! $export ) {
			return false;
		}

		$this->log( 'Starting export processor run' );
		$this->log( 'Found pending export', $export->id, 'INFO', array(
			'report_type' => $export->report_type,
			'format'      => $export->export_format,
			'status'      => $export->status,
		) );

		// Acquire lock
		if ( ! $this->acquire_lock() ) {
			$this->log( 'Failed to acquire processor lock', $export->id, 'ERROR' );
			return false;
		}
		$this->log( 'Lock acquired successfully', $export->id );

		// Set up error handling
		$error_message = '';

		try {
			// Increase time limit and memory
			$this->prepare_environment();
			$this->log( 'Environment prepared (time limit and memory increased)', $export->id );

			// Update status to in_progress
			monsterinsights_export_manager()->update_export_status( $export->id, 'in_progress' );
			$this->log( 'Export status updated to: in_progress', $export->id );

			// Process the export
			$this->log( 'Beginning export processing', $export->id );
			$result = $this->process_export( $export );

			$this->log( 'Export processing returned', $export->id, 'INFO', array(
				'result' => wp_json_encode( $result ),
			) );

			if ( $result['success'] ) {
				$this->log( 'Export succeeded, updating status to complete', $export->id );
				// Update to complete with file info
				$update_result = monsterinsights_export_manager()->update_export_status(
					$export->id,
					'complete',
					array(
						'file_name'   => $result['file_name'],
						'file_size'   => $result['file_size'],
						'file_subdir' => isset( $result['file_subdir'] ) ? $result['file_subdir'] : '',
					)
				);

				$this->log( 'Status update result', $export->id, 'INFO', array(
					'update_result' => $update_result ? 'true' : 'false',
				) );

				$this->log( 'Export completed successfully', $export->id, 'INFO', array(
					'file_name' => $result['file_name'],
					'file_size' => $result['file_size'],
				) );

				// Trigger notification
				do_action( 'monsterinsights_export_completed', $export->id, $result );

				$this->release_lock();
				$this->log( 'Lock released, export processor run finished successfully', $export->id );
				return true;
			} else {
				$error_message = $result['error'];
				$this->log( 'Export processing failed', $export->id, 'ERROR', array( 'error' => $error_message ) );
			}
		} catch ( Exception $e ) {
			$error_message = $e->getMessage();
			$this->log( 'Exception caught during export processing', $export->id, 'ERROR', array(
				'error'      => $error_message,
				'trace'      => $e->getTraceAsString(),
			) );
		} catch ( Error $e ) {
			// Catch PHP 7+ fatal errors (Error class).
			$error_message = $e->getMessage();
			$this->log( 'PHP Error caught during export processing', $export->id, 'ERROR', array(
				'error'      => $error_message,
				'file'       => $e->getFile(),
				'line'       => $e->getLine(),
				'trace'      => $e->getTraceAsString(),
			) );
		}

		// Handle failure
		$this->handle_export_failure( $export, $error_message );
		$this->release_lock();
		$this->log( 'Lock released after failure, export processor run finished', $export->id );

		return false;
	}

	/**
	 * Process a single export.
	 *
	 * Fetches report data from the API, formats it, creates a file,
	 * and returns the result.
	 *
	 * @since 9.0.0
	 *
	 * @param object $export Export record from database.
	 * @return array {
	 *     Processing result.
	 *
	 *     @type bool   $success   Whether processing succeeded.
	 *     @type string $error     Error message (on failure).
	 *     @type string $file_name File name (on success).
	 *     @type int    $file_size File size in bytes (on success).
	 * }
	 */
	private function process_export( $export ) {
		$config = array(
			'report_type'         => $export->report_type,
			'start_date'          => $export->start_date,
			'end_date'            => $export->end_date,
			'date_range_display'  => $export->date_range_display,
		);

		$this->log( 'Using export configuration from stored fields', $export->id, 'INFO', array(
			'report_type' => $config['report_type'],
			'date_range'  => $config['start_date'] . ' to ' . $config['end_date'],
		) );

		// Fetch report data.
		$this->log( 'Getting report data', $export->id );
		$report      = null;
		$report_data = null;
		$fetch_time  = 0;
		$start_time  = microtime( true );

		// New relay-based export path: used when a query builder is registered
		// for this report type (e.g. the Vue 3 overview report).
		if ( class_exists( 'MonsterInsights_API_Export_Report' )
			&& MonsterInsights_API_Export_Report::has_query_builder( $config['report_type'] )
		) {
			$this->log( 'Using relay API export for: ' . $config['report_type'], $export->id );

			$filters = array();
			if ( ! empty( $export->filters ) ) {
				$decoded = json_decode( $export->filters, true );
				if ( is_array( $decoded ) ) {
					$filters = $decoded;
				}
			}

			$api         = new MonsterInsights_API_Export_Report();
			$report_data = $api->get_export_data(
				$config['report_type'],
				$config['start_date'],
				$config['end_date'],
				$filters
			);
			$fetch_time = round( microtime( true ) - $start_time, 2 );

			if ( $report_data instanceof MonsterInsights_API_Error ) {
				$this->log( 'Relay API returned error object', $export->id, 'ERROR', array(
					'error' => $report_data->get_error_message(),
					'time'  => $fetch_time . 's',
				) );
				return array(
					'success' => false,
					'error'   => $report_data->get_error_message(),
				);
			}

		} else {
			// Standard single report handling.
			$report = $this->get_report_instance( $config['report_type'] );
			if ( ! $report ) {
				$this->log( 'Report instance not found', $export->id, 'ERROR', array(
					'report_type' => $config['report_type'],
				) );
				return array(
					'success' => false,
					'error'   => sprintf(
						// Translators: %s is the report type
						__( 'Report type not found: %s', 'google-analytics-premium' ),
						$config['report_type']
					),
				);
			}
			$this->log( 'Report instance retrieved successfully', $export->id );

			$this->log( 'Fetching report data from API', $export->id );
			$report_data = $this->fetch_report_data( $report, $config );
			$fetch_time  = round( microtime( true ) - $start_time, 2 );
		}

		if ( is_wp_error( $report_data ) ) {
			$this->log( 'API fetch failed', $export->id, 'ERROR', array(
				'error' => $report_data->get_error_message(),
				'time'  => $fetch_time . 's',
			) );
			return array(
				'success' => false,
				'error'   => $report_data->get_error_message(),
			);
		}

		if ( empty( $report_data ) ) {
			$this->log( 'No data returned from API', $export->id, 'WARNING', array(
				'time' => $fetch_time . 's',
			) );
			return array(
				'success' => false,
				'error'   => __( 'No data available for the selected date range.', 'google-analytics-premium' ),
			);
		}
		$this->log( 'Report data fetched successfully', $export->id, 'INFO', array(
			'time'       => $fetch_time . 's',
			'data_count' => count( $report_data ),
		) );

		// Format data based on export format.
		$report_title = $report ? $report->title : __( 'Overview Report', 'google-analytics-premium' );

		$this->log( 'Formatting data as ' . $export->export_format, $export->id );
		$formatter      = MonsterInsights_Export_Formatter::get_instance();
		$formatted_data = $formatter->format(
			$report_data,
			$export->export_format,
			array(
				'report_type'  => $config['report_type'],
				'report_title' => $report_title,
				'date_range'   => $config['date_range_display'],
				'export_date'  => $export->export_date,
			)
		);

		if ( is_wp_error( $formatted_data ) ) {
			$this->log( 'Data formatting failed', $export->id, 'ERROR', array(
				'error' => $formatted_data->get_error_message(),
			) );
			return array(
				'success' => false,
				'error'   => $formatted_data->get_error_message(),
			);
		}
		$this->log( 'Data formatted successfully', $export->id, 'INFO', array(
			'size' => strlen( $formatted_data ) . ' bytes',
		) );

		// Create export file
		$this->log( 'Creating export file', $export->id );
		$file_result = $this->create_export_file(
			$formatted_data,
			$export->export_format,
			$config
		);

		if ( is_wp_error( $file_result ) ) {
			$this->log( 'File creation failed', $export->id, 'ERROR', array(
				'error' => $file_result->get_error_message(),
			) );
			return array(
				'success' => false,
				'error'   => $file_result->get_error_message(),
			);
		}
		$this->log( 'Export file created successfully', $export->id, 'INFO', array(
			'file_name' => $file_result['file_name'],
			'file_size' => $file_result['file_size'],
		) );

		return array(
			'success'     => true,
			'file_name'   => $file_result['file_name'],
			'file_size'   => $file_result['file_size'],
			'file_subdir' => isset( $file_result['file_subdir'] ) ? $file_result['file_subdir'] : '',
		);
	}

	/**
	 * Get report instance by name.
	 *
	 * @param string $report_type Report type identifier.
	 * @return MonsterInsights_Report|false Report instance or false if not found.
	 */
	private function get_report_instance( $report_type ) {
		// Check if MonsterInsights is available
		if ( ! function_exists( 'MonsterInsights' ) ) {
			$this->log( 'MonsterInsights() function not available', 0, 'ERROR' );
			return false;
		}

		// Check if reporting API is available
		if ( ! isset( MonsterInsights()->reporting ) ) {
			$this->log( 'MonsterInsights reporting API not available', 0, 'ERROR' );
			return false;
		}

		$report = MonsterInsights()->reporting->get_report( $report_type );
		if ( ! $report ) {
			$this->log( 'Report type not registered in MonsterInsights', 0, 'ERROR', array(
				'report_type' => $report_type,
			) );
		}

		return $report;
	}

	/**
	 * Fetch report data from MonsterInsights API.
	 *
	 * @since 9.0.0
	 *
	 * @param MonsterInsights_Report $report Report instance.
	 * @param array                  $config Export configuration.
	 * @return array|WP_Error Report data or error.
	 */
	private function fetch_report_data( $report, $config ) {
		// Build arguments for report data request
		$args = array(
			'start' => $config['start_date'],
			'end'   => $config['end_date'],
		);

		// Get report data (this uses the MonsterInsights API)
		$result = $report->get_data( $args );

		// Check for errors
		if ( isset( $result['success'] ) && false === $result['success'] ) {
			$error_message = isset( $result['error'] ) ? $result['error'] : __( 'Unknown error fetching report data.', 'google-analytics-premium' );
			return new WP_Error( 'api_error', $error_message );
		}

		// Return the data
		if ( isset( $result['data'] ) && is_array( $result['data'] ) ) {
			return $result['data'];
		}

		return new WP_Error( 'no_data', __( 'No data returned from API.', 'google-analytics-premium' ) );
	}

	/**
	 * Create export file (ZIP with CSV or XML).
	 *
	 * @since 9.0.0
	 *
	 * @param string $formatted_data Formatted data string.
	 * @param string $format         Export format ('csv' or 'xml').
	 * @param array  $config         Export configuration.
	 * @return array|WP_Error {
	 *     File creation result.
	 *
	 *     @type string $file_name File name.
	 *     @type int    $file_size File size in bytes.
	 * }
	 */
	private function create_export_file( $formatted_data, $format, $config ) {
		// Get upload directory
		$upload_dir = wp_upload_dir();
		$export_dir = trailingslashit( $upload_dir['basedir'] ) . 'monsterinsights/exports/';

		// Ensure directory exists
		if ( ! file_exists( $export_dir ) ) {
			$this->log( 'Export directory does not exist, attempting to create', 0, 'WARNING' );
			if ( ! wp_mkdir_p( $export_dir ) ) {
				$this->log( 'Failed to create export directory', 0, 'ERROR', array(
					'directory' => $export_dir,
				) );
				return new WP_Error(
					'directory_error',
					__( 'Failed to create exports directory.', 'google-analytics-premium' )
				);
			}
			$this->log( 'Export directory created successfully', 0, 'INFO' );
		}

		// Check if directory is writable
		if ( ! is_writable( $export_dir ) ) {
			$this->log( 'Export directory is not writable', 0, 'ERROR', array(
				'directory'   => $export_dir,
				'permissions' => substr( sprintf( '%o', fileperms( $export_dir ) ), -4 ),
			) );
			return new WP_Error(
				'directory_not_writable',
				__( 'Exports directory is not writable. Please check permissions.', 'google-analytics-premium' )
			);
		}

		// Directory exists, continue
		if ( file_exists( $export_dir ) ) {

			// Add .htaccess for security
			$htaccess_file = $export_dir . '.htaccess';
			$htaccess_content = "Deny from all\n";
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( $htaccess_file, $htaccess_content );

			// Add index.php for security
			$index_file = $export_dir . 'index.php';
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( $index_file, '<?php // Silence is golden' );
		}

		$timestamp = gmdate( 'YmdHis' );

		// Place each export bundle inside its own randomly named subdirectory.
		// The .htaccess / index.php files added above are retained as an
		// additional layer of protection.
		$subdir     = wp_generate_password( 32, false );
		$bundle_dir = trailingslashit( $export_dir . $subdir );
		if ( ! wp_mkdir_p( $bundle_dir ) ) {
			$this->log( 'Failed to create export bundle directory', 0, 'ERROR', array(
				'directory' => $bundle_dir,
			) );
			return new WP_Error(
				'directory_error',
				__( 'Failed to create exports directory.', 'google-analytics-premium' )
			);
		}

		// Excel export: write .xlsx directly (no extra zip wrapper).
		if ( 'excel' === $format ) {
			if ( ! class_exists( 'ZipArchive' ) ) {
				$this->log( 'ZipArchive PHP extension not available for XLSX generation', 0, 'ERROR', array(
					'php_version' => phpversion(),
				) );
				return new WP_Error(
					'zip_unavailable',
					__( 'ZipArchive PHP extension is not available on this server.', 'google-analytics-premium' )
				);
			}

			$xlsx_filename = 'monsterinsights-' . $config['report_type'] . '-' . $timestamp . '.xlsx';
			$xlsx_filepath = $bundle_dir . $xlsx_filename;

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			$written = file_put_contents( $xlsx_filepath, $formatted_data );
			if ( false === $written ) {
				return new WP_Error(
					'file_write_error',
					__( 'Failed to write export file.', 'google-analytics-premium' )
				);
			}

			return array(
				'file_name'   => $xlsx_filename,
				'file_size'   => filesize( $xlsx_filepath ),
				'file_subdir' => $subdir,
			);
		}

		// Check if formatted_data is already a ZIP file (for multi-CSV reports)
		// ZIP files start with "PK" signature (0x504B)
		$is_zip = strlen( $formatted_data ) >= 2 && substr( $formatted_data, 0, 2 ) === 'PK';

		if ( $is_zip ) {
			// Formatter already created a ZIP file, write it directly
			$zip_filename = 'monsterinsights-' . $config['report_type'] . '-' . $timestamp . '.zip';
			$zip_filepath = $bundle_dir . $zip_filename;

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			$written = file_put_contents( $zip_filepath, $formatted_data );
			if ( false === $written ) {
				return new WP_Error(
					'file_write_error',
					__( 'Failed to write export file.', 'google-analytics-premium' )
				);
			}

			return array(
				'file_name'   => $zip_filename,
				'file_size'   => filesize( $zip_filepath ),
				'file_subdir' => $subdir,
			);
		}

		// Default: CSV (write CSV, then zip if ZipArchive is available).
		$temp_filename = 'monsterinsights-' . $config['report_type'] . '-' . $timestamp . '.' . $format;
		$temp_filepath = $bundle_dir . $temp_filename;

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$written = file_put_contents( $temp_filepath, $formatted_data );
		if ( false === $written ) {
			return new WP_Error(
				'file_write_error',
				__( 'Failed to write export file.', 'google-analytics-premium' )
			);
		}

		// ZipArchive not available, keep the raw file.
		if ( ! class_exists( 'ZipArchive' ) ) {
			return array(
				'file_name'   => $temp_filename,
				'file_size'   => filesize( $temp_filepath ),
				'file_subdir' => $subdir,
			);
		}

		$zip_filename = 'monsterinsights-' . $config['report_type'] . '-' . $timestamp . '.zip';
		$zip_filepath = $bundle_dir . $zip_filename;

		$zip = new ZipArchive();
		if ( $zip->open( $zip_filepath, ZipArchive::CREATE ) !== true ) {
			// Clean up temp file
			wp_delete_file( $temp_filepath );
			return new WP_Error(
				'zip_error',
				__( 'Failed to create ZIP file.', 'google-analytics-premium' )
			);
		}

		$zip->addFile( $temp_filepath, $temp_filename );
		$zip->close();

		// Delete temporary uncompressed file
		wp_delete_file( $temp_filepath );

		if ( ! file_exists( $zip_filepath ) ) {
			return new WP_Error(
				'zip_not_created',
				__( 'ZIP file was not created.', 'google-analytics-premium' )
			);
		}

		return array(
			'file_name'   => $zip_filename,
			'file_size'   => filesize( $zip_filepath ),
			'file_subdir' => $subdir,
		);
	}

	/**
	 * Handle export failure with retry logic.
	 *
	 * @since 9.0.0
	 *
	 * @param object $export        Export record.
	 * @param string $error_message Error message.
	 */
	private function handle_export_failure( $export, $error_message ) {
		// Get current retry count
		$retry_count = isset( $export->retry_count ) ? (int) $export->retry_count : 0;
		$retry_count++;

		// Check if we should retry
		if ( $retry_count < $this->max_retry_attempts ) {
			// Calculate exponential backoff delay (5 minutes * 2^retry_count)
			$delay = 5 * MINUTE_IN_SECONDS * pow( 2, $retry_count - 1 );
			$retry_at = gmdate( 'Y-m-d H:i:s', time() + $delay );

			// Update export with retry info
			monsterinsights_export_manager()->update_export_status(
				$export->id,
				'pending',
				array(
					'retry_count' => $retry_count,
					'retry_at'    => $retry_at,
					'error'       => $error_message,
				)
			);
		} else {
			// Max retries reached, mark as failed
			monsterinsights_export_manager()->update_export_status(
				$export->id,
				'failed',
				array(
					'error' => $error_message,
				)
			);

			// Trigger failure notification
			do_action( 'monsterinsights_export_failed', $export->id, $error_message );
		}
	}

	/**
	 * Get next pending export from queue.
	 *
	 * Retrieves the oldest pending export that is ready to process
	 * (considering retry delays).
	 *
	 * @return object|null Export record or null if none available.
	 */
	private function get_next_pending_export() {
		return monsterinsights_export_queue_store()->get_next_pending_ready( current_time( 'mysql' ) );
	}

	/**
	 * Check if processor is currently locked.
	 *
	 * @since 9.0.0
	 *
	 * @return bool True if locked, false otherwise.
	 */
	private function is_locked() {
		$lock = get_transient( $this->lock_key );
		if ( ! $lock ) {
			return false;
		}

		// Check if lock has expired (should never happen, but safety check)
		$lock_time = (int) $lock;
		if ( time() - $lock_time > $this->max_execution_time ) {
			// Lock expired, release it
			$this->release_lock();
			return false;
		}

		return true;
	}

	/**
	 * Acquire processing lock.
	 *
	 * @since 9.0.0
	 *
	 * @return bool True if lock acquired, false otherwise.
	 */
	private function acquire_lock() {
		// Try to set lock (will fail if already set)
		$locked = set_transient( $this->lock_key, time(), $this->max_execution_time );
		return $locked;
	}

	/**
	 * Release processing lock.
	 *
	 * @since 9.0.0
	 */
	private function release_lock() {
		delete_transient( $this->lock_key );
	}

	/**
	 * Prepare environment for processing.
	 *
	 * Increases time limit and memory limit to handle large exports.
	 *
	 * @since 9.0.0
	 */
	private function prepare_environment() {
		// Increase time limit
		if ( function_exists( 'set_time_limit' ) && false === strpos( ini_get( 'disable_functions' ), 'set_time_limit' ) ) {
			@set_time_limit( $this->max_execution_time ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}

		// Increase memory limit
		$memory_limit = apply_filters( 'monsterinsights_export_memory_limit', '256M' );
		if ( function_exists( 'ini_set' ) ) {
			@ini_set( 'memory_limit', $memory_limit ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.PHP.IniSet.memory_limit_Disallowed -- export processing requires elevated memory.
		}
	}

	/**
	 * Log debug message for export processing.
	 *
	 * Writes log entries to WordPress debug.log and custom export log.
	 * Only logs when WP_DEBUG is enabled.
	 *
	 * @since 9.0.1
	 *
	 * @param string $message     Log message.
	 * @param int    $export_id   Optional. Export ID for context.
	 * @param string $level       Optional. Log level: INFO, WARNING, ERROR. Default 'INFO'.
	 * @param array  $context     Optional. Additional context data.
	 */
	private function log( $message, $export_id = 0, $level = 'INFO', $context = array() ) {
		// Only log if WP_DEBUG is enabled
		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
			return;
		}

		// Build log entry
		$timestamp = current_time( 'Y-m-d H:i:s' );
		$export_context = $export_id ? " [Export ID: {$export_id}]" : '';
		$log_entry = sprintf(
			'[%s] [MonsterInsights Export %s]%s %s',
			$timestamp,
			$level,
			$export_context,
			$message
		);

		// Add context if provided
		if ( ! empty( $context ) ) {
			$log_entry .= ' | Context: ' . wp_json_encode( $context );
		}

		// Log to WordPress debug.log
		if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( $log_entry );
		}

		// Log to custom export log file (optional)
		$upload_dir = wp_upload_dir();
		$log_dir = trailingslashit( $upload_dir['basedir'] ) . 'monsterinsights/exports/';
		if ( file_exists( $log_dir ) && is_writable( $log_dir ) ) {
			$log_file = $log_dir . 'export-debug.log';
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( $log_file, $log_entry . "\n", FILE_APPEND );
		}
	}
}

/**
 * Get the Export Processor instance.
 *
 * @return MonsterInsights_Export_Processor
 */
function monsterinsights_export_processor() {
	return MonsterInsights_Export_Processor::get_instance();
}
