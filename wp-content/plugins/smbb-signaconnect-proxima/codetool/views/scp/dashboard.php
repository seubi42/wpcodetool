<?php
/**
 * Tableau de bord SignaConnect : chiffres clés et points d'attention par organisation.
 */

use Smbb\SignConnectProxima\Admin\View;
use Smbb\SignConnectProxima\Repository\Lookup;
use Smbb\SignConnectProxima\Support\Choices;
use Smbb\SignConnectProxima\Support\FrontPages;
use Smbb\SignConnectProxima\Support\Levels;

defined('ABSPATH') || exit;

global $wpdb;

$d = 'smbb-signaconnect-proxima';
$url = function ($resource, $args = array()) {
    return esc_url(add_query_arg($args, admin_url('admin.php?page=smbb-codetool-' . $resource)));
};
$month = current_time('Y-m');
$documents_table = Lookup::table('scp_document');
$tables_ready = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $documents_table)) === $documents_table;

if (!$tables_ready) {
    View::page(
        '<div class="scp-card"><p>' . esc_html__("Les tables SignaConnect n'existent pas encore. Synchronisez le schéma depuis CodeTool (menu CodeTool > Ressources).", $d) . '</p></div>',
        isset($page_header_html) ? $page_header_html : '',
        isset($notices_html) ? $notices_html : '',
        'SignaConnect'
    );
    return;
}

$in_progress = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$documents_table} WHERE deleted_flag = 0 AND status IN ('sent', 'opened')");
$signed_month = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$documents_table} WHERE deleted_flag = 0 AND billable = 1 AND billing_month = %s", $month));

$active_users = Lookup::count('scp_membership', 'is_active', 1);
$organization_count = count(Lookup::organizations());
$by_level = array();

foreach ((array) $wpdb->get_results($wpdb->prepare("SELECT signature_level, COUNT(*) AS total FROM {$documents_table} WHERE deleted_flag = 0 AND billable = 1 AND billing_month = %s GROUP BY signature_level", $month), ARRAY_A) as $row) {
    $level = Levels::normalize($row['signature_level']);
    $by_level[$level] = (isset($by_level[$level]) ? $by_level[$level] : 0) + (int) $row['total'];
}

$app = FrontPages::status('app');
$app_url = $app['status'] === 'ok' && $app['page'] ? get_permalink($app['page']) : '';
$month_label = date_i18n('F Y', strtotime($month . '-01'));
$tiles = array(
    array($organization_count, _n('Organisation', 'Organisations', $organization_count, $d), 'dashicons-building', $url('scp_organization')),
    array($active_users, _n('Utilisateur actif', 'Utilisateurs actifs', $active_users, $d), 'dashicons-groups', $url('scp_membership')),
    array($in_progress, __('En attente de signature', $d), 'dashicons-clock', $url('scp_document')),
    array($signed_month, sprintf(__('Signées en %s', $d), $month_label), 'dashicons-yes-alt', $url('scp_document', array('billing_month' => $month))),
);
$levels_html = '';

foreach (Levels::all() as $level) {
    $levels_html .= '<span class="scp-hero-level scp-hero-level--' . esc_attr($level) . '"><i aria-hidden="true"></i>' . esc_html(Levels::label($level)) . ' <b>' . esc_html(number_format_i18n(isset($by_level[$level]) ? $by_level[$level] : 0)) . '</b></span>';
}

$html = '<section class="scp-hero">'
    . '<svg class="scp-hero-ink" viewBox="0 0 420 140" aria-hidden="true" focusable="false"><path pathLength="1" d="M8 96c18-44 34-70 46-66 14 5-6 62 6 64 14 2 26-56 42-54 14 2 2 50 16 50 16 0 22-40 38-40 12 0 6 30 18 30 18 0 30-52 52-52 18 0 10 40 26 42 20 2 40-30 62-30 18 0 24 18 44 18 22 0 40-12 54-20"/><path pathLength="1" d="M60 120c80-10 190-12 330-6"/></svg>'
    . '<div class="scp-hero-inner">'
    . '<div class="scp-hero-main">'
    . '<p class="scp-hero-eyebrow"><span class="scp-hero-mark" aria-hidden="true"><span class="dashicons dashicons-edit"></span></span>SignaConnect</p>'
    . '<h2 class="scp-hero-title">' . esc_html__('Faire signer, en toute confiance.', $d) . '</h2>'
    . '<p class="scp-hero-desc">' . esc_html__('Organisations, forfaits et facturation se pilotent ici. Trois niveaux de signature : interne, Vérifiée et Qualifiée eIDAS via Universign.', $d) . '</p>'
    . '<div class="scp-hero-actions">'
    . '<a class="scp-hero-button is-primary" href="' . $url('scp_organization', array('view' => 'form')) . '">' . esc_html__('Nouvelle organisation', $d) . '</a>'
    . ($app_url !== '' ? '<a class="scp-hero-button" href="' . esc_url($app_url) . '" target="_blank" rel="noopener">' . esc_html__("Ouvrir l'app", $d) . ' <span class="dashicons dashicons-external" aria-hidden="true"></span></a>' : '<a class="scp-hero-button" href="' . $url('scp_settings') . '">' . esc_html__('Réglages', $d) . '</a>')
    . '</div></div>'
    . '<div class="scp-hero-grid">';

foreach ($tiles as $tile) {
    $html .= '<a class="scp-hero-tile" href="' . $tile[3] . '"><span class="dashicons ' . esc_attr($tile[2]) . '" aria-hidden="true"></span>'
        . '<strong>' . esc_html(number_format_i18n((int) $tile[0])) . '</strong><small>' . esc_html($tile[1]) . '</small></a>';
}

$html .= '</div></div>'
    . '<div class="scp-hero-footer"><span class="scp-hero-levels"><span class="scp-hero-footer-label">' . esc_html(sprintf(__('Facturables en %s', $d), $month_label)) . '</span>' . $levels_html . '</span>'
    . '<a class="scp-hero-footer-link" href="' . $url('scp_billing') . '">' . esc_html__('Facturation', $d) . ' <span class="dashicons dashicons-arrow-right-alt2" aria-hidden="true"></span></a></div>'
    . '</section>';

// Pages front : chacune doit contenir son shortcode (correction en un clic dans les Réglages).
$page_problems = FrontPages::problems();

if ($page_problems) {
    $html .= '<div class="scp-card">';

    foreach ($page_problems as $problem) {
        $html .= '<div class="scp-alert">' . esc_html($problem) . '</div>';
    }

    $html .= '<p><a class="button" href="' . $url('scp_settings') . '">' . esc_html__('Corriger dans les Réglages', $d) . '</a></p></div>';
}

// Points d'attention : ce qui empêche une organisation de fonctionner.
$organizations = $wpdb->get_results('SELECT * FROM ' . Lookup::table('scp_organization') . ' WHERE deleted_flag = 0 ORDER BY name ASC', ARRAY_A);
$rows = '';

foreach (is_array($organizations) ? $organizations : array() as $org) {
    $alerts = array();
    $levels = (array) json_decode((string) $org['allowed_levels'], true);
    $uses_universign = (bool) array_intersect($levels, array('simple', 'qualified'));

    if ($uses_universign && (empty($org['universign_api_key']) || empty($org['universign_workspace_id']))) {
        $alerts[] = __('Universign autorisé mais clé API ou espace de travail manquant.', $d);
    }

    if (in_array('internal', $levels, true) && empty($org['s3_bucket'])) {
        $alerts[] = __('Niveau Simple autorisé mais aucun stockage S3.', $d);
    }

    if (empty($org['default_plan_id'])) {
        $alerts[] = __('Aucun forfait par défaut pour les nouveaux utilisateurs.', $d);
    }

    if (Lookup::count('scp_team', 'organization_id', $org['id']) === 0) {
        $alerts[] = __('Aucune équipe.', $d);
    }

    $alerts_html = '';

    foreach ($alerts as $alert) {
        $alerts_html .= '<div class="scp-alert">' . esc_html($alert) . '</div>';
    }

    $rows .= '<tr>'
        . '<td><a href="' . $url('scp_organization', array('view' => 'form', 'id' => (int) $org['id'])) . '"><strong>' . esc_html($org['name']) . '</strong></a></td>'
        . '<td>' . Choices::badge('organizationStatuses', $org['status']) . '</td>'
        . '<td>' . esc_html((string) Lookup::count('scp_membership', 'organization_id', $org['id'])) . '</td>'
        . '<td>' . ($alerts_html !== '' ? $alerts_html : '<span class="scp-muted">' . esc_html__('Rien à signaler', $d) . '</span>') . '</td>'
        . '</tr>';
}

$html .= '<div class="scp-card"><h2>' . esc_html__('Organisations', $d) . '</h2>';

if ($rows === '') {
    $html .= '<p>' . esc_html__('Aucune organisation pour le moment.', $d) . ' <a class="button button-primary" href="' . $url('scp_organization', array('view' => 'form')) . '">' . esc_html__('Créer une organisation', $d) . '</a></p>';
} else {
    $html .= '<table class="scp-plain"><thead><tr><th>' . esc_html__('Organisation', $d) . '</th><th>' . esc_html__('Statut', $d) . '</th><th>' . esc_html__('Utilisateurs', $d) . '</th><th>' . esc_html__("Points d'attention", $d) . '</th></tr></thead><tbody>' . $rows . '</tbody></table>';
}

$html .= '</div>';

$html .= '<div class="scp-card"><h2>' . esc_html__('Mise en route', $d) . '</h2><ol>'
    . '<li>' . sprintf(esc_html__('Créer le catalogue de %s (S, M, L…).', $d), '<a href="' . $url('scp_plan') . '">' . esc_html__('forfaits', $d) . '</a>') . '</li>'
    . '<li>' . sprintf(esc_html__('Créer une %s et la configurer (niveaux, Universign, S3, facturation).', $d), '<a href="' . $url('scp_organization') . '">' . esc_html__('organisation', $d) . '</a>') . '</li>'
    . '<li>' . sprintf(esc_html__('Créer ses %s.', $d), '<a href="' . $url('scp_team') . '">' . esc_html__('équipes', $d) . '</a>') . '</li>'
    . '<li>' . sprintf(esc_html__('Rattacher les %s WordPress.', $d), '<a href="' . $url('scp_membership') . '">' . esc_html__('utilisateurs', $d) . '</a>') . '</li>'
    . '</ol></div>';

View::page($html, isset($page_header_html) ? $page_header_html : '', isset($notices_html) ? $notices_html : '', 'SignaConnect');
