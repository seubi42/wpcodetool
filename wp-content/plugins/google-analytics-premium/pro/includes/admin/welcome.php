<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}


/**
 * Class MonsterInsights_Welcome
 */
class MonsterInsights_Welcome_Pro {

	/**
	 * MonsterInsights_Welcome constructor.
	 */
	public function __construct() {

		// If we are not in admin or admin ajax, return
		if ( ! is_admin() ) {
			return;
		}

		// If user is in admin ajax or doing cron, return
		if ( ( defined( 'DOING_AJAX' ) && DOING_AJAX ) || ( defined( 'DOING_CRON' ) && DOING_CRON ) ) {
			return;
		}

		// If user is not logged in, return
		if ( ! is_user_logged_in() ) {
			return;
		}

		// If user cannot manage_options, return
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		add_action( 'admin_init', array( $this, 'maybe_redirect' ), 9999 );

	}

	/**
	 * Auto-redirect first-time activations to the externally-hosted setup
	 * wizard.
	 *
	 * The bundled Vue 2 welcome screen and `monsterinsights-getting-started`
	 * dashboard page were retired during the Vue 3 migration. Net-new sites
	 * still benefit from being walked through setup, so we keep the
	 * activation transient and just send users straight to
	 * `monsterinsights_get_onboarding_url()`.
	 */
	public function maybe_redirect() {

		// Bail if no activation redirect.
		if ( ! get_transient( '_monsterinsights_activation_redirect' ) || isset( $_GET['monsterinsights-redirect'] ) ) {
			return;
		}

		// Delete the redirect transient.
		delete_transient( '_monsterinsights_activation_redirect' );

		// Bail if activating from network, or bulk.
		if ( isset( $_GET['activate-multi'] ) ) { // WPCS: CSRF ok, input var ok.
			return;
		}

		$upgrade            = get_option( 'monsterinsights_version_upgraded_from', false );
		$skip_wizard        = get_option( 'monsterinsights_skip_wizard', false );
		// If this is an upgrade (a version_from is present) or skip_wizard is set, skip the redirect.
		$run_wizard         = ! ( $skip_wizard || false !== $upgrade );
		$do_redirect        = apply_filters( 'monsterinsights_enable_onboarding_wizard', $run_wizard );
		if ( $do_redirect ) {
			// Use `wp_redirect()` (not `wp_safe_redirect()`) because the
			// onboarding URL points to a different host
			// (e.g. connect.monsterinsights.com).
			wp_redirect( esc_url_raw( monsterinsights_get_onboarding_url() ) );
			exit;
		}
	}
}

new MonsterInsights_Welcome_Pro();
