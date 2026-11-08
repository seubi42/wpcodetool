<?php
/**
 * Export Formatter Class
 *
 * Handles formatting of report data into CSV and XML formats.
 * Supports UTF-8 encoding, proper escaping, and metadata inclusion.
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
 * MonsterInsights_Export_Formatter Class
 *
 * Formats report data for export in various formats.
 */
final class MonsterInsights_Export_Formatter {

	/**
	 * Singleton instance.
	 *
	 * @since 9.0.0
	 * @var MonsterInsights_Export_Formatter
	 */
	private static $instance;

	/**
	 * Multi-CSV report configurations.
	 * Stores configurations for report types that export multiple CSV files.
	 *
	 * @var array
	 */
	private $multi_csv_configs = array();

	/**
	 * Get singleton instance.
	 *
	 * @since 9.0.0
	 * @return MonsterInsights_Export_Formatter
	 */
	public static function get_instance() {
		if ( ! isset( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		// Register default multi-CSV configurations
		$this->register_default_multi_csv_configs();
	}

	/**
	 * Format data based on export format.
	 *
	 * Main entry point for formatting. Routes to appropriate formatter
	 * based on format type.
	 *
	 * @since 9.0.0
	 *
	 * @param array  $data     Report data to format.
	 * @param string $format   Export format ('csv' or 'excel').
	 * @param array  $metadata Optional metadata to include in export.
	 * @return string|WP_Error Formatted data string or error.
	 */
	public function format( $data, $format, $metadata = array() ) {
		if ( empty( $data ) || ! is_array( $data ) ) {
			return new WP_Error(
				'invalid_data',
				__( 'Invalid data provided for formatting.', 'google-analytics-premium' )
			);
		}

		switch ( $format ) {
			case 'csv':
				return $this->to_csv( $data, $metadata );
			case 'excel':
				return $this->to_excel( $data, $metadata );
			default:
				return new WP_Error(
					'invalid_format',
					sprintf(
						// Translators: %s is the format type
						__( 'Unsupported export format: %s', 'google-analytics-premium' ),
						$format
					)
				);
		}
	}

	/**
	 * Format data as CSV.
	 *
	 * Generates CSV with UTF-8 BOM for Excel compatibility.
	 * Automatically flattens nested arrays and handles various data structures.
	 * For traffic_technology report type, creates multiple CSV files and zips them.
	 *
	 * @since 9.0.0
	 *
	 * @param array $data     Report data.
	 * @param array $metadata Export metadata.
	 * @return string|WP_Error CSV formatted data, ZIP file content, or error.
	 */
	private function to_csv( $data, $metadata ) {
		// Check if this report type uses multi-CSV export
		$multi_csv_config = $this->get_multi_csv_config( $metadata['report_type'] );

		if ( $multi_csv_config ) {
			return $this->to_csv_multi_files( $data, $metadata, $multi_csv_config );
		}

		// Dynamic multi-CSV for dimensions report (config depends on data at runtime).
		if ( 'dimensions' === $metadata['report_type'] ) {
			$dimensions_config = $this->build_dimensions_multi_csv_config( $data );
			if ( ! empty( $dimensions_config ) ) {
				return $this->to_csv_multi_files( $data, $metadata, $dimensions_config );
			}
		}

		// Start output buffering
		ob_start();

		$rows = $this->get_tabular_rows( $data, $metadata );

		if ( empty( $rows ) ) {
			return ob_get_clean();
		}

		// Add UTF-8 BOM for Excel compatibility
		echo "\xEF\xBB\xBF";

		// Extract headers from first row
		$headers = array_keys( reset( $rows ) );

		// Open output stream (works in both CLI and HTTP contexts)
		$output = fopen( 'php://output', 'w' );

		// Output headers
		fputcsv( $output, $headers );

		// Output rows
		foreach ( $rows as $row ) {
			// Ensure row matches header structure
			$ordered_row = array();
			foreach ( $headers as $header ) {
				$ordered_row[] = isset( $row[ $header ] ) ? $row[ $header ] : '';
			}
			fputcsv( $output, $ordered_row );
		}

		// Close output stream
		fclose( $output );

		// Get output and clean buffer
		$csv = ob_get_clean();

		return $csv;
	}

	/**
	 * Format data as Excel (XLSX).
	 *
	 * For report types with multi-CSV configuration, creates multiple worksheets.
	 * Otherwise, creates a single worksheet.
	 *
	 * @since 9.0.0
	 *
	 * @param array $data     Report data.
	 * @param array $metadata Export metadata.
	 * @return string|WP_Error XLSX binary string or error.
	 */
	private function to_excel( $data, $metadata ) {
		// Check if this report type uses multi-sheet export (same configs as multi-CSV).
		$multi_csv_config = $this->get_multi_csv_config( $metadata['report_type'] );

		if ( $multi_csv_config ) {
			return $this->to_excel_multi_sheets( $data, $metadata, $multi_csv_config );
		}

		// Dynamic multi-sheet for dimensions report (config depends on data at runtime).
		if ( 'dimensions' === $metadata['report_type'] ) {
			$dimensions_config = $this->build_dimensions_multi_csv_config( $data );
			if ( ! empty( $dimensions_config ) ) {
				return $this->to_excel_multi_sheets( $data, $metadata, $dimensions_config );
			}
		}

		// Single sheet export for reports without multi-CSV config.
		$rows = $this->get_tabular_rows( $data, $metadata );
		if ( empty( $rows ) ) {
			return new WP_Error(
				'no_rows',
				__( 'No data available for export.', 'google-analytics-premium' )
			);
		}

		$headers = array_keys( reset( $rows ) );

		$sheet_rows   = array();
		$sheet_rows[] = $headers;

		foreach ( $rows as $row ) {
			$ordered_row = array();
			foreach ( $headers as $header ) {
				$ordered_row[] = isset( $row[ $header ] ) ? $row[ $header ] : '';
			}
			$sheet_rows[] = $ordered_row;
		}

		$sheet_name = 'Report';
		if ( ! empty( $metadata['report_title'] ) ) {
			$sheet_name = (string) $metadata['report_title'];
		} elseif ( ! empty( $metadata['report_type'] ) ) {
			$sheet_name = (string) $metadata['report_type'];
		}

		return MonsterInsights_SimpleXLSXGen::from_rows( $sheet_rows, $sheet_name );
	}

	/**
	 * Format data as Excel (XLSX) with multiple sheets.
	 *
	 * Creates a single XLSX file with multiple worksheets based on multi-CSV configuration.
	 * Each config entry becomes a separate sheet in the workbook.
	 *
	 * @since 9.0.0
	 *
	 * @param array $data              Report data.
	 * @param array $metadata          Export metadata.
	 * @param array $multi_csv_config  Multi-CSV configuration (reused for sheets).
	 * @return string|WP_Error XLSX binary string or error.
	 */
	private function to_excel_multi_sheets( $data, $metadata, $multi_csv_config ) {
		$sheets      = array();
		$report_type = isset( $metadata['report_type'] ) ? $metadata['report_type'] : 'export';

		// Generate sheets based on configuration.
		foreach ( $multi_csv_config as $config_key => $config ) {
			// Get data rows for this sheet (reuse existing CSV data preparation).
			$rows = $this->get_csv_data_rows( $config, $data, $metadata );

			if ( empty( $rows ) || ! is_array( $rows ) ) {
				continue;
			}

			// Get columns from config.
			$columns = isset( $config['columns'] ) && is_array( $config['columns'] ) ? $config['columns'] : array();

			if ( empty( $columns ) ) {
				// Try to extract columns from first row if not specified.
				if ( ! empty( $rows ) && is_array( reset( $rows ) ) ) {
					$columns = array_keys( reset( $rows ) );
				} else {
					continue;
				}
			}

			// Build sheet rows with headers first.
			$sheet_rows   = array();
			$sheet_rows[] = $columns;

			// Add data rows.
			foreach ( $rows as $row ) {
				$ordered_row = array();
				foreach ( $columns as $column ) {
					$ordered_row[] = isset( $row[ $column ] ) ? $row[ $column ] : '';
				}
				$sheet_rows[] = $ordered_row;
			}

			// Generate sheet name from filename_prefix (convert underscores to spaces, capitalize).
			$sheet_name = isset( $config['filename_prefix'] ) ? $config['filename_prefix'] : $config_key;
			$sheet_name = str_replace( '_', ' ', $sheet_name );
			$sheet_name = ucwords( $sheet_name );

			$sheets[] = array(
				'name' => $sheet_name,
				'rows' => $sheet_rows,
			);
		}

		// If no sheets were created, return error.
		if ( empty( $sheets ) ) {
			return new WP_Error(
				'no_data',
				sprintf(
					/* translators: %s: Report type. */
					__( 'No data available for %s export.', 'google-analytics-premium' ),
					$report_type
				)
			);
		}

		return MonsterInsights_SimpleXLSXGen::from_multi_sheets( $sheets );
	}

	/**
	 * Convert report data into tabular rows (associative arrays) for CSV/XLSX.
	 *
	 * @param array $data Report data.
	 * @return array
	 */
	private function get_tabular_rows( $data, $metadata ) {
		if ( $metadata['report_type'] == 'traffic_overview' ) {
			return $this->format_traffic_overview_for_csv( $data );
		}

		if ( $metadata['report_type'] == 'traffic_landing_pages' ) {
			return $this->format_traffic_landing_pages_for_csv( $data );
		}

		if ( $metadata['report_type'] == 'traffic_source_medium' ) {
			return $this->format_traffic_source_medium_for_csv( $data );
		}

		if ( $metadata['report_type'] == 'traffic_ai' ) {
			return $this->format_traffic_ai_for_csv( $data );
		}

		if ( $metadata['report_type'] == 'traffic_campaign' ) {
			return $this->format_traffic_campaign_for_csv( $data );
		}

		if ( $metadata['report_type'] == 'traffic_social' ) {
			return $this->format_traffic_social_for_csv( $data );
		}

		if ( $metadata['report_type'] == 'countries' ) {
			return $this->format_publishers_countries_for_csv( $data );
		}

		if ( $metadata['report_type'] == 'engagement_pages' ) {
			return $this->format_publishers_pages_for_csv( $data );
		}

		if ( $metadata['report_type'] == 'custom_events' ) {
			return $this->format_publishers_custom_events_for_csv( $data );
		}

		if ( $metadata['report_type'] == 'ecommerce_coupons' ) {
			return $this->format_ecommerce_coupons_for_csv( $data );
		}

		if ( $metadata['report_type'] == 'forms' ) {
			return $this->format_forms_for_csv( $data );
		}

		if ( $metadata['report_type'] == 'media' ) {
			return $this->format_media_for_csv( $data );
		}

		// Convert data to tabular format (generic fallback)
		return $this->flatten_data_for_csv( $data );
	}

	/**
	 * Flatten nested data structure for CSV output.
	 *
	 * Converts various report data structures into a flat array of rows
	 * suitable for CSV export.
	 *
	 * @since 9.0.0
	 *
	 * @param array $data Report data.
	 * @return array Array of flat associative arrays.
	 */
	private function flatten_data_for_csv( $data ) {
		$rows = array();

		// Handle different data structures
		if ( $this->is_associative_array( $data ) ) {
			// Single row of data
			$rows[] = $this->flatten_array( $data );
		} elseif ( is_array( $data ) ) {
			// Multiple rows of data
			foreach ( $data as $key => $item ) {
				if ( is_array( $item ) ) {
					$flat = $this->flatten_array( $item );
					// Add row identifier if numeric key
					if ( is_numeric( $key ) ) {
						$flat = array_merge( array( 'row' => $key + 1 ), $flat );
					}
					$rows[] = $flat;
				} else {
					// Simple scalar value
					$rows[] = array( $key => $item );
				}
			}
		}

		return $rows;
	}

	/**
	 * Flatten a single array by converting nested arrays to dot notation.
	 *
	 * @since 9.0.0
	 *
	 * @param array  $array  Array to flatten.
	 * @param string $prefix Key prefix for nested items.
	 * @return array Flattened array.
	 */
	private function flatten_array( $array, $prefix = '' ) {
		$result = array();

		foreach ( $array as $key => $value ) {
			$new_key = $prefix === '' ? $key : $prefix . '.' . $key;

			if ( is_array( $value ) && ! empty( $value ) ) {
				// Check if all keys are numeric (it's a list)
				if ( array_keys( $value ) === range( 0, count( $value ) - 1 ) ) {
					// List of items - convert to comma-separated string
					$result[ $new_key ] = implode( ', ', array_map( function( $item ) {
						if ( is_array( $item ) ) {
							return wp_json_encode( $item );
						}
						return $item;
					}, $value ) );
				} else {
					// Associative array - recurse
					$result = array_merge( $result, $this->flatten_array( $value, $new_key ) );
				}
			} else {
				// Scalar value
				if ( is_bool( $value ) ) {
					$result[ $new_key ] = $value ? 'true' : 'false';
				} elseif ( is_null( $value ) ) {
					$result[ $new_key ] = '';
				} else {
					$result[ $new_key ] = $value;
				}
			}
		}

		return $result;
	}

	/**
	 * Check if array is associative (has string keys).
	 *
	 * @since 9.0.0
	 *
	 * @param array $array Array to check.
	 * @return bool True if associative, false if numeric.
	 */
	private function is_associative_array( $array ) {
		if ( ! is_array( $array ) || empty( $array ) ) {
			return false;
		}
		return array_keys( $array ) !== range( 0, count( $array ) - 1 );
	}

	/**
	 * Escape string for XML content.
	 *
	 * @since 9.0.0
	 *
	 * @param mixed $value Value to escape.
	 * @return string Escaped string.
	 */
	private function xml_escape( $value ) {
		if ( is_array( $value ) ) {
			$value = wp_json_encode( $value );
		}

		$value = (string) $value;

		// Use htmlspecialchars for XML entity encoding
		return htmlspecialchars( $value, ENT_XML1 | ENT_QUOTES, 'UTF-8' );
	}

	/**
	 * Format traffic overview data for CSV export.
	 *
	 * @param array $data Report data.
	 * @return array Array of formatted rows.
	 */
	private function format_traffic_overview_for_csv( $data ) {
		$rows = array();

		if ( ! isset( $data['traffic_details_table'] ) || ! is_array( $data['traffic_details_table'] ) ) {
			return $rows;
		}

		foreach ( $data['traffic_details_table'] as $item ) {
			$rows[] = array(
				'Channel'          => isset( $item['channel'][0] ) ? $item['channel'][0] : '',
				'Sessions'         => isset( $item['sessions'][0] ) ? $item['sessions'][0] : '',
				'Engaged Sessions' => isset( $item['engaged_sessions'][0] ) ? $item['engaged_sessions'][0] : '',
				'Pages / Sessions' => isset( $item['pages_per_sessions'][0] ) ? $item['pages_per_sessions'][0] : '',
				'Purchases'        => isset( $item['purchases'][0] ) ? $item['purchases'][0] : '',
				'Conversion Rate'  => isset( $item['conversion_rate'][0] ) ? $item['conversion_rate'][0] . '%' : '',
				'Revenue'          => isset( $item['revenue'][0] ) ? $item['revenue'][0] : '',
			);
		}

		return $rows;
	}

	/**
	 * Format traffic landing pages data for CSV export.
	 *
	 * @param array $data Report data.
	 * @return array Array of formatted rows.
	 */
	private function format_traffic_landing_pages_for_csv( $data ) {
		$rows = array();

		if ( ! isset( $data['landing_pages_table'] ) || ! is_array( $data['landing_pages_table'] ) ) {
			return $rows;
		}

		foreach ( $data['landing_pages_table'] as $item ) {
			$rows[] = array(
				'Landing Page'     => isset( $item['landing_page'][0] ) ? $item['landing_page'][0] : '',
				'Sessions'         => isset( $item['sessions'][0] ) ? $item['sessions'][0] : '',
				'Engaged Sessions' => isset( $item['engaged_sessions'][0] ) ? $item['engaged_sessions'][0] : '',
				'Pages / Sessions' => isset( $item['pages_per_sessions'][0] ) ? $item['pages_per_sessions'][0] : '',
				'Purchases'        => isset( $item['purchases'][0] ) ? $item['purchases'][0] : '',
				'Conversion Rate'  => isset( $item['conversion_rate'][0] ) ? $item['conversion_rate'][0] . '%' : '',
				'Revenue'          => isset( $item['revenue'][0] ) ? $item['revenue'][0] : '',
			);
		}

		return $rows;
	}

	/**
	 * Format traffic source medium data for CSV export.
	 *
	 * @param array $data Report data.
	 * @return array Array of formatted rows.
	 */
	private function format_traffic_source_medium_for_csv( $data ) {
		$rows = array();

		if ( ! isset( $data['source_medium_table'] ) || ! is_array( $data['source_medium_table'] ) ) {
			return $rows;
		}

		foreach ( $data['source_medium_table'] as $item ) {
			$rows[] = array(
				'Source / Medium'  => isset( $item['source_medium'][0] ) ? $item['source_medium'][0] : '',
				'Sessions'         => isset( $item['sessions'][0] ) ? $item['sessions'][0] : '',
				'Engaged Sessions' => isset( $item['engaged_sessions'][0] ) ? $item['engaged_sessions'][0] : '',
				'Pages / Sessions' => isset( $item['pages_per_sessions'][0] ) ? $item['pages_per_sessions'][0] : '',
				'Purchases'        => isset( $item['purchases'][0] ) ? $item['purchases'][0] : '',
				'Conversion Rate'  => isset( $item['conversion_rate'][0] ) ? $item['conversion_rate'][0] . '%' : '',
				'Revenue'          => isset( $item['revenue'][0] ) ? $item['revenue'][0] : '',
			);
		}

		return $rows;
	}

	/**
	 * Format traffic AI data for CSV export.
	 *
	 * @param array $data Report data.
	 * @return array Array of formatted rows.
	 */
	private function format_traffic_ai_for_csv( $data ) {
		$rows = array();

		if ( ! isset( $data['ai_table'] ) || ! is_array( $data['ai_table'] ) ) {
			return $rows;
		}

		foreach ( $data['ai_table'] as $item ) {
			$rows[] = array(
				'AI Source'            => isset( $item['platform'][0] ) ? $item['platform'][0] : '',
				'Sessions'             => isset( $item['sessions'][0] ) ? $item['sessions'][0] : '',
				'Engaged Sessions'     => isset( $item['engaged_sessions'][0] ) ? $item['engaged_sessions'][0] : '',
				'Purchases'            => isset( $item['purchases'][0] ) ? $item['purchases'][0] : '',
				'Revenue'              => isset( $item['revenue'][0] ) ? $item['revenue'][0] : '',
				'Bounce Rate'          => isset( $item['bounce_rate'][0] ) ? $item['bounce_rate'][0] . '%' : '',
				'Avg Session Duration' => isset( $item['avg_session_duration'][0] ) ? $item['avg_session_duration'][0] : '',
			);
		}

		return $rows;
	}

	/**
	 * Format data as multiple CSV files in a ZIP archive (generic/reusable method).
	 *
	 * Creates separate CSV files based on configuration, then zips them together.
	 * This method can be used by any report type that needs to export multiple CSV files.
	 * @param array $data           Report data.
	 * @param array $metadata       Export metadata.
	 * @param array $multi_csv_config Multi-CSV configuration array.
	 * @return string|WP_Error ZIP file content as binary string, or error.
	 */
	private function to_csv_multi_files( $data, $metadata, $multi_csv_config ) {
		// Check if ZipArchive is available
		if ( ! class_exists( 'ZipArchive' ) ) {
			return new WP_Error(
				'zip_unavailable',
				__( 'ZipArchive PHP extension is not available on this server.', 'google-analytics-premium' )
			);
		}

		// Create temporary directory for CSV files
		$upload_dir = wp_upload_dir();
		$temp_dir = trailingslashit( $upload_dir['basedir'] ) . 'monsterinsights/exports/temp/';

		if ( ! file_exists( $temp_dir ) ) {
			wp_mkdir_p( $temp_dir );

			// Add .htaccess for security
			$htaccess_file = $temp_dir . '.htaccess';
			$htaccess_content = "Deny from all\n";
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( $htaccess_file, $htaccess_content );

			// Add index.php for security
			$index_file = $temp_dir . 'index.php';
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( $index_file, '<?php // Silence is golden' );
		}

		$timestamp = gmdate( 'YmdHis' );
		$csv_files = array();
		$report_type = isset( $metadata['report_type'] ) ? $metadata['report_type'] : 'export';

		// Generate CSV files based on configuration
		foreach ( $multi_csv_config as $csv_key => $config ) {
			// Get data rows for this CSV file
			$rows = $this->get_csv_data_rows( $config, $data, $metadata );

			if ( empty( $rows ) || ! is_array( $rows ) ) {
				continue;
			}

			// Get columns from config
			$columns = isset( $config['columns'] ) && is_array( $config['columns'] ) ? $config['columns'] : array();

			if ( empty( $columns ) ) {
				// Try to extract columns from first row if not specified
				if ( ! empty( $rows ) && is_array( reset( $rows ) ) ) {
					$columns = array_keys( reset( $rows ) );
				} else {
					continue;
				}
			}

			// Generate CSV content from rows
			$csv_content = $this->generate_csv_from_rows( $rows, $columns );

			if ( $csv_content === false ) {
				continue;
			}

			// Create filename
			$filename_prefix = isset( $config['filename_prefix'] ) ? $config['filename_prefix'] : $csv_key;
			$filename = $filename_prefix . '-' . $timestamp . '.csv';
			$filepath = $temp_dir . $filename;

			// Write CSV file
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			if ( file_put_contents( $filepath, $csv_content ) !== false ) {
				$csv_files[] = array(
					'path' => $filepath,
					'name' => $filename,
				);
			}
		}

		// If no CSV files were created, return error
		if ( empty( $csv_files ) ) {
			return new WP_Error(
				'no_data',
				sprintf(
					/* translators: %s: Report type. */
					__( 'No data available for %s export.', 'google-analytics-premium' ),
					$report_type
				)
			);
		}

		// Create ZIP file
		$zip_filepath = $temp_dir . $report_type . '-' . $timestamp . '.zip';
		$zip = new ZipArchive();

		if ( $zip->open( $zip_filepath, ZipArchive::CREATE | ZipArchive::OVERWRITE ) !== true ) {
			// Clean up temp files
			foreach ( $csv_files as $file ) {
				wp_delete_file( $file['path'] );
			}
			return new WP_Error(
				'zip_error',
				__( 'Failed to create ZIP file.', 'google-analytics-premium' )
			);
		}

		// Add CSV files to ZIP
		foreach ( $csv_files as $file ) {
			$zip->addFile( $file['path'], $file['name'] );
		}

		$zip->close();

		// Read ZIP file content
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$zip_content = file_get_contents( $zip_filepath );

		// Clean up temporary files
		foreach ( $csv_files as $file ) {
			wp_delete_file( $file['path'] );
		}
		wp_delete_file( $zip_filepath );

		if ( $zip_content === false ) {
			return new WP_Error(
				'zip_read_error',
				__( 'Failed to read ZIP file content.', 'google-analytics-premium' )
			);
		}

		return $zip_content;
	}

	/**
	 * Get data rows for a CSV file based on configuration.
	 *
	 * Only supports data_callback function approach.
	 * The callback function receives the full report data and metadata,
	 * and must return an array of rows (each row is an associative array).
	 *
	 * @param array  $config   CSV configuration.
	 * @param array  $data     Full report data.
	 * @param array  $metadata Export metadata.
	 * @return array Array of rows, each row is an associative array.
	 */
	private function get_csv_data_rows( $config, $data, $metadata ) {
		// Data callback function (required)
		if ( isset( $config['data_callback'] ) && is_callable( $config['data_callback'] ) ) {
			$rows = call_user_func( $config['data_callback'], $data, $metadata );
			if ( is_array( $rows ) ) {
				return $rows;
			}
		}

		return array();
	}

	/**
	 * Generate CSV content from rows array.
	 *
	 * @since 9.0.0
	 *
	 * @param array $rows    Array of rows, each row is an associative array.
	 * @param array $columns Column names in order.
	 * @return string|false CSV content or false on failure.
	 */
	private function generate_csv_from_rows( $rows, $columns ) {
		if ( empty( $rows ) || ! is_array( $rows ) ) {
			return false;
		}

		if ( empty( $columns ) || ! is_array( $columns ) ) {
			return false;
		}

		// Start output buffering
		ob_start();

		// Add UTF-8 BOM for Excel compatibility
		echo "\xEF\xBB\xBF";

		// Open output stream
		$output = fopen( 'php://output', 'w' );

		// Output headers
		fputcsv( $output, $columns );

		// Output rows
		foreach ( $rows as $row ) {
			// Ensure row matches column structure
			$ordered_row = array();
			foreach ( $columns as $column ) {
				$ordered_row[] = isset( $row[ $column ] ) ? $row[ $column ] : '';
			}
			fputcsv( $output, $ordered_row );
		}

		// Close output stream
		fclose( $output );

		// Get output and clean buffer
		$csv = ob_get_clean();

		return $csv;
	}

	/**
	 * Register default multi-CSV report configurations.
	 */
	private function register_default_multi_csv_configs() {
		// Traffic Technology report configuration
		// Uses data_callback to prepare data arrays manually
		$this->register_multi_csv_config(
			'traffic_technology',
			array(
				'browser_breakdown' => array(
					'filename_prefix' => 'browser_breakdown',
					'columns'         => array( 'Browser', 'Percentage (%)' ),
					'data_callback'   => array( $this, 'prepare_browser_breakdown_data' ),
				),
				'device_breakdown' => array(
					'filename_prefix' => 'device_breakdown',
					'columns'         => array( 'Device', 'Percentage (%)' ),
					'data_callback'   => array( $this, 'prepare_device_breakdown_data' ),
				),
			)
		);

		$this->register_multi_csv_config(
			'overview',
			array(
				'overview_metrics' => array(
					'filename_prefix' => 'overview_metrics',
					'data_callback'   => array( $this, 'prepare_overview_metrics_data' ),
				),
				'marketing_campaigns' => array(
					'filename_prefix' => 'marketing_campaigns',
					'data_callback'   => array( $this, 'prepare_overview_marketing_campaigns_data' ),
				),
				'demographics' => array(
					'filename_prefix' => 'demographics',
					'data_callback'   => array( $this, 'prepare_overview_demographics_data' ),
				),
				'devices' => array(
					'filename_prefix' => 'devices',
					'data_callback'   => array( $this, 'prepare_overview_devices_data' ),
				),
				'ecommerce_log' => array(
					'filename_prefix' => 'ecommerce_log',
					'data_callback'   => array( $this, 'prepare_overview_ecommerce_log_data' ),
				),
				'ecommerce_data' => array(
					'filename_prefix' => 'ecommerce_data',
					'data_callback'   => array( $this, 'prepare_overview_ecommerce_data_data' ),
				),
			)
		);

		$this->register_multi_csv_config(
			'publisher',
			array(
				'top_landing_pages' => array(
					'filename_prefix' => 'top_landing_pages',
					'columns'         => array( 'Page Title', 'Visits', 'Avg. Duration', 'Bounce Rate' ),
					'data_callback'   => array( $this, 'prepare_publisher_top_landing_pages_data' ),
				),
				'top_outbound_links' => array(
					'filename_prefix' => 'top_outbound_links',
					'columns'         => array( 'Links', 'Clicks' ),
					'data_callback'   => array( $this, 'prepare_publisher_top_outbound_links_data' ),
				),
				'top_download_links' => array(
					'filename_prefix' => 'top_download_links',
					'columns'         => array( 'Link Label', 'Clicks' ),
					'data_callback'   => array( $this, 'prepare_publisher_top_download_links_data' ),
				),
			)
		);

		$this->register_multi_csv_config(
			'ecommerce',
			array(
				'infobox_stats' => array(
					'filename_prefix' => 'infobox_stats',
					'columns'         => array( 'Total Add to Carts', 'Total Removed from Cart', 'New Customers', 'Abandoned Checkouts', 'Conversion Rate', 'Transactions', 'Revenue', 'Avg. Order Value' ),
					'data_callback'   => array( $this, 'prepare_ecommerce_overview_infobox_stats_data' ),
				),
				'top_products' => array(
					'filename_prefix' => 'top_products',
					'columns'         => array( 'Product Name', 'Quantity', '% of Sales', 'Revenue' ),
					'data_callback'   => array( $this, 'prepare_ecommerce_overview_top_products_data' ),
				),
				'top_conversion_sources' => array(
					'filename_prefix' => 'top_conversion_sources',
					'columns'         => array( 'Sources', 'Total Users', '% of Users', 'Revenue' ),
					'data_callback'   => array( $this, 'prepare_ecommerce_overview_top_conversion_sources_data' ),
				),
			)
		);

		$this->register_multi_csv_config(
			'cart_abandonment',
			array(
				'cart_abandonment' => array(
					'filename_prefix' => 'cart_abandonment',
					'columns'         => array( 'Product', 'Quantity', 'Revenue', 'Cart Abandonment', 'Checkout Abandonment' ),
					'data_callback'   => array( $this, 'prepare_ecommerce_cart_abandonment_data' ),
				),
				'cart_abandonment_by_day' => array(
					'filename_prefix' => 'cart_abandonment_by_day',
					'columns'         => array( 'Date', 'Quantity', 'Revenue', 'Cart Abandonment', 'Checkout Abandonment' ),
					'data_callback'   => array( $this, 'prepare_ecommerce_cart_abandonment_by_day_data' ),
				),
			)
		);
	}

	/**
	 * Prepare browser breakdown data array for CSV export.
	 * @param array $data     Full report data.
	 * @param array $metadata Export metadata.
	 * @return array Array of rows, each row is an associative array matching columns.
	 */
	public function prepare_browser_breakdown_data( $data, $metadata ) {
		$rows = array();

		if ( ! isset( $data['browser_breakdown'] ) || ! is_array( $data['browser_breakdown'] ) ) {
			return $rows;
		}

		$breakdown = $data['browser_breakdown'];
		$labels = isset( $breakdown['labels'] ) && is_array( $breakdown['labels'] ) ? $breakdown['labels'] : array();
		$series = isset( $breakdown['series'] ) && is_array( $breakdown['series'] ) ? $breakdown['series'] : array();

		$max_count = count( $labels );

		for ( $i = 0; $i < $max_count; $i++ ) {
			$rows[] = array(
				'Browser'        => isset( $labels[ $i ] ) ? $labels[ $i ] : '',
				'Percentage (%)' => isset( $series[ $i ] ) ? $series[ $i ] : '',
			);
		}

		return $rows;
	}

	/**
	 * Prepare device breakdown data array for CSV export.
	 * @param array $data     Full report data.
	 * @param array $metadata Export metadata.
	 * @return array Array of rows, each row is an associative array matching columns.
	 */
	public function prepare_device_breakdown_data( $data, $metadata ) {
		$rows = array();

		if ( ! isset( $data['device_breakdown'] ) || ! is_array( $data['device_breakdown'] ) ) {
			return $rows;
		}

		$breakdown = $data['device_breakdown'];
		$labels = isset( $breakdown['labels'] ) && is_array( $breakdown['labels'] ) ? $breakdown['labels'] : array();
		$series = isset( $breakdown['series'] ) && is_array( $breakdown['series'] ) ? $breakdown['series'] : array();

		$max_count = count( $labels );

		for ( $i = 0; $i < $max_count; $i++ ) {
			$rows[] = array(
				'Device'         => isset( $labels[ $i ] ) ? $labels[ $i ] : '',
				'Percentage (%)' => isset( $series[ $i ] ) ? $series[ $i ] : '',
			);
		}

		return $rows;
	}

	/**
	 * Build multi-CSV configuration dynamically for dimensions report.
	 *
	 * The dimensions report has a dynamic number of CSV files based on
	 * the dimensions present in the data. Each dimension becomes a separate
	 * CSV file (for CSV export) or Excel sheet (for Excel export).
	 *
	 * @since 9.0.0
	 *
	 * @param array $data Report data containing 'dimensions' key.
	 * @return array Multi-CSV configuration array, or empty array if no dimensions found.
	 */
	private function build_dimensions_multi_csv_config( $data ) {
		$config = array();

		if ( ! isset( $data['dimensions'] ) || ! is_array( $data['dimensions'] ) ) {
			return $config;
		}

		foreach ( $data['dimensions'] as $dim_id => $dimension ) {
			$dim_key  = isset( $dimension['key'] ) ? $dimension['key'] : 'dimension_' . $dim_id;
			$dim_name = isset( $dimension['name'] ) ? $dimension['name'] : 'Dimension';

			// Each dimension becomes a separate CSV file / Excel sheet.
			// The closure captures $dim_id and $dim_name for the data callback.
			$config[ 'dimension_' . $dim_id ] = array(
				'filename_prefix' => $dim_key,
				'columns'         => array( $dim_name, 'Views' ),
				'data_callback'   => function( $cb_data, $cb_metadata ) use ( $dim_id, $dim_name ) {
					return $this->prepare_dimension_data( $cb_data, $dim_id, $dim_name );
				},
			);
		}

		return $config;
	}

	/**
	 * Prepare dimension data rows for CSV/Excel export.
	 *
	 * Extracts rows for a single dimension from the report data.
	 * Each row contains the dimension label and its pageview count.
	 *
	 * @since 9.0.0
	 *
	 * @param array      $data     Full report data.
	 * @param string|int $dim_id   Dimension ID (matches key in $data for actual values).
	 * @param string     $dim_name Dimension display name (used as the first column header).
	 * @return array Array of rows, each row is an associative array with dimension name and Views.
	 */
	public function prepare_dimension_data( $data, $dim_id, $dim_name ) {
		$rows = array();

		$dim_id = (string) $dim_id;

		if ( ! isset( $data[ $dim_id ] ) || ! isset( $data[ $dim_id ]['data'] ) || ! is_array( $data[ $dim_id ]['data'] ) ) {
			return $rows;
		}

		foreach ( $data[ $dim_id ]['data'] as $item ) {
			$rows[] = array(
				$dim_name => isset( $item['label'][0] ) ? $item['label'][0] : '',
				'Views'   => isset( $item['pageview'][0] ) ? $item['pageview'][0] : '',
			);
		}

		return $rows;
	}

	/**
	 * Register a multi-CSV configuration for a report type.
	 *
	 * @param string $report_type Report type identifier.
	 * @param array  $config      Configuration array. See $multi_csv_configs property for format.
	 * @return bool True on success, false on failure.
	 */
	public function register_multi_csv_config( $report_type, $config ) {
		if ( empty( $report_type ) || ! is_string( $report_type ) ) {
			return false;
		}

		if ( ! is_array( $config ) || empty( $config ) ) {
			return false;
		}

		$this->multi_csv_configs[ $report_type ] = $config;
		return true;
	}

	/**
	 * Get multi-CSV configuration for a report type.
	 *
	 * @param string $report_type Report type identifier.
	 * @return array|false Configuration array or false if not found.
	 */
	public function get_multi_csv_config( $report_type ) {
		if ( isset( $this->multi_csv_configs[ $report_type ] ) ) {
			return $this->multi_csv_configs[ $report_type ];
		}

		return false;
	}

	/**
	 * Format traffic campaign data for CSV export.
	 * @param array $data     Full report data.
	 * @return array Array of rows, each row is an associative array matching columns.
	 */
	private function format_traffic_campaign_for_csv( $data ) {
		$rows = array();

		if ( ! isset( $data['campaign_details_table'] ) || ! is_array( $data['campaign_details_table'] ) ) {
			return $rows;
		}

		foreach ( $data['campaign_details_table'] as $item ) {
			$rows[] = array(
				'Campaign'         => isset( $item['campaign'][0] ) ? $item['campaign'][0] : '',
				'Sessions'         => isset( $item['sessions'][0] ) ? $item['sessions'][0] : '',
				'Engaged Sessions' => isset( $item['engaged_sessions'][0] ) ? $item['engaged_sessions'][0] : '',
				'Pages / Sessions' => isset( $item['pages_per_sessions'][0] ) ? $item['pages_per_sessions'][0] : '',
				'Purchases'        => isset( $item['purchases'][0] ) ? $item['purchases'][0] : '',
				'Conversion Rate'  => isset( $item['conversion_rate'][0] ) ? $item['conversion_rate'][0] . '%' : '',
				'Revenue'          => isset( $item['revenue'][0] ) ? $item['revenue'][0] : '',
			);
		}

		return $rows;
	}

	/**
	 * Format traffic social data for CSV export.
	 * @param array $data     Full report data.
	 * @return array Array of rows, each row is an associative array matching columns.
	 */
	private function format_traffic_social_for_csv( $data ) {
		$rows = array();

		if ( ! isset( $data['social_table'] ) || ! is_array( $data['social_table'] ) ) {
			return $rows;
		}

		foreach ( $data['social_table'] as $item ) {
			$rows[] = array(
				'Network'          => isset( $item['network'][0] ) ? $item['network'][0] : '',
				'Sessions'         => isset( $item['sessions'][0] ) ? $item['sessions'][0] : '',
				'Engaged Sessions' => isset( $item['engaged_sessions'][0] ) ? $item['engaged_sessions'][0] : '',
				'Pages / Sessions' => isset( $item['pages_per_sessions'][0] ) ? $item['pages_per_sessions'][0] : '',
				'Purchases'        => isset( $item['purchases'][0] ) ? $item['purchases'][0] : '',
				'Conversion Rate'  => isset( $item['conversion_rate'][0] ) ? $item['conversion_rate'][0] . '%' : '',
				'Revenue'          => isset( $item['revenue'][0] ) ? $item['revenue'][0] : '',
			);
		}

		return $rows;
	}

	// ── Overview Export Data Callbacks ───────────────────────────────
	// These callbacks handle data from MonsterInsights_API_Export_Report.
	// The API returns data keyed by query ID with rows containing
	// d[] (dimensions) and m[][] (metrics) arrays.

	/**
	 * Extract rows from an API query response.
	 *
	 * Each row has d[] (dimension values) and m[][] (metric values).
	 * Column names are provided by the caller so the result is an array
	 * of associative arrays suitable for the CSV/Excel formatter.
	 *
	 * @param array    $data        Full API response data keyed by query ID.
	 * @param string   $query_id    The query ID to extract.
	 * @param string[] $dimensions  Dimension column names matching d[0], d[1]...
	 * @param string[] $metrics     Metric column names matching m[0][0], m[0][1]...
	 * @return array Array of associative rows.
	 */
	private function extract_api_rows( $data, $query_id, $dimensions, $metrics ) {
		if ( ! isset( $data[ $query_id ]['rows'] ) || ! is_array( $data[ $query_id ]['rows'] ) ) {
			return array();
		}

		$result = array();
		foreach ( $data[ $query_id ]['rows'] as $row ) {
			$assoc = array();

			$d = isset( $row['d'] ) ? $row['d'] : array();
			foreach ( $dimensions as $i => $name ) {
				$assoc[ $name ] = isset( $d[ $i ] ) ? $d[ $i ] : '';
			}

			$m = isset( $row['m'][0] ) ? $row['m'][0] : array();
			foreach ( $metrics as $i => $name ) {
				$assoc[ $name ] = isset( $m[ $i ] ) ? $m[ $i ] : '';
			}

			$result[] = $assoc;
		}

		return $result;
	}

	/**
	 * Merge rows from multiple API query IDs into a single dataset.
	 *
	 * Adds a 'Section' column to distinguish the source.
	 *
	 * @param array $data      Full API response data.
	 * @param array $query_map Array of { query_id, label, dimensions, metrics }.
	 * @return array Merged associative rows.
	 */
	/**
	 * Human-readable labels for GA4 metric keys used in export columns.
	 *
	 * @return array<string, string>
	 */
	private function get_metric_labels() {
		return array(
			'totalUsers'              => 'Total Users',
			'screenPageViews'         => 'Page Views',
			'sessions'                => 'Sessions',
			'bounceRate'              => 'Bounce Rate',
			'newUsers'                => 'New Users',
			'engagementRate'          => 'Engagement Rate',
			'averageSessionDuration'  => 'Avg. Session Duration',
			'ecommercePurchases'      => 'Purchases',
			'averagePurchaseRevenue'  => 'Avg. Order Value',
			'totalRevenue'            => 'Total Revenue',
			'addToCarts'              => 'Add to Carts',
			'engagedSessions'         => 'Engaged Sessions',
			'sessionKeyEventRate'     => 'Key Event Rate',
			'purchaseRevenue'         => 'Revenue',
			'itemRevenue'             => 'Revenue',
			'itemsPurchased'          => 'Quantity',
		);
	}

	private function merge_api_sections( $data, $query_map ) {
		$labels = $this->get_metric_labels();
		$merged = array();

		foreach ( $query_map as $entry ) {
			$rows    = $this->extract_api_rows( $data, $entry['query_id'], $entry['dimensions'], $entry['metrics'] );
			$dim_key = $entry['dimensions'][0];

			foreach ( $rows as $row ) {
				$dim_value = isset( $row[ $dim_key ] ) ? $row[ $dim_key ] : '';
				unset( $row[ $dim_key ] );

				$ordered = array(
					'Section' => $entry['label'],
					'Name'    => $dim_value,
				);

				foreach ( $row as $key => $val ) {
					if ( 'bounceRate' === $key || 'engagementRate' === $key ) {
						$val = round( (float) $val * 100, 1 ) . '%';
					} elseif ( 'totalRevenue' === $key ) {
						$val = number_format( (float) $val, 2, '.', '' );
					}
					$label             = isset( $labels[ $key ] ) ? $labels[ $key ] : $key;
					$ordered[ $label ] = $val;
				}

				$merged[] = $ordered;
			}
		}
		return $merged;
	}

	private function prepare_overview_metrics_data( $data, $metadata ) {
		$traffic_metrics = array(
			'totalUsers', 'screenPageViews', 'sessions', 'bounceRate',
			'newUsers', 'engagementRate', 'averageSessionDuration',
		);
		$ecommerce_metrics = array(
			'ecommercePurchases', 'averagePurchaseRevenue',
			'totalRevenue', 'addToCarts',
		);

		$traffic   = $this->extract_api_rows( $data, 'overview_traffic', array( 'date' ), $traffic_metrics );
		$ecommerce = $this->extract_api_rows( $data, 'overview_ecommerce', array( 'date' ), $ecommerce_metrics );

		// Merge ecommerce columns into traffic rows by date.
		$ecom_by_date = array();
		foreach ( $ecommerce as $row ) {
			if ( isset( $row['date'] ) ) {
				$ecom_by_date[ $row['date'] ] = $row;
			}
		}

		$all_rows = array();
		foreach ( $traffic as $row ) {
			$date = isset( $row['date'] ) ? $row['date'] : '';
			unset( $row['date'] );
			if ( isset( $ecom_by_date[ $date ] ) ) {
				$ecom_row = $ecom_by_date[ $date ];
				unset( $ecom_row['date'] );
				$row = array_merge( $row, $ecom_row );
			}
			$all_rows[] = $row;
		}

		if ( empty( $all_rows ) ) {
			return array();
		}

		// Session-weighted rates match Google Analytics and the frontend:
		// rate = sum(rate_i × sessions_i) / sum(sessions_i)
		$totals          = array();
		$total_sessions  = 0;
		$weighted_bounce = 0;
		$weighted_engage = 0;
		$weighted_dur    = 0;

		foreach ( $all_rows as $row ) {
			$sessions = (float) ( isset( $row['sessions'] ) ? $row['sessions'] : 0 );
			$total_sessions += $sessions;

			if ( isset( $row['bounceRate'] ) ) {
				$weighted_bounce += (float) $row['bounceRate'] * $sessions;
			}
			if ( isset( $row['engagementRate'] ) ) {
				$weighted_engage += (float) $row['engagementRate'] * $sessions;
			}
			if ( isset( $row['averageSessionDuration'] ) ) {
				$weighted_dur += (float) $row['averageSessionDuration'] * $sessions;
			}

			foreach ( $row as $key => $val ) {
				if ( ! isset( $totals[ $key ] ) ) {
					$totals[ $key ] = 0;
				}
				$totals[ $key ] += (float) $val;
			}
		}

		$raw = array();
		foreach ( $totals as $key => $val ) {
			if ( 'bounceRate' === $key ) {
				$rate        = $total_sessions > 0 ? $weighted_bounce / $total_sessions : 0;
				$raw[ $key ] = round( $rate * 100, 1 ) . '%';
			} elseif ( 'engagementRate' === $key ) {
				$rate        = $total_sessions > 0 ? $weighted_engage / $total_sessions : 0;
				$raw[ $key ] = round( $rate * 100, 1 ) . '%';
			} elseif ( 'averageSessionDuration' === $key ) {
				$avg_seconds = $total_sessions > 0 ? $weighted_dur / $total_sessions : 0;
				$hours       = floor( $avg_seconds / 3600 );
				$minutes     = floor( fmod( $avg_seconds, 3600 ) / 60 );
				$raw[ $key ] = sprintf( '%02d:%02d', $hours, $minutes );
			} else {
				$raw[ $key ] = round( $val );
			}
		}

		$labels  = $this->get_metric_labels();
		$summary = array();
		foreach ( $raw as $key => $val ) {
			$label             = isset( $labels[ $key ] ) ? $labels[ $key ] : $key;
			$summary[ $label ] = $val;
		}

		return array( $summary );
	}

	private function prepare_overview_marketing_campaigns_data( $data, $metadata ) {
		$metrics = array( 'sessions', 'totalUsers', 'engagementRate', 'totalRevenue', 'ecommercePurchases', 'averagePurchaseRevenue' );

		$rows = $this->merge_api_sections( $data, array(
			array( 'query_id' => 'marketing_campaigns_campaign', 'label' => 'Campaign', 'dimensions' => array( 'sessionCampaignName' ),   'metrics' => $metrics ),
			array( 'query_id' => 'marketing_campaigns_source',   'label' => 'Source',   'dimensions' => array( 'sessionSource' ),          'metrics' => $metrics ),
			array( 'query_id' => 'marketing_campaigns_medium',   'label' => 'Medium',   'dimensions' => array( 'sessionMedium' ),          'metrics' => $metrics ),
			array( 'query_id' => 'marketing_campaigns_term',     'label' => 'Term',     'dimensions' => array( 'sessionManualTerm' ),      'metrics' => $metrics ),
			array( 'query_id' => 'marketing_campaigns_content',  'label' => 'Content',  'dimensions' => array( 'sessionManualAdContent' ), 'metrics' => $metrics ),
		) );

		// Compute Conversion Rate and keep Avg. Order Value from label mapping.
		foreach ( $rows as &$row ) {
			$sessions  = isset( $row['Sessions'] ) ? (float) $row['Sessions'] : 0;
			$purchases = isset( $row['Purchases'] ) ? (float) $row['Purchases'] : 0;

			// Backward compatibility: if older key exists, map it once.
			if ( ! isset( $row['Avg. Order Value'] ) ) {
				$row['Avg. Order Value'] = isset( $row['Avg. Purchase Revenue'] ) ? $row['Avg. Purchase Revenue'] : 0;
			}
			$row['Conversion Rate']   = $sessions > 0 ? round( ( $purchases / $sessions ) * 100, 2 ) . '%' : '0%';

			unset( $row['Avg. Purchase Revenue'] );
		}
		unset( $row );

		return $rows;
	}

	private function prepare_overview_demographics_data( $data, $metadata ) {
		$metrics = array( 'sessions', 'totalRevenue', 'engagementRate' );

		return $this->merge_api_sections( $data, array(
			array( 'query_id' => 'demographics_country', 'label' => 'Country',        'dimensions' => array( 'country' ), 'metrics' => $metrics ),
			array( 'query_id' => 'demographics_state',   'label' => 'State / Region', 'dimensions' => array( 'region' ),  'metrics' => $metrics ),
		) );
	}

	private function prepare_overview_devices_data( $data, $metadata ) {
		$metrics = array( 'sessions', 'engagementRate', 'totalRevenue' );

		return $this->merge_api_sections( $data, array(
			array( 'query_id' => 'devices_browser', 'label' => 'Browser',          'dimensions' => array( 'browser' ),         'metrics' => $metrics ),
			array( 'query_id' => 'devices_os',      'label' => 'Operating System', 'dimensions' => array( 'operatingSystem' ), 'metrics' => $metrics ),
			array( 'query_id' => 'devices_size',    'label' => 'Device Category',  'dimensions' => array( 'deviceCategory' ),  'metrics' => $metrics ),
		) );
	}

	private function prepare_overview_ecommerce_log_data( $data, $metadata ) {
		$dimensions = array( 'dateHour', 'transactionId', 'sessionSource', 'sessionMedium', 'sessionCampaignName' );
		$metrics    = array( 'purchaseRevenue' );
		$rows       = $this->extract_api_rows( $data, 'ecommerce_log', $dimensions, $metrics );

		if ( empty( $rows ) ) {
			return array();
		}

		$labels     = $this->get_metric_labels();
		$dim_labels = array(
			'dateHour'              => 'Transaction Date',
			'transactionId'         => 'Transaction ID',
			'sessionSource'         => 'Transaction Source',
			'sessionMedium'         => 'Transaction Medium',
			'sessionCampaignName'   => 'Transaction Campaign',
		);

		$result = array();
		foreach ( $rows as $row ) {
			$formatted = array();
			foreach ( $dim_labels as $key => $label ) {
				$formatted[ $label ] = isset( $row[ $key ] ) ? $row[ $key ] : '';
			}
			foreach ( $metrics as $key ) {
				$label             = isset( $labels[ $key ] ) ? $labels[ $key ] : $key;
				$val               = isset( $row[ $key ] ) ? $row[ $key ] : 0;
				$formatted[ $label ] = number_format( (float) $val, 2, '.', '' );
			}
			$result[] = $formatted;
		}

		return $result;
	}

	private function prepare_overview_ecommerce_data_data( $data, $metadata ) {
		$dimensions = array( 'itemName', 'orderCoupon' );
		$metrics    = array( 'itemRevenue', 'itemsPurchased' );
		$rows       = $this->extract_api_rows( $data, 'ecommerce_data', $dimensions, $metrics );

		if ( empty( $rows ) ) {
			return array();
		}

		$total_revenue = 0;
		foreach ( $rows as $row ) {
			$total_revenue += (float) ( isset( $row['itemRevenue'] ) ? $row['itemRevenue'] : 0 );
		}

		$result = array();
		foreach ( $rows as $row ) {
			$revenue     = (float) ( isset( $row['itemRevenue'] ) ? $row['itemRevenue'] : 0 );
			$pct_of_sales = $total_revenue > 0 ? round( ( $revenue / $total_revenue ) * 100, 1 ) : 0;

			$result[] = array(
				'Product Name'  => isset( $row['itemName'] ) ? $row['itemName'] : '',
				'Coupon'        => isset( $row['orderCoupon'] ) ? $row['orderCoupon'] : '',
				'Revenue'       => number_format( $revenue, 2, '.', '' ),
				'Quantity'      => isset( $row['itemsPurchased'] ) ? (int) $row['itemsPurchased'] : 0,
				'% of Sales'    => $pct_of_sales . '%',
			);
		}

		return $result;
	}

	private function prepare_publisher_top_landing_pages_data( $data, $metadata ) {
		$rows = array();

		if ( ! isset( $data['landingpages'] ) || ! is_array( $data['landingpages'] ) ) {
			return $rows;
		}

		foreach ( $data['landingpages'] as $page ) {
			$rows[] = array(
				'Page Title' => isset( $page['title'] ) ? $page['title'][0] : '',
				'Visits' => isset( $page['visits'] ) ? $page['visits'][0] : '',
				'Avg. Duration' => isset( $page['duration'] ) ? $page['duration'][0] : '',
				'Bounce Rate' => isset( $page['bounce'] ) ? number_format( $page['bounce'][0], 2 ) . '%' : '',
			);
		}

		return $rows;
	}

	private function prepare_publisher_top_outbound_links_data( $data, $metadata ) {
		$rows = array();

		if ( ! isset( $data['outboundlinks'] ) || ! is_array( $data['outboundlinks'] ) ) {
			return $rows;
		}

		foreach ( $data['outboundlinks'] as $link ) {
			$rows[] = array(
				'Links' => isset( $link['title'] ) ? $link['title'][0] : '',
				'Clicks' => isset( $link['clicks'] ) ? $link['clicks'][0] : '',
			);
		}

		return $rows;
	}

	private function prepare_publisher_top_download_links_data( $data, $metadata ) {
		$rows = array();

		if ( ! isset( $data['downloadlinks'] ) || ! is_array( $data['downloadlinks'] ) ) {
			return $rows;
		}

		foreach ( $data['downloadlinks'] as $link ) {
			$rows[] = array(
				'Link Label' => isset( $link['title'] ) ? $link['title'][0] : '',
				'Clicks' => isset( $link['clicks'] ) ? $link['clicks'][0] : '',
			);
		}

		return $rows;
	}

	private function format_publishers_countries_for_csv( $data ) {
		$rows = array();

		if ( ! isset( $data['countries'] ) || ! is_array( $data['countries'] ) ) {
			return $rows;
		}

		foreach ( $data['countries'] as $item ) {
			$rows[] = array(
				'Country'          => isset( $item['name'] ) ? $item['name'] : '',
				'Sessions'         => isset( $item['sessions'][0] ) ? $item['sessions'][0] : '',
				'Engaged Sessions' => isset( $item['engagedSessions'][0] ) ? $item['engagedSessions'][0] : '',
				'Bounce rate'      => isset( $item['bounceRate'][0] ) ? $item['bounceRate'][0] : '',
				'Purchases'        => isset( $item['purchases'][0] ) ? $item['purchases'][0] : '',
				'Revenue'          => isset( $item['revenue'][0] ) ? $item['revenue'][0] : '',
				'Average Order Value'  => isset( $item['averageOrderValue'][0] ) ? $item['averageOrderValue'][0] : '',
			);
		}

		return $rows;
	}

	private function format_publishers_pages_for_csv( $data ) {
		$rows = array();

		if ( ! isset( $data['pages_report_table'] ) || ! is_array( $data['pages_report_table'] ) ) {
			return $rows;
		}

		foreach ( $data['pages_report_table'] as $item ) {
			$rows[] = array(
				'Page' => isset( $item['page_path'] ) ? $item['page_path'][0] : '',
				'Page Views' => isset( $item['pageviews'][0] ) ? $item['pageviews'][0] : '',
				'Engaged Sessions' => isset( $item['engaged_sessions'][0] ) ? $item['engaged_sessions'][0] : '',
				'New Sessions' => isset( $item['new_session'][0] ) ? $item['new_session'][0] : '',
				'Bounce Rate' => isset( $item['bounce_rate'][0] ) ? $item['bounce_rate'][0] . '%' : '',
			);
		}

		return $rows;
	}

	private function format_publishers_custom_events_for_csv( $data ) {
		$rows = array();

		if ( ! isset( $data['custom_events'] ) || ! is_array( $data['custom_events'] ) ) {
			return $rows;
		}

		foreach ( $data['custom_events'] as $item ) {
			$rows[] = array(
				'Event Name' => isset( $item['event_name'] ) ? $item['event_name'][0] : '',
				'Event Count' => isset( $item['event_count'] ) ? $item['event_count'][0] : '',
				'Sessions' => isset( $item['sessions'] ) ? $item['sessions'][0] : '',
				'Engaged Sessions' => isset( $item['engaged_sessions'] ) ? $item['engaged_sessions'][0] : '',
				'Key Event' => isset( $item['is_key_event'] ) ? $item['is_key_event'][0] : '',
			);
		}

		return $rows;
	}

	private function prepare_ecommerce_overview_infobox_stats_data( $data, $metadata ) {
		$rows = array();

		if ( ! isset( $data['infobox'] ) || ! is_array( $data['infobox'] ) ) {
			return $rows;
		}

		$infobox_stats = $data['infobox'];

		return array(
			array(
				'Total Add to Carts' => isset( $infobox_stats['addtocart'] ) ? $infobox_stats['addtocart']['value'] : '',
				'Total Removed from Cart' => isset( $infobox_stats['remfromcart'] ) ? $infobox_stats['remfromcart']['value'] : '',
				'New Customers' => isset( $infobox_stats['newcustomers'] ) ? $infobox_stats['newcustomers']['value'] : '',
				'Abandoned Checkouts' => isset( $infobox_stats['abandonedcheckouts'] ) ? $infobox_stats['abandonedcheckouts']['value'] : '',
				'Conversion Rate' => isset( $infobox_stats['conversionrate'] ) ? number_format( $infobox_stats['conversionrate']['value'], 2 ) . '%' : '',
				'Transactions' => isset( $infobox_stats['transactions'] ) ? $infobox_stats['transactions']['value'] : '',
				'Revenue' => isset( $infobox_stats['revenue'] ) ? $infobox_stats['revenue']['value'] : '',
				'Avg. Order Value' => isset( $infobox_stats['ordervalue'] ) ? number_format( $infobox_stats['ordervalue']['value'], 2 ) : '',
			)
		);
	}

	private function prepare_ecommerce_overview_top_products_data( $data, $metadata ) {
		$rows = array();

		if ( ! isset( $data['products'] ) || ! is_array( $data['products'] ) ) {
			return $rows;
		}

		foreach ( $data['products'] as $product ) {
			$rows[] = array(
				'Product Name' => isset( $product['name'] ) ? $product['name'][0] : '',
				'Quantity' => isset( $product['quantity'] ) ? $product['quantity'][0] : '',
				'% of Sales' => isset( $product['percent'] ) ? number_format( $product['percent'][0], 2 ) . '%' : '',
				'Revenue' => isset( $product['revenue'] ) ? $product['revenue'][0] : '',
			);
		}

		return $rows;
	}

	private function prepare_ecommerce_overview_top_conversion_sources_data( $data, $metadata ) {
		$rows = array();

		if ( ! isset( $data['conversions'] ) || ! is_array( $data['conversions'] ) ) {
			return $rows;
		}

		foreach ( $data['conversions'] as $conversion ) {
			$rows[] = array(
				'Sources' => isset( $conversion['url'] ) ? $conversion['url'][0] : '',
				'Total Users' => isset( $conversion['sessions'] ) ? $conversion['sessions'][0] : '',
				'% of Users' => isset( $conversion['percent'] ) ? number_format( $conversion['percent'][0], 2 ) . '%' : '',
				'Revenue' => isset( $conversion['revenue'] ) ? $conversion['revenue'][0] : '',
			);
		}

		return $rows;
	}

	private function prepare_ecommerce_cart_abandonment_data( $data, $metadata ) {
		$rows = array();

		if ( ! isset( $data['cart_abandonment'] ) || ! is_array( $data['cart_abandonment'] ) ) {
			return $rows;
		}

		foreach ( $data['cart_abandonment'] as $item ) {
			$rows[] = array(
				'Product' => isset( $item['name'] ) ? $item['name'] : '',
				'Quantity' => isset( $item['purchase_quantity'] ) ? $item['purchase_quantity'][0] : '',
				'Revenue' => isset( $item['revenue'] ) ? $item['revenue'][0] : '',
				'Cart Abandonment' => isset( $item['cart_abandonment'] ) ? number_format( $item['cart_abandonment'][0], 2 ) . '%' : '',
				'Checkout Abandonment' => isset( $item['checkout_abandonment'] ) ? number_format( $item['checkout_abandonment'][0], 2 ) . '%' : '',
			);
		}

		return $rows;
	}

	private function prepare_ecommerce_cart_abandonment_by_day_data( $data, $metadata ) {
		$rows = array();

		if ( ! isset( $data['cart_abandonment_by_day'] ) || ! is_array( $data['cart_abandonment_by_day'] ) ) {
			return $rows;
		}

		foreach ( $data['cart_abandonment_by_day'] as $item ) {
			$rows[] = array(
				'Date' => isset( $item['date'] ) ? $item['date'] : '',
				'Quantity' => isset( $item['purchase_quantity'] ) ? $item['purchase_quantity'][0] : '',
				'Revenue' => isset( $item['revenue'] ) ? $item['revenue'][0] : '',
				'Cart Abandonment' => isset( $item['cart_abandonment'] ) ? number_format( $item['cart_abandonment'][0], 2 ) . '%' : '',
				'Checkout Abandonment' => isset( $item['checkout_abandonment'] ) ? number_format( $item['checkout_abandonment'][0], 2 ) . '%' : '',
			);
		}

		return $rows;
	}

	private function format_ecommerce_coupons_for_csv( $data ) {
		$rows = array();

		if ( ! isset( $data['coupons_table'] ) || ! is_array( $data['coupons_table'] ) ) {
			return $rows;
		}

		foreach ( $data['coupons_table'] as $item ) {
			$rows[] = array(
				'Coupon Name' => isset( $item['coupon_name'] ) ? $item['coupon_name'][0] : '',
				'Revenue' => isset( $item['revenue'] ) ? $item['revenue'][0] : '',
				'Transactions' => isset( $item['transactions'] ) ? $item['transactions'][0] : '',
				'Average Order Value' => isset( $item['average_order_value'] ) ? $item['average_order_value'][0] : '',
			);
		}

		return $rows;
	}

	private function format_forms_for_csv( $data ) {
		$rows = array();

		if ( ! isset( $data['forms'] ) || ! is_array( $data['forms'] ) ) {
			return $rows;
		}

		foreach ( $data['forms'] as $item ) {
			$rows[] = array(
				'Form Name or ID' => isset( $item['id'] ) ? $item['id'][0] : '',
				'Impressions' => isset( $item['impressions'] ) ? $item['impressions'][0] : '',
				'Conversions' => isset( $item['conversions'] ) ? $item['conversions'][0] : '',
				'Conversion Rate' => isset( $item['conversionrate'] ) ? number_format( $item['conversionrate'][0], 2 ) . '%' : '',
			);
		}

		return $rows;
	}

	private function format_media_for_csv( $data ) {
		$rows = array();

		if ( ! isset( $data['video_details_rows'] ) || ! is_array( $data['video_details_rows'] ) ) {
			return $rows;
		}

		foreach ( $data['video_details_rows'] as $item ) {
			$rows[] = array(
				'Video Details' => isset( $item['title'] ) ? $item['title'][0] : '',
				'Video Plays' => isset( $item['video_start'] ) ? $item['video_start'][0] : '',
				'Avg. Watch Time' => isset( $item['average_watch_time'] ) ? $item['average_watch_time'][0] : '',
				'Avg. % Watched' => isset( $item['average_time_viewed'] ) ? $item['average_time_viewed'][0] : '',
				'Completion Rate' => isset( $item['completion_rate'] ) ? number_format( $item['completion_rate'][0], 2 ) . '%' : '',
			);
		}

		return $rows;
	}
}

/**
 * Get the Export Formatter instance.
 *
 * @since 9.0.0
 * @return MonsterInsights_Export_Formatter
 */
function monsterinsights_export_formatter() {
	return MonsterInsights_Export_Formatter::get_instance();
}
