<?php
/**
 * Communication d'une journée : SMS et/ou e-mail à une partie des inscrits (urgence, retard,
 * changement de lieu, merci…), diffusés par la file d'envoi de CodeTool. Ouvert depuis la tuile
 * « Communication » de la fiche.
 *
 * @var array $item
 */

use Smbb\Passflow\Admin\Actions;
use Smbb\Passflow\Admin\View;
use Smbb\Passflow\Repository\Lookup;
use Smbb\Passflow\Repository\Slots;
use Smbb\Passflow\Service\Broadcasts;
use Smbb\Passflow\Support\Choices;
use Smbb\Passflow\Support\Roles;

defined('ABSPATH') || exit;

$d = 'smbb-passflow';
$header = isset($page_header_html) ? $page_header_html : '';
$notices = isset($notices_html) ? $notices_html : '';
$slot = !empty($item['id']) ? Slots::find($item['id']) : null;

if (!$slot || !Roles::canManage()) {
    View::page(View::card('', '<p>' . esc_html__('Créneau introuvable.', $d) . '</p>'), $header, $notices, __('Communication', $d));
    return;
}

$id = (int) $slot['id'];
$details_url = View::url('passflow_slot', array('view' => 'details', 'id' => $id));
$counts = array('consent' => Broadcasts::counts($id, true), 'urgent' => Broadcasts::counts($id, false));
$targets = Broadcasts::targets();
$gdpr_doc = \Smbb\Passflow\Service\Documents::gdprDoc();
$tally = Broadcasts::consentTally($id);

// Brouillon gardé après une erreur (rien à retaper).
$draft_key = 'passflow_broadcast_draft_' . get_current_user_id();
$draft = get_transient($draft_key);
$draft = is_array($draft) ? $draft : array();

if ($draft) {
    delete_transient($draft_key);
}

$target = isset($draft['target']) && isset($targets[$draft['target']]) ? $draft['target'] : 'all';
$channels = isset($draft['channels']) ? (array) $draft['channels'] : array('sms');
$subject = isset($draft['subject']) && $draft['subject'] !== '' ? $draft['subject'] : Broadcasts::defaultSubject();
$message = isset($draft['message']) ? (string) $draft['message'] : '';
$mode = isset($draft['audience']) && $draft['audience'] === 'urgent' ? 'urgent' : 'consent';

$html = '<p class="passflow-bc-back"><a href="' . esc_url($details_url) . '"><span class="dashicons dashicons-arrow-left-alt2" aria-hidden="true"></span> '
    . esc_html(sprintf(__('%1$s · %2$s', $d), Lookup::activityName($slot['activity_id']), Lookup::slotWhen($slot))) . '</a></p>';

// --- Nouvelle communication ---------------------------------------------------------------
$form = str_replace('class="passflow-bc-form"', 'class="passflow-bc-form" data-mode="' . esc_attr($mode) . '"', View::formStart(Actions::BROADCAST_SEND, $id, 'passflow-bc-form'));
$form .= '<h3 class="passflow-bc-h3">' . esc_html__('1. À qui ?', $d) . '</h3>';

// RGPD : avec consentement (par défaut) ou cas d'urgence, au-dessus des cibles.
$modes = array(
    'consent' => array(__('Avec consentement RGPD', $d), __("Pour démarchage commercial ou demande d'avis", $d), 'dashicons-shield'),
    'urgent' => array(__("Cas d'urgence", $d), __("Ne tient pas compte du consentement, pour cas d'urgence uniquement", $d), 'dashicons-warning'),
);
$form .= '<div class="passflow-bc-mode" role="radiogroup" aria-label="' . esc_attr__('Consentement RGPD', $d) . '">';

foreach ($modes as $key => $info) {
    $form .= '<label class="passflow-bc-mode-opt is-' . esc_attr($key) . '"><input type="radio" name="audience" value="' . esc_attr($key) . '"' . checked($mode, $key, false) . '>'
        . '<span class="dashicons ' . esc_attr($info[2]) . '" aria-hidden="true"></span><span><strong>' . esc_html($info[0]) . '</strong><small>' . esc_html($info[1]) . '</small></span></label>';
}

$documents_url = View::url('passflow_documents');
$form .= '</div>';

if ($gdpr_doc) {
    $form .= '<p class="passflow-bc-mode-info" data-show="consent"><span class="dashicons dashicons-shield" aria-hidden="true"></span> '
        . esc_html(sprintf(__('« %1$s » : %2$d oui · %3$d non · %4$d sans réponse. Seuls les « oui » reçoivent le message, et le consentement est vérifié encore au moment de l\'envoi.', $d), $gdpr_doc['title'], $tally['yes'], $tally['no'], $tally['none'])) . '</p>';
} else {
    $form .= '<p class="passflow-bc-mode-info is-warning" data-show="consent"><span class="dashicons dashicons-warning" aria-hidden="true"></span> '
        . sprintf(esc_html__('Aucun document ne porte le rôle « Consentement RGPD » : personne ne peut recevoir ce message dans ce mode. %s', $d), '<a href="' . esc_url($documents_url) . '">' . esc_html__('Régler les documents', $d) . '</a>') . '</p>';
}

$form .= '<p class="passflow-bc-mode-info is-urgent" data-show="urgent"><span class="dashicons dashicons-warning" aria-hidden="true"></span> '
    . esc_html__("Tous les inscrits de la cible reçoivent le message, consentants ou non. À réserver aux informations indispensables sur leur réservation (retard, changement de lieu, annulation, sécurité) : jamais pour de la promotion ou une demande d'avis.", $d) . '</p>';

$form .= '<div class="passflow-bc-targets" role="radiogroup">';

foreach ($targets as $key => $info) {
    $data = '';
    $inner = '';

    foreach ($counts as $m => $by_target) {
        $c = $by_target[$key];
        $data .= ' data-people-' . $m . '="' . (int) $c['people'] . '" data-email-' . $m . '="' . (int) $c['email'] . '" data-sms-' . $m . '="' . (int) $c['sms'] . '"';
        $inner .= '<span class="passflow-bc-n" data-show="' . esc_attr($m) . '">' . (int) $c['people'] . '</span>'
            . '<small data-show="' . esc_attr($m) . '">' . esc_html(sprintf(__('%1$d tél. · %2$d e-mail', $d), $c['sms'], $c['email'])) . '</small>';
    }

    $form .= '<label class="passflow-bc-choice' . ($counts[$mode][$key]['people'] === 0 ? ' is-empty' : '') . '"' . $data . '>'
        . '<input type="radio" name="target" value="' . esc_attr($key) . '"' . checked($target, $key, false) . '>'
        . '<strong>' . esc_html($info['label']) . '</strong>'
        . $inner
        . '<small>' . esc_html($info['hint']) . '</small>'
        . '</label>';
}

$form .= '</div><h3 class="passflow-bc-h3">' . esc_html__('2. Par où ?', $d) . '</h3><div class="passflow-bc-channels">';

foreach (array('sms' => array(__('SMS', $d), 'dashicons-smartphone'), 'email' => array(__('E-mail', $d), 'dashicons-email-alt')) as $channel => $info) {
    $form .= '<label class="passflow-bc-choice"><input type="checkbox" name="channels[]" value="' . esc_attr($channel) . '"' . checked(in_array($channel, $channels, true), true, false) . '>'
        . '<span class="dashicons ' . esc_attr($info[1]) . '" aria-hidden="true"></span><strong>' . esc_html($info[0]) . '</strong></label>';
}

$presets = array(
    __('Retard', $d) => __("Bonjour {prenom}, petit décalage pour {atelier} aujourd'hui : nous commençons 15 minutes plus tard. Merci de votre patience ! {expediteur}", $d),
    __('Changement de lieu', $d) => __('Bonjour {prenom}, attention : {atelier} du {date_courte} change de lieu. Rendez-vous à : … {expediteur}', $d),
    __('Annulation', $d) => __("Bonjour {prenom}, suite à un imprévu, {atelier} du {date_courte} ne peut pas avoir lieu. Nous revenons très vite vers vous pour une autre date. {expediteur}", $d),
    __('Absents', $d) => __("Bonjour {prenom}, nous ne vous avons pas vu à {atelier} aujourd'hui. Tout va bien ? Joignez-nous au {contact_telephone}. {expediteur}", $d),
    __('Merci', $d) => __("Merci {prenom} d'être venu(e) à {atelier} ! À très bientôt. {expediteur}", $d),
);
$consent_presets = array(__('Merci', $d));

// Questionnaire de satisfaction réglé : modèle « Demande d'avis » avec le lien personnel.
if (\Smbb\Passflow\Service\Reviews::ready()) {
    $presets[__("Demande d'avis", $d)] = __("Merci {prenom} d'être venu(e) à {atelier} ! Votre avis compte : 1 minute pour nous le donner ? {lien_avis} {expediteur}", $d);
    $consent_presets[] = __("Demande d'avis", $d);
}

$form .= '</div><h3 class="passflow-bc-h3">' . esc_html__('3. Le message', $d) . '</h3>'
    . '<p class="passflow-bc-field passflow-bc-subject"><label for="passflow-bc-subject">' . esc_html__("Objet de l'e-mail", $d) . '</label>'
    . '<input type="text" id="passflow-bc-subject" name="subject" value="' . esc_attr($subject) . '" maxlength="200"></p>'
    . '<div class="passflow-bc-presets">';

foreach ($presets as $label => $text) {
    $form .= '<button type="button" class="button button-small" data-passflow-preset="' . esc_attr($text) . '"' . (in_array($label, $consent_presets, true) ? ' data-passflow-mode="consent"' : '') . '>' . esc_html($label) . '</button>';
}

$form .= '</div><div class="passflow-bc-field"><label for="passflow-bc-message">' . esc_html__('Message', $d) . '</label>'
    . '<textarea id="passflow-bc-message" name="message" required>' . esc_textarea($message) . '</textarea>'
    . '<div class="passflow-bc-meter" aria-live="polite"></div>'
    . '<p class="description">' . esc_html__('Personnalisé pour chacun : {prenom}, {nom}, {atelier}, {date_courte}, {heure}, {lieu}, {adresse}, {lien_billet}, {lien_avis}, {contact_telephone}, {expediteur}.', $d) . '</p></div>'
    . '<p class="passflow-bc-submit"><button type="submit" class="button button-primary button-hero"><span class="dashicons dashicons-megaphone" aria-hidden="true"></span> ' . esc_html__('Envoyer', $d) . '</button></p>'
    . '<p class="description">' . esc_html__("Les messages partent dans la file d'envoi de CodeTool : la diffusion démarre tout de suite et se poursuit par lots, à la cadence réglée dans CodeTool › Communication › File d'envoi. Une même adresse ne reçoit le message qu'une fois.", $d) . '</p>'
    . '</form>';

$html .= View::card(__('Nouvelle communication', $d), $form, 'passflow-bc-new');

// --- Communications déjà envoyées ---------------------------------------------------------
$history = Broadcasts::forSlot($id);
$live = false;

if ($history) {
    $status_labels = array(
        'queued' => array(__('En file', $d), 'waiting'),
        'sending' => array(__('En cours', $d), 'notified'),
        'done' => array(__('Terminée', $d), 'sent'),
        'stopped' => array(__('Arrêtée', $d), 'cancelled'),
    );
    $rows = '';

    foreach ($history as $b) {
        $t = $b['tally'];
        $total = max(1, (int) $b['total']);
        $handled = $t['sent'] + $t['failed'] + $t['skipped'];
        $running = in_array($b['status'], array('queued', 'sending'), true);
        $live = $live || $running;
        $state = isset($status_labels[$b['status']]) ? $status_labels[$b['status']] : array($b['status'], 'draft');
        $author = !empty($b['created_by']) ? get_userdata((int) $b['created_by']) : null;
        $chan = '';

        foreach (array_filter(explode(',', (string) $b['channels'])) as $c) {
            $chan .= Choices::badge('channels', $c) . ' ';
        }

        $rows .= '<tr>'
            . '<td>' . View::datetime($b['created_at']) . ($author ? '<br><span class="passflow-muted">' . esc_html($author->display_name) . '</span>' : '') . '</td>'
            . '<td>' . esc_html(Broadcasts::targetLabel($b['target'])) . '<br>' . $chan
            . (($b['kind'] ?? '') === Broadcasts::KIND_REVIEW ? ' <span class="passflow-badge passflow-badge--confirmed">' . esc_html__('Avis (auto)', $d) . '</span>' : '')
            . (!empty($b['consent']) ? ' <span class="passflow-badge passflow-badge--sent" title="' . esc_attr__('Envoyé aux seuls consentants RGPD', $d) . '">' . esc_html__('RGPD', $d) . '</span>' : ' <span class="passflow-badge passflow-badge--absent" title="' . esc_attr__('Envoyé sans tenir compte du consentement', $d) . '">' . esc_html__('Urgence', $d) . '</span>')
            . '</td>'
            . '<td>' . esc_html(wp_trim_words((string) $b['message'], 22)) . '</td>'
            . '<td><span class="passflow-badge passflow-badge--' . esc_attr($state[1]) . '">' . esc_html($state[0]) . '</span>'
            . '<br><span class="passflow-muted">' . esc_html(sprintf(__('%1$d / %2$d envoyés', $d), $t['sent'], (int) $b['total'])) . '</span>'
            . ($t['failed'] ? '<br><span class="passflow-error">' . esc_html(sprintf(_n('%d échec', '%d échecs', $t['failed'], $d), $t['failed'])) . '</span>' : '')
            . ($t['skipped'] ? '<br><span class="passflow-muted">' . esc_html(sprintf(_n('%d ignoré', '%d ignorés', $t['skipped'], $d), $t['skipped'])) . '</span>' : '')
            . '<span class="passflow-bc-progress" aria-hidden="true"><span style="width:' . esc_attr((string) min(100, round($handled * 100 / $total))) . '%"></span></span></td>'
            . '<td>' . ($running ? '<a class="button button-small button-link-delete" href="' . esc_url(View::actionUrl(Actions::BROADCAST_STOP, array('id' => (int) $b['id']))) . '" data-smbb-confirm="' . esc_attr__('Arrêter la diffusion ? Les messages pas encore partis seront abandonnés.', $d) . '" onclick="return confirm(this.dataset.smbbConfirm)">' . esc_html__('Arrêter', $d) . '</a>' : '') . '</td>'
            . '</tr>';
    }

    $html .= View::card(__('Communications envoyées', $d),
        '<table class="widefat striped passflow-table"><thead><tr><th>' . esc_html__('Date', $d) . '</th><th>' . esc_html__('Cible', $d) . '</th><th>' . esc_html__('Message', $d) . '</th><th>' . esc_html__('Diffusion', $d) . '</th><th></th></tr></thead><tbody>' . $rows . '</tbody></table>'
        . '<p class="description">' . sprintf(
            /* translators: %s: lien vers la fiche */
            esc_html__('Le détail de chaque message (destinataire, résultat, erreur) est dans l\'onglet Messages de la %s.', $d),
            '<a href="' . esc_url($details_url) . '">' . esc_html__('fiche de la journée', $d) . '</a>'
        ) . '</p>'
        . (class_exists('Smbb\\WpCodeTool\\Admin\\Pages\\OutboxPage') && current_user_can(\Smbb\WpCodeTool\Outbox\Outbox::capability())
            ? '<p><a class="button" href="' . esc_url(\Smbb\WpCodeTool\Admin\Pages\OutboxPage::url(array('source' => Broadcasts::SOURCE))) . '"><span class="dashicons dashicons-controls-forward" aria-hidden="true"></span> ' . esc_html__("Suivre dans la file d'envoi", $d) . '</a></p>'
            : ''));
}

ob_start();
?>
<script>
(function () {
    var form = document.querySelector('.passflow-bc-form');
    if (!form) { return; }
    var text = form.querySelector('#passflow-bc-message');
    var meter = form.querySelector('.passflow-bc-meter');
    var subject = form.querySelector('.passflow-bc-subject');
    var gsm = /^[A-Za-z0-9 \r\n@£$¥èéùìòÇØøÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ!"#¤%&'()*+,\-.\/:;<=>?¡ÄÖÑÜ§¿äöñüà^{}\\\[~\]|€]*$/;
    function picked(name) { return Array.prototype.slice.call(form.querySelectorAll('input[name="' + name + '"]:checked')).map(function (i) { return i.value; }); }
    function mode() { var m = form.querySelector('input[name="audience"]:checked'); return m ? m.value : 'consent'; }
    function refresh() {
        var md = mode();
        form.setAttribute('data-mode', md);
        Array.prototype.forEach.call(form.querySelectorAll('.passflow-bc-targets .passflow-bc-choice'), function (box) {
            box.classList.toggle('is-empty', (parseInt(box.getAttribute('data-people-' + md), 10) || 0) === 0);
        });
        var ch = picked('channels[]');
        subject.style.display = ch.indexOf('email') >= 0 ? '' : 'none';
        var v = text.value, n = v.length, uni = !gsm.test(v);
        var one = uni ? 70 : 160, many = uni ? 67 : 153;
        var parts = n === 0 ? 0 : (n <= one ? 1 : Math.ceil(n / many));
        meter.textContent = ch.indexOf('sms') >= 0 && n ? <?php echo wp_json_encode(__('%1$d caractères · %2$d SMS par personne%3$s (avant remplacement des {variables})', 'smbb-passflow')); ?>.replace('%1$d', n).replace('%2$d', parts).replace('%3$s', uni ? <?php echo wp_json_encode(__(' · accents ou emoji : 70 caractères par SMS', 'smbb-passflow')); ?> : '') : '';
        meter.classList.toggle('is-long', parts > 2);
    }
    form.addEventListener('change', refresh);
    text.addEventListener('input', refresh);
    Array.prototype.forEach.call(form.querySelectorAll('[data-passflow-preset]'), function (b) {
        b.addEventListener('click', function () {
            text.value = b.getAttribute('data-passflow-preset');
            // Merci, demande d'avis : jamais en « Cas d'urgence ».
            var want = b.getAttribute('data-passflow-mode');
            var radio = want ? form.querySelector('input[name="audience"][value="' + want + '"]') : null;
            if (radio) { radio.checked = true; }
            text.focus(); refresh();
        });
    });
    form.addEventListener('submit', function (e) {
        var t = form.querySelector('input[name="target"]:checked');
        var ch = picked('channels[]');
        if (!t || !ch.length) { return; }
        var box = t.closest('.passflow-bc-choice'), total = 0;
        var md = mode();
        ch.forEach(function (c) { total += parseInt(box.getAttribute('data-' + c + '-' + md), 10) || 0; });
        var msg = (md === 'urgent'
            ? <?php echo wp_json_encode(__("CAS D'URGENCE : envoyer %d message(s) maintenant, sans tenir compte du consentement RGPD ?", 'smbb-passflow')); ?>
            : <?php echo wp_json_encode(__('Envoyer %d message(s) maintenant aux participants consentants ?', 'smbb-passflow')); ?>).replace('%d', total);
        if (!window.confirm(msg)) { e.preventDefault(); }
    });
    refresh();
    <?php if ($live) : ?>
    setTimeout(function () { if (!text.value) { window.location.reload(); } }, 15000);
    <?php endif; ?>
})();
</script>
<?php
$html .= ob_get_clean();

View::page($html, $header, $notices, __('Communication', $d));
