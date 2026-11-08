<?php

namespace Smbb\WpCodeTool\Forms;

use Smbb\WpCodeTool\Admin\CommWidget;
use Smbb\WpCodeTool\Admin\Pages\FormsPage;

defined('ABSPATH') || exit;

/**
 * Widget « Formulaires » de l'accueil WordPress, même présentation que E-mails, SMS et Sécurité
 * (Admin\CommWidget) : nouveaux contacts pas encore lus (les 5 derniers, cliquables), contacts reçus
 * et indésirables sur 7 jours, accès direct aux entrées non lues.
 *
 * Filtre smbb_wpcodetool_forms_widget (false = pas de widget).
 */
final class FormsWidget
{
    const WIDGET = 'smbb_codetool_forms';
    const SHOWN = 5;

    public static function hooks()
    {
        add_action('wp_dashboard_setup', array(__CLASS__, 'register'));
    }

    public static function register()
    {
        if (!Forms::ready() || !current_user_can(Forms::capability()) || !apply_filters('smbb_wpcodetool_forms_widget', true)) {
            return;
        }

        $unread = EntryRepository::unreadCount();
        $title = __('Formulaires', 'smbb-wpcodetool');

        if ($unread > 0) {
            $title .= ' <span class="awaiting-mod count-' . $unread . '" style="display:inline-block;min-width:18px;height:18px;margin-left:6px;padding:0 5px;border-radius:9px;background:#d63638;color:#fff;font-size:11px;line-height:18px;text-align:center;box-sizing:border-box">' . number_format_i18n($unread) . '</span>';
        }

        wp_add_dashboard_widget(self::WIDGET, $title, array(__CLASS__, 'render'), null, null, 'normal', 'high');
    }

    /**
     * Entrées reçues et indésirables par jour : même forme que MailLog::stats().
     */
    public static function stats($days = 7)
    {
        global $wpdb;
        $out = array('sent' => 0, 'failed' => 0, 'days' => array());
        $first = wp_date('Y-m-d', time() - ($days - 1) * DAY_IN_SECONDS);

        for ($i = $days - 1; $i >= 0; $i--) {
            $out['days'][wp_date('Y-m-d', time() - $i * DAY_IN_SECONDS)] = array('sent' => 0, 'failed' => 0);
        }

        $rows = $wpdb->get_results($wpdb->prepare('SELECT created_at, status FROM ' . Forms::entriesTable() . ' WHERE created_at >= %s', get_gmt_from_date($first . ' 00:00:00')), ARRAY_A); // phpcs:ignore

        foreach ((array) $rows as $row) {
            $day = get_date_from_gmt((string) $row['created_at'], 'Y-m-d'); // created_at en UTC

            if (!isset($out['days'][$day])) {
                continue;
            }

            $key = $row['status'] === 'spam' ? 'failed' : 'sent';
            $out['days'][$day][$key]++;
            $out[$key]++;
        }

        return $out;
    }

    public static function render()
    {
        $unread = EntryRepository::unreadCount();
        $forms = FormRepository::choices();
        $latest = EntryRepository::query(EntryRepository::normalizeFilters(array()), 1, 1);
        $last = $latest['rows'] ? $latest['rows'][0] : null;
        $rows = array();

        if (!$forms) {
            $tone = 'is-idle';
            $title = __('Aucun formulaire', 'smbb-wpcodetool');
            $copy = __('Créez un formulaire de contact en deux clics, ou reprenez ceux de WPForms.', 'smbb-wpcodetool');
        } elseif ($unread > 0) {
            $tone = 'is-idle';
            $title = sprintf(_n('%s nouveau contact à lire', '%s nouveaux contacts à lire', $unread, 'smbb-wpcodetool'), number_format_i18n($unread));
            $copy = $last ? sprintf(__('Dernier reçu il y a %s.', 'smbb-wpcodetool'), self::ago($last['created_at'])) : '';
        } else {
            $tone = 'is-good';
            $title = __('Tout est lu', 'smbb-wpcodetool');
            $copy = $last
                ? sprintf(__('Dernier contact il y a %1$s, via « %2$s ».', 'smbb-wpcodetool'), self::ago($last['created_at']), $forms[$last['form_id']] ?? '#' . $last['form_id'])
                : __('Aucun contact reçu pour l\'instant.', 'smbb-wpcodetool');
        }

        if ($unread > 0) {
            $list = EntryRepository::query(EntryRepository::normalizeFilters(array('status' => 'unread')), 1, self::SHOWN);

            foreach ($list['rows'] as $entry) {
                $summary = EntryRepository::summary($entry, 2);
                $rows[] = array(
                    'icon' => !empty($entry['starred']) ? 'dashicons-star-filled' : 'dashicons-email',
                    'label' => $forms[$entry['form_id']] ?? sprintf(__('Formulaire %d', 'smbb-wpcodetool'), $entry['form_id']),
                    'value' => ($summary !== '' ? $summary . ' · ' : '') . self::ago($entry['created_at']),
                    'tone' => 'is-idle',
                    'url' => FormsPage::url('entries', array('entry' => $entry['id'])),
                    'title' => get_date_from_gmt($entry['created_at'], get_option('date_format') . ' H:i'),
                );
            }

            if ($unread > self::SHOWN) {
                $more = $unread - self::SHOWN;
                $rows[] = array(
                    'icon' => 'dashicons-ellipsis',
                    'label' => sprintf(_n('%s autre', '%s autres', $more, 'smbb-wpcodetool'), number_format_i18n($more)),
                    'value' => __('voir tous les non lus', 'smbb-wpcodetool'),
                    'tone' => 'is-idle',
                    'url' => FormsPage::url('entries', array('status' => 'unread')),
                );
            }
        }

        CommWidget::render(array(
            'kind' => 'forms',
            'tone' => $tone,
            'title' => $title,
            'copy' => $copy,
            'rows' => $rows,
            'log' => true,
            'stats' => self::stats(7),
            'noun_sent' => __('contacts reçus', 'smbb-wpcodetool'),
            'noun_failed' => __('indésirables', 'smbb-wpcodetool'),
            /* translators: 1: contacts reçus, 2: indésirables */
            'bar_title' => __('%1$d reçus, %2$d indésirables', 'smbb-wpcodetool'),
            'extra' => array(
                'value' => number_format_i18n($unread),
                'label' => _n('non lu', 'non lus', $unread, 'smbb-wpcodetool'),
                'url' => FormsPage::url('entries', array('status' => 'unread')),
                'title' => __('Entrées pas encore ouvertes dans l\'admin', 'smbb-wpcodetool'),
                'alert' => $unread > 0,
            ),
            'test_label' => $forms ? __('Nouveaux contacts', 'smbb-wpcodetool') : __('Créer un formulaire', 'smbb-wpcodetool'),
            'test_icon' => $forms ? 'dashicons-email-alt2' : 'dashicons-plus-alt2',
            'log_label' => __('Toutes les entrées', 'smbb-wpcodetool'),
            'urls' => array(
                'sent' => FormsPage::url('entries'),
                'failed' => FormsPage::url('entries', array('status' => 'spam')),
                'test' => $forms ? FormsPage::url('entries', array('status' => 'unread')) : FormsPage::url(),
                'log' => FormsPage::url('entries'),
                'settings' => FormsPage::url(),
            ),
        ));
    }

    private static function ago($mysql)
    {
        $time = strtotime((string) $mysql . ' UTC'); // created_at en UTC

        return human_time_diff($time ? $time : time(), time());
    }
}
