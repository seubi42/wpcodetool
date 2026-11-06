<?php
/**
 * Formulaire d'une équipe.
 *
 * @var \Smbb\WpCodeTool\Admin\Form $form
 */

use Smbb\SignConnectProxima\Admin\View;
use Smbb\SignConnectProxima\Repository\Lookup;
use Smbb\SignConnectProxima\Support\Choices;

defined('ABSPATH') || exit;

$d = 'smbb-signaconnect-proxima';
$item = isset($item) && is_array($item) ? $item : array();
$is_new = empty($item['id']);
$money = array('min' => 0, 'step' => '0.01');
$percent = array('min' => 0, 'max' => 100, 'step' => '0.01');

$organization_field = $is_new
    ? $form->select(__('Organisation', $d))->setName('organization_id')->setOptions(Lookup::organizationOptions(__('— Choisir —', $d)))->required()
    : View::note(__('Organisation', $d), '<p><strong>' . esc_html(Lookup::organizationName($item['organization_id'])) . '</strong></p><p class="description">' . esc_html__("Une équipe ne change pas d'organisation.", $d) . '</p>', 'dashicons-building');

$fields = array(
    $form->section(__('Équipe', $d), __('Un site ou un service de l\'organisation. Les réglages restent ceux de l\'organisation, sauf le tampon, la ville et la limite aux modèles.', $d), array(
        $organization_field,
        $form->row(
            $form->text(__('Nom', $d))->setName('name')->required(),
            $form->text(__('Code', $d))->setName('code')->setHelp(__('Optionnel, pour tes exports.', $d)),
            $form->select(__('Statut', $d))->setName('status')->setOptions(Choices::options('teamStatuses'))
        ),
        $form->row(
            $form->image(__("Tampon de l'équipe", $d))->setName('stamp_attachment_id')
                ->setHelp(__("Optionnel. S'il est renseigné, il remplace celui de l'organisation dans les documents envoyés depuis cette équipe.", $d)),
            $form->text(__('Ville', $d))->setName('city')
                ->setHelp(__("Optionnel. Écrite dans les champs « Lieu » des documents de l'équipe ; vide : ville de l'organisation.", $d))
        ),
        $form->toggle(__('Modèles uniquement', $d))->setName('templates_only')
            ->setHelp(__("Les membres de l'équipe ne peuvent envoyer que des documents issus d'un modèle de l'organisation (reconnu automatiquement). Sinon : « Document inconnu, contactez le gestionnaire de votre organisation ».", $d)),
    ))->setIcon('dashicons-groups'),
    $form->section(__('Facturation', $d), __("Montants en € HT. Les remises de l'équipe s'ajoutent à celles de l'organisation.", $d), array(
        $form->row(
            $form->number(__('Abonnement fixe mensuel', $d), $money)->setName('fixed_monthly_fee'),
            $form->number(__("Remise sur l'abonnement des forfaits (%)", $d), $percent)->setName('discount_subscription_pct'),
            $form->number(__('Remise sur les dépassements (%)', $d), $percent)->setName('discount_overage_pct')
        ),
    ))->setIcon('dashicons-money-alt'),
);

$html = '<div class="form_container">' . $form->save($is_new ? __("Créer l'équipe", $d) : __('Enregistrer', $d))->setFields($fields) . '</div>';

View::page($html, isset($page_header_html) ? $page_header_html : '', isset($notices_html) ? $notices_html : '', __('Équipe', $d));
