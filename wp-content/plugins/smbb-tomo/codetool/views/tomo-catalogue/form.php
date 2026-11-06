<?php
/**
 * Formulaire d'un catalogue sur place : où il est rangé, pour quels ateliers et intervenants,
 * et sa liste ordonnée de produits WooCommerce.
 *
 * @var \Smbb\WpCodeTool\Admin\Form $form
 */

use Smbb\Tomo\Admin\View;
use Smbb\Tomo\Repository\Catalogues;
use Smbb\Tomo\Repository\Lookup;

defined('ABSPATH') || exit;

$d = 'smbb-tomo';
$item = isset($item) && is_array($item) ? $item : array();
$is_new = empty($item['id']);
$shop = \Smbb\Tomo\WooCommerce\Module::active();
$ids = function ($key) use ($item) {
    return array_map('intval', Lookup::ids(isset($item[$key]) ? $item[$key] : array()));
};

// Ateliers et intervenants : cases à cocher, aucune = tous.
$checks = function ($name, array $choices, array $checked) {
    $html = '<div class="tomo-extra-products tomo-cat-checks">';

    foreach ($choices as $id => $label) {
        $html .= '<label><input type="checkbox" name="' . esc_attr($name) . '[]" value="' . (int) $id . '"' . (in_array((int) $id, $checked, true) ? ' checked' : '') . '> ' . esc_html($label) . '</label>';
    }

    return $html . '</div>';
};
$ateliers = array();

foreach (Lookup::ateliers() as $id => $row) {
    $ateliers[(int) $id] = (string) $row['name'];
}

$intervenants = array();

foreach (Lookup::intervenants() as $id => $row) {
    $intervenants[(int) $id] = Lookup::personName($row);
}

$who_html = '<input type="hidden" name="tomo_catalogue_form" value="1">'
    . '<div class="tomo-cat-who"><div><h4>' . esc_html__('Ateliers', $d) . '</h4><p class="tomo-muted">' . esc_html__('Aucun coché = tous les ateliers.', $d) . '</p>' . $checks('atelier_ids', $ateliers, $ids('atelier_ids')) . '</div>'
    . '<div><h4>' . esc_html__('Intervenants', $d) . '</h4><p class="tomo-muted">' . esc_html__('Aucun coché = tous les intervenants. Les gestionnaires voient tout.', $d) . '</p>' . $checks('intervenant_ids', $intervenants, $ids('intervenant_ids')) . '</div></div>';

// Produits : recherche Ajax CodeTool (nom, référence, ID), liste ordonnée ↑ ↓ ✕.
$products_html = $shop
    ? $form->search(__('Produits', $d), array(
        'resource' => 'wc_product',
        'multiple' => true,
        'productTypes' => array('simple'),
        'placeholder' => __('Chercher un produit par nom ou référence…', $d),
        'emptyText' => __('Aucun produit pour le moment.', $d),
        'limit' => 20,
    ))->setName('product_ids')
    : '<p class="tomo-muted">' . esc_html__('WooCommerce doit être actif pour choisir des produits.', $d) . '</p>';

$fields = array(
    $form->section(__('Catalogue', $d), __("Ce que l'équipe peut vendre sur place, depuis la feuille d'appel (bouton « Vente »).", $d), array(
        $form->row(
            $form->text(__('Nom', $d))->setName('name')->required(),
            $form->select(__('Rangé dans', $d))->setName('parent_id')->setOptions(Catalogues::parentOptions($is_new ? 0 : (int) $item['id']))
                ->setHelp(__('Catalogue principal, ou sous-catalogue d\'un autre (comme un sous-dossier).', $d))
        ),
        $form->row(
            $form->number(__('Ordre', $d), array('step' => 1))->setName('position')->setHelp(__('Les plus petits passent en premier.', $d)),
            $form->toggle(__('Visible à la caisse', $d), array('default' => 1))->setName('active')
        ),
    ))->setIcon('dashicons-store'),
    $form->section(__('Pour qui ?', $d), __('Un sous-catalogue n\'apparaît que si son catalogue parent apparaît aussi.', $d), array($who_html))->setIcon('dashicons-groups'),
    $form->section(__('Produits', $d), __("Dans l'ordre d'affichage à la caisse. Produits simples publiés de WooCommerce : photo, référence et description viennent de la fiche produit.", $d), array($products_html))->setIcon('dashicons-products'),
    $form->section(__('Notes internes', $d), '', array(
        $form->textarea(__('Notes', $d), array('rows' => 2))->setName('notes'),
    ))->setIcon('dashicons-edit'),
);

$html = '<div class="form_container">' . $form->save($is_new ? __('Créer le catalogue', $d) : __('Enregistrer', $d))->setFields($fields) . '</div>';

View::page($html, isset($page_header_html) ? $page_header_html : '', isset($notices_html) ? $notices_html : '', __('Catalogue sur place', $d));
