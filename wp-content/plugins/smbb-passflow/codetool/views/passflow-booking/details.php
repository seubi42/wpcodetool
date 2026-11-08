<?php
/**
 * Fiche d'une inscription : participant, déplacement, annulation et historique.
 *
 * @var array $item
 */

use Smbb\Passflow\Admin\Actions;
use Smbb\Passflow\Admin\View;
use Smbb\Passflow\Repository\Slots;
use Smbb\Passflow\Repository\Deliveries;
use Smbb\Passflow\Repository\Bookings;
use Smbb\Passflow\Repository\Lookup;
use Smbb\Passflow\Support\Choices;

defined('ABSPATH') || exit;

$d = 'smbb-passflow';
$header = isset($page_header_html) ? $page_header_html : '';
$notices = isset($notices_html) ? $notices_html : '';
$booking = !empty($item['id']) ? Bookings::find($item['id']) : null;

if (!$booking) {
    View::page(View::card('', '<p>' . esc_html__('Inscription introuvable.', $d) . '</p>'), $header, $notices, __('Inscription', $d));
    return;
}

$id = (int) $booking['id'];
$slot = !empty($booking['slot_id']) ? Slots::find($booking['slot_id']) : null;

$slot_html = $slot
    ? '<a href="' . esc_url(View::url('passflow_slot', array('view' => 'details', 'id' => (int) $slot['id']))) . '">' . esc_html(Lookup::slotLabel($slot)) . '</a> ' . ' ' . View::seats($slot)
    : '<em>' . esc_html__('Aucun créneau', $d) . '</em> — ' . esc_html(Lookup::activityName($booking['activity_id']));

// Pass : la réservation principale liste ses jours ; un jour renvoie vers elle.
if (\Smbb\Passflow\Service\Passes::isPass($slot)) {
    $day_rows = array();

    foreach (\Smbb\Passflow\Service\Passes::children($id, true) as $child) {
        $day_rows[] = '<a href="' . esc_url(View::url('passflow_booking', array('view' => 'details', 'id' => (int) $child['id']))) . '">' . esc_html(date_i18n('D j M Y', strtotime($child['start_date'] . ' 12:00:00'))) . '</a> ' . Choices::badge('bookingStatuses', $child['status']);
    }

    $slot_html .= '<br><span class="passflow-pass-days">' . ($day_rows ? implode(' ', $day_rows) : esc_html__('Aucun jour actif', $d)) . '</span>';
} elseif (!empty($booking['pass_id'])) {
    $slot_html .= '<br><span class="passflow-muted">' . esc_html__('Jour compris dans un pass : billet, QR, documents et paiement sont sur la réservation principale.', $d) . '</span>';
}

$name = '<p class="passflow-person"><strong>' . esc_html(Lookup::personName($booking)) . '</strong> ' . Choices::bookingBadge($booking) . View::passBadge($booking) . '</p>';

$summary = View::definitionList(array(
    __('Créneau', $d) => $slot_html,
    __('E-mail', $d) => $booking['email'] ? '<a href="mailto:' . esc_attr($booking['email']) . '">' . esc_html($booking['email']) . '</a>' : '',
    __('Téléphone', $d) => $booking['phone'] ? '<a href="tel:' . esc_attr($booking['phone']) . '">' . esc_html($booking['phone']) . '</a>' : '',
    __('Allergies', $d) => $booking['allergies'] ? '<span class="passflow-allergy">' . esc_html($booking['allergies']) . '</span>' : '',
    __('Niveau', $d) => $booking['level'] !== '' ? esc_html(Choices::label('levels', $booking['level'])) : '',
    __('Âge', $d) => $booking['age'] ? esc_html(sprintf(__('%d ans', $d), (int) $booking['age'])) : '',
    __('Remarques', $d) => nl2br(esc_html((string) $booking['notes'])),
    __("Note de l'intervenant", $d) => nl2br(esc_html((string) $booking['host_note'])),
));

// Actions courantes en tête : confirmer / pointer, modifier.
$quick = View::quickActions($booking, $slot, '');
$hint = '';

if ($booking['status'] === 'pending' && $slot) {
    $hint = '<p class="description">' . esc_html__('La place est bloquée. Confirmez quand le paiement ou l\'accord du participant est reçu.', $d) . '</p>';
} elseif ($booking['status'] === 'pending') {
    $hint = '<p class="description">' . esc_html__('Cette réservation attend une nouvelle date : choisissez un créneau ci-dessous.', $d) . '</p>';
}

$summary .= '<p class="passflow-actions">' . $quick . ' <a class="button" href="' . esc_url(View::url('passflow_booking', array('view' => 'form', 'id' => $id))) . '"><span class="dashicons dashicons-edit" aria-hidden="true"></span> ' . esc_html__('Modifier', $d) . '</a></p>' . $hint;

$html = View::card('', $name . $summary, 'passflow-head');

// Le reste est replié : on l'ouvre seulement quand on en a besoin.
$folds = '';

if ($booking['status'] !== 'cancelled') {
    $move = View::formStart(Actions::MOVE, $id, 'passflow-inline-form')
        . '<p>' . View::select('slot_id', Slots::moveOptions($booking['slot_id'], __('— Choisir un créneau —', $d)), '', 'class="passflow-wide-select" required') . '</p>'
        . '<p><input type="hidden" name="confirm_present" value="1"><label><input type="checkbox" name="confirm" value="1" checked> ' . esc_html__('Réservation payée sur cette date', $d) . '</label><br>'
        . '<label><input type="checkbox" name="force" value="1"> ' . esc_html__('Autoriser le dépassement des places', $d) . '</label></p>'
        . '<p><button type="submit" class="button button-primary">' . esc_html($slot ? __('Déplacer', $d) : __('Placer sur ce créneau', $d)) . '</button></p></form>';

    $folds .= View::fold($slot ? __('Déplacer vers un autre créneau', $d) : __('Placer sur un créneau', $d), $move, !$slot, 'dashicons-randomize');

    $cancel = View::formStart(Actions::CANCEL, $id, 'passflow-inline-form')
        . ($slot ? '<p><label><input type="radio" name="mode" value="replace" checked> ' . esc_html__('Reporter : libérer la place, la réservation repasse « à confirmer » en attendant une autre date', $d) . '</label><br>' : '<p>')
        . '<label><input type="radio" name="mode" value="cancel"' . ($slot ? '' : ' checked') . '> ' . esc_html__('Annuler définitivement la réservation', $d) . '</label></p>'
        . '<p><input type="text" name="reason" class="regular-text" placeholder="' . esc_attr__('Motif (facultatif)', $d) . '"></p>'
        . '<p class="description">' . esc_html__("Le remboursement éventuel se fait dans la commande WooCommerce.", $d) . '</p>'
        . '<p><button type="submit" class="button button-link-delete">' . esc_html__('Valider', $d) . '</button></p></form>';

    $folds .= View::fold($slot ? __('Reporter ou annuler', $d) : __('Annuler la réservation', $d), $cancel, false, 'dashicons-dismiss');
}

$order = '';

if (!empty($booking['order_id'])) {
    $order_url = admin_url('post.php?post=' . (int) $booking['order_id'] . '&action=edit');
    $order = '<a href="' . esc_url($order_url) . '">#' . (int) $booking['order_id'] . '</a>';
}

$folds .= View::fold(__('Origine et paiement', $d), View::definitionList(array(
    __('Source', $d) => esc_html(Choices::label('sources', $booking['source'])),
    __('Commande', $d) => $order,
    __('Montant payé', $d) => View::money($booking['price_paid']),
    __('Compte client', $d) => esc_html(Lookup::userLabel($booking['customer_id'])),
)), false, 'dashicons-cart');

// Documents (décharge, droit à l'image…) : demandes envoyées, réponses, signatures, lien à envoyer.
if (\Smbb\Passflow\Service\Documents::enabled()) {
    $doc_root = \Smbb\Passflow\Service\Passes::root($booking); // jour d'un pass : tout est sur la réservation principale.
    $answers = \Smbb\Passflow\Service\Documents::answersFor($doc_root['id']);
    $doc_rows = '';
    $done = 0;
    $all_docs = \Smbb\Passflow\Service\Documents::all();

    foreach ($all_docs as $doc) {
        $answer = isset($answers[$doc['key']]) ? $answers[$doc['key']] : null;
        $status = \Smbb\Passflow\Service\Documents::status($doc, $answer);
        $done += $answer ? 1 : 0;
        $detail = '<span class="passflow-muted">' . esc_html__('Pas encore de réponse', $d) . '</span>';

        if ($answer) {
            $detail = esc_html(sprintf(__('%1$s, par %2$s (%3$s)', $d), View::datetime($answer['signed_at']), $answer['signer_name'] . ($answer['signer_role'] === 'representant' ? ' ' . __('pour le participant', $d) : ''), $answer['method'] === 'onsite' ? __('sur place', $d) : __('en ligne', $d)));

            if (!empty($answer['signature']) && strpos($answer['signature'], 'data:image/png;base64,') === 0) {
                $detail .= '<br><img class="passflow-doc-signature" src="' . esc_attr($answer['signature']) . '" alt="' . esc_attr__('Signature', $d) . '">';
            }

            // Formulaire joint : réponses et fichiers, avec l'affichage de CodeTool › Formulaires.
            if (!empty($answer['entry_id'])) {
                $entry = \Smbb\Passflow\Service\Documents::entry($answer);

                if ($entry) {
                    \Smbb\WpCodeTool\Forms\EntryView::enqueue();
                    $detail .= '<div class="passflow-doc-entry">' . \Smbb\WpCodeTool\Forms\EntryView::html($entry, array('compact' => true));

                    if (current_user_can(\Smbb\WpCodeTool\Forms\Forms::capability())) {
                        $detail .= '<p><a href="' . esc_url(admin_url('admin.php?page=' . \Smbb\WpCodeTool\Admin\Pages\FormsPage::ENTRIES_SLUG . '&entry=' . (int) $entry['id'])) . '">' . esc_html(sprintf(__('Entrée n° %d dans Formulaires', $d), $entry['id'])) . '</a></p>';
                    }

                    $detail .= '</div>';
                } else {
                    $detail .= '<br><span class="passflow-muted">' . esc_html__('Réponses du formulaire supprimées (Formulaires › Entrées).', $d) . '</span>';
                }
            }
        }

        $doc_rows .= '<tr><td><strong>' . esc_html($doc['title']) . '</strong><br><span class="passflow-muted">' . esc_html(array('signature' => __('À signer', $d), 'consent' => __('Oui / non', $d), 'form' => __('Formulaire', $d))[$doc['kind']] . (\Smbb\Passflow\Service\Documents::form($doc) && $doc['kind'] !== 'form' ? ' + ' . __('formulaire', $d) : '')) . '</span></td>'
            . '<td><span class="passflow-badge passflow-badge--' . ($status['state'] === 'ok' ? 'confirmed' : ($status['state'] === 'no' ? 'absent' : 'pending')) . '">' . esc_html($status['label']) . '</span></td>'
            . '<td>' . $detail . '</td></tr>';
    }

    $docs_html = '<table class="widefat striped passflow-table"><thead><tr><th>' . esc_html__('Document', $d) . '</th><th>' . esc_html__('Réponse', $d) . '</th><th>' . esc_html__('Détail', $d) . '</th></tr></thead><tbody>' . $doc_rows . '</tbody></table>';

    // Demandes : messages partis avec le lien, et dernière ouverture du lien.
    $requests = \Smbb\Passflow\Service\Documents::requests($doc_root);
    $viewed = \Smbb\Passflow\Service\Documents::lastView($doc_root['id']);
    $docs_html .= '<h4 class="passflow-subtitle">' . esc_html(sprintf(_n('Demande envoyée (%d)', 'Demandes envoyées (%d)', count($requests), $d), count($requests))) . '</h4>';
    $docs_html .= $requests
        ? View::messagesTable($requests, true)
        : '<p class="passflow-muted">' . esc_html__("Aucun message avec le lien des documents n'est encore parti. Le lien est ajouté à la confirmation et au rappel tant qu'il reste quelque chose à remplir.", $d) . '</p>';
    $docs_html .= '<p>' . ($viewed !== ''
        ? esc_html(sprintf(__('Lien ouvert par le participant, la dernière fois le %s.', $d), View::datetime($viewed)))
        : '<span class="passflow-muted">' . esc_html__('Le participant n\'a pas encore ouvert le lien.', $d) . '</span>') . '</p>';

    $link = \Smbb\Passflow\Service\Documents::url($doc_root);
    $docs_html .= '<p><span class="passflow-muted">' . esc_html__('Lien du participant :', $d) . '</span> <input type="text" readonly class="regular-text" value="' . esc_attr($link) . '" onclick="this.select()"> '
        . '<a class="button" href="' . esc_url(\Smbb\Passflow\Service\Documents::url($doc_root, true)) . '">' . esc_html__('Remplir sur cet appareil', $d) . '</a></p>';

    if (\Smbb\Passflow\Service\Documents::pendingUrl($doc_root) !== '') {
        $docs_html .= '<p><a class="button button-primary" href="' . esc_url(View::actionUrl(Actions::DOCUMENTS_SEND, array('id' => (int) $doc_root['id']))) . '"><span class="dashicons dashicons-email-alt" aria-hidden="true"></span> ' . esc_html__('Envoyer la demande de documents', $d) . '</a> '
            . '<span class="passflow-muted">' . esc_html(sprintf(__('À %s, par e-mail et SMS selon Réglages › Messages.', $d), trim($doc_root['email'] . ' · ' . $doc_root['phone'], ' ·') ?: __('personne (aucune coordonnée)', $d))) . '</span></p>';
    }
    $folds .= View::fold(sprintf(__('Documents (%1$d/%2$d remplis)', $d), $done, count($all_docs)), $docs_html, $booking['status'] !== 'cancelled', 'dashicons-media-text');
}

// Billet : lien PDF pour le renvoyer ou l'imprimer.
if (\Smbb\Passflow\Service\Tickets::available($booking)) {
    $folds .= View::fold(__('Billet', $d), '<p><a class="button" href="' . esc_url(\Smbb\Passflow\Service\Tickets::url($booking, 'pdf')) . '"><span class="dashicons dashicons-pdf" aria-hidden="true"></span> ' . esc_html__('Billet PDF', $d) . '</a> '
        . '<input type="text" readonly class="regular-text" value="' . esc_attr(\Smbb\Passflow\Service\Tickets::url($booking, 'pdf')) . '" onclick="this.select()"></p>', false, 'dashicons-tickets-alt');
}

// Transfert du billet à une autre personne (sur la réservation principale d'un pass).
if (empty($booking['pass_id'])) {
    $transfer_log = \Smbb\Passflow\Service\Transfers::log($booking);
    $transfer_html = '';

    if ($transfer_log) {
        $lines = array();

        foreach (array_reverse($transfer_log) as $entry) {
            $lines[] = '<li>' . esc_html(sprintf(
                __('%1$s : %2$s (%3$s) → %4$s, %5$s', $d),
                View::datetime($entry['at']),
                trim($entry['first_name'] . ' ' . $entry['last_name']),
                $entry['email'],
                $entry['to'],
                $entry['by'] === 'staff' ? __("par l'équipe", $d) : __('par le participant', $d)
            )) . '</li>';
        }

        $transfer_html .= '<p><strong>' . esc_html__('Anciens titulaires du billet', $d) . '</strong></p><ul class="passflow-transfer-log">' . implode('', $lines) . '</ul>';
    }

    $transfer_blocked = \Smbb\Passflow\Service\Transfers::blocked($booking, true);

    if ($transfer_blocked === '') {
        $transfer_html .= View::formStart(Actions::TRANSFER, $id, 'passflow-inline-form')
            . '<p class="description">' . esc_html__("La place reste la même (commande, paiement, date). La personne reçoit un nouveau billet (e-mail avec le PDF, SMS, Wallet) ; l'ancien billet, son QR code et ses Wallet sont désactivés. L'acheteur et l'ancien participant sont prévenus.", $d) . '</p>'
            . '<p><input type="text" name="first_name" placeholder="' . esc_attr__('Prénom', $d) . '" required> '
            . '<input type="text" name="last_name" placeholder="' . esc_attr__('Nom', $d) . '" required></p>'
            . '<p><input type="email" name="email" class="regular-text" placeholder="' . esc_attr__('E-mail', $d) . '" required> '
            . '<input type="tel" name="phone" placeholder="' . esc_attr__('Téléphone (facultatif)', $d) . '"></p>'
            . '<p><button type="submit" class="button button-primary" onclick="return confirm(' . esc_attr(wp_json_encode(__('Transférer ce billet ? L\'ancien billet ne sera plus valable.', $d))) . ');">' . esc_html__('Transférer le billet', $d) . '</button></p></form>';
    } elseif (!$transfer_log) {
        $transfer_html = '';
    } else {
        $transfer_html .= '<p class="description">' . esc_html($transfer_blocked) . '</p>';
    }

    if ($transfer_html !== '') {
        $folds .= View::fold($transfer_log ? sprintf(_n('Transfert du billet (%d fois)', 'Transfert du billet (%d fois)', count($transfer_log), $d), count($transfer_log)) : __('Transférer le billet', $d), $transfer_html, false, 'dashicons-randomize');
    }
}

// Historique.
$history = Bookings::history($id);
$rows = '';

foreach ($history as $event) {
    $user = $event['user_id'] ? get_userdata((int) $event['user_id']) : null;
    $rows .= '<tr><td>' . View::datetime($event['created_at']) . '</td>'
        . '<td>' . esc_html(Choices::label('historyActions', $event['action'])) . '</td>'
        . '<td>' . esc_html((string) $event['message']) . '</td>'
        . '<td>' . esc_html($event['user_id'] ? ($user ? $user->display_name : '#' . (int) $event['user_id']) : __('Système', $d)) . '</td></tr>';
}

$folds .= View::fold(sprintf(__('Historique (%d)', $d), count($history)), $rows !== ''
    ? '<table class="widefat striped passflow-table"><thead><tr><th>' . esc_html__('Date', $d) . '</th><th>' . esc_html__('Action', $d) . '</th><th>' . esc_html__('Détail', $d) . '</th><th>' . esc_html__('Par', $d) . '</th></tr></thead><tbody>' . $rows . '</tbody></table>'
    : '<p class="passflow-muted">' . esc_html__('Aucun mouvement.', $d) . '</p>', false, 'dashicons-backup');

$messages = Deliveries::forBooking($id);
$folds .= View::fold(sprintf(__('Messages envoyés (%d)', $d), count($messages)), View::messagesTable($messages, false), false, 'dashicons-email-alt');

$html .= '<div class="passflow-folds">' . $folds . '</div>';

View::page($html, $header, $notices, __('Inscription', $d));
