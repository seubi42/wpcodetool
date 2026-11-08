<?php
/**
 * Capabilities class.
 *
 * @access public
 * @since 6.0.0
 *
 * @package MonsterInsights
 * @subpackage Capabilities
 * @author  Chris Christoff
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Map MonsterInsights Capabilities.
 *
 * Using meta caps, we're creating virtual capabilities that are
 * for backwards compatibility reasons given to users with manage_options, and to
 * users who have at least of the roles selected in the options on the permissions
 * tab of the MonsterInsights settings.
 *
 * @access public
 *
 * @param array $caps Array of capabilities the user has.
 * @param string $cap The current cap being filtered.
 * @param int $user_id User to check permissions for.
 * @param array $args Extra parameters. Unused.
 *
 * @return array Array of caps needed to have this meta cap. If returned array is empty, user has the capability.
 * @since 6.0.0
 *
 */
function monsterinsights_add_capabilities( $caps, $cap, $user_id, $args ) {

	switch ( $cap ) {
		case 'monsterinsights_view_dashboard' :
			$roles = monsterinsights_get_option( 'view_reports', array() );

			$user_can_via_settings = false;
			if ( ! empty( $roles ) && is_array( $roles ) ) {
				foreach ( $roles as $role ) {
					if ( is_string( $role ) ) {
						if ( user_can( $user_id, $role ) ) {
							$user_can_via_settings = true;
							break;
						}
					}
				}
			} else if ( ! empty( $roles ) && is_string( $roles ) ) {
				if ( user_can( $user_id, $roles ) ) {
					$user_can_via_settings = true;
				}
			}

			if ( user_can( $user_id, 'manage_options' ) || $user_can_via_settings ) {
				$caps = array();
			}

			break;
		case 'monsterinsights_save_settings' :
			$roles = monsterinsights_get_option( 'save_settings', array() );

			$user_can_via_settings = false;
			if ( ! empty( $roles ) && is_array( $roles ) ) {
				foreach ( $roles as $role ) {
					if ( is_string( $role ) ) {
						if ( user_can( $user_id, $role ) ) {
							$user_can_via_settings = true;
							break;
						}
					}
				}
			} else if ( ! empty( $roles ) && is_string( $roles ) ) {
				if ( user_can( $user_id, $roles ) ) {
					$user_can_via_settings = true;
				}
			}

			if ( user_can( $user_id, 'manage_options' ) || $user_can_via_settings ) {
				$caps = array();
			}

			break;
	}

	return $caps;
}

add_filter( 'map_meta_cap', 'monsterinsights_add_capabilities', 10, 4 );

/**
 * Get the list of settings that only users with manage_options can modify.
 *
 * These are access-control settings that, if modified by a delegated user,
 * could lead to privilege escalation.
 *
 * @since 9.5.2
 *
 * @return array Array of admin-only setting keys.
 */
function monsterinsights_get_admin_only_settings() {
	$settings = array(
		'save_settings',
		'view_reports',
		'ignore_users',
	);

	return apply_filters( 'monsterinsights_admin_only_settings', $settings );
}

/**
 * Check if a given setting key is an admin-only setting.
 *
 * @since 9.5.2
 *
 * @param string $setting The setting key to check.
 *
 * @return bool True if the setting is admin-only, false otherwise.
 */
function monsterinsights_is_admin_only_setting( $setting ) {
	return in_array( $setting, monsterinsights_get_admin_only_settings(), true );
}

/**
 * Get the list of settings that are only readable by users who can save settings.
 *
 * Every setting lives in one option, so this list marks the keys that only the
 * settings screens consume. Keep it to values that are secret, personal, or that
 * describe who holds access — not to settings a view-only user merely has no use for.
 *
 * Add-ons that store credentials in the shared option should append their keys
 * through the `monsterinsights_sensitive_settings` filter.
 *
 * @since 11.1.3
 *
 * @return array Array of setting keys to withhold from users without save access.
 */
function monsterinsights_get_sensitive_settings() {
	$settings = array(
		// Third-party API credentials.
		'ads_meta_api_access_token',
		'ads_pinterest_api_token',
		'ads_snapchat_api_token',
		'gtag_selector_tracking_mp',
		'sharedcount_key',
		// Recipient addresses.
		'summaries_email_addresses',
		'exception_alert_email_addresses',
	);

	// Access-control lists read at the same tier they are written at.
	$settings = array_merge( $settings, monsterinsights_get_admin_only_settings() );

	return (array) apply_filters( 'monsterinsights_sensitive_settings', $settings );
}
