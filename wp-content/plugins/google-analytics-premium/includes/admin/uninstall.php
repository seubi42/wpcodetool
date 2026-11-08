<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Removes only the report-view telemetry options this PR introduced.
 *
 * ExactMetrics shares its option names with MonsterInsights, and its
 * uninstall hooks can fire while a MonsterInsights install is still live on
 * the same site (e.g. EM installed-but-inactive, then deleted). Calling the
 * full monsterinsights_uninstall_remove_options() from EM's hooks would wipe
 * that live MI install's db_version, license-adjacent, and display options.
 * Scoped to just the two options EM's own recording code writes.
 *
 * @since 11.2.0
 */
function monsterinsights_uninstall_remove_report_view_telemetry_options() {
	delete_option( 'monsterinsights_report_views' );
	delete_option( 'monsterinsights_last_admin_seen' );
}

/**
 * Remove various options used in the plugin.
 */
function monsterinsights_uninstall_remove_options() {

	// Remove usage tracking options.
	delete_option( 'monsterinsights_usage_tracking_config' );
	delete_option( 'monsterinsights_usage_tracking_last_checkin' );

	// Remove version options.
	delete_option( 'monsterinsights_db_version' );
	delete_option( 'monsterinsights_version_upgraded_from' );

	// Remove other options used for display.
	delete_option( 'monsterinsights_email_summaries_infoblocks_sent' );
	delete_option( 'monsterinsights_float_bar_hidden' );
	delete_option( 'monsterinsights_frontend_tracking_notice_viewed' );
	delete_option( 'monsterinsights_admin_menu_tooltip' );
	delete_option( 'monsterinsights_review' );

	// Remove Ads addon-notice dismissal option (and any legacy transient from
	// before the option-based persistence).
	delete_option( 'monsterinsights_ads_addon_installed_notice_dismissed' );
	delete_transient( 'monsterinsights_ads_addon_installed_notice_dismissed' );

	// Delete addons transient.
	delete_transient( 'monsterinsights_addons' );
	monsterinsights_uninstall_remove_report_view_telemetry_options();

}
