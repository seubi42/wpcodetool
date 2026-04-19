<?php

/**
 * Page de réglages "sample_settings".
 *
 * Contrairement à la ressource test, cette page ne représente pas une table SQL avec une
 * liste de lignes. Elle édite un seul objet stocké dans wp_options.
 *
 * @var object $form
 * @var array $item
 * @var string $button
 */

defined('ABSPATH') || exit;

// Libellé du bouton. Le moteur pourra le remplacer selon le contexte.
$button = isset($button) ? $button : 'Save settings';
$resource_label = isset($resource_label) ? $resource_label : __('Sample settings', 'smbb-sample');

// Liste d'options affichées par le champ checkbox.
// Les clés sont les labels visibles, les valeurs sont stockées dans l'option.
$features = array();
$features[__('Catalog sync', 'smbb-sample')] = 'catalog_sync';
$features[__('Public API', 'smbb-sample')] = 'public_api';
$features[__('Debug logs', 'smbb-sample')] = 'debug_logs';

// Même logique que les formulaires TypeRocket : on construit un tableau de blocs/champs.
$fields = array();

// Réglages simples au niveau racine de l'objet option.
$fields[] = $form->fieldset(
    __('General', 'smbb-sample'),
    __('Basic settings stored in one wp_options object.', 'smbb-sample'),
    array(
        $form->toggle(__('Enabled', 'smbb-sample'))
            ->setName('enabled'),

        $form->text(__('Label', 'smbb-sample'))
            ->setName('label')
            ->required()
            ->setHelp(__('Displayed in the sample admin screens.', 'smbb-sample')),

        $form->text(__('Support email', 'smbb-sample'))
            ->setName('support_email'),

        $form->image(__('Logo', 'smbb-sample'))
            ->setName('logo_id')
            ->setHelp(__('Stored as a WordPress attachment ID.', 'smbb-sample')),

        $form->color(__('Brand color', 'smbb-sample'), array('default' => '#2271b1'))
            ->setName('brand_color'),

        $form->row(
            $form->date(__('Publish date', 'smbb-sample'))
                ->setName('publish_date'),

            $form->time(__('Publish time', 'smbb-sample'))
                ->setName('publish_time')
        ),

        $form->editor(__('Intro content', 'smbb-sample'), array('rows' => 6, 'mediaButtons' => true))
            ->setName('intro_content'),
    )
);

// Réglages groupés dans le sous-objet "api".
// Les noms api[endpoint], api[token], api[timeout] produiront une structure imbriquée.
$fields[] = $form->fieldset(
    __('Remote API', 'smbb-sample'),
    __('Optional connection settings grouped in the api object.', 'smbb-sample'),
    array(
        $form->text(__('Endpoint', 'smbb-sample'))
            ->setName('api[endpoint]'),

        $form->password(__('Token', 'smbb-sample'))
            ->setName('api[token]'),

        $form->number(__('Timeout', 'smbb-sample'), array('step' => 1, 'min' => 1, 'max' => 120))
            ->setName('api[timeout]'),
    )
);

// Exemple de liste de fonctionnalités stockée sous forme de tableau.
$fields[] = $form->fieldset(
    __('Features', 'smbb-sample'),
    __('Example list stored as an array in the option object.', 'smbb-sample'),
    array(
        $form->checkbox(__('Enabled features', 'smbb-sample'))
            ->setName('features')
            ->setOptions($features),
    )
);

// Le form builder cible se chargera du rendu HTML, des erreurs et de la sauvegarde.
$html = '<div class="form_container">';
$html .= $form->save(__($button, 'smbb-sample'))->setFields($fields);
$html .= '</div>';

echo '<div class="wrap smbb-codetool">';
if (!empty($page_header_html)) {
    echo $page_header_html;
} else {
    echo '<div class="smbb-codetool-page-header">';
    echo '<div class="smbb-codetool-page-header-main">';
    echo '<h1 class="smbb-codetool-page-title">' . esc_html($resource_label) . '</h1>';
    echo '</div>';
    echo '</div>';
}
if (!empty($notices_html)) {
    echo $notices_html;
}
echo $html;
echo '</div>';
