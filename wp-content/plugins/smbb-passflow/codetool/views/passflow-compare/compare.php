<?php
/**
 * Comparer : plusieurs dates (ou plusieurs ateliers sur une période) côte à côte.
 * Remplissage, présence et chiffre d'affaires, avec les définitions du Bilan d'une journée.
 * Ouvert depuis le Bilan (« Comparer avec d'autres dates ») ou depuis Suivi › Comparer.
 */

use Smbb\Passflow\Admin\Actions;
use Smbb\Passflow\Admin\View;
use Smbb\Passflow\Repository\Lookup;
use Smbb\Passflow\Repository\Slots;
use Smbb\Passflow\Service\Compare;
use Smbb\Passflow\Support\Roles;
use Smbb\Passflow\Support\Schema;

defined('ABSPATH') || exit;

$d = 'smbb-passflow';
$header = isset($page_header_html) ? $page_header_html : '';
$notices = isset($notices_html) ? $notices_html : '';

if (!Schema::ready()) {
    View::schemaMissing($header, $notices, __('Comparer', $d));
    return;
}

if (!Roles::canManage()) {
    View::page(View::card('', '<p>' . esc_html__("Vous n'avez pas accès à cette page.", $d) . '</p>'), $header, $notices, __('Comparer', $d));
    return;
}

$c = Compare::query($_GET); // phpcs:ignore WordPress.Security.NonceVerification
$columns = $c['columns'];
$metrics = Compare::metrics();
$is_slots = $c['mode'] === 'slots';
$picker_activity = isset($_GET['atelier']) ? absint($_GET['atelier']) : 0;
$html = '';

if ($c['since'] && ($origin = Slots::find($c['since']))) {
    $html .= '<p class="passflow-bc-back no-print"><a href="' . esc_url(View::url('passflow_slot', array('view' => 'report', 'id' => (int) $origin['id']))) . '"><span class="dashicons dashicons-arrow-left-alt2" aria-hidden="true"></span> '
        . esc_html(sprintf(__('Bilan · %s', $d), Lookup::slotLabel($origin))) . '</a></p>';

    if (!$picker_activity) {
        $picker_activity = (int) $origin['activity_id'];
    }
}

// --- Que comparer ? ------------------------------------------------------------------------
$mode_url = function ($mode) {
    return View::url('passflow_compare', array('comparer' => $mode));
};
$picker = '<div class="passflow-segments">'
    . '<a class="' . ($is_slots ? 'is-active' : '') . '" href="' . esc_url($mode_url('dates')) . '">' . esc_html__('Des dates', $d) . '</a>'
    . '<a class="' . (!$is_slots ? 'is-active' : '') . '" href="' . esc_url($mode_url('ateliers')) . '">' . esc_html__('Des ateliers', $d) . '</a>'
    . '</div>';

$picker .= '<form method="get" class="passflow-compare-form">'
    . '<input type="hidden" name="page" value="smbb-codetool-passflow_compare">'
    . '<input type="hidden" name="comparer" value="' . ($is_slots ? 'dates' : 'ateliers') . '">'
    . ($c['since'] ? '<input type="hidden" name="depuis" value="' . (int) $c['since'] . '">' : '')
    . '<div class="passflow-filters">'
    . '<label>' . esc_html__('Du', $d) . ' <input type="date" name="from" value="' . esc_attr($c['from']) . '"></label> '
    . '<label>' . esc_html__('au', $d) . ' <input type="date" name="to" value="' . esc_attr($c['to']) . '"></label> ';

if ($is_slots) {
    $picker .= '<label>' . esc_html__('Atelier', $d) . ' ' . View::select('atelier', Lookup::activityOptions(__('Tous', $d)), $picker_activity ?: '') . '</label> '
        . '<button class="button" name="pick" value="1">' . esc_html__('Afficher les dates', $d) . '</button>';
}

$picker .= '</div>';

if ($is_slots) {
    // Dates proposées : celles de la période (et de l'atelier), plus celles déjà choisies.
    $chosen = array_map('intval', array_column($columns, 'id'));
    $list = Slots::between($c['from'], $c['to'], array('activity_id' => $picker_activity, 'statuses' => array('open', 'full', 'draft')));
    $list = array_values(array_filter($list, function ($s) use ($chosen) {
        return (int) $s['booked'] > 0 || in_array((int) $s['id'], $chosen, true);
    }));
    $listed = array_map('intval', array_column($list, 'id'));

    foreach ($chosen as $id) {
        if (!in_array($id, $listed, true) && ($s = Slots::find($id))) {
            array_unshift($list, $s);
        }
    }

    if ($list) {
        $boxes = '';

        foreach (array_reverse($list) as $s) {
            $sid = (int) $s['id'];
            $boxes .= '<label class="passflow-compare-pick' . (in_array($sid, $chosen, true) ? ' is-checked' : '') . '">'
                . '<input type="checkbox" name="creneaux[]" value="' . $sid . '"' . checked(in_array($sid, $chosen, true), true, false) . '>'
                . '<span><strong>' . esc_html($picker_activity ? Lookup::slotWhen($s) : Lookup::slotLabel($s)) . '</strong>'
                . View::fill($s['booked'], $s['capacity']) . '</span></label>';
        }

        $picker .= '<p class="description">' . esc_html(sprintf(__('Cochez jusqu\'à %d dates. Les dates sans inscrit ne sont pas proposées.', $d), Compare::MAX)) . '</p>'
            . '<div class="passflow-compare-picks" data-max="' . (int) Compare::MAX . '">' . $boxes . '</div>';
    } else {
        $picker .= '<p class="passflow-muted">' . esc_html__('Aucune date avec des inscrits sur cette période.', $d) . '</p>';
    }
} else {
    $boxes = '';

    foreach (Lookup::activityOptions('', true) as $label => $aid) {
        $aid = (int) $aid;
        $boxes .= '<label class="passflow-compare-pick' . (in_array($aid, $c['activity_ids'], true) ? ' is-checked' : '') . '">'
            . '<input type="checkbox" name="ateliers[]" value="' . $aid . '"' . checked(in_array($aid, $c['activity_ids'], true), true, false) . '>'
            . '<span><strong>' . esc_html($label) . '</strong></span></label>';
    }

    $picker .= '<p class="description">' . esc_html(sprintf(__('Sans choix, les %d ateliers au plus gros chiffre d\'affaires de la période. Toutes leurs dates sont additionnées.', $d), Compare::MAX)) . '</p>'
        . '<div class="passflow-compare-picks" data-max="' . (int) Compare::MAX . '">' . $boxes . '</div>';
}

$picker .= '<p><button class="button button-primary" type="submit"><span class="dashicons dashicons-columns" aria-hidden="true"></span> ' . esc_html__('Comparer', $d) . '</button></p></form>';

// Garde-fou : pas plus de MAX cases cochées.
$picker .= '<script>document.querySelectorAll(".passflow-compare-picks").forEach(function(box){var max=parseInt(box.getAttribute("data-max"),10)||8;function sync(){var n=box.querySelectorAll("input:checked").length;box.querySelectorAll("input").forEach(function(i){i.disabled=!i.checked&&n>=max;i.closest("label").classList.toggle("is-checked",i.checked);});}box.addEventListener("change",sync);sync();});</script>';

$html .= View::card(__('Que comparer ?', $d), $picker, 'passflow-compare-picker no-print');

// --- Résultat ------------------------------------------------------------------------------
if (count($columns) < 2) {
    $html .= View::card('', '<p class="passflow-muted">' . esc_html($is_slots
        ? __('Choisissez au moins deux dates pour les voir côte à côte.', $d)
        : __('Pas assez d\'ateliers avec des dates sur cette période.', $d)) . '</p>');
    View::page('<div class="passflow-report passflow-compare">' . $html . '</div>', $header, $notices, __('Comparer', $d));
    return;
}

$args = Compare::args($c);
$ref_i = $c['ref_index'];
$ref = $columns[$ref_i];
$ref_options = array();

foreach ($columns as $col) {
    $ref_options[$col['title'] . ' · ' . $col['subtitle']] = (string) $col['id'];
}

$head = '<h2 class="passflow-report-title">' . esc_html($c['title']) . '</h2>'
    . '<p class="passflow-report-when">' . esc_html(sprintf(_n('%d colonne comparée', '%d colonnes comparées', count($columns), $d), count($columns)))
    . ' · ' . esc_html(sprintf(__('Référence : %s', $d), $ref['title'] . ' · ' . $ref['subtitle'])) . '</p>'
    . '<form method="get" class="passflow-actions no-print">';

foreach (array_merge(array('page' => 'smbb-codetool-passflow_compare'), $args, $c['since'] ? array('depuis' => (string) $c['since']) : array()) as $k => $v) {
    if ($k !== 'ref') {
        $head .= '<input type="hidden" name="' . esc_attr($k) . '" value="' . esc_attr($v) . '">';
    }
}

$head .= '<label>' . esc_html__('Comparer à', $d) . ' ' . View::select('ref', $ref_options, (string) $c['ref'], 'onchange="this.form.submit()"') . '</label> '
    . '<a class="button button-primary" href="' . esc_url(View::actionUrl(Actions::COMPARE_EXPORT, $args)) . '"><span class="dashicons dashicons-download" aria-hidden="true"></span> ' . esc_html__('Exporter (CSV)', $d) . '</a> '
    . '<button type="button" class="button" onclick="window.print()"><span class="dashicons dashicons-printer" aria-hidden="true"></span> ' . esc_html__('Imprimer', $d) . '</button>'
    . '</form>';

$html .= View::card('', $head, 'passflow-report-head');

// En un coup d'œil : trois barres par colonne.
$glance = '';

foreach (array('fill' => 'is-fill', 'rate' => 'is-present', 'revenue' => 'is-revenue') as $key => $class) {
    $max = 0;

    foreach ($columns as $col) {
        $max = max($max, (float) $col['values'][$key]);
    }

    $bars = '';

    foreach ($columns as $i => $col) {
        $value = $col['values'][$key];
        $width = $metrics[$key][1] === 'pct' ? (float) $value * 100 : ($max > 0 ? (float) $value * 100 / $max : 0);
        $bars .= '<div class="passflow-compare-row' . ($i === $ref_i ? ' is-ref' : '') . '">'
            . '<span class="passflow-compare-label" title="' . esc_attr($col['title'] . ' · ' . $col['subtitle']) . '">' . esc_html($is_slots ? $col['subtitle'] : $col['title']) . '</span>'
            . '<span class="passflow-compare-track"><span class="' . $class . '" style="width:' . esc_attr((string) round(min(100, $width), 2)) . '%"></span></span>'
            . '<strong>' . esc_html(Compare::format($key, $value)) . '</strong></div>';
    }

    $glance .= '<div><h3 class="passflow-bc-h3">' . esc_html($metrics[$key][0]) . '</h3>' . $bars . '</div>';
}

$html .= View::card(__("En un coup d'œil", $d), '<div class="passflow-compare-glance">' . $glance . '</div>');

// Côte à côte : un indicateur par ligne, une colonne par date ou atelier.
$thead = '<th>' . esc_html__('Indicateur', $d) . '</th>';

foreach ($columns as $i => $col) {
    $thead .= '<th class="passflow-num' . ($i === $ref_i ? ' is-ref' : '') . '"><strong>' . esc_html($col['title']) . '</strong><br><span class="passflow-muted">' . esc_html($col['subtitle']) . '</span>'
        . ($i === $ref_i ? '<br><span class="passflow-compare-ref">' . esc_html__('Référence', $d) . '</span>' : '')
        . ($is_slots ? '<br><a class="no-print" href="' . esc_url(View::url('passflow_slot', array('view' => 'report', 'id' => (int) $col['id']))) . '">' . esc_html__('Bilan', $d) . '</a>' : '')
        . (!$col['past'] ? '<br><em class="passflow-muted">' . esc_html__('provisoire', $d) . '</em>' : '')
        . '</th>';
}

$rows = '';

foreach (Compare::groups() as $group => $group_label) {
    $rows .= '<tr class="passflow-compare-group"><th colspan="' . (count($columns) + 1) . '">' . esc_html($group_label) . '</th></tr>';

    foreach ($metrics as $key => $metric) {
        if ($metric[3] !== $group || !Compare::shown($key, $columns)) {
            continue;
        }

        // Meilleure valeur de la ligne (quand il y a un sens et un écart).
        $values = array_map(function ($col) use ($key) {
            return $col['values'][$key];
        }, $columns);
        $known = array_filter($values, function ($v) {
            return $v !== null;
        });
        $best = null;

        if ($metric[2] !== 0 && count($known) > 1 && max($known) != min($known)) {
            $best = $metric[2] > 0 ? max($known) : min($known);
        }

        $rows .= '<tr><th scope="row">' . esc_html($metric[0]) . '</th>';

        foreach ($columns as $i => $col) {
            $value = $col['values'][$key];
            $cell = esc_html(Compare::format($key, $value));

            if ($i !== $ref_i && ($delta = Compare::delta($key, $value, $ref['values'][$key]))) {
                $cell .= ' <span class="passflow-compare-delta is-' . esc_attr($delta['tone']) . '">' . esc_html($delta['text']) . '</span>';
            }

            $rows .= '<td class="passflow-num' . ($i === $ref_i ? ' is-ref' : '') . ($best !== null && $value !== null && $value == $best ? ' is-best' : '') . '">' . $cell . '</td>';
        }

        $rows .= '</tr>';
    }
}

$html .= View::card(__('Côte à côte', $d), '<div class="passflow-table-scroll"><table class="widefat passflow-table passflow-compare-table"><thead><tr>' . $thead . '</tr></thead><tbody>' . $rows . '</tbody></table></div>'
    . '<p class="description">' . esc_html__('Taux de présence = présents sur inscrits, comme dans le Bilan. Les écarts se lisent par rapport à la référence ; en vert ce qui est mieux, en rouge ce qui est moins bien. La meilleure valeur de chaque ligne est en gras.', $d) . '</p>');

View::page('<div class="passflow-report passflow-compare">' . $html . '</div>', $header, $notices, __('Comparer', $d));
