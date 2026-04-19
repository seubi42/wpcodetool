<?php

/**
 * Formulaire admin de la ressource "test".
 *
 * Cette view montre l'idee forte du projet : le JSON declare la structure technique,
 * mais la composition visuelle du formulaire reste en PHP, avec une API souple facon
 * TypeRocket. On peut donc composer librement des sections, tabs, repeaters, medias,
 * relations, etc.
 *
 * @var object $form
 * @var array $item
 * @var string $button
 */

defined('ABSPATH') || exit;

// Libelle du bouton de sauvegarde. Le moteur pourra injecter "Save", "Create", "Update"...
$button = isset($button) ? $button : 'Save';
$resource_label = isset($resource_label) ? $resource_label : __('Test', 'smbb-sample');

// Les champs sont accumules dans un tableau, puis passes au bouton save().
$fields = array();

// Options d'exemple pour un select stocke dans json_object.
$statuses = array();
$statuses[__('Draft', 'smbb-sample')] = 'draft';
$statuses[__('Published', 'smbb-sample')] = 'published';
$statuses[__('Archived', 'smbb-sample')] = 'archived';

// Le formulaire est maintenant organise en onglets, avec des sections plus legeres
// a l'interieur. Cela garde une vue PHP tres libre, tout en rendant les gros formulaires
// plus confortables a parcourir.
$fields[] = $form->tabs(
    $form->tab(
        __('General', 'smbb-sample'),
        __('Simple columns, self relation, and conditional fields.', 'smbb-sample'),
        array(
            $form->section(
                __('Identity', 'smbb-sample'),
                __('Main values stored directly in table columns.', 'smbb-sample'),
                array(
                    $form->text(__('Name', 'smbb-sample'))
                        ->setName('name')
                        ->required()
                        ->setHelp(__('Maximum 50 characters.', 'smbb-sample')),

                    $form->row(
                        $form->search(__('Parent', 'smbb-sample'), array(
                            'resource' => 'test',
                            'labelField' => 'name',
                            'valueField' => 'id',
                            'searchFields' => array('name'),
                            'placeholder' => __('Search another test record', 'smbb-sample'),
                            'excludeCurrent' => true,
                        ))
                            ->setName('parent_id')
                            ->setHelp(__('Stores the parent record ID as a bigint.', 'smbb-sample')),

                        $form->number(__('Number', 'smbb-sample'), array('step' => 1))
                            ->setName('number'),

                        $form->number(__('Amount', 'smbb-sample'), array('step' => 0.01))
                            ->setName('amount')
                    ),
                )
            )->setIcon('dashicons-admin-users'),

            $form->section(
                __('Conditional fields', 'smbb-sample'),
                __('Simple showIf / hideIf demo without complex expressions.', 'smbb-sample'),
                array(
                    $form->toggle(__('Advanced mode', 'smbb-sample'))
                        ->setName('advanced_mode')
                        ->setHelp(__('Switch this on to reveal the advanced note field.', 'smbb-sample')),

                    $form->text(__('Basic note', 'smbb-sample'))
                        ->setName('basic_note')
                        ->setHelp(__('Visible while advanced mode is off.', 'smbb-sample'))
                        ->hideIf('advanced_mode', '1'),

                    $form->text(__('Advanced note', 'smbb-sample'))
                        ->setName('advanced_note')
                        ->setHelp(__('Visible only when advanced mode is on.', 'smbb-sample'))
                        ->showIf('advanced_mode', '1')
                )
            )->setIcon('dashicons-visibility')
        )
    ),

    $form->tab(
        __('Media & Dates', 'smbb-sample'),
        __('Native WordPress controls mapped to table columns.', 'smbb-sample'),
        array(
            $form->section(
                __('Media library', 'smbb-sample'),
                __('Single image + gallery using the native WordPress media modal.', 'smbb-sample'),
                array(
                    $form->image(__('Image', 'smbb-sample'))
                        ->setName('image_id')
                        ->setHelp(__('Stored as the WordPress attachment ID.', 'smbb-sample')),

                    $form->gallery(__('Gallery', 'smbb-sample'))
                        ->setName('gallery_ids')
                        ->setHelp(__('Stored as a JSON array of attachment IDs.', 'smbb-sample'))
                )
            )->setIcon('dashicons-format-gallery'),

            $form->section(
                __('Timing and style', 'smbb-sample'),
                __('Date/time controls, color picker, and WordPress editor.', 'smbb-sample'),
                array(
                    $form->row(
                        $form->date(__('Event date', 'smbb-sample'))
                            ->setName('event_date'),

                        $form->time(__('Event time', 'smbb-sample'))
                            ->setName('event_time'),

                        $form->datetime(__('Event datetime', 'smbb-sample'))
                            ->setName('event_datetime')
                    ),

                    $form->color(__('Brand color', 'smbb-sample'), array('default' => '#2271b1'))
                        ->setName('brand_color'),

                    $form->editor(__('Content', 'smbb-sample'), array('rows' => 8))
                        ->setName('content')
                )
            )->setIcon('dashicons-calendar-alt')
        )
    ),

    $form->tab(
        __('Structured data', 'smbb-sample'),
        __('Repeaters, nested items, and JSON-backed structures.', 'smbb-sample'),
        array(
            $form->section(
                __('JSON table', 'smbb-sample'),
                __('Repeater data stored as JSON in the json_table column.', 'smbb-sample'),
                array(
                    $form->repeater('')
                        ->setFields(
                            $form->row(
                                $form->text(__('Label', 'smbb-sample'))
                                    ->setName('label'),

                                $form->number(__('Quantity', 'smbb-sample'), array('step' => 1))
                                    ->setName('quantity'),

                                $form->number(__('Unit cost', 'smbb-sample'), array('step' => 0.01))
                                    ->setName('unit_cost'),

                                $form->number(__('Unit price', 'smbb-sample'), array('step' => 0.01))
                                    ->setName('unit_price')
                            ),
                            $form->row(
                                $form->toggle(__('Poney', 'smbb-sample'))
                                    ->setName('poney')
                            ),

                            // Exemple volontairement imbrique : chaque ligne json_table peut contenir
                            // son propre repeater "options". C'est notre cas test pour verifier que
                            // les noms HTML deviennent bien json_table[0][options][0][label].
                            $form->repeater(__('Nested options', 'smbb-sample'))
                                ->setName('options')
                                ->setFields(
                                    $form->row(
                                        $form->text(__('Option label', 'smbb-sample'))
                                            ->setName('label'),

                                        $form->number(__('Option value', 'smbb-sample'), array('step' => 1))
                                            ->setName('value')
                                    ),
                                    $form->row(
                                        $form->text(__('Internal note', 'smbb-sample'))
                                            ->setName('note')
                                    )
                                )
                        )
                        ->setName('json_table')
                )
            )->setIcon('dashicons-editor-table'),

            $form->section(
                __('JSON object', 'smbb-sample'),
                __('Structured settings stored as JSON in the json_object column.', 'smbb-sample'),
                array(
                    $form->row(
                        $form->select(__('Status', 'smbb-sample'))
                            ->setName('json_object[status]')
                            ->setOptions($statuses),

                        $form->text(__('Reference', 'smbb-sample'))
                            ->setName('json_object[reference]')
                    ),

                    $form->textarea(__('Notes', 'smbb-sample'))
                        ->setName('json_object[notes]')
                )
            )->setIcon('dashicons-database')
        )
    )
);

// Rendu final. L'idee cible est que $form->save() gere le nonce, l'action,
// l'affichage des erreurs et le bouton, tandis que la view garde la structure metier.
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
