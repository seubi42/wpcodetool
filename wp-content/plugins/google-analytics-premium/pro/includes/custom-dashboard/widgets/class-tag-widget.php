<?php

defined( 'ABSPATH' ) || exit;

class MonsterInsights_Tag_Widget extends MonsterInsights_Abstract_Widget {

	public function __construct() {
		$this->type = 'tag';
		$this->title = __( 'Tags', 'google-analytics-premium' );
		$this->category = __( 'Traffic', 'google-analytics-premium' );
		$this->icon = 'dashicons-tag';
		$this->support = array(
			'metrics' => array(
				'line-chart' => array( 'screenPageViews', 'sessions', 'activeUsers', 'engagedSessions' ),
				'bar-chart' => array( 'screenPageViews', 'sessions', 'activeUsers', 'engagedSessions' ),
				'table' => array( 'screenPageViews', 'sessions', 'activeUsers', 'engagedSessions', 'newUsers', 'engagementRate' ),
			),
			'dimensions' => array( 'customEvent:tags' ),
			'types' => array( 'line-chart', 'bar-chart', 'table' ),
			'limit' => 10,
			'metricSelection' => array(
				'line-chart' => 'single',
				'bar-chart' => 'multiple',
				'table' => 'multiple',
			),
			'compare' => array(
				'line-chart' => false,
				'bar-chart' => false,
				'table' => true,
			),
		);
		$this->defaults = array(
			'metrics' => array( 'screenPageViews' ),
			'dimensions' => array( 'customEvent:tags' ),
			'type' => 'bar-chart',
			'limit' => 10,
		);
	}

	/**
	 * Build API query for this widget based on configuration.
	 * For line charts, we need date + tags dimensions to show time series data.
	 *
	 * @param array $config Widget configuration from the frontend.
	 * @return array API query parameters.
	 */
	public function build_api_query( $config ) {
		$query = parent::build_api_query( $config );

		$display_type = ! empty( $config['displayType'] ) ? $config['displayType'] : $this->defaults['type'];

		// For line charts, we need date + tags dimensions to show time series
		if ( 'line-chart' === $display_type ) {
			$query['dimensions'] = array( 'date', 'customEvent:tags' );
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
		// Custom dimensions report - use the demographics detail report as closest match
		$api_data['report_galink'] = $this->get_ga_report_url( 'user-demographics-detail' );

		return $api_data;
	}
}
