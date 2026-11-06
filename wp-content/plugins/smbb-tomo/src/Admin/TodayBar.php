<?php

namespace Smbb\Tomo\Admin;

use Smbb\Tomo\Repository\Creneaux;
use Smbb\Tomo\Repository\Lookup;
use Smbb\Tomo\Support\Roles;

defined('ABSPATH') || exit;

/**
 * Bouton « ticket » dans la barre noire d'administration (ordinateur et téléphone) : ouvre la
 * gestion mobile de l'atelier du jour. Plusieurs ateliers aujourd'hui : petit menu (et, sur
 * téléphone, une page de choix) ; aucun : la page de choix propose le prochain.
 */
final class TodayBar
{
    const NODE = 'tomo-today';

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
        return is_user_logged_in() && (Roles::canManage() || current_user_can(Roles::CAP_INTERVENANT));
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

        if (!\Smbb\Tomo\Support\Schema::ready()) {
            return self::$today;
        }

        $day = current_time('Y-m-d');

        foreach (Creneaux::between($day, $day) as $creneau) {
            if ($creneau['status'] !== 'cancelled' && Actions::canSeeCreneau((int) $creneau['id'])) {
                self::$today[] = $creneau;
            }
        }

        usort(self::$today, function ($a, $b) {
            return strcmp((string) $a['start_time'], (string) $b['start_time']);
        });

        return self::$today;
    }

    public static function url($creneau_id = 0)
    {
        return $creneau_id ? View::url('tomo_espace', array('creneau' => (int) $creneau_id)) : View::url('tomo_espace', array('aujourdhui' => 1));
    }

    /**
     * @param \WP_Admin_Bar $bar
     */
    public static function node($bar)
    {
        if (!self::allowed()) {
            return;
        }

        $d = 'smbb-tomo';
        $today = self::today();
        $count = count($today);
        $label = $count === 1 ? self::label($today[0]) : ($count ? sprintf(__("%d ateliers aujourd'hui", $d), $count) : __("Pas d'atelier aujourd'hui", $d));

        $bar->add_node(array(
            'id' => self::NODE,
            'title' => '<span class="ab-icon" aria-hidden="true"></span><span class="ab-label">' . esc_html__('Atelier du jour', $d) . '</span>'
                . ($count > 1 ? '<span class="tomo-ab-count">' . $count . '</span>' : ''),
            'href' => $count === 1 ? self::url((int) $today[0]['id']) : self::url(),
            'meta' => array('title' => $label, 'class' => $count ? 'has-today' : 'no-today'),
        ));

        foreach ($today as $creneau) {
            $bar->add_node(array(
                'parent' => self::NODE,
                'id' => self::NODE . '-' . (int) $creneau['id'],
                'title' => esc_html(self::label($creneau)),
                'href' => self::url((int) $creneau['id']),
            ));
        }
    }

    public static function label(array $creneau)
    {
        $atelier = Lookup::atelier($creneau['atelier_id']);
        $hours = Lookup::slotHours($creneau, true, __('toute la journée', 'smbb-tomo'));

        return trim(($hours !== '' ? $hours . ' · ' : '') . ($atelier ? $atelier['name'] : __('Atelier', 'smbb-tomo')));
    }

    public static function style()
    {
        if (!is_admin_bar_showing() || !self::allowed()) {
            return;
        }

        echo '<style id="tomo-today-bar">'
            . '#wpadminbar #wp-admin-bar-tomo-today .ab-icon:before{content:"\f524";top:2px}'
            . '#wpadminbar #wp-admin-bar-tomo-today.no-today .ab-icon:before{opacity:.6}'
            . '#wpadminbar .tomo-ab-count{display:inline-block;min-width:18px;height:18px;margin-left:6px;padding:0 5px;border-radius:9px;background:#d63638;color:#fff;font-size:11px;line-height:18px;text-align:center;box-sizing:border-box}'
            . '@media screen and (max-width:782px){'
            . '#wpadminbar li#wp-admin-bar-tomo-today{display:block;position:relative}'
            . '#wpadminbar #wp-admin-bar-tomo-today .ab-label{display:none}'
            . '#wpadminbar #wp-admin-bar-tomo-today>.ab-item{width:52px;padding:0;text-align:center}'
            . '#wpadminbar .tomo-ab-count{position:absolute;top:6px;right:4px;margin:0}'
            . '#wpadminbar #wp-admin-bar-tomo-today .ab-sub-wrapper{display:none!important}'
            . '}</style>';
    }

    /**
     * Page de choix (?aujourdhui=1) : les ateliers du jour en gros boutons, ou le prochain.
     */
    public static function chooser()
    {
        $d = 'smbb-tomo';
        $today = self::today();
        $html = '<div class="tomo-today-pick"><h2>' . esc_html(ucfirst(date_i18n('l j F', current_time('timestamp')))) . '</h2>';

        if ($today) {
            $html .= '<p class="tomo-muted">' . esc_html__("Quel atelier ouvrir ?", $d) . '</p><ul>';

            foreach ($today as $creneau) {
                $atelier = Lookup::atelier($creneau['atelier_id']);
                $stats = Creneaux::withStats(array($creneau));
                $taken = isset($stats[0]['booked']) ? (int) $stats[0]['booked'] : null;
                $html .= '<li><a href="' . esc_url(self::url((int) $creneau['id'])) . '">'
                    . '<span class="tomo-today-hour">' . esc_html(Lookup::slotHours($creneau, true, __('Toute la journée', $d))) . '</span>'
                    . '<strong>' . esc_html($atelier ? $atelier['name'] : __('Atelier', $d)) . '</strong>'
                    . '<span class="tomo-muted">' . esc_html(implode(' · ', array_filter(array(
                        $atelier && !empty($atelier['location']) ? $atelier['location'] : '',
                        Lookup::intervenantNames($creneau['intervenant_ids']),
                        $taken !== null ? sprintf(_n('%d inscrit', '%d inscrits', $taken, $d), $taken) : '',
                    )))) . '</span>'
                    . '<span class="dashicons dashicons-arrow-right-alt2" aria-hidden="true"></span></a></li>';
            }

            $html .= '</ul>';
        } else {
            $html .= '<p>' . esc_html__("Pas d'atelier pour vous aujourd'hui.", $d) . '</p>';
            $next = null;

            foreach (Creneaux::between(date('Y-m-d', current_time('timestamp') + DAY_IN_SECONDS), null) as $creneau) {
                if ($creneau['status'] !== 'cancelled' && Actions::canSeeCreneau((int) $creneau['id'])) {
                    $next = $next === null || strcmp($creneau['start_date'] . $creneau['start_time'], $next['start_date'] . $next['start_time']) < 0 ? $creneau : $next;
                }
            }

            if ($next) {
                $atelier = Lookup::atelier($next['atelier_id']);
                $html .= '<ul><li><a href="' . esc_url(self::url((int) $next['id'])) . '"><span class="tomo-today-hour">' . esc_html__('Prochain', $d) . '</span>'
                    . '<strong>' . esc_html($atelier ? $atelier['name'] : __('Atelier', $d)) . '</strong>'
                    . '<span class="tomo-muted">' . esc_html(Lookup::creneauWhen($next)) . '</span>'
                    . '<span class="dashicons dashicons-arrow-right-alt2" aria-hidden="true"></span></a></li></ul>';
            }
        }

        return $html . '<p><a href="' . esc_url(View::url('tomo_espace')) . '">' . esc_html__('Voir tout mon agenda', $d) . '</a></p></div>';
    }
}
