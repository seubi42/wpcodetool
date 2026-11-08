<?php

defined( 'ABSPATH' ) || exit;

class MonsterInsights_Utm_Source_Widget extends MonsterInsights_Abstract_Widget {

	public function __construct() {
		$this->type = 'utm-source';
		$this->title = __( 'UTM Source', 'google-analytics-premium' );
		$this->category = __( 'Traffic', 'google-analytics-premium' );
		$this->icon = 'dashicons-share'; // WordPress Dashicon for sources
		$this->support = array(
			'metrics' => array(
				'line-chart' => array( 'sessions', 'screenPageViews', 'engagedSessions', 'activeUsers', 'newUsers' ), // Single metric selection
				'bar-chart' => array( 'sessions', 'screenPageViews', 'engagedSessions', 'activeUsers', 'newUsers' ),  // Multiple metrics as bars
				'table' => array(
					'sessions',
					'activeUsers',
					'screenPageViews',
					'engagementRate',
					'engagedSessions',
					'conversions',
					'conversionRate',
					'totalRevenue',
					'bounceRate',
					'averageSessionDuration',
					'newUsers',
					'transactions'
				), // All metrics available for table columns
			),
			'dimensions' => array( 'sessionSource' ),
			'types' => array( 'line-chart', 'bar-chart', 'table' ),
			'limit' => 10, // Number of sources to display
			'metricSelection' => array(
				'line-chart' => 'single',   // Radio buttons - only one metric at a time
				'bar-chart' => 'multiple',  // Checkboxes - multiple metrics as grouped bars
				'table' => 'multiple',      // Checkboxes - multiple metrics as columns
			),
			'compare' => array(
				'line-chart' => false,  // Line charts don't support comparison (too many lines)
				'bar-chart' => false,   // Bar charts don't support comparison (too complex)
				'table' => true,        // Tables support comparison
			),
		);
		$this->defaults = array(
			'metrics' => array( 'sessions' ), // Default to single metric
			'dimensions' => array( 'sessionSource' ),
			'type' => 'line-chart',
			'limit' => 10,
		);
	}

	/**
	 * Build API query for this widget based on configuration.
	 * For line charts, we need date dimension to show time series data with UTM source as lines.
	 * For bar charts and tables, we only need the sessionSource dimension.
	 *
	 * @param array $config Widget configuration from the frontend.
	 * @return array API query parameters.
	 */
	public function build_api_query( $config ) {
		$query = parent::build_api_query( $config );

		// For line charts, we need date + sessionSource dimensions to show time series
		// X-axis: dates (30 days), Y-axis: metric values, Lines: one per UTM source
		// Determine the display type to use
		// Priority: 1) User-selected displayType, 2) Widget default type
		$display_type = ! empty( $config['displayType'] ) ? $config['displayType'] : $this->defaults['type'];

		if ( 'line-chart' === $display_type ) {
			$query['dimensions'] = array( 'date', 'sessionSource' );
		}

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