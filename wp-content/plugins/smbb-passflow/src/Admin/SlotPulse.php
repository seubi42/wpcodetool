<?php

namespace Smbb\Passflow\Admin;

use Smbb\Passflow\Repository\Lookup;
use Smbb\Passflow\Service\Passes;

defined('ABSPATH') || exit;

/**
 * Bandeau « vivant » en tête de la fiche Journée : jauge de remplissage animée et compte à rebours
 * jusqu'au début (« J-3 14 h 22 min »), puis « En cours », puis « Terminé ».
 *
 * Le PHP rend l'état exact au chargement (lisible sans JavaScript et à l'impression) ;
 * assets/slot-pulse.js fait tourner l'horloge et compte les inscrits de 0 à N.
 * Couleurs : variables du skin CodeTool (--smbb-t-*), repli sur la marque Passflow.
 */
class SlotPulse
{
    const RADIUS = 52;

    /**
     * @param array $slot      Ligne de Slots::find() (avec booked, remaining, waiting).
     * @param array $pass_days Jours d'un pass (Passes::days), vide pour une date simple.
     */
    public static function html(array $slot, array $pass_days = array())
    {
        $d = 'smbb-passflow';
        $is_pass = Passes::isPass($slot);
        $booked = max(0, (int) $slot['booked']);
        $remaining = max(0, (int) $slot['remaining']);
        $capacity = $is_pass ? $booked + $remaining : max(0, (int) $slot['capacity']);
        $waiting = isset($slot['waiting']) ? max(0, (int) $slot['waiting']) : 0;
        $ratio = $capacity > 0 ? min(1, $booked / $capacity) : 0;
        $pct = (int) round($ratio * 100);
        $level = $ratio >= 1 ? 'full' : ($ratio >= 0.75 ? 'high' : ($ratio >= 0.4 ? 'mid' : 'low'));
        $circ = round(2 * M_PI * self::RADIUS, 2);
        $offset = round($circ * (1 - $ratio), 2);

        self::enqueue();

        $ring = '<div class="passflow-pulse-gauge">'
            . '<svg class="passflow-pulse-ring" viewBox="0 0 120 120" aria-hidden="true" focusable="false">'
            . '<circle class="passflow-pulse-track" cx="60" cy="60" r="' . self::RADIUS . '"></circle>'
            . '<circle class="passflow-pulse-bar" cx="60" cy="60" r="' . self::RADIUS . '" transform="rotate(-90 60 60)"'
            . ' stroke-dasharray="' . esc_attr((string) $circ) . '" stroke-dashoffset="' . esc_attr((string) $offset) . '"'
            . ' style="--passflow-pulse-circ:' . esc_attr((string) $circ) . 'px;--passflow-pulse-offset:' . esc_attr((string) $offset) . 'px"></circle>'
            . '</svg>'
            . '<span class="passflow-pulse-pct"><strong data-pulse-count="' . $pct . '">' . $pct . '</strong><small>%</small></span>'
            . '</div>';

        $facts = '<div class="passflow-pulse-facts">'
            . '<span class="passflow-pulse-kicker">' . esc_html($is_pass ? __('Pass vendus', $d) : __('Remplissage', $d)) . '</span>'
            . '<span class="passflow-pulse-big"><strong data-pulse-count="' . $booked . '">' . $booked . '</strong>'
            . ($capacity > 0 ? ' <span class="passflow-pulse-of">/ ' . (int) $capacity . '</span>' : '') . '</span>'
            . '<span class="passflow-pulse-sub">'
            . ($ratio >= 1
                ? '<span class="passflow-pulse-chip is-full">' . esc_html__('Complet', $d) . '</span>'
                : esc_html(sprintf(_n('%d place libre', '%d places libres', $remaining, $d), $remaining)))
            . ($waiting > 0 ? ' <span class="passflow-pulse-chip is-wait">' . esc_html(sprintf(_n('+%d en attente', '+%d en attente', $waiting, $d), $waiting)) . '</span>' : '')
            . '</span></div>';

        return '<div class="passflow-pulse passflow-pulse--' . esc_attr($level) . '" role="group" aria-label="' . esc_attr(sprintf(__('%1$d inscrits sur %2$d places', $d), $booked, $capacity)) . '">'
            . '<div class="passflow-pulse-fill">' . $ring . $facts . '</div>'
            . self::clock($slot, $pass_days)
            . '</div>';
    }

    /**
     * Début et fin en horodatage Unix (fuseau du site). Pass : du premier au dernier jour.
     *
     * @return array|null array(start, end, all_day)
     */
    public static function window(array $slot, array $pass_days = array())
    {
        $first = $slot;
        $last = $slot;

        if (Passes::isPass($slot)) {
            if (!$pass_days) {
                return null;
            }

            $first = reset($pass_days);
            $last = end($pass_days);
        }

        if (empty($first['start_date']) || empty($last['start_date'])) {
            return null;
        }

        $tz = wp_timezone();
        $all_day = Lookup::allDay($first);

        try {
            if ($all_day) {
                $start = new \DateTimeImmutable($first['start_date'] . ' 00:00:00', $tz);
            } else {
                $start = new \DateTimeImmutable($first['start_date'] . ' ' . self::time($first, 'start_time', '00:00:00'), $tz);
            }

            if (Lookup::allDay($last) || empty($last['end_time'])) {
                $end = new \DateTimeImmutable($last['start_date'] . ' 23:59:59', $tz);
            } else {
                $end = new \DateTimeImmutable($last['start_date'] . ' ' . self::time($last, 'end_time', '23:59:59'), $tz);
            }
        } catch (\Exception $e) {
            return null;
        }

        $start_ts = $start->getTimestamp();
        $end_ts = max($start_ts + 60, $end->getTimestamp());

        return array($start_ts, $end_ts, $all_day);
    }

    private static function time(array $slot, $key, $default)
    {
        return isset($slot[$key]) && preg_match('/^\d{1,2}:\d{2}(:\d{2})?$/', (string) $slot[$key]) ? (string) $slot[$key] : $default;
    }

    /**
     * Horloge : rendu initial exact, repris chaque seconde par slot-pulse.js.
     */
    private static function clock(array $slot, array $pass_days)
    {
        $d = 'smbb-passflow';

        if ($slot['status'] === 'cancelled') {
            return '<div class="passflow-pulse-clock is-off"><span class="passflow-pulse-kicker">' . esc_html__('Statut', $d) . '</span>'
                . '<span class="passflow-pulse-state">' . esc_html__('Date annulée', $d) . '</span></div>';
        }

        $window = self::window($slot, $pass_days);

        if (!$window) {
            return '<div class="passflow-pulse-clock is-off"><span class="passflow-pulse-kicker">' . esc_html__('Date', $d) . '</span>'
                . '<span class="passflow-pulse-state">' . esc_html__('Date à définir', $d) . '</span></div>';
        }

        list($start, $end, $all_day) = $window;
        $now = time();
        $tz = wp_timezone();
        $labels = self::labels();
        $state = self::state($start, $end, $all_day, $now, $tz, $labels);

        return '<div class="passflow-pulse-clock is-' . esc_attr($state['state']) . '" role="timer" aria-live="off"'
            . ' data-pulse-start="' . (int) $start . '" data-pulse-end="' . (int) $end . '" data-pulse-now="' . (int) $now . '"'
            . ' data-pulse-allday="' . ($all_day ? 1 : 0) . '"'
            . ' data-pulse-tz="' . (int) $tz->getOffset(new \DateTimeImmutable('@' . $start)) . '"'
            . ' data-pulse-tz-now="' . (int) $tz->getOffset(new \DateTimeImmutable('@' . $now)) . '"'
            . ' data-pulse-labels="' . esc_attr(wp_json_encode($labels)) . '"'
            . ' aria-label="' . esc_attr($state['label']) . '">'
            . '<span class="passflow-pulse-kicker">' . $state['kicker'] . '</span>'
            . '<span class="passflow-pulse-count" aria-hidden="true">' . $state['html'] . '</span>'
            . '<span class="passflow-pulse-when">' . esc_html(Lookup::slotWhen($slot)) . '</span>'
            . '</div>';
    }

    /**
     * Textes partagés avec le JS (même rendu des deux côtés).
     */
    private static function labels()
    {
        $d = 'smbb-passflow';

        return array(
            'before' => __('Début dans', $d),
            'live' => __('En cours', $d),
            'done' => __('Terminé', $d),
            'endsIn' => __('fin dans', $d),
            'allDay' => __('toute la journée', $d),
            'today' => __("Aujourd'hui", $d),
            'tomorrow' => __('Demain', $d),
            'day' => __('jour', $d),
            'days' => __('jours', $d),
            'h' => __('h', $d),
            'min' => __('min', $d),
            's' => __('s', $d),
            'agoToday' => __("aujourd'hui", $d),
            'agoYesterday' => __('hier', $d),
            /* translators: %d : nombre de jours. */
            'agoDays' => __('il y a %d jours', $d),
        );
    }

    /**
     * Même calcul que state() dans slot-pulse.js.
     */
    private static function state($start, $end, $all_day, $now, \DateTimeZone $tz, array $l)
    {
        $esc = function ($s) {
            return esc_html((string) $s);
        };
        $seg = function ($n, $label) use ($esc) {
            return '<span class="passflow-pulse-seg"><b>' . $esc($n) . '</b><small>' . $esc($label) . '</small></span>';
        };
        $pad = function ($n) {
            return str_pad((string) $n, 2, '0', STR_PAD_LEFT);
        };
        $local_day = function ($ts) use ($tz) {
            return (int) floor(($ts + $tz->getOffset(new \DateTimeImmutable('@' . $ts))) / DAY_IN_SECONDS);
        };
        $cal = $local_day($start) - $local_day($now);

        if ($now < $start) {
            $diff = $start - $now;
            $days = (int) floor($diff / DAY_IN_SECONDS);
            $h = (int) floor(($diff % DAY_IN_SECONDS) / HOUR_IN_SECONDS);
            $m = (int) floor(($diff % HOUR_IN_SECONDS) / MINUTE_IN_SECONDS);
            $s = $diff % MINUTE_IN_SECONDS;
            $chip = $cal === 0 ? $l['today'] : ($cal === 1 ? $l['tomorrow'] : '');

            if ($days >= 1) {
                $html = $seg('J-' . $days, $days > 1 ? $l['days'] : $l['day']) . $seg($h, $l['h']) . $seg($pad($m), $l['min']);
                $label = sprintf('%s J-%d, %d %s %s', $l['before'], $days, $h, $l['h'], $pad($m));
            } else {
                $html = ($h > 0 ? $seg($h, $l['h']) : '') . $seg($pad($m), $l['min']) . $seg($pad($s), $l['s']);
                $label = sprintf('%s %d %s %s', $l['before'], $h, $l['h'], $pad($m));
            }

            return array(
                'state' => $diff < HOUR_IN_SECONDS ? 'soon' : ($days < 1 ? 'near' : 'before'),
                'kicker' => $esc($l['before']) . ($chip !== '' ? ' <span class="passflow-pulse-chip">' . $esc($chip) . '</span>' : ''),
                'html' => $html,
                'label' => $label,
            );
        }

        if ($now < $end) {
            $diff = $end - $now;
            $h = (int) floor($diff / HOUR_IN_SECONDS);
            $m = (int) floor(($diff % HOUR_IN_SECONDS) / MINUTE_IN_SECONDS);
            $s = $diff % MINUTE_IN_SECONDS;
            $html = $all_day
                ? '<span class="passflow-pulse-state">' . $esc($l['allDay']) . '</span>'
                : '<span class="passflow-pulse-ends">' . $esc($l['endsIn']) . '</span>' . ($h > 0 ? $seg($h, $l['h']) : '') . $seg($pad($m), $l['min']) . $seg($pad($s), $l['s']);

            return array(
                'state' => 'live',
                'kicker' => '<span class="passflow-pulse-dot" aria-hidden="true"></span>' . $esc($l['live']),
                'html' => $html,
                'label' => $l['live'],
            );
        }

        $ago = $local_day($now) - $local_day($end);
        $text = $ago <= 0 ? $l['agoToday'] : ($ago === 1 ? $l['agoYesterday'] : sprintf($l['agoDays'], $ago));

        return array(
            'state' => 'done',
            'kicker' => $esc($l['done']),
            'html' => '<span class="passflow-pulse-state">' . $esc($text) . '</span>',
            'label' => $l['done'] . ' ' . $text,
        );
    }

    private static function enqueue()
    {
        if (!defined('SMBB_PASSFLOW_URL') || !function_exists('wp_enqueue_script')) {
            return;
        }

        // Ajouté pendant le rendu de la vue : WordPress l'imprime dans le pied de page.
        wp_enqueue_script(
            'smbb-passflow-slot-pulse',
            SMBB_PASSFLOW_URL . 'assets/slot-pulse.js',
            array(),
            SMBB_PASSFLOW_VERSION . '.' . (int) @filemtime(SMBB_PASSFLOW_PATH . 'assets/slot-pulse.js'),
            true
        );
    }
}
