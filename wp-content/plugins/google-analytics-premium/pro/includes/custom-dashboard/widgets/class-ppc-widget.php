<?php

defined( 'ABSPATH' ) || exit;

class MonsterInsights_Ppc_Widget extends MonsterInsights_Abstract_Widget {

	public function __construct() {
		$this->type = 'ppc';
		$this->title = __( 'PPC', 'google-analytics-premium' );
		$this->category = __( 'Traffic Source', 'google-analytics-premium' );
		$this->icon = 'dashicons-money-alt';
		$this->support = array(
			'metrics' => array(
				'line-chart' => array( 'sessions', 'screenPageViews', 'engagedSessions', 'activeUsers' ),
				'bar-chart' => array( 'sessions', 'screenPageViews', 'engagedSessions', 'activeUsers' ),
				'table' => array( 'sessions', 'screenPageViews', 'engagedSessions', 'activeUsers', 'newUsers', 'engagementRate' ),
			),
			'dimensions' => array( 'sessionMedium' ),
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
			'metrics' => array( 'sessions' ),
			'dimensions' => array( 'sessionMedium' ),
			'type' => 'line-chart',
			'limit' => 10,
		);
	}

	/**
	 * Build API query for this widget based on configuration.
	 * For line charts, we need date + sessionMedium dimensions to show time series data.
	 *
	 * @param array $config Widget configuration from the frontend.
	 * @return array API query parameters.
	 */
	public function build_api_query( $config ) {
		$query = parent::build_api_query( $config );

		$display_type = ! empty( $config['displayType'] ) ? $config['displayType'] : $this->defaults['type'];

		// For line charts, we need date + sessionMedium dimensions to show time series
		if ( 'line-chart' === $display_type ) {
			$query['dimensions'] = array( 'date', 'sessionMedium' );
		}

		return $query;
	}
}
