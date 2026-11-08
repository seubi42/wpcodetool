<?php
/**
 * Menu Documents : décharges, droit à l'image… demandés à chaque participant.
 *
 * @var \Smbb\WpCodeTool\Admin\Form $form
 */

use Smbb\Passflow\Admin\View;
use Smbb\Passflow\Support\Settings;

defined('ABSPATH') || exit;

$d = 'smbb-passflow';

// Consigne par défaut tant qu'elle n'a jamais été enregistrée.
$form = View::refillForm($form, function (array $item) {
    if (!array_key_exists('documents_onsite_note', $item)) {
        $item['documents_onsite_note'] = Settings::defaults()['documents_onsite_note'];
    }

    return $item;
});

// Formulaires de CodeTool › Formulaires à joindre (infos, pièces à fournir au moment de signer).
$form_options = array();
$forms_ready = \Smbb\Passflow\Service\Documents::formsAvailable();

if ($forms_ready) {
    $form_options[__('Aucun', $d)] = '0';
    $titles = \Smbb\Passflow\Service\Documents::formChoices();
    $counts = array_count_values($titles);

    foreach ($titles as $form_id => $form_title) {
        $form_options[$counts[$form_title] > 1 ? sprintf('%s (n° %d)', $form_title, $form_id) : $form_title] = (string) $form_id;
    }
}

$form_fields = $forms_ready ? array(
    $form->row(
        $form->select(__('Formulaire à remplir', $d))->setName('form_id')->setOptions($form_options)
            ->setHelp(sprintf(__('Ses champs (infos, fichiers…) s\'affichent sous le texte, avant la signature ou la réponse. Les réponses sont gardées avec le document et dans Formulaires › Entrées. Formulaires à créer dans %s.', $d), 'CodeTool › Formulaires')),
        $form->toggle(__('Notifications du formulaire', $d))->setName('form_notify')->hideIf('form_id', '0|')
            ->setHelp(__('Envoie aussi les e-mails réglés sur le formulaire (Notifications) à chaque envoi. Désactivé : rien ne part, la réservation garde la trace.', $d))
    ),
) : array();

$fields = array(
    View::note(__('Comment ça marche', $d), '<p>' . esc_html__("Documents demandés à chaque participant après sa réservation : il les remplit en ligne (lien dans l'e-mail de confirmation, le rappel et Mon compte), sinon l'intervenant les fait remplir sur place depuis la gestion mobile.", $d) . '</p>'),
    View::note(__('Consentement RGPD', $d), (function () use ($d) {
        $doc = \Smbb\Passflow\Service\Documents::gdprDoc();

        return $doc
            ? '<p><span class="dashicons dashicons-shield" aria-hidden="true"></span> ' . sprintf(esc_html__('Le document %s porte le rôle « Consentement RGPD » : seuls les participants qui y répondent « Oui » reçoivent les messages commerciaux et les demandes d\'avis.', $d), '<strong>' . esc_html($doc['title']) . '</strong>') . '</p>'
            : '<p><span class="dashicons dashicons-warning" aria-hidden="true"></span> ' . esc_html__('Aucun document ne porte le rôle « Consentement RGPD » : sans lui, Communication en mode « Avec consentement » et le questionnaire de satisfaction ne visent personne. Ajoutez un document (ex. « Recevoir nos nouvelles ») et cochez « Consentement RGPD ».', $d) . '</p>';
    })(), 'dashicons-shield'),
    $form->text(__('Consigne affichée quand on fait remplir sur place', $d))->setName('documents_onsite_note')
        ->setHelp(__('En haut de la page des documents ouverte depuis la gestion mobile. Vide : pas de consigne.', $d)),
    $form->repeater(__('Documents', $d))->setName('documents')->setFields(array(
        $form->row(
            $form->text(__('Titre', $d))->setName('title')->setHelp(__('Ex. Décharge de responsabilité, Droit à l\'image, Recevoir nos nouvelles.', $d)),
            $form->select(__('Type', $d))->setName('kind')->setOptions(array_flip(\Smbb\Passflow\Service\Documents::kinds()))->hideIf('gdpr', '1'),
            $form->toggle(__('Actif', $d), array('default' => 1))->setName('active')
                ->setHelp(__('Désactivé : le document n\'est plus demandé (ni lien dans les messages, ni bouton). Les réponses déjà reçues sont gardées.', $d))
        ),
        $form->toggle(__('Consentement RGPD', $d))->setName('gdpr')
            ->setHelp(__('Ce document recueille l\'accord du participant pour être recontacté (démarchage commercial, demande d\'avis). Il devient une question oui / non, et la réponse de chacun sert de filtre dans Communication et pour le questionnaire de satisfaction. Un seul document peut avoir ce rôle. Texte vide : un texte type est proposé à l\'enregistrement.', $d)),
        $form->textarea(__('Texte', $d), array('rows' => 8))->setName('text')->setHelp(__('Le participant le lit avant de signer ou de répondre. Le texte exact est conservé avec chaque signature.', $d)),
...$form_fields,
                $form->toggle(__('Réponse obligatoire', $d))->setName('required')->setHelp(__('Pour une question oui / non : il faut répondre. Une signature est toujours attendue. Les champs obligatoires du formulaire joint se règlent dans le formulaire.', $d)),
        $form->text(__('Identifiant', $d))->setName('key')->setHelp(__('Code à définir à la création (vide = tiré du titre). Il est verrouillé dès qu\'une réponse est enregistrée.', $d)),
    )),
);

$html = '<div class="form_container">' . $form->save(__('Enregistrer', $d))->setFields($fields) . '</div>';

// Identifiants de documents déjà utilisés par une réponse : champ grisé, non modifiable.
global $wpdb;
$used_keys = \Smbb\Passflow\Support\Schema::ready() ? (array) $wpdb->get_col('SELECT DISTINCT doc_key FROM ' . \Smbb\Passflow\Repository\Lookup::table('passflow_document') . ' WHERE deleted_flag = 0') : array();
$html .= '<script>(function(){var used=' . wp_json_encode(array_values($used_keys)) . ',title=' . wp_json_encode(__('Des réponses utilisent déjà cet identifiant : il ne peut plus changer.', $d)) . ';'
    . 'function lock(){document.querySelectorAll(\'input[name^="documents["][name$="[key]"]\').forEach(function(i){if(used.indexOf(i.value)>-1){i.readOnly=true;i.classList.add("passflow-locked");i.title=title;}});}'
    . 'lock();document.addEventListener("click",function(){setTimeout(lock,0);});})();</script>';

View::page($html, isset($page_header_html) ? $page_header_html : '', isset($notices_html) ? $notices_html : '', __('Documents', $d));
