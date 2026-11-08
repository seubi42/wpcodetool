<?php

namespace Smbb\WpCodeTool\Sms;

use Smbb\WpCodeTool\Admin\CommWidget;
use Smbb\WpCodeTool\Admin\Pages\MailPage;

defined('ABSPATH') || exit;

/**
 * Alerte et widget « SMS », jumeaux de ceux des e-mails (Mail\MailHealth).
 * État : champs last_ok / last_fail / last_error / fails / snooze_until de l'option smbb_codetool_sms.
 */
final class SmsHealth
{
    const WIDGET = 'smbb_codetool_sms';

    public static function hooks()
    {
        add_action('admin_notices', array(__CLASS__, 'notice'));
        add_action('admin_post_smbb_codetool_sms_snooze', array(__CLASS__, 'snooze'));
        add_action('wp_dashboard_setup', array(__CLASS__, 'registerWidget'));
    }

    public static function broken()
    {
        $settings = Sms::settings();

        return $settings['last_fail'] > 0 && $settings['last_fail'] >= $settings['last_ok'];
    }

    public static function notice()
    {
        $settings = Sms::settings();

        if (empty($settings['alerts']) || !current_user_can('manage_options') || !self::broken() || CommWidget::snoozed($settings)) {
            return;
        }

        CommWidget::notice(array(
            'kind' => 'sms',
            'title' => __('Les SMS ne partent plus.', 'smbb-wpcodetool'),
            'message' => sprintf(
                _n('Dernier échec il y a %1$s (%2$d échec d\'affilée) : %3$s', 'Dernier échec il y a %1$s (%2$d échecs d\'affilée) : %3$s', max(1, (int) $settings['fails']), 'smbb-wpcodetool'),
                human_time_diff((int) $settings['last_fail'], time()),
                max(1, (int) $settings['fails']),
                wp_trim_words($settings['last_error'], 30)
            ),
            'test_url' => MailPage::url('test', array(), 'sms'),
            'test_label' => __('Tester l\'envoi', 'smbb-wpcodetool'),
            'failed_url' => MailPage::url('log', array('status' => 'failed'), 'sms'),
            'failed_label' => __('Voir les SMS en erreur', 'smbb-wpcodetool'),
            'snooze_url' => wp_nonce_url(admin_url('admin-post.php?action=smbb_codetool_sms_snooze'), 'smbb_codetool_sms_snooze'),
        ));
    }

    public static function snooze()
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Action non autorisée.', 'smbb-wpcodetool'));
        }

        check_admin_referer('smbb_codetool_sms_snooze');
        $settings = Sms::settings();
        $settings['snooze_until'] = time() + DAY_IN_SECONDS;
        Sms::save($settings);

        wp_safe_redirect(wp_get_referer() ? wp_get_referer() : admin_url());
        exit;
    }

    public static function registerWidget()
    {
        if (!current_user_can('manage_options') || !apply_filters('smbb_wpcodetool_sms_widget', true)) {
            return;
        }

        wp_add_dashboard_widget(self::WIDGET, __('SMS', 'smbb-wpcodetool'), array(__CLASS__, 'renderWidget'), null, null, 'normal', 'high');
    }

    public static function renderWidget()
    {
        $settings = Sms::settings();
        $ready = Sms::ready();

        if (self::broken()) {
            $tone = 'is-bad';
            $title = __('Les SMS ne partent plus', 'smbb-wpcodetool');
            $copy = sprintf(__('Depuis %1$s : %2$s', 'smbb-wpcodetool'), human_time_diff((int) $settings['last_fail'], time()), wp_trim_words($settings['last_error'], 18));
        } elseif ($ready !== true) {
            $tone = 'is-idle';
            $title = __('SMS non configurés', 'smbb-wpcodetool');
            $copy = (string) $ready;
        } else {
            $tone = 'is-good';
            $provider = Sms::provider();
            $title = __('Les SMS partent bien', 'smbb-wpcodetool');
            $copy = $provider ? $provider->summary(Sms::providerSettings()) : '';

            if (!empty($settings['last_ok'])) {
                $copy .= ' · ' . sprintf(__('dernier envoi il y a %s', 'smbb-wpcodetool'), human_time_diff((int) $settings['last_ok'], time()));
            }
        }

        // 3e cadran : la conso du mois en cours (registre des coûts, gardé à vie).
        $month = SmsCosts::monthly(1);
        $spent = $month['months'] ? end($month['months']) : null;
        $extra = array(
            'value' => $spent && $month['currency'] !== '' ? SmsCosts::money($spent['cost'], $month['currency']) : '—',
            'label' => __('ce mois', 'smbb-wpcodetool'),
            'url' => MailPage::url('stats', array(), 'sms'),
            'title' => $spent ? sprintf(_n('%s SMS ce mois-ci', '%s SMS ce mois-ci', $spent['n'], 'smbb-wpcodetool'), number_format_i18n($spent['n'])) . ($spent['unpriced'] ? ' · ' . sprintf(__('%d sans prix encore', 'smbb-wpcodetool'), $spent['unpriced']) : '') : '',
        );

        CommWidget::render(array(
            'kind' => 'sms',
            'extra' => $extra,
            'tone' => $tone,
            'title' => $title,
            'copy' => $copy,
            'log' => !empty($settings['log']),
            'stats' => SmsLog::stats(7),
            'noun_sent' => __('SMS envoyés', 'smbb-wpcodetool'),
            'noun_failed' => __('en erreur', 'smbb-wpcodetool'),
            'test_label' => __('Tester l\'envoi', 'smbb-wpcodetool'),
            'urls' => array(
                'sent' => MailPage::url('log', array('status' => 'sent'), 'sms'),
                'failed' => MailPage::url('log', array('status' => 'failed'), 'sms'),
                'test' => MailPage::url('test', array(), 'sms'),
                'log' => MailPage::url('log', array(), 'sms'),
                'settings' => MailPage::url('settings', array(), 'sms'),
            ),
        ));
    }
}
