<?php
/**
 * Fiche d'un document : envoi, suivi, Universign, stockage et chronologie.
 */

use Smbb\SignConnectProxima\Admin\DocumentActions;
use Smbb\SignConnectProxima\Admin\View;
use Smbb\SignConnectProxima\Front\Ajax;
use Smbb\SignConnectProxima\Repository\Lookup;
use Smbb\SignConnectProxima\Support\Choices;
use Smbb\SignConnectProxima\Support\Levels;

defined('ABSPATH') || exit;

global $wpdb;

$d = 'smbb-signaconnect-proxima';
$item = isset($item) && is_array($item) ? $item : array();
$document_id = isset($item['id']) ? (int) $item['id'] : 0;
$v = function ($key) use ($item) {
    return isset($item[$key]) ? (string) $item[$key] : '';
};

$fields = $document_id ? $wpdb->get_results($wpdb->prepare('SELECT page_number, field_type, label FROM ' . Lookup::table('scp_document_field') . ' WHERE deleted_flag = 0 AND document_id = %d ORDER BY page_number, id', $document_id), ARRAY_A) : array();
$events = $document_id ? $wpdb->get_results($wpdb->prepare('SELECT event_date, event_type, actor_type, message FROM ' . Lookup::table('scp_audit_log') . ' WHERE deleted_flag = 0 AND document_id = %d ORDER BY event_date ASC, id ASC', $document_id), ARRAY_A) : array();

$html = '<div class="scp-card"><h2>' . esc_html__('Document', $d) . '</h2>' . View::definitionList(array(
    __('Fichier', $d) => esc_html($v('filename')) . ' <span class="scp-muted">(' . esc_html(size_format((int) $v('file_size'))) . ', ' . esc_html(sprintf(_n('%d page', '%d pages', (int) $v('page_count'), $d), (int) $v('page_count'))) . ')</span>',
    __('Statut', $d) => Choices::badge('documentStatuses', $v('status')),
    __('Niveau', $d) => esc_html(Levels::label($v('signature_level'))),
    __('Placement des champs', $d) => esc_html(Choices::label('detectionSources', $v('detection_source'))),
    __('Empreinte source (SHA-256)', $d) => $v('source_sha256') !== '' ? '<code class="scp-code">' . esc_html($v('source_sha256')) . '</code>' : '',
    __('Empreinte signée (SHA-256)', $d) => $v('signed_sha256') !== '' ? '<code class="scp-code">' . esc_html($v('signed_sha256')) . '</code>' : '',
)) . '</div>';

$html .= '<div class="scp-card"><h2>' . esc_html__('Envoi', $d) . '</h2>' . View::definitionList(array(
    __('Organisation', $d) => esc_html(Lookup::organizationName($v('organization_id'))),
    __('Équipe', $d) => esc_html(Lookup::teamName($v('team_id'))),
    __('Expéditeur', $d) => esc_html(Lookup::userLabel($v('sender_id'))),
    __('Destinataire', $d) => esc_html(\Smbb\SignConnectProxima\Repository\Documents::recipientName($item)) . '<br>' . esc_html($v('recipient_email')) . ' ' . esc_html($v('recipient_phone')),
    __('Message', $d) => nl2br(esc_html($v('send_message'))),
    __('Champs du modèle', $d) => implode('<br>', array_map(function ($field) {
        return esc_html($field['label'] . ' : ' . $field['value']);
    }, array_values(\Smbb\SignConnectProxima\Support\Placeholders::custom($item)))),
    __("Nom d'origine", $d) => esc_html($v('source_filename')),
    __('Envoyé le', $d) => View::datetime($v('sent_at')),
    __('Expire le', $d) => View::datetime($v('expires_at')),
    __('Ouvert le', $d) => View::datetime($v('opened_at')),
    __('Signé le', $d) => View::datetime($v('signed_at')),
    __('Signataire', $d) => esc_html($v('signer_name')) . ($v('signer_ip') !== '' ? ' <span class="scp-muted">· IP ' . esc_html($v('signer_ip')) . '</span>' : ''),
    __('Motif du refus', $d) => nl2br(esc_html($v('refusal_message'))),
    __('Relances', $d) => esc_html((string) (int) $v('reminder_count')) . ($v('last_reminder_at') !== '' ? ' · ' . View::datetime($v('last_reminder_at')) : ''),
    __('Mois de facturation', $d) => esc_html($v('billing_month')) . ($v('billable') === '1' ? '' : ' <span class="scp-muted">' . esc_html__('(non compté)', $d) . '</span>'),
)) . '</div>';

$sync = $v('universign_transaction_id') !== '' && in_array($v('status'), array('sent', 'opened'), true)
    ? ' <a class="button button-small" href="' . esc_url(DocumentActions::syncUrl($document_id)) . '">' . esc_html__('Actualiser depuis Universign', $d) . '</a>'
    : '';
$html .= '<div class="scp-card"><h2>' . esc_html__('Universign et stockage', $d) . '</h2>' . DocumentActions::notice($document_id) . View::definitionList(array(
    __('Transaction Universign', $d) => $v('universign_transaction_id') !== '' ? '<code class="scp-code">' . esc_html($v('universign_transaction_id')) . '</code>' . $sync : '',
    __('Dernier événement', $d) => esc_html($v('universign_last_event')) . ($v('universign_last_event_at') !== '' ? ' · ' . View::datetime($v('universign_last_event_at')) : ''),
    __('Emplacement', $d) => esc_html(Choices::label('storageLocations', $v('storage_location'))),
    __('PDF d\'origine', $d) => $v('source_storage_path') !== '' ? '<a href="' . esc_url(Ajax::appPdfUrl($document_id)) . '" target="_blank" rel="noopener">' . esc_html__('Ouvrir', $d) . '</a>' : '',
    __('PDF signé', $d) => $item && Ajax::hasFile($item, 'signed') ? '<a href="' . esc_url(Ajax::appPdfUrl($document_id, true, true)) . '">' . esc_html__('Télécharger', $d) . '</a>' . ($v('signed_storage_path') === '' ? ' <span class="scp-muted">' . esc_html__('(lu chez Universign)', $d) . '</span>' : '') : '',
    __('Attestation de preuve', $d) => $item && $v('signature_level') !== Levels::INTERNAL && Ajax::hasFile($item, 'evidence') ? '<a href="' . esc_url(Ajax::appPdfUrl($document_id, 'evidence', true)) . '">' . esc_html__('Télécharger', $d) . '</a>' . ($v('evidence_storage_path') === '' ? ' <span class="scp-muted">' . esc_html__('(lue chez Universign)', $d) . '</span>' : '') : '',
    __('Anonymisé le', $d) => View::datetime($v('anonymized_at')),
)) . '</div>';

$rows = '';

foreach (is_array($fields) ? $fields : array() as $field) {
    $rows .= '<tr><td>' . esc_html((string) $field['page_number']) . '</td><td>' . esc_html((string) $field['field_type']) . '</td><td>' . esc_html((string) $field['label']) . '</td></tr>';
}

$html .= '<div class="scp-card"><h2>' . esc_html__('Champs', $d) . '</h2>'
    . ($rows !== '' ? '<table class="scp-plain"><thead><tr><th>' . esc_html__('Page', $d) . '</th><th>' . esc_html__('Type', $d) . '</th><th>' . esc_html__('Libellé', $d) . '</th></tr></thead><tbody>' . $rows . '</tbody></table>' : '<p class="scp-muted">' . esc_html__('Aucun champ.', $d) . '</p>')
    . '</div>';

$rows = '';

foreach (is_array($events) ? $events : array() as $event) {
    $rows .= '<tr><td>' . View::datetime($event['event_date']) . '</td><td>' . esc_html((string) $event['event_type']) . '</td><td>' . esc_html((string) $event['actor_type']) . '</td><td>' . esc_html((string) $event['message']) . '</td></tr>';
}

$html .= '<div class="scp-card"><h2>' . esc_html__('Chronologie', $d) . '</h2>'
    . ($rows !== '' ? '<table class="scp-plain"><tbody>' . $rows . '</tbody></table>' : '<p class="scp-muted">' . esc_html__('Aucun événement.', $d) . '</p>')
    . '</div>';

if (!empty($list_url)) {
    $html .= '<p><a class="button" href="' . esc_url($list_url) . '">' . esc_html__('Retour à la liste', $d) . '</a></p>';
}

View::page($html, isset($page_header_html) ? $page_header_html : '', isset($notices_html) ? $notices_html : '', __('Document', $d));
