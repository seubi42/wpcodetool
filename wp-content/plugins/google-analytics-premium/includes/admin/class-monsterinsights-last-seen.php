<?php
/**
 * Tracks the last time an admin viewed a MonsterInsights admin screen.
 *
 * @package     MonsterInsights
 * @subpackage  Admin
 * @since       11.2.0
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Records a write-throttled "last seen" timestamp for Customer360 usage tracking.
 *
 * @since 11.2.0
 * @package MonsterInsights
 * @subpackage Admin
 */
class MonsterInsights_Last_Seen {

	/**
	 * Option name storing the unix timestamp of the last recorded admin view.
	 *
	 * @since 11.2.0
	 */
	const OPTION = 'monsterinsights_last_admin_seen';

	/**
	 * Minimum interval between option writes.
	 *
	 * @since 11.2.0
	 */
	const THROTTLE = HOUR_IN_SECONDS;

	/**
	 * Primary class constructor.
	 *
	 * @since 11.2.0
	 * @access public
	 */
	public function __construct() {
		add_action( 'current_screen', array( $this, 'maybe_record' ) );
	}

	/**
	 * Updates the last-seen option when the current screen is a MonsterInsights
	 * admin screen, throttled to at most once per hour.
	 *
	 * @since 11.2.0
	 * @access public
	 *
	 * @param WP_Screen $screen The screen object passed by the `current_screen` action.
	 * @return void
	 */
	public function maybe_record( $screen = null ) {
		if ( ! $screen instanceof WP_Screen ) {
			return;
		}

		// Every MonsterInsights menu hook is derived from a `monsterinsights_*` menu
		// slug, so the substring test covers all the id variants (`toplevel_page_*`,
		// `insights_page_*`) as well as the `monsterinsights_network` base used on
		// network admin screens.
		$is_monsterinsights_screen = ( ! empty( $screen->id ) && false !== strpos( $screen->id, 'monsterinsights' ) )
			|| ( ! empty( $screen->base ) && false !== strpos( $screen->base, 'monsterinsights' ) );

		if ( ! $is_monsterinsights_screen ) {
			return;
		}

		self::touch();
	}

	/**
	 * Updates the last-seen option, throttled to at most once per hour.
	 *
	 * Public so callers that already know a MonsterInsights surface is being
	 * viewed (e.g. the dashboard widget, which renders on the core "dashboard"
	 * screen and so is invisible to the screen-id check above) can record
	 * activity directly, without needing a matching screen id.
	 *
	 * @since 11.2.0
	 * @access public
	 *
	 * @return void
	 */
	public static function touch() {
		if ( ! MonsterInsights_Usage_Tracking::tracking_allowed() ) {
			return;
		}
		
		$last_seen = get_option( self::OPTION, 0 );
		if ( is_numeric( $last_seen ) && $last_seen > time() - self::THROTTLE ) {
			return;
		}

		update_option( self::OPTION, time(), false );
	}
}
new MonsterInsights_Last_Seen();
