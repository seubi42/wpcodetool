<?php
/**
 * Bilan d'un créneau après l'atelier : présences, ventes sur place, documents, messages.
 * Ouvert depuis la tuile « Bilan » de la fiche ; export CSV et impression.
 *
 * @var array $item
 */

use Smbb\Passflow\Admin\Actions;
use Smbb\Passflow\Admin\View;
use Smbb\Passflow\Repository\Lookup;
use Smbb\Passflow\Repository\Slots;
use Smbb\Passflow\Service\ParticipantMap;
use Smbb\Passflow\Service\Report;
use Smbb\Passflow\Support\Choices;
use Smbb\Passflow\Support\Roles;
use Smbb\Passflow\WooCommerce\OnsiteSales;

defined('ABSPATH') || exit;

$d = 'smbb-passflow';
$header = isset($page_header_html) ? $page_header_html : '';
$notices = isset($notices_html) ? $notices_html : '';
$slot = !empty($item['id']) ? Slots::find($item['id']) : null;

if (!$slot || !Roles::canManage()) {
    View::page(View::card('', '<p>' . esc_html__('Créneau introuvable.', $d) . '</p>'), $header, $notices, __('Bilan', $d));
    return;
}

$id = (int) $slot['id'];
$details_url = View::url('passflow_slot', array('view' => 'details', 'id' => $id));
$r = Report::data($slot);
$p = $r['presence'];
$money = function ($amount) {
    return esc_html(OnsiteSales::money($amount));
};
$kpi = function ($icon, $value, $label, $hint = '', $url = '') {
    $tag = $url !== '' ? 'a href="' . esc_url($url) . '"' : 'div';

    return '<' . $tag . ' class="passflow-kpi"><span class="dashicons ' . esc_attr($icon) . '" aria-hidden="true"></span>'
        . '<strong>' . $value . '</strong><small>' . esc_html($label) . '</small>'
        . ($hint !== '' ? '<em class="passflow-kpi-hint">' . esc_html($hint) . '</em>' : '')
        . '</' . ($url !== '' ? 'a' : 'div') . '>';
};

$html = '<p class="passflow-bc-back no-print"><a href="' . esc_url($details_url) . '"><span class="dashicons dashicons-arrow-left-alt2" aria-hidden="true"></span> '
    . esc_html(sprintf(__('%1$s · %2$s', $d), Lookup::activityName($slot['activity_id']), Lookup::slotWhen($slot))) . '</a></p>';

// En-tête imprimable : de quoi on parle, et les boutons.
$head = '<h2 class="passflow-report-title">' . esc_html(Lookup::activityName($slot['activity_id'])) . '</h2>'
    . '<p class="passflow-report-when">' . esc_html(Lookup::slotWhen($slot))
    . ($r['hosts'] ? ' · ' . esc_html(implode(', ', $r['hosts'])) : '') . '</p>';

if ($slot['status'] === 'cancelled') {
    $head .= '<div class="passflow-alert">' . esc_html__('Ce créneau a été annulé.', $d) . '</div>';
} elseif (!Slots::isPast($slot) && $slot['start_date'] !== current_time('Y-m-d')) {
    $head .= '<div class="passflow-alert no-print">' . esc_html__("L'atelier n'a pas encore eu lieu : les chiffres sont provisoires.", $d) . '</div>';
}

$head .= '<p class="passflow-actions no-print">'
    . (!\Smbb\Passflow\Service\Passes::isPass($slot) ? '<a class="button button-primary passflow-recap-cta" href="' . esc_url(View::url('passflow_slot', array('view' => 'wrapped', 'id' => $id))) . '"><span class="dashicons dashicons-format-video" aria-hidden="true"></span> ' . esc_html__('Voir le récap animé', $d) . '</a> ' : '')
    . '<a class="button" href="' . esc_url(View::actionUrl(Actions::REPORT_EXPORT, array('id' => $id))) . '"><span class="dashicons dashicons-download" aria-hidden="true"></span> ' . esc_html__('Exporter le bilan (CSV)', $d) . '</a> '
    . '<button type="button" class="button" onclick="window.print()"><span class="dashicons dashicons-printer" aria-hidden="true"></span> ' . esc_html__('Imprimer', $d) . '</button> '
    . '<a class="button" href="' . esc_url(View::url('passflow_compare', array('comparer' => 'dates', 'depuis' => $id))) . '"><span class="dashicons dashicons-columns" aria-hidden="true"></span> ' . esc_html__("Comparer avec d'autres dates", $d) . '</a>'
    . '</p>';

$html .= View::card('', $head, 'passflow-report-head');

// --- Présence -----------------------------------------------------------------------------
$presence = '<div class="passflow-kpis passflow-report-kpis">'
    . $kpi('dashicons-groups', (string) $p['booked'], __('Inscrits', $d), $p['cancelled'] ? sprintf(_n('+ %d annulé', '+ %d annulés', $p['cancelled'], $d), $p['cancelled']) : '')
    . $kpi('dashicons-yes-alt', (string) $p['present'], __('Présents', $d), $p['booked'] ? sprintf(__('%d %% des inscrits', $d), $p['rate']) : '')
    . $kpi('dashicons-dismiss', (string) $p['absent'], __('Absents', $d))
    . $kpi('dashicons-clock', (string) $p['unpointed'], __('Non pointés', $d), $p['unpointed'] ? __("Pas vus à l'appel", $d) : '')
    . $kpi('dashicons-plus-alt', (string) $p['walkin'], __('Imprévus', $d), __('Ajoutés sur place', $d))
    . '</div>';

if ($p['booked'] > 0) {
    $bar = '';

    foreach (array('present' => 'is-present', 'absent' => 'is-absent', 'unpointed' => 'is-unpointed') as $key => $class) {
        if ($p[$key] > 0) {
            $bar .= '<span class="' . $class . '" style="width:' . esc_attr((string) round($p[$key] * 100 / $p['booked'], 2)) . '%"></span>';
        }
    }

    $presence .= '<div class="passflow-report-bar" aria-hidden="true">' . $bar . '</div>'
        . '<p class="passflow-report-legend"><span class="is-present"></span>' . esc_html__('Présents', $d) . ' <span class="is-absent"></span>' . esc_html__('Absents', $d) . ' <span class="is-unpointed"></span>' . esc_html__('Non pointés', $d) . '</p>';
}

if ($p['revenue'] > 0) {
    $presence .= '<p class="passflow-muted">' . sprintf(esc_html__('Montant des réservations : %s', $d), '<strong>' . $money($p['revenue']) . '</strong>') . '</p>';
}

// Absents et non pointés, nommés : c'est ce qu'on regarde après l'atelier.
$others = array_filter($r['seats'], function ($b) {
    return $b['status'] !== 'present';
});

if ($others) {
    $rows = '';

    foreach ($others as $b) {
        $rows .= '<tr><td><a href="' . esc_url(View::url('passflow_booking', array('view' => 'details', 'id' => (int) $b['id']))) . '"><strong>' . esc_html(Lookup::personName($b)) . '</strong></a></td>'
            . '<td>' . esc_html(trim($b['email'] . ' · ' . $b['phone'], ' ·')) . '</td>'
            . '<td>' . Choices::bookingBadge($b) . '</td>'
            . '<td>' . esc_html(Choices::label('sources', $b['source'])) . '</td></tr>';
    }

    $presence .= View::fold(
        sprintf(_n('%d participant absent ou non pointé', '%d participants absents ou non pointés', count($others), $d), count($others)),
        '<table class="widefat striped passflow-table"><tbody>' . $rows . '</tbody></table>',
        true,
        'dashicons-warning'
    );
}

$html .= View::card(__('Présence', $d), $presence);

// --- D'où viennent les participants -------------------------------------------------------
if ($p['booked'] > 0) {
    $html .= View::card(__("D'où viennent les participants", $d), ParticipantMap::render(ParticipantMap::forSlots(array($id)), array('compact' => true)), 'passflow-report-map');
}

// --- Ventes sur place ---------------------------------------------------------------------
if ($r['cashdesk']) {
    $s = $r['sales_summary'];
    $sales = '<div class="passflow-kpis passflow-report-kpis">'
        . $kpi('dashicons-money-alt', $money($s['paid']), __('Encaissé', $d), sprintf(_n('%d vente', '%d ventes', $s['count'], $d), $s['count']))
        . ($s['pending'] > 0 ? $kpi('dashicons-hourglass', $money($s['pending']), __('En attente de paiement', $d)) : '')
        . ($s['vouchers'] > 0 ? $kpi('dashicons-tickets-alt', $money($s['vouchers']), __("Bons d'achat émis", $d), __('Retours produit', $d)) : '')
        . '</div>';

    if ($s['methods']) {
        $rows = '';

        foreach ($s['methods'] as $label => $amount) {
            $rows .= '<tr><td>' . esc_html($label) . '</td><td class="passflow-num">' . $money($amount) . '</td></tr>';
        }

        $sales .= '<div class="passflow-report-cols"><div><h3 class="passflow-bc-h3">' . esc_html__('Par moyen de paiement', $d) . '</h3>'
            . '<table class="widefat striped passflow-table"><tbody>' . $rows . '</tbody></table></div>';

        $rows = '';

        foreach ($s['products'] as $name => $row) {
            $rows .= '<tr><td>' . esc_html($name) . (!empty($row['sku']) ? ' <span class="passflow-muted">' . esc_html($row['sku']) . '</span>' : '') . '</td><td class="passflow-num">' . (int) $row['qty'] . '</td><td class="passflow-num">' . $money($row['total']) . '</td></tr>';
        }

        $sales .= '<div><h3 class="passflow-bc-h3">' . esc_html__('Par produit', $d) . '</h3>'
            . '<table class="widefat striped passflow-table"><thead><tr><th>' . esc_html__('Produit', $d) . '</th><th class="passflow-num">' . esc_html__('Qté', $d) . '</th><th class="passflow-num">' . esc_html__('Montant', $d) . '</th></tr></thead><tbody>' . $rows . '</tbody></table></div></div>';
    }

    $rows = '';

    foreach ($r['sales'] as $sale) {
        $rows .= '<tr><td><a href="' . esc_url($sale['url']) . '">#' . esc_html($sale['number']) . '</a><br><span class="passflow-muted">' . View::datetime($sale['date']) . '</span></td>'
            . '<td>' . esc_html($sale['customer']) . '</td>'
            . '<td>' . esc_html(implode(', ', $sale['lines'])) . '</td>'
            . '<td class="passflow-num">' . $money($sale['total']) . '</td>'
            . '<td>' . esc_html($sale['method']) . '<br><span class="passflow-muted">' . esc_html($sale['status']) . '</span></td></tr>';
    }

    $sales .= View::fold(
        sprintf(_n('Détail de %d commande', 'Détail des %d commandes', count($r['sales']), $d), count($r['sales'])),
        '<table class="widefat striped passflow-table"><thead><tr><th>' . esc_html__('Commande', $d) . '</th><th>' . esc_html__('Client', $d) . '</th><th>' . esc_html__('Détail', $d) . '</th><th class="passflow-num">' . esc_html__('Montant', $d) . '</th><th>' . esc_html__('Paiement', $d) . '</th></tr></thead><tbody>' . $rows . '</tbody></table>',
        false,
        'dashicons-list-view'
    );

    $html .= View::card(__('Ventes sur place', $d), $sales);
}

// --- Documents ----------------------------------------------------------------------------
if ($r['docs']) {
    $rows = '';

    foreach ($r['docs'] as $doc) {
        $total = max(1, $doc['ok'] + $doc['no'] + $doc['todo']);
        $rows .= '<tr><td><strong>' . esc_html($doc['title']) . '</strong>' . ($doc['required'] ? ' <span class="passflow-muted">' . esc_html__('(obligatoire)', $d) . '</span>' : '')
            . '<span class="passflow-bc-progress" aria-hidden="true"><span style="width:' . esc_attr((string) round($doc['ok'] * 100 / $total)) . '%"></span></span></td>'
            . '<td class="passflow-num">' . (int) $doc['ok'] . '</td>'
            . '<td class="passflow-num">' . (int) $doc['no'] . '</td>'
            . '<td class="passflow-num">' . ($doc['todo'] ? '<strong class="passflow-error">' . (int) $doc['todo'] . '</strong>' : '0') . '</td></tr>';
    }

    $docs = '<table class="widefat striped passflow-table"><thead><tr><th>' . esc_html__('Document', $d) . '</th><th class="passflow-num">' . esc_html__('Faits', $d) . '</th><th class="passflow-num">' . esc_html__('Refusés', $d) . '</th><th class="passflow-num">' . esc_html__('Manquants', $d) . '</th></tr></thead><tbody>' . $rows . '</tbody></table>';

    if ($r['missing']) {
        $rows = '';

        foreach ($r['missing'] as $m) {
            $b = $m['booking'];
            $rows .= '<tr><td><a href="' . esc_url(View::url('passflow_booking', array('view' => 'details', 'id' => (int) $b['id']))) . '"><strong>' . esc_html(Lookup::personName($b)) . '</strong></a></td>'
                . '<td>' . Choices::bookingBadge($b) . '</td>'
                . '<td>' . esc_html(implode(', ', $m['titles'])) . '</td></tr>';
        }

        $docs .= View::fold(
            sprintf(_n('%d participant à régulariser', '%d participants à régulariser', count($r['missing']), $d), count($r['missing'])),
            '<table class="widefat striped passflow-table"><tbody>' . $rows . '</tbody></table>'
            . '<p class="description no-print">' . esc_html__('Les absents ne sont pas listés. Depuis la fiche d\'un participant, « Envoyer la demande de documents » lui renvoie le lien.', $d) . '</p>',
            true,
            'dashicons-media-document'
        );
    } else {
        $docs .= '<p class="passflow-report-ok"><span class="dashicons dashicons-yes-alt" aria-hidden="true"></span> ' . esc_html__('Tous les documents des participants sont en règle.', $d) . '</p>';
    }

    $html .= View::card(__('Documents', $d), $docs);
}

// --- Avis des participants (questionnaire de satisfaction) -----------------------------------
$rv = $r['reviews'];

if ($rv['ready'] || $rv['answered']) {
    $stars = function ($value) {
        $full = (int) round((float) $value);

        return '<span class="passflow-stars" aria-hidden="true">' . str_repeat('★', $full) . '<span>' . str_repeat('★', max(0, 5 - $full)) . '</span></span>';
    };
    $avis = '<div class="passflow-kpis passflow-report-kpis">'
        . $kpi('dashicons-star-filled', $rv['average'] !== null ? esc_html(number_format_i18n((float) $rv['average'], 1)) . '<small> / 5</small>' : '–', __('Note moyenne', $d))
        . $kpi('dashicons-format-chat', (string) $rv['answered'], __('Réponses', $d), $rv['asked'] ? sprintf(_n('sur %d demande envoyée', 'sur %d demandes envoyées', $rv['asked'], $d), $rv['asked']) : '')
        . ($rv['asked'] ? $kpi('dashicons-chart-pie', $rv['rate'] . ' %', __('Taux de réponse', $d)) : '')
        . '</div>';

    // Où en est l'envoi.
    $send_btn = $rv['ready'] && $rv['gdpr'] && $slot['status'] !== 'cancelled'
        ? ' <a class="button button-small no-print" href="' . esc_url(View::actionUrl(Actions::REVIEW_SEND, array('id' => $id))) . '" onclick="return confirm(' . esc_attr(wp_json_encode(__("Envoyer maintenant la demande d'avis aux participants consentants qui ne l'ont pas encore reçue ?", $d))) . ');">'
            . esc_html($rv['sent_at'] !== '' ? __('Relancer les nouveaux consentants', $d) : __('Envoyer maintenant', $d)) . '</a>'
        : '';

    if ($rv['sent_at'] !== '') {
        $state = sprintf(__("Demande d'avis traitée le %s (participants consentants seulement).", $d), View::datetime($rv['sent_at']));
    } elseif (!$rv['gdpr']) {
        $state = __("Aucun document ne porte le rôle « Consentement RGPD » : la demande d'avis ne peut viser personne.", $d);
    } elseif ($rv['enabled'] && $rv['due']) {
        $state = sprintf(__("Envoi automatique prévu vers le %s, aux participants consentants.", $d), wp_date('j F Y à H\hi', $rv['due']));
    } else {
        $state = __("Envoi automatique désactivé (Réglages › Avis).", $d);
    }

    $avis .= '<p class="passflow-muted">' . esc_html($state) . $send_btn . '</p>';

    foreach ($rv['fields'] as $field) {
        if (!$field['count']) {
            continue;
        }

        $block = '<h3 class="passflow-bc-h3">' . esc_html($field['label']) . '</h3>';

        if ($field['dist'] && in_array($field['type'], array('rating', 'radio', 'select', 'checkbox', 'number'), true)) {
            $dist = $field['dist'];

            if ($field['type'] === 'rating') {
                $dist = array();

                for ($i = 5; $i >= 1; $i--) {
                    $dist[$i] = isset($field['dist'][(string) $i]) ? $field['dist'][(string) $i] : 0;
                }
            } else {
                arsort($dist);
            }

            $rows = '';

            foreach ($dist as $choice => $n) {
                $rows .= '<tr><td>' . ($field['type'] === 'rating' ? $stars($choice) : esc_html((string) $choice)) . '</td>'
                    . '<td class="passflow-review-bar"><span class="passflow-bc-progress" aria-hidden="true"><span style="width:' . esc_attr((string) round($n * 100 / max(1, $field['count']))) . '%"></span></span></td>'
                    . '<td class="passflow-num">' . (int) $n . '</td></tr>';
            }

            $block .= ($field['avg'] !== null ? '<p>' . $stars($field['avg']) . ' <strong>' . esc_html(number_format_i18n((float) $field['avg'], 1)) . '</strong> / 5 · ' . esc_html(sprintf(_n('%d réponse', '%d réponses', $field['count'], $d), $field['count'])) . '</p>' : '')
                . '<table class="widefat striped passflow-table passflow-review-dist"><tbody>' . $rows . '</tbody></table>';
        } else {
            $items = '';

            foreach ($field['texts'] as $t) {
                $who = $t['booking'] ? '<a href="' . esc_url(View::url('passflow_booking', array('view' => 'details', 'id' => (int) $t['booking']['id']))) . '">' . esc_html(Lookup::personName($t['booking'])) . '</a>' : '';
                $items .= '<li><q>' . esc_html($t['text']) . '</q>' . ($who !== '' ? ' <span class="passflow-muted">· ' . $who . '</span>' : '') . '</li>';
            }

            $block .= '<ul class="passflow-review-quotes">' . $items . '</ul>';
        }

        $avis .= '<div class="passflow-review-field">' . $block . '</div>';
    }

    if (!$rv['answered']) {
        $avis .= '<p class="passflow-muted">' . esc_html__('Pas encore de réponse.', $d) . '</p>';
    }

    $html .= View::card(__('Avis des participants', $d), $avis);
}

// --- Messages -----------------------------------------------------------------------------
$t = $r['message_totals'];
$msg = '<div class="passflow-kpis passflow-report-kpis">'
    . $kpi('dashicons-smartphone', (string) $t['sms'], __('SMS envoyés', $d))
    . $kpi('dashicons-email-alt', (string) $t['email'], __('E-mails envoyés', $d))
    . ($t['failed'] ? $kpi('dashicons-warning', (string) $t['failed'], __('Échecs', $d)) : '')
    . ($r['broadcasts'] ? $kpi('dashicons-megaphone', (string) $r['broadcasts'], __('Communications', $d), '', View::url('passflow_slot', array('view' => 'communication', 'id' => $id))) : '')
    . '</div>';

if ($r['messages']) {
    $rows = '';

    foreach ($r['messages'] as $m) {
        $rows .= '<tr><td>' . Choices::badge('channels', $m['channel']) . ' ' . esc_html($m['event']) . '</td>'
            . '<td class="passflow-num">' . (int) $m['sent'] . '</td>'
            . '<td class="passflow-num">' . ($m['failed'] ? '<span class="passflow-error">' . (int) $m['failed'] . '</span>' : '0') . '</td>'
            . '<td class="passflow-num">' . (int) $m['skipped'] . '</td></tr>';
    }

    $msg .= '<table class="widefat striped passflow-table"><thead><tr><th>' . esc_html__('Message', $d) . '</th><th class="passflow-num">' . esc_html__('Envoyés', $d) . '</th><th class="passflow-num">' . esc_html__('Échecs', $d) . '</th><th class="passflow-num">' . esc_html__('Non envoyés', $d) . '</th></tr></thead><tbody>' . $rows . '</tbody></table>';
} else {
    $msg .= '<p class="passflow-muted">' . esc_html__('Aucun message envoyé pour ce créneau.', $d) . '</p>';
}

$html .= View::card(__('Messages', $d), $msg);

// --- Notes des intervenants ---------------------------------------------------------------
$notes = '';

foreach ($r['seats'] as $b) {
    if (!empty($b['host_note'])) {
        $notes .= '<li><strong>' . esc_html(Lookup::personName($b)) . '</strong> : ' . esc_html($b['host_note']) . '</li>';
    }
}

if ($notes !== '') {
    $html .= View::card(__("Notes de l'intervenant", $d), '<ul class="passflow-report-notes">' . $notes . '</ul>');
}

// Impression : tout déplier (les blocs repliés ne s'impriment pas sinon).
$html .= '<script>window.addEventListener("beforeprint",function(){document.querySelectorAll(".passflow-report details:not([open])").forEach(function(el){el.setAttribute("open","");el.setAttribute("data-print-open","");});});'
    . 'window.addEventListener("afterprint",function(){document.querySelectorAll(".passflow-report details[data-print-open]").forEach(function(el){el.removeAttribute("open");el.removeAttribute("data-print-open");});});</script>';

View::page('<div class="passflow-report">' . $html . '</div>', $header, $notices, __('Bilan', $d));
