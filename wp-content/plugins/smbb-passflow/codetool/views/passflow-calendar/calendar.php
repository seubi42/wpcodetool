<?php
/**
 * Calendrier du remplissage : vue mois ou semaine, filtrable par atelier.
 * Chaque créneau est une pastille colorée selon son remplissage ; un clic ouvre sa fiche.
 */

use Smbb\Passflow\Admin\View;
use Smbb\Passflow\Repository\Slots;
use Smbb\Passflow\Repository\Lookup;
use Smbb\Passflow\Support\Input;
use Smbb\Passflow\Support\Schema;

defined('ABSPATH') || exit;

$d = 'smbb-passflow';
$header = isset($page_header_html) ? $page_header_html : '';
$notices = isset($notices_html) ? $notices_html : '';

if (!Schema::ready()) {
    View::schemaMissing($header, $notices, __('Calendrier', $d));
    return;
}

$today = current_time('Y-m-d');
$mode = isset($_GET['mode']) && $_GET['mode'] === 'week' ? 'week' : 'month';
$anchor = isset($_GET['date']) && Input::isDate(sanitize_text_field(wp_unslash($_GET['date']))) ? sanitize_text_field(wp_unslash($_GET['date'])) : $today;
$activity_id = isset($_GET['atelier']) ? absint($_GET['atelier']) : 0;
$ts = strtotime($anchor . ' 12:00:00');

if ($mode === 'month') {
    $first = date('Y-m-01', $ts);
    $last = date('Y-m-t', $ts);
    $from = date('Y-m-d', strtotime($first . ' -' . ((int) date('N', strtotime($first)) - 1) . ' days'));
    $to = date('Y-m-d', strtotime($last . ' +' . (7 - (int) date('N', strtotime($last))) . ' days'));
    $prev = date('Y-m-d', strtotime($first . ' -1 month'));
    $next = date('Y-m-d', strtotime($first . ' +1 month'));
    $title = date_i18n('F Y', strtotime($first . ' 12:00:00'));
} else {
    $from = date('Y-m-d', strtotime($anchor . ' -' . ((int) date('N', $ts) - 1) . ' days'));
    $to = date('Y-m-d', strtotime($from . ' +6 days'));
    $prev = date('Y-m-d', strtotime($from . ' -7 days'));
    $next = date('Y-m-d', strtotime($from . ' +7 days'));
    $title = sprintf(__('Semaine du %1$s au %2$s', $d), date_i18n('j M', strtotime($from . ' 12:00:00')), date_i18n('j M Y', strtotime($to . ' 12:00:00')));
}

$by_day = array();

foreach (Slots::between($from, $to, array('activity_id' => $activity_id)) as $slot) {
    $by_day[$slot['start_date']][] = $slot;
}

$link = function (array $args) use ($mode, $anchor, $activity_id) {
    return View::url('passflow_calendar', array_merge(array('mode' => $mode, 'date' => $anchor, 'atelier' => $activity_id ?: false), $args));
};

$chip = function (array $slot, $detailed) use ($d) {
    $activity = Lookup::atelier($slot['activity_id']);
    $color = $activity && !empty($activity['color']) ? $activity['color'] : '#8c8f94';
    $state = $slot['status'] === 'cancelled' ? 'cancelled' : ($slot['status'] === 'draft' ? 'draft' : ($slot['fill'] >= 1 ? 'full' : ($slot['fill'] >= 0.75 ? 'high' : ($slot['fill'] >= 0.4 ? 'mid' : 'low'))));
    $url = View::url('passflow_slot', array('view' => 'details', 'id' => (int) $slot['id']));
    $html = '<a class="passflow-chip passflow-chip--' . esc_attr($state) . '" style="--passflow-color:' . esc_attr($color) . '" href="' . esc_url($url) . '" title="' . esc_attr(Lookup::slotLabel($slot)) . '">'
        . '<span class="passflow-chip-time">' . esc_html(Lookup::slotHours($slot, $detailed, __('Journée', $d))) . '</span> '
        . '<span class="passflow-chip-name">' . esc_html($activity ? $activity['name'] : '') . '</span>'
        . '<span class="passflow-chip-fill">' . esc_html($slot['status'] === 'cancelled' ? __('annulé', $d) : ($slot['booked'] . '/' . $slot['capacity'])) . ($slot['waiting'] ? ' +' . (int) $slot['waiting'] : '') . '</span>';

    if ($detailed) {
        $names = Lookup::hostNames($slot['host_ids']);
        $html .= $names !== '' ? '<span class="passflow-chip-people">' . esc_html($names) . '</span>' : '';
    }

    return $html . '</a>';
};

// Barre d'outils.
$toolbar = '<div class="passflow-cal-toolbar">'
    . '<div class="passflow-cal-nav">'
    . '<a class="button" href="' . esc_url($link(array('date' => $prev))) . '" aria-label="' . esc_attr__('Précédent', $d) . '">‹</a>'
    . '<a class="button" href="' . esc_url($link(array('date' => $today))) . '">' . esc_html__("Aujourd'hui", $d) . '</a>'
    . '<a class="button" href="' . esc_url($link(array('date' => $next))) . '" aria-label="' . esc_attr__('Suivant', $d) . '">›</a>'
    . '<h2>' . esc_html(ucfirst($title)) . '</h2>'
    . '</div>'
    . '<form method="get" class="passflow-cal-filters">'
    . '<input type="hidden" name="page" value="smbb-codetool-passflow_calendar">'
    . '<input type="hidden" name="mode" value="' . esc_attr($mode) . '">'
    . '<input type="hidden" name="date" value="' . esc_attr($anchor) . '">'
    . View::select('atelier', Lookup::activityOptions(__('Tous les ateliers', $d)), $activity_id ?: '', 'onchange="this.form.submit()"')
    . '</form>'
    . '<div class="passflow-segments">'
    . '<a class="' . ($mode === 'month' ? 'is-active' : '') . '" href="' . esc_url($link(array('mode' => 'month'))) . '">' . esc_html__('Mois', $d) . '</a>'
    . '<a class="' . ($mode === 'week' ? 'is-active' : '') . '" href="' . esc_url($link(array('mode' => 'week'))) . '">' . esc_html__('Semaine', $d) . '</a>'
    . '<a href="' . esc_url(View::filtered('passflow_slot', 'start_date', $today, 'gte')) . '">' . esc_html__('Liste', $d) . '</a>'
    . '</div></div>';

// Grille.
$days = array(__('Lun', $d), __('Mar', $d), __('Mer', $d), __('Jeu', $d), __('Ven', $d), __('Sam', $d), __('Dim', $d));
$grid = '<div class="passflow-cal passflow-cal--' . esc_attr($mode) . '">';

foreach ($days as $day) {
    $grid .= '<div class="passflow-cal-head">' . esc_html($day) . '</div>';
}

for ($day = $from; $day <= $to; $day = date('Y-m-d', strtotime($day . ' +1 day'))) {
    $classes = array('passflow-cal-day');

    if ($day === $today) {
        $classes[] = 'is-today';
    }

    if ($mode === 'month' && substr($day, 0, 7) !== substr($anchor, 0, 7)) {
        $classes[] = 'is-outside';
    }

    if ($day < $today) {
        $classes[] = 'is-past';
    }

    $grid .= '<div class="' . esc_attr(implode(' ', $classes)) . '">'
        . '<div class="passflow-cal-date">' . esc_html($mode === 'month' ? (string) (int) substr($day, 8, 2) : date_i18n('j M', strtotime($day . ' 12:00:00'))) . '</div>';

    foreach (isset($by_day[$day]) ? $by_day[$day] : array() as $slot) {
        $grid .= $chip($slot, $mode === 'week');
    }

    if ($day >= $today) {
        $grid .= '<a class="passflow-cal-add" href="' . esc_url(View::url('passflow_slot', array('view' => 'form'))) . '" title="' . esc_attr__('Nouveau créneau', $d) . '">+</a>';
    }

    $grid .= '</div>';
}

$grid .= '</div>';

$legend = '<p class="passflow-legend">'
    . '<span class="passflow-chip passflow-chip--low">' . esc_html__('moins de 40 %', $d) . '</span>'
    . '<span class="passflow-chip passflow-chip--mid">' . esc_html__('40 à 75 %', $d) . '</span>'
    . '<span class="passflow-chip passflow-chip--high">' . esc_html__('plus de 75 %', $d) . '</span>'
    . '<span class="passflow-chip passflow-chip--full">' . esc_html__('complet', $d) . '</span>'
    . '<span class="passflow-chip passflow-chip--draft">' . esc_html__('brouillon', $d) . '</span>'
    . '<span class="passflow-chip passflow-chip--cancelled">' . esc_html__('annulé', $d) . '</span>'
    . ' <span class="passflow-muted">' . esc_html__('« +2 » = personnes en liste d\'attente', $d) . '</span></p>';

View::page('<div class="passflow-card">' . $toolbar . $grid . $legend . '</div>', $header, $notices, __('Calendrier', $d));
