<?php

defined( 'ABSPATH' ) || exit;

abstract class MonsterInsights_Abstract_Widget implements MonsterInsights_Widget_Interface {

	/**
	 * Widget type identifier.
	 *
	 * @var string
	 */
	protected $type;

	/**
	 * Widget title.
	 *
	 * @var string
	 */
	protected $title;

	/**
	 * Widget category.
	 *
	 * @var string
	 */
	protected $category;

	/**
	 * Widget icon.
	 *
	 * @var string
	 */
	protected $icon;

	/**
	 * Widget support configuration.
	 *
	 * Defines what metrics, dimensions, and display types are supported by this widget.
	 *
	 * Supports two formats:
	 *
	 * 1. Simple Format (Legacy - applies to all types):
	 *    array(
	 *        'metrics' => array( 'totalRevenue' ),
	 *        'dimensions' => array(),
	 *        'types' => array( 'infobox' ),
	 *    )
	 *
	 * 2. Type-Aware Format (Recommended for multi-type widgets):
	 *    array(
	 *        'metrics' => array(
	 *            'infobox' => array( 'keyEvents' ),
	 *            'table' => array( 'eventCount', 'keyEvents' ),
	 *            'bar-chart' => array( 'eventCount' ),
	 *        ),
	 *        'dimensions' => array(
	 *            'infobox' => array(),
	 *            'table' => array( 'eventName' ),
	 *            'bar-chart' => array( 'eventName' ),
	 *        ),
	 *        'types' => array( 'infobox', 'table', 'bar-chart' ),
	 *    )
	 *
	 * The frontend uses getWidgetSupportForType() helper to extract type-specific config.
	 * See WIDGET-CONFIGURATION.md for full documentation.
	 *
	 * @var array
	 */
	protected $support;

	/**
	 * Widget default options.
	 *
	 * @var array
	 */
	protected $defaults;

	/**
	 * Whether this widget uses local data instead of API.
	 *
	 * @var bool
	 */
	protected $is_local = false;

	/**
	 * Get the widget type identifier.
	 *
	 * @return string The widget type.
	 */
	public function get_type() {
		return $this->type;
	}

	/**
	 * Get the widget title.
	 *
	 * @return string The widget title.
	 */
	public function get_title() {
		return $this->title;
	}

	/**
	 * Get the widget category.
	 *
	 * @return string The widget category.
	 */
	public function get_category() {
		return $this->category;
	}

	/**
	 * Get the widget icon.
	 *
	 * @return string The widget icon.
	 */
	public function get_icon() {
		return $this->icon;
	}

	/**
	 * Get the widget support configuration.
	 *
	 * @return array The widget support configuration.
	 */
	public function get_support() {
		return $this->support;
	}

	/**
	 * Get the widget default values.
	 *
	 * @return array The widget default values.
	 */
	public function get_defaults() {
		return $this->defaults;
	}

	/**
	 * Check if this widget uses local data.
	 *
	 * @return bool True if widget uses local data.
	 */
	public function is_local() {
		return $this->is_local;
	}

	/**
	 * Get widget metadata for frontend.
	 *
	 * Note: Dimensions are intentionally excluded from defaults sent to frontend.
	 * Dimensions are backend logic and determined by build_api_query() based on
	 * widget type and display type. Frontend only controls metrics selection.
	 *
	 * @return array Widget metadata.
	 */
	public function get_metadata() {
		// Get defaults but remove dimensions - backend determines dimensions
		$defaults = $this->get_defaults();
		unset( $defaults['dimensions'] );

		// Get support but keep dimensions info for documentation purposes
		$support = $this->get_support();

		return array(
			'type'           => $this->get_type(),
			'title'          => $this->get_title(),
			'category'       => $this->get_category(),
			'icon'           => $this->get_icon(),
			'support'        => $support,
			'defaults'       => $defaults,
			'is_local'       => $this->is_local(),
		);
	}

	/**
	 * Build API query for this widget based on configuration.
	 *
	 * Metrics come from frontend (user choice).
	 * Dimensions are determined by backend (widget logic based on type/displayType).
	 *
	 * IMPORTANT: Override this method when using type-aware configuration to set
	 * dimensions based on the displayType. Example:
	 *
	 *     public function build_api_query( $config ) {
	 *         $query = parent::build_api_query( $config );
	 *
	 *         $display_type = ! empty( $config['displayType'] )
	 *             ? $config['displayType']
	 *             : $this->defaults['type'];
	 *
	 *         if ( 'table' === $display_type ) {
	 *             $query['dimensions'] = array( 'eventName' );
	 *         } else {
	 *             $query['dimensions'] = array();
	 *         }
	 *
	 *         return $query;
	 *     }
	 *
	 * See WIDGET-CONFIGURATION.md for full documentation and examples.
	 *
	 * @param array $config Widget configuration from the frontend.
	 * @return array API query parameters.
	 */
	public function build_api_query( $config ) {
		$query = array();

		// Add metrics - use config first (user selection), fallback to widget defaults
		if ( ! empty( $config['metrics'] ) ) {
			$query['metrics'] = $config['metrics'];
		} elseif ( ! empty( $this->defaults['metrics'] ) ) {
			$query['metrics'] = $this->defaults['metrics'];
		} elseif ( ! empty( $this->support['metrics'] ) ) {
			// Last resort: use first supported metric
			$query['metrics'] = array( $this->support['metrics'][0] );
		}

		// Add dimensions - ALWAYS use widget defaults, NEVER from config
		// This ensures dimensions are determined by backend logic, not cached frontend config
		// Child classes can override build_api_query() to customize dimensions based on displayType
		if ( ! empty( $this->defaults['dimensions'] ) ) {
			$query['dimensions'] = $this->defaults['dimensions'];
		}

		// Add comparison flag (defaults to false)
		$query['compare'] = ! empty( $config['compare'] ) ? (bool) $config['compare'] : false;

		// Add filters
		if ( ! empty( $config['filters'] ) ) {
			$query['filters'] = $config['filters'];
		} elseif ( ! empty( $this->defaults['filters'] ) ) {
			$query['filters'] = $this->defaults['filters'];
		}

		// Add other query parameters
		if ( ! empty( $config['limit'] ) ) {
			$query['limit'] = intval( $config['limit'] );
		} elseif ( ! empty( $this->defaults['limit'] ) ) {
			$query['limit'] = intval( $this->defaults['limit'] );
		}

		if ( ! empty( $config['order_by'] ) ) {
			$query['orderBy'] = $config['order_by'];
		} elseif ( ! empty( $this->defaults['order_by'] ) ) {
			$query['orderBy'] = $this->defaults['order_by'];
		}

		return $query;
	}

	/**
	 * Process the API data for this widget.
	 * Default implementation returns the data as-is.
	 *
	 * @param array $api_data The raw API response data.
	 * @param array $config   The widget configuration from the frontend.
	 * @return array The processed widget data.
	 */
	public function process_data( $api_data, $config ) {
		return $api_data;
	}

	/**
	 * Generate a Google Analytics 4 report URL.
	 *
	 * Creates a direct link to a specific report in the GA4 interface.
	 * Uses the same URL format as the existing Vue 2 reports.
	 *
	 * @param string $report_name GA4 report identifier (e.g., 'lifecycle-traffic-acquisition', 'all-pages-and-screens').
	 *                            Pass empty string for generic reports.
	 * @param string $endpoint    GA4 endpoint type: 'explorer' (default), 'dashboard', or 'overview'.
	 * @return string The full GA4 report URL, or empty string if not authenticated.
	 */
	protected function get_ga_report_url( $report_name = '', $endpoint = 'explorer' ) {
		$auth = MonsterInsights()->auth;

		// Get property identifier (format: p{PROPERTY_ID})
		$referral_url = $auth->get_referral_url();
		if ( empty( $referral_url ) ) {
			return '';
		}

		// Build the GA4 URL
		// Format: https://analytics.google.com/analytics/web/#/{PROPERTY_ID}/reports/{ENDPOINT}?params=&r={REPORT_NAME}
		$base_url = 'https://analytics.google.com/analytics/web/#/' . $referral_url . '/reports/' . $endpoint;

		if ( ! empty( $report_name ) ) {
			$base_url .= '?params=_u..nav%3Dmaui&r=' . $report_name;
		}

		return $base_url;
	}
}