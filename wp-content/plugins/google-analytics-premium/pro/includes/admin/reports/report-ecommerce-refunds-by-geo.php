<?php
/**
 * eCommerce Refunds by Geo Report
 *
 * Ensures all the reports have a uniform class with helper functions.
 *
 * @since 9.5.0
 *
 * @package MonsterInsights
 * @subpackage Reports
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class MonsterInsights_Report_eCommerce_Refunds_By_Geo extends MonsterInsights_Report {

	public $class = 'MonsterInsights_Report_eCommerce_Refunds_By_Geo';
	public $name  = 'ecommerce_refunds_by_geo';
	public $level = 'pro';

	protected $api_path = 'ecommerce/refunds-by-geo';

	/**
	 * Primary class constructor.
	 */
	public function __construct() {
		$this->title = __( 'Refunds by Geo', 'google-analytics-premium' );

		parent::__construct();
	}

	/**
	 * Set eCommerce addon as a requirement of the eCommerce report.
	 *
	 * @param $error
	 * @param $args
	 * @param $name
	 *
	 * @return false|string
	 */
	public function requirements( $error = false, $args = array(), $name = '' ) {
		if ( ! empty( $error ) || $name !== $this->name ) {
			return $error;
		}

		if ( ! class_exists( 'MonsterInsights_eCommerce' ) ) {
			add_filter( 'monsterinsights_reports_handle_error_message', array( $this, 'add_error_addon_link' ) );

			// Translators: %s will be the action (install/activate) which will be filled depending on the addon state.
			$text = __( 'Please %s the MonsterInsights eCommerce addon to view Refunds by Geo reports.', 'google-analytics-premium' );

			if ( monsterinsights_can_install_plugins() ) {
				return $text;
			} else {
				return sprintf( $text, __( 'install', 'google-analytics-premium' ) );
			}
		}

		return $error;
	}

}

