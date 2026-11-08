<?php
/**
 * Engagement Pages Report
 *
 * Ensures all the reports have a uniform class with helper functions.
 *
 * @since 8.17
 *
 * @package MonsterInsights
 * @subpackage Reports
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class MonsterInsights_Report_Engagement_Pages extends MonsterInsights_Report {

	public $class = 'MonsterInsights_Report_Engagement_Pages';
	public $name  = 'engagement_pages';
	public $level = 'plus';

	protected $api_path = 'engagement-pages';

	/**
	 * Primary class constructor.
	 */
	public function __construct() {
		$this->title = __( 'Pages Report', 'google-analytics-premium' );

		parent::__construct();
	}

	/**
	 * Prepare and format the report data with any extra data needed for the report.
	 *
	 * @param $data
	 *
	 * @return mixed
	 */
	public function prepare_report_data( $data ) {
		// Add GA links similar to Overview report.
		if ( ! empty( $data['data'] ) ) {
			$data['data']['galinks'] = array(
				'pages' => $this->get_ga_report_url( 'all-pages-and-screens', $data['data'] ),
			);
		}

		return $data;
	}

}
