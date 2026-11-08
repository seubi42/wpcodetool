<?php
/**
 * Custom View: Templates AJAX
 *
 * Provides a translatable list of dashboard templates for the Vue 3
 * "Create a New View" page.
 *
 * @since 1.0.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * MonsterInsights_Custom_Dashboard_Templates
 */
class MonsterInsights_Custom_Dashboard_Templates {
	/**
	 * Constructor - register ajax action.
	 */
	public function __construct() {
		add_action( 'wp_ajax_monsterinsights_get_custom_dashboard_templates', array( $this, 'get_templates_ajax' ) );
	}

	/**
	 * AJAX: Return the list of available templates.
	 *
	 * Security:
	 * - Nonce: mi_custom_dashboard_ajax_nonce
	 * - Cap:   monsterinsights_view_dashboard
	 *
	 * @return void
	 */
	public function get_templates_ajax() {
		check_ajax_referer( 'mi-admin-nonce', 'nonce' );

		if ( ! current_user_can( 'monsterinsights_view_dashboard' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to view this data.', 'google-analytics-premium' ) ) );
		}

		$templates = $this->get_templates();

		wp_send_json_success( $templates );
	}

	/**
	 * Get templates config (translatable).
	 *
	 * Each template contains:
	 * - id          (string)
	 * - title       (string, translated)
	 * - description (string, translated)
	 * - icon        (string slug used by frontend icon system)
	 * - layout      (array) list of widget configurations
	 *
	 * @return array
	 */
	private function get_templates() {
		$templates = array(
			array(
				'id'          => 'blank',
				'title'       => __( 'Create a Blank View', 'google-analytics-premium' ),
				'description' => __( 'Set up proper website tracking across devices and campaigns with just a few clicks.', 'google-analytics-premium' ),
				'icon'        => 'templates/monsterinsights/create-new',
				'newNavName'  => __( 'New View', 'google-analytics-premium' ),
				'layout'      => array(),
			),
			array(
				'id'          => 'ecommerce',
				'title'       => __( 'eCommerce Store', 'google-analytics-premium' ),
				'description' => __( 'Track revenue, conversion rate, and top products for your store.', 'google-analytics-premium' ),
				'icon'        => 'templates/monsterinsights/ecommerce',
				'layout'      => array(
					// Row 1 - Key Revenue Metrics (height: 1 each)
					array(
						'type'  => 'revenue',
						'width' => 1,
					),
					array(
						'type'  => 'transactions',
						'width' => 1,
					),
					array(
						'type'  => 'sessions',
						'width' => 1,
					),
					// Row 2 - Revenue Trend Chart (height: 2)
					array(
						'type'        => 'revenue',
						'width'       => 3,
						'displayType' => 'line-chart',
					),
					// Row 3 - PPC Performance (height: 2)
					array(
						'type'        => 'ppc',
						'width'       => 3,
						'displayType' => 'line-chart',
					),
					// Row 4 - Traffic Sources (height: 2 each)
					array(
						'type'        => 'utm-source',
						'width'       => 1,
						'displayType' => 'bar-chart',
					),
					array(
						'type'        => 'utm-medium',
						'width'       => 1,
						'displayType' => 'bar-chart',
					),
					array(
						'type'        => 'utm-campaigns',
						'width'       => 1,
						'displayType' => 'bar-chart',
					),
					// Row 5 - Key Events Table (height: 3)
					array(
						'type'        => 'key-events',
						'width'       => 3,
						'displayType' => 'table',
					),
				),
			),
			array(
				'id'          => 'small_business',
				'title'       => __( 'Small Business', 'google-analytics-premium' ),
				'description' => __( 'See essential traffic and engagement metrics for growing sites.', 'google-analytics-premium' ),
				'icon'        => 'templates/monsterinsights/small-business',
				'layout'      => array(
					// Row 1 - Key Traffic Metrics (height: 1 each)
					array(
						'type'  => 'total-users',
						'width' => 1,
					),
					array(
						'type'  => 'sessions',
						'width' => 1,
					),
					array(
						'type'  => 'views',
						'width' => 1,
					),
					// Row 2 - Engagement Metrics (height: 1 each)
					array(
						'type'  => 'new-users',
						'width' => 1,
					),
					array(
						'type'  => 'returning-users',
						'width' => 1,
					),
					array(
						'type'  => 'engaged-users',
						'width' => 1,
					),
					// Row 3 - Traffic Sources (height: 2 each)
					array(
						'type'        => 'organic-search',
						'width'       => 1,
						'displayType' => 'bar-chart',
					),
					array(
						'type'        => 'direct',
						'width'       => 1,
						'displayType' => 'bar-chart',
					),
					array(
						'type'        => 'social-media',
						'width'       => 1,
						'displayType' => 'bar-chart',
					),
					// Row 4 - Top Landing Pages Table (height: 3)
					array(
						'type'        => 'landing-pages',
						'width'       => 3,
						'displayType' => 'table',
					),
				),
			),
			array(
				'id'          => 'publisher',
				'title'       => __( 'Publisher', 'google-analytics-premium' ),
				'description' => __( 'Focus on content performance, top pages, and reader engagement.', 'google-analytics-premium' ),
				'icon'        => 'templates/monsterinsights/publisher',
				'layout'      => array(
					// Row 1 - Key Content Metrics (height: 1 each)
					array(
						'type'  => 'views',
						'width' => 1,
					),
					array(
						'type'  => 'total-users',
						'width' => 1,
					),
					array(
						'type'  => 'engaged-users',
						'width' => 1,
					),
					// Row 2 - Reader Engagement (height: 1 each)
					array(
						'type'  => 'new-users',
						'width' => 1,
					),
					array(
						'type'  => 'returning-users',
						'width' => 1,
					),
					array(
						'type'  => 'sessions',
						'width' => 1,
					),
					// Row 3 - Pageviews Trend Chart (height: 2)
					array(
						'type'        => 'views',
						'width'       => 3,
						'displayType' => 'line-chart',
					),
					// Row 4 - Content & Traffic Analysis (height: 2 each)
					array(
						'type'        => 'author',
						'width'       => 1,
						'displayType' => 'bar-chart',
					),
					array(
						'type'        => 'organic-search',
						'width'       => 1,
						'displayType' => 'bar-chart',
					),
					array(
						'type'        => 'social-media',
						'width'       => 1,
						'displayType' => 'bar-chart',
					),
					// Row 5 - Top Content Table (height: 3)
					array(
						'type'        => 'landing-pages',
						'width'       => 3,
						'displayType' => 'table',
					),
				),
			),
			array(
				'id'          => 'marketer',
				'title'       => __( 'Marketer', 'google-analytics-premium' ),
				'description' => __( 'Highlight campaigns, traffic sources, and goal completions.', 'google-analytics-premium' ),
				'icon'        => 'templates/monsterinsights/marketer',
				'layout'      => array(
					// Row 1 - Key Marketing Metrics (height: 1 each)
					array(
						'type'  => 'total-users',
						'width' => 1,
					),
					array(
						'type'  => 'new-users',
						'width' => 1,
					),
					array(
						'type'  => 'sessions',
						'width' => 1,
					),
					// Row 3 - User Acquisition Trend (height: 2)
					array(
						'type'        => 'total-users',
						'width'       => 3,
						'displayType' => 'line-chart',
					),
					// Row 4 - Campaign Performance (height: 2 full width line charts)
					array(
						'type'        => 'utm-campaigns',
						'width'       => 3,
						'displayType' => 'line-chart',
					),
					array(
						'type'        => 'utm-source',
						'width'       => 3,
						'displayType' => 'line-chart',
					),
					array(
						'type'        => 'utm-medium',
						'width'       => 3,
						'displayType' => 'line-chart',
					),
				),
			),
		);

		/**
		 * Filter templates for Custom Views.
		 *
		 * @param array $templates Templates array.
		 */
		$templates = apply_filters( 'monsterinsights_custom_dashboard_templates', $templates );

		// Sanitize outward-facing values.
		$sanitized = array();
		foreach ( $templates as $tpl ) {
			$sanitized_layout = array();
			if ( isset( $tpl['layout'] ) && is_array( $tpl['layout'] ) ) {
				foreach ( $tpl['layout'] as $widget ) {
					$sanitized_widget = array(
						'type' => isset( $widget['type'] ) ? sanitize_key( $widget['type'] ) : '',
						'width' => isset( $widget['width'] ) ? absint( $widget['width'] ) : 1,
					);
					// Add displayType if present
					if ( isset( $widget['displayType'] ) ) {
						$sanitized_widget['displayType'] = sanitize_key( $widget['displayType'] );
					}
					$sanitized_layout[] = $sanitized_widget;
				}
			}

			$sanitized[] = array(
				'id'          => isset( $tpl['id'] ) ? sanitize_key( $tpl['id'] ) : '',
				'title'       => isset( $tpl['title'] ) ? wp_kses_post( $tpl['title'] ) : '',
				'description' => isset( $tpl['description'] ) ? wp_kses_post( $tpl['description'] ) : '',
				'icon'        => isset( $tpl['icon'] ) ? sanitize_text_field( $tpl['icon'] ) : '',
				'layout'      => $sanitized_layout,
				'newNavName'  => isset( $tpl['newNavName'] ) ? wp_kses_post( $tpl['newNavName'] ) : '',
			);
		}

		return $sanitized;
	}
}


