<?php
/**
 * Menu Documents : décharges, droit à l'image… demandés à chaque participant.
 *
 * @var \Smbb\WpCodeTool\Admin\Form $form
 */

use Smbb\Tomo\Admin\View;
use Smbb\Tomo\Support\Settings;

defined('ABSPATH') || exit;

$d = 'smbb-tomo';

// Consigne par défaut tant qu'elle n'a jamais été enregistrée.
$form = View::refillForm($form, function (array $item) {
    if (!array_key_exists('documents_onsite_note', $item)) {
        $item['documents_onsite_note'] = Settings::defaults()['documents_onsite_note'];
    }

    return $item;
});

$fields = array(
    View::note(__('Comment ça marche', $d), '<p>' . esc_html__("Documents demandés à chaque participant après sa réservation : il les remplit en ligne (lien dans l'e-mail de confirmation, le rappel et Mon compte), sinon l'intervenant les fait remplir sur place depuis la gestion mobile.", $d) . '</p>'),
    $form->text(__('Consigne affichée quand on fait remplir sur place', $d))->setName('documents_onsite_note')
        ->setHelp(__('En haut de la page des documents ouverte depuis la gestion mobile. Vide : pas de consigne.', $d)),
    $form->repeater(__('Documents', $d))->setName('documents')->setFields(array(
        $form->row(
            $form->text(__('Titre', $d))->setName('title')->setHelp(__('Ex. Décharge de responsabilité, Droit à l\'image.', $d)),
            $form->select(__('Type', $d))->setName('kind')->setOptions(array_flip(\Smbb\Tomo\Service\Documents::kinds())),
            $form->toggle(__('Actif', $d), array('default' => 1))->setName('active')
                ->setHelp(__('Désactivé : le document n\'est plus demandé (ni lien dans les messages, ni bouton). Les réponses déjà reçues sont gardées.', $d))
        ),
        $form->textarea(__('Texte', $d), array('rows' => 8))->setName('text')->setHelp(__('Le participant le lit avant de signer ou de répondre. Le texte exact est conservé avec chaque signature.', $d)),
        $form->toggle(__('Réponse obligatoire', $d))->setName('required')->setHelp(__('Pour une question oui / non : il faut répondre. Une signature est toujours attendue.', $d)),
        $form->text(__('Identifiant', $d))->setName('key')->setHelp(__('Code à définir à la création (vide = tiré du titre). Il est verrouillé dès qu\'une réponse est enregistrée.', $d)),
    )),
);

$html = '<div class="form_container">' . $form->save(__('Enregistrer', $d))->setFields($fields) . '</div>';

// Identifiants de documents déjà utilisés par une réponse : champ grisé, non modifiable.
global $wpdb;
$used_keys = \Smbb\Tomo\Support\Schema::ready() ? (array) $wpdb->get_col('SELECT DISTINCT doc_key FROM ' . \Smbb\Tomo\Repository\Lookup::table('tomo_document') . ' WHERE deleted_flag = 0') : array();
$html .= '<script>(function(){var used=' . wp_json_encode(array_values($used_keys)) . ',title=' . wp_json_encode(__('Des réponses utilisent déjà cet identifiant : il ne peut plus changer.', $d)) . ';'
    . 'function lock(){document.querySelectorAll(\'input[name^="documents["][name$="[key]"]\').forEach(function(i){if(used.indexOf(i.value)>-1){i.readOnly=true;i.classList.add("tomo-locked");i.title=title;}});}'
    . 'lock();document.addEventListener("click",function(){setTimeout(lock,0);});})();</script>';

View::page($html, isset($page_header_html) ? $page_header_html : '', isset($notices_html) ? $notices_html : '', __('Documents', $d));
