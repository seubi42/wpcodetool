<?php

namespace WPMailSMTP\Pro\Admin\SetupWizard;

use WPMailSMTP\Admin\SetupWizard\Settings as LiteSettings;

/**
 * Setup wizard settings with the Pro mailers, Email Log, alerts and license state.
 *
 * @since 4.10.1
 */
class Settings extends LiteSettings {

	/**
	 * Build the read-only `meta` bucket the client reads but never saves.
	 *
	 * @since 4.10.1
	 *
	 * @param array $settings Full options tree from Options::get_all().
	 *
	 * @return array
	 */
	protected function get_meta( $settings ) {

		$meta = parent::get_meta( $settings );

		$meta['license'] = [
			'is_valid' => $this->is_license_valid( $settings ),
		];

		return $meta;
	}

	/**
	 * The token paths that must all be present for each connection mode.
	 *
	 * @since 4.10.1
	 *
	 * @return array[]
	 */
	protected function get_mailer_connection_map() {

		return [
			'gmail'   => [
				'is_authorized'                 => [ 'access_token', 'refresh_token' ],
				'is_one_click_setup_authorized' => [ 'one_click_setup_credentials' ],
			],
			'outlook' => [
				'is_authorized'                 => [ 'access_token', 'refresh_token' ],
				'is_one_click_setup_authorized' => [ 'one_click_setup_credentials' ],
			],
			'zoho'    => [
				'is_authorized' => [ 'access_token', 'refresh_token' ],
			],
		];
	}

	/**
	 * The secret keys of each mailer group, which the wizard can write but never read back.
	 *
	 * @since 4.10.3
	 *
	 * @return array[]
	 */
	protected function get_secret_keys() {

		return array_merge(
			parent::get_secret_keys(),
			[
				'amazonses' => [ 'client_secret' ],
				'outlook'   => [ 'client_secret' ],
				'zoho'      => [ 'client_secret' ],
			]
		);
	}

	/**
	 * The option group/key paths the wizard client reads and writes.
	 *
	 * @since 4.10.1
	 *
	 * @return array[]
	 */
	protected function get_allowed_keys() {

		return array_merge(
			parent::get_allowed_keys(),
			[
				'amazonses'   => [ 'client_id', 'client_secret', 'region' ],
				'gmail'       => [ 'client_id', 'client_secret', 'user_details', 'one_click_setup_enabled', 'one_click_setup_user_details' ],
				'outlook'     => [ 'client_id', 'client_secret', 'user_details', 'one_click_setup_enabled', 'one_click_setup_user_details' ],
				'zoho'        => [ 'client_id', 'client_secret', 'domain', 'user_details' ],
				'logs'        => [ 'enabled', 'log_email_content', 'save_attachments', 'open_email_tracking', 'click_link_tracking' ],
				'alert_email' => [ 'enabled', 'connections' ],
			]
		);
	}

	/**
	 * Whether a valid, active license is stored.
	 *
	 * @since 4.10.1
	 *
	 * @param array $settings Full options tree from Options::get_all().
	 *
	 * @return bool
	 */
	private function is_license_valid( $settings ) {

		$license = isset( $settings['license'] ) ? $settings['license'] : [];

		return ! empty( $license['key'] ) &&
			empty( $license['is_expired'] ) &&
			empty( $license['is_disabled'] ) &&
			empty( $license['is_invalid'] ) &&
			empty( $license['is_limit_reached'] );
	}
}
