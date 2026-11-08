<?php

namespace Smbb\WpCodeTool\Security;

use Smbb\WpCodeTool\Admin\CommWidget;
use Smbb\WpCodeTool\Admin\Pages\SecurityPage;

defined('ABSPATH') || exit;

/**
 * Widget « Sécurité » de l'accueil WordPress, même présentation que les widgets E-mails et SMS
 * (Admin\CommWidget) : état général, double authentification par rôle, Turnstile, restriction d'IP,
 * IP bloquées, dernier mauvais mot de passe, connexions réussies / échouées sur 7 jours.
 *
 * Compteurs : option smbb_codetool_security_stats (jour => ok, fail), 14 jours gardés.
 * Dernier échec : option smbb_codetool_security_last_fail (at, user, ip).
 * Filtre smbb_wpcodetool_security_widget (false = pas de widget).
 */
final class SecurityWidget
{
    const WIDGET = 'smbb_codetool_security';
    const STATS = 'smbb_codetool_security_stats';
    const LAST_FAIL = 'smbb_codetool_security_last_fail';

    /**
     * Toujours accroché, même avec SMBB_SECURITY_OFF : le widget doit justement le montrer.
     */
    public static function hooks()
    {
        add_action('wp_dashboard_setup', array(__CLASS__, 'register'));
        add_action('wp_login', array(__CLASS__, 'onLogin'), 20);
        add_action('wp_login_failed', array(__CLASS__, 'onFailed'), 20, 2);
    }

    // ------------------------------------------------------------------ Compteurs

    public static function onLogin()
    {
        self::count('ok');
    }

    public static function onFailed($username, $error = null)
    {
        // Champs laissés vides : pas une vraie tentative.
        if ($error instanceof \WP_Error && array_intersect($error->get_error_codes(), array('empty_username', 'empty_password'))) {
            return;
        }

        self::count('fail');
        update_option(self::LAST_FAIL, array(
            'at' => time(),
            'user' => sanitize_user((string) $username),
            'ip' => Security::ip(),
            'code' => $error instanceof \WP_Error ? (string) $error->get_error_code() : '',
        ), false);
    }

    private static function count($key)
    {
        $stats = get_option(self::STATS, array());
        $stats = is_array($stats) ? $stats : array();
        $day = wp_date('Y-m-d');

        if (!isset($stats[$day])) {
            $stats[$day] = array('ok' => 0, 'fail' => 0);
        }

        $stats[$day][$key]++;
        ksort($stats);
        update_option(self::STATS, array_slice($stats, -14, null, true), false);
    }

    /**
     * Même forme que MailLog::stats() : sent / failed / days (Y-m-d => sent, failed).
     */
    public static function stats($days = 7)
    {
        $stored = get_option(self::STATS, array());
        $stored = is_array($stored) ? $stored : array();
        $out = array('sent' => 0, 'failed' => 0, 'days' => array());

        for ($i = $days - 1; $i >= 0; $i--) {
            $day = wp_date('Y-m-d', time() - $i * DAY_IN_SECONDS);
            $ok = isset($stored[$day]['ok']) ? (int) $stored[$day]['ok'] : 0;
            $fail = isset($stored[$day]['fail']) ? (int) $stored[$day]['fail'] : 0;
            $out['days'][$day] = array('sent' => $ok, 'failed' => $fail);
            $out['sent'] += $ok;
            $out['failed'] += $fail;
        }

        return $out;
    }

    // ------------------------------------------------------------------ Widget

    public static function register()
    {
        if (!current_user_can('manage_options') || !apply_filters('smbb_wpcodetool_security_widget', true)) {
            return;
        }

        wp_add_dashboard_widget(self::WIDGET, __('Sécurité', 'smbb-wpcodetool'), array(__CLASS__, 'render'), null, null, 'normal', 'high');
    }

    public static function render()
    {
        $twofa = Security::part('twofa');
        $limit = Security::part('limit');
        $off = Security::off();
        $mfa_on = !$off && !empty($twofa['enabled']);
        $mfa_roles = array();

        foreach ((array) $twofa['roles'] as $role => $rule) {
            if ($rule['channel'] !== 'off' && isset(wp_roles()->roles[$role])) {
                $mfa_roles[$role] = translate_user_role(wp_roles()->roles[$role]['name']) . ' (' . Security::channels()[$rule['channel']] . ')';
            }
        }

        $admins_covered = $mfa_on && isset($mfa_roles['administrator']);
        $limit_on = !$off && !empty($limit['enabled']);
        $turnstile_on = !$off && Turnstile::active();
        $blocked = $limit_on ? LoginLimiter::active() : array();
        $white = Security::ipList($limit['whitelist']);
        $black = Security::ipList($limit['blacklist']);
        $last = get_option(self::LAST_FAIL);

        // État général.
        if ($off) {
            $tone = 'is-bad';
            $title = __('Protection coupée', 'smbb-wpcodetool');
            $copy = __('Accès de secours actif (SMBB_SECURITY_OFF dans wp-config.php) : à retirer dès que possible.', 'smbb-wpcodetool');
        } elseif (!$limit_on && !$mfa_on) {
            $tone = 'is-bad';
            $title = __('Connexion peu protégée', 'smbb-wpcodetool');
            $copy = __('Ni double authentification ni limitation des tentatives.', 'smbb-wpcodetool');
        } elseif ($admins_covered && $limit_on) {
            $tone = 'is-good';
            $title = __('Connexion protégée', 'smbb-wpcodetool');
            $copy = $turnstile_on
                ? __('Double authentification des admins, blocage des tentatives et Turnstile.', 'smbb-wpcodetool')
                : __('Double authentification des admins et blocage des tentatives.', 'smbb-wpcodetool');
        } else {
            $tone = 'is-idle';
            $title = __('Protection partielle', 'smbb-wpcodetool');
            $copy = !$admins_covered
                ? __('Les administrateurs n\'ont pas de double authentification.', 'smbb-wpcodetool')
                : __('Les tentatives de connexion ne sont pas limitées.', 'smbb-wpcodetool');
        }

        $rows = array(
            array(
                'icon' => 'dashicons-smartphone',
                'label' => __('Double authentification', 'smbb-wpcodetool'),
                'value' => $mfa_on ? ($mfa_roles ? implode(', ', $mfa_roles) : __('activée, aucun rôle choisi', 'smbb-wpcodetool')) : __('coupée', 'smbb-wpcodetool'),
                'tone' => $admins_covered ? 'is-good' : ($mfa_on ? 'is-idle' : 'is-bad'),
                'url' => SecurityPage::url('2fa'),
            ),
            array(
                'icon' => 'dashicons-lock',
                'label' => __('Limitation des tentatives', 'smbb-wpcodetool'),
                'value' => $limit_on ? sprintf(__('%1$d échecs en %2$d min → %3$d min de blocage', 'smbb-wpcodetool'), (int) $limit['attempts'], (int) $limit['window'], (int) $limit['lockout']) : __('coupée', 'smbb-wpcodetool'),
                'tone' => $limit_on ? 'is-good' : 'is-bad',
                'url' => SecurityPage::url('login'),
            ),
            array(
                'icon' => 'dashicons-shield',
                'label' => __('Turnstile', 'smbb-wpcodetool'),
                'value' => $turnstile_on ? __('actif', 'smbb-wpcodetool') : (!empty(Security::part('turnstile')['enabled']) ? __('clés manquantes', 'smbb-wpcodetool') : __('coupé', 'smbb-wpcodetool')),
                'tone' => $turnstile_on ? 'is-good' : 'is-idle',
                'url' => SecurityPage::url('turnstile'),
            ),
            array(
                'icon' => 'dashicons-list-view',
                'label' => __('Restriction d\'IP', 'smbb-wpcodetool'),
                'value' => ($white || $black)
                    ? sprintf(__('%1$d en liste blanche, %2$d en liste noire', 'smbb-wpcodetool'), count($white), count($black))
                    : __('aucune liste', 'smbb-wpcodetool'),
                'tone' => ($white || $black) ? 'is-good' : 'is-idle',
                'url' => SecurityPage::url('blocked'),
            ),
            array(
                'icon' => 'dashicons-warning',
                'label' => __('Dernier mauvais mot de passe', 'smbb-wpcodetool'),
                'value' => is_array($last) && !empty($last['at'])
                    ? sprintf(__('il y a %1$s · %2$s · %3$s', 'smbb-wpcodetool'), human_time_diff((int) $last['at'], time()), $last['user'] !== '' ? $last['user'] : '—', $last['ip'])
                    : __('aucun enregistré', 'smbb-wpcodetool'),
                'tone' => is_array($last) && !empty($last['at']) && (int) $last['at'] > time() - DAY_IN_SECONDS ? 'is-idle' : 'is-good',
                'title' => is_array($last) && !empty($last['at']) ? wp_date(get_option('date_format') . ' H:i', (int) $last['at']) : '',
                'url' => self::journalUrl(),
            ),
        );

        CommWidget::render(array(
            'kind' => 'security',
            'tone' => $tone,
            'title' => $title,
            'copy' => $copy,
            'rows' => $rows,
            'log' => true,
            'stats' => self::stats(7),
            'noun_sent' => __('connexions réussies', 'smbb-wpcodetool'),
            'noun_failed' => __('connexions refusées', 'smbb-wpcodetool'),
            /* translators: 1: connexions réussies, 2: échecs */
            'bar_title' => __('%1$d réussies, %2$d échouées', 'smbb-wpcodetool'),
            'extra' => array(
                'value' => number_format_i18n(count($blocked)),
                'label' => _n('IP bloquée', 'IP bloquées', count($blocked), 'smbb-wpcodetool'),
                'url' => SecurityPage::url('blocked'),
                'title' => $blocked ? implode(', ', array_slice(array_keys($blocked), 0, 10)) : __('Aucune adresse bloquée en ce moment', 'smbb-wpcodetool'),
                'alert' => (bool) $blocked,
            ),
            'test_label' => __('IP bloquées', 'smbb-wpcodetool'),
            'test_icon' => 'dashicons-dismiss',
            'log_label' => __('Journal', 'smbb-wpcodetool'),
            'urls' => array(
                'sent' => self::journalUrl(),
                'failed' => self::journalUrl(),
                'test' => SecurityPage::url('blocked'),
                'log' => self::journalUrl(),
                'settings' => SecurityPage::url('login'),
            ),
        ));
    }

    /**
     * Journal d'activité (autre module de CodeTool) s'il est là, sinon la page IP bloquées.
     */
    private static function journalUrl()
    {
        global $submenu;
        $exists = false;

        foreach (isset($submenu['smbb-wpcodetool']) ? (array) $submenu['smbb-wpcodetool'] : array() as $item) {
            if (isset($item[2]) && $item[2] === 'smbb-wpcodetool-activity') {
                $exists = true;
                break;
            }
        }

        return $exists ? admin_url('admin.php?page=smbb-wpcodetool-activity&category=login') : SecurityPage::url('blocked');
    }
}
