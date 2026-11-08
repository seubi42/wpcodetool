<?php
/**
 * Détail d'un envoi : destinataire, texte envoyé, résultat.
 *
 * @var array $item
 */

use Smbb\Passflow\Admin\View;
use Smbb\Passflow\Repository\Slots;
use Smbb\Passflow\Repository\Bookings;
use Smbb\Passflow\Repository\Lookup;
use Smbb\Passflow\Support\Choices;

defined('ABSPATH') || exit;

$d = 'smbb-passflow';
$header = isset($page_header_html) ? $page_header_html : '';
$notices = isset($notices_html) ? $notices_html : '';

if (empty($item['id'])) {
    View::page(View::card('', '<p>' . esc_html__('Envoi introuvable.', $d) . '</p>'), $header, $notices, __('Envoi', $d));
    return;
}

$slot = !empty($item['slot_id']) ? Slots::find($item['slot_id']) : null;
$booking = !empty($item['booking_id']) ? Bookings::find($item['booking_id']) : null;

$summary = View::definitionList(array(
    __('Message', $d) => Choices::badge('channels', $item['channel']) . ' ' . esc_html(Choices::label('deliveryEvents', $item['event'])),
    __('Date', $d) => View::datetime($item['sent_at']),
    __('Destinataire', $d) => esc_html(trim($item['recipient_name'] . ' · ' . Choices::label('recipientTypes', $item['recipient_type']), ' ·')) . '<br>' . esc_html($item['recipient']),
    __('Résultat', $d) => Choices::badge('deliveryStatuses', $item['status']) . ($item['error'] ? ' <span class="passflow-error">' . esc_html($item['error']) . '</span>' : ''),
    __('Créneau', $d) => $slot ? '<a href="' . esc_url(View::url('passflow_slot', array('view' => 'details', 'id' => (int) $slot['id']))) . '">' . esc_html(Lookup::slotLabel($slot)) . '</a>' : '',
    __('Inscription', $d) => $booking ? '<a href="' . esc_url(View::url('passflow_booking', array('view' => 'details', 'id' => (int) $booking['id']))) . '">' . esc_html(Lookup::personName($booking)) . '</a>' : '',
    __('Référence Twilio', $d) => esc_html((string) $item['provider_id']),
));

$body = ($item['subject'] !== '' ? '<p><strong>' . esc_html($item['subject']) . '</strong></p>' : '')
    . '<pre class="passflow-message-body">' . esc_html((string) $item['body']) . '</pre>';

View::page(View::card('', $summary, 'passflow-head') . View::card(__('Texte envoyé', $d), $body), $header, $notices, __('Envoi', $d));
