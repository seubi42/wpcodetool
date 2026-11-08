<?php
/**
 * Espace intervenant : l'agenda de ses dates (remplissage, état de l'appel) et, pour une date,
 * l'écran d'appel (?creneau=ID). Un gestionnaire voit toutes les dates.
 */

use Smbb\Passflow\Admin\Actions;
use Smbb\Passflow\Admin\View;
use Smbb\Passflow\Repository\Slots;
use Smbb\Passflow\Repository\Bookings;
use Smbb\Passflow\Repository\Lookup;
use Smbb\Passflow\Support\Choices;
use Smbb\Passflow\Support\Roles;
use Smbb\Passflow\Support\Schema;

defined('ABSPATH') || exit;

$d = 'smbb-passflow';
$header = isset($page_header_html) ? $page_header_html : '';
$notices = isset($notices_html) ? $notices_html : '';

if (!Schema::ready()) {
    View::schemaMissing($header, $notices, __('Mes ateliers', $d));
    return;
}

$host = Lookup::hostForUser(get_current_user_id());
$manager = Roles::canManage();

if (!$host && !$manager) {
    View::page(View::card('', '<p>' . esc_html(sprintf(__("Votre compte n'est rattaché à aucun intervenant. Demandez à %s de le rattacher depuis la fiche intervenant.", $d), \Smbb\Passflow\Support\Settings::businessName())) . '</p>'), $header, $notices, __('Mes ateliers', $d), 'mobile');
    return;
}

$slot_id = isset($_GET['creneau']) ? absint($_GET['creneau']) : 0;

if ($slot_id) {
    require __DIR__ . '/rollcall.php';
    return;
}

// Gestionnaire : une date choisie dans la liste propose les deux écrans du jour (téléphone ou tablette).
$choice_id = isset($_GET['choix']) ? absint($_GET['choix']) : 0;
$choice = $choice_id && $manager ? Slots::find($choice_id) : null;

if ($choice) {
    $base_url = View::url('passflow_portal');
    $activity = Lookup::atelier($choice['activity_id']);
    $tablet = \Smbb\Passflow\Admin\DayBoard::available($choice);
    $card = function ($url, $icon, $title, $text, $extra = '') {
        return '<a class="passflow-mobile-cta' . $extra . '" href="' . esc_url($url) . '"><span class="dashicons dashicons-' . esc_attr($icon) . '" aria-hidden="true"></span>'
            . '<span class="passflow-mobile-cta-text"><strong>' . esc_html($title) . '</strong><small>' . esc_html($text) . '</small></span>'
            . '<span class="dashicons dashicons-arrow-right-alt2 passflow-mobile-cta-go" aria-hidden="true"></span></a>';
    };
    $body = '<p class="passflow-bc-back"><a href="' . esc_url($base_url) . '"><span class="dashicons dashicons-arrow-left-alt2" aria-hidden="true"></span> ' . esc_html__('Toutes les dates', $d) . '</a></p>'
        . '<h2 class="passflow-portal-choice-title">' . esc_html($activity ? $activity['name'] : Lookup::activityName($choice['activity_id'])) . '</h2>'
        . '<p class="passflow-muted">' . esc_html(Lookup::slotWhen($choice)) . ($activity && $activity['location'] ? ' · ' . esc_html($activity['location']) : '') . '</p>'
        . '<div class="passflow-cta-row is-duo">'
        . $card(add_query_arg('creneau', (int) $choice['id'], $base_url), 'smartphone', __('Gestion mobile', $d), __('Appel, pointage et ajouts sur place depuis le téléphone ou PDA', $d))
        . ($tablet ? $card(\Smbb\Passflow\Admin\DayBoard::url((int) $choice['id']), 'tablet', __('Suivi tablette jour J', $d), __('Arrivées, retardataires et documents en plein écran, en direct', $d), ' passflow-tablet-cta') : '')
        . '</div>'
        . '<p><a href="' . esc_url(View::url('passflow_slot', array('view' => 'details', 'id' => (int) $choice['id']))) . '">' . esc_html__('Ouvrir la fiche de la date', $d) . '</a></p>';
    View::page('<div class="passflow-portal">' . $body . '</div>', $header, $notices, __('Mes ateliers', $d), 'mobile');
    return;
}

// Bouton « ticket » de la barre d'administration : choix de l'atelier du jour.
if (!empty($_GET['aujourdhui'])) {
    View::page('<div class="passflow-portal">' . \Smbb\Passflow\Admin\TodayBar::chooser() . '</div>', $header, $notices, __("Atelier du jour", $d), 'mobile');
    return;
}

if (!empty($_GET['dispos'])) {
    require __DIR__ . '/availabilities.php';
    return;
}

$today = current_time('Y-m-d');
$past = isset($_GET['periode']) && $_GET['periode'] === 'passees';
$filter = $host && !$manager ? array('host_id' => (int) $host['id']) : array();

if ($past) {
    $slots = array_reverse(Slots::between(date('Y-m-d', strtotime($today . ' -6 months')), date('Y-m-d', strtotime($today . ' -1 day')), $filter));
} else {
    $slots = Slots::between($today, null, $filter);
}

// Les brouillons ne sont pas encore en vente mais l'intervenant doit les voir ; les annulés restent visibles pour info.
$counts = Bookings::statusCounts(array_column($slots, 'id'));
$base = View::url('passflow_portal');

$name = $host ? ($host['first_name'] ?: Lookup::personName($host)) : wp_get_current_user()->display_name;
$html = '<p class="passflow-lead">' . esc_html(sprintf(__('Bonjour %s.', $d), $name)) . ' '
    . esc_html($manager && !$host ? __('Voici toutes les dates ; choisissez-en une pour faire l\'appel.', $d) : __('Voici vos dates ; choisissez-en une pour faire l\'appel.', $d)) . '</p>';

$html .= '<nav class="passflow-agenda-tabs">'
    . '<a class="' . ($past ? '' : 'is-on') . '" href="' . esc_url($base) . '">' . esc_html__('À venir', $d) . '</a>'
    . '<a class="' . ($past ? 'is-on' : '') . '" href="' . esc_url(add_query_arg('periode', 'passees', $base)) . '">' . esc_html__('Passées', $d) . '</a>'
    . ($host ? '<a href="' . esc_url(add_query_arg('dispos', 1, $base)) . '">' . esc_html__('Mes disponibilités', $d) . '</a>' : '')
    . '</nav>';

// Demande de dispo en cours : bandeau qui mène droit à la demande.
if ($host && !$past) {
    foreach (\Smbb\Passflow\Service\Campaigns::forHost((int) $host['id']) as $campaign) {
        if (\Smbb\Passflow\Service\Campaigns::openSlots($campaign)) {
            $html .= View::note(
                sprintf(__('Demande de dispo du %s', $d), date_i18n('j F Y', strtotime($campaign['sent_at']))),
                ($campaign['message'] !== '' ? '<p>' . esc_html($campaign['message']) . '</p>' : '')
                . '<p><a class="button button-primary" href="' . esc_url(\Smbb\Passflow\Service\Campaigns::link($campaign['id'])) . '">' . esc_html__('Répondre', $d) . '</a></p>',
                'dashicons-email-alt'
            );
            break;
        }
    }
}

if ($manager) {
    $html .= '<p class="passflow-actions"><a class="button" href="' . esc_url(View::actionUrl(Actions::AVAILABILITY_REQUEST, array('id' => 0))) . '" onclick="return confirm(this.dataset.smbbConfirm)" data-smbb-confirm="' . esc_attr__('Envoyer une demande de disponibilités à chaque intervenant qui a des dates à venir sans réponse ?', $d) . '"><span class="dashicons dashicons-email-alt" aria-hidden="true"></span> ' . esc_html__('Demander les dispos manquantes', $d) . '</a></p>';
}

if (!$slots) {
    $html .= View::card('', '<p class="passflow-muted">' . esc_html($past ? __('Aucune date ces six derniers mois.', $d) : __('Aucune date prévue pour le moment.', $d)) . '</p>');
}

$month = '';

foreach ($slots as $slot) {
    $time = strtotime($slot['start_date'] . ' 12:00:00');
    $label = date_i18n('F Y', $time);

    if ($label !== $month) {
        $html .= ($month !== '' ? '</div>' : '') . '<h2 class="passflow-agenda-month">' . esc_html(ucfirst($label)) . '</h2><div class="passflow-agenda">';
        $month = $label;
    }

    $activity = Lookup::atelier($slot['activity_id']);
    $c = isset($counts[(int) $slot['id']]) ? $counts[(int) $slot['id']] : array();
    $present = isset($c['present']) ? $c['present'] : 0;
    $absent = isset($c['absent']) ? $c['absent'] : 0;
    $todo = (isset($c['confirmed']) ? $c['confirmed'] : 0) + (isset($c['pending']) ? $c['pending'] : 0);
    $is_today = $slot['start_date'] === $today;
    $cancelled = $slot['status'] === 'cancelled';

    // État de l'appel : seulement à partir du jour de l'atelier.
    $call = '';

    if ($cancelled) {
        $call = Choices::slotBadge($slot);
    } elseif (Actions::rollCallOpen($slot) && ($present + $absent + $todo) > 0) {
        if ($todo === 0) {
            $call = '<span class="passflow-badge passflow-badge--present"><span class="dashicons dashicons-yes" aria-hidden="true"></span>' . esc_html(sprintf(__('Appel fait : %1$d présents, %2$d absents', $d), $present, $absent)) . '</span>';
        } elseif ($present + $absent > 0) {
            $call = '<span class="passflow-badge passflow-badge--pending">' . esc_html(sprintf(__('Appel en cours : %d à pointer', $d), $todo)) . '</span>';
        } else {
            $call = '<span class="passflow-badge passflow-badge--pending"><span class="dashicons dashicons-clock" aria-hidden="true"></span>' . esc_html__('Appel à faire', $d) . '</span>';
        }
    } elseif ($slot['status'] === 'draft') {
        $call = '<span class="passflow-badge passflow-badge--draft">' . esc_html__('Pas encore en vente', $d) . '</span>';
    }

    $meta = array(esc_html(Lookup::slotHours($slot, true, __('Toute la journée', $d))));

    if ($activity && $activity['location']) {
        $meta[] = esc_html($activity['location']);
    }

    if ($manager) {
        $names = Lookup::hostNames($slot['host_ids']);
        $meta[] = esc_html($names !== '' ? $names : __('Sans intervenant', $d));
    }

    $html .= '<a class="passflow-agenda-item' . ($is_today ? ' is-today' : '') . ($cancelled ? ' is-cancelled' : '') . '" href="' . esc_url($manager && !$cancelled && \Smbb\Passflow\Admin\DayBoard::available($slot) ? add_query_arg('choix', (int) $slot['id'], $base) : add_query_arg('creneau', (int) $slot['id'], $base)) . '">'
        . '<span class="passflow-agenda-date"><span>' . esc_html(date_i18n('D', $time)) . '</span><strong>' . esc_html(date_i18n('j', $time)) . '</strong><span>' . esc_html(date_i18n('M', $time)) . '</span></span>'
        . '<span class="passflow-agenda-main"><strong>' . esc_html($activity ? $activity['name'] : __('Atelier', $d)) . ($is_today ? ' <span class="passflow-badge passflow-badge--open">' . esc_html__("Aujourd'hui", $d) . '</span>' : '') . '</strong>'
        . '<span class="passflow-muted">' . implode(' · ', array_filter($meta)) . '</span>'
        . ($call !== '' ? '<span>' . $call . '</span>' : '') . '</span>'
        . '<span class="passflow-agenda-fill">' . ($cancelled ? '' : View::fill($slot['booked'], $slot['capacity'], $slot['waiting']) . View::seats($slot)) . '</span>'
        . '<span class="dashicons dashicons-arrow-right-alt2 passflow-agenda-go" aria-hidden="true"></span>'
        . '</a>';
}

if ($month !== '') {
    $html .= '</div>';
}

View::page('<div class="passflow-portal">' . $html . '</div>', $header, $notices, __('Mes ateliers', $d), 'mobile');
