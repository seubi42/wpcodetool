<?php
/**
 * Fiche d'un créneau : infos, participants (pointage), liste d'attente et actions.
 *
 * @var array $item
 */

use Smbb\Tomo\Admin\Actions;
use Smbb\Tomo\Admin\View;
use Smbb\Tomo\Repository\Creneaux;
use Smbb\Tomo\Repository\Envois;
use Smbb\Tomo\Repository\Inscriptions;
use Smbb\Tomo\Repository\Lookup;
use Smbb\Tomo\Support\Choices;
use Smbb\Tomo\Support\Settings;

defined('ABSPATH') || exit;

$d = 'smbb-tomo';
$header = isset($page_header_html) ? $page_header_html : '';
$notices = isset($notices_html) ? $notices_html : '';
$creneau = !empty($item['id']) ? Creneaux::find($item['id']) : null;

if (!$creneau) {
    View::page(View::card('', '<p>' . esc_html__('Créneau introuvable.', $d) . '</p>'), $header, $notices, __('Créneau', $d));
    return;
}

$id = (int) $creneau['id'];
$atelier = Lookup::atelier($creneau['atelier_id']);
$price = $creneau['price'] !== null && $creneau['price'] !== '' ? $creneau['price'] : ($atelier ? $atelier['default_price'] : null);
$is_cancelled = $creneau['status'] === 'cancelled';
$participants = Inscriptions::forCreneau($id, true);
$waitlist = Inscriptions::waitlist($id);
$is_pass = \Smbb\Tomo\Service\Passes::isPass($creneau);
$pass_days = $is_pass ? \Smbb\Tomo\Service\Passes::days($creneau) : array();
$day_links = array();

foreach ($pass_days as $day) {
    $day_links[] = '<a href="' . esc_url(View::url('tomo_creneau', array('view' => 'details', 'id' => (int) $day['id']))) . '">' . esc_html(Lookup::creneauLabel($day)) . '</a> ' . View::fill($day['booked'], $day['capacity']);
}

// Ce jour fait partie de pass : on les signale.
$in_passes = array();

if (!$is_pass) {
    foreach (\Smbb\Tomo\Service\Passes::passesOf($id) as $pass_id) {
        $pass = Creneaux::find($pass_id);

        if ($pass) {
            $in_passes[] = '<a href="' . esc_url(View::url('tomo_creneau', array('view' => 'details', 'id' => (int) $pass_id))) . '">' . esc_html(Lookup::creneauWhen($pass)) . '</a>';
        }
    }
}

// Intervenants prévus et leur disponibilité (pastille + réponse à leur place).
$staff_html = '';
$staff_ids = Lookup::ids($creneau['intervenant_ids']);

if ($staff_ids) {
    $dispo_open = \Smbb\Tomo\Service\Availability::open($creneau);
    $dispos = \Smbb\Tomo\Service\Availability::forCreneaux(array($id));
    $all_staff = Lookup::intervenants();
    $waiting_answer = 0;
    $staff_html = '<ul class="tomo-dispo-list">';

    foreach ($staff_ids as $staff_id) {
        if (!isset($all_staff[$staff_id])) {
            continue;
        }

        $row = isset($dispos[$id][$staff_id]) ? $dispos[$id][$staff_id] : null;
        $state = \Smbb\Tomo\Service\Availability::state($row);
        $waiting_answer += in_array($state, array('yes', 'no'), true) ? 0 : 1;
        $set = function ($answer, $label) use ($id, $staff_id) {
            return '<a href="' . esc_url(View::actionUrl(Actions::DISPO_SET, array('id' => $id, 'intervenant' => $staff_id, 'answer' => $answer))) . '">' . esc_html($label) . '</a>';
        };
        $staff_html .= '<li><strong>' . esc_html(Lookup::personName($all_staff[$staff_id])) . '</strong> ' . ($dispo_open || $row ? \Smbb\Tomo\Service\Availability::badge($state) : '')
            . ($row && $row['answered_at'] ? ' <span class="tomo-muted">' . esc_html(sprintf(__('le %1$s%2$s', $d), View::datetime($row['answered_at']), $row['source'] === 'admin' ? ' ' . __('(saisi par l\'équipe)', $d) : '')) . '</span>' : '')
            . ($dispo_open ? ' <span class="tomo-dispo-set no-print">' . esc_html__('Saisir :', $d) . ' ' . ($state !== 'yes' ? $set('yes', __('dispo', $d)) : '') . ($state === 'none' || $state === 'asked' ? ' · ' : '') . ($state !== 'no' ? $set('no', __('non dispo', $d)) : '') . '</span>' : '')
            . '</li>';
    }

    $staff_html .= '</ul>';

    if ($dispo_open && $waiting_answer > 0) {
        $staff_html .= '<p class="no-print"><a class="button button-small" href="' . esc_url(View::actionUrl(Actions::DISPO_REQUEST, array('id' => $id))) . '"><span class="dashicons dashicons-email-alt" aria-hidden="true"></span> ' . esc_html__('Demander les dispos', $d) . '</a> <span class="tomo-muted">' . esc_html__('E-mail et SMS à ceux qui n\'ont pas répondu.', $d) . '</span></p>';
    }

    if ($dispo_open) {
        $staff_html .= '<p class="no-print"><a href="' . esc_url(View::url('tomo_campagnes', array('nouvelle' => 1, 'creneaux' => $id))) . '">' . esc_html__('Demander à d\'autres intervenants (demande de dispo)', $d) . '</a></p>';
    }
}

// En-tête : résumé + actions.
$summary = View::definitionList(array(
    __('Atelier', $d) => esc_html(Lookup::atelierName($creneau['atelier_id'])),
    __('Date', $d) => esc_html(Lookup::creneauWhen($creneau)),
    __('Lieu', $d) => $atelier ? esc_html($atelier['location']) : '',
    __('Intervenants', $d) => $staff_html,
    __('Remplissage', $d) => View::fill($creneau['booked'], $is_pass ? $creneau['booked'] + $creneau['remaining'] : $creneau['capacity'], $creneau['waiting']) . ' <span class="tomo-muted">' . esc_html(sprintf(_n('%d place libre', '%d places libres', $creneau['remaining'], $d), $creneau['remaining'])) . '</span>',

    __('Prix', $d) => View::money($price),
    __('Statut', $d) => Choices::creneauBadge($creneau) . ($is_cancelled && $creneau['cancel_reason'] ? ' <span class="tomo-muted">' . esc_html($creneau['cancel_reason']) . '</span>' : ''),
    __('Notes internes', $d) => nl2br(esc_html((string) $creneau['internal_note'])),
) + ($day_links ? array(__('Jours du pass', $d) => implode('<br>', $day_links)) : array())
  + ($in_passes ? array(__('Aussi vendu dans', $d) => implode('<br>', $in_passes)) : array()));

$add_url = View::url('tomo_inscription', array('view' => 'form', 'creneau_id' => $id));
$buttons = '<p class="tomo-actions no-print">'
    . (!$is_cancelled ? '<a class="button button-primary" href="' . esc_url($add_url) . '"><span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span> ' . esc_html__('Ajouter un participant', $d) . '</a> ' : '')
    . '<a class="button" href="' . esc_url(View::url('tomo_creneau', array('view' => 'form', 'id' => $id))) . '"><span class="dashicons dashicons-edit" aria-hidden="true"></span> ' . esc_html__('Modifier', $d) . '</a> '
    . (!$is_pass ? '<a class="button" href="' . esc_url(View::url('tomo_espace', array('creneau' => $id))) . '"><span class="dashicons dashicons-smartphone" aria-hidden="true"></span> ' . esc_html__('Gestion mobile', $d) . '</a> ' : '')
    . '<a class="button" href="' . esc_url(View::actionUrl(Actions::EXPORT, array('id' => $id))) . '"><span class="dashicons dashicons-download" aria-hidden="true"></span> ' . esc_html__('Exporter (CSV)', $d) . '</a> '
    . '<button type="button" class="button" onclick="window.print()"><span class="dashicons dashicons-printer" aria-hidden="true"></span> ' . esc_html__('Imprimer la liste', $d) . '</button>'
    . '</p>';

$html = View::card('', $summary . $buttons, 'tomo-head');

// Pass : état de chaque jour sous la réservation principale.
$pass_children = function (array $p) use ($d) {
    $out = array();

    foreach (\Smbb\Tomo\Service\Passes::children($p['id'], true) as $child) {
        $out[] = esc_html(date_i18n('D j M', strtotime($child['start_date'] . ' 12:00:00'))) . ' ' . Choices::badge('inscriptionStatuses', $child['status']);
    }

    return $out ? '<br><span class="tomo-pass-days">' . implode(' ', $out) . '</span>' : '';
};

// Participants : les places occupées d'abord, les annulés repliés en dessous.
$rows = '';
$cancelled_rows = '';
$n = 0;
$pending = 0;

foreach ($participants as $p) {
    $is_seat = in_array($p['status'], Choices::seatStatuses(), true);
    $details_url = View::url('tomo_inscription', array('view' => 'details', 'id' => (int) $p['id']));
    $infos = array();

    if (!empty($p['allergies'])) {
        $infos[] = '<span class="tomo-allergy">' . esc_html__('Allergies :', $d) . ' ' . esc_html($p['allergies']) . '</span>';
    }

    if (!empty($p['level'])) {
        $infos[] = esc_html(Choices::label('levels', $p['level']));
    }

    if (!empty($p['age'])) {
        $infos[] = esc_html(sprintf(__('%d ans', $d), (int) $p['age']));
    }

    if (!empty($p['notes'])) {
        $infos[] = '<span class="tomo-muted">' . esc_html($p['notes']) . '</span>';
    }

    if (!empty($p['intervenant_note'])) {
        $infos[] = '<span class="tomo-intervenant-note"><span class="dashicons dashicons-format-quote" aria-hidden="true"></span> ' . esc_html($p['intervenant_note']) . '</span>';
    }

    $person = '<a href="' . esc_url($details_url) . '"><strong>' . esc_html(Lookup::personName($p)) . '</strong></a><br><span class="tomo-muted">' . esc_html(trim($p['email'] . ' · ' . $p['phone'], ' ·')) . '</span>';

    if (!$is_seat) {
        $cancelled_rows .= '<tr><td>' . $person . '</td><td>' . Choices::inscriptionBadge($p) . '</td><td class="no-print"><a class="button button-small" href="' . esc_url($details_url) . '">' . esc_html__('Ouvrir', $d) . '</a></td></tr>';
        continue;
    }

    $n++;
    $pending += $p['status'] === 'pending' ? 1 : 0;

    $rows .= '<tr>'
        . '<td>' . (int) $n . '</td>'
        . '<td>' . $person . '</td>'
        . '<td>' . implode('<br>', $infos) . '</td>'
        . '<td>' . Choices::inscriptionBadge($p) . View::passBadge($p) . '<br><span class="tomo-muted">' . esc_html(Choices::label('sources', $p['source'])) . '</span>' . ($is_pass ? $pass_children($p) : '') . '</td>'
        . '<td class="no-print"><span class="tomo-actions">' . View::quickActions($p, $creneau) . ' <a class="button button-small" href="' . esc_url($details_url) . '" title="' . esc_attr__('Déplacer, annuler, historique', $d) . '">' . esc_html__('Gérer', $d) . '</a></span></td>'
        . '</tr>';
}

$participants_html = $pending > 0
    ? '<div class="tomo-alert no-print">' . esc_html(sprintf(_n('%d réservation est à confirmer.', '%d réservations sont à confirmer.', $pending, $d), $pending)) . '</div>'
    : '';

$participants_html .= $rows !== ''
    ? '<table class="widefat striped tomo-table"><thead><tr><th>#</th><th>' . esc_html__('Participant', $d) . '</th><th>' . esc_html__('Infos', $d) . '</th><th>' . esc_html__('Statut', $d) . '</th><th class="no-print">' . esc_html__('Actions', $d) . '</th></tr></thead><tbody>' . $rows . '</tbody></table>'
    : '<p class="tomo-muted">' . esc_html__('Aucun inscrit pour le moment.', $d) . '</p>';

if ($cancelled_rows !== '') {
    $participants_html .= View::fold(
        sprintf(_n('%d réservation annulée', '%d réservations annulées', substr_count($cancelled_rows, '<tr>'), $d), substr_count($cancelled_rows, '<tr>')),
        '<table class="widefat striped tomo-table"><tbody>' . $cancelled_rows . '</tbody></table>',
        false,
        'dashicons-dismiss',
        'no-print'
    );
}

// Liste d'attente.
$wait_rows = '';

foreach ($waitlist as $w) {
    $book_url = View::actionUrl(Actions::WAITLIST_BOOK, array('id' => (int) $w['id']));
    $can_book = $creneau['remaining'] >= max(1, (int) $w['places']);
    $wait_rows .= '<tr>'
        . '<td><strong>' . esc_html(Lookup::personName($w)) . '</strong><br><span class="tomo-muted">' . esc_html(trim($w['email'] . ' · ' . $w['phone'], ' ·')) . '</span></td>'
        . '<td>' . esc_html(sprintf(_n('%d place', '%d places', (int) $w['places'], $d), (int) $w['places'])) . '</td>'
        . '<td>' . View::datetime($w['creation_date']) . '</td>'
        . '<td>' . Choices::badge('waitlistStatuses', $w['status']) . '</td>'
        . '<td><span class="tomo-actions">'
        . ($can_book
            ? '<a class="button button-small button-primary" href="' . esc_url($book_url) . '">' . esc_html__('Inscrire', $d) . '</a> '
            : '<a class="button button-small" href="' . esc_url(add_query_arg('force', 1, $book_url)) . '" data-smbb-confirm="' . esc_attr__('Le créneau est complet : inscrire quand même, au-delà des places en vente ?', $d) . '" onclick="return confirm(this.dataset.smbbConfirm)">' . esc_html__('Inscrire en surnombre', $d) . '</a> ')
        . '<a class="button button-small" href="' . esc_url(View::url('tomo_attente', array('view' => 'form', 'id' => (int) $w['id']))) . '">' . esc_html__('Modifier', $d) . '</a>'
        . '</span></td></tr>';
}

$wait_html = $wait_rows !== ''
    ? '<table class="widefat striped tomo-table"><thead><tr><th>' . esc_html__('Personne', $d) . '</th><th>' . esc_html__('Places', $d) . '</th><th>' . esc_html__('Demandé le', $d) . '</th><th>' . esc_html__('Statut', $d) . '</th><th></th></tr></thead><tbody>' . $wait_rows . '</tbody></table>'
    : '<p class="tomo-muted">' . esc_html__("Personne en liste d'attente.", $d) . '</p>';

if (!$is_cancelled) {
    $wait_html .= '<p><a class="button" href="' . esc_url(View::url('tomo_attente', array('view' => 'form', 'creneau_id' => $id))) . '"><span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span> ' . esc_html__("Ajouter à la liste d'attente", $d) . '</a></p>';
}

$tabs = array(
    array($is_pass ? sprintf(__('Pass vendus (%d)', $d), $creneau['booked']) : sprintf(__('Participants (%1$d / %2$d)', $d), $creneau['booked'], $creneau['capacity']), $participants_html, 'tomo-print'),
);

// Sans liste d'attente (Réglages), l'onglet ne sert plus, sauf pour les demandes déjà enregistrées.
if (Settings::waitlistEnabled() || $waitlist) {
    $tabs[] = array(sprintf(__("Liste d'attente (%d)", $d), count($waitlist)), $wait_html, 'no-print');
}

// Messages : envoyés pour ce créneau, et rappels à la demande.
$messages = Envois::forCreneau($id);
$messages_html = '';

if (!$is_cancelled && !Creneaux::isPast($creneau)) {
    $remind_confirm = esc_attr__('Envoyer le rappel maintenant ?', $d);
    $messages_html .= '<p class="tomo-actions">'
        . '<a class="button" href="' . esc_url(View::actionUrl(Actions::REMIND, array('id' => $id, 'target' => 'participants'))) . '" data-smbb-confirm="' . $remind_confirm . '" onclick="return confirm(this.dataset.smbbConfirm)"><span class="dashicons dashicons-bell" aria-hidden="true"></span> ' . esc_html__('Envoyer le rappel aux participants', $d) . '</a> '
        . '<a class="button" href="' . esc_url(View::actionUrl(Actions::REMIND, array('id' => $id, 'target' => 'intervenants'))) . '" data-smbb-confirm="' . $remind_confirm . '" onclick="return confirm(this.dataset.smbbConfirm)"><span class="dashicons dashicons-businessperson" aria-hidden="true"></span> ' . esc_html__('Envoyer la liste aux intervenants', $d) . '</a>'
        . '</p><p class="description">' . esc_html__('Les rappels partent aussi tout seuls avant l\'atelier (délais dans les réglages). Ces boutons servent à les renvoyer.', $d) . '</p>';
}

$messages_html .= View::messagesTable($messages);
$tabs[] = array(sprintf(__('Messages (%d)', $d), count($messages)), $messages_html, 'no-print');

// Actions rares : repliées dans leur propre onglet.
if (!$is_cancelled) {
    $duplicate = View::formStart(Actions::CRENEAU_DUPLICATE, $id, 'tomo-inline-form')
        . '<p>' . esc_html__('Mêmes horaires, places, prix et intervenants. Une date par champ.', $d) . '</p>'
        . '<p class="tomo-dates">';

    for ($i = 0; $i < 4; $i++) {
        $duplicate .= '<input type="date" name="dates[]"> ';
    }

    $duplicate .= '</p><p><button type="submit" class="button">' . esc_html__('Créer ces créneaux', $d) . '</button></p></form>';

    $cancel = View::formStart(Actions::CRENEAU_CANCEL, $id, 'tomo-inline-form')
        . '<p>' . esc_html($creneau['booked'] > 0
            ? sprintf(_n('Le créneau passe « Annulé ». Sa réservation repasse « à confirmer », sans date, et attend un nouveau créneau dans le stock.', 'Le créneau passe « Annulé ». Ses %d réservations repassent « à confirmer », sans date, et attendent un nouveau créneau dans le stock.', $creneau['booked'], $d), $creneau['booked'])
            : __('Le créneau passe « Annulé ». Il n\'a aucun inscrit.', $d)) . '</p>'
        . '<p><input type="text" name="reason" class="regular-text" placeholder="' . esc_attr__('Motif (facultatif)', $d) . '"></p>'
        . '<p><button type="submit" class="button button-link-delete" onclick="return confirm(\'' . esc_js(__('Annuler ce créneau ?', $d)) . '\')">' . esc_html__('Annuler le créneau', $d) . '</button></p></form>';

    $tabs[] = array(
        __('Autres actions', $d),
        View::fold(__("Dupliquer sur d'autres dates", $d), $duplicate, false, 'dashicons-admin-page')
            . View::fold(__('Annuler le créneau', $d), $cancel, false, 'dashicons-dismiss'),
        'no-print',
    );
}

$html .= View::tabs($tabs, 'creneau');

View::page($html, $header, $notices, __('Créneau', $d));
