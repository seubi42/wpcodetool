<?php
/**
 * Tableau de bord Passflow (Au quotidien › Tableau de bord) :
 * 1. En-tête « Bonjour » avec le résumé du jour et les boutons d'action ;
 * 2. Des boîtes WordPress (meta boxes, comme l'accueil de l'admin WP),
 *    sur 3 colonnes : À traiter (si besoin), les widgets Passflow de l'accueil WP (Admin\DashboardWidgets :
 *    Prochains ateliers, Ventes, Remplissage, Demandes de dispo), Derniers mouvements, Derniers messages.
 *
 * Chaque utilisateur peut glisser les boîtes d'une colonne à l'autre et les plier ; WordPress retient
 * l'ordre (méta meta-box-order_{écran}) et l'état plié (closedpostboxes_{écran}) via postbox.js.
 * Pas de masquage : les boîtes sont déclarées après l'en-tête, donc absentes des Options de l'écran,
 * et le filtre hidden_meta_boxes ignore un éventuel réglage resté en base.
 */

use Smbb\Passflow\Admin\DashboardWidgets;
use Smbb\Passflow\Admin\View;
use Smbb\Passflow\Repository\Slots;
use Smbb\Passflow\Repository\Bookings;
use Smbb\Passflow\Repository\Lookup;
use Smbb\Passflow\Support\Choices;
use Smbb\Passflow\Support\Schema;
use Smbb\Passflow\Support\Settings;

defined('ABSPATH') || exit;

$d = 'smbb-passflow';
$header = isset($page_header_html) ? $page_header_html : '';
$notices = isset($notices_html) ? $notices_html : '';

if (!Schema::ready()) {
    View::schemaMissing($header, $notices);
    return;
}

global $wpdb;

wp_enqueue_style('smbb-passflow-dashboard', SMBB_PASSFLOW_URL . 'assets/dashboard.css', array(), SMBB_PASSFLOW_VERSION . '.' . (int) @filemtime(SMBB_PASSFLOW_PATH . 'assets/dashboard.css'));
DashboardWidgets::pageAssets();
wp_enqueue_script('postbox');
wp_add_inline_script('postbox', 'jQuery(function(){if(window.postboxes){postboxes.add_postbox_toggles(window.pagenow);}});');
wp_enqueue_style('smbb-passflow-home', SMBB_PASSFLOW_URL . 'assets/home.css', array('smbb-passflow-dashboard', 'smbb-passflow-wp-dashboard'), SMBB_PASSFLOW_VERSION . '.' . (int) @filemtime(SMBB_PASSFLOW_PATH . 'assets/home.css'));

$today = current_time('Y-m-d');
$in_30_days = date('Y-m-d', strtotime($today . ' +30 days'));
$woo = \Smbb\Passflow\WooCommerce\Module::active();

$button = function ($label, $url, $primary = false) {
    return '<a class="button button-small' . ($primary ? ' button-primary' : '') . '" href="' . esc_url($url) . '">' . esc_html($label) . '</a>';
};

// ---------------------------------------------------------------------------
// À traiter
// ---------------------------------------------------------------------------

$upcoming = Slots::upcoming();
$todo = array(); // [gravité (1 urgent, 2 à surveiller, 3 info), titre, détail, bouton, url]

foreach ($upcoming as $slot) {
    if ($slot['start_date'] > $in_30_days) {
        continue;
    }

    $label = Lookup::slotLabel($slot);
    $days = (int) round((strtotime($slot['start_date']) - strtotime($today)) / DAY_IN_SECONDS);
    $when = $days === 0 ? __("aujourd'hui", $d) : ($days === 1 ? __('demain', $d) : sprintf(__('dans %d jours', $d), $days));

    if ($slot['status'] === 'draft') {
        $todo[] = array(2, sprintf(__('%s est encore en brouillon', $d), $label), sprintf(__('Pas en vente · %s', $d), $when), __('Ouvrir', $d), View::url('passflow_slot', array('view' => 'form', 'id' => (int) $slot['id'])));
    }

    if (!Lookup::ids($slot['host_ids'])) {
        $todo[] = array($days <= 7 ? 1 : 2, sprintf(__("%s n'a pas d'intervenant", $d), $label), sprintf(_n('%1$s · %2$d inscrit', '%1$s · %2$d inscrits', (int) $slot['booked'], $d), $when, (int) $slot['booked']), __('Affecter', $d), View::url('passflow_slot', array('view' => 'form', 'id' => (int) $slot['id'])));
    }

    if ($slot['remaining'] > 0 && $slot['waiting'] > 0 && Settings::waitlistEnabled()) {
        $todo[] = array(3, sprintf(__("%1\$s : %2\$d places libres et %3\$d en liste d'attente", $d), $label, (int) $slot['remaining'], (int) $slot['waiting']), __('Proposer les places aux premiers de la liste', $d), __('Voir', $d), View::url('passflow_slot', array('view' => 'details', 'id' => (int) $slot['id'])));
    }
}

// Ateliers passés (7 derniers jours) dont l'appel n'est pas fini : sans pointage, le stock reste faux.
$recent = Slots::between(date('Y-m-d', strtotime($today . ' -7 days')), date('Y-m-d', strtotime($today . ' -1 day')), array('statuses' => array('open', 'full')));
$recent_counts = $recent ? Bookings::statusCounts(array_column($recent, 'id')) : array();

foreach ($recent as $slot) {
    $c = isset($recent_counts[(int) $slot['id']]) ? $recent_counts[(int) $slot['id']] : array();
    $unchecked = (isset($c['confirmed']) ? $c['confirmed'] : 0) + (isset($c['pending']) ? $c['pending'] : 0);

    if ($unchecked > 0) {
        $todo[] = array(1, sprintf(_n("%1\$s : %2\$d participant n'a pas été pointé", "%1\$s : %2\$d participants n'ont pas été pointés", $unchecked, $d), Lookup::slotLabel($slot), $unchecked), __('Sans appel, le stock et les PCA restent faux', $d), __("Faire l'appel", $d), View::url('passflow_portal', array('creneau' => (int) $slot['id'])));
    }
}

$to_confirm = Bookings::countPendingDated();
$to_replace = Bookings::countWithoutDate();
$failed = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . Lookup::table('passflow_delivery') . " WHERE deleted_flag = 0 AND status = 'failed' AND sent_at >= %s", date('Y-m-d H:i:s', strtotime(current_time('mysql')) - 7 * DAY_IN_SECONDS)));

if ($to_confirm > 0) {
    $todo[] = array(1, sprintf(_n('%d réservation à confirmer', '%d réservations à confirmer', $to_confirm, $d), $to_confirm), __('Elles bloquent une place sans être payées', $d), __('Voir', $d), View::filtered('passflow_booking', 'status', 'pending'));
}

if ($failed > 0) {
    $todo[] = array(1, sprintf(_n("%d message n'a pas pu partir", "%d messages n'ont pas pu partir", $failed, $d), $failed), __('Ces 7 derniers jours', $d), __('Voir', $d), View::filtered('passflow_delivery', 'status', 'failed'));
}

if ($to_replace > 0) {
    $todo[] = array(3, sprintf(_n('%d réservation attend une nouvelle date', '%d réservations attendent une nouvelle date', $to_replace, $d), $to_replace), __('Places payées, à replacer sur un créneau', $d), __('Placer', $d), View::url('passflow_stock', array('tab' => 'replace')));
}

$apple = \Smbb\Passflow\Service\Tickets::appleExpiryWarning();

if ($apple !== '') {
    $todo[] = array(2, $apple, '', __('Réglages', $d), View::url('passflow_settings'));
}

usort($todo, function ($a, $b) {
    return $a[0] - $b[0];
});

$today_rows = Slots::between($today, $today, array('statuses' => array('open', 'full')));

// ---------------------------------------------------------------------------
// Rendu
// ---------------------------------------------------------------------------

$user = wp_get_current_user();
$first_name = $user && $user->first_name !== '' ? $user->first_name : ($user ? $user->display_name : '');
$summary = array();
$summary[] = $today_rows ? sprintf(_n("%d créneau aujourd'hui", "%d créneaux aujourd'hui", count($today_rows), $d), count($today_rows)) : __("Aucun créneau aujourd'hui", $d);
$summary[] = $todo ? sprintf(_n('%d point à traiter', '%d points à traiter', count($todo), $d), count($todo)) : __('rien à traiter', $d);

$html = '<div class="passflow-dash passflow-home">';
$html .= '<div class="passflow-dash-head">'
    . '<div><h1>' . esc_html($first_name !== '' ? sprintf(__('Bonjour %s', $d), $first_name) : __('Bonjour', $d)) . '</h1>'
    . '<p class="passflow-muted">' . esc_html(ucfirst(date_i18n('l j F', strtotime($today))) . ' · ' . implode(', ', $summary)) . '</p></div>'
    . '<div class="passflow-actions">'
    . '<a class="button button-primary" href="' . esc_url(View::url('passflow_slot', array('view' => 'form'))) . '"><span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span> ' . esc_html__('Nouveau créneau', $d) . '</a>'
    . '<a class="button" href="' . esc_url(View::url('passflow_booking', array('view' => 'form'))) . '"><span class="dashicons dashicons-admin-users" aria-hidden="true"></span> ' . esc_html__('Inscrire un participant', $d) . '</a>'
    . ($woo && Settings::giftInStore() ? '<a class="button" href="' . esc_url(View::url('passflow_vouchers')) . '"><span class="dashicons dashicons-tickets-alt" aria-hidden="true"></span> ' . esc_html__('Utiliser un bon cadeau', $d) . '</a>' : '')
    . '</div></div>';

// ---------------------------------------------------------------------------
// Boîtes (meta boxes WordPress)
// ---------------------------------------------------------------------------

$screen = get_current_screen();
$screen_id = $screen ? $screen->id : 'passflow';

$title = function ($icon, $label, $count = 0) {
    return '<span class="passflow-home-ico" aria-hidden="true"><span class="dashicons ' . esc_attr($icon) . '"></span></span>'
        . '<span class="passflow-home-title">' . esc_html($label) . '</span>'
        . ($count > 0 ? ' <span class="passflow-dash-count">' . (int) $count . '</span>' : '');
};

$more = function ($label, $url) {
    return '<p class="passflow-home-more"><a href="' . esc_url($url) . '">' . esc_html($label) . ' →</a></p>';
};

$boxes = array(); // id => [titre HTML, contenu HTML, colonne par défaut, classe]

// À traiter : seulement s'il y a quelque chose (sinon le résumé de l'en-tête le dit déjà).
if ($todo) {
    $rows_html = '';

    foreach ($todo as $item) {
        $tone = $item[0] === 1 ? 'bad' : ($item[0] === 2 ? 'warn' : 'info');
        $rows_html .= '<div class="passflow-dash-todo-row is-' . $tone . '"><div><strong>' . esc_html($item[1]) . '</strong>'
            . ($item[2] !== '' ? '<small>' . esc_html($item[2]) . '</small>' : '') . '</div>'
            . $button($item[3], $item[4]) . '</div>';
    }

    $boxes['passflow_home_todo'] = array($title('dashicons-flag', __('À traiter', $d), count($todo)), '<div class="passflow-dash-todo">' . $rows_html . '</div>', 'normal', 'passflow-home-box--todo');
}

// Les widgets Passflow de l'accueil WordPress, répartis sur les 3 colonnes.
$contexts = array('normal', 'side', 'column3');
$column = 0;

foreach (DashboardWidgets::pageWidgets() as $widget) {
    $body = DashboardWidgets::capture($widget['key']);

    if ($body !== '') {
        $boxes['passflow_home_' . sanitize_key($widget['key'])] = array($title($widget['icon'], $widget['title']), $body, $contexts[$column++ % 3], 'passflow-home-box--widget');
    }
}

// Derniers mouvements et derniers messages.
$feed = '';

foreach (Bookings::latestHistory(6) as $event) {
    $booking = Bookings::find($event['booking_id']);
    $who = $booking ? '<a href="' . esc_url(View::url('passflow_booking', array('view' => 'details', 'id' => (int) $booking['id']))) . '">' . esc_html(Lookup::personName($booking)) . '</a> · ' : '';
    $feed .= '<div><time>' . esc_html(View::datetime($event['created_at'])) . '</time><span>' . $who . esc_html(Choices::label('historyActions', $event['action']) . ((string) $event['message'] !== '' ? ' · ' . $event['message'] : '')) . '</span></div>';
}

$messages = '';
$deliveries = $wpdb->get_results('SELECT * FROM ' . Lookup::table('passflow_delivery') . ' WHERE deleted_flag = 0 ORDER BY id DESC LIMIT 6', ARRAY_A);

foreach (is_array($deliveries) ? $deliveries : array() as $delivery) {
    $failed_row = $delivery['status'] === 'failed';
    $messages .= '<div' . ($failed_row ? ' class="is-failed"' : '') . '><time>' . esc_html(View::datetime($delivery['sent_at'])) . '</time><span>'
        . esc_html(Choices::label('channels', $delivery['channel']) . ' · ' . Choices::label('deliveryEvents', $delivery['event']) . ((string) $delivery['recipient_name'] !== '' ? ' · ' . $delivery['recipient_name'] : ''))
        . ($failed_row ? ' · <strong>' . esc_html__('non parti', $d) . '</strong>' : '') . '</span></div>';
}

if ($feed !== '' || $messages !== '') {
    $boxes['passflow_home_history'] = array(
        $title('dashicons-backup', __('Derniers mouvements', $d)),
        '<div class="passflow-dash-feed">' . ($feed !== '' ? $feed : '<p class="passflow-muted">' . esc_html__('Aucun mouvement pour le moment.', $d) . '</p>') . '</div>' . $more(__('Journal', $d), View::url('passflow_history')),
        'normal',
        'passflow-home-box--feed',
    );
    $boxes['passflow_home_messages'] = array(
        $title('dashicons-email-alt', __('Derniers messages', $d)),
        '<div class="passflow-dash-feed">' . ($messages !== '' ? $messages : '<p class="passflow-muted">' . esc_html__('Aucun message envoyé pour le moment.', $d) . '</p>') . '</div>' . $more(__('Messages envoyés', $d), View::url('passflow_delivery')),
        'side',
        'passflow-home-box--feed',
    );
}

foreach ($boxes as $id => $box) {
    $html_body = $box[1];
    $extra = $box[3];

    add_meta_box($id, $box[0], function () use ($html_body) {
        echo $html_body; // phpcs:ignore WordPress.Security.EscapeOutput -- construit et échappé ci-dessus.
    }, $screen_id, $box[2], 'default');

    add_filter('postbox_classes_' . $screen_id . '_' . $id, function ($classes) use ($extra) {
        $classes[] = $extra;

        return $classes;
    });
}

// Ordre enregistré du temps des 2 colonnes : on garde tout, sauf les boîtes prévues pour la 3e
// colonne, qu'on y déplace une fois (sinon la nouvelle colonne resterait vide).
$order_key = 'meta-box-order_' . $screen_id;
$order = get_user_meta(get_current_user_id(), $order_key, true);

if (is_array($order) && $order && !isset($order['column3'])) {
    $third = array();

    foreach ($boxes as $id => $box) {
        if ($box[2] === 'column3') {
            $third[] = $id;
        }
    }

    foreach ($order as $context => $ids) {
        $order[$context] = implode(',', array_diff(array_filter(explode(',', (string) $ids)), $third));
    }

    $order['column3'] = implode(',', $third);
    update_user_meta(get_current_user_id(), $order_key, $order);
}

// On peut déplacer et plier, pas masquer.
add_filter('hidden_meta_boxes', function ($hidden, $current) use ($screen_id) {
    return $current && $current->id === $screen_id ? array() : $hidden;
}, 99, 2);

ob_start();
wp_nonce_field('meta-box-order', 'meta-box-order-nonce', false);
wp_nonce_field('closedpostboxes', 'closedpostboxesnonce', false);
echo '<div class="metabox-holder passflow-home-boxes">';
echo '<div id="postbox-container-1" class="postbox-container">';
do_meta_boxes($screen_id, 'normal', null);
echo '</div><div id="postbox-container-2" class="postbox-container">';
do_meta_boxes($screen_id, 'side', null);
echo '</div><div id="postbox-container-3" class="postbox-container">';
do_meta_boxes($screen_id, 'column3', null);
echo '</div></div>';
$html .= ob_get_clean();

$html .= '</div>';

// Le titre « Bonjour » remplace l'en-tête CodeTool ; les notices restent.
View::page($html, '', $notices);
