<?php

defined( 'ABSPATH' ) || exit;

class MonsterInsights_Utm_Medium_Widget extends MonsterInsights_Abstract_Widget {

	public function __construct() {
		$this->type = 'utm-medium';
		$this->title = __( 'UTM Medium', 'google-analytics-premium' );
		$this->category = __( 'Traffic', 'google-analytics-premium' );
		$this->icon = 'dashicons-admin-links'; // WordPress Dashicon for links/mediums
		$this->support = array(
			'metrics' => array(
				'line-chart' => array( 'screenPageViews', 'engagedSessions' ), // User can choose which metric to display
				'bar-chart' => array( 'screenPageViews', 'engagedSessions' ),  // Multiple metrics as grouped bars
				'table' => array( 'screenPageViews', 'engagedSessions' ),      // Multiple metrics as columns
			),
			'dimensions' => array( 'sessionMedium' ),
			'types' => array( 'line-chart', 'bar-chart', 'table' ),
			'limit' => 6, // Number of mediums to display in charts
			'metricSelection' => array(
				'line-chart' => 'single',   // Radio buttons - only one metric at a time
				'bar-chart' => 'multiple',  // Checkboxes - multiple metrics as grouped bars
				'table' => 'multiple',      // Checkboxes - multiple metrics as columns
			),
			'compare' => array(
				'line-chart' => false,  // Line charts don't (too many lines with different mediums)
				'bar-chart' => false,   // Bar charts don't (too complex with multiple bars)
				'table' => true,        // Tables support comparison
			),
		);
		$this->defaults = array(
			'metrics' => array( 'screenPageViews' ), // Default to single metric for line-chart
			'dimensions' => array( 'sessionMedium' ),
			'type' => 'line-chart',
			'limit' => 6,
		);
	}

	/**
	 * Build API query for this widget based on configuration.
	 * For line charts, we need date dimension to show time series data with UTM medium as lines.
	 * For bar charts and tables, we only need the sessionMedium dimension.
	 *
	 * @param array $config Widget configuration from the frontend.
	 * @return array API query parameters.
	 */
	public function build_api_query( $config ) {
		$query = parent::build_api_query( $config );

		// Determine the display type to use
		// Priority: 1) User-selected displayType, 2) Widget default type
		$display_type = ! empty( $config['displayType'] ) ? $config['displayType'] : $this->defaults['type'];

		// For line charts, we need date + sessionMedium dimensions to show time series
		// X-axis: dates (30 days), Y-axis: metric values, Lines: one per UTM medium
		if ( 'line-chart' === $display_type ) {
			$query['dimensions'] = array( 'date', 'sessionMedium' );
		}
		// For bar charts and tables: X-axis/rows are mediums, Y-axis/columns are metrics

		return $query;
	}

	/**
	 * Process the API data for this widget.
	 *
	 * @param array $api_data The raw API response data.
	 * @param array $config   The widget configuration from the frontend.
	 * @return array The processed widget data.
	 */
	public function process_data( $api_data, $config ) {
		$api_data['report_galink'] = $this->get_ga_report_url( 'lifecycle-traffic-acquisition' );

		return $api_data;
	}
}
