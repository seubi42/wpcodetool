<?php
/**
 * MonsterInsights Onboarding Wizard
 *
 * @since 7.3
 *
 * @package MonsterInsights
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class MonsterInsights_Dashboard_Widget
 */
class MonsterInsights_Onboarding_Wizard_Pro {

	/**
	 * MonsterInsights_Onboarding_Wizard constructor.
	 */
	public function __construct() {

		add_action( 'admin_init', array( $this, 'maybe_load_onboarding_wizard' ) );

		add_action( 'admin_menu', array( $this, 'add_dashboard_page' ) );
		add_action( 'network_admin_menu', array( $this, 'add_dashboard_page' ) );

		add_action( 'wp_ajax_monsterinsights_onboarding_wpforms_install', array(
			$this,
			'install_and_activate_wpforms',
		) );

		add_action( 'wp_ajax_monsterinsights_onboarding_get_errors', array(
			$this,
			'get_install_errors',
		) );

		add_action( 'wp_ajax_nopriv_onboarding_monsterinsights_onboarding_get_errors', array(
			$this,
			'onboarding_get_install_errors',
		) );

		add_action( 'monsterinsights_after_ajax_activate_addon', array( $this, 'disable_aioseo_onboarding_wizard' ) );
		add_action( 'monsterinsights_after_ajax_activate_addon', array( $this, 'disable_wpforms_onboarding_wizard' ) );
		add_action( 'monsterinsights_after_ajax_activate_addon', array( $this, 'disable_optin_monster_onboarding_wizard' ) );

		// This will only be called in the Onboarding Wizard context because of previous checks.
		add_filter( 'monsterinsights_maybe_authenticate_siteurl', array( $this, 'change_return_url' ) );
		add_filter( 'monsterinsights_auth_success_redirect_url', array( $this, 'change_success_url' ) );
		add_filter( 'monsterinsights_reauth_success_redirect_url', array( $this, 'change_success_url' ) );

	}

	/**
	 * Redirects legacy `?page=monsterinsights-onboarding` requests to the
	 * externally-hosted setup wizard. The bundled Vue 2 wizard UI was retired
	 * during the Vue 3 migration; the external URL returned by
	 * `monsterinsights_get_onboarding_url()` is now the only setup flow.
	 */
	public function maybe_load_onboarding_wizard() {

		// Check if the page is not set, or if it's not the onboarding wizard page
		if ( empty( $_GET['page'] ) || 'monsterinsights-onboarding' !== $_GET['page'] ) {
				return;
		}

		// Check if the current user is allowed to save settings
		if ( ! current_user_can( 'monsterinsights_save_settings' ) ) {
				return;
		}

		// Don't redirect during AJAX requests.
		if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) {
			return;
		}

		// Use `wp_redirect()` (not `wp_safe_redirect()`) because the onboarding
		// URL points to a different host (e.g. connect.monsterinsights.com).
		wp_redirect( esc_url_raw( monsterinsights_get_onboarding_url() ) );
		exit;

	}

	/**
	 * Register page through WordPress's hooks.
	 *
	 * The slug is kept registered so existing links (in dashboard widgets,
	 * notices, etc.) still resolve and trigger the redirect above. The page
	 * itself never renders — `maybe_load_onboarding_wizard()` short-circuits it
	 * before WordPress draws the admin shell.
	 */
	public function add_dashboard_page() {
		add_dashboard_page( '', '', 'monsterinsights_save_settings', 'monsterinsights-onboarding', '' );
	}

	/**
	 * Check if we should suggest the EU Compliance addon by attempting to determine the website's location.
	 *
	 * @return bool
	 */
	public function should_include_eu_addon() {

		// Is WooCommerce installed and the countries class installed.
		if ( class_exists( 'WooCommerce' ) && class_exists( 'WC_Countries' ) && method_exists( 'WC_Countries', 'get_continent_code_for_country' ) ) {
			$wc_countries = new WC_Countries();
			$country      = $wc_countries->get_base_country();
			$continent    = $wc_countries->get_continent_code_for_country( $country );

			if ( 'EU' === $continent ) {
				return true;
			}
		}

		// Is EDD installed?
		if ( class_exists( 'Easy_Digital_Downloads' ) && function_exists( 'edd_get_shop_country' ) ) {
			$country      = strtoupper( edd_get_shop_country() );
			$eu_countries = self::get_eu_countries();

			// Check if the country code is in the list of EU countries we have stored.
			if ( in_array( $country, $eu_countries, true ) ) {
				return true;
			}
		}

		// If no store installed, check the timezone setting.
		$timezone_string = get_option( 'timezone_string' );
		if ( 0 === strpos( strtolower( $timezone_string ), 'europe' ) ) {
			// If the timezone is set to Europe, assume the website is based in Europe.
			return true;
		}

		return false;

	}

	/**
	 * Install WPForms lite and activate it, prevent initial setup step.
	 *
	 * @return null|string
	 */
	public function install_and_activate_wpforms() {

		check_ajax_referer( 'monsterinsights-install', 'nonce' );

		if ( ! monsterinsights_can_install_plugins() ) {
			wp_send_json( array(
				'message' => esc_html__( 'You are not allowed to install plugins', 'google-analytics-premium' ),
			) );
		}

		include_once ABSPATH . 'wp-admin/includes/plugin-install.php';

		$api = plugins_api( 'plugin_information', array(
			'slug'   => 'wpforms-lite',
			'fields' => array(
				'short_description' => false,
				'sections'          => false,
				'requires'          => false,
				'rating'            => false,
				'ratings'           => false,
				'downloaded'        => false,
				'last_updated'      => false,
				'added'             => false,
				'tags'              => false,
				'compatibility'     => false,
				'homepage'          => false,
				'donate_link'       => false,
			),
		) );

		if ( is_wp_error( $api ) ) {
			return $api->get_error_message();
		}

		$download_url = $api->download_link;

		$method = '';
		$url    = is_network_admin() ? add_query_arg( 'page', 'monsterinsights_network', network_admin_url( 'admin.php' ) ) : add_query_arg( 'page', 'monsterinsights_settings', admin_url( 'admin.php' ) );
		$url    = esc_url( $url );

		ob_start();
		if ( false === ( $creds = request_filesystem_credentials( $url, $method, false, false, null ) ) ) {
			$form = ob_get_clean();

			wp_send_json( array( 'form' => $form ) );
		}

		// If we are not authenticated, make it happen now.
		if ( ! WP_Filesystem( $creds ) ) {
			ob_start();
			request_filesystem_credentials( $url, $method, true, false, null );
			$form = ob_get_clean();

			wp_send_json( array( 'form' => $form ) );

		}

		// We do not need any extra credentials if we have gotten this far, so let's install the plugin.
		monsterinsights_require_upgrader( false );

		// Create the plugin upgrader with our custom skin.
		$installer = new Plugin_Upgrader( new MonsterInsights_Skin() );
		$installer->install( $download_url );

		// Flush the cache and return the newly installed plugin basename.
		wp_cache_flush();
		if ( $installer->plugin_info() ) {
			// Set this option to prevent WP Forms setup from showing up after the wizard completes.
			update_option( 'wpforms_activation_redirect', true );
			activate_plugin( $installer->plugin_info() );
			// The option above only covers WPForms' legacy Welcome redirect. WPForms 2.x
			// launches its Setup Wizard from a separate signal that has to be disarmed too.
			monsterinsights_suppress_wpforms_first_run( $installer->plugin_info() );
			wp_send_json_success();
		}

		wp_die();

	}

	/**
	 * Update the redirect url so the user returns to the Onboarding Wizard after auth.
	 *
	 * @param string $siteurl The url to which the user is redirected for auth.
	 *
	 * @return mixed
	 */
	public function change_return_url( $siteurl ) {

		$url       = wp_parse_url( $siteurl );
		$admin_url = is_network_admin() ? network_admin_url() : admin_url();

		if ( isset( $url['query'] ) ) {

			parse_str( $url['query'], $parameters );

			$parameters['return'] = rawurlencode( add_query_arg( array(
				'page' => 'monsterinsights-onboarding',
			), $admin_url ) );

			$siteurl = str_replace( $url['query'], '', $siteurl );

			$siteurl = add_query_arg( $parameters, $siteurl );

			$siteurl .= '#/authenticate';

		}

		return $siteurl;

	}

	/**
	 * Update the success redirect URL so if all is well we get to the next step.
	 *
	 * @param string $siteurl The url to which the user is redirected after a successful auth.
	 *
	 * @return mixed
	 */
	public function change_success_url( $siteurl ) {
		$admin_url   = is_network_admin() ? network_admin_url() : admin_url();
		$return_step = is_network_admin() ? 'recommended_addons' : 'recommended_settings';

		$siteurl = add_query_arg( array(
			'page' => 'monsterinsights-onboarding',
		), $admin_url );

		$siteurl .= '#/' . $return_step;

		return $siteurl;

	}

	/**
	 * Retrieve an array of European countries.
	 *
	 * @return array
	 */
	public static function get_eu_countries() {
		return array(
			'AD',
			'AL',
			'AT',
			'AX',
			'BA',
			'BE',
			'BG',
			'BY',
			'CH',
			'CY',
			'CZ',
			'DE',
			'DK',
			'EE',
			'ES',
			'FI',
			'FO',
			'FR',
			'GB',
			'GG',
			'GI',
			'GR',
			'HR',
			'HU',
			'IE',
			'IM',
			'IS',
			'IT',
			'JE',
			'LI',
			'LT',
			'LU',
			'LV',
			'MC',
			'MD',
			'ME',
			'MK',
			'MT',
			'NL',
			'NO',
			'PL',
			'PT',
			'RO',
			'RS',
			'RU',
			'SE',
			'SI',
			'SJ',
			'SK',
			'SM',
			'TR',
			'UA',
			'VA',
		);
	}

	/**
	 * Ajax handler for grabbing the installed code status.
	 */
	public function get_install_errors() {

		check_ajax_referer( 'mi-admin-nonce', 'nonce' );

		if ( ! current_user_can( 'monsterinsights_save_settings' ) ) {
			wp_send_json_error();
		}

		wp_send_json( monsterinsights_is_code_installed_frontend() );
	}

	public function onboarding_get_install_errors() {
		check_ajax_referer( 'onboarding', 'nonce' );
		wp_send_json( monsterinsights_is_code_installed_frontend() );
	}

	public function disable_aioseo_onboarding_wizard( $plugin ) {
		if ( empty( $plugin ) ) {
			return;
		}

		if ( 'all-in-one-seo-pack/all_in_one_seo_pack.php' !== $plugin ) {
			return;
		}

		update_option( 'aioseo_activation_redirect', true );
	}

	public function disable_wpforms_onboarding_wizard( $plugin ) {
		if ( empty( $plugin ) ) {
			return;
		}

		if ( 'wpforms-lite/wpforms.php' !== $plugin ) {
			return;
		}

		if ( false === get_transient( 'wpforms_activation_redirect' ) ) {
			return;
		}

		delete_transient( 'wpforms_activation_redirect' );
	}

	public function disable_optin_monster_onboarding_wizard( $plugin ) {
		if ( empty( $plugin ) ) {
			return;
		}

		if ( 'optinmonster/optin-monster-wp-api.php' !== $plugin ) {
			return;
		}

		if ( false === get_transient( 'optin_monster_api_activation_redirect' ) ){
			return;
		}

		delete_transient( 'optin_monster_api_activation_redirect' );
	}

}

new MonsterInsights_Onboarding_Wizard_Pro();
