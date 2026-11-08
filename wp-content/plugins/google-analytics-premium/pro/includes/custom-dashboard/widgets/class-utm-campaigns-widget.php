<?php

defined( 'ABSPATH' ) || exit;

class MonsterInsights_Utm_Campaigns_Widget extends MonsterInsights_Abstract_Widget {

	public function __construct() {
		$this->type = 'utm-campaigns';
		$this->title = __( 'UTM Campaigns', 'google-analytics-premium' );
		$this->category = __( 'Traffic', 'google-analytics-premium' );
		$this->icon = 'dashicons-megaphone'; // WordPress Dashicon for campaigns
		$this->support = array(
			'metrics' => array(
				'line-chart' => array( 'sessions', 'screenPageViews', 'engagedSessions' ), // User can choose which metric
				'bar-chart' => array( 'sessions', 'screenPageViews', 'engagedSessions' ),  // Multiple metrics as grouped bars
				'table' => array( 'sessions', 'screenPageViews', 'engagedSessions' ),      // Multiple metrics as columns
			),
			'dimensions' => array( 'sessionCampaignName' ),
			'types' => array( 'line-chart', 'bar-chart', 'table' ),
			'limit' => 6, // Number of campaigns to display in charts
			'metricSelection' => array(
				'line-chart' => 'single',   // Radio buttons - only one metric at a time
				'bar-chart' => 'multiple',  // Checkboxes - multiple metrics as grouped bars
				'table' => 'multiple',      // Checkboxes - multiple metrics as columns
			),
			'compare' => array(
				'line-chart' => false,  // Line charts don't (too many lines with different campaigns)
				'bar-chart' => false,   // Bar charts don't (too complex with multiple bars)
				'table' => true,        // Tables support comparison
			),
		);
		$this->defaults = array(
			'metrics' => array( 'sessions' ), // Default to single metric for line-chart
			'dimensions' => array( 'sessionCampaignName' ),
			'type' => 'line-chart',
			'limit' => 6,
		);
	}

	/**
	 * Build API query for this widget based on configuration.
	 * For line charts, we need date dimension to show time series data with campaigns as lines.
	 * For bar charts and tables, we only need the sessionCampaignName dimension.
	 *
	 * @param array $config Widget configuration from the frontend.
	 * @return array API query parameters.
	 */
	public function build_api_query( $config ) {
		$query = parent::build_api_query( $config );

		// Determine the display type to use
		// Priority: 1) User-selected displayType, 2) Widget default type
		$display_type = ! empty( $config['displayType'] ) ? $config['displayType'] : $this->defaults['type'];

		// For line charts, we need date + campaign dimensions to show time series
		// X-axis: dates (30 days), Y-axis: metric values, Lines: one per campaign
		if ( 'line-chart' === $display_type ) {
			$query['dimensions'] = array( 'date', 'sessionCampaignName' );
		}
		// For bar charts and tables: X-axis/rows are campaigns, Y-axis/columns are metrics

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