<?php

namespace Smbb\Passflow\Admin;

use Smbb\Passflow\Repository\Slots;
use Smbb\Passflow\Repository\Lookup;
use Smbb\Passflow\Support\Roles;

defined('ABSPATH') || exit;

/**
 * Bouton « ticket » dans la barre noire d'administration (ordinateur et téléphone) : ouvre la
 * gestion mobile de l'atelier du jour. Plusieurs ateliers aujourd'hui : petit menu (et, sur
 * téléphone, une page de choix) ; aucun : la page de choix propose le prochain.
 */
final class TodayBar
{
    const NODE = 'passflow-today';

    /** @var array<int,array<string,mixed>>|null */
    private static $today = null;

    public static function hooks()
    {
        add_action('admin_bar_menu', array(__CLASS__, 'node'), 35);
        add_action('admin_head', array(__CLASS__, 'style'));
        add_action('wp_head', array(__CLASS__, 'style'));
    }

    public static function allowed()
    {
        return is_user_logged_in() && (Roles::canManage() || current_user_can(Roles::CAP_HOST));
    }

    /**
     * Ateliers d'aujourd'hui que l'utilisateur peut ouvrir, par heure de début.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function today()
    {
        if (self::$today !== null) {
            return self::$today;
        }

        self::$today = array();

        if (!\Smbb\Passflow\Support\Schema::ready()) {
            return self::$today;
        }

        $day = current_time('Y-m-d');

        foreach (Slots::between($day, $day) as $slot) {
            if ($slot['status'] !== 'cancelled' && Actions::canSeeSlot((int) $slot['id'])) {
                self::$today[] = $slot;
            }
        }

        usort(self::$today, function ($a, $b) {
            return strcmp((string) $a['start_time'], (string) $b['start_time']);
        });

        return self::$today;
    }

    public static function url($slot_id = 0)
    {
        return $slot_id ? View::url('passflow_portal', array('creneau' => (int) $slot_id)) : View::url('passflow_portal', array('aujourdhui' => 1));
    }

    /**
     * @param \WP_Admin_Bar $bar
     */
    public static function node($bar)
    {
        if (!self::allowed()) {
            return;
        }

        $d = 'smbb-passflow';
        $today = self::today();
        $count = count($today);
        $label = $count === 1 ? self::label($today[0]) : ($count ? sprintf(__("%d ateliers aujourd'hui", $d), $count) : __("Pas d'atelier aujourd'hui", $d));

        $bar->add_node(array(
            'id' => self::NODE,
            'title' => '<span class="ab-icon" aria-hidden="true"></span><span class="ab-label">' . esc_html__('Atelier du jour', $d) . '</span>'
                . ($count > 1 ? '<span class="passflow-ab-count">' . $count . '</span>' : ''),
            'href' => $count === 1 ? self::url((int) $today[0]['id']) : self::url(),
            'meta' => array('title' => $label, 'class' => $count ? 'has-today' : 'no-today'),
        ));

        foreach ($today as $slot) {
            $bar->add_node(array(
                'parent' => self::NODE,
                'id' => self::NODE . '-' . (int) $slot['id'],
                'title' => esc_html(self::label($slot)),
                'href' => self::url((int) $slot['id']),
            ));

            if (Roles::canManage() && DayBoard::available($slot)) {
                $bar->add_node(array(
                    'parent' => self::NODE,
                    'id' => self::NODE . '-jj-' . (int) $slot['id'],
                    'title' => '&nbsp;&nbsp;↳ ' . esc_html__('Suivi tablette jour J', $d),
                    'href' => DayBoard::url((int) $slot['id']),
                ));
            }
        }
    }

    public static function label(array $slot)
    {
        $activity = Lookup::atelier($slot['activity_id']);
        $hours = Lookup::slotHours($slot, true, __('toute la journée', 'smbb-passflow'));

        return trim(($hours !== '' ? $hours . ' · ' : '') . ($activity ? $activity['name'] : __('Atelier', 'smbb-passflow')));
    }

    public static function style()
    {
        if (!is_admin_bar_showing() || !self::allowed()) {
            return;
        }

        echo '<style id="passflow-today-bar">'
            . '#wpadminbar #wp-admin-bar-passflow-today .ab-icon:before{content:"\f524";top:2px}'
            . '#wpadminbar #wp-admin-bar-passflow-today.no-today .ab-icon:before{opacity:.6}'
            . '#wpadminbar .passflow-ab-count{display:inline-block;min-width:18px;height:18px;margin-left:6px;padding:0 5px;border-radius:9px;background:#d63638;color:#fff;font-size:11px;line-height:18px;text-align:center;box-sizing:border-box}'
            . '@media screen and (max-width:782px){'
            . '#wpadminbar li#wp-admin-bar-passflow-today{display:block;position:relative}'
            . '#wpadminbar #wp-admin-bar-passflow-today .ab-label{display:none}'
            . '#wpadminbar #wp-admin-bar-passflow-today>.ab-item{width:52px;padding:0;text-align:center}'
            . '#wpadminbar .passflow-ab-count{position:absolute;top:6px;right:4px;margin:0}'
            . '#wpadminbar #wp-admin-bar-passflow-today .ab-sub-wrapper{display:none!important}'
            . '}</style>';
    }

    /**
     * Page de choix (?aujourdhui=1) : les ateliers du jour en gros boutons, ou le prochain.
     */
    public static function chooser()
    {
        $d = 'smbb-passflow';
        $today = self::today();
        $html = '<div class="passflow-today-pick"><h2>' . esc_html(ucfirst(date_i18n('l j F', current_time('timestamp')))) . '</h2>';

        if ($today) {
            $html .= '<p class="passflow-muted">' . esc_html__("Quel atelier ouvrir ?", $d) . '</p><ul>';

            foreach ($today as $slot) {
                $activity = Lookup::atelier($slot['activity_id']);
                $stats = Slots::withStats(array($slot));
                $taken = isset($stats[0]['booked']) ? (int) $stats[0]['booked'] : null;
                $html .= '<li><a href="' . esc_url(self::url((int) $slot['id'])) . '">'
                    . '<span class="passflow-today-hour">' . esc_html(Lookup::slotHours($slot, true, __('Toute la journée', $d))) . '</span>'
                    . '<strong>' . esc_html($activity ? $activity['name'] : __('Atelier', $d)) . '</strong>'
                    . '<span class="passflow-muted">' . esc_html(implode(' · ', array_filter(array(
                        $activity && !empty($activity['location']) ? $activity['location'] : '',
                        Lookup::hostNames($slot['host_ids']),
                        $taken !== null ? sprintf(_n('%d inscrit', '%d inscrits', $taken, $d), $taken) : '',
                    )))) . '</span>'
                    . '<span class="dashicons dashicons-arrow-right-alt2" aria-hidden="true"></span></a></li>';
            }

            $html .= '</ul>';
        } else {
            $html .= '<p>' . esc_html__("Pas d'atelier pour vous aujourd'hui.", $d) . '</p>';
            $next = null;

            foreach (Slots::between(date('Y-m-d', current_time('timestamp') + DAY_IN_SECONDS), null) as $slot) {
                if ($slot['status'] !== 'cancelled' && Actions::canSeeSlot((int) $slot['id'])) {
                    $next = $next === null || strcmp($slot['start_date'] . $slot['start_time'], $next['start_date'] . $next['start_time']) < 0 ? $slot : $next;
                }
            }

            if ($next) {
                $activity = Lookup::atelier($next['activity_id']);
                $html .= '<ul><li><a href="' . esc_url(self::url((int) $next['id'])) . '"><span class="passflow-today-hour">' . esc_html__('Prochain', $d) . '</span>'
                    . '<strong>' . esc_html($activity ? $activity['name'] : __('Atelier', $d)) . '</strong>'
                    . '<span class="passflow-muted">' . esc_html(Lookup::slotWhen($next)) . '</span>'
                    . '<span class="dashicons dashicons-arrow-right-alt2" aria-hidden="true"></span></a></li></ul>';
            }
        }

        return $html . '<p><a href="' . esc_url(View::url('passflow_portal')) . '">' . esc_html__('Voir tout mon agenda', $d) . '</a></p></div>';
    }
}
