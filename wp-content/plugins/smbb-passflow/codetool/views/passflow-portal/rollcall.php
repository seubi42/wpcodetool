<?php
/**
 * Écran d'appel d'une date, inclus par dashboard.php (variables $slot_id, $host, $manager, $d…).
 * Pensé pour le téléphone : une carte par participant, Présent / Absent, une note facultative.
 */

use Smbb\Passflow\Admin\Actions;
use Smbb\Passflow\Admin\View;
use Smbb\Passflow\Repository\Slots;
use Smbb\Passflow\Repository\Bookings;
use Smbb\Passflow\Repository\Lookup;
use Smbb\Passflow\Support\Choices;

defined('ABSPATH') || exit;

$back = '<p class="passflow-rollcall-back"><a href="' . esc_url(View::url('passflow_portal')) . '"><span class="dashicons dashicons-arrow-left-alt2" aria-hidden="true"></span> ' . esc_html__('Mon agenda', $d) . '</a></p>';
$slot = Slots::find($slot_id);

if (!$slot || !Actions::canSeeSlot($slot_id)) {
    View::page($back . View::card('', '<p>' . esc_html__("Cette date n'existe pas ou ne vous est pas attribuée.", $d) . '</p>'), $header, $notices, __('Mes ateliers', $d), 'compact');
    return;
}

$stats = Slots::withStats(array($slot));
$slot = $stats[0];
$activity = Lookup::atelier($slot['activity_id']);
// Pointage possible sur toute date non annulée ; hors du jour de l'atelier, il est verrouillé
// jusqu'à ce qu'on active « Pointer quand même ».
$open = $slot['status'] !== 'cancelled' && !empty($slot['start_date']);
$is_day = Actions::isRollCallDay($slot);
$lock = $open && !$is_day;
$lock_attr = $lock ? ' disabled data-passflow-lockable' : ' data-passflow-lockable';
$force_input = '<input type="hidden" name="hors_date" value="0" data-passflow-force>';
// Caisse : catalogues de cet atelier et de cet intervenant (le gestionnaire voit tout).
$sale_context = $manager ? null : ($host ? (int) $host['id'] : 0);
$sale_products = $open && \Smbb\Passflow\WooCommerce\Module::active() ? \Smbb\Passflow\WooCommerce\OnsiteSales::products($activity, $sale_context) : array();
$participants = Bookings::forSlot($slot_id);

// Ordre alphabétique : la liste ne bouge pas pendant l'appel.
usort($participants, function ($a, $b) {
    return strcasecmp(remove_accents($a['last_name'] . ' ' . $a['first_name']), remove_accents($b['last_name'] . ' ' . $b['first_name']));
});

// En-tête : l'atelier, la date, le lieu, le remplissage.
$facts = array(esc_html(Lookup::slotWhen($slot)));

if ($activity && $activity['location']) {
    $facts[] = esc_html($activity['location']);
}

$names = Lookup::hostNames($slot['host_ids']);

if ($names !== '') {
    $facts[] = esc_html(sprintf(__('Avec %s', $d), $names));
}

// ---------- Gestion mobile « PDA » (assets/pda.css, toutes tailles d'écran) ----------
// Même scène sombre que le mode Jour J : en-tête d'une ligne (anneau de présence, atelier, heure,
// compte à rebours), cartes au pouce, barre d'actions en bas. Sur ordinateur, colonne centrée.
wp_enqueue_style('passflow-pda', SMBB_PASSFLOW_URL . 'assets/pda.css', array('smbb-passflow-admin'), SMBB_PASSFLOW_VERSION . '.' . (int) @filemtime(SMBB_PASSFLOW_PATH . 'assets/pda.css'));
$pda_counts = \Smbb\Passflow\Admin\RollCall::counts($slot_id);
$pda_taken = $pda_counts['present'] + $pda_counts['absent'] + $pda_counts['none'];
$pda_ts = function ($time) use ($slot) {
    try {
        return (new \DateTimeImmutable(trim($slot['start_date'] . ' ' . ($time ? $time : '00:00:00')), wp_timezone()))->getTimestamp();
    } catch (\Exception $e) {
        return 0;
    }
};
$pda_all_day = Lookup::allDay($slot);
$pda_start = !$pda_all_day && !empty($slot['start_date']) && !empty($slot['start_time']) ? $pda_ts((string) $slot['start_time']) : 0;
$pda_end = $pda_start && !empty($slot['end_time']) ? $pda_ts((string) $slot['end_time']) : 0;
$pda_hours = Lookup::slotHours($slot, true, __('Toute la journée', $d));
$pda_date = !empty($slot['start_date']) ? date_i18n('D j M', strtotime($slot['start_date'] . ' 12:00:00')) : '';
$pda_dash = $pda_taken ? round($pda_counts['present'] / $pda_taken * 138.23, 2) : 0;
$pda_top = '<script>document.documentElement.classList.add("passflow-pda");</script>'
    . '<header class="passflow-pda-top">'
    . '<a class="passflow-pda-btn" href="' . esc_url(View::url('passflow_portal')) . '" title="' . esc_attr__('Mon agenda', $d) . '"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M15 18l-6-6 6-6"/></svg><span class="screen-reader-text">' . esc_html__('Mon agenda', $d) . '</span></a>'
    . '<span class="passflow-pda-ring" data-pda-ring title="' . esc_attr__('Présents sur inscrits', $d) . '"><svg viewBox="0 0 52 52" aria-hidden="true"><circle class="passflow-pda-ring-bg" cx="26" cy="26" r="22"/><circle class="passflow-pda-ring-fill" cx="26" cy="26" r="22" style="stroke-dasharray:' . esc_attr((string) $pda_dash) . ' 138.23"/></svg>'
    . '<b><span data-pda-ring-n>' . (int) $pda_counts['present'] . '</span><small>/<span data-pda-ring-of>' . (int) $pda_taken . '</span></small></b></span>'
    . '<div class="passflow-pda-title"><small>' . esc_html(trim($pda_date . ($pda_hours !== '' ? ' · ' . $pda_hours : ''))) . '</small><strong>' . esc_html($activity ? $activity['name'] : __('Atelier', $d)) . '</strong></div>'
    . '<div class="passflow-pda-clock"><strong data-pda-clock>' . esc_html(current_time('H:i')) . '</strong><span data-pda-countdown></span></div>'
    . '</header>';

$html = $pda_top . $back . '<div class="passflow-card passflow-rollcall-head">'
    . '<h2>' . esc_html($activity ? $activity['name'] : __('Atelier', $d)) . ' ' . ($slot['status'] !== 'open' ? Choices::slotBadge($slot) : '') . '</h2>'
    . '<p class="passflow-muted">' . implode(' · ', $facts) . '</p>'
    . '</div>';

// Jauge de l'appel : affichée en tête de l'onglet Participants.
// Pointage badge : gros bouton collé sous la jauge, ouvre la fenêtre de bip (assets/rollcall.js).
$badge_on = $open && $participants;
$gauge_html = '<div class="passflow-card passflow-rollcall-stats' . ($badge_on ? ' has-badge' : '') . '">' . \Smbb\Passflow\Admin\RollCall::gauge(\Smbb\Passflow\Admin\RollCall::counts($slot_id))
    . ((int) $slot['waiting'] > 0 ? '<p class="passflow-muted">' . esc_html(sprintf(_n("%d personne en liste d'attente", "%d personnes en liste d'attente", (int) $slot['waiting'], $d), (int) $slot['waiting'])) . '</p>' : '')
    . '</div>'
    . ($badge_on ? '<button type="button" class="passflow-badge-open" data-passflow-badge' . $lock_attr . '><span class="dashicons dashicons-id-alt" aria-hidden="true"></span> ' . esc_html__('Pointage badge', $d) . '</button>' : '');

if ($slot['status'] === 'cancelled') {
    $html .= View::note(__('Date annulée', $d), '<p>' . esc_html__('Cette date a été annulée : les réservations ont été retirées.', $d) . '</p>', 'dashicons-dismiss');
    View::page('<div class="passflow-portal">' . $html . '</div>', $header, $notices, __('Mes ateliers', $d), 'compact');
    return;
}

// Onglets : Participants (l'appel) et Caisse (ventes additionnelles de la date, chiffre d'affaires).
$sale_orders = \Smbb\Passflow\WooCommerce\Module::active() ? \Smbb\Passflow\WooCommerce\OnsiteSales::forSlot($slot_id) : array();
$cashdesk_on = $sale_products || $sale_orders;
$tab = $cashdesk_on && isset($_GET['onglet']) && sanitize_key(wp_unslash($_GET['onglet'])) === 'caisse' ? 'caisse' : 'participants'; // phpcs:ignore WordPress.Security.NonceVerification
$tab_url = function ($name) use ($slot_id) {
    return View::url('passflow_portal', $name === 'caisse' ? array('creneau' => (int) $slot_id, 'onglet' => 'caisse') : array('creneau' => (int) $slot_id));
};

$summary = $cashdesk_on ? \Smbb\Passflow\WooCommerce\OnsiteSales::summary($sale_orders) : null;

if ($cashdesk_on) {
    $html .= '<nav class="passflow-rollcall-tabs" role="tablist" aria-label="' . esc_attr__('Vues de la date', $d) . '" data-passflow-tabs>'
        . '<a href="' . esc_url($tab_url('participants')) . '" role="tab" id="passflow-tab-participants" aria-controls="passflow-panel-participants" aria-selected="' . ($tab === 'participants' ? 'true' : 'false') . '" data-passflow-tab="participants"' . ($tab === 'participants' ? ' class="is-active"' : '') . '><span class="dashicons dashicons-groups" aria-hidden="true"></span> ' . esc_html__('Participants', $d) . ' <span class="passflow-rollcall-tab-n">' . count($participants) . '</span></a>'
        . '<a href="' . esc_url($tab_url('caisse')) . '" role="tab" id="passflow-tab-cashdesk" aria-controls="passflow-panel-cashdesk" aria-selected="' . ($tab === 'caisse' ? 'true' : 'false') . '" data-passflow-tab="caisse"' . ($tab === 'caisse' ? ' class="is-active"' : '') . '><span class="dashicons dashicons-cart" aria-hidden="true"></span> ' . esc_html__('Caisse', $d) . ' <span class="passflow-rollcall-tab-n" data-passflow-cashdesk-tab>' . esc_html(\Smbb\Passflow\WooCommerce\OnsiteSales::money($summary['paid'])) . '</span></a>'
        . '</nav>';
}

// Pas le jour de l'atelier : pointage, scan et imprévus verrouillés derrière un interrupteur.
if ($lock) {
    $when = date_i18n('l j F', strtotime($slot['start_date'] . ' 12:00:00'));
    $html .= '<div class="passflow-rollcall-lock" data-passflow-lock>'
        . '<span class="dashicons dashicons-lock" aria-hidden="true"></span>'
        . '<div><strong>' . esc_html(sprintf(__("Ce n'est pas le jour de l'atelier (%s)", $d), $when)) . '</strong>'
        . '<p class="passflow-muted">' . esc_html__('Le pointage et la caisse sont fermés pour éviter de se tromper de date. Les notes restent modifiables.', $d) . '</p></div>'
        . '<label class="passflow-force-switch"><input type="checkbox" data-passflow-force-switch><span class="passflow-force-switch-ui" aria-hidden="true"></span><span>' . esc_html__('Accès forcé', $d) . '</span></label>'
        . '</div>'
        . '<script>(function(){var sw=document.querySelector("[data-passflow-force-switch]");if(!sw)return;sw.addEventListener("change",function(){var on=sw.checked;'
        . 'document.querySelectorAll("[data-passflow-lockable]").forEach(function(el){el.disabled=!on;});'
        . 'document.querySelectorAll("[data-passflow-force]").forEach(function(el){el.value=on?"1":"0";});'
        . 'document.querySelectorAll("form.passflow-rollcall").forEach(function(f){f.classList.toggle("is-locked",!on);});'
        . 'var box=document.querySelector("[data-passflow-lock]");if(box)box.classList.toggle("is-unlocked",on);'
        . 'window.passflowForce=on;document.documentElement.classList.toggle("passflow-forced",on);'
        . 'if(window.passflowRollcall)window.passflowRollcall.force=on;});})();</script>';
}

// Le bandeau ci-dessus vaut pour les deux onglets ; l'onglet Participants commence ici.
$top_html = $html;
$html = $gauge_html;

// Participant imprévu : formulaire replié, ouvert par le bouton « Imprévu » à côté de Scanner.
$walkin = '';

if ($open) {
    $walkin = '<div class="passflow-card passflow-walkin" id="passflow-walkin" hidden>'
        . View::formStart(Actions::WALKIN, $slot_id, 'passflow-walkin-form') . $force_input
        . '<h3>' . esc_html__('Ajouter un participant imprévu', $d) . '</h3>'
        . '<p class="passflow-muted">' . esc_html__('Ajouté même si la date est complète. Avec un e-mail, son compte client est repris, ou créé s\'il n\'existe pas : il pourra signer ses documents ici.', $d) . '</p>'
        . '<div class="passflow-walkin-fields">'
        . '<label>' . esc_html__('Prénom', $d) . ' *<input type="text" name="walkin[first_name]" required autocomplete="off"></label>'
        . '<label>' . esc_html__('Nom', $d) . ' *<input type="text" name="walkin[last_name]" required autocomplete="off"></label>'
        . '<label>' . esc_html__('E-mail', $d) . '<input type="email" name="walkin[email]" autocomplete="off" inputmode="email"></label>'
        . '<label>' . esc_html__('Téléphone', $d) . '<input type="tel" name="walkin[phone]" autocomplete="off" inputmode="tel"></label>'
        . '</div>'
        . '<p class="passflow-walkin-actions"><button type="button" class="button" data-passflow-walkin-close>' . esc_html__('Annuler', $d) . '</button> '
        . '<button type="button" class="button button-primary" data-passflow-walkin-choose>' . esc_html__('Ajouter', $d) . '</button></p>'
        // Choix du paiement, en fenêtre : les deux boutons envoient le formulaire.
        . '<div class="passflow-walkin-modal" data-passflow-walkin-modal hidden role="dialog" aria-modal="true" aria-labelledby="passflow-walkin-q"><div class="passflow-walkin-modal-box">'
        . '<h3 id="passflow-walkin-q">' . esc_html__('Et le paiement ?', $d) . '</h3>'
        . '<button type="submit" name="mode" value="paid" class="passflow-walkin-choice"><span class="dashicons dashicons-yes-alt" aria-hidden="true"></span><span><strong>' . esc_html__('Ajout direct, prestation déjà payée', $d) . '</strong><small>' . esc_html__('Ajouté tout de suite à la liste et pointé présent.', $d) . '</small></span></button>'
        . (\Smbb\Passflow\WooCommerce\Module::active()
            ? '<button type="submit" name="mode" value="sms" class="passflow-walkin-choice"><span class="dashicons dashicons-smartphone" aria-hidden="true"></span><span><strong>' . esc_html__('Paiement par SMS avant ajout', $d) . '</strong><small>' . esc_html__('Il reçoit un lien de paiement qui le connecte tout seul (10 minutes). Ajouté à la liste dès qu\'il a payé, à pointer ensuite.', $d) . '</small></span></button>'
            : '')
        . '<p class="passflow-walkin-modal-foot"><button type="button" class="button-link" data-passflow-walkin-back>' . esc_html__('Retour', $d) . '</button></p>'
        . '</div></div>'
        . '</form></div>'
        . '<script>(function(){var box=document.getElementById("passflow-walkin");if(!box)return;'
        . 'function show(on){box.hidden=!on;if(on){box.scrollIntoView({behavior:"smooth",block:"start"});var f=box.querySelector("input[type=text]");if(f)setTimeout(function(){f.focus();},200);}}'
        . 'var modal=box.querySelector("[data-passflow-walkin-modal]"),form=box.querySelector("form");'
        . 'document.addEventListener("click",function(e){if(e.target.closest("[data-passflow-walkin]")){e.preventDefault();show(box.hidden);}else if(e.target.closest("[data-passflow-walkin-close]")){show(false);}'
        . 'else if(e.target.closest("[data-passflow-walkin-choose]")){if(form.reportValidity())modal.hidden=false;}'
        . 'else if(e.target.closest("[data-passflow-walkin-back]")||e.target===modal){modal.hidden=true;}});'
        . 'document.addEventListener("keydown",function(e){if(e.key==="Escape"&&!modal.hidden)modal.hidden=true;});'
        . 'form.addEventListener("keydown",function(e){if(e.key==="Enter"&&e.target.tagName==="INPUT"){e.preventDefault();if(form.reportValidity())modal.hidden=false;}});'
        . 'form.addEventListener("submit",function(e){var m=e.submitter&&e.submitter.value;if(m==="sms"){var em=form.querySelector("[name=\'walkin[email]\']").value.trim(),ph=form.querySelector("[name=\'walkin[phone]\']").value.trim();if(!em&&!ph){e.preventDefault();alert(' . wp_json_encode(__('Pour le paiement par SMS, indiquez au moins un téléphone ou un e-mail.', $d)) . ');}}});'
        . 'if(/[?&]imprevu=1/.test(location.search))show(true);})();</script>';
    $html .= $walkin;

    // Imprévus qui n'ont pas encore payé : ils arrivent sur la liste au paiement.
    $waiting_pay = \Smbb\Passflow\WooCommerce\Module::active() ? \Smbb\Passflow\WooCommerce\Walkins::pending($slot_id) : array();

    if ($waiting_pay) {
        $items = '';

        foreach ($waiting_pay as $order) {
            $items .= '<li><span><strong>' . esc_html(trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name())) . '</strong> · ' . esc_html(\Smbb\Passflow\WooCommerce\OnsiteSales::total($order)) . '</span> '
                . '<span class="passflow-badge passflow-badge--pending">' . esc_html__('En attente de paiement', $d) . '</span> '
                . '<a class="button button-small" href="' . esc_url(View::actionUrl(Actions::ONSITE_RESEND, array('id' => (int) $order->get_id()))) . '">' . esc_html__('Renvoyer le lien', $d) . '</a> '
                . '<a class="button button-small passflow-cashdesk-cancel" href="' . esc_url(View::actionUrl(Actions::ONSITE_CANCEL, array('id' => (int) $order->get_id()))) . '" data-passflow-cancel-order="' . (int) $order->get_id() . '" data-client="' . esc_attr(trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name())) . '" data-order="' . esc_attr((string) $order->get_order_number()) . '" data-total="' . esc_attr(\Smbb\Passflow\WooCommerce\OnsiteSales::total($order)) . '">' . esc_html__('Annuler', $d) . '</a></li>';
        }

        $html .= '<div class="passflow-walkin-pending"><p><span class="dashicons dashicons-smartphone" aria-hidden="true"></span> ' . esc_html__('Imprévus en attente de paiement : ils arrivent sur la liste dès le paiement, à pointer ensuite.', $d) . '</p><ul>' . $items . '</ul></div>';
    }
}

if (!$participants) {
    $html .= ($open ? '<p><button type="button" class="button" data-passflow-walkin' . $lock_attr . '><span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span> ' . esc_html__('Imprévu', $d) . '</button></p>' : '');
    $html .= View::card('', '<p class="passflow-muted">' . esc_html__("Pas encore d'inscrit sur cette date.", $d) . '</p>');
} else {

$html .= View::formStart(Actions::ROLL_CALL, $slot_id, 'passflow-rollcall' . ($open ? ' is-open' : '') . ($lock ? ' is-locked' : '')) . $force_input;

// Outils : recherche, filtres, imprévu. Restent visibles en haut pendant le défilement.
$html .= '<div class="passflow-rollcall-tools">'
    . '<div class="passflow-rollcall-search"><span class="dashicons dashicons-search" aria-hidden="true"></span>'
    . '<input type="search" data-passflow-search placeholder="' . esc_attr__('Nom, téléphone ou n° de billet…', $d) . '" autocomplete="off" aria-label="' . esc_attr__('Rechercher un participant', $d) . '">'
    . '<button type="button" class="passflow-search-clear" data-passflow-search-clear hidden title="' . esc_attr__('Effacer la recherche', $d) . '" aria-label="' . esc_attr__('Effacer la recherche', $d) . '"><svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path d="M7 7l10 10M17 7L7 17" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"/></svg></button>'
    . ($open ? '<button type="button" class="button" data-passflow-walkin' . $lock_attr . ' title="' . esc_attr__('Ajouter un participant imprévu', $d) . '"><span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span><span class="passflow-btn-label"> ' . esc_html__('Imprévu', $d) . '</span></button>' : '')
    . '</div>'
    . '<div class="passflow-rollcall-filters" role="group" aria-label="' . esc_attr__('Filtrer', $d) . '">'
    . '<button type="button" class="is-active" data-passflow-filter="all">' . esc_html__('Tous', $d) . ' <span data-filter-count="all"></span></button>'
    . '<button type="button" data-passflow-filter="none">' . esc_html__('À pointer', $d) . ' <span data-filter-count="none"></span></button>'
    . '<button type="button" data-passflow-filter="present">' . esc_html__('Présents', $d) . ' <span data-filter-count="present"></span></button>'
    . '<button type="button" data-passflow-filter="absent">' . esc_html__('Absents', $d) . ' <span data-filter-count="absent"></span></button>'
    . (\Smbb\Passflow\Service\Documents::enabled() ? '<button type="button" class="is-warn" data-passflow-filter="docs">' . esc_html__('Docs à signer', $d) . ' <span data-filter-count="docs"></span></button>' : '')
    . '<button type="button" class="is-bad" data-passflow-filter="pay">' . esc_html__('À confirmer', $d) . ' <span data-filter-count="pay"></span></button>'
    . '</div>'
    . '</div>'
    . '<p class="passflow-rollcall-empty" data-passflow-empty hidden>' . esc_html__('Personne ne correspond.', $d) . '</p>';

$html .= '<ol class="passflow-rollcall-list">';
$docs_enabled = \Smbb\Passflow\Service\Documents::enabled();
$doc_answers = $docs_enabled ? \Smbb\Passflow\Service\Documents::answers(array_column($participants, 'id')) : array();
// Référence imprimée sur le billet (« PCCF-T-00042 ») ; un jour de pass porte celle du pass. Cherchable.
$ref_prefix = \Smbb\Passflow\Support\Settings::companyCode() !== '' ? \Smbb\Passflow\Support\Settings::companyCode() . '-' : '';
$pda_icon = function ($path) {
    return '<svg viewBox="0 0 24 24" aria-hidden="true">' . $path . '</svg>';
};

foreach ($participants as $p) {
    $id = (int) $p['id'];
    $status = in_array($p['status'], array('present', 'absent'), true) ? $p['status'] : 'none';
    $infos = array();

    if (!empty($p['allergies'])) {
        $infos[] = '<span class="passflow-allergy"><span class="dashicons dashicons-warning" aria-hidden="true"></span> ' . esc_html__('Allergies :', $d) . ' ' . esc_html($p['allergies']) . '</span>';
    }

    if (!empty($p['level'])) {
        $infos[] = esc_html(Choices::label('levels', $p['level']));
    }

    if (!empty($p['age'])) {
        $infos[] = esc_html(sprintf(__('%d ans', $d), (int) $p['age']));
    }

    $who = '<strong>' . esc_html(Lookup::personName($p)) . '</strong>';

    if (!empty($p['pass_id'])) {
        $who .= ' <span class="passflow-badge passflow-badge--pass" title="' . esc_attr__('Place comprise dans un pass : le même QR sert chaque jour, les documents sont signés une seule fois.', $d) . '"><span class="dashicons dashicons-tickets-alt" aria-hidden="true"></span>' . esc_html__('Pass', $d) . '</span>';
    }

    if (isset($p['source']) && $p['source'] === 'walkin') {
        $who .= ' <span class="passflow-badge passflow-badge--walkin" title="' . esc_attr__('Ajouté sur place, sans réservation', $d) . '">' . esc_html__('Imprévu', $d) . '</span>';
    }

    if ($p['status'] === 'pending') {
        $who .= ' <span class="passflow-badge passflow-badge--pending" title="' . esc_attr(sprintf(__('Paiement ou accord pas encore confirmé par %s', $d), \Smbb\Passflow\Support\Settings::businessName())) . '">' . esc_html__('À confirmer', $d) . '</span>';
    }

    if (!empty($p['phone'])) {
        $who .= '<br><a class="passflow-muted" href="tel:' . esc_attr(preg_replace('/[^0-9+]/', '', $p['phone'])) . '">' . esc_html($p['phone']) . '</a>';
    }

    $ref = $ref_prefix . sprintf('T-%05d', !empty($p['pass_id']) ? (int) $p['pass_id'] : $id);
    $search = strtolower(remove_accents(Lookup::personName($p) . ' ' . $p['first_name'] . ' ' . $p['email'] . ' ' . $ref . ' ' . $id)) . ' ' . preg_replace('/\D/', '', (string) $p['phone']);
    $docs_missing = $docs_enabled && \Smbb\Passflow\Service\Documents::missing(isset($doc_answers[$id]) ? $doc_answers[$id] : array());
    $initials = mb_strtoupper(mb_substr(trim((string) $p['first_name']), 0, 1) . mb_substr(trim((string) $p['last_name']), 0, 1));
    $tel = !empty($p['phone']) ? preg_replace('/[^0-9+]/', '', $p['phone']) : '';
    $html .= '<li class="passflow-rollcall-row" id="passflow-row-' . $id . '" data-id="' . $id . '" data-status="' . esc_attr($status) . '" data-walkin="' . (isset($p['source']) && $p['source'] === 'walkin' ? '1' : '0') . '" data-docs="' . ($docs_missing ? '1' : '0') . '" data-pay="' . ($p['status'] === 'pending' ? '1' : '0') . '" data-name="' . esc_attr(Lookup::personName($p)) . '" data-search="' . esc_attr($search) . '">'
        // Carte : sur ordinateur, display: contents (la ligne reste une grille) ; sur PDA, elle glisse.
        . '<div class="passflow-rollcall-card" data-pda-card>'
        . '<span class="passflow-pda-av" aria-hidden="true">' . esc_html($initials !== '' ? $initials : '?') . '</span>'
        . '<div class="passflow-rollcall-who">' . $who
        . '<span class="passflow-pda-ref">' . esc_html(sprintf(__('Billet %s', $d), $ref)) . '</span>'
        . ($infos ? '<div class="passflow-rollcall-infos">' . implode(' · ', $infos) . '</div>' : '')
        . (!empty($p['notes']) ? '<div class="passflow-rollcall-remark">' . esc_html__('Remarque :', $d) . ' ' . esc_html($p['notes']) . '</div>' : '');

    // Documents : état de chacun, et « Faire signer » sur place s'il en manque.
    if ($docs_enabled) {
        $answers = isset($doc_answers[$id]) ? $doc_answers[$id] : array();
        $html .= '<div class="passflow-rollcall-docs">' . \Smbb\Passflow\Service\Documents::badges($answers)
            . (\Smbb\Passflow\Service\Documents::missing($answers) ? ' <a class="button button-small" data-sign-name="' . esc_attr(Lookup::personName($p)) . '" href="' . esc_url(\Smbb\Passflow\Service\Documents::url($p, true)) . '">' . esc_html__('Faire signer', $d) . '</a>' : '')
            . '</div>';
    }

    if ($sale_products) {
        $html .= '<div class="passflow-rollcall-sale"><button type="button" class="button button-small" data-passflow-sale' . $lock_attr . ' data-booking="' . $id . '" data-first="' . esc_attr($p['first_name']) . '" data-last="' . esc_attr($p['last_name']) . '" data-email="' . esc_attr($p['email']) . '" data-phone="' . esc_attr($p['phone']) . '"><span class="dashicons dashicons-cart" aria-hidden="true"></span> ' . esc_html__('Vente', $d) . '</button></div>';
    }

    $html .= '</div>';

    if ($open) {
        // Trois états côte à côte : Absent, À pointer, Présent. Chaque choix est enregistré aussitôt.
        $html .= '<fieldset class="passflow-presence" aria-label="' . esc_attr(sprintf(__('Pointage de %s', $d), Lookup::personName($p))) . '">';

        foreach (array('absent' => __('Absent', $d), 'none' => __('À pointer', $d), 'present' => __('Présent', $d)) as $value => $label) {
            $html .= '<label class="passflow-presence-' . $value . '"><input type="radio" name="presence[' . $id . ']" value="' . $value . '"' . checked($status, $value, false) . $lock_attr . '><span>' . esc_html($label) . '</span></label>';
        }

        $html .= '</fieldset>'
            // PDA : une grosse coche. Toucher = présent (ou retour « à pointer »), appui long = absent.
            . '<button type="button" class="passflow-pda-tap" data-pda-tap' . $lock_attr . ' aria-label="' . esc_attr(sprintf(__('Pointer %s', $d), Lookup::personName($p))) . '">'
            . $pda_icon('<path class="passflow-pda-tap-yes" d="M5 12.5l4.5 4.5L19 7.5"/><path class="passflow-pda-tap-no" d="M7 7l10 10M17 7L7 17"/>') . '</button>';
    }

    $note = (string) $p['host_note'];
    $html .= '<details class="passflow-rollcall-note"' . ($note !== '' ? ' open' : '') . '><summary>' . esc_html($note !== '' ? __('Note', $d) : __('Ajouter une note', $d)) . '</summary>'
        . '<textarea name="note[' . $id . ']" data-passflow-note rows="2" placeholder="' . esc_attr(sprintf(__("Visible par l'équipe de %s", $d), \Smbb\Passflow\Support\Settings::businessName())) . '">' . esc_textarea($note) . '</textarea></details>'
        . '<span class="passflow-saved" data-passflow-saved aria-live="polite"></span>'
        . '</div>';

    // PDA : glisser à droite = arrivé ; à gauche = Appeler, Note, Vente (sous la carte).
    if ($open) {
        $html .= '<div class="passflow-pda-under passflow-pda-under--yes" aria-hidden="true">' . $pda_icon('<path d="M5 12.5l4.5 4.5L19 7.5"/>') . '<span>' . esc_html__('Arrivé', $d) . '</span></div>';
    }

    $html .= '<div class="passflow-pda-under passflow-pda-under--acts" data-pda-acts>'
        . ($tel !== '' ? '<a class="passflow-pda-act" href="tel:' . esc_attr($tel) . '">' . $pda_icon('<path d="M5 4h4l2 5-2.5 1.5a11 11 0 005 5L15 13l5 2v4a2 2 0 01-2 2A16 16 0 013 6a2 2 0 012-2"/>') . '<span>' . esc_html__('Appeler', $d) . '</span></a>' : '')
        . '<button type="button" class="passflow-pda-act" data-pda-note>' . $pda_icon('<path d="M5 4h14v12l-4 4H5z"/><path d="M15 20v-4h4M8 9h8M8 13h5"/>') . '<span>' . esc_html__('Note', $d) . '</span></button>'
        . ($sale_products ? '<button type="button" class="passflow-pda-act is-sale" data-passflow-sale' . $lock_attr . ' data-booking="' . $id . '" data-first="' . esc_attr($p['first_name']) . '" data-last="' . esc_attr($p['last_name']) . '" data-email="' . esc_attr($p['email']) . '" data-phone="' . esc_attr($p['phone']) . '">' . $pda_icon('<circle cx="9" cy="20" r="1.4"/><circle cx="18" cy="20" r="1.4"/><path d="M2 3h3l2.6 12.2a1.5 1.5 0 001.5 1.2h8.6a1.5 1.5 0 001.5-1.1L21 8H6"/>') . '<span>' . esc_html__('Vente', $d) . '</span></button>' : '')
        . '</div>'
        . '</li>';
}

// Sans JavaScript, le bouton enregistre tout ; avec, chaque changement part tout de suite et le bouton est masqué.
$html .= '</ol>'
    . '<div class="passflow-rollcall-save" data-passflow-fallback><button type="submit" class="button button-primary button-hero">' . esc_html($open ? __("Enregistrer l'appel", $d) : __('Enregistrer les notes', $d)) . '</button></div>'
    . '</form>';
}

// Vente sur place : bouton « Vente » sur chaque participant, nouvelle vente ici ; le détail est dans l'onglet Caisse.
if ($sale_products) {
    $html .= '<div class="passflow-card passflow-sale-bar"><p class="passflow-muted">' . esc_html__('Bouton « Vente » sur chaque participant, ou nouvelle vente pour quelqu\'un d\'autre : payée sur place ou par lien envoyé par SMS.', $d) . '</p>'
        . '<p><button type="button" class="button button-primary" data-passflow-sale' . $lock_attr . '><span class="dashicons dashicons-cart" aria-hidden="true"></span> ' . esc_html__('Nouvelle vente', $d) . '</button> '
        . '<a class="button" href="' . esc_url($tab_url('caisse')) . '" data-passflow-tab="caisse" data-passflow-cashdesk-bar>' . esc_html(\Smbb\Passflow\Admin\Cashdesk::barLabel($summary)) . '</a></p></div>';
}

$html .= '<p class="passflow-rollcall-links"><a class="button" href="' . esc_url(View::actionUrl(Actions::EXPORT, array('id' => (int) $slot_id))) . '"><span class="dashicons dashicons-download" aria-hidden="true"></span> ' . esc_html__('Télécharger la liste', $d) . '</a>'
    . ($manager ? ' <a class="button" href="' . esc_url(View::url('passflow_slot', array('view' => 'details', 'id' => (int) $slot_id))) . '">' . esc_html__('Fiche du créneau', $d) . '</a>' : '')
    . '</p>';

// Enregistrement immédiat, recherche, filtres, jauge en direct et pointage badge.

$config = array(
    'url' => admin_url('admin-ajax.php'),
    'action' => \Smbb\Passflow\Admin\RollCall::AJAX,
    'nonce' => \Smbb\Passflow\Admin\RollCall::nonce($slot_id),
    'creneau' => (int) $slot_id,
    'open' => $open,
    // Douchette : « keys » = écoute du clavier sans champ (rafale + Entrée), « field » = champ focalisé.
    'capture' => \Smbb\Passflow\Support\Settings::get('scan_capture') === 'field' ? 'field' : 'keys',
    'now' => time(),
    'start' => $pda_start,
    'end' => $pda_end,
    'texts' => array(
        'startsIn' => __('Début %s', $d),
        'startedFor' => __('En cours', $d),
        'ended' => __('Terminé', $d),
        'allDay' => '', // déjà écrit sous le titre
        'pdaPresent' => __('%s arrivé', $d),
        'pdaAbsent' => __('%s absent', $d),
        'pdaNone' => __('%s à pointer', $d),
        'pdaUndo' => __('Annuler', $d),
        'pdaLocked' => __("Pointage fermé : ce n'est pas le jour de l'atelier.", $d),
        'saving' => __('Enregistrement…', $d),
        'saved' => __('Enregistré', $d),
        'error' => __('Non enregistré, réessayez', $d),
        'network' => __('Pas de réseau : non enregistré', $d),
        'badgeTitle' => __('Pointage badge', $d),
        'badgeHint' => __('Bipez le badge ou tapez son numéro, puis Entrée.', $d),
        'badgeHintKeys' => __('Bipez le badge, ou touchez le champ pour taper son numéro.', $d),
        'badgePlaceholder' => __('N° de badge', $d),
        'badgeOk' => __('Valider', $d),
        'badgeClose' => __('Fermer', $d),
        'badgeReady' => __('Prêt : bipez un badge', $d),
        'badgeDocs' => __('Document à signer manquant : %s', $d),
        'badgeDocsTap' => __('Touchez ici pour le faire signer', $d),
        'badgeKbOn' => __('Clavier virtuel affiché (toucher pour le masquer)', $d),
        'badgeKbOff' => __('Clavier virtuel masqué (toucher pour l\'afficher)', $d),
        'badgeCamera' => __('Lire le QR code avec la caméra', $d),
        'noCamera' => __('Caméra indisponible : autorisez-la dans le navigateur (le site doit être en https).', $d),
        'noReader' => __("Le lecteur de QR code ne s'est pas chargé.", $d),
    ),
);
if ($participants) {
    // Version = date du fichier : le navigateur reprend le JS et le CSS dès qu'ils changent.
    // Caméra (QR du billet) : en option dans la fenêtre Pointage badge.
    if ($open) {
        wp_enqueue_script('passflow-html5-qrcode', SMBB_PASSFLOW_URL . 'assets/vendor/html5-qrcode/html5-qrcode.min.js', array(), '2.3.8', true);
    }
    wp_enqueue_script('passflow-rollcall', SMBB_PASSFLOW_URL . 'assets/rollcall.js', $open ? array('passflow-html5-qrcode') : array(), SMBB_PASSFLOW_VERSION . '.' . (int) @filemtime(SMBB_PASSFLOW_PATH . 'assets/rollcall.js'), true);
    wp_enqueue_style('passflow-badge', SMBB_PASSFLOW_URL . 'assets/badge.css', array('smbb-passflow-admin'), SMBB_PASSFLOW_VERSION . '.' . (int) @filemtime(SMBB_PASSFLOW_PATH . 'assets/badge.css'));
    // « Faire signer » : la page des documents s'ouvre par-dessus l'appel (lightbox), pas à la place.
    if ($docs_enabled) {
        \Smbb\Passflow\Service\Documents::enqueueFrame();
    }
    wp_add_inline_script('passflow-rollcall', 'window.passflowRollcall=' . wp_json_encode($config) . ';', 'before');
}

// Deux onglets dans la même page : on passe de l'un à l'autre sans recharger (assets/cashdesk.js).
$out = $top_html;

if ($cashdesk_on) {
    $out .= '<section class="passflow-rollcall-panel" id="passflow-panel-participants" role="tabpanel" aria-labelledby="passflow-tab-participants" data-passflow-panel="participants"' . ($tab === 'participants' ? '' : ' hidden') . '>' . $html . '</section>'
        . '<section class="passflow-rollcall-panel" id="passflow-panel-cashdesk" role="tabpanel" aria-labelledby="passflow-tab-cashdesk" data-passflow-panel="caisse"' . ($tab === 'caisse' ? '' : ' hidden') . '>'
        . '<div data-passflow-cashdesk-panel aria-live="polite">'
        . \Smbb\Passflow\Admin\Cashdesk::panel($slot, $sale_orders, $summary, $manager, (bool) $sale_products, isset($_GET['q']) ? sanitize_text_field(wp_unslash($_GET['q'])) : '', isset($_GET['p']) ? absint($_GET['p']) : 1) // phpcs:ignore WordPress.Security.NonceVerification
        . '</div></section>';
} else {
    $out .= $html;
}

// Fenêtre de caisse, confirmations et messages : une seule fois pour les deux onglets.
if ($sale_products || $sale_orders) {
    $out .= \Smbb\Passflow\WooCommerce\OnsiteSales::register($slot, $sale_context, array(
        'ajax' => admin_url('admin-ajax.php'),
        'action' => \Smbb\Passflow\Admin\Cashdesk::AJAX,
        'nonce' => \Smbb\Passflow\Admin\Cashdesk::nonce($slot_id),
        'creneau' => (int) $slot_id,
        'baseUrl' => View::url('passflow_portal', array('creneau' => (int) $slot_id)),
    ));
}

// ---------- PDA : barre d'actions au pouce et feuille « Plus » ----------
$bar_icon = function ($path) {
    return '<svg viewBox="0 0 24 24" aria-hidden="true">' . $path . '</svg>';
};
$cart_icon = '<circle cx="9" cy="20" r="1.4"/><circle cx="18" cy="20" r="1.4"/><path d="M2 3h3l2.6 12.2a1.5 1.5 0 001.5 1.2h8.6a1.5 1.5 0 001.5-1.1L21 8H6"/>';
$cash_label = $summary ? \Smbb\Passflow\WooCommerce\OnsiteSales::money($summary['paid']) : '';
$void = '<span class="passflow-pda-tab is-void" aria-hidden="true"></span>';
$bar = '<nav class="passflow-pda-bar" aria-label="' . esc_attr__('Actions', $d) . '">'
    . '<a href="' . esc_url($tab_url('participants')) . '" class="passflow-pda-tab is-on" data-pda-list' . ($cashdesk_on ? ' data-passflow-tab="participants"' : '') . '>' . $bar_icon('<path d="M9 6h11M9 12h11M9 18h11M4 6h.01M4 12h.01M4 18h.01"/>') . '<span>' . esc_html__('Liste', $d) . '</span></a>'
    . ($open ? '<button type="button" class="passflow-pda-tab" data-passflow-walkin' . $lock_attr . '>' . $bar_icon('<path d="M12 5v14M5 12h14"/>') . '<span>' . esc_html__('Imprévu', $d) . '</span></button>' : $void)
    . ($badge_on ? '<button type="button" class="passflow-pda-tab is-big" data-pda-badge' . $lock_attr . '>' . $bar_icon('<rect x="3" y="5" width="18" height="14" rx="2"/><circle cx="9" cy="11" r="2"/><path d="M6 16c.6-1.4 1.7-2 3-2s2.4.6 3 2M14 10h4M14 13h3"/>') . '<span>' . esc_html__('Badge', $d) . '</span></button>' : $void)
    . ($sale_products
        // Caisse : ouvre directement la vente (tuiles) ; le détail des ventes est dans « Plus ».
        ? '<button type="button" class="passflow-pda-tab" data-passflow-sale data-pda-cash' . $lock_attr . '>' . $bar_icon($cart_icon) . '<span>' . esc_html__('Caisse', $d) . '</span><em data-passflow-cashdesk-tab>' . esc_html($cash_label) . '</em></button>'
        : ($cashdesk_on ? '<a href="' . esc_url($tab_url('caisse')) . '" class="passflow-pda-tab" data-passflow-tab="caisse" data-pda-cash>' . $bar_icon($cart_icon) . '<span>' . esc_html__('Caisse', $d) . '</span><em data-passflow-cashdesk-tab>' . esc_html($cash_label) . '</em></a>' : $void))
    . '<button type="button" class="passflow-pda-tab" data-pda-more-open aria-haspopup="dialog">' . $bar_icon('<circle cx="5" cy="12" r="1.2"/><circle cx="12" cy="12" r="1.2"/><circle cx="19" cy="12" r="1.2"/>') . '<span>' . esc_html__('Plus', $d) . '</span></button>'
    . '</nav>';

$more_link = function ($href, $icon, $label, $attrs = '') use ($bar_icon) {
    return '<a class="passflow-pda-more-item" href="' . esc_url($href) . '"' . $attrs . '>' . $bar_icon($icon) . '<span>' . esc_html($label) . '</span></a>';
};
$more = '<div class="passflow-pda-sheet" data-pda-more hidden role="dialog" aria-modal="true" aria-label="' . esc_attr__('Plus', $d) . '"><div class="passflow-pda-sheet-box"><span class="passflow-pda-grab" aria-hidden="true"></span>'
    . ($cashdesk_on ? $more_link($tab_url('caisse'), '<path d="M4 6h16M4 12h16M4 18h10"/>', __('Ventes de la journée', $d), ' data-passflow-tab="caisse" data-pda-more-close') : '')
    . ($cashdesk_on ? $more_link($tab_url('participants'), '<path d="M9 6h11M9 12h11M9 18h11M4 6h.01M4 12h.01M4 18h.01"/>', __('Liste des participants', $d), ' data-passflow-tab="participants" data-pda-more-close') : '')
    . $more_link(View::actionUrl(Actions::EXPORT, array('id' => (int) $slot_id)), '<path d="M12 4v11M7 10l5 5 5-5M5 20h14"/>', __('Télécharger la liste', $d))
    . ($manager ? $more_link(View::url('passflow_slot', array('view' => 'details', 'id' => (int) $slot_id)), '<rect x="4" y="4" width="16" height="16" rx="2"/><path d="M8 9h8M8 13h8M8 17h5"/>', __('Fiche du créneau', $d)) : '')
    . ($manager && \Smbb\Passflow\Admin\DayBoard::available($slot) ? $more_link(\Smbb\Passflow\Admin\DayBoard::url($slot_id), '<rect x="3" y="5" width="18" height="12" rx="2"/><path d="M8 21h8M12 17v4"/>', __('Mode Jour J', $d)) : '')
    . $more_link(View::url('passflow_portal'), '<rect x="4" y="5" width="16" height="15" rx="2"/><path d="M4 10h16M9 3v4M15 3v4"/>', __('Mon agenda', $d))
    . '<button type="button" class="passflow-pda-more-close" data-pda-more-close>' . esc_html__('Fermer', $d) . '</button>'
    . '</div></div>';

$out .= $bar . $more
    // Barre du bas et feuille « Plus » sorties au niveau de la page : un parent animé du thème
    // (transform) piégerait sinon les éléments « fixed » (barre hors de l'écran).
    . '<script>(function(){var l=document.createElement("div");l.className="passflow-admin passflow-pda-layer";document.body.appendChild(l);'
    . 'document.querySelectorAll(".passflow-pda-bar,[data-pda-more]").forEach(function(n){l.appendChild(n);});})();</script>';

View::page('<div class="passflow-portal">' . $out . '</div>', $header, $notices, __('Mes ateliers', $d), 'compact');
