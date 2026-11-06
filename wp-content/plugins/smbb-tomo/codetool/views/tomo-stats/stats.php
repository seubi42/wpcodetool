<?php
/**
 * Statistiques : remplissage et présence des créneaux de la période, inscriptions prises,
 * stock vendu non réalisé, ventes sur place.
 */

use Smbb\Tomo\Admin\View;
use Smbb\Tomo\Repository\Creneaux;
use Smbb\Tomo\Repository\Lookup;
use Smbb\Tomo\Repository\Stats;
use Smbb\Tomo\Support\Choices;
use Smbb\Tomo\Support\Input;
use Smbb\Tomo\Support\Schema;

defined('ABSPATH') || exit;

$d = 'smbb-tomo';
$header = isset($page_header_html) ? $page_header_html : '';
$notices = isset($notices_html) ? $notices_html : '';

if (!Schema::ready()) {
    View::schemaMissing($header, $notices, __('Statistiques', $d));
    return;
}

$periods = Stats::periods();
$period = isset($_GET['period']) ? sanitize_key(wp_unslash($_GET['period'])) : '12m';
$from_get = isset($_GET['from']) ? sanitize_text_field(wp_unslash($_GET['from'])) : '';
$to_get = isset($_GET['to']) ? sanitize_text_field(wp_unslash($_GET['to'])) : '';

if ($period === 'custom' && Input::isDate($from_get) && Input::isDate($to_get) && $from_get <= $to_get) {
    $from = $from_get;
    $to = $to_get;
} else {
    $period = isset($periods[$period]) ? $period : '12m';
    $from = $periods[$period][1];
    $to = $periods[$period][2];
}

$atelier_id = isset($_GET['atelier']) ? absint($_GET['atelier']) : 0;
$creneau_id = isset($_GET['creneau']) ? absint($_GET['creneau']) : 0;
$creneau = $creneau_id ? Creneaux::find($creneau_id) : null;

// Un créneau appartient à un atelier : le filtre atelier suit.
if ($creneau && (!$atelier_id || (int) $creneau['atelier_id'] === $atelier_id)) {
    $atelier_id = (int) $creneau['atelier_id'];
} else {
    $creneau = null;
    $creneau_id = 0;
}

$stats = Stats::compute($from, $to, $atelier_id, $creneau_id);
$stock = Stats::stock($atelier_id);
$onsite = Stats::onsite($from, $to, $atelier_id, $creneau_id);
$totals = $stats['totals'];
$sales = $stats['sales'];
$pct = function ($ratio) {
    return $ratio === null ? '–' : number_format_i18n($ratio * 100, 0) . ' %';
};
$url = function (array $args) use ($period, $atelier_id, $creneau_id, $from, $to) {
    $base = array('period' => $period, 'atelier' => $atelier_id ?: false, 'creneau' => $creneau_id ?: false);

    if ($period === 'custom') {
        $base['from'] = $from;
        $base['to'] = $to;
    }

    return View::url('tomo_stats', array_merge($base, $args));
};

// Créneaux de l'atelier choisi sur la période (plus celui demandé s'il est hors période).
$creneau_select = '';

if ($atelier_id) {
    $options = array(__('Tous', $d) => '');
    $list = Creneaux::between($from, $to, array('atelier_id' => $atelier_id, 'statuses' => array('open', 'full', 'draft', 'cancelled')));

    if ($creneau && !in_array($creneau_id, array_map('intval', array_column($list, 'id')), true)) {
        $list[] = $creneau;
    }

    foreach ($list as $c) {
        $options[Lookup::creneauLabel($c, false) . ($c['status'] === 'cancelled' ? ' (' . __('annulé', $d) . ')' : '')] = (string) (int) $c['id'];
    }

    $creneau_select = '<label>' . esc_html__('Créneau', $d) . ' ' . View::select('creneau', $options, $creneau_id ? (string) $creneau_id : '', 'onchange="this.form.submit()"') . '</label> ';
}

// Période et atelier.
$segments = '';

foreach ($periods as $key => $p) {
    $segments .= '<a class="' . ($key === $period ? 'is-active' : '') . '" href="' . esc_url($url(array('period' => $key, 'from' => false, 'to' => false))) . '">' . esc_html($p[0]) . '</a>';
}

$html = '<div class="tomo-stats-filters">'
    . '<div class="tomo-segments">' . $segments . '</div>'
    . '<form method="get" class="tomo-filters">'
    . '<input type="hidden" name="page" value="smbb-codetool-tomo_stats">'
    . '<input type="hidden" name="period" value="custom">'
    . '<label>' . esc_html__('Du', $d) . ' <input type="date" name="from" value="' . esc_attr($from) . '"></label> '
    . '<label>' . esc_html__('au', $d) . ' <input type="date" name="to" value="' . esc_attr($to) . '"></label> '
    . '<label>' . esc_html__('Atelier', $d) . ' ' . View::select('atelier', Lookup::atelierOptions(__('Tous', $d)), $atelier_id ?: '', 'onchange="if(this.form.creneau){this.form.creneau.value=\'\'}this.form.submit()"') . '</label> '
    . $creneau_select
    . '<button class="button">' . esc_html__('Afficher', $d) . '</button>'
    . '</form></div>';

if ($creneau) {
    $html .= '<div class="notice notice-info inline tomo-stats-focus"><p>'
        . esc_html(sprintf(__('Chiffres du créneau %s, quelle que soit la période (inscriptions et ventes sur place de cette date).', $d), Lookup::creneauLabel($creneau)))
        . ' <a href="' . esc_url(View::url('tomo_espace', array('creneau' => $creneau_id))) . '">' . esc_html__('Gestion mobile', $d) . '</a>'
        . ' · <a href="' . esc_url($url(array('creneau' => false))) . '">' . esc_html__('Tout l\'atelier', $d) . '</a></p></div>';
}

// Chiffres clés.
$kpi = function ($icon, $value, $label, $hint = '', $href = '') {
    $tag = $href !== '' ? 'a' : 'div';

    return '<' . $tag . ' class="tomo-kpi"' . ($href !== '' ? ' href="' . esc_url($href) . '"' : '') . '>'
        . '<span class="dashicons ' . esc_attr($icon) . '" aria-hidden="true"></span>'
        . '<strong>' . $value . '</strong><small>' . esc_html($label) . '</small>'
        . ($hint !== '' ? '<em class="tomo-kpi-hint">' . esc_html($hint) . '</em>' : '')
        . '</' . $tag . '>';
};

$html .= '<div class="tomo-kpis">'
    . $kpi('dashicons-chart-bar', esc_html($pct($stats['fill'])), __('Remplissage', $d), sprintf(__('%1$s places occupées sur %2$s, %3$s créneaux', $d), number_format_i18n($totals['booked']), number_format_i18n($totals['capacity']), number_format_i18n($totals['sessions'])))
    . $kpi('dashicons-groups', esc_html($pct($stats['presence'])), __('Présence', $d), $totals['present'] + $totals['absent']
        ? sprintf(__('%1$s présents, %2$s absents', $d), number_format_i18n($totals['present']), number_format_i18n($totals['absent'])) . ($totals['unchecked'] ? ' · ' . sprintf(_n('%s non pointé', '%s non pointés', $totals['unchecked'], $d), number_format_i18n($totals['unchecked'])) : '')
        : __('Aucun appel fait sur la période', $d))
    . $kpi('dashicons-cart', esc_html(number_format_i18n($sales['sold'])), __('Inscriptions prises', $d), sprintf(__('%1$s encaissés · %2$s annulées', $d), wp_strip_all_tags(View::money($sales['revenue'])), number_format_i18n($sales['cancelled'])))
    . $kpi('dashicons-clock', $sales['lead_days'] === null ? '–' : esc_html(sprintf(_n('%s jour', '%s jours', $sales['lead_days'], $d), number_format_i18n($sales['lead_days']))), __('Délai de réservation', $d), __('médiane entre la réservation et l\'atelier', $d))
    . $kpi('dashicons-tickets-alt', esc_html(number_format_i18n($stock['upcoming'] + $stock['undated'])), __('Vendu, pas encore réalisé', $d), sprintf(__('%1$s à venir · %2$s sans date · %3$s', $d), number_format_i18n($stock['upcoming']), number_format_i18n($stock['undated']), wp_strip_all_tags(View::money($stock['amount']))), View::url('tomo_stock', array('atelier' => $atelier_id ?: false)))
    . ($onsite ? $kpi('dashicons-store', View::money($onsite['paid']), __('Ventes sur place', $d), sprintf(_n('%s vente payée', '%s ventes payées', $onsite['paid_count'], $d), number_format_i18n($onsite['paid_count'])) . ($onsite['pending_count'] ? ' · ' . sprintf(_n('%s lien en attente', '%s liens en attente', $onsite['pending_count'], $d), number_format_i18n($onsite['pending_count'])) : '')) : '')
    . '</div>';

// Graphique : places occupées par mois (date de l'atelier), sur fond de capacité.
$months = $stats['months'];

if ($months && array_sum(array_column($months, 'capacity')) > 0) {
    $month_label = function ($key) {
        return date_i18n('M y', strtotime($key . '-01'));
    };
    $max = max(1, max(array_column($months, 'capacity')));
    $n = count($months);
    $w = 720;
    $h = 220;
    $top = 22;
    $bottom = 28;
    $left = 8;
    $slot = ($w - $left * 2) / $n;
    $bar = min(42, $slot * 0.62);
    $plot = $h - $top - $bottom;
    $svg = '<svg class="tomo-chart" viewBox="0 0 ' . $w . ' ' . $h . '" role="img" aria-label="' . esc_attr__('Places occupées par mois, sur la capacité proposée', $d) . '">';
    $svg .= '<line class="tomo-chart-base" x1="' . $left . '" x2="' . ($w - $left) . '" y1="' . ($h - $bottom) . '" y2="' . ($h - $bottom) . '"/>';
    $i = 0;

    foreach ($months as $key => $m) {
        $x = $left + $slot * $i + ($slot - $bar) / 2;
        $cap_h = $m['capacity'] / $max * $plot;
        $book_h = $m['booked'] / $max * $plot;
        $ratio = $m['capacity'] ? $m['booked'] / $m['capacity'] : null;
        $label = $month_label($key);
        $tip = sprintf(__('%1$s : %2$d places occupées sur %3$d (%4$s), %5$d créneaux', $d), $label, $m['booked'], $m['capacity'], $pct($ratio), $m['sessions']);
        $svg .= '<g class="tomo-chart-col"><title>' . esc_html($tip) . '</title>'
            . '<rect class="tomo-chart-hit" x="' . round($left + $slot * $i, 1) . '" y="0" width="' . round($slot, 1) . '" height="' . $h . '"/>';

        if ($m['capacity']) {
            $svg .= '<rect class="tomo-chart-cap" x="' . round($x, 1) . '" y="' . round($h - $bottom - $cap_h, 1) . '" width="' . round($bar, 1) . '" height="' . round($cap_h, 1) . '" rx="4"/>';
        }

        if ($m['booked']) {
            $svg .= '<rect class="tomo-chart-bar" x="' . round($x, 1) . '" y="' . round($h - $bottom - $book_h, 1) . '" width="' . round($bar, 1) . '" height="' . round($book_h, 1) . '" rx="4"/>';
        }

        if ($ratio !== null && ($n <= 12 || $i % 2 === 0)) {
            $svg .= '<text class="tomo-chart-val" x="' . round($x + $bar / 2, 1) . '" y="' . round($h - $bottom - $cap_h - 6, 1) . '">' . esc_html($pct($ratio)) . '</text>';
        }

        if ($n <= 12 || $i % 2 === 0) {
            $svg .= '<text class="tomo-chart-x" x="' . round($x + $bar / 2, 1) . '" y="' . ($h - 8) . '">' . esc_html($label) . '</text>';
        }

        $svg .= '</g>';
        $i++;
    }

    $svg .= '</svg>';
    $legend = '<p class="tomo-chart-legend"><span class="tomo-swatch tomo-swatch--bar"></span>' . esc_html__('Places occupées', $d)
        . ' <span class="tomo-swatch tomo-swatch--cap"></span>' . esc_html__('Capacité proposée', $d)
        . ' <span class="tomo-muted">' . esc_html__('Pourcentage = remplissage du mois.', $d) . '</span></p>';

    $rows = '';

    foreach ($months as $key => $m) {
        $rows .= '<tr><td>' . esc_html($month_label($key)) . '</td><td>' . (int) $m['sessions'] . '</td><td>' . View::fill($m['booked'], $m['capacity']) . '</td><td>' . (int) $m['present'] . '</td><td>' . (int) (isset($sales['by_month'][$key]) ? $sales['by_month'][$key] : 0) . '</td></tr>';
    }

    $table = '<details class="tomo-chart-table"><summary>' . esc_html__('Voir le détail par mois', $d) . '</summary>'
        . '<table class="widefat striped tomo-table"><thead><tr><th>' . esc_html__('Mois', $d) . '</th><th>' . esc_html__('Créneaux', $d) . '</th><th>' . esc_html__('Remplissage', $d) . '</th><th>' . esc_html__('Présents', $d) . '</th><th>' . esc_html__('Inscriptions prises', $d) . '</th></tr></thead><tbody>' . $rows . '</tbody></table></details>';

    $html .= View::card(__('Remplissage par mois', $d), $legend . $svg . $table, 'tomo-stats-chart');
} else {
    $html .= View::card(__('Remplissage par mois', $d), '<p class="tomo-muted">' . esc_html__('Aucun créneau sur cette période.', $d) . '</p>');
}

// Par atelier.
if ($stats['ateliers']) {
    $rows = '';

    foreach ($stats['ateliers'] as $aid => $a) {
        $presence = ($a['present'] + $a['absent']) ? $a['present'] / ($a['present'] + $a['absent']) : null;
        $rows .= '<tr>'
            . '<td><a href="' . esc_url($url(array('atelier' => $aid))) . '"><strong>' . esc_html(Lookup::atelierName($aid)) . '</strong></a></td>'
            . '<td>' . (int) $a['sessions'] . ($a['cancelled_sessions'] ? ' <span class="tomo-muted">' . esc_html(sprintf(_n('(+%d annulé)', '(+%d annulés)', $a['cancelled_sessions'], $d), $a['cancelled_sessions'])) . '</span>' : '') . '</td>'
            . '<td>' . ($a['capacity'] ? View::fill($a['booked'], $a['capacity']) : '<span class="tomo-muted">–</span>') . '</td>'
            . '<td>' . esc_html($pct($presence)) . '</td>'
            . '<td>' . (int) $a['sold'] . ($a['cancelled'] ? ' <span class="tomo-muted">' . esc_html(sprintf(_n('(%d annulée)', '(%d annulées)', $a['cancelled'], $d), $a['cancelled'])) . '</span>' : '') . '</td>'
            . '<td>' . View::money($a['revenue']) . '</td>'
            . ($onsite ? '<td>' . View::money(isset($onsite['by_atelier'][$aid]) ? $onsite['by_atelier'][$aid] : 0) . '</td>' : '')
            . '</tr>';
    }

    $html .= View::card(__('Par atelier', $d), '<div class="tomo-table-scroll"><table class="widefat striped tomo-table"><thead><tr>'
        . '<th>' . esc_html__('Atelier', $d) . '</th><th>' . esc_html__('Créneaux', $d) . '</th><th>' . esc_html__('Remplissage', $d) . '</th><th>' . esc_html__('Présence', $d) . '</th><th>' . esc_html__('Inscriptions prises', $d) . '</th><th>' . esc_html__('Billets', $d) . '</th>'
        . ($onsite ? '<th>' . esc_html__('Sur place', $d) . '</th>' : '')
        . '</tr></thead><tbody>' . $rows . '</tbody></table></div>'
        . '<p class="tomo-muted">' . esc_html__('Créneaux, remplissage et présence : ateliers qui ont lieu sur la période. Inscriptions et billets : réservations prises sur la période. Sur place : ventes de la caisse sur la période, nettes des retours.', $d) . '</p>');
}

// Billets (par origine) et ventes sur place, côte à côte, puis le détail de la caisse.
$split = array();

foreach ($sales['sources'] as $source => $count) {
    $split[] = array(Choices::label('sources', $source) ?: $source, $count, isset($sales['source_revenue'][$source]) ? (float) $sales['source_revenue'][$source] : 0.0, __('billets', $d));
}

if ($onsite && ($onsite['paid_count'] || $onsite['paid'] > 0)) {
    $split[] = array(__('Ventes sur place (caisse)', $d), $onsite['paid_count'], (float) $onsite['paid'], __('ventes', $d));
}

if ($split) {
    $grand = max(0.01, array_sum(array_column($split, 2)));
    $rows = '';

    foreach ($split as $row) {
        $rows .= '<tr><td>' . esc_html($row[0]) . '</td><td>' . esc_html(number_format_i18n($row[1]) . ' ' . $row[3]) . '</td><td>' . View::money($row[2]) . '</td>'
            . '<td><span class="tomo-share"><span style="width:' . esc_attr((string) round($row[2] / $grand * 100)) . '%"></span></span> ' . esc_html($pct($row[2] / $grand)) . '</td></tr>';
    }

    $rows .= '<tr class="tomo-total"><td><strong>' . esc_html__('Total', $d) . '</strong></td><td></td><td><strong>' . View::money(array_sum(array_column($split, 2))) . '</strong></td><td></td></tr>';
    $html .= View::card(__('En ligne et sur place', $d), '<div class="tomo-table-scroll"><table class="widefat striped tomo-table"><thead><tr><th>' . esc_html__('Origine', $d) . '</th><th>' . esc_html__('Nombre', $d) . '</th><th>' . esc_html__('Montant', $d) . '</th><th>' . esc_html__('Part', $d) . '</th></tr></thead><tbody>' . $rows . '</tbody></table></div>'
        . '<p class="tomo-muted">' . esc_html__('Billets : prix des inscriptions selon leur origine (réservation en ligne, ajout manuel, imprévu…). Caisse : produits vendus sur place, nets des retours.', $d) . '</p>');
}

if ($onsite && ($onsite['products'] || $onsite['methods'])) {
    $methods = '';

    foreach ($onsite['methods'] as $label => $amount) {
        $methods .= '<li><span>' . esc_html($label) . '</span><span class="tomo-share"><span style="width:' . esc_attr((string) round($amount / max(0.01, $onsite['paid']) * 100)) . '%"></span></span><strong>' . View::money($amount) . '</strong></li>';
    }

    $products = '';

    foreach (array_slice($onsite['products'], 0, 15, true) as $name => $p) {
        $products .= '<tr><td>' . esc_html($name) . (!empty($p['sku']) ? ' <span class="tomo-muted">' . esc_html(sprintf(__('Réf. %s', $d), $p['sku'])) . '</span>' : '') . '</td><td>' . (int) $p['qty'] . '</td><td>' . View::money($p['total']) . '</td></tr>';
    }

    $extra = array();

    if ($onsite['pending'] > 0) {
        $extra[] = sprintf(__('En attente de paiement : %s', $d), wp_strip_all_tags(View::money($onsite['pending'])));
    }

    if ($onsite['vouchers'] > 0) {
        $extra[] = sprintf(__("Retours remboursés en bon d'achat : %s", $d), wp_strip_all_tags(View::money($onsite['vouchers'])));
    }

    $html .= View::card(__('Ventes sur place', $d), '<div class="tomo-stats-grid">'
        . '<div><h3>' . esc_html__('Par moyen de paiement', $d) . '</h3><ul class="tomo-shares">' . $methods . '</ul></div>'
        . '<div><h3>' . esc_html__('Produits les plus vendus', $d) . '</h3>' . ($products !== '' ? '<table class="widefat striped tomo-table"><thead><tr><th>' . esc_html__('Produit', $d) . '</th><th>' . esc_html__('Qté', $d) . '</th><th>' . esc_html__('Montant', $d) . '</th></tr></thead><tbody>' . $products . '</tbody></table>' : '<p class="tomo-muted">–</p>') . '</div>'
        . '</div>' . ($extra ? '<p class="tomo-muted">' . esc_html(implode(' · ', $extra)) . '</p>' : ''));
}

// Jours de la semaine et sources, côte à côte.
$side = '';

if ($stats['weekdays']) {
    $names = array(1 => __('Lundi', $d), 2 => __('Mardi', $d), 3 => __('Mercredi', $d), 4 => __('Jeudi', $d), 5 => __('Vendredi', $d), 6 => __('Samedi', $d), 7 => __('Dimanche', $d));
    $rows = '';

    foreach ($stats['weekdays'] as $day => $w) {
        $rows .= '<tr><td>' . esc_html($names[$day]) . '</td><td>' . (int) $w['sessions'] . '</td><td>' . View::fill($w['booked'], $w['capacity']) . '</td></tr>';
    }

    $side .= View::card(__('Selon le jour', $d), '<table class="widefat striped tomo-table"><thead><tr><th>' . esc_html__('Jour', $d) . '</th><th>' . esc_html__('Créneaux', $d) . '</th><th>' . esc_html__('Remplissage', $d) . '</th></tr></thead><tbody>' . $rows . '</tbody></table>');
}

if ($sales['sources']) {
    $total = max(1, array_sum($sales['sources']));
    $rows = '';

    foreach ($sales['sources'] as $source => $count) {
        $rows .= '<li><span>' . esc_html(Choices::label('sources', $source) ?: $source) . '</span>'
            . '<span class="tomo-share"><span style="width:' . esc_attr((string) round($count / $total * 100)) . '%"></span></span>'
            . '<strong>' . esc_html(number_format_i18n($count)) . '</strong></li>';
    }

    $side .= View::card(__('Origine des inscriptions', $d), '<ul class="tomo-shares">' . $rows . '</ul>');
}

if ($side !== '') {
    $html .= '<div class="tomo-stats-grid">' . $side . '</div>';
}

View::page($html, $header, $notices, __('Statistiques', $d));
