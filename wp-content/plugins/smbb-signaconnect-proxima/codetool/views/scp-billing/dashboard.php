<?php
/**
 * Facturation : vue d'ensemble du mois, puis proforma détaillée d'une organisation.
 */

use Smbb\SignConnectProxima\Admin\BillingActions;
use Smbb\SignConnectProxima\Admin\View;
use Smbb\SignConnectProxima\Repository\Lookup;
use Smbb\SignConnectProxima\Service\Billing;
use Smbb\SignConnectProxima\Support\Levels;

defined('ABSPATH') || exit;

global $wpdb;

$d = 'smbb-signaconnect-proxima';
$header = isset($page_header_html) ? $page_header_html : '';
$notices = (isset($notices_html) ? $notices_html : '') . BillingActions::notice();
$table = Lookup::table('scp_billing_month');

if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) {
    View::page('<div class="scp-card"><p>' . esc_html__("La table des mois de facturation n'existe pas encore. Synchronisez le schéma depuis CodeTool (menu CodeTool > Ressources).", $d) . '</p></div>', $header, $notices, __('Facturation', $d));
    return;
}

$current = Billing::currentMonth();
$month = isset($_GET['month']) ? sanitize_text_field(wp_unslash($_GET['month'])) : $current;
$month = Billing::validMonth($month) ? $month : $current;
$organization_id = isset($_GET['organization_id']) ? absint($_GET['organization_id']) : 0;
$organizations = Lookup::organizations();
$organization_id = isset($organizations[$organization_id]) ? $organization_id : 0;

$money = function ($value) {
    return esc_html(Billing::money($value));
};
$status_badge = function ($status) use ($d) {
    return $status === Billing::STATUS_CLOSED
        ? '<span class="scp-badge scp-badge--signed">' . esc_html__('Clôturé', $d) . '</span>'
        : '<span class="scp-badge scp-badge--opened">' . esc_html__('Ouvert', $d) . '</span>';
};

// Sélecteurs : organisation et mois (du premier mois facturable au mois en cours).
$month_options = '';
$cursor = $current;
$first = Billing::firstMonth($organization_id);

if ($month < $first) {
    $first = $month;
}

while ($cursor >= $first) {
    $month_options .= '<option value="' . esc_attr($cursor) . '"' . selected($cursor, $month, false) . '>' . esc_html(ucfirst(Billing::monthLabel($cursor))) . ($cursor === $current ? ' ' . esc_html__('(en cours)', $d) : '') . '</option>';
    $cursor = Billing::previousMonth($cursor);
}

$org_options = '<option value="0">' . esc_html__('Toutes les organisations', $d) . '</option>';

foreach ($organizations as $id => $org) {
    $org_options .= '<option value="' . (int) $id . '"' . selected($id, $organization_id, false) . '>' . esc_html($org['name']) . '</option>';
}

$html = '<form class="scp-billing-filters" method="get" action="' . esc_url(admin_url('admin.php')) . '">'
    . '<input type="hidden" name="page" value="' . esc_attr(BillingActions::PAGE) . '">'
    . '<select name="organization_id" onchange="this.form.submit()">' . $org_options . '</select>'
    . '<select name="month" onchange="this.form.submit()">' . $month_options . '</select>'
    . '<noscript><button class="button">' . esc_html__('Afficher', $d) . '</button></noscript>'
    . '</form>';

// ---------------------------------------------------------------- Vue d'ensemble
if ($organization_id === 0) {
    $rows = '';
    $grand_total = 0.0;
    $closed_count = 0;

    foreach ($organizations as $id => $org) {
        try {
            $invoice = Billing::get($id, $month);
        } catch (\Throwable $exception) {
            $rows .= '<tr><td>' . esc_html($org['name']) . '</td><td colspan="5"><div class="scp-alert">' . esc_html($exception->getMessage()) . '</div></td></tr>';
            continue;
        }

        $grand_total += $invoice['total'];
        $closed_count += $invoice['status'] === Billing::STATUS_CLOSED ? 1 : 0;
        $rows .= '<tr>'
            . '<td><a href="' . esc_url(BillingActions::pageUrl(array('organization_id' => $id, 'month' => $month))) . '"><strong>' . esc_html($org['name']) . '</strong></a></td>'
            . '<td>' . esc_html(number_format_i18n($invoice['user_count'])) . '</td>'
            . '<td>' . esc_html(number_format_i18n($invoice['document_count'])) . '</td>'
            . '<td class="scp-num"><strong>' . $money($invoice['total']) . '</strong></td>'
            . '<td>' . $status_badge($invoice['status']) . '</td>'
            . '<td class="scp-num"><a class="button button-small" href="' . esc_url(BillingActions::url(BillingActions::CSV, $id, $month)) . '">CSV</a></td>'
            . '</tr>';
    }

    $html .= '<div class="scp-kpis">'
        . '<div class="scp-kpi"><div class="scp-kpi-value">' . $money($grand_total) . '</div><div class="scp-kpi-label">' . esc_html(sprintf(__('Total HT, %s', $d), Billing::monthLabel($month))) . '</div></div>'
        . '<div class="scp-kpi"><div class="scp-kpi-value">' . esc_html($closed_count . ' / ' . count($organizations)) . '</div><div class="scp-kpi-label">' . esc_html__('Organisations clôturées', $d) . '</div></div>'
        . '</div>';

    $html .= '<div class="scp-card"><h2>' . esc_html(sprintf(__('Proformas de %s', $d), Billing::monthLabel($month))) . '</h2>';
    $html .= $rows === ''
        ? '<p class="scp-muted">' . esc_html__('Aucune organisation.', $d) . '</p>'
        : '<table class="scp-plain"><thead><tr><th>' . esc_html__('Organisation', $d) . '</th><th>' . esc_html__('Utilisateurs facturés', $d) . '</th><th>' . esc_html__('Signatures', $d) . '</th><th class="scp-num">' . esc_html__('Total HT', $d) . '</th><th>' . esc_html__('Statut', $d) . '</th><th></th></tr></thead><tbody>' . $rows . '</tbody></table>';
    $html .= '</div>';

    View::page($html, $header, $notices, __('Facturation', $d));
    return;
}

// ---------------------------------------------------------------- Proforma d'une organisation
try {
    $invoice = Billing::get($organization_id, $month);
} catch (\Throwable $exception) {
    View::page($html . '<div class="scp-card"><div class="scp-alert">' . esc_html($exception->getMessage()) . '</div></div>', $header, $notices, __('Facturation', $d));
    return;
}

$closed = $invoice['status'] === Billing::STATUS_CLOSED;
$org = $invoice['organization'];

// En-tête : identité de facturation, statut et actions.
$identity = array_filter(array(
    $org['billing_company_name'] !== '' ? '<strong>' . esc_html($org['billing_company_name']) . '</strong>' : '<strong>' . esc_html($org['name']) . '</strong>',
    $org['billing_address'] !== '' ? nl2br(esc_html($org['billing_address'])) : '',
    $org['billing_siret'] !== '' ? esc_html('SIRET ' . $org['billing_siret']) : '',
    $org['billing_vat_number'] !== '' ? esc_html(__('TVA', $d) . ' ' . $org['billing_vat_number']) : '',
    $org['billing_email'] !== '' ? esc_html($org['billing_email']) : '',
    $org['customer_reference'] !== '' ? esc_html(__('Réf. client', $d) . ' ' . $org['customer_reference']) : '',
));

$actions = '<a class="button" href="' . esc_url(BillingActions::url(BillingActions::CSV, $organization_id, $month)) . '">' . esc_html__('Exporter en CSV', $d) . '</a>';

if ($closed) {
    $actions .= ' <a class="button" href="' . esc_url(BillingActions::url(BillingActions::REOPEN, $organization_id, $month)) . '" onclick="return confirm(' . esc_attr(wp_json_encode(__('Rouvrir ce mois ? Les montants seront recalculés avec les forfaits et remises actuels.', $d))) . ')">' . esc_html__('Rouvrir', $d) . '</a>';
} elseif ($month < Billing::currentMonth()) {
    $actions .= ' <a class="button button-primary" href="' . esc_url(BillingActions::url(BillingActions::CLOSE, $organization_id, $month)) . '" onclick="return confirm(' . esc_attr(wp_json_encode(__('Clôturer ce mois ? Les prix et quantités seront figés.', $d))) . ')">' . esc_html__('Clôturer le mois', $d) . '</a>';
}

$state = $closed
    ? sprintf(__('Clôturé le %s : prix et quantités figés.', $d), View::datetime($invoice['closed_at']))
    : ($month < Billing::currentMonth()
        ? esc_html__('Ouvert : recalculé en direct jusqu\'à la clôture.', $d)
        : esc_html__('Mois en cours : recalculé en direct, clôture possible à partir du mois prochain.', $d));

$html .= '<div class="scp-card scp-proforma-head">'
    . '<div class="scp-proforma-who">' . implode('<br>', $identity) . '</div>'
    . '<div class="scp-proforma-meta">'
    . '<div class="scp-proforma-title">' . esc_html(sprintf(__('Proforma %s', $d), ucfirst(Billing::monthLabel($month)))) . ' ' . $status_badge($invoice['status']) . '</div>'
    . '<div class="scp-proforma-total">' . $money($invoice['total']) . ' <span>' . esc_html__('HT', $d) . '</span></div>'
    . '<p class="scp-muted">' . $state . '</p>'
    . '<div class="scp-proforma-actions">' . $actions . '</div>'
    . '</div></div>';

if (!empty($invoice['late_documents'])) {
    $html .= '<div class="scp-card"><div class="scp-alert">' . esc_html(sprintf(
        _n('%d signature de ce mois a abouti après la clôture : elle n\'est pas dans la proforma figée. Rouvrez puis clôturez à nouveau pour l\'inclure.', '%d signatures de ce mois ont abouti après la clôture : elles ne sont pas dans la proforma figée. Rouvrez puis clôturez à nouveau pour les inclure.', (int) $invoice['late_documents'], $d),
        (int) $invoice['late_documents']
    )) . '</div></div>';
}

if (!empty($invoice['warnings'])) {
    $html .= '<div class="scp-card">';

    foreach ($invoice['warnings'] as $warning) {
        $html .= '<div class="scp-alert">' . esc_html($warning) . '</div>';
    }

    $html .= '</div>';
}

// Lignes de la proforma, par chapitre : abonnement d'organisation, abonnements d'équipe, forfaits utilisateurs.
$chapters = array(
    'org' => array('title' => __('Abonnement organisation', $d), 'lines' => array(), 'total' => 0.0),
    'team' => array('title' => __('Abonnements équipes', $d), 'lines' => array(), 'total' => 0.0),
    'users' => array('title' => __('Forfaits utilisateurs', $d), 'lines' => array(), 'total' => 0.0),
);

foreach ($invoice['lines'] as $line) {
    $chapter = $line['kind'] === 'org_fee' ? 'org' : ($line['kind'] === 'team_fee' ? 'team' : 'users');
    $chapters[$chapter]['lines'][] = $line;
    $chapters[$chapter]['total'] += $line['amount'];
}

$row = function ($line) use ($d, $money) {
    return '<tr class="' . esc_attr('scp-proforma-line scp-proforma-line--' . $line['kind']) . '">'
        . '<td>' . esc_html($line['label']) . '</td>'
        . '<td>' . esc_html($line['detail']) . '</td>'
        . '<td>' . esc_html($line['team']) . '</td>'
        . '<td>' . ($line['level'] !== '' ? esc_html(Levels::label($line['level'])) : '') . '</td>'
        . '<td class="scp-num">' . ($line['kind'] === 'usage' ? '<span class="scp-muted">' . esc_html__('inclus', $d) . '</span>' : $money($line['amount'])) . '</td>'
        . '</tr>';
};

$body = '';

foreach ($chapters as $key => $chapter) {
    if (!$chapter['lines']) {
        continue;
    }

    $body .= '<tr class="scp-proforma-chapter"><td colspan="4">' . esc_html($chapter['title']) . '</td><td class="scp-num">' . $money($chapter['total']) . '</td></tr>';
    $previous_user = null;

    foreach ($chapter['lines'] as $line) {
        if ($key === 'users' && (string) $line['user_id'] !== $previous_user) {
            $user_total = 0.0;

            foreach ($chapter['lines'] as $other) {
                $user_total += (string) $other['user_id'] === (string) $line['user_id'] ? $other['amount'] : 0;
            }

            $body .= '<tr class="scp-proforma-group"><td colspan="4"><strong>' . esc_html($line['user']) . '</strong> <span class="scp-muted">' . esc_html($line['email']) . '</span>'
                . ($line['plan'] !== '' ? ' <span class="scp-badge scp-badge--draft">' . esc_html($line['plan']) . '</span>' : '')
                . '</td><td class="scp-num scp-muted">' . $money($user_total) . '</td></tr>';
            $previous_user = (string) $line['user_id'];
        }

        $body .= $row($line);
    }
}

$body .= '<tr class="scp-proforma-total-row"><td colspan="4"><strong>' . esc_html__('Total HT', $d) . '</strong></td><td class="scp-num"><strong>' . $money($invoice['total']) . '</strong></td></tr>';

$html .= '<div class="scp-card"><h2>' . esc_html__('Détail', $d) . '</h2>'
    . '<table class="scp-plain scp-proforma"><thead><tr><th>' . esc_html__('Ligne', $d) . '</th><th>' . esc_html__('Détail', $d) . '</th><th>' . esc_html__('Équipe', $d) . '</th><th>' . esc_html__('Niveau', $d) . '</th><th class="scp-num">' . esc_html__('Montant HT', $d) . '</th></tr></thead>'
    . '<tbody>' . $body . '</tbody></table></div>';

// Consommation : jauges par utilisateur et par niveau, sans les prix.
$gauges = '';

foreach ($invoice['usage'] as $usage) {
    $cells = '';

    foreach (Levels::all() as $level) {
        $u = $usage['levels'][$level];

        if (!empty($u['unlimited'])) {
            $cells .= '<td><div class="scp-gauge-text">' . esc_html(sprintf(__('%d · illimité', $d), $u['consumed'])) . '</div></td>';
            continue;
        }

        if ($u['consumed'] === 0 && $u['included'] === 0) {
            $cells .= '<td class="scp-muted">—</td>';
            continue;
        }

        $ratio = $u['included'] > 0 ? min(100, round($u['consumed'] * 100 / $u['included'])) : 100;
        $over = $u['overage'] > 0;
        $cells .= '<td><div class="scp-gauge' . ($over ? ' is-over' : '') . '"><span style="width:' . (int) $ratio . '%"></span></div>'
            . '<div class="scp-gauge-text">' . esc_html(sprintf(__('%1$d sur %2$d incluses', $d), $u['consumed'], $u['included']))
            . ($over ? ' · <strong>' . esc_html(sprintf(_n('%d en dépassement', '%d en dépassement', $u['overage'], $d), $u['overage'])) . '</strong>' : '') . '</div></td>';
    }

    $gauges .= '<tr><td><strong>' . esc_html($usage['user']) . '</strong><div class="scp-muted">' . esc_html($usage['team']) . '</div></td>' . $cells . '</tr>';
}

if ($gauges !== '') {
    $level_heads = '';

    foreach (Levels::all() as $level) {
        $level_heads .= '<th>' . esc_html(Levels::label($level)) . '</th>';
    }

    $html .= '<div class="scp-card"><h2>' . esc_html__('Consommation', $d) . '</h2>'
        . '<table class="scp-plain scp-usage"><thead><tr><th>' . esc_html__('Utilisateur', $d) . '</th>' . $level_heads . '</tr></thead><tbody>' . $gauges . '</tbody></table></div>';
}

View::page($html, $header, $notices, __('Facturation', $d));
