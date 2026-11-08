<?php

defined( 'ABSPATH' ) || exit;

class MonsterInsights_Landing_Pages_Widget extends MonsterInsights_Abstract_Widget {

	public function __construct() {
		$this->type = 'landing-pages';
		$this->title = __( 'Landing Pages', 'google-analytics-premium' );
		$this->category = __( 'Traffic', 'google-analytics-premium' );
		$this->icon = 'dashicons-admin-page'; // WordPress Dashicon for pages
		$this->support = array(
			'metrics' => array( 'activeUsers', 'screenPageViews', 'engagementRate' ),
			'dimensions' => array( 'landingPage' ),
			'types' => array( 'table' ),
		);
		$this->defaults = array(
			'metrics' => array( 'activeUsers', 'screenPageViews', 'engagementRate' ),
			'dimensions' => array( 'landingPage' ),
			'type' => 'table',
		);
	}

	/**
	 * Process the API data for this widget.
	 *
	 * @param array $api_data The raw API response data.
	 * @param array $config   The widget configuration from the frontend.
	 * @return array The processed widget data.
	 */
	public function process_data( $api_data, $config ) {
		$api_data['report_galink'] = $this->get_ga_report_url( 'landing-page' );

		return $api_data;
	}
}
