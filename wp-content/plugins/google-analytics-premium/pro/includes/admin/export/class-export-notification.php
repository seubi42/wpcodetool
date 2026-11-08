<?php
/**
 * Export Notification Class
 *
 * Handles notification generation for export completion and failure events.
 * Creates notifications that appear in the MonsterInsights notification center
 * with actionable buttons (Download, Retry).
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
 * MonsterInsights_Export_Notification_Complete Class
 *
 * Notification for successful export completion with download link.
 *
 * @since 9.0.0
 */
final class MonsterInsights_Export_Notification_Complete extends MonsterInsights_Notification_Event {

	/**
	 * Notification ID prefix.
	 *
	 * @since 9.0.0
	 * @var string
	 */
	public $notification_id = 'monsterinsights_export_complete';

	/**
	 * Notification interval (set to 0 for one-time notifications).
	 *
	 * @since 9.0.0
	 * @var int
	 */
	public $notification_interval = 0;

	/**
	 * License types that can see this notification.
	 *
	 * @since 9.0.0
	 * @var array
	 */
	public $notification_type = array( 'plus', 'pro', 'master' );

	/**
	 * Notification category.
	 *
	 * @since 9.0.0
	 * @var string
	 */
	public $notification_category = 'alert';

	/**
	 * Notification icon.
	 *
	 * @since 9.0.0
	 * @var string
	 */
	public $notification_icon = 'checkmark';

	/**
	 * Notification priority (1 = high, 2 = medium, 3 = low).
	 *
	 * @since 9.0.0
	 * @var int
	 */
	public $notification_priority = 1;

	/**
	 * Export ID for this notification.
	 *
	 * @since 9.0.0
	 * @var int
	 */
	private $export_id;

	/**
	 * Export data.
	 *
	 * @since 9.0.0
	 * @var object
	 */
	private $export_data;

	/**
	 * Constructor.
	 *
	 * @since 9.0.0
	 *
	 * @param int   $export_id Export ID.
	 * @param array $result    Export processing result.
	 */
	public function __construct( $export_id, $result = array() ) {
		$this->export_id = $export_id;

		// Get export data
		$this->export_data = monsterinsights_export_manager()->get_export( $export_id );

		if ( ! $this->export_data ) {
			return;
		}

		// Override notification ID to make it unique per export
		$this->notification_id = 'monsterinsights_export_complete_' . $export_id;
		$this->notification_active_for = date( 'm/d/Y', strtotime( '+7 days' ) ); // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date

		// Call parent constructor
		parent::__construct();
	}

	/**
	 * Build notification data.
	 *
	 * @since 9.0.0
	 *
	 * @param array $notification Notification array.
	 * @return array|false Prepared notification or false if invalid.
	 */
	public function prepare_notification_data( $notification ) {
		if ( ! $this->export_data || 'complete' !== $this->export_data->status ) {
			return false;
		}

		// Get report title
		$report_title = monsterinsights_get_export_report_display_name( $this->export_data->report_type );

		// Build notification title
		$notification['title'] = sprintf(
			// Translators: %s is the report name
			__( 'Your %s Export is Ready!', 'google-analytics-premium' ),
			$report_title
		);

		// Build notification content
		$date_range = isset( $this->export_data->date_range_display ) ? $this->export_data->date_range_display : '';
		$format     = strtoupper( $this->export_data->export_format );
		$file_size  = $this->format_file_size( $this->export_data->file_size );

		$notification['content'] = sprintf(
			// Translators: 1: Report name, 2: Date range, 3: Format, 4: File size
			__( 'Your %1$s report (%2$s) has been successfully exported to %3$s format (%4$s). Click below to download your file. This export will be available for 7 days.', 'google-analytics-premium' ),
			$report_title,
			$date_range,
			$format,
			$file_size
		);

		// Build download URL
		$download_url = monsterinsights_export_manager()->get_download_url( $this->export_id );

		// Add action buttons
		$notification['btns'] = array(
			'download' => array(
				'url'  => $download_url,
				'text' => __( 'Download Export', 'google-analytics-premium' ),
			),
			'view_exports' => array(
				'url'  => admin_url( 'admin.php?page=monsterinsights_settings#/tools/report-export' ),
				'text' => __( 'View All Exports', 'google-analytics-premium' ),
			),
		);

		return $notification;
	}

	/**
	 * Format file size for display.
	 *
	 * @since 9.0.0
	 *
	 * @param int $bytes File size in bytes.
	 * @return string Formatted file size.
	 */
	private function format_file_size( $bytes ) {
		if ( ! $bytes || $bytes < 1 ) {
			return __( 'Unknown size', 'google-analytics-premium' );
		}

		$units = array( 'B', 'KB', 'MB', 'GB' );
		$bytes = max( $bytes, 0 );
		$pow   = floor( ( $bytes ? log( $bytes ) : 0 ) / log( 1024 ) );
		$pow   = min( $pow, count( $units ) - 1 );

		$bytes /= pow( 1024, $pow );

		return round( $bytes, 2 ) . ' ' . $units[ $pow ];
	}
}

/**
 * MonsterInsights_Export_Notification_Failed Class
 *
 * Notification for failed exports with retry button.
 *
 * @since 9.0.0
 */
final class MonsterInsights_Export_Notification_Failed extends MonsterInsights_Notification_Event {

	/**
	 * Notification ID prefix.
	 *
	 * @since 9.0.0
	 * @var string
	 */
	public $notification_id = 'monsterinsights_export_failed';

	/**
	 * Notification interval (set to 0 for one-time notifications).
	 *
	 * @since 9.0.0
	 * @var int
	 */
	public $notification_interval = 1;

	/**
	 * License types that can see this notification.
	 *
	 * @since 9.0.0
	 * @var array
	 */
	public $notification_type = array( 'plus', 'pro', 'master' );

	/**
	 * Notification category.
	 *
	 * @since 9.0.0
	 * @var string
	 */
	public $notification_category = 'alert';

	/**
	 * Notification icon.
	 *
	 * @since 9.0.0
	 * @var string
	 */
	public $notification_icon = 'warning';

	/**
	 * Notification priority (1 = high, 2 = medium, 3 = low).
	 *
	 * @since 9.0.0
	 * @var int
	 */
	public $notification_priority = 1;

	/**
	 * Export ID for this notification.
	 *
	 * @since 9.0.0
	 * @var int
	 */
	private $export_id;

	/**
	 * Export data.
	 *
	 * @since 9.0.0
	 * @var object
	 */
	private $export_data;

	/**
	 * Error message.
	 *
	 * @since 9.0.0
	 * @var string
	 */
	private $error_message;

	/**
	 * Constructor.
	 *
	 * @since 9.0.0
	 *
	 * @param int    $export_id     Export ID.
	 * @param string $error_message Error message.
	 */
	public function __construct( $export_id, $error_message = '' ) {
		$this->export_id     = $export_id;
		$this->error_message = $error_message;

		// Get export data
		$this->export_data = monsterinsights_export_manager()->get_export( $export_id );

		if ( ! $this->export_data ) {
			return;
		}

		// Override notification ID to make it unique per export
		$this->notification_id = 'monsterinsights_export_failed_' . $export_id;

		// Call parent constructor
		parent::__construct();
	}

	/**
	 * Build notification data.
	 *
	 * @since 9.0.0
	 *
	 * @param array $notification Notification array.
	 * @return array|false Prepared notification or false if invalid.
	 */
	public function prepare_notification_data( $notification ) {
		if ( ! $this->export_data || 'failed' !== $this->export_data->status ) {
			return false;
		}

		// Get report title
		$report_title = $this->get_report_title( $this->export_data->report_type );

		// Build notification title
		$notification['title'] = sprintf(
			// Translators: %s is the report name
			__( 'Export Failed: %s', 'google-analytics-premium' ),
			$report_title
		);

		// Build notification content with user-friendly error
		$date_range = isset( $this->export_data->date_range_display ) ? $this->export_data->date_range_display : '';
		$error_info = $this->get_user_friendly_error( $this->error_message );

		$notification['content'] = sprintf(
			// Translators: 1: Report name, 2: Date range, 3: Error message
			__( 'Your %1$s report export (%2$s) failed to complete. %3$s', 'google-analytics-premium' ),
			$report_title,
			$date_range,
			$error_info['message']
		);

		// Add help text if available
		if ( ! empty( $error_info['help_text'] ) ) {
			$notification['content'] .= ' ' . $error_info['help_text'];
		}

		// Add action buttons
		$notification['btns'] = array(
			'retry' => array(
				'url'  => add_query_arg(
					array(
						'page'               => 'monsterinsights_tools',
						'retry_export'       => $this->export_id,
						'nonce'              => wp_create_nonce( 'monsterinsights_retry_export_' . $this->export_id ),
					),
					admin_url( 'admin.php' )
				) . '#/export',
				'text' => __( 'Try Again', 'google-analytics-premium' ),
			),
		);

		// Add support link if it's a critical error
		if ( ! empty( $error_info['show_support_link'] ) ) {
			$notification['btns']['support'] = array(
				'url'  => $this->build_external_link( 'https://www.monsterinsights.com/contact/' ),
				'text' => __( 'Contact Support', 'google-analytics-premium' ),
			);
		}

		return $notification;
	}

	/**
	 * Get human-readable report title.
	 *
	 * @since 9.0.0
	 *
	 * @param string $report_type Report type identifier.
	 * @return string Report title.
	 */
	private function get_report_title( $report_type ) {
		$titles = array(
			'overview'   => __( 'Overview', 'google-analytics-premium' ),
			'ecommerce'  => __( 'eCommerce', 'google-analytics-premium' ),
			'publisher'  => __( 'Publisher', 'google-analytics-premium' ),
			'queries'    => __( 'Search Console', 'google-analytics-premium' ),
			'dimensions' => __( 'Dimensions', 'google-analytics-premium' ),
			'forms'      => __( 'Forms', 'google-analytics-premium' ),
			'realtime'   => __( 'Real-Time', 'google-analytics-premium' ),
		);

		return isset( $titles[ $report_type ] ) ? $titles[ $report_type ] : ucfirst( $report_type );
	}

	/**
	 * Convert technical error messages to user-friendly ones.
	 *
	 * @since 9.0.0
	 *
	 * @param string $error_message Technical error message.
	 * @return array {
	 *     Error information.
	 *
	 *     @type string $message           User-friendly error message.
	 *     @type string $help_text         Optional help text.
	 *     @type bool   $show_support_link Whether to show support link.
	 * }
	 */
	private function get_user_friendly_error( $error_message ) {
		$error_info = array(
			'message'           => '',
			'help_text'         => '',
			'show_support_link' => false,
		);

		// Match error patterns and provide user-friendly messages
		if ( empty( $error_message ) ) {
			$error_info['message'] = __( 'An unknown error occurred.', 'google-analytics-premium' );
			$error_info['help_text'] = __( 'Please try again or contact support if the problem persists.', 'google-analytics-premium' );
			$error_info['show_support_link'] = true;
		} elseif ( strpos( $error_message, 'No data available' ) !== false ) {
			$error_info['message'] = __( 'No data was found for the selected date range.', 'google-analytics-premium' );
			$error_info['help_text'] = __( 'Try selecting a different date range with available data.', 'google-analytics-premium' );
		} elseif ( strpos( $error_message, 'API' ) !== false || strpos( $error_message, 'api_error' ) !== false ) {
			$error_info['message'] = __( 'There was a problem connecting to Google Analytics.', 'google-analytics-premium' );
			$error_info['help_text'] = __( 'Please check your connection and try again.', 'google-analytics-premium' );
		} elseif ( strpos( $error_message, 'Failed to create' ) !== false || strpos( $error_message, 'directory' ) !== false ) {
			$error_info['message'] = __( 'There was a problem creating the export file.', 'google-analytics-premium' );
			$error_info['help_text'] = __( 'This may be a file permissions issue. Please contact your hosting provider.', 'google-analytics-premium' );
			$error_info['show_support_link'] = true;
		} elseif ( strpos( $error_message, 'ZIP' ) !== false || strpos( $error_message, 'zip' ) !== false ) {
			$error_info['message'] = __( 'There was a problem compressing the export file.', 'google-analytics-premium' );
			$error_info['help_text'] = __( 'Your server may not have the ZIP extension enabled. Please contact your hosting provider.', 'google-analytics-premium' );
			$error_info['show_support_link'] = true;
		} elseif ( strpos( $error_message, 'memory' ) !== false || strpos( $error_message, 'Memory' ) !== false ) {
			$error_info['message'] = __( 'The export exceeded available memory.', 'google-analytics-premium' );
			$error_info['help_text'] = __( 'Try exporting a smaller date range or contact your hosting provider to increase memory limits.', 'google-analytics-premium' );
		} elseif ( strpos( $error_message, 'timeout' ) !== false || strpos( $error_message, 'time limit' ) !== false ) {
			$error_info['message'] = __( 'The export took too long to complete.', 'google-analytics-premium' );
			$error_info['help_text'] = __( 'Try exporting a smaller date range.', 'google-analytics-premium' );
		} elseif ( strpos( $error_message, 'Invalid' ) !== false || strpos( $error_message, 'invalid' ) !== false ) {
			$error_info['message'] = __( 'The export configuration was invalid.', 'google-analytics-premium' );
			$error_info['help_text'] = __( 'Please check your settings and try again.', 'google-analytics-premium' );
		} else {
			// Generic error with sanitized technical message
			$error_info['message'] = __( 'The export could not be completed.', 'google-analytics-premium' );
			$error_info['help_text'] = sprintf(
				// Translators: %s is the error message
				__( 'Error details: %s', 'google-analytics-premium' ),
				esc_html( $error_message )
			);
			$error_info['show_support_link'] = true;
		}

		return $error_info;
	}
}

/**
 * Hook notifications into export events.
 *
 * @since 9.0.0
 */
function monsterinsights_export_init_notifications() {
	// Hook into export completed action
	add_action( 'monsterinsights_export_completed', 'monsterinsights_export_notification_completed', 10, 2 );

	// Hook into export failed action
	add_action( 'monsterinsights_export_failed', 'monsterinsights_export_notification_failed', 10, 2 );
}
add_action( 'init', 'monsterinsights_export_init_notifications' );

/**
 * Create notification when export completes successfully.
 *
 * @since 9.0.0
 *
 * @param int   $export_id Export ID.
 * @param array $result    Export processing result.
 */
function monsterinsights_export_notification_completed( $export_id, $result ) {
	// Create and trigger notification
	$notification = new MonsterInsights_Export_Notification_Complete( $export_id, $result );
	$notification->add_notification();
}

/**
 * Create notification when export fails.
 *
 * @since 9.0.0
 *
 * @param int    $export_id     Export ID.
 * @param string $error_message Error message.
 */
function monsterinsights_export_notification_failed( $export_id, $error_message ) {
	// Create and trigger notification
	$notification = new MonsterInsights_Export_Notification_Failed( $export_id, $error_message );
	$notification->add_notification();
}
