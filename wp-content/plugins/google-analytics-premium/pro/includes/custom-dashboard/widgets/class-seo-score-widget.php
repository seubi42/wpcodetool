<?php

defined( 'ABSPATH' ) || exit;

class MonsterInsights_Seo_Score_Widget extends MonsterInsights_Abstract_Widget {

	public function __construct() {
		$this->type = 'seo-score';
		$this->title = __( 'SEO Score', 'google-analytics-premium' );
		$this->category = __( 'Custom Dimensions', 'google-analytics-premium' );
		$this->icon = 'dashicons-chart-line'; // WordPress Dashicon for SEO/analytics
		$this->support = array(
			'metrics' => array(
				'line-chart' => array( 'sessions', 'screenPageViews', 'engagedSessions', 'activeUsers' ),
				'bar-chart' => array( 'sessions', 'screenPageViews', 'engagedSessions', 'activeUsers' ),
				'table' => array( 'sessions', 'screenPageViews', 'engagedSessions', 'activeUsers', 'newUsers', 'engagementRate' ),
			),
			// Support multiple SEO dimensions - user can select which one to use
			'dimensions' => array(
				'customEvent:seo_score',            // Generic SEO Score
				'customEvent:aioseo_truseo_score',  // All in One SEO (TruSEO Score)
				'customEvent:yoast_seo_score',      // Yoast SEO Score
				'customEvent:rankmath_seo_score',   // Rank Math SEO Score
			),
			'types' => array( 'line-chart', 'bar-chart', 'table' ),
			'limit' => 10,
			'metricSelection' => array(
				'line-chart' => 'single',   // Line charts show one metric with SEO scores as separate lines
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
			'metrics' => array( 'sessions' ),
			'dimensions' => array( 'customEvent:aioseo_truseo_score' ), // Default to All in One SEO
			'type' => 'line-chart',
			'limit' => 10,
		);
	}

	/**
	 * Build API query for this widget based on configuration.
	 * For line charts, we need date + seo_score dimensions to show time series data with scores as lines.
	 * For bar charts and tables, we only need the seo_score dimension (user-selected).
	 *
	 * @param array $config Widget configuration from the frontend.
	 * @return array API query parameters.
	 */
	public function build_api_query( $config ) {
		$query = parent::build_api_query( $config );

		// Determine the display type to use
		$display_type = ! empty( $config['displayType'] ) ? $config['displayType'] : $this->defaults['type'];

		// Get the user-selected SEO dimension from config (overrides defaults)
		// Priority: 1) User selection from config, 2) Widget defaults
		if ( ! empty( $config['dimensions'] ) && is_array( $config['dimensions'] ) ) {
			$seo_dimension = $config['dimensions'][0];
		} elseif ( ! empty( $query['dimensions'][0] ) ) {
			$seo_dimension = $query['dimensions'][0];
		} else {
			$seo_dimension = 'customEvent:aioseo_truseo_score';
		}

		// For line charts, we need date + seo_score dimensions to show time series
		if ( 'line-chart' === $display_type ) {
			$query['dimensions'] = array( 'date', $seo_dimension );
		} else {
			// For bar charts and tables: just the user-selected seo_score dimension
			$query['dimensions'] = array( $seo_dimension );
		}

		return $query;
	}
}