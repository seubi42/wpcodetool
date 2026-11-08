<?php
/**
 * Formulaire d'une demande en liste d'attente.
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

// Lien depuis la fiche d'un créneau : créneau pré-rempli.
if ($is_new && empty($item) && isset($_GET['slot_id'])) {
    $item = array('slot_id' => (string) absint($_GET['slot_id']), 'places' => '1', 'status' => 'waiting');
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

$options = Slots::moveOptions(0, __('— Choisir —', $d));

if (!empty($item['slot_id']) && !in_array((string) $item['slot_id'], $options, true)) {
    $current = Slots::find($item['slot_id']);

    if ($current) {
        $options = array(Lookup::slotLabel($current) => (string) $current['id']) + $options;
    }
}

$fields = array(
    $form->section(__('Demande', $d), __("Les demandes sont servies dans l'ordre d'arrivée.", $d), array(
        $form->select(__('Créneau', $d))->setName('slot_id')->setOptions($options)->required(),
        $form->row(
            $form->number(__('Places souhaitées', $d), array('min' => 1, 'max' => 20, 'step' => 1))->setName('places'),
            $form->select(__('Statut', $d))->setName('status')->setOptions(Choices::options('waitlistStatuses'))
        ),
    ))->setIcon('dashicons-list-view'),
    $form->section(__('Personne', $d), '', array(
        $form->row(
            $form->text(__('Prénom', $d))->setName('first_name'),
            $form->text(__('Nom', $d))->setName('last_name')->required()
        ),
        $form->row(
            $form->text(__('E-mail', $d))->setName('email'),
            $form->text(__('Téléphone', $d))->setName('phone')
        ),
        $form->textarea(__('Remarques', $d), array('rows' => 2))->setName('notes'),
    ))->setIcon('dashicons-admin-users'),
);

$html = '<div class="form_container">' . $form->save($is_new ? __("Ajouter à la liste d'attente", $d) : __('Enregistrer', $d))->setFields($fields) . '</div>';

View::page($html, isset($page_header_html) ? $page_header_html : '', isset($notices_html) ? $notices_html : '', __("Liste d'attente", $d));
