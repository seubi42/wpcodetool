<?php
/**
 * Espace intervenant › Mes disponibilités : les dates à venir où l'intervenant est prévu,
 * en liste ou sur un calendrier du mois, avec « Dispo » / « Pas dispo » enregistré tout de suite.
 *
 * Variables reçues de dashboard.php : $d, $header, $notices, $host, $manager, $base.
 */

use Smbb\Passflow\Admin\View;
use Smbb\Passflow\Repository\Slots;
use Smbb\Passflow\Repository\Lookup;
use Smbb\Passflow\Service\Availability;
use Smbb\Passflow\Service\Campaigns;

defined('ABSPATH') || exit;

$base = View::url('passflow_portal');

if (!$host) {
    View::page(View::card('', '<p>' . esc_html__('Cette page sert aux intervenants pour donner leurs disponibilités. Les réponses de chacun sont sur la fiche de chaque date.', $d) . '</p>'), $header, $notices, __('Mes disponibilités', $d), 'mobile');
    return;
}

$today = current_time('Y-m-d');
$planned = array_values(array_filter(
    Slots::between($today, null, array('host_id' => (int) $host['id'], 'statuses' => array('draft', 'open', 'full'))),
    array(Availability::class, 'open')
));

// Demandes groupées reçues (la plus récente d'abord) : seulement celles qui ont encore des dates à venir.
$requests = array();

foreach (Campaigns::forHost((int) $host['id']) as $campaign) {
    $campaign['open'] = Campaigns::openSlots($campaign);

    if ($campaign['open']) {
        $requests[] = $campaign;
    }
}

// Toutes les dates à afficher : prévues + demandées, sans doublon, dans l'ordre.
$slots = array();

foreach ($planned as $slot) {
    $slots[(int) $slot['id']] = $slot;
}

$in_requests = array();

foreach ($requests as $campaign) {
    foreach ($campaign['open'] as $slot) {
        $slots[(int) $slot['id']] = $slot;
        $in_requests[(int) $slot['id']] = true;
    }
}

uasort($slots, function ($a, $b) {
    return strcmp($a['start_date'] . $a['start_time'], $b['start_date'] . $b['start_time']);
});
$slots = array_values($slots);
$answers = Availability::forSlots(array_column($slots, 'id'));

// En haut, seulement les demandes qui attendent encore une réponse : une fois tout répondu, elle disparaît.
$all_requests = $requests;
$requests = array_values(array_filter($requests, function ($campaign) use ($answers, $host) {
    foreach ($campaign['open'] as $slot) {
        $row = isset($answers[(int) $slot['id']][(int) $host['id']]) ? $answers[(int) $slot['id']][(int) $host['id']] : null;

        if (!in_array(Availability::state($row), array('yes', 'no'), true)) {
            return true;
        }
    }

    return false;
}));
$calendar = isset($_GET['vue']) && $_GET['vue'] === 'calendrier';
$state_of = function ($slot) use ($answers, $host) {
    $row = isset($answers[(int) $slot['id']][(int) $host['id']]) ? $answers[(int) $slot['id']][(int) $host['id']] : null;

    return Availability::state($row);
};

// Les deux boutons d'une date ; l'état courant est mis en avant.
$buttons = function ($slot, $compact = false) use ($state_of, $d) {
    $state = $state_of($slot);

    return '<span class="passflow-availability-choice' . ($compact ? ' is-compact' : '') . '" data-availability-slot="' . (int) $slot['id'] . '" data-availability-state="' . esc_attr($state) . '">'
        . '<button type="button" class="passflow-availability-btn passflow-availability-btn--yes' . ($state === 'yes' ? ' is-on' : '') . '" data-answer="yes" aria-pressed="' . ($state === 'yes' ? 'true' : 'false') . '">' . ($compact ? '✓' : esc_html__('Dispo', $d)) . '</button>'
        . '<button type="button" class="passflow-availability-btn passflow-availability-btn--no' . ($state === 'no' ? ' is-on' : '') . '" data-answer="no" aria-pressed="' . ($state === 'no' ? 'true' : 'false') . '">' . ($compact ? '✗' : esc_html__('Pas dispo', $d)) . '</button>'
        . '</span>';
};

$todo = count(array_filter($slots, function ($slot) use ($state_of) {
    return !in_array($state_of($slot), array('yes', 'no'), true);
}));

$html = '<p class="passflow-lead">' . esc_html($todo
    ? sprintf(_n('%d date attend votre réponse. Un clic suffit, c\'est enregistré tout de suite.', '%d dates attendent votre réponse. Un clic suffit, c\'est enregistré tout de suite.', $todo, $d), $todo)
    : __('Merci, vous avez répondu pour toutes vos dates. Vous pouvez changer d\'avis tant que la date n\'est pas passée.', $d)) . '</p>';

$html .= '<nav class="passflow-agenda-tabs">'
    . '<a href="' . esc_url($base) . '">' . esc_html__('À venir', $d) . '</a>'
    . '<a class="' . ($calendar ? '' : 'is-on') . '" href="' . esc_url(add_query_arg('dispos', 1, $base)) . '">' . esc_html__('Disponibilités : liste', $d) . '</a>'
    . '<a class="' . ($calendar ? 'is-on' : '') . '" href="' . esc_url(add_query_arg(array('dispos' => 1, 'vue' => 'calendrier'), $base)) . '">' . esc_html__('Calendrier', $d) . '</a>'
    . '</nav>';

if (!$slots) {
    $html .= View::card('', '<p class="passflow-muted">' . esc_html__('Aucune date à venir ne vous est proposée pour le moment.', $d) . '</p>');
} elseif (!$calendar) {
    // Une date de la liste : jour, atelier, horaires, lieu, boutons.
    $item = function ($slot) use ($buttons, $d) {
        $time = strtotime($slot['start_date'] . ' 12:00:00');
        $activity = Lookup::atelier($slot['activity_id']);
        $meta = array(Lookup::slotHours($slot, true, __('Toute la journée', $d)));

        if ($activity && $activity['location']) {
            $meta[] = $activity['location'];
        }

        return '<div class="passflow-agenda-item passflow-availability-item">'
            . '<span class="passflow-agenda-date"><span>' . esc_html(date_i18n('D', $time)) . '</span><strong>' . esc_html(date_i18n('j', $time)) . '</strong><span>' . esc_html(date_i18n('M', $time)) . '</span></span>'
            . '<span class="passflow-agenda-main"><strong>' . esc_html($activity ? $activity['name'] : '') . '</strong><span class="passflow-muted">' . esc_html(implode(' · ', array_filter($meta))) . '</span></span>'
            . $buttons($slot)
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
        } elseif (!in_array((int) $host['id'], $wanted['host_ids'], true)) {
            $why = sprintf(__("Cette demande n'est pas adressée à %1\$s (le compte connecté). Elle a été envoyée à : %2\$s.", $d), Lookup::personName($host), Lookup::hostNames($wanted['host_ids']));
        } else {
            $why = __('Toutes les dates de cette demande sont passées ou annulées.', $d);
        }

        $html .= View::note(__('Demande de dispo', $d), '<p>' . esc_html($why) . '</p>', 'dashicons-info-outline');
    }

    // 1. Les demandes, la plus récente en haut.
    foreach ($requests as $campaign) {
        $html .= '<section class="passflow-camp-request" id="demande-' . (int) $campaign['id'] . '">'
            . '<h2>' . esc_html(sprintf(__('Demande du %s', $d), date_i18n('j F Y', strtotime($campaign['sent_at'])))) . '</h2>'
            . ($campaign['message'] !== '' ? '<div class="passflow-camp-message"><strong>' . esc_html__('Message :', $d) . '</strong> ' . esc_html($campaign['message']) . '</div>' : '')
            . '<div class="passflow-agenda">';

        foreach ($campaign['open'] as $slot) {
            $html .= $item($slot);
        }

        $html .= '</div>'
            . '<form class="passflow-camp-reply" data-camp-reply="' . (int) $campaign['id'] . '">'
            . '<label class="screen-reader-text" for="passflow-camp-reply-' . (int) $campaign['id'] . '">' . esc_html__('Répondre', $d) . '</label>'
            . '<textarea id="passflow-camp-reply-' . (int) $campaign['id'] . '" name="reply" rows="2" maxlength="1000" placeholder="' . esc_attr__('Répondre : une précision, une contrainte… (facultatif)', $d) . '">' . esc_textarea((string) $campaign['reply']) . '</textarea>'
            . '<button type="submit" class="button button-primary">' . esc_html__('Envoyer', $d) . '</button>'
            . '<span class="passflow-camp-reply-state" data-camp-reply-state>' . ($campaign['replied_at'] ? esc_html(sprintf(__('Message envoyé le %s', $d), View::datetime($campaign['replied_at']))) : '') . '</span>'
            . '</form>'
            . '</section>';
    }

    Campaigns::markSeen(array_column($requests, 'dest_id'));

    // 2. Toutes les dates (prévues et demandées), par mois.
    $others = $slots;

    if ($others) {
        $html .= $requests ? '<h2 class="passflow-availability-others-title">' . esc_html__('Toutes mes disponibilités', $d) . '</h2>' : '';
        $month = '';

        foreach ($others as $slot) {
            $label = date_i18n('F Y', strtotime($slot['start_date'] . ' 12:00:00'));

            if ($label !== $month) {
                $html .= ($month !== '' ? '</div>' : '') . '<h3 class="passflow-agenda-month">' . esc_html(ucfirst($label)) . '</h3><div class="passflow-agenda">';
                $month = $label;
            }

            $html .= $item($slot);
        }

        $html .= '</div>';
    }
} else {
    // Calendrier du mois (?mois=AAAA-MM), du lundi au dimanche.
    $by_day = array();

    foreach ($slots as $slot) {
        $by_day[$slot['start_date']][] = $slot;
    }

    $requested = isset($_GET['mois']) && preg_match('/^\d{4}-\d{2}$/', (string) $_GET['mois']) ? (string) $_GET['mois'] : substr($slots[0]['start_date'], 0, 7);
    $first = strtotime($requested . '-01 12:00:00');
    $prev = date('Y-m', strtotime('-1 month', $first));
    $next = date('Y-m', strtotime('+1 month', $first));
    $url = function ($month) use ($base) {
        return add_query_arg(array('dispos' => 1, 'vue' => 'calendrier', 'mois' => $month), $base);
    };

    $html .= '<div class="passflow-availability-cal-nav"><a class="button" href="' . esc_url($url($prev)) . '">‹</a> <strong>' . esc_html(ucfirst(date_i18n('F Y', $first))) . '</strong> <a class="button" href="' . esc_url($url($next)) . '">›</a></div>';
    $html .= '<div class="passflow-availability-cal"><div class="passflow-availability-cal-head">';

    foreach (array(__('Lun', $d), __('Mar', $d), __('Mer', $d), __('Jeu', $d), __('Ven', $d), __('Sam', $d), __('Dim', $d)) as $day_name) {
        $html .= '<span>' . esc_html($day_name) . '</span>';
    }

    $html .= '</div><div class="passflow-availability-cal-grid">';
    $offset = (int) date('N', $first) - 1;
    $days = (int) date('t', $first);

    for ($i = 0; $i < $offset; $i++) {
        $html .= '<div class="passflow-availability-cal-cell is-empty"></div>';
    }

    for ($day = 1; $day <= $days; $day++) {
        $date = $requested . '-' . sprintf('%02d', $day);
        $items = isset($by_day[$date]) ? $by_day[$date] : array();
        $html .= '<div class="passflow-availability-cal-cell' . ($items ? ' has-dates' : '') . ($date === $today ? ' is-today' : '') . '"><span class="passflow-availability-cal-day">' . $day . '</span>';

        foreach ($items as $slot) {
            $activity = Lookup::atelier($slot['activity_id']);
            $html .= '<div class="passflow-availability-cal-item"><span class="passflow-availability-cal-label">' . esc_html(implode(' · ', array_filter(array($activity ? $activity['name'] : '', Lookup::slotHours($slot, false))))) . '</span>' . $buttons($slot, true) . '</div>';
        }

        $html .= '</div>';
    }

    $html .= '</div></div>';
}

wp_enqueue_style('smbb-passflow-campaigns', SMBB_PASSFLOW_URL . 'assets/campaigns.css', array(), SMBB_PASSFLOW_VERSION);
wp_enqueue_script('passflow-availabilities', SMBB_PASSFLOW_URL . 'assets/availabilities.js', array(), SMBB_PASSFLOW_VERSION, true);
wp_add_inline_script('passflow-availabilities', 'window.passflowAvailabilities=' . wp_json_encode(array(
    'url' => admin_url('admin-ajax.php'),
    'action' => Availability::AJAX,
    'nonce' => Availability::nonce(),
    'replyAction' => Campaigns::AJAX_REPLY,
    'replyNonce' => Campaigns::nonce(),
    'texts' => array('error' => __("Pas enregistré, réessayez.", $d), 'network' => __('Pas de connexion : réessayez.', $d), 'sending' => __('Envoi…', $d), 'done' => __('Merci, tout est répondu : cette demande disparaîtra de la page à votre prochain passage.', $d)),
)) . ';', 'before');

View::page('<div class="passflow-portal">' . $html . '</div>', $header, $notices, __('Mes disponibilités', $d), 'mobile');
