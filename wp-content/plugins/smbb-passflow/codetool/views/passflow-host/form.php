<?php
/**
 * Formulaire d'un intervenant.
 *
 * @var \Smbb\WpCodeTool\Admin\Form $form
 */

use Smbb\Passflow\Admin\View;
use Smbb\Passflow\Repository\Slots;
use Smbb\Passflow\Repository\Lookup;
use Smbb\Passflow\Support\Choices;

defined('ABSPATH') || exit;

$d = 'smbb-passflow';
$item = isset($item) && is_array($item) ? $item : array();
$is_new = empty($item['id']);

// Le compte WordPress fait l'identité : nom et e-mail viennent du compte, le téléphone y est enregistré.
$user = !empty($item['user_id']) ? get_userdata((int) $item['user_id']) : null;
$account_html = '';

if ($user) {
    // Identité lue sur le compte (WordPress + WooCommerce) : rien n'est ressaisi ici.
    $identity = \Smbb\Passflow\Service\Accounts::identity($user->ID);
    $phone = $identity ? $identity['phone'] : '';
    $account_html = View::definitionList(array(
        __('Nom', $d) => esc_html(trim($identity['first_name'] . ' ' . $identity['last_name'])),
        __('E-mail', $d) => esc_html($identity['email']),
        __('Téléphone (SMS)', $d) => $phone !== '' ? esc_html($phone) : '<span class="passflow-warning-tag"><span class="dashicons dashicons-warning" aria-hidden="true"></span>' . esc_html__('Aucun : pas de SMS', $d) . '</span>',
    ))
        . '<p class="passflow-account-summary">'
        . esc_html__('Ce sont les données du compte, les mêmes que WooCommerce (le téléphone est celui de facturation). Pour les changer :', $d) . ' '
        . '<a class="button button-small" href="' . esc_url(get_edit_user_link($user->ID)) . '#billing_phone">' . esc_html__('Modifier le compte', $d) . '</a>'
        . ' <span class="passflow-muted">' . esc_html(sprintf(__('identifiant : %s', $d), $user->user_login)) . '</span></p>';
} elseif (!$is_new && !empty($item['last_name'])) {
    $account_html = '<p class="passflow-allergy">' . esc_html(sprintf(__('%s n\'a pas encore de compte rattaché : cherchez-le ou créez-le ci-dessous.', $d), Lookup::personName($item))) . '</p>';
}

// Création d'un compte sans quitter la fiche (champs sans name : ils ne partent pas avec le formulaire).
$prefill = array(
    'email' => !$user && !empty($item['email']) ? $item['email'] : '',
    'first_name' => !$user && !empty($item['first_name']) ? $item['first_name'] : '',
    'last_name' => !$user && !empty($item['last_name']) ? $item['last_name'] : '',
    'phone' => !$user && !empty($item['phone']) ? $item['phone'] : '',
);
$create_html = '<details class="passflow-create-account"' . (!$user && !$is_new ? ' open' : '') . ' data-passflow-create-account data-nonce="' . esc_attr(wp_create_nonce(\Smbb\Passflow\Service\Accounts::AJAX_CREATE)) . '" data-url="' . esc_url(admin_url('admin-ajax.php')) . '">'
    . '<summary>' . esc_html__('Pas encore de compte ? Le créer', $d) . '</summary>'
    . '<div class="passflow-create-account-grid">'
    . '<label>' . esc_html__('Prénom', $d) . '<input type="text" data-field="first_name" value="' . esc_attr($prefill['first_name']) . '"></label>'
    . '<label>' . esc_html__('Nom', $d) . '<input type="text" data-field="last_name" value="' . esc_attr($prefill['last_name']) . '"></label>'
    . '<label>' . esc_html__('E-mail', $d) . '<input type="email" data-field="email" value="' . esc_attr($prefill['email']) . '"></label>'
    . '<label>' . esc_html__('Téléphone', $d) . '<input type="tel" data-field="phone" value="' . esc_attr($prefill['phone']) . '"></label>'
    . '</div>'
    . '<p><button type="button" class="button" data-passflow-create>' . esc_html__('Créer le compte et lui envoyer l\'accès', $d) . '</button> <span class="passflow-muted" data-passflow-create-message></span></p>'
    . '<p class="description">' . esc_html__('Il reçoit un e-mail pour choisir son mot de passe. Si un compte existe déjà avec cet e-mail, il est simplement sélectionné.', $d) . '</p>'
    . '</details>'
    . '<script>(function(){var box=document.querySelector("[data-passflow-create-account]");if(!box)return;var btn=box.querySelector("[data-passflow-create]");var msg=box.querySelector("[data-passflow-create-message]");'
    . 'btn.addEventListener("click",function(){var body=new URLSearchParams();body.set("action","' . esc_js(\Smbb\Passflow\Service\Accounts::AJAX_CREATE) . '");body.set("nonce",box.getAttribute("data-nonce"));'
    . 'box.querySelectorAll("[data-field]").forEach(function(i){body.set(i.getAttribute("data-field"),i.value);});btn.disabled=true;msg.textContent="…";'
    . 'fetch(box.getAttribute("data-url"),{method:"POST",credentials:"same-origin",body:body}).then(function(r){return r.json();}).then(function(res){btn.disabled=false;'
    . 'if(!res||!res.success){msg.textContent=(res&&res.data&&res.data.message)||"Erreur";return;}msg.textContent=res.data.message;var it=res.data.item;'
    . 'var field=document.querySelector("[data-smbb-search-field][data-resource=wp_user]");if(!field)return;field.querySelector("[data-smbb-search-value]").value=it.value;field.querySelector("[data-smbb-search-text]").value=it.label;'
    . 'var c=field.querySelector("[data-smbb-search-clear]");if(c)c.hidden=false;var sum=field.querySelector("[data-smbb-search-selection]");if(sum){sum.classList.add("has-selection");sum.innerHTML="";var a=document.createElement("span");a.className="smbb-codetool-search-selection-label";a.textContent=it.label;var b=document.createElement("small");b.className="smbb-codetool-search-selection-meta";b.textContent=it.meta||"";sum.appendChild(a);sum.appendChild(b);}'
    . 'var ph=document.querySelector("[name=phone]");var p=box.querySelector("[data-field=phone]");if(ph&&p&&!ph.value)ph.value=p.value;box.open=false;})'
    . '.catch(function(){btn.disabled=false;msg.textContent="Erreur réseau";});});})();</script>';

$fields = array(
    $form->section(__('Compte WordPress', $d), __('L\'intervenant se connecte avec ce compte et arrive sur son espace. Nom, e-mail et téléphone viennent du compte.', $d), array(
        $form->search(__('Compte', $d), array(
            'resource' => 'wp_user',
            'placeholder' => __('Nom, e-mail ou téléphone…', $d),
            'limit' => 15,
        ))->setName('user_id')->required(),
        $account_html,
        $create_html,
    ))->setIcon('dashicons-admin-users'),
    $form->section(__('Profil', $d), '', array(
        $form->select(__('Statut', $d))->setName('status')->setOptions(Choices::options('hostStatuses')),
        $form->image(__('Photo', $d))->setName('photo_id'),
        $form->textarea(__('Présentation', $d), array('rows' => 4))->setName('bio'),
    ))->setIcon('dashicons-businessperson'),
    $form->section(__('Notes internes', $d), '', array(
        $form->textarea(__('Notes', $d), array('rows' => 3))->setName('notes'),
    ))->setIcon('dashicons-edit'),
);

if (!$is_new) {
    $rows = '';

    foreach (Slots::upcoming(0, array('host_id' => (int) $item['id'])) as $slot) {
        $rows .= '<li><a href="' . esc_url(View::url('passflow_slot', array('view' => 'details', 'id' => (int) $slot['id']))) . '">' . esc_html(Lookup::slotLabel($slot)) . '</a> · ' . View::fill($slot['booked'], $slot['capacity']) . '</li>';
    }

    $fields[] = View::note(__('Créneaux à venir', $d), $rows !== '' ? '<ul class="passflow-list">' . $rows . '</ul>' : '<p class="passflow-muted">' . esc_html__('Aucun créneau à venir.', $d) . '</p>', 'dashicons-calendar-alt');
}

$html = '<div class="form_container">' . $form->save($is_new ? __("Créer l'intervenant", $d) : __('Enregistrer', $d))->setFields($fields) . '</div>';

View::page($html, isset($page_header_html) ? $page_header_html : '', isset($notices_html) ? $notices_html : '', __('Intervenant', $d));
