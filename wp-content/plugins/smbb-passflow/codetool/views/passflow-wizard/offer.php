<?php
/**
 * Assistant › Votre première offre : la fiche (préremplie par le profil) et son produit WooCommerce,
 * puis les sections des Réglages qui disent comment vous vendez.
 *
 * @var \Smbb\WpCodeTool\Admin\Form $form
 * @var array<string,mixed>         $offer
 * @var bool                        $offer_exists
 */

use Smbb\Passflow\Admin\SettingsSections as Sections;
use Smbb\Passflow\Admin\View;
use Smbb\Passflow\Support\Prices;

defined('ABSPATH') || exit;

$d = 'smbb-passflow';
$form = View::refillForm($form, function (array $item) use ($offer) {
    $map = array(
        'offer_name' => 'name',
        'offer_location' => 'location',
        'offer_duration' => 'duration_minutes',
        'offer_price' => 'default_price',
        'offer_capacity' => 'default_capacity',
        'offer_color' => 'color',
        'offer_description' => 'description',
        'offer_image_id' => 'image_id',
    );

    foreach ($map as $field => $key) {
        if (!isset($item[$field])) {
            $item[$field] = isset($offer[$key]) ? $offer[$key] : '';
        }
    }

    return $item;
});

$example = trim((string) \Smbb\Passflow\Support\Settings::get('vocabulary_example_name'));
$fields = array(
    $form->section(__('La fiche', $d), $offer_exists
        ? __('Déjà créée : vos changements la mettent à jour, avec son produit WooCommerce.', $d)
        : __('Elle est créée avec son produit WooCommerce, publié : on peut la réserver tout de suite une fois une date ajoutée.', $d), array(
        $form->text(__('Nom', $d))->setName('offer_name')->required()->setHelp($example !== '' ? sprintf(__('Par exemple : %s.', $d), $example) : ''),
        $form->row(
            $form->text(__('Lieu', $d))->setName('offer_location'),
            $form->number(__('Durée (minutes)', $d), array('min' => 0, 'step' => 15))->setName('offer_duration')
        ),
        $form->row(
            $form->text(__('Prix', $d) . ' (' . Prices::label() . ')')->setName('offer_price')->setHelp(Prices::help()),
            $form->number(__('Places par date', $d), array('min' => 1, 'step' => 1))->setName('offer_capacity'),
            $form->color(__('Couleur (calendrier, billets)', $d))->setName('offer_color')
        ),
        $form->textarea(__('Description', $d), array('rows' => 3))->setName('offer_description'),
        $form->image(__('Photo', $d))->setName('offer_image_id'),
    ))->setIcon('dashicons-calendar-alt'),
    Sections::waitlist($form),
    Sections::vouchers($form),
    Sections::invoiceEmission($form),
);
