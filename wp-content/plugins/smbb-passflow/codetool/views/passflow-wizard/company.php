<?php
/**
 * Assistant › Entreprise et TVA : les sections des Réglages (Contact, Identité légale, Mode démo
 * des factures) et la question HT / TTC, qui règle Passflow et WooCommerce ensemble.
 *
 * @var \Smbb\WpCodeTool\Admin\Form $form
 * @var string                      $vat_rate
 */

use Smbb\Passflow\Admin\SettingsSections as Sections;
use Smbb\Passflow\Admin\View;

defined('ABSPATH') || exit;

$d = 'smbb-passflow';
$form = View::refillForm($form, function (array $item) use ($vat_rate) {
    $item['wizard_vat_rate'] = isset($item['wizard_vat_rate']) ? $item['wizard_vat_rate'] : $vat_rate;

    return $item;
});

$fields = array(
    Sections::invoiceIdentity($form),
    $form->section(__('Votre sigle', $d), __('Un code court qui signe vos billets, badges et bons cadeaux.', $d), array(
        Sections::companyCode($form),
    ))->setIcon('dashicons-tag'),
    $form->section(__('Vos prix', $d), __('Les prix saisis dans Passflow (fiches, dates, pass) et dans WooCommerce suivent ce choix. L\'affichage pour vos clients aussi.', $d), array(
        $form->row(
            $form->select(__('Vous pensez vos prix…', $d))->setName('price_entry')->setOptions(array(
                __('TTC, taxes comprises (clients particuliers)', $d) => 'ttc',
                __('HT, hors taxes (clients professionnels)', $d) => 'ht',
            )),
            $form->select(__('Taux de TVA de vos prestations', $d))->setName('wizard_vat_rate')->setOptions(array(
                __('20 % (taux normal)', $d) => '20',
                __('10 % (taux intermédiaire)', $d) => '10',
                __('5,5 % (taux réduit)', $d) => '5.5',
                __('2,1 % (taux particulier)', $d) => '2.1',
            ))->setHelp(__('Le taux est créé dans WooCommerce › Réglages › TVA s\'il n\'existe pas encore.', $d))
        )->showIf('invoice_vat_mode', 'vat'),
        '<p class="description">' . esc_html__('En franchise de TVA, les taxes de WooCommerce sont coupées et les factures portent la mention de l\'article 293 B.', $d) . '</p>',
    ))->setIcon('dashicons-money-alt'),
    Sections::contact($form),
    Sections::invoiceDemo($form),
);
