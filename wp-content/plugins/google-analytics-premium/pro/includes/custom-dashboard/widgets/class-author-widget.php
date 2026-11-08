<?php

defined( 'ABSPATH' ) || exit;

class MonsterInsights_Author_Widget extends MonsterInsights_Abstract_Widget {

	public function __construct() {
		$this->type = 'author';
		$this->title = __( 'Author', 'google-analytics-premium' );
		$this->category = __( 'Custom Dimensions', 'google-analytics-premium' );
		$this->icon = 'dashicons-admin-users'; // WordPress Dashicon for authors/users
		$this->support = array(
			'metrics' => array(
				'line-chart' => array( 'screenPageViews', 'sessions', 'activeUsers', 'engagedSessions' ),
				'bar-chart' => array( 'screenPageViews', 'sessions', 'activeUsers', 'engagedSessions' ),
				'table' => array( 'screenPageViews', 'sessions', 'activeUsers', 'engagedSessions', 'engagementRate' ),
			),
			'dimensions' => array( 'customEvent:author' ),
			'types' => array( 'line-chart', 'bar-chart', 'table' ),
			'limit' => 10, // Number of authors to display
			'metricSelection' => array(
				'line-chart' => 'single',   // Line charts show one metric with authors as separate lines
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
			'metrics' => array( 'screenPageViews' ), // Default to single metric for line-chart
			'dimensions' => array( 'customEvent:author' ),
			'type' => 'line-chart',
			'limit' => 10,
		);
	}

	/**
	 * Build API query for this widget based on configuration.
	 * For line charts, we need date dimension to show time series data with authors as lines.
	 * For bar charts and tables, we only need the author dimension.
	 *
	 * @param array $config Widget configuration from the frontend.
	 * @return array API query parameters.
	 */
	public function build_api_query( $config ) {
		$query = parent::build_api_query( $config );

		// Determine the display type to use
		// Priority: 1) User-selected displayType, 2) Widget default type
		$display_type = ! empty( $config['displayType'] ) ? $config['displayType'] : $this->defaults['type'];

		// For line charts, we need date + author dimensions to show time series
		// X-axis: dates (30 days), Y-axis: metric values, Lines: one per author
		if ( 'line-chart' === $display_type ) {
			$query['dimensions'] = array( 'date', 'customEvent:author' );
		}
		// For bar charts and tables: X-axis/rows are authors, Y-axis/columns are metrics

		return $query;
	}
}