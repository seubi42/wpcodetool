<?php

namespace WPMailSMTP\Pro\Admin\Pages;

use WPMailSMTP\Admin\Area;
use WPMailSMTP\Admin\Pages\MiscTab as MiscTabLite;
use WPMailSMTP\Helpers\UI;
use WPMailSMTP\Options;
use WPMailSMTP\Pro\Emails\RateLimiting\RateLimiting;

/**
 * Class MiscTab.
 *
 * @since 4.0.0
 */
class MiscTab extends MiscTabLite {

	/**
	 * Display rate limit settings.
	 *
	 * @since 4.0.0
	 */
	protected function display_rate_limit_settings() {

		$options = Options::init();

		$rate_limit_periods = [
			'minute' => esc_html__( 'Max emails per minute', 'wp-mail-smtp-pro' ),
			'hour'   => esc_html__( 'Max emails per hour', 'wp-mail-smtp-pro' ),
			'day'    => esc_html__( 'Max emails per day', 'wp-mail-smtp-pro' ),
			'week'   => esc_html__( 'Max emails per week', 'wp-mail-smtp-pro' ),
			'month'  => esc_html__( 'Max emails per month', 'wp-mail-smtp-pro' ),
		];
		?>
		<div class="wp-mail-smtp-setting-group">
			<div id="wp-mail-smtp-setting-row-rate_limit" class="wp-mail-smtp-setting-row wp-mail-smtp-clear">
				<div class="wp-mail-smtp-setting-label">
					<label for="<?php echo 'wp-mail-smtp-setting-' . esc_attr( $this->get_slug() ) . '-rate_limit_enabled'; ?>">
						<?php esc_html_e( 'Email Rate Limiting', 'wp-mail-smtp-pro' ); ?>
					</label>
				</div>
				<div class="wp-mail-smtp-setting-field">
					<?php
					UI::toggle(
						[
							'name'    => 'wp-mail-smtp[rate_limit][enabled]',
							'id'      => 'wp-mail-smtp-setting-' . esc_attr( $this->get_slug() ) . '-rate_limit_enabled',
							'value'   => 'true',
							'checked' => RateLimiting::is_enabled(),
						]
					);
					?>
					<p class="desc">
					<?php
					printf(
						wp_kses( /* translators: %1$s - Documentation URL. */
							__( 'Limit the number of emails this site will send in each time interval (per minute, hour, day, week and month). Emails that will cross those set limits will be queued and sent as soon as your limits allow. <a href="%1$s" target="_blank" rel="noopener noreferrer">Learn More</a>', 'wp-mail-smtp-pro' ),
							[
								'a' => [
									'href'   => [],
									'rel'    => [],
									'target' => [],
								],
							]
						),
						esc_url(
							wp_mail_smtp()->get_utm_url(
								'https://wpmailsmtp.com/docs/a-complete-guide-to-miscellaneous-settings/#email-rate-limiting',
								[
									'medium'  => 'misc-settings',
									'content' => 'Email Rate Limiting - support article',
								]
							)
						)
					);
					?>
					</p>
				</div>
			</div>
			<div id="wp-mail-smtp-setting-row-rate_limit_periods" style="display: <?php echo (bool) $options->get( 'rate_limit', 'enabled' ) ? 'block' : 'none'; ?>">
				<div class="wp-mail-smtp-setting-label"></div>
				<div class="wp-mail-smtp-setting-field">
					<?php $this->display_enqueued_emails_count_notice(); ?>
					<?php foreach ( $rate_limit_periods as $period => $label ) : ?>
						<div class="wp-mail-smtp-setting-row wp-mail-smtp-setting-row-text wp-mail-smtp-clear">
							<div class="wp-mail-smtp-setting-label">
								<label for="wp-mail-smtp-setting-rate_limit_<?php echo esc_attr( $period ); ?>"><?php echo esc_html( $label ); ?></label>
							</div>
							<div class="wp-mail-smtp-setting-field">
								<input type="number"
									   name="wp-mail-smtp[rate_limit][<?php echo esc_attr( $period ); ?>]"
									   value="<?php echo esc_attr( $options->get( 'rate_limit', $period ) ); ?>"
									   min="0"
								/>
							</div>
						</div>
					<?php endforeach; ?>
				</div>
			</div>
		</div>
		<script>
			jQuery( '#wp-mail-smtp-setting-misc-rate_limit_enabled' ).on( 'change', function() {
				if ( jQuery( this ).is( ':checked' ) ) {
					jQuery( '#wp-mail-smtp-setting-row-rate_limit_periods' ).show();
				} else {
					jQuery( '#wp-mail-smtp-setting-row-rate_limit_periods' ).hide();
				}
			} );
		</script>
		<?php
	}

	/**
	 * Display the Log Blocked Emails setting after the Do Not Send setting field description.
	 *
	 * @since 4.10.1
	 */
	protected function display_after_do_not_send_setting_field_description() {

		$options = Options::init();

		$is_log_blocked_emails_const_defined = $options->is_const_defined( 'logs', 'log_blocked_emails' );
		?>
		<div style="margin-top: 10px; display: <?php echo (bool) $options->get( 'general', 'do_not_send' ) ? 'block' : 'none'; ?>;">
			<?php
			UI::toggle(
				[
					'name'     => 'wp-mail-smtp[logs][log_blocked_emails]',
					'id'       => 'wp-mail-smtp-setting-log_blocked_emails',
					'value'    => 'true',
					'label'    => esc_html__( 'Log Blocked Emails', 'wp-mail-smtp-pro' ),
					'checked'  => (bool) $options->get( 'logs', 'log_blocked_emails' ),
					'disabled' => $is_log_blocked_emails_const_defined,
				]
			);
			?>
			<p class="desc">
				<?php esc_html_e( 'When selected, emails blocked by the "Do Not Send" option will be logged in the Email Log.', 'wp-mail-smtp-pro' ); ?>
			</p>
			<?php if ( $is_log_blocked_emails_const_defined ) : ?>
				<p class="desc">
					<?php echo $options->get_const_set_message( 'WPMS_LOG_BLOCKED_EMAILS' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Whether the Email Summaries setting can be configured.
	 *
	 * @since 4.10.1
	 *
	 * @return bool
	 */
	protected function is_summary_report_email_setting_available() {

		return ! empty( Options::init()->get( 'logs', 'enabled' ) );
	}

	/**
	 * Get the notice explaining what the Email Summaries setting requires before it can be configured.
	 *
	 * @since 4.10.1
	 *
	 * @return string
	 */
	protected function get_summary_report_email_setting_requirement_notice() {

		return wp_kses(
			sprintf( /* translators: %s - Email Log settings url. */
				__( 'Please enable <a href="%s">Email Logging</a> first, before this setting can be configured.', 'wp-mail-smtp-pro' ),
				esc_url( wp_mail_smtp()->get_admin()->get_admin_page_url( Area::SLUG . '&tab=logs' ) )
			),
			[
				'a' => [
					'href' => [],
				],
			]
		);
	}

	/**
	 * Process tab form submission ($_POST).
	 *
	 * @since 4.0.0
	 *
	 * @param array $data Tab data specific for the plugin ($_POST).
	 */
	public function process_post( $data ) {

		if ( empty( $data['rate_limit']['enabled'] ) ) {
			$data['rate_limit']['enabled'] = false;
		}

		if ( empty( $data['logs']['log_blocked_emails'] ) ) {
			$data['logs']['log_blocked_emails'] = false;
		}

		parent::process_post( $data );
	}

	/**
	 * Display a notice with the current count
	 * of enqueued emails.
	 *
	 * @since 4.0.0
	 */
	private function display_enqueued_emails_count_notice() {

		$queue = wp_mail_smtp()->get_queue();

		// We're checking for the queue being enabled explictly,
		// just in case a 3rd party filter is turning it off.
		if ( ! $queue->is_enabled() ) {
			return;
		}

		$enqueued_emails_count = $queue->count_queued_emails();

		if ( $enqueued_emails_count === 0 ) {
			return;
		}

		$message = sprintf( /* translators: %s: enqueued email count. */
			_n( 'Currently there is %s email in the queue.', 'Currently there are %s emails in the queue.', $enqueued_emails_count, 'wp-mail-smtp-pro' ),
			$enqueued_emails_count
		);
		?>
		<div class="notice inline notice-inline wp-mail-smtp-notice notice-info" style="margin: 10px 0 0;">
			<p>
				<?php echo esc_html( $message ); ?>
			</p>
		</div>
		<?php
	}
}
