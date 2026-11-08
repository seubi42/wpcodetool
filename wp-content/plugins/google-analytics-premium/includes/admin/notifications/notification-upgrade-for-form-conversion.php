<?php

/**
 * Nudge sub-Pro sites toward Forms tracking.
 *
 * When a supported form plugin is loaded, the copy names it, because a site
 * already collecting submissions with no conversion data on them is the
 * audience this notification exists for. Sites with no form plugin keep the
 * generic copy.
 *
 * Recurrence: 90 Days. The previous 20-day cadence was judged too frequent for
 * an upgrade prompt (MI-251). Each showing still expires after 18 days, as it
 * did before: the recurrence changed, the display window did not.
 *
 * @since 7.12.3
 * @since 11.2.0 Gated on a detected form plugin; recurrence raised from 20 to 90 days.
 */
final class MonsterInsights_Notification_Upgrade_For_Form_Conversion extends MonsterInsights_Notification_Event {

	public $notification_id = 'monsterinsights_notification_upgrade_for_form_conversion';
	public $notification_interval = 90; // in days
	public $notification_type = array( 'basic', 'lite', 'plus' );
	public $notification_category = 'insight';
	public $notification_priority = 3;

	/**
	 * How long each showing stays in the drawer, in days.
	 *
	 * The value the parent's `interval - 2` formula produced at the old 20-day
	 * recurrence. Pinned here so raising the recurrence does not widen the window.
	 */
	const DISPLAY_WINDOW_DAYS = 18;

	/**
	 * Keep the display window at its previous length.
	 *
	 * The parent derives how long a shown notification stays visible from
	 * $notification_interval minus two days, so raising the recurrence to 90
	 * would otherwise leave each showing in the drawer for 88 days.
	 *
	 * @since 11.2.0
	 */
	public function __construct() {
		parent::__construct();

		$this->notification_active_for = date( 'm/d/Y', strtotime( '+' . self::DISPLAY_WINDOW_DAYS . ' day' ) ); // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date -- We need this to depend on the runtime timezone.
	}

	/**
	 * Build Notification
	 *
	 * @return array $notification notification is ready to add
	 *
	 * @since 7.12.3
	 */
	public function prepare_notification_data( $notification ) {
		$form_plugin = monsterinsights_get_active_form_plugin();
		$link_open   = '<a href="' . $this->get_upgrade_url() . '" target="_blank">';

		if ( '' !== $form_plugin ) {
			/* translators: %s: Name of the form plugin detected on the site, e.g. WPForms. */
			$notification['title'] = sprintf( __( 'Track Your %s Conversions', 'google-analytics-premium' ), $form_plugin );
			/* translators: %1$s: Name of the form plugin detected on the site. %2$s and %3$s wrap the link to the upgrade page. */
			$notification['content'] = sprintf( __( 'You are already collecting submissions with %1$s. Upgrade to %2$sMonsterInsights Pro%3$s to see which of your forms convert and where those visitors came from.', 'google-analytics-premium' ), $form_plugin, $link_open, '</a>' );
		} else {
			$notification['title'] = __( 'Easily Track Form Conversions', 'google-analytics-premium' );
			/* translators: %1$s and %2$s wrap the link to the upgrade page. */
			$notification['content'] = sprintf( __( 'Track your website\'s form conversion rates by upgrading to %1$sMonsterInsights Pro%2$s.', 'google-analytics-premium' ), $link_open, '</a>' );
		}

		$notification['btns'] = array(
			"get_monsterinsights_pro" => array(
				'url'         => $this->get_upgrade_url(),
				'text'        => __( 'Upgrade Now', 'google-analytics-premium' ),
				'is_external' => true,
			),
		);

		return $notification;
	}

}

// initialize the class
new MonsterInsights_Notification_Upgrade_For_Form_Conversion();
