<?php
/**
 * Détail d'un envoi : destinataire, texte envoyé, résultat.
 *
 * @var array $item
 */

use Smbb\Tomo\Admin\View;
use Smbb\Tomo\Repository\Creneaux;
use Smbb\Tomo\Repository\Inscriptions;
use Smbb\Tomo\Repository\Lookup;
use Smbb\Tomo\Support\Choices;

defined('ABSPATH') || exit;

$d = 'smbb-tomo';
$header = isset($page_header_html) ? $page_header_html : '';
$notices = isset($notices_html) ? $notices_html : '';

if (empty($item['id'])) {
    View::page(View::card('', '<p>' . esc_html__('Envoi introuvable.', $d) . '</p>'), $header, $notices, __('Envoi', $d));
    return;
}

$creneau = !empty($item['creneau_id']) ? Creneaux::find($item['creneau_id']) : null;
$inscription = !empty($item['inscription_id']) ? Inscriptions::find($item['inscription_id']) : null;

$summary = View::definitionList(array(
    __('Message', $d) => Choices::badge('channels', $item['channel']) . ' ' . esc_html(Choices::label('envoiEvents', $item['event'])),
    __('Date', $d) => View::datetime($item['sent_at']),
    __('Destinataire', $d) => esc_html(trim($item['recipient_name'] . ' · ' . Choices::label('recipientTypes', $item['recipient_type']), ' ·')) . '<br>' . esc_html($item['recipient']),
    __('Résultat', $d) => Choices::badge('envoiStatuses', $item['status']) . ($item['error'] ? ' <span class="tomo-error">' . esc_html($item['error']) . '</span>' : ''),
    __('Créneau', $d) => $creneau ? '<a href="' . esc_url(View::url('tomo_creneau', array('view' => 'details', 'id' => (int) $creneau['id']))) . '">' . esc_html(Lookup::creneauLabel($creneau)) . '</a>' : '',
    __('Inscription', $d) => $inscription ? '<a href="' . esc_url(View::url('tomo_inscription', array('view' => 'details', 'id' => (int) $inscription['id']))) . '">' . esc_html(Lookup::personName($inscription)) . '</a>' : '',
    __('Référence Twilio', $d) => esc_html((string) $item['provider_id']),
));

$body = ($item['subject'] !== '' ? '<p><strong>' . esc_html($item['subject']) . '</strong></p>' : '')
    . '<pre class="tomo-message-body">' . esc_html((string) $item['body']) . '</pre>';

View::page(View::card('', $summary, 'tomo-head') . View::card(__('Texte envoyé', $d), $body), $header, $notices, __('Envoi', $d));
