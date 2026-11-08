<?php

defined( 'ABSPATH' ) || exit;

class MonsterInsights_Key_Events_Widget extends MonsterInsights_Abstract_Widget {

	public function __construct() {
		$this->type = 'key-events';
		$this->title = __( 'Key Events', 'google-analytics-premium' );
		$this->category = __( 'Key Metrics', 'google-analytics-premium' );
		$this->icon = 'dashicons-star-filled';

		// Type-aware configuration: different metrics/dimensions per display type
		// See WIDGET-CONFIGURATION.md for documentation
		$this->support = array(
			'metrics' => array(
				'infobox' => array( 'keyEvents' ),
				'table' => array( 'eventCount', 'keyEvents' ),
				'bar-chart' => array( 'eventCount' ),
			),
			'dimensions' => array(
				'infobox' => array(), // No dimension needed for total count
				'table' => array( 'eventName' ), // Break down by event name
				'bar-chart' => array( 'eventName' ), // Break down by event name
			),
			'types' => array( 'infobox', 'bar-chart', 'table' ),
		);

		$this->defaults = array(
			'metrics' => array( 'keyEvents' ),
			'type' => 'infobox',
			'compare' => true,
		);
	}

	/**
	 * Build API query for this widget based on configuration.
	 * For infobox, we show just the total (no dimensions).
	 * For bar-chart and table, we break down by eventName dimension.
	 *
	 * @param array $config Widget configuration from the frontend.
	 * @return array API query parameters.
	 */
	public function build_api_query( $config ) {
		$query = parent::build_api_query( $config );

		// Determine the display type
		$display_type = ! empty( $config['displayType'] ) ? $config['displayType'] : $this->defaults['type'];

		// For bar-chart and table, include eventName dimension to show breakdown
		if ( 'bar-chart' === $display_type || 'table' === $display_type ) {
			$query['dimensions'] = array( 'eventName' );
		} else {
			// For infobox, no dimensions - just show total
			$query['dimensions'] = array();
		}

		return $query;
	}
}