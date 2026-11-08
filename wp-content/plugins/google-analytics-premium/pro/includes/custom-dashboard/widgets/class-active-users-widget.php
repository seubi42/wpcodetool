<?php

defined( 'ABSPATH' ) || exit;

class MonsterInsights_Active_Users_Widget extends MonsterInsights_Abstract_Widget {

	public function __construct() {
		$this->type = 'active-users';
		$this->title = __( 'Active Users', 'google-analytics-premium' );
		$this->category = __( 'Key Metrics', 'google-analytics-premium' );
		$this->icon = 'users';
		$this->support = array(
			'metrics' => array( 'activeUsers' ),
			'dimensions' => array(
				'line-chart' => array( 'date' ),
			),
			'types' => array( 'infobox', 'line-chart' ),
		);
		$this->defaults = array(
			'metrics' => array( 'activeUsers' ),
			'type' => 'infobox',
			'compare' => true,
		);
	}

	/**
	 * Build API query for this widget based on configuration.
	 *
	 * @param array $config Widget configuration from the frontend.
	 * @return array API query parameters.
	 */
	public function build_api_query( $config ) {
		$query = parent::build_api_query( $config );

		// Determine the display type
		$display_type = ! empty( $config['displayType'] ) ? $config['displayType'] : $this->defaults['type'];

		// Set dimensions based on display type
		if ( 'line-chart' === $display_type ) {
			// Line chart needs date dimension for time series
			$query['dimensions'] = array( 'date' );
		} else {
			// Infobox: no dimensions, just total
			$query['dimensions'] = array();
		}

		return $query;
	}
}