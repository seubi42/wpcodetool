<?php

defined( 'ABSPATH' ) || exit;

class MonsterInsights_User_Login_Widget extends MonsterInsights_Abstract_Widget {

	public function __construct() {
		$this->type = 'user-login';
		$this->title = __( 'User Login', 'google-analytics-premium' );
		$this->category = __( 'Custom Dimensions', 'google-analytics-premium' );
		$this->icon = 'dashicons-admin-users'; // WordPress Dashicon for users
		$this->support = array(
			'metrics' => array(
				'line-chart' => array( 'sessions', 'screenPageViews', 'engagedSessions', 'activeUsers' ),
				'bar-chart' => array( 'sessions', 'screenPageViews', 'engagedSessions', 'activeUsers' ),
				'pie-chart' => array( 'sessions' ),
				'table' => array( 'sessions', 'screenPageViews', 'engagedSessions', 'activeUsers', 'newUsers', 'engagementRate' ),
			),
			'dimensions' => array( 'customEvent:logged_in' ),
			'types' => array( 'pie-chart', 'line-chart', 'bar-chart', 'table' ),
			'metricSelection' => array(
				'line-chart' => 'single',   // Line charts show one metric with login status as separate lines
				'bar-chart' => 'multiple',  // Bar charts can show multiple metrics as grouped bars
				'pie-chart' => 'single',    // Pie charts show one metric split by login status
				'table' => 'multiple',      // Tables can show multiple metric columns
			),
			'compare' => array(
				'line-chart' => false,  // Line charts don't support comparison
				'bar-chart' => false,   // Bar charts don't support comparison
				'pie-chart' => false,   // Pie charts don't support comparison
				'table' => true,        // Tables support comparison
			),
		);
		$this->defaults = array(
			'metrics' => array( 'sessions' ),
			'dimensions' => array( 'customEvent:logged_in' ),
			'type' => 'pie-chart',
		);
	}

	/**
	 * Build API query for this widget based on configuration.
	 * For line charts, we need date + logged_in dimensions to show time series data with login status as lines.
	 * For pie charts, we exclude "(not set)" to show only logged-in vs logged-out split.
	 * For other chart types, we keep "(not set)" to show the discrepancy.
	 *
	 * @param array $config Widget configuration from the frontend.
	 * @return array API query parameters.
	 */
	public function build_api_query( $config ) {
		$query = parent::build_api_query( $config );

		// Determine the display type to use
		// Priority: 1) User-selected displayType, 2) Widget default type
		$display_type = ! empty( $config['displayType'] ) ? $config['displayType'] : $this->defaults['type'];

		// For line charts, we need date + logged_in dimensions to show time series
		// X-axis: dates (30 days), Y-axis: metric values, Lines: logged in vs logged out
		if ( 'line-chart' === $display_type ) {
			$query['dimensions'] = array( 'date', 'customEvent:logged_in' );
		}

		// For pie charts, exclude "(not set)" to show clean logged-in vs logged-out comparison
		// For other types (bar-chart, table), keep "(not set)" to show data discrepancies
		if ( 'pie-chart' === $display_type ) {
			// Include only logged-in and logged-out values, excluding "(not set)"
			// The dimension stores boolean values as strings: "true" and "false"
			$query['filters'] = array(
				'conditions' => array(
					array(
						'field'  => 'customEvent:logged_in',
						'match'  => 'in',
						'values' => array( 'true', 'false' ),
					),
				),
			);
		}

		return $query;
	}
}
