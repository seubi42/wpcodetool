<?php
/**
 * Auth class.
 *
 * Helper for auth.
 *
 * @since 7.0.0
 *
 * @package MonsterInsights
 * @subpackage Auth
 * @author  Chris Christoff
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class MonsterInsights_Auth {

	private $profile = array();
	private $network = array();

	/**
	 * Primary class constructor.
	 *
	 * @access public
	 * @since 7.0.0
	 */
	public function __construct() {
		$this->profile = $this->get_analytics_profile();
		$this->network = $this->get_network_analytics_profile();
	}

	public function is_manual() {
		if ( empty( $this->profile['manual'] ) ) {
			return false;
		}

		$manual_code = $this->profile['manual'];
		return monsterinsights_is_valid_v4_id( $manual_code );
	}

	public function is_network_manual( $type = false ) {
		if ( empty( $this->network['manual'] ) ) {
			return false;
		}

		$manual_code = $this->network['manual'];
		return monsterinsights_is_valid_v4_id( $manual_code );
	}

	public function is_authed() {
		return ! empty( $this->profile['key'] ) && ! empty( $this->profile[ 'v4' ] );
	}

	public function is_network_authed() {
		return ! empty( $this->network['key'] ) && ! empty( $this->network[ 'v4' ] );
	}

	public function get_analytics_profile( $force = false ) {
		if ( ! empty( $this->profile ) && ! $force ) {
			return $this->profile;
		} else {
			$profile       = get_option( 'monsterinsights_site_profile', array() );
			$this->profile = $profile;

			return $profile;
		}
	}

	public function get_network_analytics_profile( $force = false ) {
		if ( ! empty( $this->network ) && ! $force ) {
			return $this->network;
		} else {
			$profile       = get_site_option( 'monsterinsights_network_profile', array() );
			$this->network = $profile;

			return $profile;
		}
	}

	/**
	 * Carry the stored credentials over when an incoming profile would keep the
	 * property but drop `key`/`token`.
	 *
	 * That combination is never legitimate: authenticating always writes both, and
	 * disconnecting drops `v4` too. When it shows up, the write is a partial
	 * overwrite built from an incomplete copy of the profile — and persisting it
	 * leaves a connection that looks healthy while every Reporting API call fails
	 * with `403: The key is missing from the request` (GH-3343). Only credentials
	 * belonging to the same property are carried over, so switching properties
	 * still requires real credentials.
	 *
	 * @param array $data   Profile about to be saved.
	 * @param array $stored Profile currently in the database.
	 *
	 * @return array
	 * @since 9.8.0
	 */
	private function keep_existing_credentials( $data, $stored ) {
		if ( ! is_array( $data ) || empty( $data['v4'] ) || ! empty( $data['key'] ) ) {
			return $data;
		}

		if ( ! is_array( $stored ) || empty( $stored['key'] ) || empty( $stored['v4'] ) ) {
			return $data;
		}

		if ( $stored['v4'] !== $data['v4'] ) {
			return $data;
		}

		$data['key']   = $stored['key'];
		$data['token'] = isset( $stored['token'] ) ? $stored['token'] : '';

		return $data;
	}

	/**
	 * Merge fields into the stored site profile, read in the current context.
	 *
	 * Partial updates used to rebuild the profile from `$this->profile`, which is
	 * hydrated once per request: after a `switch_to_blog()` it belongs to another
	 * site, and in a cron/REST context it can be empty or stale. Saving that copy
	 * back replaced the target profile wholesale — writing another site's
	 * credentials into it, or dropping `key`/`token` altogether (GH-3343). Always
	 * re-read the option for the site being written to.
	 *
	 * @param array $fields Fields to set.
	 *
	 * @return void
	 * @since 9.8.0
	 */
	private function merge_analytics_profile( $fields ) {
		$stored = get_option( 'monsterinsights_site_profile', array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		$this->set_analytics_profile( array_merge( $stored, $fields ) );
	}

	/**
	 * Network counterpart of merge_analytics_profile().
	 *
	 * @param array $fields Fields to set.
	 *
	 * @return void
	 * @since 9.8.0
	 */
	private function merge_network_analytics_profile( $fields ) {
		$stored = get_site_option( 'monsterinsights_network_profile', array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		$this->set_network_analytics_profile( array_merge( $stored, $fields ) );
	}

	public function set_analytics_profile( $data = array() ) {
		$data = $this->keep_existing_credentials( $data, get_option( 'monsterinsights_site_profile', array() ) );

		if ( ! empty( $data ) ) {
			$data['connection_time'] = time();
		}

		update_option( 'monsterinsights_site_profile', $data );
		$this->profile = $data;

		// If this is the first time, save the date when they connected.
		$over_time    = get_option( 'monsterinsights_over_time', array() );
		$needs_update = false;
		if ( monsterinsights_is_pro_version() && empty( $over_time['connected_date_pro'] ) ) {
			$over_time['connected_date_pro'] = time();
			$needs_update                    = true;
		}
		if ( ! monsterinsights_is_pro_version() && empty( $over_time['connected_date_lite'] ) ) {
			$over_time['connected_date_lite'] = time();
			$needs_update                     = true;
		}
		if ( $needs_update ) {
			update_option( 'monsterinsights_over_time', $over_time, false );
		}
		monsterinsights_update_option( 'site_notes_import_synced', 0 );
		monsterinsights_update_option( 'site_notes_export_synced', 0 );
	}

	public function set_network_analytics_profile( $data = array() ) {
		$data = $this->keep_existing_credentials( $data, get_site_option( 'monsterinsights_network_profile', array() ) );

		update_site_option( 'monsterinsights_network_profile', $data );
		$this->network = $data;
	}

	public function delete_analytics_profile( $migrate = true ) {
		if ( $migrate ) {
			$newdata = array();
			if ( isset( $this->profile['v4'] ) ) {
				$newdata['manual_v4'] = $this->profile['v4'];
				// The secret is optional, so a profile can have a `v4` without it. Reading it
				// unguarded warns and stores a null secret; a forced disconnect now routes
				// corrupt profiles through here, where that is much easier to hit.
				$newdata['measurement_protocol_secret'] = isset( $this->profile['measurement_protocol_secret'] ) ? $this->profile['measurement_protocol_secret'] : '';
			}
			$this->profile = $newdata;
			$this->set_analytics_profile( $newdata );
		} else {
			$this->profile = array();
			delete_option( 'monsterinsights_site_profile' );
		}
	}

	public function delete_network_analytics_profile( $migrate = true ) {
		if ( $migrate ) {
			$newdata = array();
			if ( isset( $this->network['v4'] ) ) {
				$newdata['manual_v4'] = $this->network['v4'];
				// Optional, exactly as in delete_analytics_profile() above.
				$newdata['measurement_protocol_secret'] = isset( $this->network['measurement_protocol_secret'] ) ? $this->network['measurement_protocol_secret'] : '';
			}
			$this->network = $newdata;
			$this->set_network_analytics_profile( $newdata );
		} else {
			$this->network = array();
			delete_site_option( 'monsterinsights_network_profile' );
		}
	}

	public function set_manual_v4_id( $v4 = '' ) {
		if ( empty( $v4 ) ) {
			return;
		}

		if ( $this->is_authed() ) {
			MonsterInsights()->api_auth->delete_auth();
		}

		do_action( 'monsterinsights_reports_delete_aggregate_data' );

		$this->merge_analytics_profile( array( 'manual_v4' => $v4 ) );
	}

	public function set_network_manual_v4_id( $v4 = '' ) {
		if ( empty( $v4 ) ) {
			return;
		}

		if ( $this->is_network_authed() ) {
			MonsterInsights()->api_auth->delete_auth();
		}

		do_action( 'monsterinsights_reports_delete_network_aggregate_data' );

		// Both keys are written now. They used to diverge -- `network_manual_v4` was only
		// added when a network profile already existed -- which left a fresh network manual
		// ID readable by get_network_manual_v4_id() but not by anything reading the
		// `network_manual_v4` key.
		$this->merge_network_analytics_profile( array(
			'manual_v4'         => $v4,
			'network_manual_v4' => $v4,
		) );
	}

	public function get_measurement_protocol_secret() {
		return ! empty( $this->profile['measurement_protocol_secret'] ) ? $this->profile['measurement_protocol_secret'] : '';
	}

	public function get_network_measurement_protocol_secret() {
		return ! empty( $this->network['measurement_protocol_secret'] ) ? $this->network['measurement_protocol_secret'] : '';
	}

	public function set_measurement_protocol_secret( $value ) {
		$this->merge_analytics_profile( array( 'measurement_protocol_secret' => $value ) );
	}

	public function set_network_measurement_protocol_secret( $value ) {
		$this->merge_network_analytics_profile( array( 'measurement_protocol_secret' => $value ) );
	}

	public function delete_manual_v4_id() {
		$profile = get_option( 'monsterinsights_site_profile', array() );
		if ( is_array( $profile ) && ! empty( $profile['manual_v4'] ) ) {
			unset( $profile['manual_v4'] );
			$this->set_analytics_profile( $profile );
		}
	}

	public function delete_network_manual_v4_id() {
		$network = get_site_option( 'monsterinsights_network_profile', array() );
		if ( is_array( $network ) && ! empty( $network['manual_v4'] ) ) {
			unset( $network['manual_v4'] );
			$this->set_network_analytics_profile( $network );
		}
	}

	public function get_manual_v4_id() {
		return ! empty( $this->profile['manual_v4'] ) ? monsterinsights_is_valid_v4_id( $this->profile['manual_v4'] ) : '';
	}

	public function get_network_manual_v4_id() {
		return ! empty( $this->network['manual_v4'] ) ? monsterinsights_is_valid_v4_id( $this->network['manual_v4'] ) : '';
	}

	public function get_v4_id() {
		return ! empty( $this->profile['v4'] ) ? monsterinsights_is_valid_v4_id( $this->profile['v4'] ) : '';
	}

	public function get_network_v4_id() {
		return ! empty( $this->network['v4'] ) ? monsterinsights_is_valid_v4_id( $this->network['v4'] ) : '';
	}

	public function get_site_hash() {
		return ! empty( $this->profile['site_hash'] ) ? $this->profile['site_hash'] : '';
	}

	public function get_network_site_hash() {
		return ! empty( $this->network['site_hash'] ) ? $this->network['site_hash'] : '';
	}

	public function get_viewname() {
		return ! empty( $this->profile['viewname'] ) ? $this->profile['viewname'] : '';
	}

	public function get_network_viewname() {
		return ! empty( $this->network['viewname'] ) ? $this->network['viewname'] : '';
	}

	public function get_accountid() {
		return ! empty( $this->profile['a'] ) ? $this->profile['a'] : '';
	}

	public function get_network_accountid() {
		return ! empty( $this->network['a'] ) ? $this->network['a'] : '';
	}

	public function get_propertyid() {
		return ! empty( $this->profile['w'] ) ? $this->profile['w'] : '';
	}

	public function get_network_propertyid() {
		return ! empty( $this->network['w'] ) ? $this->network['w'] : '';
	}

	public function get_viewid() { // also known as profileID
		return ! empty( $this->profile['p'] ) ? $this->profile['p'] : '';
	}

	public function get_network_viewid() { // also known as profileID
		return ! empty( $this->network['p'] ) ? $this->network['p'] : '';
	}

	public function get_key() {
		return ! empty( $this->profile['key'] ) ? $this->profile['key'] : '';
	}

	public function get_network_key() {
		return ! empty( $this->network['key'] ) ? $this->network['key'] : '';
	}

	public function get_token() {
		return ! empty( $this->profile['token'] ) ? $this->profile['token'] : '';
	}

	public function get_network_token() {
		return ! empty( $this->network['token'] ) ? $this->network['token'] : '';
	}

	public function get_referral_url() {
		$auth = MonsterInsights()->auth;

		if ( $this->is_authed() ) {
			$acc_id      = $auth->get_accountid();
			$view_id     = $auth->get_viewid();
			$property_id = $auth->get_propertyid();
		} else if ( $this->is_network_authed() ) {
			$acc_id      = $auth->get_network_accountid();
			$view_id     = $auth->get_network_viewid();
			$property_id = $auth->get_network_propertyid();
		}

		if ( ! empty( $acc_id ) && ! empty( $view_id ) && ! empty( $property_id ) ) {
			$format = 'p%2$s';

			return sprintf( $format, $acc_id, $property_id, $view_id );
		}

		return '';
	}
}
