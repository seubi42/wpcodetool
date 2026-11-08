<?php

namespace Smbb\WpCodeTool\Mail;

use Smbb\WpCodeTool\Admin\CommWidget;
use Smbb\WpCodeTool\Admin\Pages\MailPage;

defined('ABSPATH') || exit;

/**
 * Santé des envois : dernier succès, dernier échec, échecs d'affilée.
 *
 * - Alerte rouge en haut de l'admin (administrateurs) dès qu'un envoi échoue et tant qu'aucun
 *   envoi n'a réussi depuis ; « Masquer 24 h » la fait taire (elle revient si ça échoue encore après).
 * - Widget « E-mails » sur l'accueil de l'admin : état, envoyés / en erreur sur 7 jours, liens vers
 *   le test et le journal.
 *
 * Une alerte par e-mail n'aurait pas de sens : elle partirait par le même chemin, en panne.
 */
final class MailHealth
{
    const OPTION = 'smbb_codetool_mail_health';
    const WIDGET = 'smbb_codetool_mail';

    public static function hooks()
    {
        add_action('wp_mail_succeeded', array(__CLASS__, 'onSucceeded'), 20);
        add_action('wp_mail_failed', array(__CLASS__, 'onFailed'), 20);
        add_action('admin_notices', array(__CLASS__, 'notice'));
        add_action('admin_post_smbb_codetool_mail_snooze', array(__CLASS__, 'snooze'));
        add_action('wp_dashboard_setup', array(__CLASS__, 'registerWidget'));
    }

    public static function state()
    {
        $state = get_option(self::OPTION, array());

        return array_merge(array(
            'last_ok' => 0,
            'last_fail' => 0,
            'last_error' => '',
            'fails' => 0,
            'snooze_until' => 0,
        ), is_array($state) ? $state : array());
    }

    private static function store(array $state)
    {
        update_option(self::OPTION, $state, false);
    }

    public static function onSucceeded()
    {
        $state = self::state();
        $state['last_ok'] = time();
        $state['fails'] = 0;
        self::store($state);
    }

    public static function onFailed($error)
    {
        $state = self::state();
        $state['last_fail'] = time();
        $state['last_error'] = is_wp_error($error) ? $error->get_error_message() : __('Erreur inconnue', 'smbb-wpcodetool');
        $state['fails'] = (int) $state['fails'] + 1;
        self::store($state);
    }

    /**
     * En panne = le dernier envoi a échoué.
     */
    public static function broken()
    {
        $state = self::state();

        return $state['last_fail'] > 0 && $state['last_fail'] >= $state['last_ok'];
    }

    public static function notice()
    {
        $settings = Mailer::settings();

        if (empty($settings['alerts']) || !current_user_can('manage_options') || !self::broken()) {
            return;
        }

        $state = self::state();

        if (CommWidget::snoozed($state)) {
            return;
        }

        CommWidget::notice(array(
            'kind' => 'mail',
            'title' => __('Les e-mails du site ne partent plus.', 'smbb-wpcodetool'),
            'message' => sprintf(
                _n('Dernier échec il y a %1$s (%2$d échec d\'affilée) : %3$s', 'Dernier échec il y a %1$s (%2$d échecs d\'affilée) : %3$s', (int) $state['fails'], 'smbb-wpcodetool'),
                human_time_diff($state['last_fail'], time()),
                (int) $state['fails'],
                wp_trim_words($state['last_error'], 30)
            ),
            'test_url' => MailPage::url('test'),
            'test_label' => __('Tester l\'envoi', 'smbb-wpcodetool'),
            'failed_url' => MailPage::url('log', array('status' => 'failed')),
            'failed_label' => __('Voir les e-mails en erreur', 'smbb-wpcodetool'),
            'snooze_url' => wp_nonce_url(admin_url('admin-post.php?action=smbb_codetool_mail_snooze'), 'smbb_codetool_mail_snooze'),
        ));
    }

    public static function snooze()
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Action non autorisée.', 'smbb-wpcodetool'));
        }

        check_admin_referer('smbb_codetool_mail_snooze');
        $state = self::state();
        $state['snooze_until'] = time() + DAY_IN_SECONDS;
        self::store($state);

        wp_safe_redirect(wp_get_referer() ? wp_get_referer() : admin_url());
        exit;
    }

    public static function registerWidget()
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        if (!apply_filters('smbb_wpcodetool_mail_widget', true)) {
            return;
        }

        wp_add_dashboard_widget(self::WIDGET, __('E-mails', 'smbb-wpcodetool'), array(__CLASS__, 'renderWidget'), null, null, 'normal', 'high');
    }

    public static function renderWidget()
    {
        $settings = Mailer::settings();
        $state = self::state();
        $reason = Mailer::inactiveReason();

        if (self::broken()) {
            $tone = 'is-bad';
            $title = __('Les e-mails ne partent plus', 'smbb-wpcodetool');
            $copy = sprintf(__('Depuis %1$s : %2$s', 'smbb-wpcodetool'), human_time_diff($state['last_fail'], time()), wp_trim_words($state['last_error'], 18));
        } elseif ($reason !== '') {
            $tone = 'is-idle';
            $title = !empty($settings['enabled']) ? __('Réglages incomplets', 'smbb-wpcodetool') : __('Envoi par WordPress', 'smbb-wpcodetool');
            $copy = $reason;
        } else {
            $tone = 'is-good';
            $transport = Mailer::transport();
            $title = __('Les e-mails partent bien', 'smbb-wpcodetool');
            $copy = $transport ? $transport->summary(Mailer::transportSettings()) : '';

            if ($state['last_ok']) {
                $copy .= ' · ' . sprintf(__('dernier envoi il y a %s', 'smbb-wpcodetool'), human_time_diff($state['last_ok'], time()));
            }
        }

        CommWidget::render(array(
            'kind' => 'mail',
            'tone' => $tone,
            'title' => $title,
            'copy' => $copy,
            'log' => !empty($settings['log']),
            'stats' => MailLog::stats(7),
            'noun_sent' => __('e-mails envoyés', 'smbb-wpcodetool'),
            'noun_failed' => __('en erreur', 'smbb-wpcodetool'),
            'test_label' => __('Tester l\'envoi', 'smbb-wpcodetool'),
            'urls' => array(
                'sent' => MailPage::url('log', array('status' => 'sent')),
                'failed' => MailPage::url('log', array('status' => 'failed')),
                'test' => MailPage::url('test'),
                'log' => MailPage::url('log'),
                'settings' => MailPage::url('settings'),
            ),
        ));
    }
}
