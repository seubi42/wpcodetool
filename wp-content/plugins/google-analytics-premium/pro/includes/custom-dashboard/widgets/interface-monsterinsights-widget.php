<?php

defined( 'ABSPATH' ) || exit;

interface MonsterInsights_Widget_Interface {

	/**
	 * Get the widget type identifier.
	 *
	 * @return string The widget type.
	 */
	public function get_type();

	/**
	 * Get the widget title.
	 *
	 * @return string The widget title.
	 */
	public function get_title();

	/**
	 * Get the widget category.
	 *
	 * @return string The widget category.
	 */
	public function get_category();

	/**
	 * Get the widget support configuration.
	 *
	 * @return array The widget support configuration.
	 */
	public function get_support();

	/**
	 * Get the widget default values.
	 *
	 * @return array The widget default values.
	 */
	public function get_defaults();

	/**
	 * Build API query for this widget based on configuration.
	 *
	 * @param array $config Widget configuration from the frontend.
	 * @return array API query parameters.
	 */
	public function build_api_query( $config );

	/**
	 * Process the API data for this widget.
	 *
	 * @param array $api_data The raw API response data.
	 * @param array $config   The widget configuration from the frontend.
	 * @return array The processed widget data.
	 */
	public function process_data( $api_data, $config );
}