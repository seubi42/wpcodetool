<?php

/**
 * Reports class.
 *
 * @since 6.0.0
 *
 * @package MonsterInsights
 * @subpackage Reports
 * @author  Chris Christoff
 */

// Exit if accessed directly
if (! defined('ABSPATH')) {
	exit;
}

function monsterinsights_reports_page_body_class($classes)
{
	if (! empty($_REQUEST['page']) && $_REQUEST['page'] === 'monsterinsights_reports') {
		$classes .= ' monsterinsights-reporting-page ';
	}

	return $classes;
}

add_filter('admin_body_class', 'monsterinsights_reports_page_body_class');

/**
 * Callback for getting all of the reports tabs for MonsterInsights.
 *
 * @return array Array of tab information.
 * @since 6.0.0
 * @access public
 *
 */
function monsterinsights_get_reports()
{
	/**
	 * Developer Alert:
	 *
	 * Per the README, this is considered an internal hook and should
	 * not be used by other developers. This hook's behavior may be modified
	 * or the hook may be removed at any time, without warning.
	 */
	$reports = apply_filters('monsterinsights_get_reports', array());

	return $reports;
}

/**
 * Whether the Vue 3 overview report page is registered in this context.
 *
 * monsterinsights_admin_menu() only adds `monsterinsights_overview_report` when
 * the menu hook resolves to the reports screen. With the `dashboards_disabled`
 * option set it resolves to the settings screen instead and that page is never
 * registered — while the `Insights` top level menu still points at the legacy
 * reports slug. Redirecting there regardless would swap one dead screen for
 * another: WordPress answers an unregistered page with "Sorry, you are not
 * allowed to access this page."
 *
 * @return bool True when the overview report page exists for this request.
 * @since 11.1.3
 */
function monsterinsights_overview_report_page_exists() {
	if ( ! function_exists( 'monsterinsights_get_menu_hook' ) ) {
		return false;
	}

	return 'monsterinsights_reports' === monsterinsights_get_menu_hook();
}

/**
 * The report screen URLs the dashboard widget links to.
 *
 * In "Dashboard Widget Only" mode — `dashboards_disabled` set to
 * `dashboard_widget` — the widget is the only MonsterInsights reporting screen
 * there is: the reports page is never registered and hands off to Settings.
 * Every CTA in the widget that points at a report is a dead end there, so this
 * returns empty URLs for that configuration.
 *
 * The widget's Vue components each hide themselves when their URL is empty
 * (`WidgetReportsLink`, `settings/WidgetFullReportButton`, and the per-report
 * "View Full Report" buttons by way of `utils/reportLinks.js`), so emptying the
 * URLs here is what removes the links.
 *
 * @return array {
 *     @type string $reports_url          Legacy reports screen, or '' when unreachable.
 *     @type string $overview_reports_url Vue 3 overview report screen, or '' when unreachable.
 * }
 * @since 11.1.4
 */
function monsterinsights_get_widget_report_urls() {
	if ( ! monsterinsights_overview_report_page_exists() ) {
		return array(
			'reports_url'          => '',
			'overview_reports_url' => '',
		);
	}

	return array(
		'reports_url'          => add_query_arg( 'page', 'monsterinsights_reports', admin_url( 'admin.php' ) ),
		'overview_reports_url' => add_query_arg( 'page', 'monsterinsights_overview_report', admin_url( 'admin.php' ) ),
	);
}

/**
 * The page slug the legacy reports screen should hand off to.
 *
 * Falls back to the settings screen when the overview report is not registered,
 * because that is the screen this configuration actually lands people on — see
 * monsterinsights_overview_report_page_exists().
 *
 * Returns an empty string when neither screen is available to this user, which
 * leaves the request where it is rather than moving it somewhere it would be
 * refused. A viewer — `monsterinsights_view_dashboard` without
 * `monsterinsights_save_settings` — on a site with dashboards disabled has no
 * MonsterInsights screen to be sent to, and swapping one refusal for another
 * helps nobody.
 *
 * @return string The target page slug, or an empty string to stay put.
 * @since 11.1.3
 */
function monsterinsights_get_legacy_reports_target_page() {
	if ( monsterinsights_overview_report_page_exists() ) {
		return 'monsterinsights_overview_report';
	}

	if ( current_user_can( 'monsterinsights_save_settings' ) ) {
		return 'monsterinsights_settings';
	}

	return '';
}

/**
 * Build the URL the legacy reports screen should redirect to.
 *
 * Kept separate from the redirect itself so the decision — which requests move
 * and what carries over with them — can be exercised without a live request.
 *
 * @param array  $query_args  The request's query arguments, i.e. $_GET.
 * @param string $target_page Page slug to hand off to. Defaults to the overview
 *                            report; callers pass the resolved slug so a site
 *                            without that page registered is not sent to it.
 *
 * @return string The redirect target, or an empty string to leave the request alone.
 * @since 11.1.3
 */
function monsterinsights_get_legacy_reports_redirect_url( $query_args, $target_page = 'monsterinsights_overview_report' ) {
	if ( ! is_array( $query_args ) || empty( $query_args['page'] ) ) {
		return '';
	}

	if ( 'monsterinsights_reports' !== $query_args['page'] ) {
		return '';
	}

	if ( ! is_string( $target_page ) || '' === $target_page || 'monsterinsights_reports' === $target_page ) {
		return '';
	}

	// Carry the rest of the query string over — notification links append their
	// own arguments and lose their effect if those are dropped here.
	$args = map_deep( $query_args, 'sanitize_text_field' );

	$args['page'] = $target_page;

	return add_query_arg( $args, admin_url( 'admin.php' ) );
}

/**
 * Redirect the legacy reports screen to the Vue 3 overview report page.
 *
 * This screen no longer enqueues an app of its own, so there is nothing to keep
 * a visitor here for. Runs before any output so a visitor never sees this page,
 * even with JavaScript unavailable. Browsers carry the original hash fragment
 * over to the redirect target, so deep links keep working; the Vue 3 router
 * resolves anything it does not recognise to the overview report.
 *
 * @return void
 * @since 11.1.3
 */
function monsterinsights_redirect_legacy_reports_page() {
	// The network screen registers this page under its own menu and has no
	// overview report of its own, so leave it to the inline script fallback.
	if ( wp_doing_ajax() || is_network_admin() ) {
		return;
	}

	// This runs on every admin request, so test the page with a plain string
	// comparison before doing anything that copies or walks the query string.
	// monsterinsights_get_legacy_reports_redirect_url() repeats the check so it
	// stays safe to call on its own.
	if ( ! isset( $_GET['page'] ) || 'monsterinsights_reports' !== $_GET['page'] ) { // phpcs:ignore WordPress.Security.NonceVerification
		return;
	}

	// Let WordPress render its own error when the user cannot view reports.
	if ( ! current_user_can( 'monsterinsights_view_dashboard' ) ) {
		return;
	}

	$target = monsterinsights_get_legacy_reports_redirect_url(
		wp_unslash( $_GET ), // phpcs:ignore WordPress.Security.NonceVerification
		monsterinsights_get_legacy_reports_target_page()
	);

	if ( '' === $target ) {
		return;
	}

	// Output already started, so headers cannot be sent. The inline script in
	// monsterinsights_reports_page() takes over from here.
	if ( headers_sent() ) {
		return;
	}

	wp_safe_redirect( $target );
	exit;
}

add_action( 'admin_init', 'monsterinsights_redirect_legacy_reports_page' );

/**
 * Callback to output the MonsterInsights reports page.
 *
 * @return void
 * @since 6.0.0
 * @access public
 *
 */
function monsterinsights_reports_page()
{
	// Fallback for the rare case monsterinsights_redirect_legacy_reports_page()
	// could not send headers, and for the network screen it skips. Redirect
	// every hash: this screen has no app of its own, and the Vue 3 router
	// resolves unknown paths to the overview report on its own — matching a
	// hard-coded route table here only stranded the hashes it did not list.
	// PHP cannot read the hash fragment, so it is carried over in JavaScript.
	//
	// In the network admin this is a behaviour change rather than a like-for-like
	// fallback: the old route table redirected an empty hash and stranded every
	// other one. There is no network-level report app to send anyone to, so both
	// cases now land on the main site's overview report — a working screen
	// instead of a dead one, at the cost of leaving the network admin.
	//
	// The target is resolved the same way there as anywhere else. In the network
	// admin `admin_url()` and `get_option()` both address the main site, so
	// monsterinsights_get_legacy_reports_target_page() reads the settings of the
	// very site it is about to send the visitor to — which is what decides
	// whether that site registered an overview report at all.
	$target_page = monsterinsights_get_legacy_reports_target_page();

	// No screen this user can be sent to, so stay put rather than bounce the
	// request into a page WordPress would refuse.
	if ( '' !== $target_page ) {
		$overview_url = add_query_arg( 'page', $target_page, admin_url( 'admin.php' ) );
		?>
		<script>
		(function () {
			var targetUrl = <?php echo wp_json_encode( $overview_url ); ?>;
			var hash = window.location.hash;

			if ( hash && hash !== '#' && hash !== '#/' ) {
				targetUrl += hash;
			}

			window.location.replace( targetUrl );
		})();
		</script>
		<?php
	}

	/**
	 * Developer Alert:
	 *
	 * Per the README, this is considered an internal hook and should
	 * not be used by other developers. This hook's behavior may be modified
	 * or the hook may be removed at any time, without warning.
	 */
	do_action('monsterinsights_head');

	// Show the same spinner as the report screen we are on the way to. The
	// "JavaScript didn't load" panel monsterinsights_settings_error_page()
	// reveals on a 2 second timer cannot be right here — this screen enqueues
	// no app for an ad blocker to block — and it sent customers looking for a
	// blocked script while all they were waiting on was this redirect.
	monsterinsights_app_loading_placeholder();
}
