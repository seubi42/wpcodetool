<?php
/**
 * Formulaire d'un forfait (S, M, L…).
 *
 * @var \Smbb\WpCodeTool\Admin\Form $form
 */

use Smbb\SignConnectProxima\Admin\View;
use Smbb\SignConnectProxima\Support\Choices;
use Smbb\SignConnectProxima\Support\Levels;

defined('ABSPATH') || exit;

$d = 'smbb-signaconnect-proxima';
$item = isset($item) && is_array($item) ? $item : array();
$is_new = empty($item['id']);
$money = array('min' => 0, 'step' => '0.01');
$integer = array('min' => 0, 'step' => 1);

$level_sections = array();

foreach (Levels::labels() as $level => $label) {
    $level_sections[] = $form->section($label, '', array(
        $form->row(
            $form->toggle(__('Illimité', $d))->setName('unlimited_' . $level)
                ->setHelp(__('Aucun plafond ni dépassement : les autres champs sont ignorés.', $d)),
            $form->number(__('Signatures incluses par mois', $d), $integer)->setName('included_' . $level),
            $form->number(__('Prix en dépassement (€ HT / signature)', $d), $money)->setName('overage_price_' . $level),
            $form->number(__('Taille de recharge', $d), array('min' => 1, 'step' => 1))->setName('recharge_size_' . $level)
                ->setHelp(__('Le dépassement est facturé par recharges entières (ex. 10 = par paquet de 10).', $d))
        ),
    ))->setIcon('dashicons-edit');
}

$fields = array_merge(
    array(
        $form->section(__('Forfait', $d), __('Utilisable dans toutes les organisations. Une modification s\'applique immédiatement au mois en cours.', $d), array(
            $form->row(
                $form->text(__('Nom', $d))->setName('name')->required()->setHelp(__('Ex. S, M, L, Gratuit.', $d)),
                $form->number(__('Abonnement mensuel (€ HT)', $d), $money)->setName('monthly_fee'),
                $form->select(__('Statut', $d))->setName('status')->setOptions(Choices::options('planStatuses'))
                    ->setHelp(__('Archivé : plus proposé, reste valable pour les utilisateurs existants.', $d))
            ),
            $form->textarea(__('Description interne', $d), array('rows' => 2))->setName('description'),
        ))->setIcon('dashicons-tag'),
    ),
    $level_sections
);

$html = '<div class="form_container">' . $form->save($is_new ? __('Créer le forfait', $d) : __('Enregistrer', $d))->setFields($fields) . '</div>';

View::page($html, isset($page_header_html) ? $page_header_html : '', isset($notices_html) ? $notices_html : '', __('Forfait', $d));
