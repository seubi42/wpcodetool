<?php
/**
 * Formulaire d'un créneau.
 *
 * @var \Smbb\WpCodeTool\Admin\Form $form
 */

use Smbb\Tomo\Admin\View;
use Smbb\Tomo\Repository\Lookup;
use Smbb\Tomo\Support\Choices;

defined('ABSPATH') || exit;

$d = 'smbb-tomo';
$item = isset($item) && is_array($item) ? $item : array();
$is_new = empty($item['id']);
$current_status = isset($item['status']) ? (string) $item['status'] : '';

// « Complet » est automatique, « Annulé » passe par la fiche (pour reporter les réservations).
$statuses = Choices::creneauEditableStatuses();
unset($statuses['cancelled']);

if (in_array($current_status, array('full', 'cancelled'), true)) {
    $statuses = array($current_status => Choices::label('creneauStatuses', $current_status)) + $statuses;
}

$status_options = array_flip($statuses);
$intervenant_ids = Lookup::ids(isset($item['intervenant_ids']) ? $item['intervenant_ids'] : array());
$intervenants = Lookup::intervenantOptions($intervenant_ids);

// Intervenants en tableau : case à cocher (la vraie case du formulaire, déplacée par le script) et
// réponse à la demande de disponibilité. Sans script, la liste de cases classique reste affichée.
$staff_table = '';

if ($intervenants) {
    $dispos = !$is_new ? \Smbb\Tomo\Service\Availability::forCreneaux(array((int) $item['id'])) : array();
    $dispo_open = !$is_new && \Smbb\Tomo\Service\Availability::open($item);
    $rows = '';

    foreach ($intervenants as $label => $staff_id) {
        $row = isset($dispos[(int) $item['id']][(int) $staff_id]) ? $dispos[(int) $item['id']][(int) $staff_id] : null;
        $state = \Smbb\Tomo\Service\Availability::state($row);
        $answer = $is_new ? '<span class="tomo-muted">' . esc_html__('Demande possible après enregistrement', $d) . '</span>'
            : (($dispo_open || $row) ? \Smbb\Tomo\Service\Availability::badge($state) : '<span class="tomo-muted">—</span>');

        if ($row && !empty($row['answered_at'])) {
            $answer .= ' <span class="tomo-muted">' . esc_html(sprintf(__('le %1$s%2$s', $d), View::datetime($row['answered_at']), $row['source'] === 'admin' ? ' ' . __("(saisi par l'équipe)", $d) : '')) . '</span>';
        } elseif ($row && !empty($row['asked_at'])) {
            $answer .= ' <span class="tomo-muted">' . esc_html(sprintf(__('demandé le %s', $d), View::datetime($row['asked_at']))) . '</span>';
        }

        if ($row && !empty($row['campagne_id'])) {
            $answer .= ' <a href="' . esc_url(View::url('tomo_campagnes', array('demande' => (int) $row['campagne_id']))) . '">' . esc_html__('voir la demande', $d) . '</a>';
        }

        $rows .= '<tr><td><label data-tomo-staff-slot="' . esc_attr((string) $staff_id) . '"> ' . esc_html($label) . '</label></td><td>' . $answer . '</td></tr>';
    }

    $staff_table = '<table class="widefat striped tomo-staff-table" data-tomo-staff-table hidden><thead><tr><th>' . esc_html__('Intervenant', $d) . '</th><th>' . esc_html__('Disponibilité', $d) . '</th></tr></thead><tbody>' . $rows . '</tbody></table>'
        . '<script>document.addEventListener("DOMContentLoaded",function(){var t=document.querySelector("[data-tomo-staff-table]");if(!t)return;var boxes=document.querySelectorAll("input[type=checkbox][name*=\\"intervenant_ids\\"]");if(!boxes.length)return;var group=null;'
        . 'Array.prototype.forEach.call(boxes,function(b){var slot=t.querySelector("[data-tomo-staff-slot=\\""+b.value+"\\"]");if(!slot)return;group=group||b.closest("fieldset");var old=b.closest("label");slot.insertBefore(b,slot.firstChild);if(old)old.remove();});'
        . 'if(group){var wrap=group.closest(".smbb-codetool-field")||group;wrap.style.display="none";}t.hidden=false;});</script>';
}

// Pass plusieurs jours : jours proposés = dates à venir (et celles déjà choisies).
$pass_day_ids = Lookup::ids(isset($item['pass_creneau_ids']) ? $item['pass_creneau_ids'] : array());
$day_options = array();

foreach (\Smbb\Tomo\Repository\Creneaux::upcoming(0, array('statuses' => array('open', 'full', 'draft'))) as $day) {
    $day_options[Lookup::creneauLabel($day) . ' · ' . sprintf(_n('%d place libre', '%d places libres', $day['remaining'], $d), $day['remaining'])] = (string) $day['id'];
}

foreach ($pass_day_ids as $day_id) {
    if (!in_array((string) $day_id, $day_options, true)) {
        $day = \Smbb\Tomo\Repository\Creneaux::find($day_id);

        if ($day) {
            $day_options[Lookup::creneauLabel($day)] = (string) $day_id;
        }
    }
}

$kind_options = array(
    __('Une date', $d) => 'day',
    __('Pass plusieurs jours (exemple : week-end)', $d) => 'pass',
);

$fields = array(
    $form->section(__('Créneau', $d), __('Une date d\'un atelier. Laissez places et prix vides pour reprendre ceux de l\'atelier.', $d), array(
        $form->row(
            $form->select(__('Atelier', $d))->setName('atelier_id')->setOptions(Lookup::atelierOptions(__('— Choisir —', $d), true, isset($item['atelier_id']) ? $item['atelier_id'] : 0))->required(),
            $form->select(__('Statut', $d))->setName('status')->setOptions($status_options)
                ->setHelp(__('Brouillon : pas encore en vente. Complet est calculé tout seul.', $d))
        ),
        $form->select(__('Type', $d))->setName('kind')->setOptions($kind_options)
            ->setHelp(__("Un pass vend en une fois une place sur plusieurs dates : un seul billet et un seul QR, valables chaque jour, et des documents signés une fois.", $d)),
        $form->row(
            $form->date(__('Date', $d))->setName('start_date')->required(),
            $form->toggle(__('Toute la journée', $d))->setName('all_day')
                ->setHelp(__('Pas d\'heure affichée aux clients : seule la date est vendue.', $d))
        )->showIf('kind', 'day'),
        $form->row(
            $form->time(__('Début', $d))->setName('start_time')
                ->setHelp(__('Pour une journée entière : facultatif, 7h00 par défaut (sert aux rappels).', $d)),
            $form->time(__('Fin', $d))->setName('end_time')->hideIf('all_day', '1')
        )->showIf('kind', 'day'),
        $form->row(
            $form->number(__('Places en vente', $d), array('min' => 1, 'step' => 1))->setName('capacity')
        )->showIf('kind', 'day'),
        $form->row(
            $form->number(__('Places du pass (facultatif)', $d), array('min' => 0, 'step' => 1))->setName('capacity')
                ->setHelp(__('Vide ou 0 : autant que le jour le plus rempli le permet. Sinon, nombre maximum de pass vendus.', $d))
        )->showIf('kind', 'pass'),
        $form->number(sprintf(__('Prix par place (%s)', $d), \Smbb\Tomo\Support\Prices::label()), array('min' => 0, 'step' => '0.01'))->setName('price')
            ->setHelp(__('Vide = prix de l\'atelier. Pour un pass : prix du pass entier.', $d) . ' ' . \Smbb\Tomo\Support\Prices::help()),
    ))->setIcon('dashicons-clock'),
    $form->section(__('Jours du pass', $d), __("Cochez les dates incluses. Chaque date garde sa jauge, son intervenant et son appel ; elle reste vendable seule.", $d), array(
        $day_options
            ? $form->checkbox(__('Dates incluses', $d))->setName('pass_creneau_ids')->setOptions($day_options)
            : '<p>' . esc_html__("Créez d'abord les dates (une par jour), puis le pass qui les regroupe.", $d) . '</p>',
    ))->setIcon('dashicons-calendar-alt')->showIf('kind', 'pass'),
    $form->section(__('Intervenants', $d), __('Un ou plusieurs intervenants animent ce créneau.', $d), array(
        $staff_table,
        $intervenants
            ? $form->checkbox(__('Intervenants', $d))->setName('intervenant_ids')->setOptions($intervenants)
            : '<p>' . sprintf(esc_html__('Aucun intervenant pour le moment. %s', $d), '<a href="' . esc_url(View::url('tomo_intervenant', array('view' => 'form'))) . '">' . esc_html__('Ajouter un intervenant', $d) . '</a>') . '</p>',
    ))->setIcon('dashicons-businessperson'),
    $form->section(__('Notes internes', $d), '', array(
        $form->textarea(__('Notes', $d), array('rows' => 3))->setName('internal_note'),
    ))->setIcon('dashicons-edit'),
);

if (!$is_new) {
    $fields[] = View::note(
        __('Participants', $d),
        '<p><a class="button button-primary" href="' . esc_url(View::url('tomo_creneau', array('view' => 'details', 'id' => (int) $item['id']))) . '">' . esc_html__('Ouvrir la fiche du créneau', $d) . '</a></p>'
        . '<p class="description">' . esc_html__('Liste des inscrits, pointage, liste d\'attente, export, annulation et duplication sur d\'autres dates.', $d) . '</p>',
        'dashicons-groups'
    );
}

$html = '<div class="form_container">' . $form->save($is_new ? __('Créer le créneau', $d) : __('Enregistrer', $d))->setFields($fields) . '</div>';

// « Ajouter un créneau » depuis la fiche d'un atelier : l'atelier est présélectionné.
$preset_atelier = $is_new && isset($_GET['atelier']) ? absint($_GET['atelier']) : 0;

if ($preset_atelier) {
    $html .= '<script>(function(){var s=document.querySelector("select[name=atelier_id]");if(s&&!s.value){s.value="' . (int) $preset_atelier . '";s.dispatchEvent(new Event("change",{bubbles:true}));}})();</script>';
}

View::page($html, isset($page_header_html) ? $page_header_html : '', isset($notices_html) ? $notices_html : '', __('Créneau', $d));
