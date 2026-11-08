<?php
/**
 * Formulaire d'une inscription (ajout manuel ou modification).
 *
 * @var \Smbb\WpCodeTool\Admin\Form $form
 */

use Smbb\Passflow\Admin\View;
use Smbb\Passflow\Repository\Slots;
use Smbb\Passflow\Repository\Lookup;
use Smbb\Passflow\Support\Choices;
use Smbb\Passflow\Support\Settings;

defined('ABSPATH') || exit;

$d = 'smbb-passflow';
$item = isset($item) && is_array($item) ? $item : array();
$is_new = empty($item['id']);

// Lien « Ajouter un participant » depuis la fiche d'un créneau : créneau pré-rempli.
if ($is_new && empty($item) && isset($_GET['slot_id'])) {
    $item = array('slot_id' => (string) absint($_GET['slot_id']), 'status' => 'confirmed', 'source' => 'manual');
    $form = new \Smbb\WpCodeTool\Admin\Form(array(
        'action_url' => $action_url,
        'item' => $item,
        'field_errors' => array(),
        'nonce_action' => 'smbb_codetool_save_' . $resource_name,
        'resource_name' => $resource_name,
        'resource' => $resource,
        'requested_id' => $requested_id,
        'resources' => $resources,
        'store' => $store,
    ));
}

$current_slot_id = !empty($item['slot_id']) ? (int) $item['slot_id'] : 0;
$slot_options = Slots::moveOptions(0, __('— Pas encore de date —', $d));

// Le créneau actuel reste sélectionnable même s'il est passé ou annulé.
if ($current_slot_id && !in_array((string) $current_slot_id, $slot_options, true)) {
    $current = Slots::find($current_slot_id);

    if ($current) {
        $slot_options = array(Lookup::slotLabel($current) . ' (' . Choices::label('slotStatuses', $current['status']) . ')' => (string) $current_slot_id) + $slot_options;
    }
}

$participant = array(
    $form->row(
        $form->text(__('Prénom', $d))->setName('first_name')->required(),
        $form->text(__('Nom', $d))->setName('last_name')->required()
    ),
    $form->row(
        $form->text(__('E-mail', $d))->setName('email'),
        $form->text(__('Téléphone', $d))->setName('phone')->setHelp(__('Pour le rappel SMS. Format 06… ou +33…', $d))
    ),
);

$extra = array();

if ((int) Settings::get('ask_level')) {
    $extra[] = $form->select(__('Niveau', $d))->setName('level')->setOptions(Choices::options('levels'));
}

if ((int) Settings::get('ask_age')) {
    $extra[] = $form->number(__('Âge', $d), array('min' => 1, 'max' => 120, 'step' => 1))->setName('age');
}

if ($extra) {
    $participant[] = call_user_func_array(array($form, 'row'), $extra);
}

if ((int) Settings::get('ask_allergies')) {
    $participant[] = $form->textarea(__('Allergies ou intolérances', $d), array('rows' => 2))->setName('allergies');
}

$participant[] = $form->textarea(__('Remarques', $d), array('rows' => 2))->setName('notes');

$fields = array(
    $form->section(__('Créneau', $d), __('« À confirmer » bloque la place en attendant le paiement ou l\'accord du participant ; sans date, elle attend un créneau. Les places restantes sont contrôlées à l\'enregistrement.', $d), array(
        $form->select(__('Créneau', $d))->setName('slot_id')->setOptions($slot_options),
        $form->row(
            $form->select(__('Statut', $d))->setName('status')->setOptions(Choices::options('bookingStatuses')),
            $form->select(__('Atelier (sans date)', $d))->setName('activity_id')->setOptions(Lookup::activityOptions(__('— Choisir —', $d)))
                ->showIf('slot_id', '')
        ),
    ))->setIcon('dashicons-clock'),
    $form->section(__('Participant', $d), '', $participant)->setIcon('dashicons-admin-users'),
    $form->section(__('Origine', $d), '', array(
        $form->row(
            $form->select(__('Source', $d))->setName('source')->setOptions(Choices::options('sources')),
            $form->number(__('Montant payé (€)', $d), array('min' => 0, 'step' => '0.01'))->setName('price_paid')
        ),
    ))->setIcon('dashicons-cart'),
);

if (!$is_new) {
    $fields[] = View::note(
        __('Annuler ou déplacer', $d),
        '<p><a class="button" href="' . esc_url(View::url('passflow_booking', array('view' => 'details', 'id' => (int) $item['id']))) . '">' . esc_html__('Ouvrir la fiche de l\'inscription', $d) . '</a></p>'
        . '<p class="description">' . esc_html__('Confirmation, report, annulation, déplacement et historique.', $d) . '</p>',
        'dashicons-randomize'
    );
}

$html = '<div class="form_container">' . $form->save($is_new ? __('Inscrire', $d) : __('Enregistrer', $d))->setFields($fields) . '</div>';

View::page($html, isset($page_header_html) ? $page_header_html : '', isset($notices_html) ? $notices_html : '', __('Inscription', $d));
