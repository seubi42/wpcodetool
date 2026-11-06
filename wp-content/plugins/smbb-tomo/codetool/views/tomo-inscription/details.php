<?php
/**
 * Fiche d'une inscription : participant, déplacement, annulation et historique.
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

defined('ABSPATH') || exit;

$d = 'smbb-tomo';
$header = isset($page_header_html) ? $page_header_html : '';
$notices = isset($notices_html) ? $notices_html : '';
$inscription = !empty($item['id']) ? Inscriptions::find($item['id']) : null;

if (!$inscription) {
    View::page(View::card('', '<p>' . esc_html__('Inscription introuvable.', $d) . '</p>'), $header, $notices, __('Inscription', $d));
    return;
}

$id = (int) $inscription['id'];
$creneau = !empty($inscription['creneau_id']) ? Creneaux::find($inscription['creneau_id']) : null;

$creneau_html = $creneau
    ? '<a href="' . esc_url(View::url('tomo_creneau', array('view' => 'details', 'id' => (int) $creneau['id']))) . '">' . esc_html(Lookup::creneauLabel($creneau)) . '</a> ' . ' ' . View::seats($creneau)
    : '<em>' . esc_html__('Aucun créneau', $d) . '</em> — ' . esc_html(Lookup::atelierName($inscription['atelier_id']));

// Pass : la réservation principale liste ses jours ; un jour renvoie vers elle.
if (\Smbb\Tomo\Service\Passes::isPass($creneau)) {
    $day_rows = array();

    foreach (\Smbb\Tomo\Service\Passes::children($id, true) as $child) {
        $day_rows[] = '<a href="' . esc_url(View::url('tomo_inscription', array('view' => 'details', 'id' => (int) $child['id']))) . '">' . esc_html(date_i18n('D j M Y', strtotime($child['start_date'] . ' 12:00:00'))) . '</a> ' . Choices::badge('inscriptionStatuses', $child['status']);
    }

    $creneau_html .= '<br><span class="tomo-pass-days">' . ($day_rows ? implode(' ', $day_rows) : esc_html__('Aucun jour actif', $d)) . '</span>';
} elseif (!empty($inscription['pass_id'])) {
    $creneau_html .= '<br><span class="tomo-muted">' . esc_html__('Jour compris dans un pass : billet, QR, documents et paiement sont sur la réservation principale.', $d) . '</span>';
}

$name = '<p class="tomo-person"><strong>' . esc_html(Lookup::personName($inscription)) . '</strong> ' . Choices::inscriptionBadge($inscription) . View::passBadge($inscription) . '</p>';

$summary = View::definitionList(array(
    __('Créneau', $d) => $creneau_html,
    __('E-mail', $d) => $inscription['email'] ? '<a href="mailto:' . esc_attr($inscription['email']) . '">' . esc_html($inscription['email']) . '</a>' : '',
    __('Téléphone', $d) => $inscription['phone'] ? '<a href="tel:' . esc_attr($inscription['phone']) . '">' . esc_html($inscription['phone']) . '</a>' : '',
    __('Allergies', $d) => $inscription['allergies'] ? '<span class="tomo-allergy">' . esc_html($inscription['allergies']) . '</span>' : '',
    __('Niveau', $d) => $inscription['level'] !== '' ? esc_html(Choices::label('levels', $inscription['level'])) : '',
    __('Âge', $d) => $inscription['age'] ? esc_html(sprintf(__('%d ans', $d), (int) $inscription['age'])) : '',
    __('Remarques', $d) => nl2br(esc_html((string) $inscription['notes'])),
    __("Note de l'intervenant", $d) => nl2br(esc_html((string) $inscription['intervenant_note'])),
));

// Actions courantes en tête : confirmer / pointer, modifier.
$quick = View::quickActions($inscription, $creneau, '');
$hint = '';

if ($inscription['status'] === 'pending' && $creneau) {
    $hint = '<p class="description">' . esc_html__('La place est bloquée. Confirmez quand le paiement ou l\'accord du participant est reçu.', $d) . '</p>';
} elseif ($inscription['status'] === 'pending') {
    $hint = '<p class="description">' . esc_html__('Cette réservation attend une nouvelle date : choisissez un créneau ci-dessous.', $d) . '</p>';
}

$summary .= '<p class="tomo-actions">' . $quick . ' <a class="button" href="' . esc_url(View::url('tomo_inscription', array('view' => 'form', 'id' => $id))) . '"><span class="dashicons dashicons-edit" aria-hidden="true"></span> ' . esc_html__('Modifier', $d) . '</a></p>' . $hint;

$html = View::card('', $name . $summary, 'tomo-head');

// Le reste est replié : on l'ouvre seulement quand on en a besoin.
$folds = '';

if ($inscription['status'] !== 'cancelled') {
    $move = View::formStart(Actions::MOVE, $id, 'tomo-inline-form')
        . '<p>' . View::select('creneau_id', Creneaux::moveOptions($inscription['creneau_id'], __('— Choisir un créneau —', $d)), '', 'class="tomo-wide-select" required') . '</p>'
        . '<p><input type="hidden" name="confirm_present" value="1"><label><input type="checkbox" name="confirm" value="1" checked> ' . esc_html__('Réservation payée sur cette date', $d) . '</label><br>'
        . '<label><input type="checkbox" name="force" value="1"> ' . esc_html__('Autoriser le dépassement des places', $d) . '</label></p>'
        . '<p><button type="submit" class="button button-primary">' . esc_html($creneau ? __('Déplacer', $d) : __('Placer sur ce créneau', $d)) . '</button></p></form>';

    $folds .= View::fold($creneau ? __('Déplacer vers un autre créneau', $d) : __('Placer sur un créneau', $d), $move, !$creneau, 'dashicons-randomize');

    $cancel = View::formStart(Actions::CANCEL, $id, 'tomo-inline-form')
        . ($creneau ? '<p><label><input type="radio" name="mode" value="replace" checked> ' . esc_html__('Reporter : libérer la place, la réservation repasse « à confirmer » en attendant une autre date', $d) . '</label><br>' : '<p>')
        . '<label><input type="radio" name="mode" value="cancel"' . ($creneau ? '' : ' checked') . '> ' . esc_html__('Annuler définitivement la réservation', $d) . '</label></p>'
        . '<p><input type="text" name="reason" class="regular-text" placeholder="' . esc_attr__('Motif (facultatif)', $d) . '"></p>'
        . '<p class="description">' . esc_html__("Le remboursement éventuel se fait dans la commande WooCommerce.", $d) . '</p>'
        . '<p><button type="submit" class="button button-link-delete">' . esc_html__('Valider', $d) . '</button></p></form>';

    $folds .= View::fold($creneau ? __('Reporter ou annuler', $d) : __('Annuler la réservation', $d), $cancel, false, 'dashicons-dismiss');
}

$order = '';

if (!empty($inscription['order_id'])) {
    $order_url = admin_url('post.php?post=' . (int) $inscription['order_id'] . '&action=edit');
    $order = '<a href="' . esc_url($order_url) . '">#' . (int) $inscription['order_id'] . '</a>';
}

$folds .= View::fold(__('Origine et paiement', $d), View::definitionList(array(
    __('Source', $d) => esc_html(Choices::label('sources', $inscription['source'])),
    __('Commande', $d) => $order,
    __('Montant payé', $d) => View::money($inscription['price_paid']),
    __('Compte client', $d) => esc_html(Lookup::userLabel($inscription['customer_id'])),
)), false, 'dashicons-cart');

// Documents (décharge, droit à l'image…) : demandes envoyées, réponses, signatures, lien à envoyer.
if (\Smbb\Tomo\Service\Documents::enabled()) {
    $doc_root = \Smbb\Tomo\Service\Passes::root($inscription); // jour d'un pass : tout est sur la réservation principale.
    $answers = \Smbb\Tomo\Service\Documents::answersFor($doc_root['id']);
    $doc_rows = '';
    $done = 0;
    $all_docs = \Smbb\Tomo\Service\Documents::all();

    foreach ($all_docs as $doc) {
        $answer = isset($answers[$doc['key']]) ? $answers[$doc['key']] : null;
        $status = \Smbb\Tomo\Service\Documents::status($doc, $answer);
        $done += $answer ? 1 : 0;
        $detail = '<span class="tomo-muted">' . esc_html__('Pas encore de réponse', $d) . '</span>';

        if ($answer) {
            $detail = esc_html(sprintf(__('%1$s, par %2$s (%3$s)', $d), View::datetime($answer['signed_at']), $answer['signer_name'] . ($answer['signer_role'] === 'representant' ? ' ' . __('pour le participant', $d) : ''), $answer['method'] === 'onsite' ? __('sur place', $d) : __('en ligne', $d)));

            if (!empty($answer['signature']) && strpos($answer['signature'], 'data:image/png;base64,') === 0) {
                $detail .= '<br><img class="tomo-doc-signature" src="' . esc_attr($answer['signature']) . '" alt="' . esc_attr__('Signature', $d) . '">';
            }
        }

        $doc_rows .= '<tr><td><strong>' . esc_html($doc['title']) . '</strong><br><span class="tomo-muted">' . esc_html($doc['kind'] === 'signature' ? __('À signer', $d) : __('Oui / non', $d)) . '</span></td>'
            . '<td><span class="tomo-badge tomo-badge--' . ($status['state'] === 'ok' ? 'confirmed' : ($status['state'] === 'no' ? 'absent' : 'pending')) . '">' . esc_html($status['label']) . '</span></td>'
            . '<td>' . $detail . '</td></tr>';
    }

    $docs_html = '<table class="widefat striped tomo-table"><thead><tr><th>' . esc_html__('Document', $d) . '</th><th>' . esc_html__('Réponse', $d) . '</th><th>' . esc_html__('Détail', $d) . '</th></tr></thead><tbody>' . $doc_rows . '</tbody></table>';

    // Demandes : messages partis avec le lien, et dernière ouverture du lien.
    $requests = \Smbb\Tomo\Service\Documents::requests($doc_root);
    $viewed = \Smbb\Tomo\Service\Documents::lastView($doc_root['id']);
    $docs_html .= '<h4 class="tomo-subtitle">' . esc_html(sprintf(_n('Demande envoyée (%d)', 'Demandes envoyées (%d)', count($requests), $d), count($requests))) . '</h4>';
    $docs_html .= $requests
        ? View::messagesTable($requests, true)
        : '<p class="tomo-muted">' . esc_html__("Aucun message avec le lien des documents n'est encore parti. Le lien est ajouté à la confirmation et au rappel tant qu'il reste quelque chose à remplir.", $d) . '</p>';
    $docs_html .= '<p>' . ($viewed !== ''
        ? esc_html(sprintf(__('Lien ouvert par le participant, la dernière fois le %s.', $d), View::datetime($viewed)))
        : '<span class="tomo-muted">' . esc_html__('Le participant n\'a pas encore ouvert le lien.', $d) . '</span>') . '</p>';

    $link = \Smbb\Tomo\Service\Documents::url($doc_root);
    $docs_html .= '<p><span class="tomo-muted">' . esc_html__('Lien du participant :', $d) . '</span> <input type="text" readonly class="regular-text" value="' . esc_attr($link) . '" onclick="this.select()"> '
        . '<a class="button" href="' . esc_url(\Smbb\Tomo\Service\Documents::url($doc_root, true)) . '">' . esc_html__('Remplir sur cet appareil', $d) . '</a></p>';

    if (\Smbb\Tomo\Service\Documents::pendingUrl($doc_root) !== '') {
        $docs_html .= '<p><a class="button button-primary" href="' . esc_url(View::actionUrl(Actions::DOCUMENTS_SEND, array('id' => (int) $doc_root['id']))) . '"><span class="dashicons dashicons-email-alt" aria-hidden="true"></span> ' . esc_html__('Envoyer la demande de documents', $d) . '</a> '
            . '<span class="tomo-muted">' . esc_html(sprintf(__('À %s, par e-mail et SMS selon Réglages › Messages.', $d), trim($doc_root['email'] . ' · ' . $doc_root['phone'], ' ·') ?: __('personne (aucune coordonnée)', $d))) . '</span></p>';
    }
    $folds .= View::fold(sprintf(__('Documents (%1$d/%2$d remplis)', $d), $done, count($all_docs)), $docs_html, $inscription['status'] !== 'cancelled', 'dashicons-media-text');
}

// Billet : lien PDF pour le renvoyer ou l'imprimer.
if (\Smbb\Tomo\Service\Tickets::available($inscription)) {
    $folds .= View::fold(__('Billet', $d), '<p><a class="button" href="' . esc_url(\Smbb\Tomo\Service\Tickets::url($inscription, 'pdf')) . '"><span class="dashicons dashicons-pdf" aria-hidden="true"></span> ' . esc_html__('Billet PDF', $d) . '</a> '
        . '<input type="text" readonly class="regular-text" value="' . esc_attr(\Smbb\Tomo\Service\Tickets::url($inscription, 'pdf')) . '" onclick="this.select()"></p>', false, 'dashicons-tickets-alt');
}

// Historique.
$history = Inscriptions::history($id);
$rows = '';

foreach ($history as $event) {
    $user = $event['user_id'] ? get_userdata((int) $event['user_id']) : null;
    $rows .= '<tr><td>' . View::datetime($event['created_at']) . '</td>'
        . '<td>' . esc_html(Choices::label('historyActions', $event['action'])) . '</td>'
        . '<td>' . esc_html((string) $event['message']) . '</td>'
        . '<td>' . esc_html($event['user_id'] ? ($user ? $user->display_name : '#' . (int) $event['user_id']) : __('Système', $d)) . '</td></tr>';
}

$folds .= View::fold(sprintf(__('Historique (%d)', $d), count($history)), $rows !== ''
    ? '<table class="widefat striped tomo-table"><thead><tr><th>' . esc_html__('Date', $d) . '</th><th>' . esc_html__('Action', $d) . '</th><th>' . esc_html__('Détail', $d) . '</th><th>' . esc_html__('Par', $d) . '</th></tr></thead><tbody>' . $rows . '</tbody></table>'
    : '<p class="tomo-muted">' . esc_html__('Aucun mouvement.', $d) . '</p>', false, 'dashicons-backup');

$messages = Envois::forInscription($id);
$folds .= View::fold(sprintf(__('Messages envoyés (%d)', $d), count($messages)), View::messagesTable($messages, false), false, 'dashicons-email-alt');

$html .= '<div class="tomo-folds">' . $folds . '</div>';

View::page($html, $header, $notices, __('Inscription', $d));
