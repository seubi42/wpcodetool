<?php

namespace WPMailSMTP\Pro\Providers\Sendlayer;

use WPMailSMTP\ConnectionInterface;
use WPMailSMTP\Options;
use WPMailSMTP\Pro\AdditionalConnections\Connection as AdditionalConnection;
use WPMailSMTP\Pro\AdditionalConnections\ConnectionOptions;
use WPMailSMTP\Providers\Sendlayer\QuickConnect as LiteQuickConnect;
use WPMailSMTP\WP;

/**
 * SendLayer Quick Connect with additional and backup connection support.
 *
 * @since 4.10.1
 */
class QuickConnect extends LiteQuickConnect {

	/**
	 * Connect mode that creates a new connection and assigns it as the backup connection.
	 *
	 * @since 4.10.1
	 *
	 * @var string
	 */
	private const MODE_BACKUP_MAILER = 'backup_mailer';

	/**
	 * Get the connect modes accepted on top of the default one.
	 *
	 * @since 4.10.1
	 *
	 * @return string[]
	 */
	protected function get_allowed_modes() {

		return [ self::MODE_BACKUP_MAILER ];
	}

	/**
	 * Get the connection the SendLayer API key is stored in.
	 *
	 * @since 4.10.1
	 *
	 * @param string $mode Connect mode.
	 *
	 * @return ConnectionInterface|false
	 */
	protected function get_target_connection( $mode ) {

		if ( $mode === self::MODE_BACKUP_MAILER ) {
			$connection_id = uniqid();

			( new ConnectionOptions( $connection_id ) )->set(
				[
					'connection' => [
						'name' => __( 'Backup', 'wp-mail-smtp-pro' ),
					],
					'mail'       => [
						'from_name'  => (string) Options::init()->get( 'mail', 'from_name' ),
						'from_email' => (string) Options::init()->get( 'mail', 'from_email' ),
					],
				]
			);

			return new AdditionalConnection( $connection_id );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$connection_id = ! empty( $_GET['connection_id'] ) ? sanitize_key( $_GET['connection_id'] ) : '';

		if ( ! empty( $connection_id ) ) {
			return wp_mail_smtp()->get_connections_manager()->get_connection( $connection_id, false );
		}

		return parent::get_target_connection( $mode );
	}

	/**
	 * Finish the connection after the SendLayer settings are saved.
	 *
	 * @since 4.10.1
	 *
	 * @param ConnectionInterface $connection The connection the settings were saved to.
	 * @param string              $mode       Connect mode.
	 *
	 * @return string The result code to redirect with.
	 */
	protected function complete_connection( $connection, $mode ) {

		if ( $mode === self::MODE_BACKUP_MAILER ) {
			Options::init()->set(
				[
					'backup_connection' => [
						'connection_id' => $connection->get_id(),
					],
				],
				false,
				false
			);

			return 'backup_success';
		}

		return parent::complete_connection( $connection, $mode );
	}

	/**
	 * Display admin notices for SendLayer Quick Connect results.
	 *
	 * @since 4.10.1
	 */
	public function display_notice() {

		if (
			wp_mail_smtp()->get_admin()->is_admin_page() &&
			isset( $_GET['sendlayer_quick_connect_result'] ) && // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			sanitize_key( $_GET['sendlayer_quick_connect_result'] ) === 'backup_success' // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		) {
			WP::add_admin_notice(
				esc_html__( 'SendLayer is now set up as your Backup Connection. Emails that fail to send with your primary connection will be sent via SendLayer.', 'wp-mail-smtp-pro' ),
				WP::ADMIN_NOTICE_SUCCESS
			);

			return;
		}

		parent::display_notice();
	}
}
