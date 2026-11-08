<?php
/**
 * Demandes de dispo : liste des demandes envoyées, nouvelle demande (arbre ateliers / dates,
 * intervenants, message court) et suivi d'une demande (vu, réponses en %, messages, relance).
 */

use Smbb\Passflow\Admin\View;
use Smbb\Passflow\Repository\Lookup;
use Smbb\Passflow\Service\Availability;
use Smbb\Passflow\Service\Campaigns;
use Smbb\Passflow\Support\Schema;

defined('ABSPATH') || exit;

$d = 'smbb-passflow';
$header = isset($page_header_html) ? $page_header_html : '';
$notices = isset($notices_html) ? $notices_html : '';
$title = __('Demandes de dispo', $d);

if (!Schema::ready()) {
    View::schemaMissing($header, $notices, $title);
    return;
}

wp_enqueue_style('smbb-passflow-campaigns', SMBB_PASSFLOW_URL . 'assets/campaigns.css', array(), SMBB_PASSFLOW_VERSION);
$list_url = View::url('passflow_campaigns');
$people_all = Lookup::intervenants();
$pct = function ($part, $total) {
    return $total > 0 ? (int) round($part * 100 / $total) : 0;
};

// --- Nouvelle demande -------------------------------------------------------
if (!empty($_GET['nouvelle'])) {
    $draft = !empty($_GET['brouillon']) ? get_transient('smbb_passflow_campaign_draft_' . get_current_user_id()) : null;
    $draft = is_array($draft) ? $draft : array();
    $checked_dates = isset($draft['slot_ids']) ? array_map('intval', $draft['slot_ids']) : (isset($_GET['creneaux']) ? Lookup::ids(explode(',', sanitize_text_field(wp_unslash($_GET['creneaux'])))) : array());
    $checked_people = isset($draft['host_ids']) ? array_map('intval', $draft['host_ids']) : array();
    $channels = isset($draft['channels']) ? (array) $draft['channels'] : array('email', 'sms');
    $groups = Campaigns::tree();

    $html = '<p><a href="' . esc_url($list_url) . '">← ' . esc_html__('Toutes les demandes', $d) . '</a></p>';

    if (!$groups) {
        $html .= View::card('', '<p class="passflow-muted">' . esc_html__('Aucune date à venir pour le moment : créez d\'abord les dates dans Créneaux.', $d) . '</p>');
        View::page($html, $header, $notices, __('Nouvelle demande de dispo', $d));
        return;
    }

    // 1. Les dates : un dossier par atelier, une ligne par date avec les réponses déjà connues.
    $all_ids = array();

    foreach ($groups as $group) {
        $all_ids = array_merge($all_ids, array_map('intval', array_column($group['creneaux'], 'id')));
    }

    $known = Availability::forSlots($all_ids);
    $names = function (array $ids, $class, $mark = '') use ($people_all) {
        $out = '';

        foreach ($ids as $id) {
            $out .= '<span class="passflow-camp-who ' . esc_attr($class) . '">' . ($mark !== '' ? '<span class="passflow-camp-q" aria-hidden="true">' . esc_html($mark) . '</span>' : '<span class="passflow-camp-dot" aria-hidden="true"></span>')
                . esc_html(isset($people_all[$id]) ? Lookup::personName($people_all[$id]) : '#' . $id) . '</span>';
        }

        return $out;
    };

    $tree = '<div class="passflow-camp-tools"><button type="button" class="button button-small" data-camp-all="dates">' . esc_html__('Tout cocher', $d) . '</button> '
        . '<button type="button" class="button button-small" data-camp-none="dates">' . esc_html__('Tout décocher', $d) . '</button> '
        . '<button type="button" class="button button-small" data-camp-nobody>' . esc_html__('Cocher les créneaux sans intervenant dispo', $d) . '</button> '
        . '<span class="passflow-muted" data-camp-count="dates"></span></div>'
        . '<div class="passflow-camp-legend passflow-muted"><span class="passflow-camp-who is-yes"><span class="passflow-camp-dot"></span>' . esc_html__('dispo', $d) . '</span>'
        . '<span class="passflow-camp-who is-no"><span class="passflow-camp-dot"></span>' . esc_html__('pas dispo', $d) . '</span>'
        . '<span class="passflow-camp-who is-wait"><span class="passflow-camp-q">?</span>' . esc_html__('prévu ou demandé, sans réponse', $d) . '</span></div>'
        . '<ul class="passflow-camp-tree" data-camp-group="dates">';

    foreach ($groups as $activity_id => $group) {
        $name = $group['atelier'] ? $group['atelier']['name'] : __('Sans atelier', $d);
        $open = !$checked_dates || array_intersect($checked_dates, array_map('intval', array_column($group['creneaux'], 'id')));
        $tree .= '<li class="passflow-camp-folder' . ($open ? ' is-open' : '') . '"><div class="passflow-camp-folder-head">'
            . '<label><input type="checkbox" data-camp-folder> <span class="dashicons dashicons-category" aria-hidden="true"></span> <strong>' . esc_html($name) . '</strong></label>'
            . ' <span class="passflow-muted">' . esc_html(sprintf(_n('%d date', '%d dates', count($group['creneaux']), $d), count($group['creneaux']))) . '</span>'
            . '<button type="button" class="passflow-camp-toggle" data-camp-toggle aria-expanded="' . ($open ? 'true' : 'false') . '" aria-label="' . esc_attr__('Ouvrir ou fermer', $d) . '"><span class="dashicons dashicons-arrow-down-alt2" aria-hidden="true"></span></button>'
            . '</div><ul>';

        foreach ($group['creneaux'] as $slot) {
            $cid = (int) $slot['id'];
            $staff = Lookup::ids($slot['host_ids']);
            $who = array('yes' => array(), 'no' => array(), 'wait' => array());

            foreach (array_unique(array_merge($staff, isset($known[$cid]) ? array_keys($known[$cid]) : array())) as $person_id) {
                $state = Availability::state(isset($known[$cid][$person_id]) ? $known[$cid][$person_id] : null);
                $who[in_array($state, array('yes', 'no'), true) ? $state : 'wait'][] = (int) $person_id;
            }

            $tree .= '<li class="passflow-camp-row"><label class="passflow-camp-when"><input type="checkbox" name="creneaux[]" value="' . $cid . '" data-staff="' . esc_attr(implode(',', $staff)) . '" data-has-yes="' . ($who['yes'] ? '1' : '0') . '"' . checked(in_array($cid, $checked_dates, true), true, false) . '> '
                . esc_html(Lookup::slotWhen($slot))
                . ($slot['status'] === 'draft' ? ' <span class="passflow-badge passflow-badge--draft">' . esc_html__('Brouillon', $d) . '</span>' : '') . '</label>'
                . '<span class="passflow-camp-col">' . $names($who['yes'], 'is-yes') . '</span>'
                . '<span class="passflow-camp-col">' . $names($who['no'], 'is-no') . '</span>'
                . '<span class="passflow-camp-col">' . $names($who['wait'], 'is-wait', '?') . '</span>'
                . '</li>';
        }

        $tree .= '</ul></li>';
    }

    $tree .= '</ul>';

    // 2. Les intervenants (actifs).
    $people = '<div class="passflow-camp-tools"><button type="button" class="button button-small" data-camp-all="people">' . esc_html__('Tout cocher', $d) . '</button> '
        . '<button type="button" class="button button-small" data-camp-none="people">' . esc_html__('Tout décocher', $d) . '</button> '
        . '<button type="button" class="button button-small" data-camp-planned>' . esc_html__('Ceux prévus sur les dates cochées', $d) . '</button> '
        . '<span class="passflow-muted" data-camp-count="people"></span></div><div class="passflow-camp-people" data-camp-group="people">';

    foreach (Lookup::hostOptions($checked_people) as $label => $id) {
        $row = $people_all[(int) $id];
        $contact = array_filter(array(!empty($row['email']) ? '✉' : '', !empty($row['phone']) ? '📱' : ''));
        $people .= '<label><input type="checkbox" name="intervenants[]" value="' . (int) $id . '"' . checked(in_array((int) $id, $checked_people, true), true, false) . '> ' . esc_html($label)
            . ($contact ? ' <span class="passflow-muted">' . esc_html(implode(' ', $contact)) . '</span>' : '') . '</label>';
    }

    $people .= '</div>';

    // 3. Message et envoi.
    $send = '<p><label for="passflow-camp-message"><strong>' . esc_html__('Message court (facultatif)', $d) . '</strong></label><br>'
        . '<textarea id="passflow-camp-message" name="message" rows="3" maxlength="500" class="large-text" placeholder="' . esc_attr__('Ex. : Voici les dates du premier trimestre, merci de répondre avant vendredi.', $d) . '">' . esc_textarea(isset($draft['message']) ? $draft['message'] : '') . '</textarea></p>'
        . '<p class="passflow-camp-channels"><label><input type="checkbox" name="canaux[]" value="email"' . checked(in_array('email', $channels, true), true, false) . '> ' . esc_html__('E-mail', $d) . '</label> '
        . '<label><input type="checkbox" name="canaux[]" value="sms"' . checked(in_array('sms', $channels, true), true, false) . '> ' . esc_html__('SMS', $d) . '</label></p>'
        . '<p class="passflow-muted">' . esc_html__("Chacun reçoit la liste des dates cochées et un lien direct vers « Mes disponibilités », où la demande s'affiche en haut. Le texte se règle dans Réglages › Messages (« Demande de dispo groupée »).", $d) . '</p>'
        . '<p><button type="submit" class="button button-primary button-hero" data-camp-submit data-confirm="' . esc_attr__('Envoyer la demande à %p intervenant(s) pour %d date(s) ?', $d) . '"><span class="dashicons dashicons-email-alt" aria-hidden="true"></span> ' . esc_html__('Envoyer la demande', $d) . '</button></p>';

    $html .= '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="passflow-camp-form" data-camp-form>'
        . '<input type="hidden" name="action" value="' . esc_attr(Campaigns::SEND) . '">'
        . wp_nonce_field(Campaigns::SEND . '_0', '_wpnonce', true, false)
        . View::card(__('1. Les dates', $d), $tree)
        . View::card(__('2. Les intervenants', $d), $people)
        . View::card(__('3. Message et envoi', $d), $send)
        . '</form>';

    wp_enqueue_script('smbb-passflow-campaigns', SMBB_PASSFLOW_URL . 'assets/campaigns.js', array(), SMBB_PASSFLOW_VERSION, true);
    wp_add_inline_script('smbb-passflow-campaigns', 'window.passflowCamp=' . wp_json_encode(array(
        'dates' => __('%d date(s) cochée(s)', $d),
        'people' => __('%d intervenant(s) coché(s)', $d),
        'empty' => __('Cochez au moins une date et un intervenant.', $d),
    )) . ';', 'before');

    View::page($html, $header, $notices, __('Nouvelle demande de dispo', $d));
    return;
}

// --- Suivi d'une demande ----------------------------------------------------
$campaign_id = isset($_GET['demande']) ? absint($_GET['demande']) : 0;

if ($campaign_id) {
    $campaign = Campaigns::find($campaign_id);

    if (!$campaign) {
        View::page(View::note(__('Demande introuvable', $d), '', 'dashicons-warning'), $header, $notices, $title);
        return;
    }

    $dests = Campaigns::recipients($campaign_id);
    $slots = array();

    foreach ($campaign['slot_ids'] as $id) {
        $slot = \Smbb\Passflow\Repository\Slots::find($id);

        if ($slot) {
            $slots[] = $slot;
        }
    }

    usort($slots, function ($a, $b) {
        return strcmp($a['start_date'] . $a['start_time'], $b['start_date'] . $b['start_time']);
    });

    $answers = Availability::forSlots($campaign['slot_ids']);
    $open_count = count(Campaigns::openSlots($campaign));
    $channel_names = array('email' => __('e-mail', $d), 'sms' => __('SMS', $d));

    $html = '<p><a href="' . esc_url($list_url) . '">← ' . esc_html__('Toutes les demandes', $d) . '</a></p>';
    $html .= View::card('', View::definitionList(array_filter(array(
        __('Envoyée le', $d) => esc_html(View::datetime($campaign['sent_at'])) . ($campaign['sent_by'] ? ' <span class="passflow-muted">' . esc_html(sprintf(__('par %s', $d), Lookup::userLabel($campaign['sent_by']))) . '</span>' : ''),
        __('Relancée le', $d) => $campaign['reminded_at'] ? esc_html(View::datetime($campaign['reminded_at'])) : '',
        __('Par', $d) => esc_html(implode(' + ', array_map(function ($c) use ($channel_names) {
            return isset($channel_names[$c]) ? $channel_names[$c] : $c;
        }, $campaign['channels']))),
        __('Message', $d) => $campaign['message'] !== '' ? nl2br(esc_html($campaign['message'])) : '<span class="passflow-muted">—</span>',
        __('Dates', $d) => esc_html(sprintf(_n('%1$d date (%2$d encore à venir)', '%1$d dates (%2$d encore à venir)', count($slots), $d), count($slots), $open_count)),
    )))
        . '<p class="passflow-actions">'
        . ($open_count ? '<a class="button" href="' . esc_url(View::actionUrl(Campaigns::REMIND, array('id' => $campaign_id))) . '" onclick="return confirm(this.dataset.smbbConfirm)" data-smbb-confirm="' . esc_attr__('Relancer ceux qui n\'ont pas répondu pour toutes les dates à venir ?', $d) . '"><span class="dashicons dashicons-controls-repeat" aria-hidden="true"></span> ' . esc_html__('Relancer ceux qui n\'ont pas répondu', $d) . '</a> ' : '')
        . '<a class="button" href="' . esc_url(View::url('passflow_campaigns', array('nouvelle' => 1, 'creneaux' => implode(',', $campaign['slot_ids'])))) . '"><span class="dashicons dashicons-admin-page" aria-hidden="true"></span> ' . esc_html__('Nouvelle demande sur ces dates', $d) . '</a>'
        . '</p>');

    // Une ligne par intervenant : envoi, vu, réponses en %, message.
    $rows = '';

    foreach ($dests as $host_id => $dest) {
        $person = isset($people_all[$host_id]) ? $people_all[$host_id] : null;
        $tally = Campaigns::tally($campaign, $host_id, $answers);
        $sent = array_filter(array(!empty($dest['email_sent']) ? __('e-mail', $d) : '', !empty($dest['sms_sent']) ? __('SMS', $d) : ''));
        $bar = '<span class="passflow-camp-bar" aria-hidden="true">'
            . '<span class="is-yes" style="width:' . $pct($tally['yes'], $tally['total']) . '%"></span>'
            . '<span class="is-no" style="width:' . $pct($tally['no'], $tally['total']) . '%"></span></span>';

        $rows .= '<tr>'
            . '<td><strong>' . esc_html($person ? Lookup::personName($person) : '#' . $host_id) . '</strong>' . $bar . '</td>'
            . '<td>' . ($sent ? esc_html(implode(' + ', $sent)) : '<span class="passflow-badge passflow-badge--failed">' . esc_html__('Non envoyé', $d) . '</span>') . '</td>'
            . '<td>' . ($dest['opened_at'] ? esc_html(View::datetime($dest['opened_at'])) : '<span class="passflow-muted">' . esc_html__('Pas encore', $d) . '</span>') . '</td>'
            . '<td class="passflow-camp-pct is-yes"><strong>' . $pct($tally['yes'], $tally['total']) . ' %</strong> <span class="passflow-muted">(' . (int) $tally['yes'] . ')</span></td>'
            . '<td class="passflow-camp-pct is-no"><strong>' . $pct($tally['no'], $tally['total']) . ' %</strong> <span class="passflow-muted">(' . (int) $tally['no'] . ')</span></td>'
            . '<td class="passflow-camp-pct is-none"><strong>' . $pct($tally['none'], $tally['total']) . ' %</strong> <span class="passflow-muted">(' . (int) $tally['none'] . ')</span></td>'
            . '<td>' . ($dest['reply'] !== null && $dest['reply'] !== '' ? nl2br(esc_html($dest['reply'])) . '<br><span class="passflow-muted">' . esc_html(View::datetime($dest['replied_at'])) . '</span>' : '<span class="passflow-muted">—</span>') . '</td>'
            . '</tr>';
    }

    $html .= View::card(__('Réponses par intervenant', $d), '<div class="passflow-table-scroll"><table class="widefat striped passflow-table passflow-camp-table"><thead><tr>'
        . '<th>' . esc_html__('Intervenant', $d) . '</th><th>' . esc_html__('Envoyé par', $d) . '</th><th>' . esc_html__('Demande vue le', $d) . '</th>'
        . '<th>' . esc_html__('Dispo', $d) . '</th><th>' . esc_html__('Non dispo', $d) . '</th><th>' . esc_html__('Sans réponse', $d) . '</th><th>' . esc_html__('Son message', $d) . '</th>'
        . '</tr></thead><tbody>' . $rows . '</tbody></table></div>'
        . '<p class="passflow-muted">' . esc_html__("« Vue » : l'intervenant a ouvert « Mes disponibilités » depuis l'envoi. Les pourcentages suivent la réponse actuelle de chacun, même s'il l'a changée ensuite.", $d) . '</p>');

    // Détail date par date.
    if ($slots && $dests) {
        $grid = '<div class="passflow-table-scroll"><table class="widefat striped passflow-table passflow-camp-grid"><thead><tr><th>' . esc_html__('Date', $d) . '</th>';

        foreach (array_keys($dests) as $host_id) {
            $grid .= '<th>' . esc_html(isset($people_all[$host_id]) ? Lookup::personName($people_all[$host_id]) : '#' . $host_id) . '</th>';
        }

        $grid .= '</tr></thead><tbody>';

        foreach ($slots as $slot) {
            $past = !Availability::open($slot);
            $grid .= '<tr' . ($past ? ' class="passflow-row-off"' : '') . '><td><a href="' . esc_url(View::url('passflow_slot', array('view' => 'details', 'id' => (int) $slot['id']))) . '">' . esc_html(Lookup::slotLabel($slot)) . '</a></td>';

            foreach (array_keys($dests) as $host_id) {
                $row = isset($answers[(int) $slot['id']][$host_id]) ? $answers[(int) $slot['id']][$host_id] : null;
                $planned = in_array($host_id, Lookup::ids($slot['host_ids']), true);
                $grid .= '<td>' . Availability::badge(Availability::state($row)) . ($planned ? ' <span class="dashicons dashicons-yes-alt passflow-camp-planned" title="' . esc_attr__('Prévu sur cette date', $d) . '"></span>' : '') . '</td>';
            }

            $grid .= '</tr>';
        }

        $grid .= '</tbody></table></div><p class="passflow-muted"><span class="dashicons dashicons-yes-alt passflow-camp-planned" aria-hidden="true"></span> ' . esc_html__("déjà prévu sur la date (cases « Intervenants » de la fiche).", $d) . '</p>';
        $html .= View::fold(__('Détail date par date', $d), $grid, count($slots) <= 12, 'dashicons-calendar-alt');
    }

    View::page($html, $header, $notices, sprintf(__('Demande de dispo du %s', $d), View::datetime($campaign['sent_at'])));
    return;
}

// --- Liste ------------------------------------------------------------------
$campaigns = Campaigns::all();
$html = '<p class="passflow-actions"><a class="button button-primary" href="' . esc_url(View::url('passflow_campaigns', array('nouvelle' => 1))) . '"><span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span> ' . esc_html__('Nouvelle demande', $d) . '</a></p>';

if (!$campaigns) {
    $html .= View::card('', '<p class="passflow-muted">' . esc_html__("Aucune demande pour l'instant. Une demande regroupe plusieurs dates à venir et plusieurs intervenants : chacun reçoit un e-mail et un SMS avec un lien direct pour répondre.", $d) . '</p>');
} else {
    $rows = '';

    foreach ($campaigns as $campaign) {
        $dests = Campaigns::recipients($campaign['id']);
        $answers = Availability::forSlots($campaign['slot_ids']);
        $totals = array('yes' => 0, 'no' => 0, 'none' => 0, 'total' => 0);
        $seen = 0;
        $replies = 0;

        foreach ($dests as $host_id => $dest) {
            foreach (Campaigns::tally($campaign, $host_id, $answers) as $key => $value) {
                $totals[$key] += $value;
            }

            $seen += $dest['opened_at'] ? 1 : 0;
            $replies += ($dest['reply'] !== null && $dest['reply'] !== '') ? 1 : 0;
        }

        $url = View::url('passflow_campaigns', array('demande' => $campaign['id']));
        $excerpt = $campaign['message'] !== '' ? wp_trim_words($campaign['message'], 14) : '';
        $rows .= '<tr>'
            . '<td><a href="' . esc_url($url) . '"><strong>' . esc_html(View::datetime($campaign['sent_at'])) . '</strong></a>' . ($excerpt !== '' ? '<br><span class="passflow-muted">' . esc_html($excerpt) . '</span>' : '') . '</td>'
            . '<td>' . esc_html(sprintf(_n('%d date', '%d dates', count($campaign['slot_ids']), $d), count($campaign['slot_ids']))) . '</td>'
            . '<td>' . esc_html(sprintf(_n('%d intervenant', '%d intervenants', count($dests), $d), count($dests))) . '</td>'
            . '<td>' . esc_html(sprintf(__('%1$d / %2$d', $d), $seen, count($dests))) . ($replies ? ' <span class="passflow-badge">' . esc_html(sprintf(_n('%d message', '%d messages', $replies, $d), $replies)) . '</span>' : '') . '</td>'
            . '<td><span class="passflow-camp-pct is-yes">' . $pct($totals['yes'], $totals['total']) . ' %</span> · <span class="passflow-camp-pct is-no">' . $pct($totals['no'], $totals['total']) . ' %</span> · <span class="passflow-camp-pct is-none">' . $pct($totals['none'], $totals['total']) . ' %</span></td>'
            . '<td><a class="button button-small" href="' . esc_url($url) . '">' . esc_html__('Suivi', $d) . '</a></td>'
            . '</tr>';
    }

    $html .= View::card('', '<div class="passflow-table-scroll"><table class="widefat striped passflow-table"><thead><tr>'
        . '<th>' . esc_html__('Envoyée le', $d) . '</th><th>' . esc_html__('Dates', $d) . '</th><th>' . esc_html__('Intervenants', $d) . '</th><th>' . esc_html__('Vue par', $d) . '</th>'
        . '<th>' . esc_html__('Dispo · Non dispo · Sans réponse', $d) . '</th><th></th>'
        . '</tr></thead><tbody>' . $rows . '</tbody></table></div>');
}

View::page($html, $header, $notices, $title);
