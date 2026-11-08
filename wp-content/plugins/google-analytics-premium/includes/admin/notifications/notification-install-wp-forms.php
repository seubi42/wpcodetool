<?php

/**
 * Add notification after 1 week of lite version installation
 * Recurrence: 30 Days
 *
 * @since 7.12.3
 */
final class MonsterInsights_Notification_Install_WPForms extends MonsterInsights_Notification_Event {

	public $notification_id = 'monsterinsights_notification_install_wpforms';
	public $notification_interval = 30; // in days
	public $notification_type = array( 'basic', 'lite', 'master', 'plus', 'pro' );
	public $notification_icon = 'star';
	public $notification_category = 'insight';
	public $notification_priority = 2;

	/**
	 * Build Notification
	 *
	 * @return array $notification notification is ready to add
	 *
	 * @since 7.12.3
	 */
	public function prepare_notification_data( $notification ) {

		// Do not suggest installing a form plugin on a site that already runs one.
		if ( ! monsterinsights_site_has_form_plugin() ) {
			$notification['title']   = __( 'Create a Contact Form in Only Minutes', 'google-analytics-premium' );
			$notification['content'] = __( 'Install WPForms and create contact forms in a matter of minutes.', 'google-analytics-premium' );

			return $notification;
		}

		return false;
	}

}

// initialize the class
new MonsterInsights_Notification_Install_WPForms();
