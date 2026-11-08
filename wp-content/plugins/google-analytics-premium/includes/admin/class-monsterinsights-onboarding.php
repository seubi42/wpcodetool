<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * MonsterInsights Onboarding Class
 *
 * @package MonsterInsights
 * @author David Paternina
 */
class MonsterInsights_Onboarding {

	/**
	 * Constructor
	 *
	 * @return void
	 */
	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register REST routes
	 *
	 * @return void
	 */
	public function register_routes() {
		$namespace = 'monsterinsights/v1';

		register_rest_route( $namespace, '/onboarding/settings', array(
			'args' => array(
				'onboarding_key' => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
					'validate_callback' => function( $param ) {
						return ! empty( $param );
					},
				),
			),
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_info' ),
				'permission_callback' => array( $this, 'validate_onboarding_request' ),
			),
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'store_settings' ),
				'permission_callback' => array( $this, 'validate_onboarding_request' ),
			),
		) );

		register_rest_route(
			$namespace,
			'/onboarding/connect-url',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_connect_url' ),
				'permission_callback' => array( $this, 'validate_onboarding_request' ),
				'args'               => array(
					'license_key'     => array(
						'required'          => true,
						'type'             => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'onboarding_key' => array(
						'required'          => true,
						'type'             => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);
		register_rest_route( $namespace, '/onboarding/set-license-key', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( $this, 'set_license_key' ),
			'permission_callback' => array( $this, 'validate_onboarding_request' ),
			'args' => array(
				'license_key' => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
				'onboarding_key' => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
			),
		) );
		register_rest_route( $namespace, '/onboarding/delete-onboarding-key', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( $this, 'delete_onboarding_key' ),
			'permission_callback' => array( $this, 'validate_onboarding_request' ),
			'args' => array(
				'onboarding_key' => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
			),
		) );
	}

	/**
	 * Validate onboarding request
	 * 
	 * @param WP_REST_Request $request Request object.
	 * @return bool|WP_Error True if validation succeeds, WP_Error otherwise.
	 */
	public function validate_onboarding_request( $request ) {
		// Validate the onboarding key for all requests.
		$provided_key = $request->get_param( 'onboarding_key' );
		$stored       = monsterinsights_get_onboarding_key_data();
		$stored_key   = $stored['key'];
		if ( empty( $provided_key ) || '' === $stored_key || ! hash_equals( $stored_key, $provided_key ) ) {
			return new WP_Error(
				'monsterinsights_invalid_key',
				esc_html__( 'Invalid onboarding key', 'google-analytics-premium' ),
				array( 'status' => 403 )
			);
		}

		// Keys minted since the user id was stored alongside the key always resolve
		// to a launching user. A key that does not resolve was minted earlier and
		// its companion transient is already gone, so it cannot be attributed to
		// anyone. Treat it as stale: relaunching the wizard mints a fresh key that
		// carries its launching user.
		$onboarding_user_id = (int) $stored['user_id'];
		if ( empty( $onboarding_user_id ) ) {
			return new WP_Error(
				'monsterinsights_invalid_key',
				esc_html__( 'Invalid onboarding key', 'google-analytics-premium' ),
				array( 'status' => 403 )
			);
		}

		// Require a launching user that still holds plugin installation capability.
		if ( ! monsterinsights_can_install_plugins( $onboarding_user_id ) ) {
			return new WP_Error(
				'monsterinsights_insufficient_permissions',
				esc_html__( 'Insufficient permissions', 'google-analytics-premium' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Get onboarding settings
	 * 
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response Response with settings data.
	 */
	public function get_info( $request ) {
		$auth = MonsterInsights()->auth;
		$is_network = is_network_admin();

		if ( ! function_exists( 'monsterinsights_get_addons' ) ) {
			require_once MONSTERINSIGHTS_PLUGIN_DIR . 'includes/admin/pages/addons.php';
		}

		if ( ! class_exists( 'MonsterInsights_Rest_Routes' ) ) {
			require_once MONSTERINSIGHTS_PLUGIN_DIR . 'includes/admin/routes.php';
		}

		if ( monsterinsights_is_pro_version() ) {
			require_once MONSTERINSIGHTS_PLUGIN_DIR . 'pro/includes/license.php';
			$licensing = new MonsterInsights_License();
		} else {
			require_once MONSTERINSIGHTS_PLUGIN_DIR . 'lite/includes/license-compat.php';
			$licensing = new MonsterInsights_License_Compat();
		}

		$rest_routes = new MonsterInsights_Rest_Routes();
		$is_file_edit_allowed = 1;
		// Determine whether file modifications are allowed.
		if ( function_exists( 'wp_is_file_mod_allowed' ) && ! wp_is_file_mod_allowed( 'monsterinsights_can_install' ) ) {
			$is_file_edit_allowed = 0;
		}
		$mi_info = array(
			'existing_settings'     => array(
				'site_type'             => monsterinsights_get_option( 'site_type', 'business' ),
				'extensions_of_files'   => monsterinsights_get_option( 'extensions_of_files' ),
				'affiliate_links'       => monsterinsights_get_option( 'affiliate_links' ),
				'view_reports'          => monsterinsights_get_option( 'view_reports' ),
				'automatic_updates'     => monsterinsights_get_option( 'automatic_updates' ),
				'anonymous_data'        => monsterinsights_get_option( 'anonymous_data' ),
				'verified_automatic'    => monsterinsights_get_option( 'verified_automatic' ),
			),
			'plugin_info'          => array(
				'plugin_version'       => MONSTERINSIGHTS_VERSION,
				'is_pro'               => monsterinsights_is_pro_version() ? 1 : 0,
				'installed_version'    => monsterinsights_get_option( 'installed_version' ),
				'installed_date'        => monsterinsights_get_option( 'installed_date' ),
				'site_license'  => array(
					'key'  => $licensing->get_site_license_key(),
					'type' => $licensing->get_site_license_type(),
				),
				'network_license' => array(
					'key'  => $licensing->get_network_license_key(),
					'type' => $licensing->get_network_license_type(),
				),
				'shareasale_id'         => monsterinsights_get_shareasale_id(),
				'shareasale_url'        => monsterinsights_get_shareasale_url( monsterinsights_get_shareasale_id(), '' ),
			),	
			'site_info'             => array(
				'admin_url'            => admin_url( 'admin-ajax.php' ),
				'site_url'             => get_site_url(),
				'roles'                => monsterinsights_get_roles(),
				'roles_manage_options' => monsterinsights_get_manage_options_roles(),
				'rest_url'             => rest_url( 'monsterinsights/v1' ),
				'ajax_url'             => admin_url( 'admin-ajax.php' ),
				'locale'               => get_locale(),
				'allow_file_edit'      => $is_file_edit_allowed,
				'exit_url'             => add_query_arg( 'page', 'monsterinsights_reports', admin_url( 'admin.php' ) ),
			),	
			'plugins'                 => array(
				'addons' => $rest_routes->onboarding_get_addons(),
			),
			'auth'              => array(
				'is_authed'                           => $auth->is_authed() || $auth->is_network_authed(),
				'v4'                                  => $auth->get_v4_id(),
				'viewname'                            => $auth->get_viewname(),
				'manual_v4'                           => $auth->get_manual_v4_id(),
				'measurement_protocol_secret'         => $auth->get_measurement_protocol_secret(),
				'network_v4'                          => $auth->get_network_v4_id(),
				'network_viewname'                    => $auth->get_network_viewname(),
				'network_manual_v4'                   => $auth->get_network_manual_v4_id(),
				'network_measurement_protocol_secret' => $auth->get_network_measurement_protocol_secret(),
				'gtag_selector_tracking_tag'          => monsterinsights_get_option( 'gtag_selector_tracking_tag', '' ),
				'gtag_selector_tracking_mp'           => monsterinsights_get_option( 'gtag_selector_tracking_mp', '' ),
				'site_key'                            => $auth->get_key(),
				'network_key'                         => $auth->get_network_key(),
				'site_token'                          => $auth->get_token(),
				'network_token'                       => $auth->get_network_token(),
			),
		);

		return new WP_REST_Response( $mi_info, 200 );
	}

	/**
	 * Store onboarding settings
	 * 
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response Response indicating success.
	 */
	public function store_settings( $request ) {
		if ( ! function_exists( 'monsterinsights_get_addons_data' ) ) {
			require_once MONSTERINSIGHTS_PLUGIN_DIR . 'includes/admin/pages/addons.php';
		}

		$is_network = boolval( $request->get_param( 'is_network' ) );
		// Process settings
		$settings = $request->get_param( 'settings' );
		$onboarding_user_id = monsterinsights_get_onboarding_user_id();

		if ( ! empty( $settings ) ) {
			$allowed_settings = apply_filters( 'monsterinsights_onboarding_allowed_settings', array(
				'site_type',
				'extensions_of_files',
				'affiliate_links',
				'view_reports',
				'automatic_updates',
				'anonymous_data',
				'verified_automatic',
			) );
			foreach ( $settings as $key => $value ) {
				if ( ! in_array( $key, $allowed_settings, true ) ) {
					continue;
				}
				// Skip admin-only settings for non-admin users.
				if ( monsterinsights_is_admin_only_setting( $key ) && ! user_can( $onboarding_user_id, 'manage_options' ) ) {
					continue;
				}
				monsterinsights_update_option( $key, $value );
			}
		}
		if ( $request->get_param('license') && class_exists( 'MonsterInsights' ) ) {
			$license = $request->get_param('license');
			$option                = $is_network ? MonsterInsights()->license->get_network_license() : MonsterInsights()->license->get_site_license();
			$option['key']         = $license['key'];
			$option['type']        = $license['type'];
			$option['is_expired']  = false;
			$option['is_disabled'] = false;
			$option['is_invalid']  = false;
			$option['is_agency']   = isset( $license['is_agency'] ) ? boolval( $license['is_agency'] ) : false;

			$is_network ? MonsterInsights()->license->set_network_license( $option ) : MonsterInsights()->license->set_site_license( $option );
			delete_transient( '_monsterinsights_addons' );
			monsterinsights_get_addons_data( $option['key'] );
		}

		// Process auth data from request, if present
		$auth = $request->get_param( 'auth' );
		if ( ! empty( $auth ) ) {
			// The request does not always carry credentials — `is_authed` marks a payload
			// that relies on the ones already stored, and a partial payload may omit them
			// entirely. Reading them straight from the request wrote a profile with the
			// property but an empty `key`/`token` over a working connection, which then
			// looked connected while every report failed (GH-3343). Fall back to what is
			// stored instead, and never overwrite real credentials with nothing.
			$existing = $is_network
				? MonsterInsights()->auth->get_network_analytics_profile()
				: MonsterInsights()->auth->get_analytics_profile();
			if ( ! is_array( $existing ) ) {
				$existing = array();
			}

			$auth_value = function ( $key ) use ( $auth ) {
				return isset( $auth[ $key ] ) ? $auth[ $key ] : '';
			};

			$key   = ! empty( $auth['key'] ) ? $auth['key'] : ( isset( $existing['key'] ) ? $existing['key'] : '' );
			$token = ! empty( $auth['token'] ) ? $auth['token'] : ( isset( $existing['token'] ) ? $existing['token'] : '' );

			$profile = array(
				'key'                         => $key,
				'token'                       => $token,
				'v4'                          => $auth_value( 'v4' ),
				'viewname'                    => $auth_value( 'v4' ),
				'a'                           => $auth_value( 'account_id' ), // AccountID
				'w'                           => $auth_value( 'property_id' ), // PropertyID
				'p'                           => $auth_value( 'view_id' ),
				'siteurl'                     => home_url(),
				'neturl'                      => network_admin_url(),
				'measurement_protocol_secret' => $auth_value( 'measurement_protocol_secret' ),
			);
			$is_network ? MonsterInsights()->auth->set_network_analytics_profile( $profile ) : MonsterInsights()->auth->set_analytics_profile( $profile );

			// This is the route the hosted onboarding app posts the newly selected
			// property back to, and it performed no invalidation at all (MI-247).
			monsterinsights_flush_property_scoped_caches( $is_network );
		}
		$can_install = monsterinsights_can_install_plugins( $onboarding_user_id ?: null );
		if ( $can_install && ! empty( $settings['addons_to_install'] ) ) {
			$plugins = $settings['addons_to_install'];
			
			foreach ( $plugins as $plugin ) {
				if ( ! isset( $plugin['slug'] ) || ! isset( $plugin['type'] ) || ! isset( $plugin['title'] ) ) {
					continue;
				}
				$installed = false;
				$plugin_type = $plugin['type'];
				switch ( $plugin_type ) {
					case 'wp_plugin':
						$installed = $this->install_plugin( $plugin['slug'] );
						break;
					case 'mkt_addon':
						$installed = $this->install_addon( $plugin['installation_path'] );
						break;
					case 'activation':
						activate_plugin( $plugin['installation_path'], '', $is_network, true );
						monsterinsights_suppress_wpforms_first_run( $plugin['installation_path'] );
						$activated_plugins[] = $plugin['title'];
						break;
				}
				if ( $installed ) {
					activate_plugin( $installed, '', $is_network, true );
					monsterinsights_suppress_wpforms_first_run( $installed );
					$activated_plugins[] = $plugin['title'];
				}
			}
			//Delete addons transient to update them to the latest status.
			delete_transient( '_monsterinsights_addons' );
		}
		return new WP_REST_Response( array(
			'success' => true
		), 200 );
	}

	/**
	 * Get connect URL for MonsterInsights authentication
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response with connect URL or error.
	 */
	public function get_connect_url( $request ) {
		if ( ! class_exists( 'MonsterInsights_Connect' ) ) {
			require_once MONSTERINSIGHTS_PLUGIN_DIR . 'includes/connect.php';
		}

		$key = $request->get_param( 'license_key' );
		$network = is_network_admin();
		if ( empty( $key ) ) {
			return new WP_Error(
				'missing_key',
				esc_html__( 'Please enter your license key to connect.', 'google-analytics-premium' ),
				array( 'status' => 400 )
			);
		}
		$url_data = MonsterInsights_Connect::generate_connect_url_data( $key, $network );
		if ( empty( $url_data ) ) {
			return new WP_Error(
				'url_generation_failed',
				esc_html__( 'Failed to generate connect URL.', 'google-analytics-premium' ),
				array( 'status' => 500 )
			);
		}

		return new WP_REST_Response(
			array(
				'url' => $url_data['url'],
			),
			200
		);
	}

	/**
	 * Install plugins which are not addons.
	 * 
	 * @since 9.5.0
	 * @param string $slug The slug of the plugin to install.
	 * @return string|false The basename of the installed plugin or false if installation fails.
	 */
	private function install_plugin( $slug ) {
		if ( ! $slug ) {
			return false;
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		
		include_once ABSPATH . 'wp-admin/includes/plugin-install.php';

		$api = plugins_api( 'plugin_information', array(
			'slug'   => $slug,
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
		$url    = add_query_arg(
			array(
				'page' => 'monsterinsights-settings',
			),
			admin_url( 'admin.php' )
		);
		$url    = esc_url( $url );

		$creds = request_filesystem_credentials( $url, $method, false, false, null );
		if ( false === $creds ) {
			return false;
		}

		if ( ! WP_Filesystem( $creds ) ) {
			return false;
		}

		// We do not need any extra credentials if we have gotten this far, so let's install the plugin.
		monsterinsights_require_upgrader();

		// Prevent language upgrade in ajax calls.
		remove_action( 'upgrader_process_complete', array( 'Language_Pack_Upgrader', 'async_upgrade' ), 20 );
		// Create the plugin upgrader with our custom skin.
		$installer = new MonsterInsights_Plugin_Upgrader( new MonsterInsights_Skin() );
		$installer->install( $download_url );

		// Flush the cache and return the newly installed plugin basename.
		if ( $installer->plugin_info() ) {
			wp_cache_flush();
			$plugin_basename = $installer->plugin_info();
			return $plugin_basename;
		}

		return false;
	}

	/**
	 * Installs a MonsterInsights addon.
	 *
	 * @param string $download_url The plugin download URL.
	 * @since 9.5.0
	 */
	private function install_addon( $download_url ) {
		// Install the addon.
		if ( isset( $download_url ) ) {
			// Only proceed when the package URL is present in the plugin's addon catalog.
			if ( ! $this->is_allowed_addon_download_url( $download_url ) ) {
				return false;
			}
			require_once ABSPATH . 'wp-admin/includes/file.php';
			$method = '';
			$url    = add_query_arg(
				array(
					'page' => 'monsterinsights-settings',
				),
				admin_url( 'admin.php' )
			);
			$url    = esc_url( $url );

			$creds = request_filesystem_credentials( $url, $method, false, false, null );
			if ( false === $creds ) {
				return false;
			}

			if ( ! WP_Filesystem( $creds ) ) {
				return false;
			}
			monsterinsights_require_upgrader( false );

			// Create the plugin upgrader with our custom skin.
			$installer = new Plugin_Upgrader( $skin = new MonsterInsights_Skin() );
			$installer->install( $download_url );

			// Flush the cache and return the newly installed plugin basename.
			wp_cache_flush();
			if ( $installer->plugin_info() ) {
				$plugin_basename = $installer->plugin_info();
				return $plugin_basename;
			}
			return false;
		}
	}

	/**
	 * Determine whether an addon package may be downloaded from the given URL.
	 *
	 * The URL must match one served by the plugin's own addon catalog. Because
	 * the wizard's install URLs originate from that same catalog, every
	 * legitimate package matches while request-supplied URLs that are not in
	 * the catalog are rejected.
	 *
	 * @since 9.5.0
	 *
	 * @param string $download_url The package download URL.
	 * @return bool True when the URL is present in the addon catalog, false otherwise.
	 */
	private function is_allowed_addon_download_url( $download_url ) {
		if ( ! is_string( $download_url ) || '' === $download_url ) {
			return false;
		}

		// Structural pre-check before the catalog lookup: an absolute http(s) URL
		// with no embedded credentials. It stays offline on purpose. Core's
		// wp_http_validate_url() resolves the host through gethostbyname(), which
		// would turn a resolver outage or a proxied site into a refused install
		// even though the package itself is legitimate.
		$parts = wp_parse_url( $download_url );
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return false;
		}
		if ( ! in_array( strtolower( $parts['scheme'] ), array( 'http', 'https' ), true ) ) {
			return false;
		}
		if ( isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return false;
		}

		// The addon package URLs carry a signed query that is regenerated on each
		// catalog fetch, so compare only the origin and path. Matching the full
		// string would reject a legitimate install whenever the catalog was
		// refreshed between issuing the URL and performing the install.
		$requested = $this->addon_download_url_base( $download_url );
		if ( '' === $requested ) {
			return false;
		}

		foreach ( $this->get_addon_download_urls() as $catalog_url ) {
			if ( $requested === $this->addon_download_url_base( $catalog_url ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Reduce a package URL to its stable origin and path for comparison,
	 * dropping the signed query string that is regenerated on each catalog fetch.
	 *
	 * @since 9.5.0
	 *
	 * @param string $url Package URL.
	 * @return string Normalized scheme://host[:port]/path, or empty string if unparseable.
	 */
	private function addon_download_url_base( $url ) {
		$parts = wp_parse_url( (string) $url );
		if ( empty( $parts['host'] ) ) {
			return '';
		}
		$scheme = isset( $parts['scheme'] ) ? $parts['scheme'] : 'https';
		$path   = isset( $parts['path'] ) ? $parts['path'] : '';
		// The port is part of the origin the catalog served, so it is compared too.
		$port = isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '';
		return strtolower( $scheme . '://' . $parts['host'] ) . $port . $path;
	}

	/**
	 * Collect the package URLs from the plugin's own addon catalog.
	 *
	 * The catalog is sourced from monsterinsights_get_addons(), which returns
	 * the cached licensing API response (transient `_monsterinsights_addons`)
	 * and refreshes it from the API when the cache is absent. When the catalog
	 * cannot be obtained this returns an empty list, so the membership test
	 * fails closed and no unverified package is installed.
	 *
	 * @since 9.5.0
	 *
	 * @return array List of package URLs known to the catalog.
	 */
	private function get_addon_download_urls() {
		if ( ! function_exists( 'monsterinsights_get_addons' ) ) {
			require_once MONSTERINSIGHTS_PLUGIN_DIR . 'includes/admin/pages/addons.php';
		}

		$catalog = monsterinsights_get_addons();
		if ( empty( $catalog ) || ! is_array( $catalog ) ) {
			return array();
		}

		$urls = array();
		foreach ( $catalog as $group ) {
			if ( ! is_array( $group ) ) {
				continue;
			}
			foreach ( $group as $addon ) {
				if ( is_object( $addon ) && ! empty( $addon->url ) && is_string( $addon->url ) ) {
					$urls[] = $addon->url;
				}
			}
		}

		return $urls;
	}

	/**
	 * Set the license key if valid, if not return an error.
	 * 
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response Response indicating success.
	 */
	public function set_license_key( $request ) {
		$license_key = $request->get_param( 'license_key' );
		$verify = monsterinsights_perform_remote_request( 'verify-key', array( 'tgm-updater-key' => $license_key ) );
		if ( ! $verify ) {
			return new WP_REST_Response(
				array(
					'error' => esc_html__( 'There was an error connecting to the remote key API. Please try again later.', 'google-analytics-premium' ),
				)
			);
		}

		if ( ! empty( $verify->error ) ) {
			return new WP_REST_Response(
				array(
					'error' => $verify->error,
				)
			);
		}
		return new WP_REST_Response(
			array(
				'success'   => true,
				'type'      => $verify->type,
				'is_agency' => $verify->is_agency,
			)
		);
	}

	/**
	 * Delete the onboarding key.
	 * 
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response Response indicating success.
	 */
	public function delete_onboarding_key( $request ) {
		delete_transient( 'monsterinsights_onboarding_key' );
		delete_transient( 'monsterinsights_onboarding_user_id' );
		return new WP_REST_Response(
			array(
				'success' => true,
			), 200 );
	}
}

new MonsterInsights_Onboarding();