<?php
/**
 * Espace intervenant › Mes disponibilités : les dates à venir où l'intervenant est prévu,
 * en liste ou sur un calendrier du mois, avec « Dispo » / « Pas dispo » enregistré tout de suite.
 *
 * Variables reçues de dashboard.php : $d, $header, $notices, $intervenant, $manager, $base.
 */

use Smbb\Tomo\Admin\View;
use Smbb\Tomo\Repository\Creneaux;
use Smbb\Tomo\Repository\Lookup;
use Smbb\Tomo\Service\Availability;
use Smbb\Tomo\Service\Campaigns;

defined('ABSPATH') || exit;

$base = View::url('tomo_espace');

if (!$intervenant) {
    View::page(View::card('', '<p>' . esc_html__('Cette page sert aux intervenants pour donner leurs disponibilités. Les réponses de chacun sont sur la fiche de chaque date.', $d) . '</p>'), $header, $notices, __('Mes disponibilités', $d));
    return;
}

$today = current_time('Y-m-d');
$planned = array_values(array_filter(
    Creneaux::between($today, null, array('intervenant_id' => (int) $intervenant['id'], 'statuses' => array('draft', 'open', 'full'))),
    array(Availability::class, 'open')
));

// Demandes groupées reçues (la plus récente d'abord) : seulement celles qui ont encore des dates à venir.
$requests = array();

foreach (Campaigns::forIntervenant((int) $intervenant['id']) as $campagne) {
    $campagne['open'] = Campaigns::openCreneaux($campagne);

    if ($campagne['open']) {
        $requests[] = $campagne;
    }
}

// Toutes les dates à afficher : prévues + demandées, sans doublon, dans l'ordre.
$creneaux = array();

foreach ($planned as $creneau) {
    $creneaux[(int) $creneau['id']] = $creneau;
}

$in_requests = array();

foreach ($requests as $campagne) {
    foreach ($campagne['open'] as $creneau) {
        $creneaux[(int) $creneau['id']] = $creneau;
        $in_requests[(int) $creneau['id']] = true;
    }
}

uasort($creneaux, function ($a, $b) {
    return strcmp($a['start_date'] . $a['start_time'], $b['start_date'] . $b['start_time']);
});
$creneaux = array_values($creneaux);
$answers = Availability::forCreneaux(array_column($creneaux, 'id'));

// En haut, seulement les demandes qui attendent encore une réponse : une fois tout répondu, elle disparaît.
$all_requests = $requests;
$requests = array_values(array_filter($requests, function ($campagne) use ($answers, $intervenant) {
    foreach ($campagne['open'] as $creneau) {
        $row = isset($answers[(int) $creneau['id']][(int) $intervenant['id']]) ? $answers[(int) $creneau['id']][(int) $intervenant['id']] : null;

        if (!in_array(Availability::state($row), array('yes', 'no'), true)) {
            return true;
        }
    }

    return false;
}));
$calendar = isset($_GET['vue']) && $_GET['vue'] === 'calendrier';
$state_of = function ($creneau) use ($answers, $intervenant) {
    $row = isset($answers[(int) $creneau['id']][(int) $intervenant['id']]) ? $answers[(int) $creneau['id']][(int) $intervenant['id']] : null;

    return Availability::state($row);
};

// Les deux boutons d'une date ; l'état courant est mis en avant.
$buttons = function ($creneau, $compact = false) use ($state_of, $d) {
    $state = $state_of($creneau);

    return '<span class="tomo-dispo-choice' . ($compact ? ' is-compact' : '') . '" data-dispo-creneau="' . (int) $creneau['id'] . '" data-dispo-state="' . esc_attr($state) . '">'
        . '<button type="button" class="tomo-dispo-btn tomo-dispo-btn--yes' . ($state === 'yes' ? ' is-on' : '') . '" data-answer="yes" aria-pressed="' . ($state === 'yes' ? 'true' : 'false') . '">' . ($compact ? '✓' : esc_html__('Dispo', $d)) . '</button>'
        . '<button type="button" class="tomo-dispo-btn tomo-dispo-btn--no' . ($state === 'no' ? ' is-on' : '') . '" data-answer="no" aria-pressed="' . ($state === 'no' ? 'true' : 'false') . '">' . ($compact ? '✗' : esc_html__('Pas dispo', $d)) . '</button>'
        . '</span>';
};

$todo = count(array_filter($creneaux, function ($creneau) use ($state_of) {
    return !in_array($state_of($creneau), array('yes', 'no'), true);
}));

$html = '<p class="tomo-lead">' . esc_html($todo
    ? sprintf(_n('%d date attend votre réponse. Un clic suffit, c\'est enregistré tout de suite.', '%d dates attendent votre réponse. Un clic suffit, c\'est enregistré tout de suite.', $todo, $d), $todo)
    : __('Merci, vous avez répondu pour toutes vos dates. Vous pouvez changer d\'avis tant que la date n\'est pas passée.', $d)) . '</p>';

$html .= '<nav class="tomo-agenda-tabs">'
    . '<a href="' . esc_url($base) . '">' . esc_html__('À venir', $d) . '</a>'
    . '<a class="' . ($calendar ? '' : 'is-on') . '" href="' . esc_url(add_query_arg('dispos', 1, $base)) . '">' . esc_html__('Disponibilités : liste', $d) . '</a>'
    . '<a class="' . ($calendar ? 'is-on' : '') . '" href="' . esc_url(add_query_arg(array('dispos' => 1, 'vue' => 'calendrier'), $base)) . '">' . esc_html__('Calendrier', $d) . '</a>'
    . '</nav>';

if (!$creneaux) {
    $html .= View::card('', '<p class="tomo-muted">' . esc_html__('Aucune date à venir ne vous est proposée pour le moment.', $d) . '</p>');
} elseif (!$calendar) {
    // Une date de la liste : jour, atelier, horaires, lieu, boutons.
    $item = function ($creneau) use ($buttons, $d) {
        $time = strtotime($creneau['start_date'] . ' 12:00:00');
        $atelier = Lookup::atelier($creneau['atelier_id']);
        $meta = array(Lookup::slotHours($creneau, true, __('Toute la journée', $d)));

        if ($atelier && $atelier['location']) {
            $meta[] = $atelier['location'];
        }

        return '<div class="tomo-agenda-item tomo-dispo-item">'
            . '<span class="tomo-agenda-date"><span>' . esc_html(date_i18n('D', $time)) . '</span><strong>' . esc_html(date_i18n('j', $time)) . '</strong><span>' . esc_html(date_i18n('M', $time)) . '</span></span>'
            . '<span class="tomo-agenda-main"><strong>' . esc_html($atelier ? $atelier['name'] : '') . '</strong><span class="tomo-muted">' . esc_html(implode(' · ', array_filter($meta))) . '</span></span>'
            . $buttons($creneau)
            . '</div>';
    };

    // Lien d'une demande qui n'apparaît pas ici : on dit pourquoi.
    $asked = isset($_GET['demande']) ? absint($_GET['demande']) : 0;

    if ($asked && !in_array($asked, array_map('intval', array_column($requests, 'id')), true)) {
        $wanted = Campaigns::find($asked);

        if (!$wanted) {
            $why = __('Cette demande est introuvable.', $d);
        } elseif (in_array($asked, array_map('intval', array_column($all_requests, 'id')), true)) {
            $why = __("Merci, vous avez déjà répondu à toutes les dates de cette demande. Vos réponses sont dans la liste ci-dessous, vous pouvez encore les changer.", $d);
        } elseif (!in_array((int) $intervenant['id'], $wanted['intervenant_ids'], true)) {
            $why = sprintf(__("Cette demande n'est pas adressée à %1\$s (le compte connecté). Elle a été envoyée à : %2\$s.", $d), Lookup::personName($intervenant), Lookup::intervenantNames($wanted['intervenant_ids']));
        } else {
            $why = __('Toutes les dates de cette demande sont passées ou annulées.', $d);
        }

        $html .= View::note(__('Demande de dispo', $d), '<p>' . esc_html($why) . '</p>', 'dashicons-info-outline');
    }

    // 1. Les demandes, la plus récente en haut.
    foreach ($requests as $campagne) {
        $html .= '<section class="tomo-camp-request" id="demande-' . (int) $campagne['id'] . '">'
            . '<h2>' . esc_html(sprintf(__('Demande du %s', $d), date_i18n('j F Y', strtotime($campagne['sent_at'])))) . '</h2>'
            . ($campagne['message'] !== '' ? '<div class="tomo-camp-message"><strong>' . esc_html__('Message :', $d) . '</strong> ' . esc_html($campagne['message']) . '</div>' : '')
            . '<div class="tomo-agenda">';

        foreach ($campagne['open'] as $creneau) {
            $html .= $item($creneau);
        }

        $html .= '</div>'
            . '<form class="tomo-camp-reply" data-camp-reply="' . (int) $campagne['id'] . '">'
            . '<label class="screen-reader-text" for="tomo-camp-reply-' . (int) $campagne['id'] . '">' . esc_html__('Répondre', $d) . '</label>'
            . '<textarea id="tomo-camp-reply-' . (int) $campagne['id'] . '" name="reply" rows="2" maxlength="1000" placeholder="' . esc_attr__('Répondre : une précision, une contrainte… (facultatif)', $d) . '">' . esc_textarea((string) $campagne['reply']) . '</textarea>'
            . '<button type="submit" class="button button-primary">' . esc_html__('Envoyer', $d) . '</button>'
            . '<span class="tomo-camp-reply-state" data-camp-reply-state>' . ($campagne['replied_at'] ? esc_html(sprintf(__('Message envoyé le %s', $d), View::datetime($campagne['replied_at']))) : '') . '</span>'
            . '</form>'
            . '</section>';
    }

    Campaigns::markSeen(array_column($requests, 'dest_id'));

    // 2. Toutes les dates (prévues et demandées), par mois.
    $others = $creneaux;

    if ($others) {
        $html .= $requests ? '<h2 class="tomo-dispo-others-title">' . esc_html__('Toutes mes disponibilités', $d) . '</h2>' : '';
        $month = '';

        foreach ($others as $creneau) {
            $label = date_i18n('F Y', strtotime($creneau['start_date'] . ' 12:00:00'));

            if ($label !== $month) {
                $html .= ($month !== '' ? '</div>' : '') . '<h3 class="tomo-agenda-month">' . esc_html(ucfirst($label)) . '</h3><div class="tomo-agenda">';
                $month = $label;
            }

            $html .= $item($creneau);
        }

        $html .= '</div>';
    }
} else {
    // Calendrier du mois (?mois=AAAA-MM), du lundi au dimanche.
    $by_day = array();

    foreach ($creneaux as $creneau) {
        $by_day[$creneau['start_date']][] = $creneau;
    }

    $requested = isset($_GET['mois']) && preg_match('/^\d{4}-\d{2}$/', (string) $_GET['mois']) ? (string) $_GET['mois'] : substr($creneaux[0]['start_date'], 0, 7);
    $first = strtotime($requested . '-01 12:00:00');
    $prev = date('Y-m', strtotime('-1 month', $first));
    $next = date('Y-m', strtotime('+1 month', $first));
    $url = function ($month) use ($base) {
        return add_query_arg(array('dispos' => 1, 'vue' => 'calendrier', 'mois' => $month), $base);
    };

    $html .= '<div class="tomo-dispo-cal-nav"><a class="button" href="' . esc_url($url($prev)) . '">‹</a> <strong>' . esc_html(ucfirst(date_i18n('F Y', $first))) . '</strong> <a class="button" href="' . esc_url($url($next)) . '">›</a></div>';
    $html .= '<div class="tomo-dispo-cal"><div class="tomo-dispo-cal-head">';

    foreach (array(__('Lun', $d), __('Mar', $d), __('Mer', $d), __('Jeu', $d), __('Ven', $d), __('Sam', $d), __('Dim', $d)) as $day_name) {
        $html .= '<span>' . esc_html($day_name) . '</span>';
    }

    $html .= '</div><div class="tomo-dispo-cal-grid">';
    $offset = (int) date('N', $first) - 1;
    $days = (int) date('t', $first);

    for ($i = 0; $i < $offset; $i++) {
        $html .= '<div class="tomo-dispo-cal-cell is-empty"></div>';
    }

    for ($day = 1; $day <= $days; $day++) {
        $date = $requested . '-' . sprintf('%02d', $day);
        $items = isset($by_day[$date]) ? $by_day[$date] : array();
        $html .= '<div class="tomo-dispo-cal-cell' . ($items ? ' has-dates' : '') . ($date === $today ? ' is-today' : '') . '"><span class="tomo-dispo-cal-day">' . $day . '</span>';

        foreach ($items as $creneau) {
            $atelier = Lookup::atelier($creneau['atelier_id']);
            $html .= '<div class="tomo-dispo-cal-item"><span class="tomo-dispo-cal-label">' . esc_html(implode(' · ', array_filter(array($atelier ? $atelier['name'] : '', Lookup::slotHours($creneau, false))))) . '</span>' . $buttons($creneau, true) . '</div>';
        }

        $html .= '</div>';
    }

    $html .= '</div></div>';
}

wp_enqueue_style('smbb-tomo-campagnes', SMBB_TOMO_URL . 'assets/campagnes.css', array(), SMBB_TOMO_VERSION);
wp_enqueue_script('tomo-dispos', SMBB_TOMO_URL . 'assets/dispos.js', array(), SMBB_TOMO_VERSION, true);
wp_add_inline_script('tomo-dispos', 'window.tomoDispos=' . wp_json_encode(array(
    'url' => admin_url('admin-ajax.php'),
    'action' => Availability::AJAX,
    'nonce' => Availability::nonce(),
    'replyAction' => Campaigns::AJAX_REPLY,
    'replyNonce' => Campaigns::nonce(),
    'texts' => array('error' => __("Pas enregistré, réessayez.", $d), 'network' => __('Pas de connexion : réessayez.', $d), 'sending' => __('Envoi…', $d), 'done' => __('Merci, tout est répondu : cette demande disparaîtra de la page à votre prochain passage.', $d)),
)) . ';', 'before');

View::page('<div class="tomo-espace">' . $html . '</div>', $header, $notices, __('Mes disponibilités', $d));
