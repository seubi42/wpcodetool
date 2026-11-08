<?php
/**
 * Site Notes Widget - Local Data Example
 *
 * This widget demonstrates how to create a simple local data widget that:
 * - Uses local WordPress data instead of GA4 API
 * - Returns data directly via process_data() method
 * - Displays data using DataTableWidget component
 *
 * Note: Site notes are managed elsewhere (e.g., a dedicated settings page).
 * This widget only displays them in the dashboard.
 *
 * @package MonsterInsights
 * @subpackage Custom_Dashboard
 */

defined( 'ABSPATH' ) || exit;

class MonsterInsights_Site_Notes_Widget extends MonsterInsights_Abstract_Widget {

	/**
	 * Database instance for site notes.
	 */
	private $db;

	/**
	 * Constructor - Define widget properties and configuration.
	 */
	public function __construct() {
		$this->type = 'site-notes';
		$this->title = __( 'Site Notes', 'google-analytics-premium' );
		$this->category = __( 'Custom Dimensions', 'google-analytics-premium' );
		$this->icon = 'dashicons-edit-page'; // WordPress Dashicon for notes/editing

		// Mark this as a local data widget (doesn't use GA4 API)
		$this->is_local = true;

		$this->support = array(
			// Local widgets don't need metrics or dimensions
			'metrics' => array(),
			'dimensions' => array(),
			// Only supports table display
			'types' => array( 'table' ),
			'compare' => false,
		);

		$this->defaults = array(
			'type' => 'table',
			'title' => __( 'Site Notes', 'google-analytics-premium' ),
		);

		// Initialize database instance
		$this->db = new MonsterInsights_Site_Notes_DB_Base();
	}

	/**
	 * Process local data for this widget.
	 * This method is called instead of making a GA4 API request.
	 *
	 * Since is_local = true, the dashboard will NOT make an API request.
	 * This method returns the data directly to the widget component.
	 *
	 * @param array $api_data Not used for local widgets (will be empty).
	 * @param array $config Widget configuration from frontend, including date_range.
	 * @return array Widget data to be displayed.
	 */
	public function process_data( $api_data, $config ) {
		// Get date range from config (added by AJAX handler)
		$date_range = isset( $config['date_range'] ) ? $config['date_range'] : array();
		$start_date = isset( $date_range['start'] ) ? $date_range['start'] : '';
		$end_date = isset( $date_range['end'] ) ? $date_range['end'] : '';

		// Get notes using the database class
		$args = array(
			'per_page' => 100, // Show up to 100 notes
			'page'     => 1,
			'orderby'  => 'date',
			'order'    => 'DESC',
		);

		// Add date range filter if provided (nested structure required by DB class)
		if ( ! empty( $start_date ) && ! empty( $end_date ) ) {
			$args['filter']['date_range']['start'] = $start_date;
			$args['filter']['date_range']['end'] = $end_date;
		}

		$result = $this->db->get_items( $args );
		$notes = isset( $result['items'] ) ? $result['items'] : array();

		// var_dump($result);

		// Format notes for table display in GA4 API format
		// DataTableWidget expects: { d: [...dimensions], m: [[...metrics]] }
		$formatted_notes = array();
		foreach ( $notes as $note ) {
			$date = isset( $note['note_date'] ) ? $note['note_date'] : '';
			$note_text = isset( $note['note_title'] ) ? $note['note_title'] : '';
			$important = isset( $note['important'] ) ? (bool) $note['important'] : false;

			// Prepend star icon to date if important, nothing for non-important
			$date_display = $important ? '⭐ ' . $date : $date;

			$formatted_notes[] = array(
				'd' => array( $date_display ), // Dimensions array with star
				'm' => array( array( $note_text ) ), // Metrics array (nested)
			);
		}

		return array(
			'rows' => $formatted_notes,
			'total' => count( $formatted_notes ),
			'headers' => array(
				array( 'key' => 'date', 'label' => __( 'Date', 'google-analytics-premium' ) ),
				array( 'key' => 'note', 'label' => __( 'Site Note', 'google-analytics-premium' ) ),
			),
		);
	}
}
