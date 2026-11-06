<?php
/**
 * Bons cadeaux : miroir des codes promo WooCommerce, avec une création simplifiée et le PDF.
 */

use Smbb\Tomo\Admin\Actions;
use Smbb\Tomo\Admin\View;
use Smbb\Tomo\WooCommerce\GiftCards;
use Smbb\Tomo\WooCommerce\Module;

defined('ABSPATH') || exit;

$d = 'smbb-tomo';
$header = isset($page_header_html) ? $page_header_html : '';
$notices = isset($notices_html) ? $notices_html : '';

if (!Module::active()) {
    View::page(View::note(__('WooCommerce requis', $d), esc_html__('Les bons cadeaux sont des codes promo WooCommerce : activez WooCommerce pour les gérer ici.', $d), 'dashicons-warning'), $header, $notices, __('Bons cadeaux', $d));
    return;
}

$filter = isset($_GET['etat']) ? sanitize_key(wp_unslash($_GET['etat'])) : 'unused';
$filter = in_array($filter, array('all', 'unused', 'used', 'expired'), true) ? $filter : 'unused';
$search = isset($_GET['q']) ? sanitize_text_field(wp_unslash($_GET['q'])) : '';
$created = isset($_GET['created']) ? absint($_GET['created']) : 0;
$counts = GiftCards::counts();
$rows = GiftCards::rows($filter, $search);
$states = GiftCards::states();
$lookup = isset($_GET['bon']) ? sanitize_text_field(wp_unslash($_GET['bon'])) : '';

// Réglages › Général › Bons d'achat › « Utiliser les bons cadeaux en boutique ».
$html = '';

if (\Smbb\Tomo\Support\Settings::giftInStore()) {
    // --- Utiliser un bon en boutique ----------------------------------------
    // On tape le code ou on scanne le QR code (caméra du téléphone ou de l'ordinateur), puis on confirme.
    wp_enqueue_script('tomo-html5-qrcode', SMBB_TOMO_URL . 'assets/vendor/html5-qrcode/html5-qrcode.min.js', array(), '2.3.8', true);

    $redeem = '<form method="get" class="tomo-redeem-search" data-tomo-redeem>'
        . '<input type="hidden" name="page" value="smbb-codetool-tomo_bons">'
        . '<label class="screen-reader-text" for="tomo-redeem-code">' . esc_html__('Code du bon', $d) . '</label>'
        . '<input type="text" id="tomo-redeem-code" name="bon" value="' . esc_attr($lookup) . '" placeholder="' . esc_attr__('Code du bon, ex. TOMO-7KQ4-M2XP', $d) . '" autocomplete="off" autocapitalize="characters" style="text-transform:uppercase">'
        . '<button type="submit" class="button button-primary">' . esc_html__('Vérifier', $d) . '</button>'
        . '<button type="button" class="button" data-tomo-scan><span class="dashicons dashicons-camera" aria-hidden="true"></span> ' . esc_html__('Scanner le QR code', $d) . '</button>'
        . '</form>'
        . '<div class="tomo-scan" data-tomo-scan-box hidden><div id="tomo-scan-reader"></div>'
        . '<p><button type="button" class="button" data-tomo-scan-stop>' . esc_html__('Fermer la caméra', $d) . '</button> <span class="tomo-muted" data-tomo-scan-message></span></p></div>';

    if ($lookup !== '') {
        $coupon = GiftCards::find($lookup);

        if (!$coupon) {
            $redeem .= '<div class="tomo-redeem-result is-error"><p><strong>' . esc_html__('Aucun bon ne correspond à ce code.', $d) . '</strong> ' . esc_html__('Vérifiez la saisie : les codes ne contiennent ni O ni I.', $d) . '</p></div>';
        } else {
            $found = GiftCards::row($coupon, get_post($coupon->get_id()));
            $facts = array_filter(array(
                $found['recipient'] !== '' ? sprintf(__('Pour %s', $d), $found['recipient']) : '',
                $found['email'],
                $found['expires'] !== '' ? sprintf(__("Valable jusqu'au %s", $d), $found['expires']) : '',
                !$found['gift'] ? __('Code promo WooCommerce (pas un bon Tomo à usage unique)', $d) : '',
            ));
            $result = '<p class="tomo-redeem-head"><span class="tomo-gift-code">' . esc_html($found['code']) . '</span> <strong class="tomo-redeem-amount">' . esc_html($found['amount']) . '</strong> '
                . '<span class="tomo-badge tomo-badge--' . esc_attr($found['state'] === 'unused' ? 'confirmed' : ($found['state'] === 'used' ? 'present' : 'cancelled')) . '">' . esc_html($states[$found['state']]) . '</span></p>'
                . ($facts ? '<p class="tomo-muted">' . esc_html(implode(' · ', $facts)) . '</p>' : '');

            if ($found['state'] === 'unused') {
                $result .= View::formStart(Actions::GIFT_REDEEM, $found['id'], 'tomo-redeem-confirm')
                    . '<input type="text" name="note" maxlength="190" placeholder="' . esc_attr__('Note (facultatif) : atelier, achat…', $d) . '">'
                    . '<button type="submit" class="button button-primary button-hero" onclick="return confirm(this.dataset.confirm)" data-confirm="' . esc_attr(sprintf(__('Confirmer l\'utilisation du bon %1$s (%2$s) ? Il ne sera plus valable.', $d), $found['code'], $found['amount'])) . '">'
                    . esc_html__("Confirmer l'utilisation", $d) . '</button>'
                    . '</form>'
                    . '<p class="description">' . esc_html__("Le bon est consommé tout de suite. Un e-mail part au bénéficiaire (si son e-mail est connu) et à l'adresse qui reçoit les nouvelles commandes WooCommerce.", $d) . '</p>';
            } elseif ($found['redeemed'] !== '') {
                $result .= '<p><strong>' . esc_html__('Déjà utilisé :', $d) . '</strong> ' . esc_html($found['redeemed']) . '</p>';
            } elseif ($found['used_by']) {
                $result .= '<p><strong>' . esc_html__('Déjà utilisé :', $d) . '</strong> ' . esc_html(implode(', ', $found['used_by'])) . '</p>';
            }

            $redeem .= '<div class="tomo-redeem-result is-' . esc_attr($found['state']) . '">' . $result . '</div>';
        }
    }

    $redeem .= '<script>(function(){var form=document.querySelector("[data-tomo-redeem]");if(!form)return;var box=document.querySelector("[data-tomo-scan-box]");var msg=box.querySelector("[data-tomo-scan-message]");var input=form.querySelector("[name=bon]");var scanner=null;'
        . 'function stop(){if(scanner){scanner.stop().catch(function(){}).then(function(){scanner.clear();scanner=null;});}box.hidden=true;}'
        . 'form.querySelector("[data-tomo-scan]").addEventListener("click",function(){if(typeof Html5Qrcode==="undefined"){msg.textContent=' . wp_json_encode(__("Le lecteur de QR code ne s'est pas chargé.", $d)) . ';box.hidden=false;return;}box.hidden=false;msg.textContent=' . wp_json_encode(__('Placez le QR code du bon devant la caméra.', $d)) . ';'
        . 'scanner=new Html5Qrcode("tomo-scan-reader");scanner.start({facingMode:"environment"},{fps:10,qrbox:{width:240,height:240}},function(text){input.value=text;stop();form.submit();},function(){}).catch(function(e){msg.textContent=' . wp_json_encode(__('Caméra indisponible : autorisez-la dans le navigateur (le site doit être en https), ou tapez le code.', $d)) . ';});});'
        . 'box.querySelector("[data-tomo-scan-stop]").addEventListener("click",stop);'
        . (($lookup === '') ? '' : 'var c=document.querySelector(".tomo-redeem-confirm [name=note]");if(c)c.focus();')
        . '})();</script>';

    $html = View::card(__('Utiliser un bon en boutique', $d), $redeem, 'tomo-gift-redeem');
}

// --- Nouveau bon ---------------------------------------------------------
$form = View::formStart(Actions::GIFT_CREATE, 0, 'tomo-gift-form')
    . '<div class="tomo-gift-fields">'
    . '<label><span>' . esc_html__('Montant (€)', $d) . ' <span class="required">*</span></span><input type="number" name="amount" min="1" step="0.01" required placeholder="65"></label>'
    . '<label><span>' . esc_html__('Code', $d) . '</span><input type="text" name="code" maxlength="40" placeholder="' . esc_attr__('Généré si vide', $d) . '" style="text-transform:uppercase"></label>'
    . '<label><span>' . esc_html__('Pour (facultatif)', $d) . '</span><input type="text" name="recipient" maxlength="120" placeholder="' . esc_attr__('Prénom Nom', $d) . '"></label>'
    . '<label><span>' . esc_html__('E-mail (facultatif)', $d) . '</span><input type="email" name="email" maxlength="190" placeholder="' . esc_attr__('Pour le prévenir quand il sert', $d) . '"></label>'
    . '<label><span>' . esc_html__("Valable jusqu'au (facultatif)", $d) . '</span><input type="date" name="expires" min="' . esc_attr(current_time('Y-m-d')) . '"></label>'
    . '<button type="submit" class="button button-primary"><span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span>' . esc_html__('Créer le bon', $d) . '</button>'
    . '</div>'
    . '<p class="description">' . esc_html__("Le bon devient un code promo WooCommerce : remise du montant sur le panier, une seule utilisation. Si la réservation coûte moins que le bon, le reste n'est pas conservé.", $d) . '</p>'
    . '</form>';

$html .= View::card(__('Nouveau bon cadeau', $d), $form, 'tomo-gift-new');

// --- Bon tout juste créé -------------------------------------------------
if ($created) {
    $coupon = new \WC_Coupon($created);

    if ($coupon->get_id()) {
        $row = GiftCards::row($coupon);
        $html .= View::card('', '<p class="tomo-gift-created"><span class="tomo-gift-code">' . esc_html($row['code']) . '</span> '
            . esc_html($row['amount']) . ($row['recipient'] !== '' ? ' · ' . esc_html($row['recipient']) : '') . ' '
            . '<a class="button button-primary" href="' . esc_url(View::actionUrl(Actions::GIFT_PDF, array('id' => $row['id']))) . '"><span class="dashicons dashicons-pdf" aria-hidden="true"></span>' . esc_html__('Télécharger le PDF', $d) . '</a></p>', 'tomo-gift-created-card');
    }
}

// --- Liste ----------------------------------------------------------------
$segments = array(
    'unused' => sprintf(__('Non utilisés (%d)', $d), $counts['unused']),
    'used' => sprintf(__('Utilisés (%d)', $d), $counts['used']),
    'expired' => sprintf(__('Expirés ou désactivés (%d)', $d), $counts['expired']),
    'all' => sprintf(__('Tous (%d)', $d), $counts['all']),
);
$tools = '<div class="tomo-gift-tools"><p class="tomo-segments">';

foreach ($segments as $key => $label) {
    $tools .= '<a class="' . ($filter === $key ? 'is-active' : '') . '" href="' . esc_url(View::url('tomo_bons', array('etat' => $key))) . '">' . esc_html($label) . '</a>';
}

$tools .= '</p><form method="get" class="tomo-gift-search">'
    . '<input type="hidden" name="page" value="smbb-codetool-tomo_bons"><input type="hidden" name="etat" value="all">'
    . '<input type="search" name="q" value="' . esc_attr($search) . '" placeholder="' . esc_attr__('Code ou destinataire', $d) . '">'
    . '<button class="button">' . esc_html__('Rechercher', $d) . '</button></form></div>';

if ($rows) {
    $body = '';

    foreach ($rows as $row) {
        $badge_class = $row['state'] === 'unused' ? 'confirmed' : ($row['state'] === 'used' ? 'present' : 'cancelled');
        $kind = $row['gift'] ? '' : ' <span class="tomo-muted">' . esc_html__('code promo', $d) . '</span>';
        $usage = $row['limit'] > 0
            ? sprintf(__('%1$d / %2$d', $d), $row['used'], $row['limit'])
            : sprintf(_n('%d fois', '%d fois', $row['used'], $d), $row['used']);

        $body .= '<tr' . ($row['id'] === $created ? ' class="tomo-row-new"' : '') . '>'
            . '<td><span class="tomo-gift-code">' . esc_html($row['code']) . '</span>' . $kind . '</td>'
            . '<td><strong>' . esc_html($row['amount']) . '</strong></td>'
            . '<td>' . esc_html($row['recipient']) . ($row['email'] !== '' ? '<br><span class="tomo-muted">' . esc_html($row['email']) . '</span>' : '') . '</td>'
            . '<td><span class="tomo-badge tomo-badge--' . esc_attr($badge_class) . '">' . esc_html($states[$row['state']]) . '</span>'
            . ($row['redeemed'] !== '' ? '<br><span class="tomo-muted">' . esc_html($row['redeemed']) . '</span>' : ($row['used_by'] ? '<br><span class="tomo-muted">' . esc_html(implode(', ', $row['used_by'])) . '</span>' : '')) . '</td>'
            . '<td>' . esc_html($usage) . '</td>'
            . '<td>' . esc_html($row['expires'] !== '' ? $row['expires'] : '—') . '</td>'
            . '<td>' . esc_html($row['created']) . '</td>'
            . '<td class="tomo-actions">'
            . '<a class="button button-small" href="' . esc_url(View::actionUrl(Actions::GIFT_PDF, array('id' => $row['id']))) . '"><span class="dashicons dashicons-pdf" aria-hidden="true"></span>' . esc_html__('PDF', $d) . '</a> '
            . ($row['edit_url'] !== '' ? '<a class="button button-small" href="' . esc_url($row['edit_url']) . '">' . esc_html__('Dans WooCommerce', $d) . '</a>' : '')
            . '</td></tr>';
    }

    $list = '<table class="widefat striped tomo-table tomo-gift-table"><thead><tr>'
        . '<th>' . esc_html__('Code', $d) . '</th>'
        . '<th>' . esc_html__('Montant', $d) . '</th>'
        . '<th>' . esc_html__('Pour', $d) . '</th>'
        . '<th>' . esc_html__('État', $d) . '</th>'
        . '<th>' . esc_html__('Utilisations', $d) . '</th>'
        . '<th>' . esc_html__('Expire le', $d) . '</th>'
        . '<th>' . esc_html__('Créé le', $d) . '</th>'
        . '<th></th>'
        . '</tr></thead><tbody>' . $body . '</tbody></table>';
} else {
    $list = '<p class="tomo-muted">' . esc_html($search !== '' ? __('Aucun code ne correspond.', $d) : __('Aucun code dans cette catégorie.', $d)) . '</p>';
}

$html .= View::card(__('Codes WooCommerce', $d), $tools . $list . '<p class="description">' . esc_html__('Cette liste lit directement les codes promo de WooCommerce : un code créé ou modifié dans WooCommerce apparaît ici, et inversement.', $d) . '</p>');

View::page($html, $header, $notices, __('Bons cadeaux', $d));
