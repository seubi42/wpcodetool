<?php
/**
 * Espace intervenant : l'agenda de ses dates (remplissage, état de l'appel) et, pour une date,
 * l'écran d'appel (?creneau=ID). Un gestionnaire voit toutes les dates.
 */

use Smbb\Tomo\Admin\Actions;
use Smbb\Tomo\Admin\View;
use Smbb\Tomo\Repository\Creneaux;
use Smbb\Tomo\Repository\Inscriptions;
use Smbb\Tomo\Repository\Lookup;
use Smbb\Tomo\Support\Choices;
use Smbb\Tomo\Support\Roles;
use Smbb\Tomo\Support\Schema;

defined('ABSPATH') || exit;

$d = 'smbb-tomo';
$header = isset($page_header_html) ? $page_header_html : '';
$notices = isset($notices_html) ? $notices_html : '';

if (!Schema::ready()) {
    View::schemaMissing($header, $notices, __('Mes ateliers', $d));
    return;
}

$intervenant = Lookup::intervenantForUser(get_current_user_id());
$manager = Roles::canManage();

if (!$intervenant && !$manager) {
    View::page(View::card('', '<p>' . esc_html(sprintf(__("Votre compte n'est rattaché à aucun intervenant. Demandez à %s de le rattacher depuis la fiche intervenant.", $d), \Smbb\Tomo\Support\Settings::businessName())) . '</p>'), $header, $notices, __('Mes ateliers', $d));
    return;
}

$creneau_id = isset($_GET['creneau']) ? absint($_GET['creneau']) : 0;

if ($creneau_id) {
    require __DIR__ . '/appel.php';
    return;
}

// Bouton « ticket » de la barre d'administration : choix de l'atelier du jour.
if (!empty($_GET['aujourdhui'])) {
    View::page('<div class="tomo-espace">' . \Smbb\Tomo\Admin\TodayBar::chooser() . '</div>', $header, $notices, __("Atelier du jour", $d));
    return;
}

if (!empty($_GET['dispos'])) {
    require __DIR__ . '/dispos.php';
    return;
}

$today = current_time('Y-m-d');
$past = isset($_GET['periode']) && $_GET['periode'] === 'passees';
$filter = $intervenant && !$manager ? array('intervenant_id' => (int) $intervenant['id']) : array();

if ($past) {
    $creneaux = array_reverse(Creneaux::between(date('Y-m-d', strtotime($today . ' -6 months')), date('Y-m-d', strtotime($today . ' -1 day')), $filter));
} else {
    $creneaux = Creneaux::between($today, null, $filter);
}

// Les brouillons ne sont pas encore en vente mais l'intervenant doit les voir ; les annulés restent visibles pour info.
$counts = Inscriptions::statusCounts(array_column($creneaux, 'id'));
$base = View::url('tomo_espace');

$name = $intervenant ? ($intervenant['first_name'] ?: Lookup::personName($intervenant)) : wp_get_current_user()->display_name;
$html = '<p class="tomo-lead">' . esc_html(sprintf(__('Bonjour %s.', $d), $name)) . ' '
    . esc_html($manager && !$intervenant ? __('Voici toutes les dates ; choisissez-en une pour faire l\'appel.', $d) : __('Voici vos dates ; choisissez-en une pour faire l\'appel.', $d)) . '</p>';

$html .= '<nav class="tomo-agenda-tabs">'
    . '<a class="' . ($past ? '' : 'is-on') . '" href="' . esc_url($base) . '">' . esc_html__('À venir', $d) . '</a>'
    . '<a class="' . ($past ? 'is-on' : '') . '" href="' . esc_url(add_query_arg('periode', 'passees', $base)) . '">' . esc_html__('Passées', $d) . '</a>'
    . ($intervenant ? '<a href="' . esc_url(add_query_arg('dispos', 1, $base)) . '">' . esc_html__('Mes disponibilités', $d) . '</a>' : '')
    . '</nav>';

// Demande de dispo en cours : bandeau qui mène droit à la demande.
if ($intervenant && !$past) {
    foreach (\Smbb\Tomo\Service\Campaigns::forIntervenant((int) $intervenant['id']) as $campagne) {
        if (\Smbb\Tomo\Service\Campaigns::openCreneaux($campagne)) {
            $html .= View::note(
                sprintf(__('Demande de dispo du %s', $d), date_i18n('j F Y', strtotime($campagne['sent_at']))),
                ($campagne['message'] !== '' ? '<p>' . esc_html($campagne['message']) . '</p>' : '')
                . '<p><a class="button button-primary" href="' . esc_url(\Smbb\Tomo\Service\Campaigns::link($campagne['id'])) . '">' . esc_html__('Répondre', $d) . '</a></p>',
                'dashicons-email-alt'
            );
            break;
        }
    }
}

if ($manager) {
    $html .= '<p class="tomo-actions"><a class="button" href="' . esc_url(View::actionUrl(Actions::DISPO_REQUEST, array('id' => 0))) . '" onclick="return confirm(this.dataset.smbbConfirm)" data-smbb-confirm="' . esc_attr__('Envoyer une demande de disponibilités à chaque intervenant qui a des dates à venir sans réponse ?', $d) . '"><span class="dashicons dashicons-email-alt" aria-hidden="true"></span> ' . esc_html__('Demander les dispos manquantes', $d) . '</a></p>';
}

if (!$creneaux) {
    $html .= View::card('', '<p class="tomo-muted">' . esc_html($past ? __('Aucune date ces six derniers mois.', $d) : __('Aucune date prévue pour le moment.', $d)) . '</p>');
}

$month = '';

foreach ($creneaux as $creneau) {
    $time = strtotime($creneau['start_date'] . ' 12:00:00');
    $label = date_i18n('F Y', $time);

    if ($label !== $month) {
        $html .= ($month !== '' ? '</div>' : '') . '<h2 class="tomo-agenda-month">' . esc_html(ucfirst($label)) . '</h2><div class="tomo-agenda">';
        $month = $label;
    }

    $atelier = Lookup::atelier($creneau['atelier_id']);
    $c = isset($counts[(int) $creneau['id']]) ? $counts[(int) $creneau['id']] : array();
    $present = isset($c['present']) ? $c['present'] : 0;
    $absent = isset($c['absent']) ? $c['absent'] : 0;
    $todo = (isset($c['confirmed']) ? $c['confirmed'] : 0) + (isset($c['pending']) ? $c['pending'] : 0);
    $is_today = $creneau['start_date'] === $today;
    $cancelled = $creneau['status'] === 'cancelled';

    // État de l'appel : seulement à partir du jour de l'atelier.
    $call = '';

    if ($cancelled) {
        $call = Choices::creneauBadge($creneau);
    } elseif (Actions::rollCallOpen($creneau) && ($present + $absent + $todo) > 0) {
        if ($todo === 0) {
            $call = '<span class="tomo-badge tomo-badge--present"><span class="dashicons dashicons-yes" aria-hidden="true"></span>' . esc_html(sprintf(__('Appel fait : %1$d présents, %2$d absents', $d), $present, $absent)) . '</span>';
        } elseif ($present + $absent > 0) {
            $call = '<span class="tomo-badge tomo-badge--pending">' . esc_html(sprintf(__('Appel en cours : %d à pointer', $d), $todo)) . '</span>';
        } else {
            $call = '<span class="tomo-badge tomo-badge--pending"><span class="dashicons dashicons-clock" aria-hidden="true"></span>' . esc_html__('Appel à faire', $d) . '</span>';
        }
    } elseif ($creneau['status'] === 'draft') {
        $call = '<span class="tomo-badge tomo-badge--draft">' . esc_html__('Pas encore en vente', $d) . '</span>';
    }

    $meta = array(esc_html(Lookup::slotHours($creneau, true, __('Toute la journée', $d))));

    if ($atelier && $atelier['location']) {
        $meta[] = esc_html($atelier['location']);
    }

    if ($manager) {
        $names = Lookup::intervenantNames($creneau['intervenant_ids']);
        $meta[] = esc_html($names !== '' ? $names : __('Sans intervenant', $d));
    }

    $html .= '<a class="tomo-agenda-item' . ($is_today ? ' is-today' : '') . ($cancelled ? ' is-cancelled' : '') . '" href="' . esc_url(add_query_arg('creneau', (int) $creneau['id'], $base)) . '">'
        . '<span class="tomo-agenda-date"><span>' . esc_html(date_i18n('D', $time)) . '</span><strong>' . esc_html(date_i18n('j', $time)) . '</strong><span>' . esc_html(date_i18n('M', $time)) . '</span></span>'
        . '<span class="tomo-agenda-main"><strong>' . esc_html($atelier ? $atelier['name'] : __('Atelier', $d)) . ($is_today ? ' <span class="tomo-badge tomo-badge--open">' . esc_html__("Aujourd'hui", $d) . '</span>' : '') . '</strong>'
        . '<span class="tomo-muted">' . implode(' · ', array_filter($meta)) . '</span>'
        . ($call !== '' ? '<span>' . $call . '</span>' : '') . '</span>'
        . '<span class="tomo-agenda-fill">' . ($cancelled ? '' : View::fill($creneau['booked'], $creneau['capacity'], $creneau['waiting']) . View::seats($creneau)) . '</span>'
        . '<span class="dashicons dashicons-arrow-right-alt2 tomo-agenda-go" aria-hidden="true"></span>'
        . '</a>';
}

if ($month !== '') {
    $html .= '</div>';
}

View::page('<div class="tomo-espace">' . $html . '</div>', $header, $notices, __('Mes ateliers', $d));
