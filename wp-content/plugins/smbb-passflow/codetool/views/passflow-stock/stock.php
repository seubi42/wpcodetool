<?php
/**
 * Stock des inscriptions non réalisées : places vendues à venir et réservations sans date.
 */

use Smbb\Passflow\Admin\Actions;
use Smbb\Passflow\Admin\View;
use Smbb\Passflow\Repository\Slots;
use Smbb\Passflow\Repository\Bookings;
use Smbb\Passflow\Repository\Lookup;
use Smbb\Passflow\Support\Choices;
use Smbb\Passflow\Support\Input;
use Smbb\Passflow\Support\Schema;

defined('ABSPATH') || exit;

$d = 'smbb-passflow';
$header = isset($page_header_html) ? $page_header_html : '';
$notices = isset($notices_html) ? $notices_html : '';

if (!Schema::ready()) {
    View::schemaMissing($header, $notices, __('Produits constatés d\'avance', $d));
    return;
}

$tab = isset($_GET['tab']) && $_GET['tab'] === 'replace' ? 'replace' : 'upcoming';
$activity_id = isset($_GET['atelier']) ? absint($_GET['atelier']) : 0;
$to = isset($_GET['to']) && Input::isDate(sanitize_text_field(wp_unslash($_GET['to']))) ? sanitize_text_field(wp_unslash($_GET['to'])) : '';

$upcoming = Bookings::upcomingConfirmed(array('activity_id' => $activity_id, 'to' => $to));
$replace = Bookings::withoutDate(array('activity_id' => $activity_id));

// Totaux par atelier.
$totals = array();

foreach ($upcoming as $row) {
    $key = (int) $row['activity_id'];
    $totals[$key] = isset($totals[$key]) ? $totals[$key] : array('upcoming' => 0, 'replace' => 0, 'amount' => 0.0);
    $totals[$key]['upcoming']++;
    $totals[$key]['amount'] += (float) $row['price_paid'];
}

foreach ($replace as $row) {
    $key = (int) $row['activity_id'];
    $totals[$key] = isset($totals[$key]) ? $totals[$key] : array('upcoming' => 0, 'replace' => 0, 'amount' => 0.0);
    $totals[$key]['replace']++;
    $totals[$key]['amount'] += (float) $row['price_paid'];
}

$html = '<div class="passflow-kpis">'
    . '<a class="passflow-kpi" href="' . esc_url(View::url('passflow_stock', array('tab' => 'upcoming', 'atelier' => $activity_id ?: false))) . '"><span class="dashicons dashicons-tickets-alt" aria-hidden="true"></span><strong>' . esc_html(number_format_i18n(count($upcoming))) . '</strong><small>' . esc_html__('Places vendues à venir', $d) . '</small></a>'
    . '<a class="passflow-kpi" href="' . esc_url(View::url('passflow_stock', array('tab' => 'replace', 'atelier' => $activity_id ?: false))) . '"><span class="dashicons dashicons-calendar" aria-hidden="true"></span><strong>' . esc_html(number_format_i18n(count($replace))) . '</strong><small>' . esc_html__('Réservations sans date', $d) . '</small></a>'
    . '<div class="passflow-kpi"><span class="dashicons dashicons-money-alt" aria-hidden="true"></span><strong>' . View::money(array_sum(array_column($totals, 'amount'))) . '</strong><small>' . esc_html__('Encaissé, non réalisé (montants connus)', $d) . '</small></div>'
    . '</div>';

// Filtres.
$html .= '<form method="get" class="passflow-filters">'
    . '<input type="hidden" name="page" value="smbb-codetool-passflow_stock">'
    . '<input type="hidden" name="tab" value="' . esc_attr($tab) . '">'
    . '<label>' . esc_html__('Atelier', $d) . ' ' . View::select('atelier', Lookup::activityOptions(__('Tous', $d)), $activity_id ?: '') . '</label> '
    . ($tab === 'upcoming' ? '<label>' . esc_html__("Jusqu'au", $d) . ' <input type="date" name="to" value="' . esc_attr($to) . '"></label> ' : '')
    . '<button class="button">' . esc_html__('Filtrer', $d) . '</button>'
    . '</form>';

// Par atelier.
if ($totals) {
    $rows = '';

    foreach ($totals as $key => $total) {
        $rows .= '<tr><td>' . esc_html(Lookup::activityName($key)) . '</td><td>' . (int) $total['upcoming'] . '</td><td>' . (int) $total['replace'] . '</td><td>' . View::money($total['amount']) . '</td></tr>';
    }

    $html .= View::card(__('Par atelier', $d), '<table class="widefat striped passflow-table"><thead><tr><th>' . esc_html__('Atelier', $d) . '</th><th>' . esc_html__('À venir', $d) . '</th><th>' . esc_html__('Sans date', $d) . '</th><th>' . esc_html__('Montant', $d) . '</th></tr></thead><tbody>' . $rows . '</tbody></table>');
}

$html .= '<div class="passflow-segments passflow-segments--tabs">'
    . '<a class="' . ($tab === 'upcoming' ? 'is-active' : '') . '" href="' . esc_url(View::url('passflow_stock', array('tab' => 'upcoming', 'atelier' => $activity_id ?: false))) . '">' . esc_html(sprintf(__('À venir (%d)', $d), count($upcoming))) . '</a>'
    . '<a class="' . ($tab === 'replace' ? 'is-active' : '') . '" href="' . esc_url(View::url('passflow_stock', array('tab' => 'replace', 'atelier' => $activity_id ?: false))) . '">' . esc_html(sprintf(__('Sans date (%d)', $d), count($replace))) . '</a>'
    . '</div>';

if ($tab === 'upcoming') {
    $rows = '';

    foreach ($upcoming as $row) {
        $rows .= '<tr>'
            . '<td>' . esc_html(Lookup::slotWhen($row)) . '</td>'
            . '<td>' . esc_html(Lookup::activityName($row['activity_id'])) . '</td>'
            . '<td><a href="' . esc_url(View::url('passflow_booking', array('view' => 'details', 'id' => (int) $row['id']))) . '">' . esc_html(Lookup::personName($row)) . '</a></td>'
            . '<td>' . esc_html(Choices::label('sources', $row['source'])) . '</td>'
            . '<td>' . View::money($row['price_paid']) . '</td>'
            . '</tr>';
    }

    $html .= View::card('', $rows !== ''
        ? '<table class="widefat striped passflow-table"><thead><tr><th>' . esc_html__('Créneau', $d) . '</th><th>' . esc_html__('Atelier', $d) . '</th><th>' . esc_html__('Participant', $d) . '</th><th>' . esc_html__('Source', $d) . '</th><th>' . esc_html__('Payé', $d) . '</th></tr></thead><tbody>' . $rows . '</tbody></table>'
        : '<p class="passflow-muted">' . esc_html__('Aucune place vendue à venir.', $d) . '</p>');
} else {
    $rows = '';
    $options_by_activity = array();

    foreach ($replace as $row) {
        $key = (int) $row['activity_id'];

        if (!isset($options_by_activity[$key])) {
            $options_by_activity[$key] = array(__('— Choisir un créneau —', $d) => '');

            foreach (Slots::upcoming(0, array('activity_id' => $key, 'statuses' => array('open', 'full', 'draft'), 'with_passes' => true)) as $slot) {
                $options_by_activity[$key][Lookup::slotWhen($slot) . ' · ' . sprintf(_n('%d place libre', '%d places libres', $slot['remaining'], $d), $slot['remaining'])] = (string) $slot['id'];
            }
        }

        $place = count($options_by_activity[$key]) > 1
            ? View::formStart(Actions::MOVE, $row['id'], 'passflow-row-form') . View::select('slot_id', $options_by_activity[$key], '', 'required') . ' <button class="button button-small button-primary">' . esc_html__('Placer', $d) . '</button></form>'
            : '<span class="passflow-muted">' . esc_html__('Aucun créneau à venir pour cet atelier.', $d) . '</span>';

        $rows .= '<tr>'
            . '<td><a href="' . esc_url(View::url('passflow_booking', array('view' => 'details', 'id' => (int) $row['id']))) . '"><strong>' . esc_html(Lookup::personName($row)) . '</strong></a><br><span class="passflow-muted">' . esc_html(trim($row['email'] . ' · ' . $row['phone'], ' ·')) . '</span></td>'
            . '<td>' . esc_html(Lookup::activityName($row['activity_id'])) . '</td>'
            . '<td>' . View::datetime($row['lastupdate_date']) . '</td>'
            . '<td>' . $place . '</td>'
            . '</tr>';
    }

    $html .= View::card('', $rows !== ''
        ? '<table class="widefat striped passflow-table"><thead><tr><th>' . esc_html__('Participant', $d) . '</th><th>' . esc_html__('Atelier', $d) . '</th><th>' . esc_html__('Depuis le', $d) . '</th><th>' . esc_html__('Placer sur', $d) . '</th></tr></thead><tbody>' . $rows . '</tbody></table>'
        : '<p class="passflow-muted">' . esc_html__('Aucune réservation en attente de date.', $d) . '</p>');
}

View::page($html, $header, $notices, __('Produits constatés d\'avance', $d));
