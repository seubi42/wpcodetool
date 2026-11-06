<?php
/**
 * Écran d'appel d'une date, inclus par dashboard.php (variables $creneau_id, $intervenant, $manager, $d…).
 * Pensé pour le téléphone : une carte par participant, Présent / Absent, une note facultative.
 */

use Smbb\Tomo\Admin\Actions;
use Smbb\Tomo\Admin\View;
use Smbb\Tomo\Repository\Creneaux;
use Smbb\Tomo\Repository\Inscriptions;
use Smbb\Tomo\Repository\Lookup;
use Smbb\Tomo\Support\Choices;

defined('ABSPATH') || exit;

$back = '<p class="tomo-appel-back"><a href="' . esc_url(View::url('tomo_espace')) . '"><span class="dashicons dashicons-arrow-left-alt2" aria-hidden="true"></span> ' . esc_html__('Mon agenda', $d) . '</a></p>';
$creneau = Creneaux::find($creneau_id);

if (!$creneau || !Actions::canSeeCreneau($creneau_id)) {
    View::page($back . View::card('', '<p>' . esc_html__("Cette date n'existe pas ou ne vous est pas attribuée.", $d) . '</p>'), $header, $notices, __('Mes ateliers', $d));
    return;
}

$stats = Creneaux::withStats(array($creneau));
$creneau = $stats[0];
$atelier = Lookup::atelier($creneau['atelier_id']);
// Pointage possible sur toute date non annulée ; hors du jour de l'atelier, il est verrouillé
// jusqu'à ce qu'on active « Pointer quand même ».
$open = $creneau['status'] !== 'cancelled' && !empty($creneau['start_date']);
$is_day = Actions::isRollCallDay($creneau);
$lock = $open && !$is_day;
$lock_attr = $lock ? ' disabled data-tomo-lockable' : ' data-tomo-lockable';
$force_input = '<input type="hidden" name="hors_date" value="0" data-tomo-force>';
// Caisse : catalogues de cet atelier et de cet intervenant (le gestionnaire voit tout).
$sale_context = $manager ? null : ($intervenant ? (int) $intervenant['id'] : 0);
$sale_products = $open && \Smbb\Tomo\WooCommerce\Module::active() ? \Smbb\Tomo\WooCommerce\OnsiteSales::products($atelier, $sale_context) : array();
$participants = Inscriptions::forCreneau($creneau_id);

// Ordre alphabétique : la liste ne bouge pas pendant l'appel.
usort($participants, function ($a, $b) {
    return strcasecmp(remove_accents($a['last_name'] . ' ' . $a['first_name']), remove_accents($b['last_name'] . ' ' . $b['first_name']));
});

// En-tête : l'atelier, la date, le lieu, le remplissage.
$facts = array(esc_html(Lookup::creneauWhen($creneau)));

if ($atelier && $atelier['location']) {
    $facts[] = esc_html($atelier['location']);
}

$names = Lookup::intervenantNames($creneau['intervenant_ids']);

if ($names !== '') {
    $facts[] = esc_html(sprintf(__('Avec %s', $d), $names));
}

$html = $back . '<div class="tomo-card tomo-appel-head">'
    . '<h2>' . esc_html($atelier ? $atelier['name'] : __('Atelier', $d)) . ' ' . ($creneau['status'] !== 'open' ? Choices::creneauBadge($creneau) : '') . '</h2>'
    . '<p class="tomo-muted">' . implode(' · ', $facts) . '</p>'
    . '</div>';

// Jauge de l'appel : affichée en tête de l'onglet Participants.
$gauge_html = '<div class="tomo-card tomo-appel-stats">' . \Smbb\Tomo\Admin\RollCall::gauge(\Smbb\Tomo\Admin\RollCall::counts($creneau_id))
    . ((int) $creneau['waiting'] > 0 ? '<p class="tomo-muted">' . esc_html(sprintf(_n("%d personne en liste d'attente", "%d personnes en liste d'attente", (int) $creneau['waiting'], $d), (int) $creneau['waiting'])) . '</p>' : '')
    . '</div>';

if ($creneau['status'] === 'cancelled') {
    $html .= View::note(__('Date annulée', $d), '<p>' . esc_html__('Cette date a été annulée : les réservations ont été retirées.', $d) . '</p>', 'dashicons-dismiss');
    View::page('<div class="tomo-espace">' . $html . '</div>', $header, $notices, __('Mes ateliers', $d));
    return;
}

// Onglets : Participants (l'appel) et Caisse (ventes additionnelles de la date, chiffre d'affaires).
$sale_orders = \Smbb\Tomo\WooCommerce\Module::active() ? \Smbb\Tomo\WooCommerce\OnsiteSales::forCreneau($creneau_id) : array();
$caisse_on = $sale_products || $sale_orders;
$tab = $caisse_on && isset($_GET['onglet']) && sanitize_key(wp_unslash($_GET['onglet'])) === 'caisse' ? 'caisse' : 'participants'; // phpcs:ignore WordPress.Security.NonceVerification
$tab_url = function ($name) use ($creneau_id) {
    return View::url('tomo_espace', $name === 'caisse' ? array('creneau' => (int) $creneau_id, 'onglet' => 'caisse') : array('creneau' => (int) $creneau_id));
};

$summary = $caisse_on ? \Smbb\Tomo\WooCommerce\OnsiteSales::summary($sale_orders) : null;

if ($caisse_on) {
    $html .= '<nav class="tomo-appel-tabs" role="tablist" aria-label="' . esc_attr__('Vues de la date', $d) . '" data-tomo-tabs>'
        . '<a href="' . esc_url($tab_url('participants')) . '" role="tab" id="tomo-tab-participants" aria-controls="tomo-panel-participants" aria-selected="' . ($tab === 'participants' ? 'true' : 'false') . '" data-tomo-tab="participants"' . ($tab === 'participants' ? ' class="is-active"' : '') . '><span class="dashicons dashicons-groups" aria-hidden="true"></span> ' . esc_html__('Participants', $d) . ' <span class="tomo-appel-tab-n">' . count($participants) . '</span></a>'
        . '<a href="' . esc_url($tab_url('caisse')) . '" role="tab" id="tomo-tab-caisse" aria-controls="tomo-panel-caisse" aria-selected="' . ($tab === 'caisse' ? 'true' : 'false') . '" data-tomo-tab="caisse"' . ($tab === 'caisse' ? ' class="is-active"' : '') . '><span class="dashicons dashicons-cart" aria-hidden="true"></span> ' . esc_html__('Caisse', $d) . ' <span class="tomo-appel-tab-n" data-tomo-caisse-tab>' . esc_html(\Smbb\Tomo\WooCommerce\OnsiteSales::money($summary['paid'])) . '</span></a>'
        . '</nav>';
}

// Pas le jour de l'atelier : pointage, scan et imprévus verrouillés derrière un interrupteur.
if ($lock) {
    $when = date_i18n('l j F', strtotime($creneau['start_date'] . ' 12:00:00'));
    $html .= '<div class="tomo-appel-lock" data-tomo-lock>'
        . '<span class="dashicons dashicons-lock" aria-hidden="true"></span>'
        . '<div><strong>' . esc_html(sprintf(__("Ce n'est pas le jour de l'atelier (%s)", $d), $when)) . '</strong>'
        . '<p class="tomo-muted">' . esc_html__('Le pointage et la caisse sont fermés pour éviter de se tromper de date. Les notes restent modifiables.', $d) . '</p></div>'
        . '<label class="tomo-force-switch"><input type="checkbox" data-tomo-force-switch><span class="tomo-force-switch-ui" aria-hidden="true"></span><span>' . esc_html__('Accès forcé', $d) . '</span></label>'
        . '</div>'
        . '<script>(function(){var sw=document.querySelector("[data-tomo-force-switch]");if(!sw)return;sw.addEventListener("change",function(){var on=sw.checked;'
        . 'document.querySelectorAll("[data-tomo-lockable]").forEach(function(el){el.disabled=!on;});'
        . 'document.querySelectorAll("[data-tomo-force]").forEach(function(el){el.value=on?"1":"0";});'
        . 'document.querySelectorAll("form.tomo-appel").forEach(function(f){f.classList.toggle("is-locked",!on);});'
        . 'var box=document.querySelector("[data-tomo-lock]");if(box)box.classList.toggle("is-unlocked",on);'
        . 'window.tomoForce=on;document.documentElement.classList.toggle("tomo-forced",on);'
        . 'if(window.tomoAppel)window.tomoAppel.force=on;});})();</script>';
}

// Le bandeau ci-dessus vaut pour les deux onglets ; l'onglet Participants commence ici.
$top_html = $html;
$html = $gauge_html;

// Participant imprévu : formulaire replié, ouvert par le bouton « Imprévu » à côté de Scanner.
$walkin = '';

if ($open) {
    $walkin = '<div class="tomo-card tomo-walkin" id="tomo-walkin" hidden>'
        . View::formStart(Actions::WALKIN, $creneau_id, 'tomo-walkin-form') . $force_input
        . '<h3>' . esc_html__('Ajouter un participant imprévu', $d) . '</h3>'
        . '<p class="tomo-muted">' . esc_html__('Ajouté même si la date est complète. Avec un e-mail, son compte client est repris, ou créé s\'il n\'existe pas : il pourra signer ses documents ici.', $d) . '</p>'
        . '<div class="tomo-walkin-fields">'
        . '<label>' . esc_html__('Prénom', $d) . ' *<input type="text" name="walkin[first_name]" required autocomplete="off"></label>'
        . '<label>' . esc_html__('Nom', $d) . ' *<input type="text" name="walkin[last_name]" required autocomplete="off"></label>'
        . '<label>' . esc_html__('E-mail', $d) . '<input type="email" name="walkin[email]" autocomplete="off" inputmode="email"></label>'
        . '<label>' . esc_html__('Téléphone', $d) . '<input type="tel" name="walkin[phone]" autocomplete="off" inputmode="tel"></label>'
        . '</div>'
        . '<p class="tomo-walkin-actions"><button type="button" class="button" data-tomo-walkin-close>' . esc_html__('Annuler', $d) . '</button> '
        . '<button type="button" class="button button-primary" data-tomo-walkin-choose>' . esc_html__('Ajouter', $d) . '</button></p>'
        // Choix du paiement, en fenêtre : les deux boutons envoient le formulaire.
        . '<div class="tomo-walkin-modal" data-tomo-walkin-modal hidden role="dialog" aria-modal="true" aria-labelledby="tomo-walkin-q"><div class="tomo-walkin-modal-box">'
        . '<h3 id="tomo-walkin-q">' . esc_html__('Et le paiement ?', $d) . '</h3>'
        . '<button type="submit" name="mode" value="paid" class="tomo-walkin-choice"><span class="dashicons dashicons-yes-alt" aria-hidden="true"></span><span><strong>' . esc_html__('Ajout direct, prestation déjà payée', $d) . '</strong><small>' . esc_html__('Ajouté tout de suite à la liste et pointé présent.', $d) . '</small></span></button>'
        . (\Smbb\Tomo\WooCommerce\Module::active()
            ? '<button type="submit" name="mode" value="sms" class="tomo-walkin-choice"><span class="dashicons dashicons-smartphone" aria-hidden="true"></span><span><strong>' . esc_html__('Paiement par SMS avant ajout', $d) . '</strong><small>' . esc_html__('Il reçoit un lien de paiement qui le connecte tout seul (10 minutes). Ajouté et pointé présent dès qu\'il a payé.', $d) . '</small></span></button>'
            : '')
        . '<p class="tomo-walkin-modal-foot"><button type="button" class="button-link" data-tomo-walkin-back>' . esc_html__('Retour', $d) . '</button></p>'
        . '</div></div>'
        . '</form></div>'
        . '<script>(function(){var box=document.getElementById("tomo-walkin");if(!box)return;'
        . 'function show(on){box.hidden=!on;if(on){box.scrollIntoView({behavior:"smooth",block:"start"});var f=box.querySelector("input[type=text]");if(f)setTimeout(function(){f.focus();},200);}}'
        . 'var modal=box.querySelector("[data-tomo-walkin-modal]"),form=box.querySelector("form");'
        . 'document.addEventListener("click",function(e){if(e.target.closest("[data-tomo-walkin]")){e.preventDefault();show(box.hidden);}else if(e.target.closest("[data-tomo-walkin-close]")){show(false);}'
        . 'else if(e.target.closest("[data-tomo-walkin-choose]")){if(form.reportValidity())modal.hidden=false;}'
        . 'else if(e.target.closest("[data-tomo-walkin-back]")||e.target===modal){modal.hidden=true;}});'
        . 'document.addEventListener("keydown",function(e){if(e.key==="Escape"&&!modal.hidden)modal.hidden=true;});'
        . 'form.addEventListener("keydown",function(e){if(e.key==="Enter"&&e.target.tagName==="INPUT"){e.preventDefault();if(form.reportValidity())modal.hidden=false;}});'
        . 'form.addEventListener("submit",function(e){var m=e.submitter&&e.submitter.value;if(m==="sms"){var em=form.querySelector("[name=\'walkin[email]\']").value.trim(),ph=form.querySelector("[name=\'walkin[phone]\']").value.trim();if(!em&&!ph){e.preventDefault();alert(' . wp_json_encode(__('Pour le paiement par SMS, indiquez au moins un téléphone ou un e-mail.', $d)) . ');}}});'
        . 'if(/[?&]imprevu=1/.test(location.search))show(true);})();</script>';
    $html .= $walkin;

    // Imprévus qui n'ont pas encore payé : ils arrivent sur la liste au paiement.
    $waiting_pay = \Smbb\Tomo\WooCommerce\Module::active() ? \Smbb\Tomo\WooCommerce\Walkins::pending($creneau_id) : array();

    if ($waiting_pay) {
        $items = '';

        foreach ($waiting_pay as $order) {
            $items .= '<li><span><strong>' . esc_html(trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name())) . '</strong> · ' . esc_html(\Smbb\Tomo\WooCommerce\OnsiteSales::total($order)) . '</span> '
                . '<span class="tomo-badge tomo-badge--pending">' . esc_html__('En attente de paiement', $d) . '</span> '
                . '<a class="button button-small" href="' . esc_url(View::actionUrl(Actions::ONSITE_RESEND, array('id' => (int) $order->get_id()))) . '">' . esc_html__('Renvoyer le lien', $d) . '</a> '
                . '<a class="button button-small tomo-caisse-cancel" href="' . esc_url(View::actionUrl(Actions::ONSITE_CANCEL, array('id' => (int) $order->get_id()))) . '" data-tomo-cancel-order="' . (int) $order->get_id() . '" data-client="' . esc_attr(trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name())) . '" data-order="' . esc_attr((string) $order->get_order_number()) . '" data-total="' . esc_attr(\Smbb\Tomo\WooCommerce\OnsiteSales::total($order)) . '">' . esc_html__('Annuler', $d) . '</a></li>';
        }

        $html .= '<div class="tomo-walkin-pending"><p><span class="dashicons dashicons-smartphone" aria-hidden="true"></span> ' . esc_html__('Imprévus en attente de paiement : ils arrivent sur la liste, pointés présents, dès le paiement.', $d) . '</p><ul>' . $items . '</ul></div>';
    }
}

if (!$participants) {
    $html .= ($open ? '<p><button type="button" class="button" data-tomo-walkin' . $lock_attr . '><span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span> ' . esc_html__('Imprévu', $d) . '</button></p>' : '');
    $html .= View::card('', '<p class="tomo-muted">' . esc_html__("Pas encore d'inscrit sur cette date.", $d) . '</p>');
} else {

$html .= View::formStart(Actions::ROLL_CALL, $creneau_id, 'tomo-appel' . ($open ? ' is-open' : '') . ($lock ? ' is-locked' : '')) . $force_input;

// Outils : recherche, filtres, scan du billet. Restent visibles en haut pendant le défilement.
$html .= '<div class="tomo-appel-tools">'
    . '<div class="tomo-appel-search"><span class="dashicons dashicons-search" aria-hidden="true"></span>'
    . '<input type="search" data-tomo-search placeholder="' . esc_attr__('Nom, prénom ou téléphone…', $d) . '" autocomplete="off" aria-label="' . esc_attr__('Rechercher un participant', $d) . '">'
    . ($open ? '<button type="button" class="button button-primary" data-tomo-scan' . $lock_attr . ' aria-label="' . esc_attr__('Scanner un billet', $d) . '"><span class="dashicons dashicons-camera" aria-hidden="true"></span><span class="tomo-btn-label"> ' . esc_html__('Scanner', $d) . '</span></button>'
        . '<button type="button" class="button" data-tomo-walkin' . $lock_attr . ' title="' . esc_attr__('Ajouter un participant imprévu', $d) . '"><span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span><span class="tomo-btn-label"> ' . esc_html__('Imprévu', $d) . '</span></button>' : '')
    . '</div>'
    . '<div class="tomo-appel-filters" role="group" aria-label="' . esc_attr__('Filtrer', $d) . '">'
    . '<button type="button" class="is-active" data-tomo-filter="all">' . esc_html__('Tous', $d) . ' <span data-filter-count="all"></span></button>'
    . '<button type="button" data-tomo-filter="none">' . esc_html__('À pointer', $d) . ' <span data-filter-count="none"></span></button>'
    . '<button type="button" data-tomo-filter="present">' . esc_html__('Présents', $d) . ' <span data-filter-count="present"></span></button>'
    . '<button type="button" data-tomo-filter="absent">' . esc_html__('Absents', $d) . ' <span data-filter-count="absent"></span></button>'
    . '</div>'
    . ($open ? '<div class="tomo-scan" data-tomo-scan-box hidden><div id="tomo-scan-reader"></div><p class="tomo-scan-result" data-tomo-scan-result aria-live="assertive"></p><button type="button" class="button" data-tomo-scan-close>' . esc_html__('Fermer la caméra', $d) . '</button></div>' : '')
    . '</div>'
    . '<p class="tomo-appel-empty" data-tomo-empty hidden>' . esc_html__('Personne ne correspond.', $d) . '</p>';

$html .= '<ol class="tomo-appel-list">';
$docs_enabled = \Smbb\Tomo\Service\Documents::enabled();
$doc_answers = $docs_enabled ? \Smbb\Tomo\Service\Documents::answers(array_column($participants, 'id')) : array();

foreach ($participants as $p) {
    $id = (int) $p['id'];
    $status = in_array($p['status'], array('present', 'absent'), true) ? $p['status'] : 'none';
    $infos = array();

    if (!empty($p['allergies'])) {
        $infos[] = '<span class="tomo-allergy"><span class="dashicons dashicons-warning" aria-hidden="true"></span> ' . esc_html__('Allergies :', $d) . ' ' . esc_html($p['allergies']) . '</span>';
    }

    if (!empty($p['level'])) {
        $infos[] = esc_html(Choices::label('levels', $p['level']));
    }

    if (!empty($p['age'])) {
        $infos[] = esc_html(sprintf(__('%d ans', $d), (int) $p['age']));
    }

    $who = '<strong>' . esc_html(Lookup::personName($p)) . '</strong>';

    if (!empty($p['pass_id'])) {
        $who .= ' <span class="tomo-badge tomo-badge--pass" title="' . esc_attr__('Place comprise dans un pass : le même QR sert chaque jour, les documents sont signés une seule fois.', $d) . '"><span class="dashicons dashicons-tickets-alt" aria-hidden="true"></span>' . esc_html__('Pass', $d) . '</span>';
    }

    if (isset($p['source']) && $p['source'] === 'walkin') {
        $who .= ' <span class="tomo-badge tomo-badge--walkin" title="' . esc_attr__('Ajouté sur place, sans réservation', $d) . '">' . esc_html__('Imprévu', $d) . '</span>';
    }

    if ($p['status'] === 'pending') {
        $who .= ' <span class="tomo-badge tomo-badge--pending" title="' . esc_attr(sprintf(__('Paiement ou accord pas encore confirmé par %s', $d), \Smbb\Tomo\Support\Settings::businessName())) . '">' . esc_html__('À confirmer', $d) . '</span>';
    }

    if (!empty($p['phone'])) {
        $who .= '<br><a class="tomo-muted" href="tel:' . esc_attr(preg_replace('/[^0-9+]/', '', $p['phone'])) . '">' . esc_html($p['phone']) . '</a>';
    }

    $search = strtolower(remove_accents(Lookup::personName($p) . ' ' . $p['first_name'] . ' ' . $p['email'])) . ' ' . preg_replace('/\D/', '', (string) $p['phone']);
    $html .= '<li class="tomo-appel-row" id="tomo-row-' . $id . '" data-id="' . $id . '" data-status="' . esc_attr($status) . '" data-walkin="' . (isset($p['source']) && $p['source'] === 'walkin' ? '1' : '0') . '" data-search="' . esc_attr($search) . '">'
        . '<div class="tomo-appel-who">' . $who
        . ($infos ? '<div class="tomo-appel-infos">' . implode(' · ', $infos) . '</div>' : '')
        . (!empty($p['notes']) ? '<div class="tomo-appel-remark">' . esc_html__('Remarque :', $d) . ' ' . esc_html($p['notes']) . '</div>' : '');

    // Documents : état de chacun, et « Faire signer » sur place s'il en manque.
    if ($docs_enabled) {
        $answers = isset($doc_answers[$id]) ? $doc_answers[$id] : array();
        $html .= '<div class="tomo-appel-docs">' . \Smbb\Tomo\Service\Documents::badges($answers)
            . (\Smbb\Tomo\Service\Documents::missing($answers) ? ' <a class="button button-small" href="' . esc_url(\Smbb\Tomo\Service\Documents::url($p, true)) . '">' . esc_html__('Faire signer', $d) . '</a>' : '')
            . '</div>';
    }

    if ($sale_products) {
        $html .= '<div class="tomo-appel-sale"><button type="button" class="button button-small" data-tomo-sale' . $lock_attr . ' data-inscription="' . $id . '" data-first="' . esc_attr($p['first_name']) . '" data-last="' . esc_attr($p['last_name']) . '" data-email="' . esc_attr($p['email']) . '" data-phone="' . esc_attr($p['phone']) . '"><span class="dashicons dashicons-cart" aria-hidden="true"></span> ' . esc_html__('Vente', $d) . '</button></div>';
    }

    $html .= '</div>';

    if ($open) {
        // Trois états côte à côte : Absent, À pointer, Présent. Chaque choix est enregistré aussitôt.
        $html .= '<fieldset class="tomo-presence" aria-label="' . esc_attr(sprintf(__('Pointage de %s', $d), Lookup::personName($p))) . '">';

        foreach (array('absent' => __('Absent', $d), 'none' => __('À pointer', $d), 'present' => __('Présent', $d)) as $value => $label) {
            $html .= '<label class="tomo-presence-' . $value . '"><input type="radio" name="presence[' . $id . ']" value="' . $value . '"' . checked($status, $value, false) . $lock_attr . '><span>' . esc_html($label) . '</span></label>';
        }

        $html .= '</fieldset>';
    }

    $note = (string) $p['intervenant_note'];
    $html .= '<details class="tomo-appel-note"' . ($note !== '' ? ' open' : '') . '><summary>' . esc_html($note !== '' ? __('Note', $d) : __('Ajouter une note', $d)) . '</summary>'
        . '<textarea name="note[' . $id . ']" data-tomo-note rows="2" placeholder="' . esc_attr(sprintf(__("Visible par l'équipe de %s", $d), \Smbb\Tomo\Support\Settings::businessName())) . '">' . esc_textarea($note) . '</textarea></details>'
        . '<span class="tomo-saved" data-tomo-saved aria-live="polite"></span>'
        . '</li>';
}

// Sans JavaScript, le bouton enregistre tout ; avec, chaque changement part tout de suite et le bouton est masqué.
$html .= '</ol>'
    . '<div class="tomo-appel-save" data-tomo-fallback><button type="submit" class="button button-primary button-hero">' . esc_html($open ? __("Enregistrer l'appel", $d) : __('Enregistrer les notes', $d)) . '</button></div>'
    . '</form>';
}

// Vente sur place : bouton « Vente » sur chaque participant, nouvelle vente ici ; le détail est dans l'onglet Caisse.
if ($sale_products) {
    $html .= '<div class="tomo-card tomo-sale-bar"><p class="tomo-muted">' . esc_html__('Bouton « Vente » sur chaque participant, ou nouvelle vente pour quelqu\'un d\'autre : payée sur place ou par lien envoyé par SMS.', $d) . '</p>'
        . '<p><button type="button" class="button button-primary" data-tomo-sale' . $lock_attr . '><span class="dashicons dashicons-cart" aria-hidden="true"></span> ' . esc_html__('Nouvelle vente', $d) . '</button> '
        . '<a class="button" href="' . esc_url($tab_url('caisse')) . '" data-tomo-tab="caisse" data-tomo-caisse-bar>' . esc_html(\Smbb\Tomo\Admin\Caisse::barLabel($summary)) . '</a></p></div>';
}

$html .= '<p><a class="button" href="' . esc_url(View::actionUrl(Actions::EXPORT, array('id' => (int) $creneau_id))) . '"><span class="dashicons dashicons-download" aria-hidden="true"></span> ' . esc_html__('Télécharger la liste', $d) . '</a>'
    . ($manager ? ' <a class="button" href="' . esc_url(View::url('tomo_creneau', array('view' => 'details', 'id' => (int) $creneau_id))) . '">' . esc_html__('Fiche du créneau', $d) . '</a>' : '')
    . '</p>';

// Enregistrement immédiat, recherche, filtres, jauge en direct et scan des billets.
if ($participants && $open) {
    wp_enqueue_script('tomo-html5-qrcode', SMBB_TOMO_URL . 'assets/vendor/html5-qrcode/html5-qrcode.min.js', array(), '2.3.8', true);
}

$config = array(
    'url' => admin_url('admin-ajax.php'),
    'action' => \Smbb\Tomo\Admin\RollCall::AJAX,
    'nonce' => \Smbb\Tomo\Admin\RollCall::nonce($creneau_id),
    'creneau' => (int) $creneau_id,
    'open' => $open,
    'texts' => array(
        'saving' => __('Enregistrement…', $d),
        'saved' => __('Enregistré', $d),
        'error' => __('Non enregistré, réessayez', $d),
        'network' => __('Pas de réseau : non enregistré', $d),
        'noCamera' => __('Caméra indisponible : autorisez-la dans le navigateur (le site doit être en https).', $d),
        'noReader' => __("Le lecteur de QR code ne s'est pas chargé.", $d),
        'aim' => __('Visez le QR code du billet.', $d),
    ),
);
if ($participants) {
    wp_enqueue_script('tomo-appel', SMBB_TOMO_URL . 'assets/appel.js', $open ? array('tomo-html5-qrcode') : array(), SMBB_TOMO_VERSION, true);
    wp_add_inline_script('tomo-appel', 'window.tomoAppel=' . wp_json_encode($config) . ';', 'before');
}

// Deux onglets dans la même page : on passe de l'un à l'autre sans recharger (assets/caisse.js).
$out = $top_html;

if ($caisse_on) {
    $out .= '<section class="tomo-appel-panel" id="tomo-panel-participants" role="tabpanel" aria-labelledby="tomo-tab-participants" data-tomo-panel="participants"' . ($tab === 'participants' ? '' : ' hidden') . '>' . $html . '</section>'
        . '<section class="tomo-appel-panel" id="tomo-panel-caisse" role="tabpanel" aria-labelledby="tomo-tab-caisse" data-tomo-panel="caisse"' . ($tab === 'caisse' ? '' : ' hidden') . '>'
        . '<div data-tomo-caisse-panel aria-live="polite">'
        . \Smbb\Tomo\Admin\Caisse::panel($creneau, $sale_orders, $summary, $manager, (bool) $sale_products, isset($_GET['q']) ? sanitize_text_field(wp_unslash($_GET['q'])) : '', isset($_GET['p']) ? absint($_GET['p']) : 1) // phpcs:ignore WordPress.Security.NonceVerification
        . '</div></section>';
} else {
    $out .= $html;
}

// Fenêtre de caisse, confirmations et messages : une seule fois pour les deux onglets.
if ($sale_products || $sale_orders) {
    $out .= \Smbb\Tomo\WooCommerce\OnsiteSales::register($creneau, $sale_context, array(
        'ajax' => admin_url('admin-ajax.php'),
        'action' => \Smbb\Tomo\Admin\Caisse::AJAX,
        'nonce' => \Smbb\Tomo\Admin\Caisse::nonce($creneau_id),
        'creneau' => (int) $creneau_id,
        'baseUrl' => View::url('tomo_espace', array('creneau' => (int) $creneau_id)),
    ));
}

View::page('<div class="tomo-espace">' . $out . '</div>', $header, $notices, __('Mes ateliers', $d));
