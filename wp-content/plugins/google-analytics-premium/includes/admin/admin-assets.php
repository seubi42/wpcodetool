<?php
/**
 * WP ADMIN assets will be enqueued here.
 *
 * @package monsterinsights
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class MonsterInsights_Admin_Assets
 * This class is responsible for load CSS and JS in admin panel.
 */
class MonsterInsights_Admin_Assets {
	/**
	 * Vue 3 entry-point script handles. Used by `set_scripts_as_type_module()`
	 * and `script_loader_tag()` to flag these <script> tags as `type="module"`.
	 */
	private $own_handles = array(
		'monsterinsights-vue3-custom-dashboard',
		'monsterinsights-vue3-reports',
		'monsterinsights-vue3-settings',
		'monsterinsights-vue3-settings-network',
		'monsterinsights-vue3-widget',
	);

	/**
	 * Directory path of assets.
	 */
	private $version_path;

	/**
	 * Store Vue 3 manifest.json file content.
	 *
	 * @var array
	 */
	private static $manifest_data_v3;

	/**
	 * Class constructor.
	 */
	public function __construct() {
		global $wp_version;
		// The wp_script_attributes filter was introduced in WP 6.4. Fall back to script_loader_tag on older versions.
		if ( version_compare( $wp_version, '6.4', '>=' ) ) {
			add_filter( 'wp_script_attributes', array( $this, 'set_scripts_as_type_module' ), 99999 );
		} else {
			add_filter( 'script_loader_tag', array( $this, 'script_loader_tag' ), 99999, 3 );
		}

		add_action( 'admin_enqueue_scripts', array( $this, 'admin_styles' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'admin_scripts' ) );

		// CSS files path.
		$this->version_path = monsterinsights_is_pro_version() ? 'pro' : 'lite';

		$this->get_manifest_data_v3();
	}

	/**
	 * Updates the script type for the plugin's handles to type module.
	 *
	 * @param array $attrs Key-value pairs representing <script> tag attributes.
	 * @return array $attrs
	 */
	public function set_scripts_as_type_module( $attrs ) {
		if ( isset( $attrs['id'] ) && in_array( str_replace( '-js', '', $attrs['id'] ), $this->own_handles, true ) ) {
			$attrs['type'] = 'module';
		}
		return $attrs;
	}

	/**
	 * Update script tag for WP < 6.4 — Vue code needs type=module.
	 */
	public function script_loader_tag( $tag, $handle, $src ) {

		if ( ! in_array( $handle, $this->own_handles, true ) ) {
			return $tag;
		}

		return str_replace( '></script>', ' type="module"></script>', $tag );
	}

	/**
	 * Loads styles for all MonsterInsights-based Administration Screens.
	 *
	 * @return null Return early if not on the proper screen.
	 */
	public function admin_styles() {

		$suffix = ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ) ? '' : '.min';

		// Load Common admin styles. Includes the WPConsent admin notice CSS
		// that previously lived in the Vue 2 admin entry build
		// (`vue/css/admin.css`) and is shown on every WP admin page.
		wp_register_style( 'monsterinsights-admin-common-style', plugins_url( 'assets/css/admin-common' . $suffix . '.css', MONSTERINSIGHTS_PLUGIN_FILE ), array(), monsterinsights_get_asset_version() );
		wp_enqueue_style( 'monsterinsights-admin-common-style' );

		// Get current screen.
		$screen = get_current_screen();

		// Bail if we're not on a MonsterInsights screen.
		if ( empty( $screen->id ) || strpos( $screen->id, 'monsterinsights' ) === false ) {
			return;
		}

		// Enqueue Vue 3 CSS for the current admin page. In dev mode Vite injects
		// CSS via JS, so we skip manual enqueues there.
		if ( ! defined( 'MONSTERINSIGHTS_V3_DEV_URL' ) || ! MONSTERINSIGHTS_V3_DEV_URL ) {
			$page      = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
			$entry_key = $this->get_vue3_entry_key( $page );
			$this->enqueue_vue3_entry_css( $entry_key );
		}
	}

	/**
	 * Loads scripts for all MonsterInsights-based Administration Screens.
	 *
	 * @return null Return early if not on the proper screen.
	 */
	public function admin_scripts() {

		// Our Common Admin JS.
		$suffix = ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ) ? '' : '.min';

		wp_enqueue_script( 'monsterinsights-admin-common-script', plugins_url( 'assets/js/admin-common' . $suffix . '.js', MONSTERINSIGHTS_PLUGIN_FILE ), array( 'jquery' ), monsterinsights_get_asset_version(), true );

		wp_localize_script(
			'monsterinsights-admin-common-script',
			'monsterinsights_admin_common',
			array(
				'ajax'                 => admin_url( 'admin-ajax.php' ),
				'dismiss_notice_nonce' => wp_create_nonce( 'monsterinsights-dismiss-notice' ),
			)
		);

		// Flush Vue 3 localStorage cache registry if flagged (e.g. after Google re-auth).
		if ( get_transient( 'monsterinsights_flush_cache_registry' ) ) {
			delete_transient( 'monsterinsights_flush_cache_registry' );
			wp_add_inline_script(
				'monsterinsights-admin-common-script',
				'try{localStorage.removeItem("mi_cache_registry")}catch(e){}',
				'before'
			);
		}

		// Load setup wizard handler script for all admin pages where the setup wizard link might appear
		// This includes MonsterInsights pages and any admin page where the setup notice might show
		wp_enqueue_script( 'monsterinsights-admin-setup-wizard', plugins_url( 'assets/js/admin-setup-wizard.js', MONSTERINSIGHTS_PLUGIN_FILE ), array( 'jquery' ), monsterinsights_get_asset_version(), true );

		wp_localize_script(
			'monsterinsights-admin-setup-wizard',
			'monsterinsights',
			array(
				'ajax'       => admin_url( 'admin-ajax.php' ),
				'nonce'      => wp_create_nonce( 'mi-admin-nonce' ),
				// Pre-generate the onboarding URL at render time so the launch link
				// navigates instantly without an admin-ajax round-trip. The handler
				// keeps this fresh via a background refresh (see admin-setup-wizard.js).
				'wizard_url' => monsterinsights_can_install_plugins() ? monsterinsights_get_onboarding_url() : '',
			)
		);

		// Get current screen.
		$screen = get_current_screen();

		// Bail if we're not on a MonsterInsights screen for other scripts.
		if ( empty( $screen->id ) || strpos( $screen->id, 'monsterinsights' ) === false ) {
			return;
		}

		$version_path = monsterinsights_is_pro_version() ? 'pro' : 'lite';
		$text_domain  = monsterinsights_get_plugin_textdomain();

		$license      = MonsterInsights()->license;
		$license_info = array(
			'type'      => $license->get_license_type(),
			'is_agency' => $license->is_agency(),
		);

		// Pro-only license fields (methods don't exist in Lite's MonsterInsights_License_Compat)
		if ( monsterinsights_is_pro_version() ) {
			// The license key is only needed by the capability-gated settings/license
			// screens. Keep it out of the bootstrap for view-only report delegates.
			$license_info['key']         = current_user_can( 'monsterinsights_save_settings' ) ? $license->get_site_license_key() : '';
			// `type` above comes from get_license_type(), which returns the network
			// license when using_network_license() — so these flags must describe the
			// same license, or a network-licensed subsite reports the state of its
			// absent site license (GH-3342). get_license_expiry_date() below is
			// already network-aware for the same reason.
			$using_network               = $license->using_network_license();
			$license_info['is_expired']  = $using_network ? $license->network_license_expired() : $license->site_license_expired();
			$license_info['is_disabled'] = $using_network ? $license->network_license_disabled() : $license->site_license_disabled();
			$license_info['is_invalid']  = $using_network ? $license->network_license_invalid() : $license->site_license_invalid();
			$license_info['expiry_date'] = $license->get_license_expiry_date();
		}

		// Multisite needs the network license alongside the site one: the Pinia store
		// reads `license_network` at first paint, and without it a network-licensed
		// install renders as unlicensed until fetchLicenseData() resolves (GH-3342).
		$license_network_info = array();
		if ( monsterinsights_is_pro_version() && is_multisite() ) {
			$license_network_info = array(
				// Capability-gated exactly as $license_info['key'] above.
				'key'         => current_user_can( 'monsterinsights_save_settings' ) ? $license->get_network_license_key() : '',
				'type'        => $license->get_network_license_type(),
				'is_agency'   => $license->network_is_agency(),
				'is_expired'  => $license->network_license_expired(),
				'is_disabled' => $license->network_license_disabled(),
				'is_invalid'  => $license->network_license_invalid(),
				// Mirrors $license_info's shape so the network object is a complete
				// license, not a partial one: the renewal row keys off expiry_date and
				// the license-type label off is_agency, and both were absent at first
				// paint. Network-scoped variants of the same getters used above.
				'expiry_date' => $license->get_network_license_expiry_date(),
			);
		}

		// Get auth data (shared across Vue 2 and Vue 3 apps)
		$auth      = MonsterInsights()->auth;
		// The measurement protocol secret is only consumed by the capability-gated
		// settings/authenticate screens; keep it out of the bootstrap for view-only
		// report delegates.
		$can_manage_secrets = current_user_can( 'monsterinsights_save_settings' );
		$auth_data          = array(
			'v4'                                  => $auth->get_v4_id(),
			'network_v4'                          => is_multisite() ? $auth->get_network_v4_id() : '',
			'manual_v4'                           => $auth->get_manual_v4_id(),
			'network_manual_v4'                   => is_multisite() ? $auth->get_network_manual_v4_id() : '',
			'viewname'                            => $auth->get_viewname(),
			'network_viewname'                    => is_multisite() ? $auth->get_network_viewname() : '',
			'measurement_protocol_secret'         => $can_manage_secrets ? $auth->get_measurement_protocol_secret() : '',
			'network_measurement_protocol_secret' => ( $can_manage_secrets && is_multisite() ) ? $auth->get_network_measurement_protocol_secret() : '',
			// Whether the profile actually holds the credentials a Reporting API call
			// needs (key + v4), per scope. A profile can keep `v4`/`viewname` and lose
			// its key/token, which looks connected but fails every report request, so
			// the app must be able to tell the two states apart.
			'has_credentials'                     => $auth->is_authed(),
			'network_has_credentials'             => is_multisite() ? $auth->is_network_authed() : false,
		);

		// Route to the appropriate Vue 3 entry based on the current admin page.
		if ( strpos( $screen->id, 'monsterinsights_overview_report' ) !== false ) {
			// Reports use this to explain empty date ranges that predate the
			// connection instead of telling the user to widen a range that can
			// never return data. `monsterinsights_over_time` is autoload=false and
			// only the reports need it, so it is read here rather than for every
			// MonsterInsights admin screen.
			$auth_data['connected_date'] = monsterinsights_get_connection_date();

			$this->load_vue3_report_script( $auth, $auth_data, $license_info, $version_path );
			return;
		}

		if ( strpos( $screen->id, 'monsterinsights_settings' ) !== false ) {
			$this->load_vue3_settings_script( $auth, $auth_data, $license_info, $license_network_info, $version_path );
			return;
		}

		// Multisite Network-Admin settings screen (page=monsterinsights_network).
		// Shares the settings localization but loads the reduced network entry.
		if ( strpos( $screen->id, 'monsterinsights_network' ) !== false ) {
			$this->load_vue3_settings_script( $auth, $auth_data, $license_info, $license_network_info, $version_path, 'src/modules/settings/main-network.js', 'monsterinsights-vue3-settings-network' );
			return;
		}

		// Custom Dashboard is enqueued inline below for now; the other Vue 3
		// entries have dedicated `load_vue3_*_script()` helpers.
		if ( strpos( $screen->id, 'monsterinsights_custom_dashboard' ) !== false || strpos( $screen->id, 'monsterinsights-custom-dashboards' ) !== false ) {
			$handle = 'monsterinsights-vue3-custom-dashboard';

			if ( defined( 'MONSTERINSIGHTS_V3_DEV_URL' ) && MONSTERINSIGHTS_V3_DEV_URL ) {
				$dev_url = trailingslashit( MONSTERINSIGHTS_V3_DEV_URL ) . 'src/modules/custom-dashboard/main.js';
				wp_register_script( $handle, $dev_url, array( 'wp-i18n', 'wp-util' ), monsterinsights_get_asset_version(), true );
				wp_enqueue_script( $handle );
			} else {
				list( $base_url, $entry ) = $this->get_vue3_entry( 'src/modules/custom-dashboard/main.js' );
				if ( ! empty( $entry['file'] ) ) {
					$src = $base_url . ltrim( $entry['file'], '/' );
					wp_register_script( $handle, $src, array( 'wp-i18n', 'wp-util' ), monsterinsights_get_asset_version(), true );
					wp_enqueue_script( $handle );
				}
			}

			// Provide bootstrap payload for the Vue 3 app in build
			// Base "authed" on real credentials (key + v4), not just the property name,
			// so a profile that lost its key/token is reported as not connected.
			$site_auth = $auth->is_authed();
			$ms_auth   = is_multisite() && $auth->is_network_authed();

			// Get bearer token for direct browser-to-API requests.
			$bearer_token_data = MonsterInsights_API_Token::get_token( is_network_admin() );
			$bearer_token      = '';
			$bearer_expires    = 0;
			if ( ! is_wp_error( $bearer_token_data ) ) {
				$bearer_token   = $bearer_token_data['token'];
				$bearer_expires = $bearer_token_data['expires_at'];
			}

			wp_localize_script(
				$handle,
				'monsterinsights',
				apply_filters( 'monsterinsights_localize_script_data', array(
					'ajax'                 => admin_url( 'admin-ajax.php' ),
					'assets_url'           => apply_filters( 'monsterinsights_vue3_assets_url', plugins_url( $version_path . '/assets/vue3', MONSTERINSIGHTS_PLUGIN_FILE ) ),
					'plugin_assets_url'    => plugins_url( 'assets/', MONSTERINSIGHTS_PLUGIN_FILE ),
					'nonce'                => wp_create_nonce( 'mi-admin-nonce' ),
					'cd_nonce'             => wp_create_nonce( 'mi_custom_dashboard_ajax_nonce' ), // Custom Dashboard nonce
					'network'              => is_network_admin(),
					'custom_dashboard_url' => add_query_arg( 'page', 'monsterinsights_custom_dashboard', admin_url( 'admin.php' ) ),
					'license'              => $license_info,
					'auth'                 => $auth_data,
					'authed'               => $site_auth || $ms_auth, // Boolean auth flag (used by settings tools/Google Ads views via getMiGlobal)
					'plugin_version'       => MONSTERINSIGHTS_VERSION,
					'wizard_url'           => monsterinsights_can_install_plugins() ? monsterinsights_get_onboarding_url() : '',
					'rest_url'             => get_rest_url(),
					'rest_nonce'           => wp_create_nonce( 'wp_rest' ),
					// Direct API access (bypasses WordPress for performance).
					'relay_api_url'        => apply_filters( 'monsterinsights_api_url_custom_dashboard', 'https://app.monsterinsights.com/' ),
					'bearer_token'         => $bearer_token,
					'bearer_expires'       => $bearer_expires,
					// Sample data mode: when true, frontend should bypass direct API and use WP AJAX for sample data.
					'sample_data_enabled'  => apply_filters( 'monsterinsights_sample_data_enabled', false ),
					'can_view_reports'     => current_user_can( 'monsterinsights_view_dashboard' ),
					'update_settings'      => current_user_can( 'monsterinsights_save_settings' ),
					// eCommerce store currency for key-metric value formatting; without
					// this consumers fall back to USD (getMiGlobal('currency', 'USD')).
					'currency'             => monsterinsights_get_ecommerce_currency(),
				) )
			);

			// Load translations for Vue 3 app using WordPress's script translation system
			wp_set_script_translations( $handle, 'google-analytics-premium' );

			return;
		}
	}

	/**
	 * Resolve the Vue 3 manifest path.
	 *
	 * The minified build writes manifest.json (production); the unminified build
	 * writes manifest.dev.json. Because the two builds hash their output
	 * differently, the debug filenames can't be derived from the production
	 * names — so under SCRIPT_DEBUG we read manifest.dev.json directly, falling
	 * back to manifest.json when the unminified build isn't present.
	 *
	 * @return string Absolute path to the manifest file to read.
	 */
	private static function get_vue3_manifest_path() {
		$version_path = monsterinsights_is_pro_version() ? 'pro' : 'lite';
		$plugin_path  = plugin_dir_path( MONSTERINSIGHTS_PLUGIN_FILE );
		$base         = $plugin_path . $version_path . '/assets/vue3/';

		if ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG && file_exists( $base . 'manifest.dev.json' ) ) {
			return $base . 'manifest.dev.json';
		}

		return $base . 'manifest.json';
	}

	/**
	 * Fetch Vue 3 manifest data and store it to array for future use.
	 *
	 * @return void
	 */
	private function get_manifest_data_v3() {
		$manifest_path = self::get_vue3_manifest_path();

		if ( ! file_exists( $manifest_path ) ) {
			return;
		}

		self::$manifest_data_v3 = json_decode( file_get_contents( $manifest_path ), true );
	}

	/**
	 * Lazy-load the Vue 3 manifest so the public helpers work even if the
	 * Admin_Assets class hasn't been instantiated yet (used by callers like
	 * the dashboard widget loader that runs on non-MonsterInsights screens).
	 *
	 * @return void
	 */
	private static function ensure_manifest_data_v3() {
		if ( ! empty( self::$manifest_data_v3 ) ) {
			return;
		}

		$manifest_path = self::get_vue3_manifest_path();

		if ( ! file_exists( $manifest_path ) ) {
			return;
		}

		self::$manifest_data_v3 = json_decode( file_get_contents( $manifest_path ), true );
	}

	/**
	 * Resolve the build URL for a Vue 3 entry key. Under SCRIPT_DEBUG the
	 * manifest read is manifest.dev.json, so the entry already resolves to the
	 * unminified build (see get_vue3_manifest_path).
	 *
	 * @param string $entry_key Manifest key (e.g. `src/modules/widget/main.js`).
	 * @return string Empty string if the manifest/entry isn't present.
	 */
	public static function get_vue3_asset_url( $entry_key ) {
		self::ensure_manifest_data_v3();

		if ( empty( self::$manifest_data_v3[ $entry_key ]['file'] ) ) {
			return '';
		}

		$file = self::$manifest_data_v3[ $entry_key ]['file'];

		$version_path = monsterinsights_is_pro_version() ? 'pro' : 'lite';
		return plugins_url( $version_path . '/assets/vue3/' . ltrim( $file, '/' ), MONSTERINSIGHTS_PLUGIN_FILE );
	}

	/**
	 * Enqueue every CSS file associated with a Vue 3 entry, including CSS from
	 * static and dynamic imports. Walks the manifest dep tree recursively.
	 *
	 * @param string $entry_key Manifest key (e.g. `src/modules/widget/main.js`).
	 * @param string $handle_prefix Prefix for the registered style handles.
	 */
	public static function enqueue_vue3_asset_css( $entry_key, $handle_prefix = 'monsterinsights-v3-style' ) {
		self::ensure_manifest_data_v3();

		if ( empty( self::$manifest_data_v3[ $entry_key ] ) ) {
			return;
		}

		$css_files = array();
		$visited   = array();
		self::collect_vue3_css_static( $entry_key, $css_files, $visited );

		$version_path = monsterinsights_is_pro_version() ? 'pro' : 'lite';
		$base_url     = plugins_url( $version_path . '/assets/vue3/', MONSTERINSIGHTS_PLUGIN_FILE );

		foreach ( $css_files as $i => $css_file ) {
			wp_enqueue_style(
				$handle_prefix . '-' . $i,
				$base_url . ltrim( $css_file, '/' ),
				array(),
				monsterinsights_get_asset_version()
			);
		}
	}

	/**
	 * Static companion of `collect_vue3_css()` — walks `imports` and
	 * `dynamicImports` entries recursively to collect every CSS file needed by
	 * an entry point.
	 */
	private static function collect_vue3_css_static( $key, &$css_files, &$visited ) {
		if ( isset( $visited[ $key ] ) ) {
			return;
		}
		$visited[ $key ] = true;

		$entry = isset( self::$manifest_data_v3[ $key ] ) ? self::$manifest_data_v3[ $key ] : null;
		if ( empty( $entry ) ) {
			return;
		}

		if ( ! empty( $entry['css'] ) && is_array( $entry['css'] ) ) {
			foreach ( $entry['css'] as $css_file ) {
				if ( ! in_array( $css_file, $css_files, true ) ) {
					$css_files[] = $css_file;
				}
			}
		}
		if ( ! empty( $entry['imports'] ) && is_array( $entry['imports'] ) ) {
			foreach ( $entry['imports'] as $import_key ) {
				self::collect_vue3_css_static( $import_key, $css_files, $visited );
			}
		}
		if ( ! empty( $entry['dynamicImports'] ) && is_array( $entry['dynamicImports'] ) ) {
			foreach ( $entry['dynamicImports'] as $dynamic_key ) {
				self::collect_vue3_css_static( $dynamic_key, $css_files, $visited );
			}
		}
	}

	/**
	 * Map a Vue 3 page slug to its Vite entry point.
	 *
	 * @param string $page The sanitized page slug.
	 * @return string Entry key for the manifest (defaults to custom-dashboard).
	 */
	private function get_vue3_entry_key( $page ) {
		$entry_map = apply_filters( 'monsterinsights_vue3_entry_map', array(
			'monsterinsights_overview_report'    => 'src/modules/reports/main.js',
			'monsterinsights_custom_dashboard'   => 'src/modules/custom-dashboard/main.js',
			'monsterinsights-custom-dashboards'  => 'src/modules/custom-dashboard/main.js',
			'monsterinsights_settings'           => 'src/modules/settings/main.js',
			'monsterinsights_network'            => 'src/modules/settings/main-network.js',
		) );

		return isset( $entry_map[ $page ] ) ? $entry_map[ $page ] : 'src/modules/custom-dashboard/main.js';
	}

	/**
	 * Get Vue 3 entry and base URL from manifest for a given key.
	 *
	 * @param string $entry_key Manifest key (e.g., 'custom-dashboard').
	 * @return array [ base_url, entry_array ]
	 */
	private function get_vue3_entry( $entry_key ) {
		$version_path = monsterinsights_is_pro_version() ? 'pro' : 'lite';
		$base_url     = plugins_url( $version_path . '/assets/vue3/', MONSTERINSIGHTS_PLUGIN_FILE );
		$entry        = array();

		if ( isset( self::$manifest_data_v3[ $entry_key ] ) ) {
			$entry = self::$manifest_data_v3[ $entry_key ];
		} elseif ( isset( self::$manifest_data_v3[ 'src/' . $entry_key . '/main.js' ] ) ) {
			$entry = self::$manifest_data_v3[ 'src/' . $entry_key . '/main.js' ];
		}

		// Under SCRIPT_DEBUG the manifest itself is the unminified manifest.dev.json
		// (see get_vue3_manifest_path), so $entry already points at the .js build.

		return array( $base_url, $entry );
	}

	/**
	 * Enqueue all CSS files for a Vue 3 entry point, including its imports and dynamic imports.
	 *
	 * Walks the manifest dependency tree to collect CSS from the entry, its static imports,
	 * and its dynamic imports (lazy-loaded chunks like route components).
	 *
	 * @param string $entry_key Manifest key (e.g., 'src/modules/reports/main.js').
	 */
	private function enqueue_vue3_entry_css( $entry_key ) {
		list( $base_url, $entry ) = $this->get_vue3_entry( $entry_key );

		if ( empty( $entry ) ) {
			return;
		}

		$css_files = array();
		$visited   = array();

		// Recursively collect CSS from entry and all its dependencies.
		$this->collect_vue3_css( $entry_key, $css_files, $visited );

		foreach ( $css_files as $i => $css_file ) {
			wp_enqueue_style(
				'monsterinsights-v3-style-' . $i,
				$base_url . ltrim( $css_file, '/' ),
				array(),
				monsterinsights_get_asset_version()
			);
		}
	}

	/**
	 * Recursively collect CSS files from a manifest entry and its imports/dynamic imports.
	 *
	 * @param string $key      Manifest key to process.
	 * @param array  $css_files Collected CSS files (passed by reference).
	 * @param array  $visited   Already visited keys to prevent cycles (passed by reference).
	 */
	private function collect_vue3_css( $key, &$css_files, &$visited ) {
		if ( isset( $visited[ $key ] ) ) {
			return;
		}
		$visited[ $key ] = true;

		$entry = isset( self::$manifest_data_v3[ $key ] ) ? self::$manifest_data_v3[ $key ] : null;
		if ( empty( $entry ) ) {
			return;
		}

		// Collect CSS from this entry.
		if ( ! empty( $entry['css'] ) && is_array( $entry['css'] ) ) {
			foreach ( $entry['css'] as $css_file ) {
				if ( ! in_array( $css_file, $css_files, true ) ) {
					$css_files[] = $css_file;
				}
			}
		}

		// Walk static imports (shared chunks like _Icon-xxx.js).
		if ( ! empty( $entry['imports'] ) && is_array( $entry['imports'] ) ) {
			foreach ( $entry['imports'] as $import_key ) {
				$this->collect_vue3_css( $import_key, $css_files, $visited );
			}
		}

		// Walk dynamic imports (lazy-loaded route chunks like OverviewReport.vue).
		if ( ! empty( $entry['dynamicImports'] ) && is_array( $entry['dynamicImports'] ) ) {
			foreach ( $entry['dynamicImports'] as $dynamic_key ) {
				$this->collect_vue3_css( $dynamic_key, $css_files, $visited );
			}
		}
	}

	/**
	 * Sanitization specific to each field.
	 *
	 * @param string $field The key of the field to sanitize.
	 * @param string $value The value of the field to sanitize.
	 *
	 * @return mixed The sanitized input.
	 */
	private function handle_sanitization( $field, $value ) {

		$value = wp_unslash( $value );

		// Textarea fields.
		$textarea_fields = array();

		if ( in_array( $field, $textarea_fields, true ) ) {
			if ( function_exists( 'sanitize_textarea_field' ) ) {
				return sanitize_textarea_field( $value );
			} else {
				return wp_kses( $value, array() );
			}
		}

		$array_value = $value;
		if ( is_array( $array_value ) ) {
			$value = $array_value;
			// Don't save empty values.
			foreach ( $value as $key => $item ) {
				if ( is_array( $item ) ) {
					$empty = true;
					foreach ( $item as $item_value ) {
						if ( ! empty( $item_value ) ) {
							$empty = false;
						}
					}
					if ( $empty ) {
						unset( $value[ $key ] );
					}
				}
			}
			// Reset array keys because JavaScript can't handle arrays with non-sequential keys.
			$value = array_values( $value );

			return $value;
		}
		return sanitize_text_field( $value );
	}

	/**
	 * Check if the CharitableWP notice should be shown.
	 */
	private function show_charitablewp_notice() {
		// Check if user has permission to show the notice.
		if ( ! current_user_can( 'monsterinsights_save_settings' ) ) {
			return false;
		}

		$installed_plugins = get_plugins();
		$plugin_path = 'charitable/charitable.php';

		if ( isset( $installed_plugins[$plugin_path] ) ) {
			return false;
		}

		return monsterinsights_get_option( 'show_charitable_notice', false );
	}

	/**
	 * Load Vue 3 report script.
	 */
	private function load_vue3_report_script($auth, $auth_data, $license_info, $version_path) {
		$handle = 'monsterinsights-vue3-reports';

		// `wp-date` supplies wp.date.dateI18n(), which reports use to render dates
		// embedded in translated copy with localized month names instead of
		// moment's hard-coded English ones.
		$deps = array( 'wp-i18n', 'wp-util', 'wp-date' );

		if ( defined( 'MONSTERINSIGHTS_V3_DEV_URL' ) && MONSTERINSIGHTS_V3_DEV_URL ) {
			$dev_url = trailingslashit( MONSTERINSIGHTS_V3_DEV_URL ) . 'src/modules/reports/main.js';
			wp_register_script( $handle, $dev_url, $deps, monsterinsights_get_asset_version(), true );
			wp_enqueue_script( $handle );
		} else {
			list( $base_url, $entry ) = $this->get_vue3_entry( 'src/modules/reports/main.js' );
			if ( ! empty( $entry['file'] ) ) {
				$src = $base_url . ltrim( $entry['file'], '/' );
				wp_register_script( $handle, $src, $deps, monsterinsights_get_asset_version(), true );
				wp_enqueue_script( $handle );
			}
		}

		// Declare the bundled web fonts for the Vue 3 reports app on every report
		// view. This must live here (not in the CSS bundle) for two reasons:
		//  - In dev, Vite injects CSS via JS and the SCSS @font-face url() paths
		//    resolve relative to the WP page, not the dev server, so they 404.
		//  - In production, the bundled @font-face only lands in code-split CSS
		//    chunks that are not guaranteed to load on a given report view, so the
		//    Misettings icon font can be left undeclared and font icons (e.g. the
		//    notice dismiss "x", monstericon-times) render as empty tofu boxes.
		// Attaching to the always-enqueued common stylesheet guarantees they load.
		$fonts_base = plugins_url( $version_path . '/assets/vue3/fonts/', MONSTERINSIGHTS_PLUGIN_FILE );
		wp_add_inline_style( 'monsterinsights-admin-common-style', '
			@font-face {
				font-family: "Misettings";
				src: url("' . $fonts_base . 'icons.woff2?v=7.5.0") format("woff2"),
					url("' . $fonts_base . 'icons.woff?v=7.5.0") format("woff"),
					url("' . $fonts_base . 'icons.ttf?v=7.5.0") format("truetype");
				font-weight: 400;
				font-style: normal;
			}
			@font-face {
				font-family: "Lato";
				src: url("' . $fonts_base . 'lato-regular-webfont.woff2") format("woff2"),
					url("' . $fonts_base . 'lato-regular-webfont.woff") format("woff");
				font-weight: 400;
				font-style: normal;
			}
			@font-face {
				font-family: "Lato";
				src: url("' . $fonts_base . 'lato-bold-webfont.woff2") format("woff2"),
					url("' . $fonts_base . 'lato-bold-webfont.woff") format("woff");
				font-weight: 700;
				font-style: normal;
			}
			@font-face {
				font-family: "Roboto";
				src: url("' . $fonts_base . 'Roboto-Regular.woff2") format("woff2");
				font-weight: 400;
				font-style: normal;
			}
			@font-face {
				font-family: "Roboto";
				src: url("' . $fonts_base . 'Roboto-Bold.woff2") format("woff2");
				font-weight: 700;
				font-style: normal;
			}
			@font-face {
				font-family: "text-security-disc";
				src: url("' . $fonts_base . 'text-security-disc.woff2") format("woff2"),
					url("' . $fonts_base . 'text-security-disc.woff") format("woff");
			}
		' );

		// Provide bootstrap payload for the Vue 3 app in build
		// Base "authed" on real credentials (key + v4), not just the property name,
		// so a profile that lost its key/token is reported as not connected.
		$site_auth = $auth->is_authed();
		$ms_auth   = is_multisite() && $auth->is_network_authed();

		// Reporting API endpoint for direct client-side requests to api/v3/reporting/query,
		// authenticated with the short-lived bearer token issued below — same pattern as
		// the Custom Dashboard bootstrap. The license key is capability-gated.
		//
		// The relay key and token are deliberately absent. Every v3/reporting route
		// requires `Authorization: Bearer` and rejects X-Relay header auth, so a host
		// that cannot mint a bearer gets a 401 whether or not the pair is localised —
		// sending it would disclose a long-lived credential to every dashboard-tier
		// user and buy nothing.
		$can_view_license = current_user_can( 'monsterinsights_save_settings' );
		$reporting_api    = array(
			'url'      => apply_filters( 'monsterinsights_api_url_custom_dashboard', 'https://app.monsterinsights.com/' ),
			'license'  => ( monsterinsights_is_pro_version() && $can_view_license ) ? ( is_network_admin() ? MonsterInsights()->license->get_network_license_key() : MonsterInsights()->license->get_site_license_key() ) : '',
			'site_url' => is_network_admin() ? network_admin_url() : home_url(),
		);

		// Bearer token for direct browser-to-API requests (Relay), same pattern as Custom Dashboard.
		$bearer_token_data = MonsterInsights_API_Token::get_token( is_network_admin() );
		$bearer_token      = '';
		$bearer_expires    = 0;

		if ( ! is_wp_error( $bearer_token_data ) ) {
			$bearer_token   = $bearer_token_data['token'];
			$bearer_expires = $bearer_token_data['expires_at'];
		}

		// Build addon info (active, installed, basename) for Vue 3 report addon gates.
		$installed_plugins = get_plugins();
		$addon_defs = array(
			'ecommerce'     => array( 'monsterinsights-ecommerce', 'ga-ecommerce' ),
			'dimensions'    => array( 'monsterinsights-dimensions' ),
			'forms'         => array( 'monsterinsights-forms' ),
			'page_insights' => array( 'monsterinsights-page-insights' ),
			'exceptions'    => array( 'monsterinsights-exceptions' ),
			'media'         => array( 'monsterinsights-media' ),
		);
		$addons_active    = array();
		$addons_info      = array();
		foreach ( $addon_defs as $key => $slugs ) {
			$is_active    = false;
			$is_installed = false;
			$basename     = '';
			foreach ( $slugs as $slug ) {
				$bn = monsterinsights_get_plugin_basename_from_slug( $slug );
				if ( $bn && isset( $installed_plugins[ $bn ] ) ) {
					$is_installed = true;
					$basename     = $bn;
					if ( is_plugin_active( $bn ) ) {
						$is_active = true;
					}
					break;
				}
			}
			$addons_active[ $key ] = $is_active;
			$addons_info[ $key ]   = array(
				'installed' => $is_installed,
				'basename'  => $basename,
			);
		}

		// Dimension type definitions + user-configured dimensions for the Dimensions report.
		$prepared_dimensions = array();
		$custom_dimensions_config = array();
		if ( class_exists( 'MonsterInsights_Admin_Custom_Dimensions' ) ) {
			$dim_instance        = new MonsterInsights_Admin_Custom_Dimensions();
			$all_dimensions      = $dim_instance->custom_dimensions();
			foreach ( $all_dimensions as $dimension_type => $dimension ) {
				$dimension['type']     = $dimension_type;
				$prepared_dimensions[] = $dimension;
			}
			$custom_dimensions_config = monsterinsights_get_option( 'custom_dimensions', array() );
		}

		wp_localize_script(
			$handle,
			'monsterinsights',
			apply_filters( 'monsterinsights_localize_script_data', array(
				'ajax'               => admin_url( 'admin-ajax.php' ),
				'assets_url'         => apply_filters( 'monsterinsights_vue3_assets_url', plugins_url( $version_path . '/assets/vue3', MONSTERINSIGHTS_PLUGIN_FILE ) ),
				'plugin_assets_url'  => plugins_url( 'assets/', MONSTERINSIGHTS_PLUGIN_FILE ),
				'nonce'              => wp_create_nonce( 'mi-admin-nonce' ),
				'license'            => $license_info,
				'auth'               => $auth_data,
				'authed'             => $site_auth || $ms_auth, // Boolean auth flag (used by settings tools/Google Ads views via getMiGlobal)
				'can_view_reports'   => current_user_can( 'monsterinsights_view_dashboard' ),
				'license_expired'    => monsterinsights_is_pro_version() && MonsterInsights()->license->license_has_error(),
				'plugin_version'     => MONSTERINSIGHTS_VERSION,
				'reporting_api'      => $reporting_api,
				// Direct API access (Relay) for Overview/Reports, aligned with Custom Dashboard.
				'relay_api_url'      => apply_filters( 'monsterinsights_api_url_custom_dashboard', 'https://app.monsterinsights.com/' ),
				'bearer_token'       => $bearer_token,
				'bearer_expires'     => $bearer_expires,
				// Sample data mode: when true, frontend should bypass direct API and use WP AJAX for sample data.
				'sample_data_enabled' => apply_filters( 'monsterinsights_sample_data_enabled', false ),
				'wizard_url'         => monsterinsights_can_install_plugins() ? monsterinsights_get_onboarding_url() : '',
				'admin_url'          => admin_url(),
				'addons'             => $addons_active,
				'addons_info'        => $addons_info,
				// Whether the third-party Woo Product Feed PRO plugin is active. Gates the
				// Pro-only "Product Feed" eCommerce report tab in the Vue 3 nav (parity with
				// the legacy app's addons_pre_check.woo_product_feed_pro check).
				'woo_product_feed'   => is_plugin_active( 'woo-product-feed-pro/woocommerce-sea.php' ),
				'activate_nonce'     => wp_create_nonce( 'monsterinsights-activate' ),
				'install_nonce'      => wp_create_nonce( 'monsterinsights-install' ),
				'addons_page_url'    => is_multisite() ? network_admin_url( 'admin.php?page=monsterinsights_network#/addons' ) : admin_url( 'admin.php?page=monsterinsights_settings#/addons' ),
				'update_settings'    => current_user_can( 'monsterinsights_save_settings' ),
				// Universally contextual promo state (GH-3374): gate on install capability,
				// active plugin, and per-user dismissals so the report-side tips render correctly.
				'install_plugins'    => monsterinsights_can_install_plugins(),
				'universally_active' => defined( 'UNIVERSALLY_VERSION' ),
				'dismissed_promos'   => monsterinsights_get_dismissed_promos(),
				// eCommerce store currency for report value formatting; without this
				// every consumer falls back to USD (getMiGlobal('currency', 'USD')).
				'currency'           => monsterinsights_get_ecommerce_currency(),
				'dimensions'              => $prepared_dimensions,
				'custom_dimensions_config' => $custom_dimensions_config,
				// GA4 web UI deep link path (MonsterInsights auth) for "View in Analytics" links in reports.
				'ga_referral_url'    => $auth->get_referral_url(),
			) )
		);
		// Load translations for Vue 3 app using WordPress's script translation system
		wp_set_script_translations( $handle, 'google-analytics-premium' );
	}

	/**
	 * Load Vue 3 Settings script and localize all data the settings module needs.
	 * Mirrors the localization data from the Vue 2 settings loading block.
	 */
	private function load_vue3_settings_script( $auth, $auth_data, $license_info, $license_network_info, $version_path, $entry_key = 'src/modules/settings/main.js', $handle = 'monsterinsights-vue3-settings' ) {

		if ( defined( 'MONSTERINSIGHTS_V3_DEV_URL' ) && MONSTERINSIGHTS_V3_DEV_URL ) {
			$dev_url = trailingslashit( MONSTERINSIGHTS_V3_DEV_URL ) . $entry_key;
			wp_register_script( $handle, $dev_url, array( 'wp-i18n', 'wp-util' ), monsterinsights_get_asset_version(), true );
			wp_enqueue_script( $handle );
		} else {
			list( $base_url, $entry ) = $this->get_vue3_entry( $entry_key );
			if ( ! empty( $entry['file'] ) ) {
				$src = $base_url . ltrim( $entry['file'], '/' );
				wp_register_script( $handle, $src, array( 'wp-i18n', 'wp-util' ), monsterinsights_get_asset_version(), true );
				wp_enqueue_script( $handle );
			}

			// Enqueue CSS directly from manifest entry.
			if ( ! empty( $entry['css'] ) && is_array( $entry['css'] ) ) {
				foreach ( $entry['css'] as $i => $css_file ) {
					wp_enqueue_style(
						$handle . '-css-' . $i,
						$base_url . ltrim( $css_file, '/' ),
						array(),
						monsterinsights_get_asset_version()
					);
				}
			}
		}

		// Declare the bundled web fonts for the Vue 3 settings app. This must live
		// here (not in the CSS bundle) for two reasons:
		//  - In dev, Vite injects CSS via JS and the SCSS @font-face url() paths
		//    resolve relative to the WP page, not the dev server, so they 404.
		//  - In production, the bundled @font-face only lands in code-split CSS
		//    chunks that are not guaranteed to load on a given settings view, so the
		//    Misettings icon font can be left undeclared and font icons (e.g. a
		//    notice dismiss "x", monstericon-times) render as empty tofu boxes.
		// Attached to the always-enqueued common stylesheet so they always load.
		$fonts_base = plugins_url( $version_path . '/assets/vue3/fonts/', MONSTERINSIGHTS_PLUGIN_FILE );
		wp_add_inline_style( 'monsterinsights-admin-common-style', '
			@font-face {
				font-family: "Misettings";
				src: url("' . $fonts_base . 'icons.woff2?v=7.5.0") format("woff2"),
					url("' . $fonts_base . 'icons.woff?v=7.5.0") format("woff"),
					url("' . $fonts_base . 'icons.ttf?v=7.5.0") format("truetype");
				font-weight: 400;
				font-style: normal;
			}
			@font-face {
				font-family: "Lato";
				src: url("' . $fonts_base . 'lato-regular-webfont.woff2") format("woff2"),
					url("' . $fonts_base . 'lato-regular-webfont.woff") format("woff");
				font-weight: 400;
				font-style: normal;
			}
			@font-face {
				font-family: "Lato";
				src: url("' . $fonts_base . 'lato-bold-webfont.woff2") format("woff2"),
					url("' . $fonts_base . 'lato-bold-webfont.woff") format("woff");
				font-weight: 700;
				font-style: normal;
			}
			@font-face {
				font-family: "Roboto";
				src: url("' . $fonts_base . 'Roboto-Regular.woff2") format("woff2");
				font-weight: 400;
				font-style: normal;
			}
			@font-face {
				font-family: "Roboto";
				src: url("' . $fonts_base . 'Roboto-Bold.woff2") format("woff2");
				font-weight: 700;
				font-style: normal;
			}
			@font-face {
				font-family: "text-security-disc";
				src: url("' . $fonts_base . 'text-security-disc.woff2") format("woff2"),
					url("' . $fonts_base . 'text-security-disc.woff") format("woff");
			}
		' );

		// Prepare settings-specific data (mirrors Vue 2 settings localization).
		$plugins         = get_plugins();
		$install_amp_url = false;
		if ( monsterinsights_can_install_plugins() ) {
			$amp_key = 'amp/amp.php';
			if ( array_key_exists( $amp_key, $plugins ) ) {
				$install_amp_url = wp_nonce_url( self_admin_url( 'plugins.php?action=activate&plugin=' . $amp_key ), 'activate-plugin_' . $amp_key );
			} else {
				$install_amp_url = wp_nonce_url( self_admin_url( 'update.php?action=install-plugin&plugin=amp' ), 'install-plugin_amp' );
			}
		}

		$install_woocommerce_url = false;
		if ( monsterinsights_can_install_plugins() ) {
			$woo_key = 'woocommerce/woocommerce.php';
			if ( array_key_exists( $woo_key, $plugins ) ) {
				$install_woocommerce_url = wp_nonce_url( self_admin_url( 'plugins.php?action=activate&plugin=' . $woo_key ), 'activate-plugin_' . $woo_key );
			} else {
				$install_woocommerce_url = wp_nonce_url( self_admin_url( 'update.php?action=install-plugin&plugin=woocommerce' ), 'install-plugin_woocommerce' );
			}
		}

		$prepared_dimensions = array();
		if ( class_exists( 'MonsterInsights_Admin_Custom_Dimensions' ) ) {
			$dimensions          = new MonsterInsights_Admin_Custom_Dimensions();
			$dimensions          = $dimensions->custom_dimensions();
			foreach ( $dimensions as $dimension_type => $dimension ) {
				$dimension['type']     = $dimension_type;
				$prepared_dimensions[] = $dimension;
			}
		}

		// This payload already localizes `authed` from $is_authed; the viewname-based
		// $site_auth/$ms_auth pair it used to need is unused here.
		$is_authed   = ( MonsterInsights()->auth->is_authed() || MonsterInsights()->auth->is_network_authed() );

		wp_localize_script(
			$handle,
			'monsterinsights',
			apply_filters( 'monsterinsights_localize_script_data', array(
				'ajax'                            => admin_url( 'admin-ajax.php' ),
				'nonce'                           => wp_create_nonce( 'mi-admin-nonce' ),
				'network'                         => is_network_admin(),
				'assets_url'                      => apply_filters( 'monsterinsights_vue3_assets_url', plugins_url( $version_path . '/assets/vue3', MONSTERINSIGHTS_PLUGIN_FILE ) ),
				'plugin_assets_url'               => plugins_url( 'assets/', MONSTERINSIGHTS_PLUGIN_FILE ),
				'roles'                           => monsterinsights_get_roles(),
				'roles_manage_options'            => monsterinsights_get_manage_options_roles(),
				'shareasale_id'                   => monsterinsights_get_shareasale_id(),
				'shareasale_url'                  => monsterinsights_get_shareasale_url( monsterinsights_get_shareasale_id(), '' ),
				'addons_url'                      => is_multisite() ? network_admin_url( 'admin.php?page=monsterinsights_network#/addons' ) : admin_url( 'admin.php?page=monsterinsights_settings#/addons' ),
				'seo_settings_page_url'           => is_multisite() ? network_admin_url( 'admin.php?page=monsterinsights_network#/seo' ) : admin_url( 'admin.php?page=monsterinsights_settings#/seo' ),
				'aioseo_dashboard_url'            => is_multisite() ? network_admin_url( 'admin.php?page=aioseo' ) : admin_url( 'admin.php?page=aioseo' ),
				'wp_plugins_page_url'             => is_multisite() ? network_admin_url( 'plugins.php' ) : admin_url( 'plugins.php' ),
				'email_summary_url'               => admin_url( 'admin.php?monsterinsights_email_preview&monsterinsights_email_template=summary' ),
				'install_amp_url'                 => $install_amp_url,
				'install_woo_url'                 => $install_woocommerce_url,
				'dimensions'                      => $prepared_dimensions,
				'install_plugins'                 => monsterinsights_can_install_plugins(),
				// Contextual Universally promos in settings (GH-3374) gate on these the same
				// way the report loader does — mirror load_vue3_report_script's keys.
				'universally_active'              => defined( 'UNIVERSALLY_VERSION' ),
				'dismissed_promos'                => monsterinsights_get_dismissed_promos(),
				'unfiltered_html'                 => current_user_can( 'unfiltered_html' ),
				'activate_nonce'                  => wp_create_nonce( 'monsterinsights-activate' ),
				'deactivate_nonce'                => wp_create_nonce( 'monsterinsights-deactivate' ),
				'install_nonce'                   => wp_create_nonce( 'monsterinsights-install' ),
				'versions'                        => monsterinsights_get_php_wp_version_warning_data(),
				'plugin_version'                  => MONSTERINSIGHTS_VERSION,
				'is_admin'                        => true,
				'admin_email'                     => get_option( 'admin_email' ),
				'site_url'                        => get_site_url(),
				'site_name'                       => get_bloginfo( 'name' ),
				'reports_url'                     => add_query_arg( 'page', 'monsterinsights_overview_report', admin_url( 'admin.php' ) ),
				'custom_dashboard_url'            => add_query_arg( 'page', 'monsterinsights_custom_dashboard', admin_url( 'admin.php' ) ),
				'first_run_notice'                => apply_filters( 'monsterinsights_settings_first_time_notice_hide', monsterinsights_get_option( 'monsterinsights_first_run_notice' ) ),
				'getting_started_url'             => is_network_admin() ? network_admin_url( 'admin.php?page=monsterinsights_network#/about' ) : admin_url( 'admin.php?page=monsterinsights_settings#/about/getting-started' ),
				'authed'                          => $is_authed,
				'auth'                            => $auth_data,
				'license'                         => $license_info,
				'license_network'                 => $license_network_info,
				'new_pretty_link_url'             => admin_url( 'post-new.php?post_type=pretty-link' ),
				'load_headline_analyzer_settings' => monsterinsights_load_gutenberg_app() ? 'true' : 'false',
				'exit_url'                        => add_query_arg( 'page', 'monsterinsights_settings', admin_url( 'admin.php' ) ),
				'wizard_url'                      => monsterinsights_can_install_plugins() ? monsterinsights_get_onboarding_url() : '',
				'site_notes_export_synced'        => monsterinsights_get_option( 'site_notes_export_synced', 0 ),
				'site_notes_import_synced'        => monsterinsights_get_option( 'site_notes_import_synced', 0 ),
				'timezone'                        => date( 'e' ), // phpcs:ignore
				'currency'                        => monsterinsights_get_ecommerce_currency(),
				'can_view_reports'                => current_user_can( 'monsterinsights_view_dashboard' ),
				'update_settings'                 => current_user_can( 'monsterinsights_save_settings' ),
			) )
		);

		// Load translations for Vue 3 settings app.
		wp_set_script_translations( $handle, 'google-analytics-premium' );
	}
}

new MonsterInsights_Admin_Assets();
