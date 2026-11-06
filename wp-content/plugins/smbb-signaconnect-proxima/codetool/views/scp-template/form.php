<?php
/**
 * Modèle de détection, propre à une organisation.
 *
 * @var \Smbb\WpCodeTool\Admin\Form $form
 */

use Smbb\SignConnectProxima\Admin\TemplateEditor;
use Smbb\SignConnectProxima\Admin\View;
use Smbb\SignConnectProxima\Repository\Lookup;
use Smbb\SignConnectProxima\Repository\Templates;
use Smbb\SignConnectProxima\Support\Levels;
use Smbb\SignConnectProxima\Support\Placeholders;

defined('ABSPATH') || exit;

$d = 'smbb-signaconnect-proxima';
$item = isset($item) && is_array($item) ? $item : array();
$is_new = empty($item['id']);

$fields = array(
    $form->section(__('Modèle', $d), __('Sert à reconnaître un document à l\'envoi et à placer ses champs automatiquement.', $d), array(
        $form->row(
            $form->select(__('Organisation', $d))->setName('organization_id')->setOptions(Lookup::organizationOptions(__('— Choisir —', $d)))->required(),
            $form->text(__('Nom', $d))->setName('name')->required(),
            $form->toggle(__('Actif', $d))->setName('is_active')
        ),
        $form->textarea(__('Description', $d), array('rows' => 2))->setName('description'),
        $form->media(__("PDF d'exemple", $d), array('library' => 'application/pdf'))->setName('sample_pdf_attachment_id'),
    ))->setIcon('dashicons-layout'),
    $form->section(__('Niveau de signature', $d), '', array(
        $form->row(
            $form->select(__('Niveau proposé', $d))->setName('suggested_level')->setOptions(Levels::options()),
            $form->toggle(__('Niveau imposé', $d))->setName('level_enforced')
                ->setHelp(__("L'utilisateur ne peut pas choisir un niveau plus faible.", $d))
        ),
    ))->setIcon('dashicons-yes-alt'),
    $form->section(__("À l'envoi", $d), __("Ce que l'expéditeur trouve tout prêt quand ce modèle est reconnu ou choisi.", $d), array(
        $form->textarea(__('Message par défaut au signataire', $d), array('rows' => 3))->setName('default_message')
            ->setHelp(__("Vide : message par défaut de l'organisation.", $d)),
        $form->repeater(__('Champs à saisir à l\'envoi', $d), array('limit' => Templates::MAX_CUSTOM_FIELDS))->setName('custom_fields')
            ->setHelp(__('Ex. N° de commande, N° de bon. Saisis sous le nom, l\'email et le mobile du signataire ; visibles et cherchables dans Transactions, pas écrits sur le PDF.', $d))
            ->setFields(array(
                $form->row(
                    $form->text(__('Libellé', $d))->setName('label'),
                    $form->toggle(__('Obligatoire', $d))->setName('required')
                ),
            )),
        $form->text(__('Nom du PDF (stratégie de nommage)', $d))->setName('naming_pattern')
            ->setHelp(__('Ex. Devis - [nom] - [N° de commande]. Vide : nom du fichier déposé.', $d)),
        $form->toggle(__("Passer l'aperçu quand ce modèle est reconnu", $d))->setName('skip_preview')
            ->setHelp(__("L'expéditeur arrive directement sur « Qui doit signer ? », les zones déjà placées.", $d)),
        $form->toggle(__("Envoyer le document signé à l'expéditeur par email", $d))->setName('signed_mail_enabled')
            ->setHelp(__("Avec le sujet et le message ci-dessous, même si l'expéditeur a coupé la notification « signé ». Le PDF signé est joint.", $d)),
        $form->row(
            $form->text(__('Sujet', $d))->setName('signed_mail_subject'),
            $form->text(__('En copie (optionnel)', $d))->setName('signed_mail_cc')->setHelp(__('Adresses séparées par des virgules.', $d))
        ),
        $form->textarea(__('Message', $d), array('rows' => 4))->setName('signed_mail_body')
            ->setHelp(sprintf(__('Variables : %s', $d), implode(' ', Placeholders::help()))),
    ))->setIcon('dashicons-email-alt'),
    $form->section(__('Zones', $d), __("Placez les champs à signer et les zones qui permettent de reconnaître ce document. Pensez à enregistrer.", $d), array(
        TemplateEditor::html($item),
        $form->textarea(__('Zones de reconnaissance (JSON)', $d), array('rows' => 3))->setName('detection_zones')
            ->setHelp(__("Rempli par l'éditeur. Format : [{\"page\":1,\"x\":0.1,\"y\":0.05,\"width\":0.4,\"height\":0.05,\"text\":\"CONTRAT DE VENTE\"}]", $d)),
        $form->textarea(__('Champs à placer (JSON)', $d), array('rows' => 3))->setName('field_zones')
            ->setHelp(__("Rempli par l'éditeur. Page négative = comptée depuis la fin (-1 = dernière page, -3 = antépénultième).", $d)),
    ))->setIcon('dashicons-editor-table'),
);

$html = '<div class="form_container">' . $form->save($is_new ? __('Créer le modèle', $d) : __('Enregistrer', $d))->setFields($fields) . '</div>';

View::page($html, isset($page_header_html) ? $page_header_html : '', isset($notices_html) ? $notices_html : '', __('Modèle de détection', $d));
