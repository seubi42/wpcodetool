<?php
/**
 * Mode « Jour J » : écran plein écran de l'accueil (tablette) pendant une date.
 * Arrivées, attendus / retardataires, documents manquants ; rafraîchi toutes les 8 secondes.
 * Le contenu est construit par assets/dayboard.js à partir de DayBoard::data().
 *
 * @var array $item
 */

use Smbb\Passflow\Admin\DayBoard;
use Smbb\Passflow\Admin\RollCall;
use Smbb\Passflow\Admin\View;
use Smbb\Passflow\Repository\Lookup;
use Smbb\Passflow\Repository\Slots;
use Smbb\Passflow\Support\Roles;

defined('ABSPATH') || exit;

$d = 'smbb-passflow';
$header = isset($page_header_html) ? $page_header_html : '';
$notices = isset($notices_html) ? $notices_html : '';
$slot = !empty($item['id']) ? Slots::find($item['id']) : null;

if (!$slot || !Roles::canManage() || !DayBoard::available($slot)) {
    View::page(View::card('', '<p>' . esc_html__('Suivi tablette jour J indisponible pour cette date.', $d) . '</p>'), $header, $notices, __('Suivi tablette jour J', $d));
    return;
}

$id = (int) $slot['id'];
$activity = Lookup::atelier($slot['activity_id']);
$back = View::url('passflow_slot', array('view' => 'details', 'id' => $id));
$version = SMBB_PASSFLOW_VERSION . '.' . (int) @filemtime(SMBB_PASSFLOW_PATH . 'assets/dayboard.js');

wp_enqueue_style('passflow-dayboard', SMBB_PASSFLOW_URL . 'assets/dayboard.css', array('smbb-passflow-admin'), SMBB_PASSFLOW_VERSION . '.' . (int) @filemtime(SMBB_PASSFLOW_PATH . 'assets/dayboard.css'));
wp_enqueue_script('passflow-dayboard', SMBB_PASSFLOW_URL . 'assets/dayboard.js', array(), $version, true);
// « Faire signer » : lightbox par-dessus le Jour J au lieu d'un nouvel onglet.
\Smbb\Passflow\Service\Documents::enqueueFrame();
wp_add_inline_script('passflow-dayboard', 'window.passflowDayboard=' . wp_json_encode(array(
    'url' => admin_url('admin-ajax.php'),
    'creneau' => $id,
    'action' => DayBoard::AJAX,
    'nonce' => DayBoard::nonce($id),
    'rollcall' => RollCall::AJAX,
    'rollcallNonce' => RollCall::nonce($id),
    'back' => $back,
    'every' => 8,
    'capture' => \Smbb\Passflow\Support\Settings::get('scan_capture') === 'field' ? 'field' : 'keys',
    'data' => DayBoard::data($slot),
    'texts' => array(
        'arrivals' => __('Arrivées', $d),
        'expected' => __('Attendus', $d),
        'late' => __('Retardataires', $d),
        'docs' => __('Documents manquants', $d),
        'noArrival' => __('Personne pour le moment.', $d),
        'allHere' => __('Tout le monde est là !', $d),
        'noDocs' => __('Tous les documents sont faits.', $d),
        'startsIn' => __('Début dans %s', $d),
        'startedFor' => __('Commencé depuis %s', $d),
        'ended' => __('Terminé', $d),
        'allDay' => __('Toute la journée', $d),
        'future' => __("Aperçu : l'atelier n'a pas encore lieu, le pointage est fermé.", $d),
        'past' => __("Aperçu : l'atelier a déjà eu lieu, le pointage est fermé.", $d),
        'lateBy' => __('%s de retard', $d),
        'arrivedLate' => __('arrivé avec %s de retard', $d),
        'walkin' => __('Imprévu', $d),
        'sign' => __('Faire signer', $d),
        'here' => __('Sur place', $d),
        'notHere' => __('Pas encore arrivé', $d),
        'markPresent' => __('Arrivé', $d),
        'markAbsent' => __('Absent', $d),
        'undo' => __('Annuler le pointage', $d),
        'cancel' => __('Fermer', $d),
        'call' => __('Appeler', $d),
        'justArrived' => __('%s vient d\'arriver', $d),
        'live' => __('En direct', $d),
        'offline' => __('Hors ligne, nouvel essai…', $d),
        'updated' => __('mis à jour à %s', $d),
        'scanReady' => __('Douchette prête', $d),
        'present' => __('présents', $d),
        'outOf' => __('sur %d inscrits', $d),
        'kpiNone' => __('à pointer', $d),
        'kpiAbsent' => __('absents', $d),
        'kpiWalkin' => __('imprévus', $d),
        'kpiDocs' => __('documents à faire', $d),
        'docsMissing' => __('%d doc. manquant', $d),
        'error' => __('Action impossible.', $d),
        'sound' => __('Son des arrivées', $d),
        'fullscreen' => __('Plein écran', $d),
        'forced' => __('Accès forcé : le pointage est ouvert même si ce n\'est pas le jour de l\'atelier.', $d),
        'force' => __('Pointer quand même', $d),
        'unforce' => __('Refermer', $d),
    ),
)) . ';', 'before');

$hours = Lookup::slotHours($slot, true, __('Toute la journée', $d));
$date = ucfirst(date_i18n('l j F', strtotime($slot['start_date'] . ' 12:00:00')));
$icon = function ($name) {
    return '<span class="dashicons dashicons-' . esc_attr($name) . '" aria-hidden="true"></span>';
};

$html = '<div class="passflow-jj" data-passflow-jj>'
    // Barre du haut : quoi, quand, l'heure, l'état du direct et les boutons.
    . '<header class="passflow-jj-top">'
    . '<a class="passflow-jj-btn passflow-jj-back" href="' . esc_url($back) . '" title="' . esc_attr__('Quitter le suivi tablette', $d) . '">' . $icon('arrow-left-alt2') . '<span class="screen-reader-text">' . esc_html__('Quitter', $d) . '</span></a>'
    . '<div class="passflow-jj-title"><small>' . esc_html__('Suivi tablette jour J', $d) . ' · ' . esc_html($date) . ($hours !== '' ? ' · ' . esc_html($hours) : '') . '</small>'
    . '<h1>' . esc_html($activity ? $activity['name'] : Lookup::activityName($slot['activity_id'])) . '</h1>'
    . ($activity && !empty($activity['location']) ? '<span class="passflow-jj-where">' . $icon('location') . esc_html($activity['location']) . '</span>' : '')
    . '</div>'
    . '<div class="passflow-jj-clock"><strong data-jj-clock>--:--</strong><span data-jj-countdown></span></div>'
    . '<div class="passflow-jj-tools">'
    . '<span class="passflow-jj-live" data-jj-live><i></i><span data-jj-live-text>' . esc_html__('En direct', $d) . '</span></span>'
    . '<span class="passflow-jj-scan" data-jj-scan hidden>' . $icon('id')
    // Réglage « Avec champ » : le lecteur écrit dans un petit champ gardé actif (sans clavier virtuel).
    . (\Smbb\Passflow\Support\Settings::get('scan_capture') === 'field'
        ? '<input type="text" class="passflow-jj-scan-input" data-jj-scan-input inputmode="none" autocomplete="off" autocapitalize="characters" spellcheck="false" placeholder="' . esc_attr__('Douchette prête', $d) . '" aria-label="' . esc_attr__('Badge lu par la douchette', $d) . '">'
        : '<span>' . esc_html__('Douchette prête', $d) . '</span>')
    . '</span>'
    . '<button type="button" class="passflow-jj-btn" data-jj-sound aria-pressed="false" title="' . esc_attr__('Son des arrivées', $d) . '">' . $icon('controls-volumeoff') . '</button>'
    . '<button type="button" class="passflow-jj-btn" data-jj-full title="' . esc_attr__('Plein écran', $d) . '">' . $icon('editor-expand') . '</button>'
    . '</div>'
    . '</header>'
    . '<div class="passflow-jj-banner" data-jj-banner hidden><span data-jj-banner-text></span>'
    . '<button type="button" class="passflow-jj-force" data-jj-force aria-pressed="false">' . $icon('unlock') . '<span>' . esc_html__('Pointer quand même', $d) . '</span></button></div>'
    // Bandeau de chiffres : anneau de présence + compteurs.
    . '<section class="passflow-jj-hero">'
    . '<div class="passflow-jj-ring" data-jj-ring><svg viewBox="0 0 120 120" aria-hidden="true"><circle class="passflow-jj-ring-bg" cx="60" cy="60" r="52"/><circle class="passflow-jj-ring-absent" cx="60" cy="60" r="52" data-jj-ring-absent/><circle class="passflow-jj-ring-fill" cx="60" cy="60" r="52" data-jj-ring-fill/></svg>'
    . '<div class="passflow-jj-ring-text"><strong data-jj-present>0</strong><span data-jj-outof></span></div></div>'
    . '<div class="passflow-jj-kpis">'
    . '<div class="passflow-jj-kpi is-none"><strong data-jj-kpi="none">0</strong><span>' . esc_html__('à pointer', $d) . '</span></div>'
    . '<div class="passflow-jj-kpi is-absent"><strong data-jj-kpi="absent">0</strong><span>' . esc_html__('absents', $d) . '</span></div>'
    . '<div class="passflow-jj-kpi is-walkin"><strong data-jj-kpi="walkin">0</strong><span>' . esc_html__('imprévus', $d) . '</span></div>'
    . '<div class="passflow-jj-kpi is-docs" data-jj-kpi-docs><strong data-jj-kpi="docs">0</strong><span>' . esc_html__('documents à faire', $d) . '</span></div>'
    . '</div>'
    . '</section>'
    // Les trois colonnes.
    . '<section class="passflow-jj-cols">'
    . '<div class="passflow-jj-col is-arrivals"><h2>' . $icon('yes-alt') . '<span>' . esc_html__('Arrivées', $d) . '</span><em data-jj-n="arrivals">0</em></h2><ol class="passflow-jj-list" data-jj-list="arrivals"></ol></div>'
    . '<div class="passflow-jj-col is-expected"><h2>' . $icon('clock') . '<span data-jj-expected-title>' . esc_html__('Attendus', $d) . '</span><em data-jj-n="expected">0</em></h2><ol class="passflow-jj-list" data-jj-list="expected"></ol></div>'
    . '<div class="passflow-jj-col is-docs" data-jj-col-docs><h2>' . $icon('media-document') . '<span>' . esc_html__('Documents', $d) . '</span><em data-jj-n="missing">0</em></h2><ol class="passflow-jj-list" data-jj-list="missing"></ol></div>'
    . '</section>'
    . '<div class="passflow-jj-toasts" data-jj-toasts aria-live="polite"></div>'
    . '</div>'
    . '<noscript><p>' . esc_html__('Le suivi tablette a besoin de JavaScript.', $d) . '</p></noscript>';

View::page($html, '', $notices, __('Suivi tablette jour J', $d), 'compact');
