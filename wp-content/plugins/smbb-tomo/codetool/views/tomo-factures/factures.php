<?php
/**
 * Factures et avoirs : liste du mois, export CSV et ZIP pour la comptabilité,
 * et l'écran « Établir des factures » d'une commande (?commande=ID).
 */

use Smbb\Tomo\Admin\View;
use Smbb\Tomo\WooCommerce\InvoiceScreen;
use Smbb\Tomo\WooCommerce\Invoices;
use Smbb\Tomo\WooCommerce\Module;
use Smbb\Tomo\WooCommerce\OnsiteSales;

defined('ABSPATH') || exit;

$d = 'smbb-tomo';
$header = isset($page_header_html) ? $page_header_html : '';
$notices = isset($notices_html) ? $notices_html : '';
wp_enqueue_style('smbb-tomo-factures', SMBB_TOMO_URL . 'assets/factures.css', array(), SMBB_TOMO_VERSION);

if (!Module::active() || !\Smbb\Tomo\Support\Schema::ready()) {
    View::page(View::note(__('WooCommerce requis', $d), esc_html__('Les factures portent sur les commandes WooCommerce : activez WooCommerce.', $d), 'dashicons-warning'), $header, $notices, __('Factures', $d));
    return;
}

// --- Une commande : documents émis et répartition ------------------------
$order_id = isset($_GET['commande']) ? absint($_GET['commande']) : 0;

if ($order_id) {
    $order = wc_get_order($order_id);

    if (!$order) {
        View::page(View::note(__('Commande introuvable', $d), '', 'dashicons-warning'), $header, $notices, __('Factures', $d));
        return;
    }

    $prefill = isset($_GET['prefill']) ? Invoices::find(absint($_GET['prefill'])) : null;
    $prefill = $prefill && (int) $prefill['order_id'] === $order_id ? $prefill : null;
    $docs = Invoices::forOrder($order_id);
    $edit = $order->get_edit_order_url();
    $buyer = trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name());

    $html = '<p><a href="' . esc_url(View::url('tomo_factures')) . '">← ' . esc_html__('Toutes les factures', $d) . '</a> · '
        . '<a href="' . esc_url($edit) . '">' . esc_html(sprintf(__('Commande n° %s', $d), $order->get_order_number())) . '</a> · '
        . esc_html($buyer) . ' · ' . esc_html(OnsiteSales::total($order)) . '</p>';

    if ($docs) {
        $html .= View::card(__('Déjà émis', $d), InvoiceScreen::table($docs, 'admin'));
    }

    if ($prefill) {
        $html .= View::note(__('Correction', $d), esc_html(sprintf(__("L'avoir sur la facture %s est émis. Ses lignes sont cochées : « Générer la facture », corrigez les coordonnées, puis émettez la nouvelle.", $d), $prefill['number'])), 'dashicons-edit');
    }

    $html .= View::card(__('Établir des factures', $d), Invoices::billable($order)
        ? InvoiceScreen::screen($order, 'admin', $prefill)
        : '<p class="tomo-muted">' . esc_html__("Cette commande n'est pas payée : pas de facture.", $d) . '</p>');

    View::page($html, $header, $notices, sprintf(__('Factures de la commande n° %s', $d), $order->get_order_number()));
    return;
}

// --- Liste du mois --------------------------------------------------------
$month = isset($_GET['mois']) && preg_match('/^\d{4}-\d{2}$/', sanitize_text_field(wp_unslash($_GET['mois']))) ? sanitize_text_field(wp_unslash($_GET['mois'])) : current_time('Y-m');
$kind = isset($_GET['type']) ? sanitize_key(wp_unslash($_GET['type'])) : '';
$source = isset($_GET['origine']) ? sanitize_key(wp_unslash($_GET['origine'])) : '';
$search = isset($_GET['q']) ? sanitize_text_field(wp_unslash($_GET['q'])) : '';
$docs = InvoiceScreen::month($month, $kind, $source, $search);

$months = '';

for ($i = 0; $i < 24; $i++) {
    $value = date('Y-m', strtotime(current_time('Y-m') . '-01 -' . $i . ' month'));
    $months .= '<option value="' . esc_attr($value) . '"' . selected($month, $value, false) . '>' . esc_html(ucfirst(date_i18n('F Y', strtotime($value . '-01')))) . '</option>';
}

$tools = '<form method="get" class="tomo-inv-filters">'
    . '<input type="hidden" name="page" value="smbb-codetool-tomo_factures">'
    . '<select name="mois" onchange="this.form.submit()">' . $months . '</select> '
    . '<select name="type" onchange="this.form.submit()"><option value="">' . esc_html__('Factures et avoirs', $d) . '</option>'
    . '<option value="invoice"' . selected($kind, 'invoice', false) . '>' . esc_html__('Factures', $d) . '</option>'
    . '<option value="credit"' . selected($kind, 'credit', false) . '>' . esc_html__('Avoirs', $d) . '</option></select> '
    . '<select name="origine" onchange="this.form.submit()"><option value="">' . esc_html__('En ligne et sur place', $d) . '</option>'
    . '<option value="online"' . selected($source, 'online', false) . '>' . esc_html__('En ligne', $d) . '</option>'
    . '<option value="onsite"' . selected($source, 'onsite', false) . '>' . esc_html__('Sur place', $d) . '</option></select> '
    . '<input type="search" name="q" value="' . esc_attr($search) . '" placeholder="' . esc_attr__('Numéro, nom ou e-mail', $d) . '"> '
    . '<button class="button">' . esc_html__('Filtrer', $d) . '</button></form>'
    . '<p class="tomo-switch">'
    . '<a class="button" href="' . esc_url(View::actionUrl(InvoiceScreen::CSV, array('id' => 0, 'mois' => $month))) . '"><span class="dashicons dashicons-media-spreadsheet" aria-hidden="true"></span> ' . esc_html__('Journal des ventes (CSV)', $d) . '</a> '
    . '<a class="button" href="' . esc_url(View::actionUrl(InvoiceScreen::ZIP, array('id' => 0, 'mois' => $month))) . '"><span class="dashicons dashicons-media-archive" aria-hidden="true"></span> ' . esc_html__('PDF du mois (ZIP)', $d) . '</a> '
    . '<a class="button" href="' . esc_url(View::url('tomo_settings', array('tab' => 'factures'))) . '"><span class="dashicons dashicons-admin-generic" aria-hidden="true"></span> ' . esc_html__('Réglages des factures', $d) . '</a></p>';

$total = 0.0;
$body = '';

foreach ($docs as $doc) {
    $credit = $doc['kind'] === 'credit';
    $total += ($credit ? -1 : 1) * (float) $doc['total_ttc'];
    $order = wc_get_order($doc['order_id']);
    $body .= '<tr><td><strong>' . esc_html($doc['number']) . '</strong><br><span class="tomo-muted">' . esc_html(Invoices::kindLabel($doc['kind'])) . '</span></td>'
        . '<td>' . esc_html(mysql2date('d/m/Y', $doc['issued_at'])) . '</td>'
        . '<td>' . esc_html($doc['name']) . '<br><span class="tomo-muted">' . esc_html($doc['email']) . '</span></td>'
        . '<td><a href="' . esc_url(InvoiceScreen::adminUrl($doc['order_id'])) . '">' . esc_html($order ? $order->get_order_number() : (string) $doc['order_id']) . '</a><br><span class="tomo-muted">' . esc_html($doc['source'] === 'onsite' ? __('Sur place', $d) : __('En ligne', $d)) . '</span></td>'
        . '<td class="num">' . esc_html(($credit ? '− ' : '') . OnsiteSales::money($doc['total_ttc'])) . '</td>'
        . '<td class="tomo-actions"><a class="button button-small" target="_blank" href="' . esc_url(View::actionUrl(InvoiceScreen::PDF, array('id' => (int) $doc['id']))) . '">' . esc_html__('PDF', $d) . '</a> '
        . '<a class="button button-small" href="' . esc_url(View::actionUrl(InvoiceScreen::RESEND, array('id' => (int) $doc['id']))) . '">' . esc_html__('Renvoyer', $d) . '</a></td></tr>';
}

$list = $docs
    ? '<table class="widefat striped tomo-table tomo-inv-list"><thead><tr><th>' . esc_html__('Numéro', $d) . '</th><th>' . esc_html__('Date', $d) . '</th><th>' . esc_html__('Au nom de', $d) . '</th><th>' . esc_html__('Commande', $d) . '</th><th class="num">' . esc_html__('TTC', $d) . '</th><th></th></tr></thead>'
        . '<tbody>' . $body . '</tbody><tfoot><tr><th colspan="4">' . esc_html(sprintf(_n('%d document', '%d documents', count($docs), $d), count($docs))) . '</th><th class="num">' . esc_html(OnsiteSales::money($total)) . '</th><th></th></tr></tfoot></table>'
    : '<p class="tomo-muted">' . esc_html__('Aucune facture ni aucun avoir pour cette sélection.', $d) . '</p>';

$html = View::card('', $tools . $list . '<p class="description">' . esc_html__("Une facture ne se supprime pas : seul un avoir l'annule. Pour facturer une commande, ouvrez-la dans WooCommerce (encadré « Factures ») ou depuis la caisse.", $d) . '</p>');

View::page($html, $header, $notices, __('Factures', $d));
