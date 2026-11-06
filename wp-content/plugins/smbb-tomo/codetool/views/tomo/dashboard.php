<?php
/**
 * Tableau de bord Tomo, dans l'ordre de lecture :
 * 1. Aujourd'hui : les créneaux du jour, l'appel et la caisse en premier bouton ;
 * 2. À traiter : une ligne par point d'attention, avec son bouton et sa couleur d'urgence ;
 * 3. En chiffres : ventes du mois, remplissage à 30 jours, places vendues à venir, bons cadeaux ;
 * 4. Les 14 prochains jours, colorés selon le remplissage ;
 * 5. Derniers mouvements et derniers messages.
 */

use Smbb\Tomo\Admin\View;
use Smbb\Tomo\Repository\Creneaux;
use Smbb\Tomo\Repository\Inscriptions;
use Smbb\Tomo\Repository\Lookup;
use Smbb\Tomo\Repository\Stats;
use Smbb\Tomo\Support\Choices;
use Smbb\Tomo\Support\Schema;
use Smbb\Tomo\Support\Settings;

defined('ABSPATH') || exit;

$d = 'smbb-tomo';
$header = isset($page_header_html) ? $page_header_html : '';
$notices = isset($notices_html) ? $notices_html : '';

if (!Schema::ready()) {
    View::schemaMissing($header, $notices);
    return;
}

global $wpdb;

wp_enqueue_style('smbb-tomo-dashboard', SMBB_TOMO_URL . 'assets/dashboard.css', array(), SMBB_TOMO_VERSION . '.' . (int) @filemtime(SMBB_TOMO_PATH . 'assets/dashboard.css'));

$today = current_time('Y-m-d');
$now = current_time('H:i:s');
$in_30_days = date('Y-m-d', strtotime($today . ' +30 days'));
$woo = \Smbb\Tomo\WooCommerce\Module::active();

$fill_class = function ($creneau) {
    $fill = isset($creneau['fill']) ? (float) $creneau['fill'] : 0;

    return $fill >= .8 ? 'is-good' : ($fill >= .5 ? 'is-mid' : 'is-low');
};

$bar = function ($creneau) use ($fill_class) {
    $pct = (int) round(100 * (isset($creneau['fill']) ? (float) $creneau['fill'] : 0));

    return '<span class="tomo-dash-bar"><i class="' . esc_attr($fill_class($creneau)) . '" style="width:' . $pct . '%"></i></span>';
};

$button = function ($label, $url, $primary = false) {
    return '<a class="button button-small' . ($primary ? ' button-primary' : '') . '" href="' . esc_url($url) . '">' . esc_html($label) . '</a>';
};

$fiche = function ($creneau) {
    return View::url('tomo_creneau', array('view' => 'details', 'id' => (int) $creneau['id']));
};

// ---------------------------------------------------------------------------
// Données
// ---------------------------------------------------------------------------

$upcoming = Creneaux::upcoming();
$todo = array(); // [gravité (1 urgent, 2 à surveiller, 3 info), titre, détail, bouton, url]

foreach ($upcoming as $creneau) {
    if ($creneau['start_date'] > $in_30_days) {
        continue;
    }

    $label = Lookup::creneauLabel($creneau);
    $days = (int) round((strtotime($creneau['start_date']) - strtotime($today)) / DAY_IN_SECONDS);
    $when = $days === 0 ? __("aujourd'hui", $d) : ($days === 1 ? __('demain', $d) : sprintf(__('dans %d jours', $d), $days));

    if ($creneau['status'] === 'draft') {
        $todo[] = array(2, sprintf(__('%s est encore en brouillon', $d), $label), sprintf(__('Pas en vente · %s', $d), $when), __('Ouvrir', $d), View::url('tomo_creneau', array('view' => 'form', 'id' => (int) $creneau['id'])));
    }

    if (!Lookup::ids($creneau['intervenant_ids'])) {
        $todo[] = array($days <= 7 ? 1 : 2, sprintf(__("%s n'a pas d'intervenant", $d), $label), sprintf(_n('%1$s · %2$d inscrit', '%1$s · %2$d inscrits', (int) $creneau['booked'], $d), $when, (int) $creneau['booked']), __('Affecter', $d), View::url('tomo_creneau', array('view' => 'form', 'id' => (int) $creneau['id'])));
    }

    if ($creneau['remaining'] > 0 && $creneau['waiting'] > 0 && Settings::waitlistEnabled()) {
        $todo[] = array(3, sprintf(__("%1\$s : %2\$d places libres et %3\$d en liste d'attente", $d), $label, (int) $creneau['remaining'], (int) $creneau['waiting']), __('Proposer les places aux premiers de la liste', $d), __('Voir', $d), $fiche($creneau));
    }
}

// Ateliers passés (7 derniers jours) dont l'appel n'est pas fini : sans pointage, le stock reste faux.
$recent = Creneaux::between(date('Y-m-d', strtotime($today . ' -7 days')), date('Y-m-d', strtotime($today . ' -1 day')), array('statuses' => array('open', 'full')));
$recent_counts = Inscriptions::statusCounts(array_column($recent, 'id'));

foreach ($recent as $creneau) {
    $c = isset($recent_counts[(int) $creneau['id']]) ? $recent_counts[(int) $creneau['id']] : array();
    $unchecked = (isset($c['confirmed']) ? $c['confirmed'] : 0) + (isset($c['pending']) ? $c['pending'] : 0);

    if ($unchecked > 0) {
        $todo[] = array(1, sprintf(_n("%1\$s : %2\$d participant n'a pas été pointé", "%1\$s : %2\$d participants n'ont pas été pointés", $unchecked, $d), Lookup::creneauLabel($creneau), $unchecked), __('Sans appel, le stock et les PCA restent faux', $d), __("Faire l'appel", $d), View::url('tomo_espace', array('creneau' => (int) $creneau['id'])));
    }
}

$to_confirm = Inscriptions::countPendingDated();
$to_replace = Inscriptions::countWithoutDate();
$failed = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . Lookup::table('tomo_envoi') . " WHERE deleted_flag = 0 AND status = 'failed' AND sent_at >= %s", date('Y-m-d H:i:s', strtotime(current_time('mysql')) - 7 * DAY_IN_SECONDS)));

if ($to_confirm > 0) {
    $todo[] = array(1, sprintf(_n('%d réservation à confirmer', '%d réservations à confirmer', $to_confirm, $d), $to_confirm), __('Elles bloquent une place sans être payées', $d), __('Voir', $d), View::filtered('tomo_inscription', 'status', 'pending'));
}

if ($failed > 0) {
    $todo[] = array(1, sprintf(_n("%d message n'a pas pu partir", "%d messages n'ont pas pu partir", $failed, $d), $failed), __('Ces 7 derniers jours', $d), __('Voir', $d), View::filtered('tomo_envoi', 'status', 'failed'));
}

if ($to_replace > 0) {
    $todo[] = array(3, sprintf(_n('%d réservation attend une nouvelle date', '%d réservations attendent une nouvelle date', $to_replace, $d), $to_replace), __('Places payées, à replacer sur un créneau', $d), __('Placer', $d), View::url('tomo_stock', array('tab' => 'replace')));
}

$apple = \Smbb\Tomo\Service\Tickets::appleExpiryWarning();

if ($apple !== '') {
    $todo[] = array(2, $apple, '', __('Réglages', $d), View::url('tomo_settings'));
}

usort($todo, function ($a, $b) {
    return $a[0] - $b[0];
});

// Aujourd'hui.
$today_rows = Creneaux::between($today, $today, array('statuses' => array('open', 'full')));
$today_counts = $today_rows ? Inscriptions::statusCounts(array_column($today_rows, 'id')) : array();

// Les 14 prochains jours (brouillons compris, pour voir les trous et ce qui n'est pas en vente).
$last_day = date('Y-m-d', strtotime($today . ' +13 days'));
$fortnight = array();

foreach (Creneaux::between($today, $last_day, array('statuses' => array('open', 'full', 'draft'))) as $creneau) {
    $fortnight[$creneau['start_date']][] = $creneau;
}

// Chiffres.
$month_start = substr($today, 0, 8) . '01';
$prev_start = date('Y-m-01', strtotime($month_start . ' -1 month'));
$prev_same_day = date('Y-m-d', min(strtotime($prev_start . ' +' . ((int) substr($today, 8, 2) - 1) . ' days'), strtotime(date('Y-m-t', strtotime($prev_start)))));
$sales = Stats::sales($month_start, $today);
$sales_prev = Stats::sales($prev_start, $prev_same_day);
$stock = Stats::stock();

$capacity_30 = 0;
$booked_30 = 0;

foreach ($upcoming as $creneau) {
    if ($creneau['status'] !== 'draft' && $creneau['start_date'] <= $in_30_days) {
        $booked_30 += (int) $creneau['booked'];
        $capacity_30 += (int) $creneau['booked'] + (int) $creneau['remaining'];
    }
}

$fill_30 = $capacity_30 > 0 ? (int) round(100 * $booked_30 / $capacity_30) : null;

// Ventes des 8 dernières semaines, pour la courbe (hors pass, comme Statistiques).
$weeks = array_fill(0, 8, 0.0);
$since = date('Y-m-d', strtotime($today . ' -55 days'));
$rows = $wpdb->get_results($wpdb->prepare('SELECT creation_date, price_paid FROM ' . Lookup::table('tomo_inscription') . " WHERE deleted_flag = 0 AND pass_id = 0 AND status <> 'cancelled' AND creation_date >= %s", $since . ' 00:00:00'), ARRAY_A);

foreach (is_array($rows) ? $rows : array() as $row) {
    $index = 7 - (int) floor((strtotime($today) - strtotime(substr($row['creation_date'], 0, 10))) / (7 * DAY_IN_SECONDS));

    if ($index >= 0 && $index <= 7) {
        $weeks[$index] += (float) $row['price_paid'];
    }
}

$sparkline = function (array $values, $tone) {
    $max = max($values) > 0 ? max($values) : 1;
    $count = count($values);
    $points = array();

    foreach (array_values($values) as $i => $value) {
        $points[] = round(120 * $i / max(1, $count - 1), 1) . ' ' . round(24 - 20 * $value / $max, 1);
    }

    $line = 'M' . implode(' L', $points);
    $last = explode(' ', end($points));

    return '<svg class="tomo-dash-spark is-' . esc_attr($tone) . '" viewBox="-2 0 124 26" preserveAspectRatio="none" aria-hidden="true">'
        . '<path class="area" d="' . esc_attr($line . ' L120 26 L0 26Z') . '"/><path class="line" d="' . esc_attr($line) . '"/>'
        . '<circle cx="' . esc_attr($last[0]) . '" cy="' . esc_attr($last[1]) . '" r="2.5"/></svg>';
};

$gift = null;

if ($woo && class_exists('\Smbb\Tomo\WooCommerce\GiftCards')) {
    $gift = \Smbb\Tomo\WooCommerce\GiftCards::counts();
}

// ---------------------------------------------------------------------------
// Rendu
// ---------------------------------------------------------------------------

$user = wp_get_current_user();
$first_name = $user && $user->first_name !== '' ? $user->first_name : ($user ? $user->display_name : '');
$summary = array();
$summary[] = $today_rows ? sprintf(_n("%d créneau aujourd'hui", "%d créneaux aujourd'hui", count($today_rows), $d), count($today_rows)) : __("Aucun créneau aujourd'hui", $d);
$summary[] = $todo ? sprintf(_n('%d point à traiter', '%d points à traiter', count($todo), $d), count($todo)) : __('rien à traiter', $d);

$html = '<div class="tomo-dash">';
$html .= '<div class="tomo-dash-head">'
    . '<div><h1>' . esc_html($first_name !== '' ? sprintf(__('Bonjour %s', $d), $first_name) : __('Bonjour', $d)) . '</h1>'
    . '<p class="tomo-muted">' . esc_html(ucfirst(date_i18n('l j F', strtotime($today))) . ' · ' . implode(', ', $summary)) . '</p></div>'
    . '<div class="tomo-actions">'
    . '<a class="button button-primary" href="' . esc_url(View::url('tomo_creneau', array('view' => 'form'))) . '"><span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span> ' . esc_html__('Nouveau créneau', $d) . '</a>'
    . '<a class="button" href="' . esc_url(View::url('tomo_inscription', array('view' => 'form'))) . '"><span class="dashicons dashicons-admin-users" aria-hidden="true"></span> ' . esc_html__('Inscrire un participant', $d) . '</a>'
    . ($woo && Settings::giftInStore() ? '<a class="button" href="' . esc_url(View::url('tomo_bons')) . '"><span class="dashicons dashicons-tickets-alt" aria-hidden="true"></span> ' . esc_html__('Utiliser un bon cadeau', $d) . '</a>' : '')
    . '</div></div>';

// 1. Aujourd'hui.
$cards = '';

foreach ($today_rows as $creneau) {
    $c = isset($today_counts[(int) $creneau['id']]) ? $today_counts[(int) $creneau['id']] : array();
    $present = isset($c['present']) ? (int) $c['present'] : 0;
    $absent = isset($c['absent']) ? (int) $c['absent'] : 0;
    $unchecked = (isset($c['confirmed']) ? (int) $c['confirmed'] : 0) + (isset($c['pending']) ? (int) $c['pending'] : 0);
    $all_day = Lookup::allDay($creneau);
    $started = $all_day || (string) $creneau['start_time'] <= $now;
    $ended = !$all_day && (string) $creneau['end_time'] !== '' && (string) $creneau['end_time'] < $now;

    if ($present + $absent + $unchecked === 0) {
        $chip = '<span class="tomo-dash-chip is-mute">' . esc_html__('Aucun inscrit', $d) . '</span>';
    } elseif ($unchecked === 0) {
        $chip = '<span class="tomo-dash-chip is-ok">' . esc_html__('Appel fait', $d) . '</span>';
    } elseif ($started) {
        $chip = '<span class="tomo-dash-chip is-warn">' . esc_html(sprintf(__('%d à pointer', $d), $unchecked)) . '</span>';
    } else {
        $minutes = (int) round((strtotime($today . ' ' . $creneau['start_time']) - strtotime($today . ' ' . $now)) / 60);
        $chip = '<span class="tomo-dash-chip is-info">' . esc_html($minutes >= 60 ? sprintf(__('dans %d h', $d), (int) floor($minutes / 60)) : sprintf(__('dans %d min', $d), $minutes)) . '</span>';
    }

    $detail = sprintf(__('%1$d / %2$d places', $d), (int) $creneau['booked'], (int) $creneau['booked'] + (int) $creneau['remaining']);

    if ($present + $absent > 0) {
        $detail .= ' · ' . sprintf(__('%1$d présents, %2$d absents', $d), $present, $absent);
    }

    $buttons = $button(__("Faire l'appel", $d), View::url('tomo_espace', array('creneau' => (int) $creneau['id'])), $started && !$ended)
        . ($woo ? $button(__('Caisse', $d), \Smbb\Tomo\Admin\Caisse::url($creneau['id'])) : '')
        . $button(__('Fiche', $d), $fiche($creneau));

    $cards .= '<div class="tomo-dash-slot' . ($started && !$ended ? ' is-now' : '') . '">'
        . '<div class="tomo-dash-slot-top"><div>'
        . '<div class="tomo-dash-time">' . esc_html(Lookup::slotHours($creneau, false, __('Journée', $d))) . '</div>'
        . '<strong>' . esc_html(Lookup::atelierName($creneau['atelier_id'])) . '</strong>'
        . '<div class="tomo-muted">' . esc_html(Lookup::intervenantNames($creneau['intervenant_ids']) ?: __('Sans intervenant', $d)) . '</div>'
        . '</div>' . $chip . '</div>'
        . '<div class="tomo-dash-fill">' . $bar($creneau) . '<span>' . esc_html($detail) . '</span></div>'
        . '<div class="tomo-actions">' . $buttons . '</div>'
        . '</div>';
}

if ($cards === '') {
    $next = null;

    foreach ($upcoming as $creneau) {
        if ($creneau['start_date'] > $today && $creneau['status'] !== 'draft') {
            $next = $creneau;
            break;
        }
    }

    $cards = '<p class="tomo-muted tomo-dash-empty">' . ($next
        ? sprintf(esc_html__('Rien aujourd\'hui. Prochain créneau : %s.', $d), '<a href="' . esc_url($fiche($next)) . '">' . esc_html(Lookup::creneauLabel($next)) . '</a>')
        : esc_html__('Rien aujourd\'hui, et aucun créneau à venir. Commencez par créer un atelier, puis ses créneaux.', $d)) . '</p>';
}

$html .= '<section class="tomo-dash-block"><div class="tomo-dash-title"><h2>' . esc_html__("Aujourd'hui", $d) . '</h2><a href="' . esc_url(View::url('tomo_calendrier')) . '">' . esc_html__('Calendrier', $d) . '</a></div>'
    . '<div class="tomo-dash-today">' . $cards . '</div></section>';

// 2. À traiter + 3. En chiffres.
$rows_html = '';

foreach ($todo as $item) {
    $tone = $item[0] === 1 ? 'bad' : ($item[0] === 2 ? 'warn' : 'info');
    $rows_html .= '<div class="tomo-dash-todo-row is-' . $tone . '"><div><strong>' . esc_html($item[1]) . '</strong>'
        . ($item[2] !== '' ? '<small>' . esc_html($item[2]) . '</small>' : '') . '</div>'
        . $button($item[3], $item[4]) . '</div>';
}

$todo_html = $rows_html !== ''
    ? '<div class="tomo-dash-todo">' . $rows_html . '</div>'
    : '<div class="tomo-dash-todo"><div class="tomo-dash-todo-row is-ok"><div><strong>' . esc_html__('Rien à traiter sur les 30 prochains jours.', $d) . '</strong></div></div></div>';

$trend = '';

if ($sales_prev['revenue'] > 0) {
    $delta = (int) round(100 * ($sales['revenue'] - $sales_prev['revenue']) / $sales_prev['revenue']);
    $trend = '<em class="' . ($delta >= 0 ? 'is-up' : 'is-down') . '">' . esc_html(sprintf(__('%1$s%2$d %% vs %3$s à la même date', $d), $delta >= 0 ? '+' : '−', abs($delta), date_i18n('F', strtotime($prev_start)))) . '</em>';
} else {
    $trend = '<em>' . esc_html(sprintf(_n('%d place vendue', '%d places vendues', (int) $sales['sold'], $d), (int) $sales['sold'])) . '</em>';
}

$kpis = '<a class="tomo-dash-kpi" href="' . esc_url(View::url('tomo_stats')) . '"><span>' . esc_html(sprintf(__('Ventes de %s', $d), date_i18n('F', strtotime($today)))) . '</span>'
    . '<strong>' . esc_html(View::money($sales['revenue'])) . '</strong>' . $trend . $sparkline($weeks, 'ok') . '</a>';

$kpis .= '<a class="tomo-dash-kpi" href="' . esc_url(View::url('tomo_calendrier')) . '"><span>' . esc_html__('Remplissage à 30 jours', $d) . '</span>'
    . '<strong>' . esc_html($fill_30 === null ? '–' : $fill_30 . ' %') . '</strong>'
    . '<em>' . esc_html(sprintf(_n('%d place encore libre', '%d places encore libres', max(0, $capacity_30 - $booked_30), $d), max(0, $capacity_30 - $booked_30))) . '</em></a>';

$kpis .= '<a class="tomo-dash-kpi" href="' . esc_url(View::url('tomo_stock')) . '"><span>' . esc_html__('Places vendues à venir', $d) . '</span>'
    . '<strong>' . esc_html(number_format_i18n((int) $stock['upcoming'] + (int) $stock['undated'])) . '</strong>'
    . '<em>' . esc_html(sprintf(__('soit %s de PCA', $d), View::money($stock['amount']))) . '</em></a>';

if ($gift !== null) {
    $kpis .= '<a class="tomo-dash-kpi" href="' . esc_url(View::url('tomo_bons')) . '"><span>' . esc_html__('Bons cadeaux en circulation', $d) . '</span>'
        . '<strong>' . esc_html(number_format_i18n((int) $gift['unused'])) . '</strong>'
        . '<em>' . esc_html(sprintf(__('%1$d utilisés, %2$d expirés', $d), (int) $gift['used'], (int) $gift['expired'])) . '</em></a>';
} else {
    $waiting = (int) $wpdb->get_var('SELECT COALESCE(SUM(places), 0) FROM ' . Lookup::table('tomo_attente') . " WHERE deleted_flag = 0 AND status IN ('waiting','notified')");
    $kpis .= '<a class="tomo-dash-kpi" href="' . esc_url(View::url('tomo_attente')) . '"><span>' . esc_html__("Liste d'attente", $d) . '</span>'
        . '<strong>' . esc_html(number_format_i18n($waiting)) . '</strong><em>' . esc_html__('places demandées', $d) . '</em></a>';
}

$html .= '<div class="tomo-dash-split">'
    . '<section class="tomo-dash-block"><div class="tomo-dash-title"><h2>' . esc_html__('À traiter', $d) . ($todo ? ' <span class="tomo-dash-count">' . count($todo) . '</span>' : '') . '</h2></div>' . $todo_html . '</section>'
    . '<section class="tomo-dash-block"><div class="tomo-dash-title"><h2>' . esc_html__('En chiffres', $d) . '</h2><a href="' . esc_url(View::url('tomo_stats')) . '">' . esc_html__('Statistiques', $d) . '</a></div><div class="tomo-dash-kpis">' . $kpis . '</div></section>'
    . '</div>';

// 4. Les 14 prochains jours.
$days_html = '';

for ($i = 0; $i < 14; $i++) {
    $date = date('Y-m-d', strtotime($today . ' +' . $i . ' days'));
    $weekday = (int) date('N', strtotime($date));
    $events = '';

    foreach (isset($fortnight[$date]) ? $fortnight[$date] : array() as $creneau) {
        $class = $creneau['status'] === 'draft' ? 'is-draft' : $fill_class($creneau);
        $events .= '<a class="tomo-dash-ev ' . esc_attr($class) . '" href="' . esc_url($fiche($creneau)) . '" title="' . esc_attr(Lookup::creneauLabel($creneau)) . '">'
            . '<b>' . esc_html(Lookup::slotHours($creneau, false, __('Journée', $d))) . '</b>'
            . esc_html(Lookup::atelierName($creneau['atelier_id']))
            . '<span>' . esc_html($creneau['status'] === 'draft' ? __('brouillon', $d) : $creneau['booked'] . '/' . ((int) $creneau['booked'] + (int) $creneau['remaining'])) . '</span></a>';
    }

    $days_html .= '<div class="tomo-dash-day' . ($i === 0 ? ' is-today' : '') . ($weekday >= 6 ? ' is-weekend' : '') . '">'
        . '<div class="tomo-dash-day-head">' . esc_html(date_i18n('D', strtotime($date))) . '<b>' . esc_html(date_i18n('j', strtotime($date))) . '</b></div>'
        . $events . '</div>';
}

$html .= '<section class="tomo-dash-block"><div class="tomo-dash-title"><h2>' . esc_html__('Les 14 prochains jours', $d) . '</h2><a href="' . esc_url(View::url('tomo_calendrier')) . '">' . esc_html__('Ouvrir le calendrier', $d) . '</a></div>'
    . '<div class="tomo-dash-strip"><div class="tomo-dash-days">' . $days_html . '</div></div>'
    . '<div class="tomo-dash-legend"><span><i class="is-good"></i>' . esc_html__('80 % et plus', $d) . '</span><span><i class="is-mid"></i>' . esc_html__('50 à 79 %', $d) . '</span><span><i class="is-low"></i>' . esc_html__('moins de 50 %', $d) . '</span><span><i class="is-draft"></i>' . esc_html__('brouillon, pas en vente', $d) . '</span></div>'
    . '</section>';

// 5. Derniers mouvements et derniers messages.
$feed = '';

foreach (Inscriptions::latestHistory(6) as $event) {
    $inscription = Inscriptions::find($event['inscription_id']);
    $who = $inscription ? '<a href="' . esc_url(View::url('tomo_inscription', array('view' => 'details', 'id' => (int) $inscription['id']))) . '">' . esc_html(Lookup::personName($inscription)) . '</a> · ' : '';
    $feed .= '<div><time>' . esc_html(View::datetime($event['created_at'])) . '</time><span>' . $who . esc_html(Choices::label('historyActions', $event['action']) . ((string) $event['message'] !== '' ? ' · ' . $event['message'] : '')) . '</span></div>';
}

$messages = '';
$envois = $wpdb->get_results('SELECT * FROM ' . Lookup::table('tomo_envoi') . ' WHERE deleted_flag = 0 ORDER BY id DESC LIMIT 6', ARRAY_A);

foreach (is_array($envois) ? $envois : array() as $envoi) {
    $failed_row = $envoi['status'] === 'failed';
    $messages .= '<div' . ($failed_row ? ' class="is-failed"' : '') . '><time>' . esc_html(View::datetime($envoi['sent_at'])) . '</time><span>'
        . esc_html(Choices::label('channels', $envoi['channel']) . ' · ' . Choices::label('envoiEvents', $envoi['event']) . ((string) $envoi['recipient_name'] !== '' ? ' · ' . $envoi['recipient_name'] : ''))
        . ($failed_row ? ' · <strong>' . esc_html__('non parti', $d) . '</strong>' : '') . '</span></div>';
}

if ($feed !== '' || $messages !== '') {
    $html .= '<div class="tomo-dash-split tomo-dash-split--even">'
        . '<section class="tomo-dash-block"><div class="tomo-dash-title"><h2>' . esc_html__('Derniers mouvements', $d) . '</h2><a href="' . esc_url(View::url('tomo_historique')) . '">' . esc_html__('Journal', $d) . '</a></div>'
        . '<div class="tomo-dash-feed">' . ($feed !== '' ? $feed : '<p class="tomo-muted">' . esc_html__('Aucun mouvement pour le moment.', $d) . '</p>') . '</div></section>'
        . '<section class="tomo-dash-block"><div class="tomo-dash-title"><h2>' . esc_html__('Derniers messages', $d) . '</h2><a href="' . esc_url(View::url('tomo_envoi')) . '">' . esc_html__('Messages envoyés', $d) . '</a></div>'
        . '<div class="tomo-dash-feed">' . ($messages !== '' ? $messages : '<p class="tomo-muted">' . esc_html__('Aucun message envoyé pour le moment.', $d) . '</p>') . '</div></section>'
        . '</div>';
}

$html .= '</div>';

// Le titre « Bonjour » remplace l'en-tête CodeTool ; les notices restent.
View::page($html, '', $notices);
