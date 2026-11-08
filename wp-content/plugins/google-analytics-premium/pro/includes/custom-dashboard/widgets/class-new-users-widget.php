<?php

defined( 'ABSPATH' ) || exit;

class MonsterInsights_New_Users_Widget extends MonsterInsights_Abstract_Widget {

	public function __construct() {
		$this->type = 'new-users';
		$this->title = __( 'New Users', 'google-analytics-premium' );
		$this->category = __( 'Key Metrics', 'google-analytics-premium' );
		$this->icon = 'dashicons-businessman'; // WordPress Dashicon for new user
		$this->support = array(
			'metrics' => array( 'newUsers' ), // Single metric - uses GA4's dedicated newUsers metric
			'dimensions' => array(
				'line-chart' => array( 'date' ),
			),
			'types' => array( 'infobox', 'line-chart' ),
		);
		$this->defaults = array(
			'metrics' => array( 'newUsers' ),
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
