<?php

defined( 'ABSPATH' ) || exit;

class MonsterInsights_Organic_Search_Widget extends MonsterInsights_Abstract_Widget {

	public function __construct() {
		$this->type = 'organic-search';
		$this->title = __( 'Organic Search', 'google-analytics-premium' );
		$this->category = __( 'Traffic Source', 'google-analytics-premium' );
		$this->icon = 'dashicons-search'; // WordPress Dashicon for search
		$this->support = array(
			'metrics' => array(
				'line-chart' => array( 'sessions', 'screenPageViews', 'engagedSessions', 'activeUsers' ),
				'bar-chart' => array( 'sessions', 'screenPageViews', 'engagedSessions', 'activeUsers' ),
				'table' => array( 'sessions', 'screenPageViews', 'engagedSessions', 'activeUsers', 'newUsers', 'engagementRate' ),
			),
			'dimensions' => array( 'landingPage' ),
			'types' => array( 'line-chart', 'bar-chart', 'table' ),
			'limit' => 10, // Number of landing pages to display
			'metricSelection' => array(
				'line-chart' => 'single',   // Line charts show one metric with landing pages as separate lines
				'bar-chart' => 'multiple',  // Bar charts can show multiple metrics as grouped bars
				'table' => 'multiple',      // Tables can show multiple metric columns
			),
			'compare' => array(
				'line-chart' => false,  // Line charts don't support comparison (too many lines)
				'bar-chart' => false,   // Bar charts don't support comparison
				'table' => true,        // Tables support comparison
			),
		);
		$this->defaults = array(
			'metrics' => array( 'sessions' ), // Default to single metric for line-chart
			'dimensions' => array( 'landingPage' ),
			'type' => 'line-chart',
			'limit' => 10,
		);
	}

	/**
	 * Build API query for this widget based on configuration.
	 * For line charts, we need date + landingPage dimensions to show time series data with pages as lines.
	 * For bar charts and tables, we only need the landingPage dimension.
	 *
	 * @param array $config Widget configuration from the frontend.
	 * @return array API query parameters.
	 */
	public function build_api_query( $config ) {
		$query = parent::build_api_query( $config );

		// Determine the display type to use
		// Priority: 1) User-selected displayType, 2) Widget default type
		$display_type = ! empty( $config['displayType'] ) ? $config['displayType'] : $this->defaults['type'];

		// For line charts, we need date + landingPage dimensions to show time series
		// X-axis: dates (30 days), Y-axis: metric values, Lines: one per landing page
		if ( 'line-chart' === $display_type ) {
			$query['dimensions'] = array( 'date', 'landingPage' );
		}
		// For bar charts and tables: X-axis/rows are landing pages, Y-axis/columns are metrics

		return $query;
	}
}