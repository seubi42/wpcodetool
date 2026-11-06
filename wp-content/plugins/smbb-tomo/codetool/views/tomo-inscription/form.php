<?php
/**
 * Formulaire d'une inscription (ajout manuel ou modification).
 *
 * @var \Smbb\WpCodeTool\Admin\Form $form
 */

use Smbb\Tomo\Admin\View;
use Smbb\Tomo\Repository\Creneaux;
use Smbb\Tomo\Repository\Lookup;
use Smbb\Tomo\Support\Choices;
use Smbb\Tomo\Support\Settings;

defined('ABSPATH') || exit;

$d = 'smbb-tomo';
$item = isset($item) && is_array($item) ? $item : array();
$is_new = empty($item['id']);

// Lien « Ajouter un participant » depuis la fiche d'un créneau : créneau pré-rempli.
if ($is_new && empty($item) && isset($_GET['creneau_id'])) {
    $item = array('creneau_id' => (string) absint($_GET['creneau_id']), 'status' => 'confirmed', 'source' => 'manual');
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

$current_creneau_id = !empty($item['creneau_id']) ? (int) $item['creneau_id'] : 0;
$creneau_options = Creneaux::moveOptions(0, __('— Pas encore de date —', $d));

// Le créneau actuel reste sélectionnable même s'il est passé ou annulé.
if ($current_creneau_id && !in_array((string) $current_creneau_id, $creneau_options, true)) {
    $current = Creneaux::find($current_creneau_id);

    if ($current) {
        $creneau_options = array(Lookup::creneauLabel($current) . ' (' . Choices::label('creneauStatuses', $current['status']) . ')' => (string) $current_creneau_id) + $creneau_options;
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
        $form->select(__('Créneau', $d))->setName('creneau_id')->setOptions($creneau_options),
        $form->row(
            $form->select(__('Statut', $d))->setName('status')->setOptions(Choices::options('inscriptionStatuses')),
            $form->select(__('Atelier (sans date)', $d))->setName('atelier_id')->setOptions(Lookup::atelierOptions(__('— Choisir —', $d)))
                ->showIf('creneau_id', '')
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
        '<p><a class="button" href="' . esc_url(View::url('tomo_inscription', array('view' => 'details', 'id' => (int) $item['id']))) . '">' . esc_html__('Ouvrir la fiche de l\'inscription', $d) . '</a></p>'
        . '<p class="description">' . esc_html__('Confirmation, report, annulation, déplacement et historique.', $d) . '</p>',
        'dashicons-randomize'
    );
}

$html = '<div class="form_container">' . $form->save($is_new ? __('Inscrire', $d) : __('Enregistrer', $d))->setFields($fields) . '</div>';

View::page($html, isset($page_header_html) ? $page_header_html : '', isset($notices_html) ? $notices_html : '', __('Inscription', $d));
