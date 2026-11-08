<?php

namespace WPMailSMTP\Pro\Admin\EmailSendingErrors;

use WPMailSMTP\Admin\EmailSendingErrors\EmailSendingErrors as LiteEmailSendingErrors;
use WPMailSMTP\ConnectionInterface;
use WPMailSMTP\EmailSendingDebug;
use WPMailSMTP\Options;

/**
 * Email sending error banners and notices for additional and backup connections.
 *
 * @since 4.10.1
 */
class EmailSendingErrors extends LiteEmailSendingErrors {

	/**
	 * Get the failure records the banners and the notice report, keyed by connection id.
	 *
	 * @since 4.10.1
	 *
	 * @return array
	 */
	protected function get_failure_records() {

		return EmailSendingDebug::get();
	}

	/**
	 * Print the in-plugin banners for the failure records.
	 *
	 * @since 4.10.1
	 *
	 * @param array $records Failure records keyed by connection id.
	 */
	protected function print_error_banners( $records ) {

		// Which connection's record gets the primary (full banner) slot on this
		// page: the AC edit-view's `connection_id`, the just-tested connection on
		// the Test Email tab, or 'primary' everywhere else.
		$connection_id = $this->get_primary_banner_connection_id();

		// Render the full banner, except on the Connections list page, where
		// primary failures route to the one-liner notice and the full banner is
		// reserved for the per-connection edit view.
		if (
			isset( $records[ $connection_id ] ) &&
			! ( $this->is_connections_tab() && $connection_id === 'primary' )
		) {
			$this->print_primary_banner(
				$connection_id === 'primary' ? 'primary' : 'additional',
				$records[ $connection_id ],
				$this->get_connection_name( $connection_id ),
				$connection_id
			);

			unset( $records[ $connection_id ] );
		}

		// AC edit page and Test Email tab are connection-focused surfaces:
		// they show only the active connection's banner. Skip stacking the
		// one-liner notices for every other failing connection. Read-only
		// presence check; the connection_id GET param is a navigation key.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if (
			( $this->is_connections_tab() && isset( $_GET['connection_id'] ) ) ||
			$this->is_test_tab()
		) {
			return;
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		// Primary was either rendered above (and removed from $records) or is
		// suppressed on the Connections list page. Either way, the loop below
		// only handles additional connections.
		unset( $records['primary'] );

		foreach ( $records as $connection_id => $record ) {
			$connection = wp_mail_smtp()->get_connections_manager()->get_connection( $connection_id, false );

			if ( $connection !== false ) {
				$this->print_additional_connection_note( $record, $connection );
			}
		}
	}

	/**
	 * Build the banner title.
	 *
	 * @since 4.10.1
	 *
	 * @param array  $record          Failure record.
	 * @param string $role            Role: 'primary' or 'additional'.
	 * @param string $connection_name Display name for the failing connection.
	 *
	 * @return string Title HTML (with bolded interpolations).
	 */
	protected function get_banner_title( $record, $role, $connection_name ) {

		$is_test    = ( isset( $record['context'] ) && $record['context'] === 'test' );
		$rescued    = ( isset( $record['status'] ) && $record['status'] === 'sent' );
		$is_primary = ( $role === 'primary' );
		$name_html  = '<strong>' . esc_html( $connection_name ) . '</strong>';

		if ( $is_primary && $rescued ) {
			return $is_test
				? esc_html__( 'Heads up! Your primary connection failed to send the last test email. Your backup connection sent it successfully', 'wp-mail-smtp-pro' )
				: esc_html__( 'Heads up! Your primary connection failed to send the last email. Your backup connection sent it successfully', 'wp-mail-smtp-pro' );
		}

		if ( ! $is_primary && $rescued ) {
			return $is_test
				? sprintf( /* translators: %s: the connection name, bolded. */
					__( 'Heads up! %s failed to send the last test email. Your backup connection sent it successfully', 'wp-mail-smtp-pro' ),
					$name_html
				)
				: sprintf( /* translators: %s: the connection name, bolded. */
					__( 'Heads up! %s failed to send the last email. Your backup connection sent it successfully', 'wp-mail-smtp-pro' ),
					$name_html
				);
		}

		if ( ! $is_primary && ! $rescued ) {
			return $is_test
				? sprintf( /* translators: %s: connection name, bolded. */
					__( 'Heads up! The last test email your site attempted to send via %s was unsuccessful', 'wp-mail-smtp-pro' ),
					$name_html
				)
				: sprintf( /* translators: %s: the additional connection name, bolded. */
					__( 'Heads up! The last email your site attempted to send via %s was unsuccessful', 'wp-mail-smtp-pro' ),
					$name_html
				);
		}

		return parent::get_banner_title( $record, $role, $connection_name );
	}

	/**
	 * Get the URL the cross-admin notice links to.
	 *
	 * @since 4.10.1
	 *
	 * @param array $failed_records Failed regular-context records keyed by connection id.
	 *
	 * @return string
	 */
	protected function get_global_error_notice_url( $failed_records ) {

		$connection_id = array_keys( $failed_records )[0];

		if ( count( $failed_records ) === 1 && $connection_id !== 'primary' ) {
			return add_query_arg(
				[
					'page'          => 'wp-mail-smtp',
					'tab'           => 'connections',
					'mode'          => 'edit',
					'connection_id' => $connection_id,
				],
				admin_url( 'admin.php' )
			);
		}

		return parent::get_global_error_notice_url( $failed_records );
	}

	/**
	 * Resolve the connection_id when viewing an Additional Connection's edit page.
	 *
	 * Returns an empty string when not on the AC edit view. Read-only access to
	 * request superglobals, no state changes are made here.
	 *
	 * @since 4.10.1
	 *
	 * @return string
	 */
	private function get_primary_banner_connection_id() {

		$connection_id = 'primary';

		// Read-only banner-target resolution during admin render. Submission
		// nonce is verified by TestTab::process_post() before any side effects,
		// and the AC edit view's connection_id is a navigation parameter (not
		// processed input).
		// phpcs:disable WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended
		if ( $this->is_connections_tab() && isset( $_GET['connection_id'] ) ) {
			$connection_id = sanitize_key( wp_unslash( $_GET['connection_id'] ) );
		} elseif ( $this->is_test_tab() && isset( $_POST['wp-mail-smtp']['test']['connection'] ) ) {
			$connection_id = sanitize_key( wp_unslash( $_POST['wp-mail-smtp']['test']['connection'] ) );
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended

		return $connection_id;
	}

	/**
	 * Look up a connection's display name (primary or additional).
	 *
	 * @since 4.10.1
	 *
	 * @param string $connection_id Connection ID.
	 *
	 * @return string
	 */
	private function get_connection_name( $connection_id ) {

		if ( $connection_id === 'primary' ) {
			return esc_html__( 'Primary Connection', 'wp-mail-smtp-pro' );
		}

		$connection = wp_mail_smtp()->get_connections_manager()->get_connection( $connection_id, false );

		if ( $connection ) {
			return $connection->get_name();
		}

		return esc_html__( 'an additional connection', 'wp-mail-smtp-pro' );
	}

	/**
	 * Render the verbose one-liner notice for an additional connection. On the
	 * General Settings page these stack vertically under the primary banner.
	 *
	 * @since 4.10.1
	 *
	 * @param array               $record     Failure record.
	 * @param ConnectionInterface $connection Additional connection object.
	 */
	private function print_additional_connection_note( $record, $connection ) {

		$connection_id   = $connection->get_id();
		$connection_name = $connection->get_name();

		if ( $connection_name === '' ) {
			$connection_name = esc_html__( 'an additional connection', 'wp-mail-smtp-pro' );
		}

		$severity_class = ( isset( $record['status'] ) && $record['status'] === 'sent' )
			? 'notice-warning'
			: 'notice-error';

		$edit_url = add_query_arg(
			[
				'page'          => 'wp-mail-smtp',
				'tab'           => 'connections',
				'mode'          => 'edit',
				'connection_id' => $connection_id,
			],
			admin_url( 'admin.php' )
		);

		$is_backup     = $this->is_backup_connection( $connection_id );
		$name_html     = '<strong>' . esc_html( $connection_name ) . '</strong>';
		$rescued       = ( isset( $record['status'] ) && $record['status'] === 'sent' );
		$is_test       = ( isset( $record['context'] ) && $record['context'] === 'test' );
		$test_word     = '<strong>' . esc_html__( 'test', 'wp-mail-smtp-pro' ) . '</strong>';
		$backup_prefix = $is_backup
			? sprintf( /* translators: %s: connection name, bolded. */
				__( 'your backup connection %s', 'wp-mail-smtp-pro' ),
				$name_html
			)
			: $name_html;

		if ( $rescued ) {
			$copy = sprintf( /* translators: %s: connection display name. */
				__( '%s failed to send the last email. Your backup connection sent it successfully.', 'wp-mail-smtp-pro' ),
				$backup_prefix
			);
		} elseif ( $is_test ) {
			$copy = sprintf( /* translators: %1$s: literal word "test", bolded. %2$s: connection display name. */
				__( 'The last %1$s email your site attempted to send via %2$s was unsuccessful.', 'wp-mail-smtp-pro' ),
				$test_word,
				$name_html
			);
		} else {
			$copy = sprintf( /* translators: %s: connection display name. */
				__( 'The last email your site attempted to send via %s was unsuccessful.', 'wp-mail-smtp-pro' ),
				$backup_prefix
			);
		}

		$manage_html = wp_kses(
			sprintf(
				/* translators: %s - link to the Additional Connection Settings page. */
				__( 'Manage it on the %s page.', 'wp-mail-smtp-pro' ),
				sprintf(
					'<a href="%s">%s</a>',
					esc_url( $edit_url ),
					esc_html__( 'Additional Connection Settings', 'wp-mail-smtp-pro' )
				)
			),
			[ 'a' => [ 'href' => [] ] ]
		);

		printf(
			'<div class="notice %1$s is-dismissible wpms-email-sending-errors-one-liner" data-connection-id="%2$s">' .
			'<p>%3$s %4$s</p>' .
			'</div>',
			esc_attr( $severity_class ),
			esc_attr( $connection_id ),
			wp_kses( $copy, [ 'strong' => [] ] ),
			$manage_html // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		);
	}

	/**
	 * Whether a given additional-connection id is currently configured as the
	 * backup connection. Returns false when Pro is inactive or no backup is set.
	 *
	 * @since 4.10.1
	 *
	 * @param string $connection_id Connection ID.
	 *
	 * @return bool
	 */
	private function is_backup_connection( $connection_id ) {

		$backup_id = Options::init()->get( 'backup_connection', 'connection_id' );

		return ! empty( $backup_id ) && $backup_id === $connection_id;
	}

	/**
	 * Whether we're currently viewing the Additional Connections tab on Settings.
	 *
	 * Settings > Connections is `page=wp-mail-smtp&tab=connections`; the AC tab is
	 * NOT a registered WP admin page slug, so `is_admin_page('connections')` falls
	 * back to matching the Settings page (any tab). This helper does the
	 * tab-aware check.
	 *
	 * @since 4.10.1
	 *
	 * @return bool
	 */
	private function is_connections_tab() {

		if ( ! wp_mail_smtp()->get_admin()->is_admin_page( 'general' ) ) {
			return false;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';

		return $tab === 'connections';
	}
}
