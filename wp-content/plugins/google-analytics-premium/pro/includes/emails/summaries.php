<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}


/**
 * Email Summaries main class.
 *
 * @since 7.10.5
 */
class MonsterInsights_Email_Summaries {

	/**
	 * The source URL for the email summaries.
	 * This will be used to compute the assets URL for the email summaries.
	 *
	 * @since 9.4.0
	 * @access public
	 * @var string
	 */
	const SOURCE_URL = 'https://plugin-cdn.monsterinsights.com';

	/**
	 * The URL for email template icons and images.
	 *
	 * @since 9.4.0
	 * @access public
	 * @var string
	 */
	const ICONS_URL = 'https://www.monsterinsights.com';

	/**
	 * Email template to use for this class.
	 *
	 * @since 7.10.5
	 *
	 * @var string
	 */
	private $email_template = 'summaries';

	/**
	 * Test email template
	 *
	 * @since 7.10.5
	 *
	 * @var string
	 */
	private $test_email_template = 'summaries-test';

	/**
	 * Email options
	 *
	 * @since 7.10.5
	 *
	 * @var string
	 */
	private $email_options;

	/**
	 * Email is enabled.
	 *
	 * @var string
	 */
	private $is_enabled;

	/**
	 * Constructor.
	 *
	 * @since 7.10.5
	 */
	public function __construct() {
		$options                              = array();
		$email_summaries                              = monsterinsights_get_option( 'email_summaries', 'on' );
		$options['email_summaries']                   = $email_summaries;
		$options['summaries_html_template']           = monsterinsights_get_option( 'summaries_html_template', 'yes' );
		$options['summaries_carbon_copy']             = monsterinsights_get_option( 'summaries_carbon_copy', 'no' );
		$options['summaries_email_addresses']         = monsterinsights_get_option( 'summaries_email_addresses' );
		$options['summaries_header_image']            = monsterinsights_get_option( 'summaries_header_image' );
		$options['summaries_show_blog_posts']         = monsterinsights_get_option( 'summaries_show_blog_posts', 'yes' );
		$options['summaries_show_update_notices']     = monsterinsights_get_option( 'summaries_show_update_notices', 'yes' );
		$this->email_options = $options;
		$this->hooks();

		// Clear cron job for settings disabled.
		if ( ! empty( $email_summaries ) && 'on' !== $email_summaries && wp_next_scheduled( 'monsterinsights_email_summaries_cron' ) ) {
			wp_clear_scheduled_hook( 'monsterinsights_email_summaries_cron' );
		}

		// Schedule cron job for settings enabled.
		if ( ! empty( $email_summaries ) && 'on' === $email_summaries && ! wp_next_scheduled( 'monsterinsights_email_summaries_cron' ) ) {
			wp_schedule_event( $this->get_next_cron_date(), 'monsterinsights_email_summaries_weekly', 'monsterinsights_email_summaries_cron' );
		}
	}

	/**
	 * Email Summaries hooks.
	 *
	 * @since 7.10.5
	 */
	public function hooks() {

		add_action( 'admin_enqueue_scripts', array( $this, 'admin_scripts' ) );

		if ( ! empty( $this->email_options['email_summaries'] ) && 'on' === $this->email_options['email_summaries'] ) {

			add_filter( 'monsterinsights_email_template_paths', array( $this, 'add_email_template_path' ) );
			add_filter( 'monsterinsights_emails_templates_set_initial_args', array( $this, 'set_template_args' ) );
			add_filter( 'cron_schedules', array( $this, 'add_weekly_cron_schedule' ) );
			add_action( 'monsterinsights_email_summaries_cron', array( $this, 'cron' ) );
			add_action( 'wp_ajax_monsterinsights_send_test_email', array( $this, 'send_test_email' ) );
			add_action( 'monsterinsights_after_update_settings', array(
				$this,
				'reset_email_summaries_options'
			), 10, 2 );

			//  Maybe render preview
			$this->preview();
		}

	}

	/**
	 * Load required scripts for email summaries features
	 *
	 * @return void
	 * @since 7.10.5
	 *
	 */
	public function admin_scripts() {
		if ( monsterinsights_is_settings_page() ) {
			// This will load the required dependencies for the WordPress media uploader
			wp_enqueue_media();
		}
	}

	/**
	 * Check if Email Summaries are enabled in settings.
	 *
	 * @return bool
	 * @since 7.10.5
	 *
	 */
	protected function is_enabled() {
		if ( ! isset( $this->is_enabled ) ) {
			$this->is_enabled = false;

			if ( ! $this->is_preview() ) {
				$info_block      = new MonsterInsights_Summaries_InfoBlocks();
				$info_block      = $info_block->fetch_data();
				$email_addresses = $this->get_email_addresses();

				if ( ! empty( $info_block ) ) {
					if ( 'on' === $this->email_options['email_summaries'] && ! empty( $email_addresses ) && true === $info_block['status'] ) {
						$this->is_enabled = true;
					}
				}
			}
		}

		return apply_filters( 'monsterinsights_emails_summaries_is_enabled', $this->is_enabled );
	}

	/**
	 * Preview Email Summary.
	 *
	 * @since 7.10.5
	 */
	public function preview() {
		if ( ! current_user_can( 'monsterinsights_save_settings' ) ) {
			return;
		}

		if ( ! $this->is_preview() ) {
			return;
		}

		// initiate email class.
		$emails = new MonsterInsights_WP_Emails( $this->email_template );

		// check if html template option is enabled
		if ( ! $this->is_enabled_html_template() ) {
			$emails->__set( 'html', false );
		}

		$content = $emails->build_email();

		if ( ! $this->is_enabled_html_template() ) {
			$content = wpautop( $content );
		}

		echo $content; // phpcs:ignore

		exit;
	}

	/**
	 * Check whether it's in preview mode
	 *
	 * @return boolean
	 * @since 7.10.5
	 *
	 */
	public function is_preview() {
		if ( isset( $_GET['monsterinsights_email_preview'], $_GET['monsterinsights_email_template'] ) && 'summary' === $_GET['monsterinsights_email_template'] ) { // phpcs:ignore
			return true;
		}

		return false;
	}

	/**
	 * Get the email header image.
	 *
	 * @return string The email from address.
	 * @since 7.10.5
	 *
	 */
	public function get_header_image() {
		// set default header image
		$img = array(
			'url' => plugins_url( "pro/assets/img/emails/summaries/logo-MonsterInsights.png", MONSTERINSIGHTS_PLUGIN_FILE ),
			'2x'  => plugins_url( "pro/assets/img/emails/summaries/logo-MonsterInsights@2x.png", MONSTERINSIGHTS_PLUGIN_FILE ),
		);

		if ( ! empty( $this->email_options['summaries_header_image'] ) ) {
			$img['url'] = $this->email_options['summaries_header_image'];
			$img['2x']  = '';
		}

		return apply_filters( 'monsterinsights_email_header_image', $img );
	}

	/**
	 * Get next cron occurrence date.
	 *
	 * @return int Timestamp.
	 */
	private function get_next_cron_date() {
		$schedule           = array();
		$schedule['day']    = wp_rand( 0, 1 );
		$schedule['hour']   = wp_rand( 0, 23 );
		$schedule['minute'] = wp_rand( 0, 59 );
		$schedule['second'] = wp_rand( 0, 59 );
		$schedule['offset'] = ( $schedule['day'] * DAY_IN_SECONDS ) +
							  ( $schedule['hour'] * HOUR_IN_SECONDS ) +
							  ( $schedule['minute'] * MINUTE_IN_SECONDS ) +
							  $schedule['second'];

		return strtotime( 'next sunday' ) + $schedule['offset'];
	}

	/**
	 * Add summaries email template path
	 *
	 * @param array $schedules WP cron schedules.
	 *
	 * @return array
	 * @since 7.10.5
	 *
	 */
	public function add_email_template_path( $file_paths ) {
		$file_paths['1000'] = MONSTERINSIGHTS_PLUGIN_DIR . 'pro/includes/emails/templates';

		return $file_paths;
	}

	/**
	 * Add custom Email Summaries cron schedule.
	 *
	 * @param array $schedules WP cron schedules.
	 *
	 * @return array
	 * @since 7.10.5
	 *
	 */
	public function add_weekly_cron_schedule( $schedules ) {
		$schedules['monsterinsights_email_summaries_weekly'] = array(
			'interval' => WEEK_IN_SECONDS,
			'display'  => esc_html__( 'Weekly MonsterInsights Email Summaries', 'google-analytics-premium' ),
		);

		return $schedules;
	}

	/**
	 * Get email subject
	 *
	 * @since 7.10.5
	 */
	public function get_email_subject() {

		$site_url        = get_site_url();
		$site_url_parsed = wp_parse_url( $site_url );
		$site_url        = isset( $site_url_parsed['host'] ) ? $site_url_parsed['host'] : $site_url;

		// Translators: The domain of the site is appended to the subject.
		$subject = sprintf( __( 'MonsterInsights Summary - %s', 'google-analytics-premium' ), $site_url );

		return apply_filters( 'monsterinsights_emails_summaries_cron_subject', $subject );
	}

	/**
	 * Get email addresses to send
	 *
	 * @since 7.10.5
	 */
	public function get_email_addresses() {
		$emails          = array();
		$email_addresses = $this->email_options['summaries_email_addresses'];

		if ( ! empty( $email_addresses ) && is_array( $email_addresses ) ) {
			foreach ( $email_addresses as $email_address ) {
				if ( ! empty( $email_address['email'] ) && is_email( $email_address['email'] ) ) {
					$emails[] = $email_address['email'];
				}
			}
		} else {
			$emails[] = get_option( 'admin_email' );
		}

		return apply_filters( 'monsterinsights_email_addresses_to_send', $emails );
	}

	/**
	 * check if carbon copy option is enabled
	 *
	 * @since 7.10.5
	 */
	public function is_cc_enabled() {
		$value = false;
		if ( 'yes' === $this->email_options['summaries_carbon_copy'] ) {
			$value = true;
		}

		return apply_filters( 'monsterinsights_email_cc_enabled', $value, $this );
	}

	/**
	 * Check if html template option is turned on
	 *
	 * @return bool
	 * @since 7.10.5
	 *
	 */
	public function is_enabled_html_template() {
		$value = true;
		if ( 'no' === $this->email_options['summaries_html_template'] ) {
			$value = false;
		}

		return apply_filters( 'monsterinsights_email_html_template', $value, $this );
	}

	/**
	 * Email Summaries cron callback.
	 *
	 * @since 7.10.5
	 */
	public function cron() {
		if ( ! $this->is_enabled() ) {
			return;
		}

		if( !monsterinsights_is_authed() ){
			return;
		}

		$email            = array();
		$email['subject'] = $this->get_email_subject();
		$email['address'] = $this->get_email_addresses();
		$email['address'] = array_map( 'sanitize_email', $email['address'] );

		// Create new email.
		$emails = new MonsterInsights_WP_Emails( $this->email_template );

		// Maybe include CC.
		if ( $this->is_cc_enabled() ) {
			$emails->__set( 'cc', implode( ',', $this->get_email_addresses() ) );
		}

		// check if html template option is enabled
		if ( ! $this->is_enabled_html_template() ) {
			$emails->__set( 'html', false );
		}

		$info_blocks = new MonsterInsights_Summaries_InfoBlocks();
		$next_block  = $info_blocks->get_next();

		// Go.
		foreach ( $email['address'] as $address ) {
			$sent = $emails->send( trim( $address ), $email['subject'] );
			if ( true === $sent && ! empty( $next_block ) ) {
				$info_blocks->register_sent( $next_block );
			}
		}
	}

	/**
	 * Send test email
	 *
	 * @since 7.10.5
	 */
	public function send_test_email() {
		// Run a security check first.
		check_ajax_referer( 'mi-admin-nonce', 'nonce' );

		if ( ! current_user_can( 'monsterinsights_save_settings' ) ) {
			return;
		}

		$email            = array();
		$email['subject'] = '[Test email] MonsterInsights Summary';
		$email['address'] = $this->get_email_addresses();
		$email['address'] = array_map( 'sanitize_email', $email['address'] );

		// Create new email.
		$emails = new MonsterInsights_WP_Emails( $this->test_email_template );

		// Maybe include CC.
		if ( $this->is_cc_enabled() ) {
			$emails->__set( 'cc', implode( ',', $this->get_email_addresses() ) );
		}

		// check if html template option is enabled
		if ( ! $this->is_enabled_html_template() ) {
			$emails->__set( 'html', false );
		}

		// Go.
		foreach ( $email['address'] as $address ) {
			if ( ! $emails->send( trim( $address ), $email['subject'] ) ) {
				wp_send_json_error();
			}
		}
		wp_send_json_success();
	}

	/**
	 * Email summaries template arguments
	 *
	 * @since 7.10.5
	 */
	public function set_template_args( $args ) {
		if ( $this->is_enabled_html_template() ) {
			$args['header']['header_image'] = $this->get_header_image();
		}

		$args['header']['assets_url']  = self::SOURCE_URL;
		$theme_name                    = defined( 'EXACTMETRICS_VERSION' ) ? 'exactmetricsv1' : 'monsterinsightsv7';
		$icons_url                     = apply_filters( 'monsterinsights_get_licensing_url', self::ICONS_URL ) . '/wp-content/themes/' . $theme_name . '/assets/newsletter/';
		$args['header']['icons_url']   = $icons_url;
		$start_date_raw = $this->get_summaries_start_date();
		$end_date_raw = $this->get_summaries_end_date();
		$formatted_dates = $this->format_date_range_for_header( $start_date_raw, $end_date_raw );
		$args['header']['start_date']  = $formatted_dates['start'];
		$args['header']['end_date']    = $formatted_dates['end'];
		$args['header']['site_url']    = get_site_url();
		$args['body']['title']         = esc_html__( 'Website Traffic Summary', 'google-analytics-premium' );

		// Only check for plugin update notice if the option is enabled.
		if ( ! empty( $this->email_options['summaries_show_update_notices'] ) && 'yes' === $this->email_options['summaries_show_update_notices'] ) {
			$update_version = $this->plugin_has_update_notice();

			if ( ! empty( $update_version ) ) {
				$args['body']['update_available'] = true;
				$args['body']['update_version']   = $update_version;
			} else {
				$args['body']['update_available'] = false;
				$args['body']['update_version']   = '';
			}
		} else {
			$args['body']['update_available'] = false;
			$args['body']['update_version']   = '';
		}
		$args['body']['description']   = esc_html__( "Let's take a look at how your website traffic performed in the past week.", 'google-analytics-premium' );
		$summaries_data                = $this->get_summaries();
		$args['body']['summaries']     = $summaries_data;
		

		$args['body']['startDate']     = $this->get_summaries_start_date();
		$args['body']['endDate']       = $this->get_summaries_end_date();
		$args['body']['icons_url']     = $icons_url;

		// Initialize default values for all template variables
		$args['body']['analytics_stats']         = array();
		$args['body']['store_performance']       = array();
		$args['body']['form_performance']        = array();
		$args['body']['organic_performance']     = array();
		$args['body']['top_keywords']            = array();
		$args['body']['most_popular_categories'] = array();
		$args['body']['most_popular_post_types'] = array();
		$args['body']['source_medium_traffic']   = array();
		$args['body']['device_breakdown']        = array();
		$args['body']['top_pages']               = array();
		$args['body']['custom_dimensions']       = array();

		// Process summaries data if available
		if ( ! empty( $summaries_data['data'] ) ) {
			$data = $summaries_data['data'];

			// Analytics Stats
			if ( ! empty( $data['analytics_stats'] ) ) {
				$args['body']['analytics_stats'] = $this->process_analytics_stats( $data['analytics_stats'] );
			}

			// Store Performance (only if eCommerce addon is active)
			if ( class_exists( 'MonsterInsights_eCommerce' ) && ! empty( $data['store_performance'] ) ) {
				$args['body']['store_performance'] = $this->process_store_performance( $data['store_performance'] );
			}

			// Form Performance (only if Forms addon is active)
			if ( class_exists( 'MonsterInsights_Forms' ) && ! empty( $data['form_performance'] ) ) {
				$args['body']['form_performance'] = $this->process_form_performance( $data['form_performance'] );
			}

			// Organic Performance
			if ( ! empty( $data['organic_performance'] ) ) {
				$args['body']['organic_performance'] = $this->process_organic_performance( $data['organic_performance'] );
			}

			// Top Keywords
			if ( ! empty( $data['top_keywords'] ) ) {
				$args['body']['top_keywords'] = $this->process_top_keywords( $data['top_keywords'] );
			}

			// Most Popular Categories
			if ( ! empty( $data['most_popular_categories'] ) ) {
				$args['body']['most_popular_categories'] = $this->process_most_popular_categories( $data['most_popular_categories'] );
			}

			// Most Popular Post Types
			if ( ! empty( $data['most_popular_post_types'] ) ) {
				$args['body']['most_popular_post_types'] = $this->process_most_popular_post_types( $data['most_popular_post_types'] );
			}

			// Source/Medium Traffic
			if ( ! empty( $data['source_medium_traffic'] ) ) {
				$args['body']['source_medium_traffic'] = $this->process_source_medium_traffic( $data['source_medium_traffic'] );
			}

			// Device Breakdown
			if ( ! empty( $data['device_breakdown'] ) ) {
				$args['body']['device_breakdown'] = $this->process_device_breakdown( $data['device_breakdown'] );
			}

			// Top 5 Pages
			if ( ! empty( $data['top_pages'] ) ) {
				$args['body']['top_pages'] = $this->process_top_pages( $data['top_pages'] );
			}

			// Custom Dimensions
			if ( ! empty( $data['custom_dimensions'] ) ) {
				$args['body']['custom_dimensions'] = $this->process_custom_dimensions( $data['custom_dimensions'] );
			}

			// Build legacy structure for exactmetrics-templates (infobox, toppages, referrals)
			// so emails show real values when API returns the new format.
			$args['body']['summaries'] = $this->build_legacy_summaries_for_template( $args['body']['summaries'], $args['body'] );
		}

		$info_blocks   = new MonsterInsights_Summaries_InfoBlocks();
		$next_block    = $info_blocks->get_next();

		// Blog posts in the email footer – only populate if the option is enabled.
		if ( ! empty( $this->email_options['summaries_show_blog_posts'] ) && 'yes' === $this->email_options['summaries_show_blog_posts'] ) {
			$default_block = $info_blocks->get_default_block_data();
			
			// A default url for the blog posts source.
			$args['body']['blog_posts_url'] = 'https://www.monsterinsights.com/blog/';

			if ( ! empty( $default_block['blog_posts_source'] ) ) {
				$args['body']['blog_posts_url'] = $default_block['blog_posts_source'];
			}

			$args['body']['blog_posts'] = $this->get_latest_blog_posts_from_feed( $default_block );
		} else {
			$args['body']['blog_posts_url'] = '';
			$args['body']['blog_posts']     = array();
		}

		if ( ! empty( $next_block ) ) {
			$args['body']['info_block'] = $next_block;
		}

		$args['body']['settings_tab_url']   = esc_url( admin_url( 'admin.php?page=monsterinsights_settings#/advanced' ) );
		$args['footer']['settings_tab_url'] = esc_url( admin_url( 'admin.php?page=monsterinsights_settings#/advanced' ) );
		$args['footer']['icons_url']        = $args['body']['icons_url'];

		// All the data are available at overview page, so setting `View More` link as overview page.
		$reports_url = admin_url( 'admin.php?page=monsterinsights_reports#/' );

		if ( ! empty( $summaries_data['data']['galinks'] ) ) {
			$args['body']['summaries']['data']['galinks']['topposts']  = $reports_url;
			$args['body']['summaries']['data']['galinks']['referrals'] = $reports_url;
		}

		return apply_filters( 'monsterinsights_email_summaries_template_args', $args );
	}

	/**
	 * Build legacy summaries structure for exactmetrics-templates (infobox, toppages, referrals).
	 * When the API returns the new format (analytics_stats, top_pages), templates that expect
	 * the old format (infobox, toppages with title/hostname/url/sessions) get 0. This method
	 * maps the new format into the old so both template types receive valid data.
	 *
	 * @since 9.0.2
	 * @param array $summaries_data Current summaries array (e.g. from get_summaries()).
	 * @param array $body_args      Processed body args (analytics_stats, top_pages, etc.).
	 * @return array Summaries array with data.infobox, data.toppages, data.referrals set when missing.
	 */
	private function build_legacy_summaries_for_template( $summaries_data, $body_args ) {
		if ( ! is_array( $summaries_data ) ) {
			$summaries_data = array( 'data' => array() );
		}
		if ( ! isset( $summaries_data['data'] ) || ! is_array( $summaries_data['data'] ) ) {
			$summaries_data['data'] = array();
		}

		$data = &$summaries_data['data'];

		// Infobox: exactmetrics-templates expect numeric value (for number_format_i18n) and prev; analytics_stats may have "4.1K" strings.
		// `prev` must carry the trend sign because downstream templates choose up/down arrows from `>= 0`,
		// while analytics_stats already absint()s the percentage and tracks direction via `increased`.
		if ( empty( $data['infobox'] ) && ! empty( $body_args['analytics_stats'] ) ) {
			$stats = $body_args['analytics_stats'];
			$data['infobox'] = array(
				'sessions' => array(
					'value' => $this->ensure_numeric_for_legacy_infobox( isset( $stats['sessions']['value'] ) ? $stats['sessions']['value'] : '0' ),
					'prev'  => $this->signed_percentage_for_legacy_infobox( $stats['sessions'] ?? array() ),
				),
				'pageviews' => array(
					'value' => $this->ensure_numeric_for_legacy_infobox( isset( $stats['pageviews']['value'] ) ? $stats['pageviews']['value'] : '0' ),
					'prev'  => $this->signed_percentage_for_legacy_infobox( $stats['pageviews'] ?? array() ),
				),
			);
		}

		// Top pages: exactmetrics-templates expect array of { title, hostname, url, sessions }. Link = hostname + url.
		if ( empty( $data['toppages'] ) && ! empty( $body_args['top_pages'] ) ) {
			$base_url = get_site_url();
			$data['toppages'] = array();
			foreach ( $body_args['top_pages'] as $page ) {
				$page_views = isset( $page['page_views'] ) ? $page['page_views'] : 0;
				$page_views = $this->ensure_numeric_for_legacy_infobox( $page_views );
				$path = isset( $page['page_path'] ) ? $page['page_path'] : '';
				$path = '/' . ltrim( $path, '/' );
				$data['toppages'][] = array(
					'title'    => isset( $page['page_title'] ) ? (string) $page['page_title'] : ( isset( $page['page_path'] ) ? (string) $page['page_path'] : '' ),
					'hostname' => $base_url,
					'url'      => (string) $path,
					'sessions' => $page_views,
				);
			}
		}

		// Referrals: exactmetrics-templates expect array of { url, sessions }. Map from source_medium_traffic if present.
		if ( empty( $data['referrals'] ) && ! empty( $body_args['source_medium_traffic'] ) ) {
			$data['referrals'] = array();
			foreach ( array_slice( $body_args['source_medium_traffic'], 0, 5 ) as $row ) {
				$source = isset( $row['source'] ) ? $row['source'] : '';
				$medium = isset( $row['medium'] ) ? $row['medium'] : '';
				$label  = $source . ( $medium ? ' / ' . $medium : '' );
				$data['referrals'][] = array(
					'url'      => (string) $label,
					'sessions' => isset( $row['sessions'] ) ? (int) $row['sessions'] : 0,
				);
			}
		}

		// Ensure galinks exist when we built legacy data so "View More" links work in the template.
		if ( ( ! empty( $data['toppages'] ) || ! empty( $data['referrals'] ) ) && empty( $data['galinks'] ) ) {
			$reports_url       = admin_url( 'admin.php?page=monsterinsights_reports#/' );
			$data['galinks']   = array(
				'topposts'  => $reports_url,
				'referrals' => $reports_url,
			);
		}

		return $summaries_data;
	}

	/**
	 * Ensure a value is numeric for legacy infobox and toppages (templates use number_format_i18n which requires int|float).
	 * Converts "4.1K", "1.2M" and comma-formatted strings (e.g. "1,234") from format_number_with_decimal back to numbers.
	 *
	 * @param mixed $value Value from analytics_stats or top_pages (may be int, float, or "4.1K"/"1.2M"/"1,234" string).
	 * @return int|float Numeric value for display.
	 */
	private function ensure_numeric_for_legacy_infobox( $value ) {
		if ( is_numeric( $value ) ) {
			return (float) $value;
		}
		if ( ! is_string( $value ) ) {
			return 0;
		}
		$value = trim( $value );
		if ( preg_match( '/^([\d.]+)\s*K$/i', $value, $m ) ) {
			return (float) $m[1] * 1000;
		}
		if ( preg_match( '/^([\d.]+)\s*M$/i', $value, $m ) ) {
			return (float) $m[1] * 1000000;
		}
		// number_format() output (e.g. "1,234") for values < 1000.
		$no_commas = str_replace( ',', '', $value );
		if ( is_numeric( $no_commas ) ) {
			return (float) $no_commas;
		}
		return 0;
	}

	/**
	 * Sign the analytics_stats percentage for the legacy infobox shape.
	 * format_stat_value() applies absint() to percentage and stores direction in `increased`,
	 * but exactmetrics-templates derive arrow direction from the numeric sign of `prev`.
	 *
	 * @param array $stat Single analytics_stats entry (sessions, pageviews, ...).
	 * @return int Signed percentage (negative when not increased).
	 */
	private function signed_percentage_for_legacy_infobox( $stat ) {
		$percentage = isset( $stat['percentage'] ) ? (int) $stat['percentage'] : 0;
		$increased  = ! empty( $stat['increased'] );

		return $increased ? abs( $percentage ) : -abs( $percentage );
	}

	/**
	 * get the start date from the last week
	 *
	 * @since 7.10.5
	 */
	public function get_summaries_start_date() {
		return wp_date( 'Y-m-d', strtotime( '-1 day, last week' ) );
	}

	/**
	 * get the end date from the last week
	 *
	 * @since 7.10.5
	 */
	public function get_summaries_end_date() {
		return wp_date( 'Y-m-d', strtotime( 'last saturday' ) );
	}

	/**
	 * data for email template
	 *
	 * @since 7.10.5
	 */
	public function get_summaries() {
		$data = array();

		// get overview report data for email summaries template
		$report_name = 'summaries';
		$report      = MonsterInsights()->reporting->get_report( $report_name );

		$isnetwork = ! empty( $_REQUEST['isnetwork'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['isnetwork'] ) ) : '';

		// get the data of last week.
		$args = array(
			'start' => $this->get_summaries_start_date(),
			'end'   => $this->get_summaries_end_date(),
		);

		if ( $isnetwork ) {
			$args['network'] = true;
		}

		if ( monsterinsights_is_pro_version() && ! MonsterInsights()->license->license_can( $report->level ) ) {
			$data = array(
				'success' => false,
				'message' => __( "You don't have permission to view MonsterInsights reports.", 'google-analytics-premium' ),
			);
		} else {
			$data = apply_filters( 'monsterinsights_vue_reports_data', $report->get_data( $args ), $report_name, $report );
		}

		// Debug: when cron or preview gets no data, allow logging the response (e.g. API error or wrong structure).
		if ( ( empty( $data['data'] ) || ! is_array( $data['data'] ) ) && ( defined( 'DOING_CRON' ) && DOING_CRON || $this->is_preview() ) ) {
			do_action( 'monsterinsights_email_summaries_no_data', $data, $args, $report_name );
		}

		return $data;
	}


	/**
	 * Process analytics stats data
	 *
	 * @since 7.10.5
	 * @param array $data Analytics stats data.
	 * @return array Processed analytics stats.
	 */
	private function process_analytics_stats( $data ) {
		// Calculate blog posts count for last 7 days only if blog posts are enabled in email summaries.
		if ( ! empty( $this->email_options['summaries_show_blog_posts'] ) && 'yes' === $this->email_options['summaries_show_blog_posts'] ) {
			$no_of_blog_posts = $this->get_blog_posts_count_last_7_days();
		} else {
			$no_of_blog_posts = array(
				'value'      => '0',
				'percentage' => 0,
				'increased'  => false,
			);
		}

		// Bounce rate: ensure % suffix.
		$bounce = $data['bounce_rate'] ?? array();
		if ( isset( $bounce['value'] ) && is_numeric( $bounce['value'] ) ) {
			$bounce['value'] = round( (float) $bounce['value'], 1 ) . '%';
		}

		// Avg session length: convert seconds to human-readable (e.g. "1m 2s").
		$avg_session = $data['avg_session_length'] ?? array();
		if ( isset( $avg_session['value'] ) && is_numeric( $avg_session['value'] ) ) {
			$secs = (int) $avg_session['value'];
			if ( $secs >= 3600 ) {
				$avg_session['value'] = floor( $secs / 3600 ) . 'h ' . floor( ( $secs % 3600 ) / 60 ) . 'm ' . ( $secs % 60 ) . 's';
			} elseif ( $secs >= 60 ) {
				$avg_session['value'] = floor( $secs / 60 ) . 'm ' . ( $secs % 60 ) . 's';
			} else {
				$avg_session['value'] = $secs . 's';
			}
		}

		return array(
			'sessions'            => $this->format_stat_value( $data['sessions'] ?? array() ),
			'pageviews'           => $this->format_stat_value( $data['pageviews'] ?? array() ),
			'avg_session_length'  => $this->format_stat_value( $avg_session ),
			'bounce_rate'         => $this->format_stat_value( $bounce ),
			'no_of_blog_posts'    => $no_of_blog_posts,
			'no_of_pages_viewed'  => $this->format_stat_value( $data['no_of_pages_viewed'] ?? array() ),
		);
	}

	/**
	 * Process store performance data
	 *
	 * @since 7.10.5
	 * @param array $data Store performance data.
	 * @return array Processed store performance.
	 */
	private function process_store_performance( $data ) {
		$top_selling_product = array();
		
		// Process top_selling_product with new structure (name and total_sales)
		if ( ! empty( $data['top_selling_product'] ) ) {
			if ( is_array( $data['top_selling_product'] ) ) {
				$top_selling_product = array(
					'name'        => ! empty( $data['top_selling_product']['name'] ) ? esc_html( $data['top_selling_product']['name'] ) : '',
					'total_sales' => ! empty( $data['top_selling_product']['total_sales'] ) ? esc_html( $data['top_selling_product']['total_sales'] ) : '0',
				);
			}
		}
		
		return array(
			'last_week_revenue'    => $this->format_stat_value( $data['last_week_revenue'] ?? array() ),
			'last_week_orders'     => $this->format_stat_value( $data['last_week_orders'] ?? array() ),
			'top_selling_product'  => $top_selling_product,
			'average_order_value'  => $this->format_stat_value( $data['average_order_value'] ?? array() ),
			'conversion_rate'      => $this->format_stat_value( $data['conversion_rate'] ?? array() ),
		);
	}

	/**
	 * Process form performance data
	 *
	 * @since 7.10.5
	 * @param array $data Form performance data.
	 * @return array Processed form performance.
	 */
	private function process_form_performance( $data ) {
		return array(
			'total_impressions'     => $this->format_stat_value( $data['total_impressions'] ?? array() ),
			'total_conversions'     => $this->format_stat_value( $data['total_conversions'] ?? array() ),
			'total_conversion_rate' => $this->format_stat_value( $data['total_conversion_rate'] ?? array() ),
		);
	}

	/**
	 * Process organic performance data
	 *
	 * @since 7.10.5
	 * @param array $data Organic performance data.
	 * @return array Processed organic performance.
	 */
	private function process_organic_performance( $data ) {
		$top_keyword = array();
		
		// Process top_keyword with new structure
		if ( ! empty( $data['top_keywords'] ) && is_array( $data['top_keywords'] ) ) {
			$top_keyword = array(
				'keyword'             => ! empty( $data['top_keywords']['keyword'] ) ? esc_html( $data['top_keywords']['keyword'] ) : '',
				'impressions'         => ! empty( $data['top_keywords']['impressions'] ) ? esc_html( $data['top_keywords']['impressions'] ) : '0',
				'previous_impressions' => ! empty( $data['top_keywords']['previous_impressions'] ) ? esc_html( $data['top_keywords']['previous_impressions'] ) : '0',
				'percentage'          => isset( $data['top_keywords']['percentage'] ) ? (float) $data['top_keywords']['percentage'] : 0,
				'increased'           => ! empty( $data['top_keywords']['increased'] ) ? (bool) $data['top_keywords']['increased'] : false,
			);
		} elseif ( ! empty( $data['top_keywords'] ) && is_string( $data['top_keywords'] ) ) {
			// Fallback for old format (string)
			$top_keyword = array(
				'keyword'             => esc_html( $data['top_keywords'] ),
				'impressions'         => '0',
				'previous_impressions' => '0',
				'percentage'          => 0,
				'increased'           => false,
			);
		}
		
		return array(
			'total_impressions' => $this->format_stat_value( $data['total_impressions'] ?? array() ),
			'total_clicks'      => $this->format_stat_value( $data['total_clicks'] ?? array() ),
			'top_keyword'       => $top_keyword,
		);
	}

	/**
	 * Process top keywords data
	 *
	 * @since 7.10.5
	 * @param array $data Top keywords data.
	 * @return array Processed top keywords (limited to 6).
	 */
	private function process_top_keywords( $data ) {
		$keywords = array();
		$count    = 0;

		foreach ( $data as $keyword ) {
			if ( $count >= 6 ) {
				break;
			}
			if ( ! empty( $keyword['keyword'] ) ) {
				$keywords[] = array(
					'keyword'     => esc_html( $keyword['keyword'] ),
					'impressions' => ! empty( $keyword['impressions'] ) ? number_format( (float) $keyword['impressions'] ) : '0',
					'percentage'  => isset( $keyword['percentage'] ) ? (float) $keyword['percentage'] : 0,
					'increased'   => ! empty( $keyword['increased'] ) ? (bool) $keyword['increased'] : false,
				);
				$count++;
			}
		}

		return $keywords;
	}

	/**
	 * Process most popular categories data
	 *
	 * @since 7.10.5
	 * @param array $data Most popular categories data.
	 * @return array Processed categories with names and percentages (limited to 5).
	 */
	private function process_most_popular_categories( $data ) {
		$categories = array();
		$total_views = 0;

		// Calculate total page views
		foreach ( $data as $item ) {
			if ( ! empty( $item['page_views'] ) ) {
				$total_views += (int) $item['page_views'];
			}
		}

		// Process categories and get names from WordPress
		$processed = array();
		foreach ( $data as $item ) {
			if ( empty( $item['page_path'] ) || empty( $item['page_views'] ) ) {
				continue;
			}

			$category_name = $this->get_category_name_from_path( $item['page_path'] );
			if ( $category_name ) {
				$page_views = (int) $item['page_views'];
				if ( ! isset( $processed[ $category_name ] ) ) {
					$processed[ $category_name ] = 0;
				}
				$processed[ $category_name ] += $page_views;
			}
		}

		// Sort by page views descending
		arsort( $processed );

		// Get top 5
		$count = 0;
		foreach ( $processed as $name => $views ) {
			if ( $count >= 5 ) {
				break;
			}
			$percentage = $total_views > 0 ? round( ( $views / $total_views ) * 100, 1 ) : 0;
			$categories[] = array(
				'name'       => esc_html( $name ),
				'percentage' => $percentage . '%',
			);
			$count++;
		}

		return $categories;
	}

	/**
	 * Process most popular post types data
	 *
	 * @since 7.10.5
	 * @param array $data Most popular post types data.
	 * @return array Processed post types with names and impressions (limited to 5).
	 */
	private function process_most_popular_post_types( $data ) {
		$post_types = array();
		$processed  = array();

		// Process post types and get names from WordPress
		foreach ( $data as $item ) {
			if ( empty( $item['page_path'] ) || empty( $item['page_views'] ) ) {
				continue;
			}

			$post_type_name = $this->get_post_type_name_from_path( $item['page_path'] );
			if ( $post_type_name ) {
				$page_views = (int) $item['page_views'];
				if ( ! isset( $processed[ $post_type_name ] ) ) {
					$processed[ $post_type_name ] = 0;
				}
				$processed[ $post_type_name ] += $page_views;
			}
		}

		// Sort by page views descending
		arsort( $processed );

		// Get top 5
		$count = 0;
		foreach ( $processed as $name => $views ) {
			if ( $count >= 5 ) {
				break;
			}
			$post_types[] = array(
				'name'       => esc_html( $name ),
				'impressions' => number_format( $views ),
			);
			$count++;
		}

		return $post_types;
	}

	/**
	 * Process source/medium traffic data
	 *
	 * @since 7.10.5
	 * @param array $data Source/medium traffic data.
	 * @return array Processed source/medium grouped by Source and Medium.
	 */
	private function process_source_medium_traffic( $data ) {
		$traffic = array();

		foreach ( $data as $item ) {
			if ( empty( $item['source_medium'] ) || empty( $item['sessions'] ) ) {
				continue;
			}

			// Parse source/medium (format: "source / medium")
			$parts = explode( ' / ', $item['source_medium'], 2 );
			$source = ! empty( $parts[0] ) ? trim( $parts[0] ) : '';
			$medium = ! empty( $parts[1] ) ? trim( $parts[1] ) : '';

			if ( $source ) {
				$key = $source . ( $medium ? ' / ' . $medium : '' );
				if ( ! isset( $traffic[ $key ] ) ) {
					$traffic[ $key ] = array(
						'source'   => esc_html( $source ),
						'medium'   => esc_html( $medium ),
						'sessions' => 0,
					);
				}
				$traffic[ $key ]['sessions'] += (int) $item['sessions'];
			}
		}

		// Sort by sessions descending
		usort( $traffic, function( $a, $b ) {
			return $b['sessions'] - $a['sessions'];
		} );

		return $traffic;
	}

	/**
	 * Process device breakdown data
	 *
	 * @since 7.10.5
	 * @param array $data Device breakdown data.
	 * @return array Processed device breakdown with percentages.
	 */
	private function process_device_breakdown( $data ) {
		$devices = array();

		foreach ( $data as $item ) {
			if ( empty( $item['device'] ) ) {
				continue;
			}

			$devices[] = array(
				'device'           => esc_html( $item['device'] ),
				'percentage'       => ! empty( $item['percentage'] ) ? round( (float) $item['percentage'], 2 ) . '%' : '0%',
				'percentage_change' => ! empty( $item['percentage_change'] ) ? abs( (float) $item['percentage_change'] ) : ( ! empty( $item['percentage'] ) ? abs( (float) $item['percentage'] ) : 0 ),
				'increased'        => ! empty( $item['increased'] ) ? (bool) $item['increased'] : false,
			);
		}

		return $devices;
	}

	/**
	 * Process top pages data
	 *
	 * @since 7.10.5
	 * @param array $data Top pages data.
	 * @return array Processed top 5 pages with formatted page views.
	 */
	private function process_top_pages( $data ) {
		$pages = array();
		$count = 0;

		foreach ( $data as $page ) {
			if ( $count >= 5 ) {
				break;
			}
			if ( ! empty( $page['page_path'] ) ) {
				$page_views = ! empty( $page['page_views'] ) ? (int) $page['page_views'] : 0;
				$pages[] = array(
					'page_path'  => esc_html( $page['page_path'] ),
					'page_title' => ! empty( $page['page_title'] ) ? esc_html( $page['page_title'] ) : esc_html( $page['page_path'] ),
					'page_views' => $this->format_number_with_decimal( $page_views ),
					'percentage'  => ! empty( $page['percentage'] ) ? (int) $page['percentage'] : 0,
					'increased'   => ! empty( $page['increased'] ) ? (bool) $page['increased'] : false,
				);
				$count++;
			}
		}

		return $pages;
	}

	/**
	 * Process custom dimensions data
	 *
	 * @since 7.10.5
	 * @param array $data Custom dimensions data.
	 * @return array Processed custom dimensions with log in and logout event counts.
	 */
	private function process_custom_dimensions( $data ) {
		$result = array(
			'log_in_count' => 0,
			'logout_count' => 0,
			'percentage' => 0,
			'increased' => false,
		);

		if ( ! is_array( $data ) ) {
			return $result;
		}

		foreach ( $data as $dimension ) {
			if ( ! is_array( $dimension ) || empty( $dimension['action'] ) || ! is_array( $dimension['action'] ) ) {
				continue;
			}

			$action = strtolower( trim( $dimension['action'][0] ) );
			$event_count = ! empty( $dimension['event_count'] ) && is_array( $dimension['event_count'] ) 
				? (int) $dimension['event_count'][0] 
				: 0;

			// Match "log in" or "login"
			if ( $action === 'log in' || $action === 'login' ) {
				$result['log_in_count'] = $event_count;
				// Use event_count_percentage and event_count_increased for the trend
				if ( isset( $dimension['event_count_percentage'] ) ) {
					$result['percentage'] = abs( (float) $dimension['event_count_percentage'] );
				}
				if ( isset( $dimension['event_count_increased'] ) ) {
					$result['increased'] = (bool) $dimension['event_count_increased'];
				}
			}
			// Match "logout" or "log out"
			elseif ( $action === 'logout' || $action === 'log out' ) {
				$result['logout_count'] = $event_count;
			}
		}

		return $result;
	}

	/**
	 * Format stat value with trend data
	 *
	 * @since 7.10.5
	 * @param array $stat Stat data with value, percentage, and increased flag.
	 * @return array Formatted stat value.
	 */
	private function format_stat_value( $stat ) {
		if ( empty( $stat ) || ! is_array( $stat ) ) {
			return array(
				'value'     => '0',
				'percentage' => 0,
				'increased' => false,
			);
		}

		$value = ! empty( $stat['value'] ) ? $stat['value'] : '0';
		
		// Format numeric values
		if ( is_numeric( $value ) ) {
			$value = $this->format_number_with_decimal( (float) $value );
		}

		return array(
			'value'      => $value,
			'percentage' => ! empty( $stat['percentage'] ) ? absint( $stat['percentage'] ) : 0,
			'increased'  => ! empty( $stat['increased'] ) ? (bool) $stat['increased'] : false,
		);
	}

	/**
	 * Format number with decimal notation (e.g., 2500 = 2.5K)
	 *
	 * @since 7.10.5
	 * @param float $num Number to format.
	 * @return string Formatted number.
	 */
	private function format_number_with_decimal( $num ) {
		if ( $num >= 1000000 ) {
			return round( $num / 1000000, 1 ) . 'M';
		} elseif ( $num >= 1000 ) {
			return round( $num / 1000, 1 ) . 'K';
		}
		return number_format( $num );
	}

	/**
	 * Get category name from page path
	 *
	 * @since 7.10.5
	 * @param string $page_path Page path.
	 * @return string|false Category name or false if not found.
	 */
	private function get_category_name_from_path( $page_path ) {
		// Remove leading/trailing slashes
		$page_path = trim( $page_path, '/' );

		// Try to get category from path
		// Common patterns: /category/slug, /collections/slug, etc.
		$parts = explode( '/', $page_path );
		
		// Check if it's a category archive URL
		if ( ! empty( $parts[0] ) ) {
			$slug = end( $parts );
			
			// Try to find category by slug
			$category = get_term_by( 'slug', $slug, 'category' );
			if ( $category && ! is_wp_error( $category ) ) {
				return $category->name;
			}

			// Try product category (WooCommerce)
			if ( taxonomy_exists( 'product_cat' ) ) {
				$category = get_term_by( 'slug', $slug, 'product_cat' );
				if ( $category && ! is_wp_error( $category ) ) {
					return $category->name;
				}
			}

			// Try custom taxonomies
			$taxonomies = get_taxonomies( array( 'public' => true ), 'names' );
			foreach ( $taxonomies as $taxonomy ) {
				if ( 'category' === $taxonomy || 'post_tag' === $taxonomy ) {
					continue;
				}
				$term = get_term_by( 'slug', $slug, $taxonomy );
				if ( $term && ! is_wp_error( $term ) ) {
					return $term->name;
				}
			}
		}

		return false;
	}

	/**
	 * Get post type name from page path
	 *
	 * @since 7.10.5
	 * @param string $page_path Page path.
	 * @return string|false Post type name or false if not found.
	 */
	private function get_post_type_name_from_path( $page_path ) {
		// Remove leading/trailing slashes
		$page_path = trim( $page_path, '/' );

		// Try to get post/page from path
		$post = get_page_by_path( $page_path );
		if ( $post ) {
			$post_type_obj = get_post_type_object( $post->post_type );
			if ( $post_type_obj ) {
				return $post_type_obj->labels->singular_name;
			}
		}

		// Try to get post by slug
		$parts = explode( '/', $page_path );
		$slug = end( $parts );

		$post = get_page_by_path( $slug );
		if ( $post ) {
			$post_type_obj = get_post_type_object( $post->post_type );
			if ( $post_type_obj ) {
				return $post_type_obj->labels->singular_name;
			}
		}

		return false;
	}

	/**
	 * Get blog posts count for last 7 days with comparison to previous week
	 *
	 * @since 7.10.5
	 * @return array Formatted stat value with count, percentage change, and trend.
	 */
	private function get_blog_posts_count_last_7_days() {
		// Get current date range (last 7 days) using site timezone.
		$end_date   = wp_date( 'Y-m-d 23:59:59' );
		$start_date = wp_date( 'Y-m-d 00:00:00', strtotime( '-7 days' ) );

		// Get previous week date range (7 days before that)
		$prev_end_date   = wp_date( 'Y-m-d 23:59:59', strtotime( '-7 days' ) );
		$prev_start_date = wp_date( 'Y-m-d 00:00:00', strtotime( '-14 days' ) );

		// Count posts in last 7 days
		$current_count = $this->count_posts_by_date_range( $start_date, $end_date );

		// Count posts in previous 7 days
		$prev_count = $this->count_posts_by_date_range( $prev_start_date, $prev_end_date );

		// Calculate percentage change
		$percentage = 0;
		if ( $prev_count > 0 ) {
			$percentage = ( ( $current_count - $prev_count ) / $prev_count ) * 100;
		} elseif ( $current_count > 0 ) {
			$percentage = 100; // If previous was 0 and current > 0, it's 100% increase
		}

		$increased = $current_count >= $prev_count;

		return array(
			'value'      => (string) $current_count,
			'percentage' => absint( round( $percentage ) ),
			'increased'  => $increased,
		);
	}

	/**
	 * Count posts by date range
	 *
	 * @since 7.10.5
	 * @param string $start_date Start date in Y-m-d H:i:s format.
	 * @param string $end_date End date in Y-m-d H:i:s format.
	 * @return int Number of posts.
	 */
	private function count_posts_by_date_range( $start_date, $end_date ) {
		$args = array(
			'post_type'      => 'post',
			'post_status'    => 'publish',
			'date_query'     => array(
				array(
					'after'     => $start_date,
					'before'    => $end_date,
					'inclusive' => true,
				),
			),
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
		);

		$query = new WP_Query( $args );
		return $query->post_count;
	}

		/**
	 * Check if a plugin update is available.
	 *
	 * Verifies if there's a new version of the plugin available for update.
	 *
	 * @since 8.19.0
	 * @access private
	 *
	 * @param boolean $force_update Whether to force a check with WordPress.org.
	 * @return boolean True if an update is available, false otherwise.
	 */
	private function plugin_has_update_notice( $force_update = false ) {
		$plugin_slug = plugin_basename( MonsterInsights::get_instance()->file );

		if ( $force_update === true ) {
			wp_update_plugins();
		}

		// Get the update_plugins transient.
		$update_plugins = get_site_transient( 'update_plugins' );
		// Bail if we don't have update information or response data.
		if ( empty( $update_plugins ) || empty( $update_plugins->response ) || ! is_array( $update_plugins->response ) ) {
			return false;
		}

		// If our plugin is not present in the response array, there is no update available.
		if ( ! isset( $update_plugins->response[ $plugin_slug ] ) || ! is_object( $update_plugins->response[ $plugin_slug ] ) ) {
			return false;
		}

		$plugin_info = $update_plugins->response[ $plugin_slug ];

		// Ensure the new_version property exists and is not empty.
		if ( empty( $plugin_info->new_version ) ) {
			return false;
		}

		// Return the new version string so it can be used in templates.
		return (string) $plugin_info->new_version;
	}

	/**
	 * Format date range for header display (e.g., "21st - 27th September")
	 *
	 * @since 7.10.5
	 * @param string $start_date Start date in Y-m-d format.
	 * @param string $end_date End date in Y-m-d format.
	 * @return array Formatted date strings with 'start' and 'end' keys.
	 */
	private function format_date_range_for_header( $start_date, $end_date ) {
		if ( empty( $start_date ) || empty( $end_date ) ) {
			return array( 'start' => '', 'end' => '' );
		}

		$start_timestamp = strtotime( $start_date );
		$end_timestamp = strtotime( $end_date );
		
		if ( false === $start_timestamp || false === $end_timestamp ) {
			return array( 'start' => '', 'end' => '' );
		}

		$start_day   = (int) wp_date( 'j', $start_timestamp );
		$start_month = wp_date( 'F', $start_timestamp );
		$end_day     = (int) wp_date( 'j', $end_timestamp );
		$end_month   = wp_date( 'F', $end_timestamp );

		// Add ordinal suffix helper
		$get_ordinal = function( $day ) {
			$suffix = 'th';
			if ( $day % 10 == 1 && $day % 100 != 11 ) {
				$suffix = 'st';
			} elseif ( $day % 10 == 2 && $day % 100 != 12 ) {
				$suffix = 'nd';
			} elseif ( $day % 10 == 3 && $day % 100 != 13 ) {
				$suffix = 'rd';
			}
			return $day . $suffix;
		};

		// If both dates are in the same month, show month only once at the end
		if ( $start_month === $end_month ) {
			return array(
				'start' => $get_ordinal( $start_day ),
				'end'   => $get_ordinal( $end_day ) . ' ' . $end_month,
			);
		} else {
			// Different months, show both
			return array(
				'start' => $get_ordinal( $start_day ) . ' ' . $start_month,
				'end'   => $get_ordinal( $end_day ) . ' ' . $end_month,
			);
		}
	}

	/**
	 * Get the latest blog posts from the MonsterInsights RSS feed.
	 *
	 * Fetches the RSS feed from monsterinsights.com and extracts the titles and links
	 * of the last 3 blog posts.
	 *
	 * @since 7.10.5
	 * @access private
	 *
	 * @param array $info_block Info block data containing blog_posts_source.
	 * @return array Array of the latest 3 blog posts with title, link, excerpt, and featured image, or empty array on failure.
	 */
	private function get_latest_blog_posts_from_feed( $info_block ) {
		$source = 'https://monsterinsights.com';

		if ( ! empty( $info_block['blog_posts_source'] ) ) {
			$source = $info_block['blog_posts_source'];
		}

		$rest_url = $source . '/wp-json/wp/v2/posts?per_page=3&_embed'; // Fetch last 3 posts and embed media

		$response = wp_remote_get( $rest_url );

		if ( is_wp_error( $response ) ) {
			return array(); // Return empty array on error
		}

		$body       = wp_remote_retrieve_body( $response );
		$posts_data = json_decode( $body, true );
		$posts      = array();

		if ( empty( $posts_data ) || ! is_array( $posts_data ) ) {
			return array();
		}

		foreach ( $posts_data as $post_item ) {
			$featured_image_url = '';

			if ( isset( $post_item['_embedded']['wp:featuredmedia'] ) && ! empty( $post_item['_embedded']['wp:featuredmedia'] ) ) {
				$featured_media = $post_item['_embedded']['wp:featuredmedia'][0];

				if ( isset( $featured_media['media_details']['sizes']['large']['source_url'] ) ) {
					$featured_image_url = $featured_media['media_details']['sizes']['large']['source_url'];
				}
			}

			if ( empty( $post_item['title']['rendered'] ) || empty( $post_item['link'] ) ) {
				continue;
			}

			$new = array(
				'title'            => esc_html( $post_item['title']['rendered'] ),
				'link'             => esc_url( $post_item['link'] ),
				'excerpt'          => ''
			);
			
			if ( ! empty( $post_item['excerpt']['rendered'] ) ) {
				$new['excerpt'] = esc_html( wp_trim_words( wp_strip_all_tags( $post_item['excerpt']['rendered'] ), 15, '...' ) );
			}

			if ( ! empty( $featured_image_url ) ) {
				$new['featured_image'] = esc_url_raw( $featured_image_url );
			}

			$posts[] = $new;
		}

		return $posts;
	}

	/**
	 * reset email summaries options
	 *
	 * @since 7.10.5
	 */
	public function reset_email_summaries_options( $key, $value ) {
		if ( isset( $key ) && $key === 'email_summaries' && isset( $value ) && $value === 'off' ) {
			$default_email = array(
				'email' => get_option( 'admin_email' ),
			);
			monsterinsights_update_option( 'summaries_email_addresses', array( $default_email ) );
			monsterinsights_update_option( 'summaries_header_image', '' );
		}
	}
}
