<?php

namespace Smbb\WpCodeTool\Admin;

use Smbb\WpCodeTool\Store\TableStore;

// Les éléments de formulaire ne sont rendus que dans l'admin WordPress.
defined('ABSPATH') || exit;

/**
 * Élément de formulaire très simple.
 *
 * Cette classe est volontairement "prototype". Elle donne assez de rendu pour tester les
 * views déclaratives, mais on pourra la remplacer/raffiner sans changer l'idée générale :
 * les views construisent des objets, le moteur rend le HTML.
 */
final class FormElement
{
    private $attributes = array();
    private $context = array();
    private $fields = array();
    private $help = '';
    private $label = '';
    private $name = '';
    private $name_prefix = '';
    private $options = array();
    private $type = '';

    public function __construct($type, $label = '', array $attributes = array(), array $context = array())
    {
        $this->attributes = $attributes;
        $this->context = $context;
        $this->label = (string) $label;
        $this->type = (string) $type;
    }

    public function setName($name)
    {
        $this->name = (string) $name;

        return $this;
    }

    public function setHelp($help)
    {
        $this->help = (string) $help;

        return $this;
    }

    public function setOptions(array $options)
    {
        $this->options = $options;

        return $this;
    }

    public function setFields($fields = array())
    {
        /*
         * TypeRocket permet deux styles tres pratiques :
         *
         * $element->setFields([$field1, $field2]);
         * $element->setFields($row1, $row2);
         *
         * Notre premiere version ne lisait que le premier argument. Du coup,
         * dans un repeater, une deuxieme row passee comme deuxieme argument
         * etait silencieusement ignoree. On accepte maintenant les deux formes.
         */
        $arguments = func_get_args();

        if (count($arguments) === 1 && is_array($fields)) {
            $this->fields = $fields;

            return $this;
        }

        $this->fields = $arguments;

        return $this;
    }

    /**
     * Marque explicitement le champ comme obligatoire.
     *
     * Effets :
     * - etoile rouge dans le label ;
     * - attribut HTML required quand le controle le supporte ;
     * - validation serveur generique par CodeTool, sans hook metier.
     */
    public function required($required = true)
    {
        $this->attributes['required'] = (bool) $required;

        return $this;
    }

    /**
     * Rend l'element visible seulement si un autre champ vaut une valeur donnee.
     *
     * Le but est de rester simple et lisible dans la view :
     * $form->text('...')
     *     ->setName('advanced_note')
     *     ->showIf('advanced_mode', '1');
     */
    public function showIf($field_name, $value = '1')
    {
        // Plusieurs valeurs possibles : showIf('type', array('select', 'radio')) ou 'select|radio'.
        $this->attributes['showIf'] = array(
            'field' => (string) $field_name,
            'value' => is_array($value) ? implode('|', array_map('strval', $value)) : (string) $value,
        );

        return $this;
    }

    /**
     * Rend l'element invisible si un autre champ vaut une valeur donnee.
     */
    public function hideIf($field_name, $value = '1')
    {
        $this->attributes['hideIf'] = array(
            'field' => (string) $field_name,
            'value' => is_array($value) ? implode('|', array_map('strval', $value)) : (string) $value,
        );

        return $this;
    }

    /**
     * Ajoute une icone Dashicons au bloc courant.
     *
     * V1 : cible surtout les sections de formulaire, pour donner un petit repere visuel
     * sans complexifier l'API des views.
     */
    public function setIcon($icon)
    {
        $this->attributes['icon'] = (string) $icon;

        return $this;
    }

    /**
     * Repeater à plusieurs sortes de lignes : le bouton « Ajouter » ouvre un menu de types.
     *
     * $form->repeater('Champs')->setName('fields')->types(array(
     *     'text'   => array('label' => 'Texte', 'icon' => 'dashicons-editor-textcolor', 'group' => 'Base'),
     *     'select' => array('label' => 'Liste', 'icon' => 'dashicons-menu-alt', 'group' => 'Choix'),
     * ));
     *
     * Chaque ligne garde son type dans un champ caché (« type » par défaut) : les sous-champs
     * propres à un type se déclarent avec showIf('type', array('select', 'radio')).
     *
     * @param array<string,string|array{label?:string,icon?:string,group?:string}> $types
     */
    public function types(array $types, $field = 'type')
    {
        $clean = array();

        foreach ($types as $key => $def) {
            $key = sanitize_key((string) $key);

            if ($key === '') {
                continue;
            }

            $def = is_array($def) ? $def : array('label' => (string) $def);
            $clean[$key] = array(
                'label' => isset($def['label']) ? (string) $def['label'] : $key,
                'icon' => isset($def['icon']) ? sanitize_html_class((string) $def['icon']) : '',
                'group' => isset($def['group']) ? (string) $def['group'] : '',
            );
        }

        $this->attributes['types'] = $clean;
        $this->attributes['typeField'] = $field !== '' ? (string) $field : 'type';

        return $this;
    }

    /**
     * Titre de chaque ligne = valeur d'un de ses champs, mise à jour pendant la saisie
     * (au lieu de « Élément 3 »).
     */
    public function titleFrom($field)
    {
        $this->attributes['titleFrom'] = (string) $field;

        return $this;
    }

    /**
     * Largeur de chaque ligne en mode aperçu, lue dans un de ses champs : full | half | third | two-thirds.
     */
    public function widthFrom($field)
    {
        $this->attributes['widthFrom'] = (string) $field;

        return $this;
    }

    /**
     * Identifiant stable par ligne (champ caché), créé à l'ajout et renouvelé à la duplication.
     * Utile quand d'autres données pointent vers une ligne (ex. les entrées d'un formulaire).
     */
    public function uid($field = 'key', $prefix = '')
    {
        $this->attributes['uidField'] = (string) $field;
        $this->attributes['uidPrefix'] = sanitize_key((string) $prefix);

        return $this;
    }

    /**
     * Mode d'affichage du repeater.
     *
     * - 'cards' (défaut) : les lignes dépliables habituelles ;
     * - 'preview' : chaque ligne s'affiche comme un aperçu, posé en grille (widthFrom) ; un clic
     *   ouvre ses réglages dans un panneau latéral, une palette (types) permet d'ajouter par
     *   clic ou glisser-déposer. Un bouton permet de repasser en cartes à tout moment.
     *
     * Options du mode aperçu :
     * - renderer : nom d'une fonction déclarée dans window.SmbbRepeaterPreviews,
     *   appelée avec (valeurs de la ligne, contexte) et qui rend le HTML de l'aperçu ;
     * - palette  : true pour afficher les types en panneau à gauche (défaut true si types()) ;
     * - empty    : texte affiché quand il n'y a encore aucune ligne.
     */
    public function mode($mode, array $options = array())
    {
        $this->attributes['mode'] = $mode === 'preview' ? 'preview' : 'cards';
        $this->attributes['modeOptions'] = $options;

        return $this;
    }

    public function hideContract()
    {
        /*
         * Compatibilite avec le style TypeRocket utilise dans les exemples.
         *
         * Chez TypeRocket, "contract" correspond au mode compact/replie des
         * groupes de repeater. hideContract() masque donc les controles qui
         * permettent de replier/deplier les lignes.
         *
         * Dans notre prototype, on garde pour l'instant la methode en no-op :
         * ca permet de copier des views TypeRocket sans casser le rendu. On
         * decidera plus tard si on veut l'implementer strictement ou renommer
         * l'intention avec une API plus claire.
         */
        return $this;
    }

    public function __toString()
    {
        return $this->render();
    }

    public function render()
    {
        switch ($this->type) {
            case 'fieldset':
                return $this->renderFieldset();
            case 'section':
                return $this->renderSection();
            case 'tabs':
                return $this->renderTabs();
            case 'tab':
                return $this->renderTab();
            case 'row':
                return $this->renderRow();
            case 'textarea':
                return $this->renderTextarea();
            case 'editor':
            case 'wysiwyg':
                return $this->renderEditor();
            case 'select':
                return $this->renderSelect();
            case 'checkbox':
                return $this->renderCheckboxGroup();
            case 'toggle':
                return $this->renderToggle();
            case 'color':
                return $this->renderColor();
            case 'image':
            case 'media':
                return $this->renderMedia();
            case 'gallery':
                return $this->renderGallery();
            case 'search':
                return $this->renderSearch();
            case 'repeater':
                return $this->renderRepeater();
            case 'save':
                return $this->renderSave();
            case 'secret':
                return $this->renderSecret();
            case 'date':
            case 'time':
            case 'datetime':
            case 'password':
            case 'number':
            case 'text':
            default:
                return $this->renderInput($this->type ?: 'text');
        }
    }

    /**
     * Clone l'élément avec un autre contexte de rendu.
     *
     * C'est la petite mécanique qui rend le repeater possible : les champs déclarés dans
     * la view gardent leur nom simple ("label", "quantity"), puis le repeater les rend avec
     * un préfixe HTML ("json_table[0][label]", "json_table[0][quantity]").
     */
    public function withContext(array $context, $name_prefix = '')
    {
        $clone = clone $this;
        $clone->context = $context;
        $clone->name_prefix = (string) $name_prefix;
        $clone->fields = array();

        foreach ($this->fields as $field) {
            $clone->fields[] = $field instanceof self ? $field->withContext($context, $name_prefix) : $field;
        }

        return $clone;
    }

    private function renderFieldset()
    {
        $description = isset($this->attributes['description']) ? $this->attributes['description'] : '';

        return '<div class="postbox smbb-codetool-fieldset"' . $this->visibilityAttributes() . '>'
            . '<div class="postbox-header"><h2>' . esc_html($this->label) . '</h2></div>'
            . '<div class="inside">'
            . ($description !== '' ? '<p class="description">' . esc_html($description) . '</p>' : '')
            . $this->renderChildren()
            . '</div></div>';
    }

    /**
     * Section legere, sans chrome postbox complet.
     *
     * C'est utile dans un onglet quand on veut garder des sous-blocs lisibles,
     * sans empiler des cadres lourds dans des cadres.
     */
    private function renderSection()
    {
        $description = isset($this->attributes['description']) ? $this->attributes['description'] : '';
        $icon = isset($this->attributes['icon']) ? sanitize_html_class((string) $this->attributes['icon']) : '';
        $header = '';

        if ($this->label !== '') {
            $header = '<header class="smbb-codetool-section-header">';

            if ($icon !== '') {
                $header .= '<span class="smbb-codetool-section-icon" aria-hidden="true"><span class="dashicons ' . esc_attr($icon) . '"></span></span>';
            }

            $header .= '<div class="smbb-codetool-section-heading">'
                . '<h3>' . esc_html($this->label) . '</h3>'
                . ($description !== '' ? '<p class="description">' . esc_html($description) . '</p>' : '')
                . '</div></header>';
        }

        return '<section class="smbb-codetool-section"' . $this->visibilityAttributes() . '>'
            . $header
            . '<div class="smbb-codetool-section-body">'
            . $this->renderChildren()
            . '</div></section>';
    }

    /**
     * Groupe d'onglets.
     *
     * On reste sur une structure HTML tres simple :
     * - une barre de boutons type tabs ;
     * - un panneau par onglet ;
     * - du JS minimal pour basculer l'etat actif.
     */
    private function renderTabs()
    {
        if (!$this->fields) {
            return '';
        }

        $base_id = $this->tabBaseId();
        $nav = '<div class="smbb-codetool-tabs-nav" role="tablist" aria-label="' . esc_attr__('Sections du formulaire', 'smbb-wpcodetool') . '">';
        $panels = '<div class="smbb-codetool-tabs-panels">';

        foreach ($this->fields as $index => $field) {
            if (!$field instanceof self) {
                continue;
            }

            $tab_id = $base_id . '-tab-' . $index;
            $panel_id = $base_id . '-panel-' . $index;
            $active = $index === 0;

            $nav .= '<button type="button" class="smbb-codetool-tab-button' . ($active ? ' is-active' : '') . '" role="tab" id="' . esc_attr($tab_id) . '" aria-controls="' . esc_attr($panel_id) . '" aria-selected="' . ($active ? 'true' : 'false') . '" data-smbb-tab-button="' . esc_attr($panel_id) . '">'
                . esc_html($field->label ?: sprintf(__('Onglet %d', 'smbb-wpcodetool'), $index + 1))
                . '</button>';

            $panels .= '<section class="smbb-codetool-tab-panel' . ($active ? ' is-active' : '') . '" role="tabpanel" id="' . esc_attr($panel_id) . '" aria-labelledby="' . esc_attr($tab_id) . '"' . ($active ? '' : ' hidden') . '>'
                . $field->renderTab()
                . '</section>';
        }

        $nav .= '</div>';
        $panels .= '</div>';

        return '<div class="smbb-codetool-tabs"' . $this->visibilityAttributes() . '>' . $nav . $panels . '</div>';
    }

    /**
     * Contenu d'un onglet.
     *
     * Si l'onglet est rendu hors d'un conteneur tabs, on garde simplement ses enfants.
     */
    private function renderTab()
    {
        $description = isset($this->attributes['description']) ? $this->attributes['description'] : '';
        $html = '';

        if ($description !== '') {
            $html .= '<p class="description smbb-codetool-tab-description">' . esc_html($description) . '</p>';
        }

        $html .= $this->renderChildren();

        return $html;
    }

    private function renderRow()
    {
        return '<div class="smbb-codetool-row"' . $this->visibilityAttributes() . '>'
            . $this->renderChildren()
            . '</div>';
    }

    private function renderInput($type)
    {
        $value = $this->value();
        $input_type = $this->htmlInputType($type);

        if ($type === 'datetime') {
            $value = $this->datetimeLocalValue($value);
        }

        return $this->wrapField(
            '<input type="' . esc_attr($input_type) . '" id="' . esc_attr($this->id()) . '" name="' . esc_attr($this->fieldName()) . '" value="' . esc_attr((string) $value) . '"' . $this->controlAttributes() . '>'
        );
    }

    /**
     * Champ secret : input password prérempli (valeur déchiffrée par le store) et bouton œil.
     */
    private function renderSecret()
    {
        $value = $this->value();
        $input = '<input type="password" id="' . esc_attr($this->id()) . '" name="' . esc_attr($this->fieldName()) . '" value="' . esc_attr(is_scalar($value) ? (string) $value : '') . '"'
            . $this->controlAttributes(array('autocomplete' => 'new-password', 'spellcheck' => 'false', 'data-smbb-secret-input' => true), array('autocomplete', 'spellcheck')) . '>';

        return $this->wrapField(
            '<span class="smbb-codetool-secret">' . $input
            . '<button type="button" class="smbb-codetool-secret-toggle" data-smbb-secret-toggle aria-controls="' . esc_attr($this->id()) . '" aria-pressed="false"'
            . ' aria-label="' . esc_attr__('Afficher la valeur', 'smbb-wpcodetool') . '" data-label-show="' . esc_attr__('Afficher la valeur', 'smbb-wpcodetool') . '" data-label-hide="' . esc_attr__('Masquer la valeur', 'smbb-wpcodetool') . '">'
            . '<span class="dashicons dashicons-visibility" aria-hidden="true"></span></button></span>'
        );
    }

    private function renderTextarea()
    {
        $value = $this->value();
        $rows = isset($this->attributes['rows']) ? max(2, (int) $this->attributes['rows']) : 6;

        if (is_array($value) || is_object($value)) {
            $value = wp_json_encode($value, JSON_PRETTY_PRINT);
        }

        return $this->wrapField(
            '<textarea id="' . esc_attr($this->id()) . '" name="' . esc_attr($this->fieldName()) . '" rows="' . esc_attr($rows) . '" class="large-text code"' . $this->controlAttributes(array(), array('rows', 'class')) . '>' . esc_textarea((string) $value) . '</textarea>'
        );
    }

    private function renderEditor()
    {
        $value = $this->value();

        if (is_array($value) || is_object($value)) {
            $value = wp_json_encode($value, JSON_PRETTY_PRINT);
        }

        /*
         * wp_editor() est la brique native WordPress pour TinyMCE/Quicktags/media buttons.
         * En revanche elle n'aime pas etre clonee dynamiquement dans un repeater. Dans ce
         * cas on garde un textarea classique pour rester stable, et on pourra ajouter plus
         * tard une initialisation JS specifique si le besoin devient reel.
         */
        if ($this->name_prefix !== '' || !function_exists('wp_editor')) {
            return $this->wrapField(
                '<textarea id="' . esc_attr($this->id()) . '" name="' . esc_attr($this->fieldName()) . '" rows="8" class="large-text"' . $this->controlAttributes(array(), array('rows', 'class')) . '>' . esc_textarea((string) $value) . '</textarea>'
            );
        }

        $settings = array(
            'textarea_name' => $this->fieldName(),
            'textarea_rows' => isset($this->attributes['rows']) ? max(3, (int) $this->attributes['rows']) : 8,
            'media_buttons' => array_key_exists('mediaButtons', $this->attributes) ? (bool) $this->attributes['mediaButtons'] : true,
            'teeny' => !empty($this->attributes['teeny']),
            'quicktags' => array_key_exists('quicktags', $this->attributes) ? (bool) $this->attributes['quicktags'] : true,
        );

        ob_start();
        wp_editor((string) $value, $this->editorId(), $settings);
        $html = ob_get_clean();

        return $this->wrapField($html);
    }

    private function renderColor()
    {
        $value = (string) $this->value();
        $default = isset($this->attributes['default']) ? (string) $this->attributes['default'] : '';

        $html = '<input type="text" class="smbb-codetool-color-picker" id="' . esc_attr($this->id()) . '" name="' . esc_attr($this->fieldName()) . '" value="' . esc_attr($value) . '"';

        if ($default !== '') {
            $html .= ' data-default-color="' . esc_attr($default) . '"';
        }

        $html .= $this->controlAttributes(array(), array('default', 'class'));
        $html .= '>';

        return $this->wrapField($html);
    }

    private function renderMedia()
    {
        $attachment_id = absint($this->value());
        $is_image = $this->type === 'image';
        $library = $is_image ? 'image' : (isset($this->attributes['library']) ? sanitize_key($this->attributes['library']) : '');
        $select_label = $is_image ? __('Choisir une image', 'smbb-wpcodetool') : __('Choisir un média', 'smbb-wpcodetool');
        $title = $is_image ? __('Sélectionner une image', 'smbb-wpcodetool') : __('Sélectionner un média', 'smbb-wpcodetool');

        /*
         * On stocke uniquement l'ID de l'attachment. C'est le mapping le plus WordPress :
         * la base garde un bigint, et l'affichage peut ensuite passer par wp_get_attachment_*.
         */
        $html = '<div class="smbb-codetool-media-field" data-smbb-media-field data-library="' . esc_attr($library) . '" data-select-title="' . esc_attr($title) . '" data-select-button="' . esc_attr($select_label) . '">';
        $html .= '<input type="hidden" id="' . esc_attr($this->id()) . '" name="' . esc_attr($this->fieldName()) . '" value="' . esc_attr($attachment_id ? $attachment_id : '') . '" data-smbb-media-input>';
        $html .= '<div class="smbb-codetool-media-preview" data-smbb-media-preview>';
        $html .= $this->mediaPreviewHtml($attachment_id, $is_image);
        $html .= '</div>';
        $html .= '<div class="smbb-codetool-media-actions">';
        $html .= '<button type="button" class="button" data-smbb-media-action="select"><span class="dashicons dashicons-format-image" aria-hidden="true"></span> ' . esc_html($select_label) . '</button> ';
        $html .= '<button type="button" class="button button-link-delete" data-smbb-media-action="remove"' . ($attachment_id ? '' : ' hidden') . '>' . esc_html__('Retirer', 'smbb-wpcodetool') . '</button>';
        $html .= '</div></div>';

        return $this->wrapField($html);
    }

    /**
     * Galerie WordPress native.
     *
     * Le stockage vise un tableau d'IDs d'attachments, typiquement encode en JSON
     * dans une colonne texte de table custom.
     */
    private function renderGallery()
    {
        $attachment_ids = $this->normaliseAttachmentIds($this->value());
        $serialized_ids = wp_json_encode($attachment_ids);
        $library = isset($this->attributes['library']) ? sanitize_key($this->attributes['library']) : 'image';
        $select_label = $library === 'image' ? __('Choisir des images', 'smbb-wpcodetool') : __('Choisir des fichiers', 'smbb-wpcodetool');
        $title = $library === 'image' ? __('Sélectionner la galerie', 'smbb-wpcodetool') : __('Sélectionner des fichiers', 'smbb-wpcodetool');

        $html = '<div class="smbb-codetool-gallery-field" data-smbb-gallery-field data-name="' . esc_attr($this->fieldName()) . '" data-library="' . esc_attr($library) . '" data-select-title="' . esc_attr($title) . '" data-select-button="' . esc_attr($select_label) . '">';
        $html .= '<input type="hidden" name="' . esc_attr($this->fieldName()) . '" value="' . esc_attr($serialized_ids) . '" data-smbb-gallery-input>';
        $html .= '<div class="smbb-codetool-gallery-preview" data-smbb-gallery-preview>';
        $html .= $this->galleryPreviewHtml($attachment_ids);
        $html .= '</div>';
        $html .= '<div class="smbb-codetool-media-actions">';
        $html .= '<button type="button" class="button" data-smbb-gallery-action="select"><span class="dashicons dashicons-format-gallery" aria-hidden="true"></span> ' . esc_html($select_label) . '</button> ';
        $html .= '<button type="button" class="button button-link-delete" data-smbb-gallery-action="clear"' . ($attachment_ids ? '' : ' hidden') . '>' . esc_html__('Vider la galerie', 'smbb-wpcodetool') . '</button>';
        $html .= '</div></div>';

        return $this->wrapField($html);
    }

    /**
     * Champ relationnel leger avec recherche Ajax.
     *
     * V1 : on choisit un enregistrement d'une autre ressource CodeTool stockee
     * dans une table custom, puis on ne sauvegarde que sa cle primaire.
     */
    private function renderSearch()
    {
        $resource_name = isset($this->attributes['resource']) ? sanitize_key((string) $this->attributes['resource']) : '';
        $label_field = isset($this->attributes['labelField']) ? (string) $this->attributes['labelField'] : 'name';
        $value_field = isset($this->attributes['valueField']) ? sanitize_key((string) $this->attributes['valueField']) : '';
        $placeholder = isset($this->attributes['placeholder']) ? (string) $this->attributes['placeholder'] : __('Rechercher…', 'smbb-wpcodetool');
        $limit = isset($this->attributes['limit']) ? max(1, min(50, (int) $this->attributes['limit'])) : 12;
        $search_fields = isset($this->attributes['searchFields']) && is_array($this->attributes['searchFields']) ? array_values(array_filter(array_map('sanitize_key', $this->attributes['searchFields']))) : array();
        $current_value = $this->value();

        if (!empty($this->attributes['multiple'])) {
            return $this->renderSearchMultiple($resource_name, $label_field, $value_field, $placeholder, $limit, $search_fields, $current_value);
        }

        $selection = $this->searchSelectionState($resource_name, $label_field, $value_field, $current_value);
        $exclude_current = !empty($this->attributes['excludeCurrent']);
        $current_resource = isset($this->context['resource']) && is_object($this->context['resource']) ? $this->context['resource'] : null;
        $exclude_id = '';

        if ($exclude_current && $current_resource && isset($this->context['requested_id']) && $resource_name === $current_resource->name()) {
            $exclude_id = (string) $this->context['requested_id'];
        }

        $html = '<div class="smbb-codetool-search-field" data-smbb-search-field data-resource="' . esc_attr($resource_name) . '" data-label-field="' . esc_attr($label_field) . '" data-value-field="' . esc_attr($selection['value_field']) . '" data-search-fields="' . esc_attr(wp_json_encode($search_fields)) . '" data-limit="' . esc_attr((string) $limit) . '" data-exclude-id="' . esc_attr($exclude_id) . '" data-product-types="' . esc_attr(wp_json_encode($this->productTypes())) . '">';
        $html .= '<input type="hidden" id="' . esc_attr($this->id() . '--value') . '" name="' . esc_attr($this->fieldName()) . '" value="' . esc_attr((string) $selection['value']) . '" data-smbb-search-value>';
        $html .= '<div class="smbb-codetool-search-control">';
        $html .= '<input type="search" id="' . esc_attr($this->id()) . '" value="' . esc_attr($selection['label']) . '" class="regular-text" placeholder="' . esc_attr($placeholder) . '" autocomplete="off" data-smbb-search-text' . $this->invalidAttribute() . $this->describedByAttribute() . '>';
        $html .= '<button type="button" class="button button-link-delete" data-smbb-search-clear' . ($selection['value'] !== '' ? '' : ' hidden') . '>' . esc_html__('Effacer', 'smbb-wpcodetool') . '</button>';
        $html .= '</div>';
        $html .= '<div class="smbb-codetool-search-selection' . ($selection['value'] !== '' ? ' has-selection' : '') . '" data-smbb-search-selection>';

        if ($selection['value'] !== '') {
            $html .= '<span class="smbb-codetool-search-selection-label">' . esc_html($selection['label']) . '</span>';
            $html .= '<small class="smbb-codetool-search-selection-meta">' . esc_html(!empty($selection['meta']) ? (string) $selection['meta'] : '#' . (string) $selection['value']) . '</small>';
        } else {
            $html .= '<span class="smbb-codetool-search-selection-empty">' . esc_html__('Aucune sélection pour l\'instant.', 'smbb-wpcodetool') . '</span>';
        }

        $html .= '</div>';
        $html .= '<div class="smbb-codetool-search-results" data-smbb-search-results hidden></div>';
        $html .= '</div>';

        return $this->wrapField($html);
    }

    /**
     * Champ relationnel à plusieurs valeurs : recherche Ajax, liste ordonnée (↑ ↓ ✕), une entrée
     * cachée name[] par élément. Valeur enregistrée : tableau d'IDs (colonne JSON conseillée).
     *
     *     $form->search('Produits', array('resource' => 'wc_product', 'multiple' => true))->setName('product_ids')
     *
     * Options : sortable (true par défaut), productTypes (wc_product), max, emptyText.
     */
    private function renderSearchMultiple($resource_name, $label_field, $value_field, $placeholder, $limit, array $search_fields, $current_value)
    {
        $sortable = !isset($this->attributes['sortable']) || !empty($this->attributes['sortable']);
        $max = isset($this->attributes['max']) ? max(0, (int) $this->attributes['max']) : 0;
        $empty = isset($this->attributes['emptyText']) ? (string) $this->attributes['emptyText'] : __('Aucun élément pour l\'instant.', 'smbb-wpcodetool');
        $name = $this->fieldName();
        $list = '';
        $state_field = $value_field;

        foreach ($this->normaliseValueList($current_value) as $value) {
            $state = $this->searchSelectionState($resource_name, $label_field, $value_field, $value);
            $state_field = $state['value_field'];

            if ($state['value'] === '' || $state['label'] === '') {
                $state = array('value' => (string) $value, 'label' => '#' . $value, 'meta' => __('Introuvable', 'smbb-wpcodetool'), 'image' => '');
            }

            $list .= self::searchItemHtml($name, $state, $sortable);
        }

        $html = '<div class="smbb-codetool-search-field is-multiple" data-smbb-search-field data-multiple data-name="' . esc_attr($name . '[]') . '"' . ($sortable ? ' data-sortable' : '') . ($max ? ' data-max="' . $max . '"' : '')
            . ' data-resource="' . esc_attr($resource_name) . '" data-label-field="' . esc_attr($label_field) . '" data-value-field="' . esc_attr($state_field !== '' ? $state_field : 'id') . '" data-search-fields="' . esc_attr(wp_json_encode($search_fields)) . '" data-limit="' . esc_attr((string) $limit) . '" data-exclude-id="" data-product-types="' . esc_attr(wp_json_encode($this->productTypes())) . '">';
        // Liste vide : on envoie quand même le champ, pour pouvoir tout retirer.
        $html .= '<input type="hidden" name="' . esc_attr($name) . '" value="">';
        $html .= '<ol class="smbb-codetool-search-list" data-smbb-search-list>' . $list . '</ol>';
        $html .= '<p class="smbb-codetool-search-list-empty" data-smbb-search-list-empty' . ($list !== '' ? ' hidden' : '') . '>' . esc_html($empty) . '</p>';
        $html .= '<div class="smbb-codetool-search-control">';
        $html .= '<input type="search" id="' . esc_attr($this->id()) . '" class="regular-text" placeholder="' . esc_attr($placeholder) . '" autocomplete="off" data-smbb-search-text' . $this->invalidAttribute() . $this->describedByAttribute() . '>';
        $html .= '</div>';
        $html .= '<div class="smbb-codetool-search-results" data-smbb-search-results hidden></div>';
        $html .= '</div>';

        return $this->wrapField($html);
    }

    /**
     * Ligne d'un champ relationnel multiple (même balisage que celui construit en JavaScript).
     *
     * @param array<string,mixed> $state value, label, meta, image
     */
    public static function searchItemHtml($name, array $state, $sortable = true)
    {
        $image = !empty($state['image']) ? '<img class="smbb-codetool-search-item-image" src="' . esc_url((string) $state['image']) . '" alt="" loading="lazy">' : '<span class="smbb-codetool-search-item-image is-empty dashicons dashicons-format-image" aria-hidden="true"></span>';
        $label = (string) $state['label'];

        return '<li class="smbb-codetool-search-item" data-value="' . esc_attr((string) $state['value']) . '">'
            . '<input type="hidden" name="' . esc_attr($name . '[]') . '" value="' . esc_attr((string) $state['value']) . '">'
            . $image
            . '<span class="smbb-codetool-search-item-text"><strong>' . esc_html($label) . '</strong>'
            . '<small>' . esc_html(!empty($state['meta']) ? (string) $state['meta'] : '#' . (string) $state['value']) . '</small></span>'
            . '<span class="smbb-codetool-search-item-actions">'
            . ($sortable ? '<button type="button" class="button button-small" data-smbb-search-item="up" aria-label="' . esc_attr(sprintf(__('Monter %s', 'smbb-wpcodetool'), $label)) . '">↑</button>'
                . '<button type="button" class="button button-small" data-smbb-search-item="down" aria-label="' . esc_attr(sprintf(__('Descendre %s', 'smbb-wpcodetool'), $label)) . '">↓</button>' : '')
            . '<button type="button" class="button button-small" data-smbb-search-item="remove" aria-label="' . esc_attr(sprintf(__('Retirer %s', 'smbb-wpcodetool'), $label)) . '">✕</button>'
            . '</span></li>';
    }

    /**
     * Valeur d'un champ multiple : tableau, JSON ou liste séparée par des virgules → valeurs uniques, dans l'ordre.
     *
     * @return string[]
     */
    private function normaliseValueList($value)
    {
        if (is_string($value) && $value !== '') {
            $decoded = json_decode($value, true);
            $value = json_last_error() === JSON_ERROR_NONE ? $decoded : explode(',', $value);
        }

        $out = array();

        foreach ((array) $value as $item) {
            $item = is_scalar($item) ? trim((string) $item) : '';

            if ($item !== '' && !in_array($item, $out, true)) {
                $out[] = $item;
            }
        }

        return $out;
    }

    /**
     * @return string[]
     */
    private function productTypes()
    {
        return isset($this->attributes['productTypes']) && is_array($this->attributes['productTypes']) ? array_values(array_filter(array_map('sanitize_key', $this->attributes['productTypes']))) : array();
    }

    private function renderSelect()
    {
        $value = (string) $this->value();
        $html = '<select id="' . esc_attr($this->id()) . '" name="' . esc_attr($this->fieldName()) . '"' . $this->controlAttributes() . '>';

        foreach ($this->options as $label => $option_value) {
            $html .= '<option value="' . esc_attr($option_value) . '"' . selected($value, (string) $option_value, false) . '>' . esc_html($label) . '</option>';
        }

        $html .= '</select>';

        return $this->wrapField($html);
    }

    private function renderCheckboxGroup()
    {
        $value = $this->value();
        $values = is_array($value) ? $value : array();
        $html = '<fieldset>';

        foreach ($this->options as $label => $option_value) {
            $html .= '<label class="smbb-codetool-checkbox-option">'
                . '<input type="checkbox" name="' . esc_attr($this->fieldName()) . '[]" value="' . esc_attr($option_value) . '"' . checked(in_array($option_value, $values, true), true, false) . '> '
                . esc_html($label)
                . '</label>';
        }

        $html .= '</fieldset>';

        return $this->wrapField($html);
    }

    private function renderToggle()
    {
        $value = $this->value();

        // Valeur absente (nouvel élément, ligne de répéteur ajoutée) : on prend l'attribut "default" s'il existe.
        if ($value === '' && array_key_exists('default', $this->attributes)) {
            $value = $this->attributes['default'];
        }

        $checked = (bool) $value;
        $state_label = $checked ? __('Activé', 'smbb-wpcodetool') : __('Désactivé', 'smbb-wpcodetool');
        $required = $this->isRequired() ? ' required' : '';

        /*
         * Le champ cache garantit qu'un toggle decoche envoie bien "0".
         * Sans ca, une checkbox absente du POST pourrait etre comprise comme
         * "ne pas modifier ce champ", ce qui est dangereux dans un formulaire
         * d'admin classique.
         */
        $html = '<input type="hidden" name="' . esc_attr($this->fieldName()) . '" value="0">';
        $html .= '<label class="smbb-codetool-toggle" for="' . esc_attr($this->id()) . '">';
        $html .= '<input class="smbb-codetool-toggle-input" type="checkbox" id="' . esc_attr($this->id()) . '" name="' . esc_attr($this->fieldName()) . '" value="1"' . checked($checked, true, false) . $required . $this->invalidAttribute() . $this->describedByAttribute() . '>';
        $html .= '<span class="smbb-codetool-toggle-track" aria-hidden="true"><span class="smbb-codetool-toggle-thumb"></span></span>';
        $html .= '<span class="smbb-codetool-toggle-state" data-on="' . esc_attr__('Activé', 'smbb-wpcodetool') . '" data-off="' . esc_attr__('Désactivé', 'smbb-wpcodetool') . '">' . esc_html($state_label) . '</span>';
        $html .= '</label>';

        return $this->wrapField($html);
    }

    private function renderRepeater()
    {
        $rows = $this->normaliseRepeaterRows($this->value());
        $limit = isset($this->attributes['limit']) ? max(1, (int) $this->attributes['limit']) : 9999;
        $field_name = $this->fieldName();
        $template_index = '__smbb_index__';
        $is_empty = empty($rows);
        $types = $this->repeaterTypes();
        $preview = $this->repeaterMode() === 'preview';
        $options = isset($this->attributes['modeOptions']) && is_array($this->attributes['modeOptions']) ? $this->attributes['modeOptions'] : array();
        $data = array(
            'data-smbb-repeater' => true,
            'data-name' => $field_name,
            'data-limit' => $limit,
            'data-title-from' => isset($this->attributes['titleFrom']) ? $this->attributes['titleFrom'] : null,
            'data-width-from' => isset($this->attributes['widthFrom']) ? $this->attributes['widthFrom'] : null,
            'data-uid-field' => isset($this->attributes['uidField']) ? $this->attributes['uidField'] : null,
            'data-uid-prefix' => !empty($this->attributes['uidPrefix']) ? $this->attributes['uidPrefix'] : null,
            'data-type-field' => $types ? $this->attributes['typeField'] : null,
            'data-mode' => $preview ? 'preview' : null,
            'data-preview-renderer' => $preview && !empty($options['renderer']) ? (string) $options['renderer'] : null,
            'data-type-labels' => $types ? wp_json_encode(wp_list_pluck($types, 'label')) : null,
        );
        $class = 'smbb-codetool-repeater' . ($types ? ' has-types' : '') . ($preview ? ' is-builder is-view-preview' : '');
        $html = '<div class="' . esc_attr($class) . '"';

        foreach ($data as $key => $value) {
            if ($value === null) {
                continue;
            }

            $html .= $value === true ? ' ' . $key : ' ' . $key . '="' . esc_attr((string) $value) . '"';
        }

        $html .= '>';

        $html .= $this->renderRepeaterToolbar('top', $preview || !$is_empty);

        $html .= '<template data-smbb-repeater-template>';
        $html .= $this->renderRepeaterItem($template_index, array(), true);
        $html .= '</template>';

        $items = '<ol class="smbb-codetool-repeater-items' . ($preview ? ' smbb-codetool-builder-items' : '') . '" data-smbb-repeater-items>';

        foreach ($rows as $index => $row) {
            $items .= $this->renderRepeaterItem($index, is_array($row) ? $row : array(), false);
        }

        $items .= '</ol>';
        $empty_text = $preview && !empty($options['empty'])
            ? (string) $options['empty']
            : ($preview && $types ? __('Ajoutez un premier élément depuis la palette, par un clic ou en le glissant ici.', 'smbb-wpcodetool') : __('Aucun élément pour l\'instant. Ajoutez le premier.', 'smbb-wpcodetool'));
        $items .= '<p class="smbb-codetool-repeater-empty"' . ($is_empty ? '' : ' hidden') . '>' . esc_html($empty_text) . '</p>';

        if ($preview) {
            $palette = !array_key_exists('palette', $options) || !empty($options['palette']);
            $html .= '<div class="smbb-codetool-builder' . ($palette && $types ? ' has-palette' : '') . '">';

            if ($palette && $types) {
                $html .= '<aside class="smbb-codetool-builder-palette" aria-label="' . esc_attr__('Ajouter', 'smbb-wpcodetool') . '">'
                    . '<h3 class="smbb-codetool-builder-panel-title">' . esc_html__('Ajouter', 'smbb-wpcodetool') . '</h3>'
                    . $this->renderRepeaterTypeButtons($types, true)
                    . '<p class="description">' . esc_html__('Clic : ajout sous l\'élément choisi. Ou glissez-le à sa place.', 'smbb-wpcodetool') . '</p>'
                    . '</aside>';
            }

            $html .= '<div class="smbb-codetool-builder-canvas">' . $items . '</div>';
            $html .= '<aside class="smbb-codetool-builder-inspector" data-smbb-repeater-inspector aria-live="polite">'
                . '<div class="smbb-codetool-builder-inspector-head">'
                . '<strong class="smbb-codetool-builder-panel-title" data-smbb-inspector-title>' . esc_html__('Réglages', 'smbb-wpcodetool') . '</strong>'
                . '<span class="smbb-codetool-builder-type" data-smbb-inspector-type></span>'
                . '</div>'
                . '<p class="description smbb-codetool-builder-inspector-empty" data-smbb-inspector-empty>' . esc_html__('Cliquez sur un élément pour le régler.', 'smbb-wpcodetool') . '</p>'
                . '<div class="smbb-codetool-builder-inspector-body" data-smbb-inspector-body></div>'
                . '</aside>';
            $html .= '</div>';
        } else {
            $html .= $items;
            $html .= $this->renderRepeaterToolbar('bottom', true);
        }

        $html .= '</div>';

        return $this->wrapField($html);
    }

    /**
     * Types déclarés par types(), ou tableau vide.
     *
     * @return array<string,array{label:string,icon:string,group:string}>
     */
    private function repeaterTypes()
    {
        return isset($this->attributes['types']) && is_array($this->attributes['types']) ? $this->attributes['types'] : array();
    }

    private function repeaterMode()
    {
        return isset($this->attributes['mode']) && $this->attributes['mode'] === 'preview' ? 'preview' : 'cards';
    }

    /**
     * Boutons d'ajout par type, rangés par groupe (menu « Ajouter » ou palette du mode aperçu).
     */
    private function renderRepeaterTypeButtons(array $types, $draggable)
    {
        $groups = array();

        foreach ($types as $key => $def) {
            $groups[$def['group']][$key] = $def;
        }

        $html = '';

        foreach ($groups as $group => $items) {
            $html .= '<div class="smbb-codetool-type-group">';

            if ($group !== '') {
                $html .= '<p class="smbb-codetool-type-group-title">' . esc_html($group) . '</p>';
            }

            $html .= '<div class="smbb-codetool-type-grid">';

            foreach ($items as $key => $def) {
                $html .= '<button type="button" class="smbb-codetool-type-button" data-smbb-repeater-action="add" data-type="' . esc_attr($key) . '"'
                    . ($draggable ? ' draggable="true" data-smbb-palette-type="' . esc_attr($key) . '"' : '') . '>'
                    . ($def['icon'] !== '' ? '<span class="dashicons ' . esc_attr($def['icon']) . '" aria-hidden="true"></span>' : '')
                    . '<span>' . esc_html($def['label']) . '</span></button>';
            }

            $html .= '</div></div>';
        }

        return $html;
    }

    /**
     * Rend la barre d'outils globale d'un repeater.
     *
     * On la rend en haut et en bas pour eviter de remonter toute la page quand
     * le repeater contient beaucoup de lignes. Les boutons sont identiques :
     * le JS ecoute seulement data-smbb-repeater-action.
     */
    private function renderRepeaterToolbar($position, $visible)
    {
        $class = 'smbb-codetool-repeater-toolbar is-' . sanitize_html_class($position);
        $add_position = $position === 'top' ? 'start' : 'end';
        $attributes = array(
            'data-smbb-repeater-add-position' => $add_position,
        );
        $types = $this->repeaterTypes();
        $preview = $this->repeaterMode() === 'preview';
        $hidden = $visible ? '' : ' hidden';
        $html = '<div class="' . esc_attr($class) . '" role="toolbar" aria-label="' . esc_attr__('Commandes de la liste répétable', 'smbb-wpcodetool') . '"' . $hidden . '>';

        if ($types) {
            // Menu des types : même boutons que la palette, dans une liste déroulante.
            $html .= '<div class="smbb-codetool-repeater-add" data-smbb-repeater-add-position="' . esc_attr($add_position) . '">';
            $html .= '<button type="button" class="button button-primary smbb-codetool-repeater-add-toggle" data-smbb-repeater-action="add-menu" aria-expanded="false">'
                . '<span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span>' . esc_html__('Ajouter', 'smbb-wpcodetool') . '</button>';
            $html .= '<div class="smbb-codetool-repeater-add-menu" data-smbb-repeater-add-menu hidden>' . $this->renderRepeaterTypeButtons($types, false) . '</div>';
            $html .= '</div>';
        } else {
            $html .= $this->renderRepeaterActionButton('add', 'dashicons-plus-alt2', __('Ajouter un élément', 'smbb-wpcodetool'), $attributes, 'is-primary');
        }

        if ($preview && $position === 'top') {
            $html .= '<span class="smbb-codetool-repeater-toolbar-spacer"></span>';
            $html .= '<span class="smbb-codetool-segmented" role="group" aria-label="' . esc_attr__('Affichage', 'smbb-wpcodetool') . '">'
                . '<button type="button" class="is-active" data-smbb-repeater-action="view" data-view="preview" aria-pressed="true">' . esc_html__('Aperçu', 'smbb-wpcodetool') . '</button>'
                . '<button type="button" data-smbb-repeater-action="view" data-view="cards" aria-pressed="false">' . esc_html__('Cartes', 'smbb-wpcodetool') . '</button>'
                . '</span>';
            $html .= '<span class="smbb-codetool-repeater-cards-tools">';
        }

        $html .= $this->renderRepeaterActionButton('collapse-all', 'dashicons-arrow-up-alt2', __('Tout replier', 'smbb-wpcodetool'));
        $html .= $this->renderRepeaterActionButton('expand-all', 'dashicons-arrow-down-alt2', __('Tout déplier', 'smbb-wpcodetool'));

        if ($preview && $position === 'top') {
            $html .= '</span>';
        } else {
            $html .= $this->renderRepeaterActionButton('clear', 'dashicons-trash', __('Effacer', 'smbb-wpcodetool'), array(), 'is-danger');
        }

        $html .= '</div>';

        return $html;
    }

    private function renderSave()
    {
        $action_url = isset($this->context['action_url']) ? $this->context['action_url'] : '';
        $nonce_action = isset($this->context['nonce_action']) ? $this->context['nonce_action'] : 'smbb_codetool_save';
        $html = '<form method="post" enctype="multipart/form-data" action="' . esc_url($action_url) . '">';

        ob_start();
        wp_nonce_field($nonce_action);
        $html .= ob_get_clean();

        $html .= $this->renderChildren();

        ob_start();
        submit_button($this->label);
        $html .= ob_get_clean();

        return $html . '</form>';
    }

    private function wrapField($control)
    {
        $label_class = 'smbb-codetool-field-label' . ($this->isRequired() ? ' is-required' : '');
        $required_mark = $this->isRequired()
            ? ' <span class="smbb-codetool-required-mark" aria-hidden="true">*</span><span class="screen-reader-text"> ' . esc_html__('obligatoire', 'smbb-wpcodetool') . '</span>'
            : '';
        $error = $this->fieldErrorMessage();
        $field_class = 'smbb-codetool-field' . ($error !== '' ? ' has-error' : '');
        $help = $this->help !== '' ? '<p class="description" id="' . esc_attr($this->helpId()) . '">' . esc_html($this->help) . '</p>' : '';
        $error_html = $error !== '' ? '<p class="smbb-codetool-field-error" id="' . esc_attr($this->errorId()) . '">' . esc_html($error) . '</p>' : '';

        return '<div class="' . esc_attr($field_class) . '"' . $this->visibilityAttributes() . '>'
            . ($this->label !== '' ? '<label class="' . esc_attr($label_class) . '" for="' . esc_attr($this->id()) . '">' . esc_html($this->label) . $required_mark . '</label>' : '')
            . $control
            . $this->requiredFieldMarker()
            . $help
            . $error_html
            . '</div>';
    }

    private function renderChildren()
    {
        $html = '';

        foreach ($this->fields as $field) {
            $html .= (string) $field;
        }

        return $html;
    }

    private function value()
    {
        $item = isset($this->context['item']) && is_array($this->context['item']) ? $this->context['item'] : array();

        if ($this->name === '') {
            return '';
        }

        return $this->valueFromArray($item, $this->name);
    }

    private function valueFromArray(array $item, $name)
    {
        // Gère les noms HTML simples et imbriqués : label, api[endpoint], json_object[status].
        $parts = preg_split('/\\[|\\]/', $name, -1, PREG_SPLIT_NO_EMPTY);
        $value = $item;

        foreach ($parts as $part) {
            if (!is_array($value) || !array_key_exists($part, $value)) {
                return '';
            }

            $value = $value[$part];
        }

        return $value;
    }

    private function id()
    {
        return 'smbb-codetool-' . sanitize_html_class(str_replace(array('[', ']'), '-', $this->fieldName() ?: $this->type));
    }

    /**
     * Base d'ID stable pour un groupe d'onglets.
     */
    private function tabBaseId()
    {
        $seed = $this->fieldName();

        if ($seed === '') {
            $seed = $this->type . '-' . substr(md5($this->label . spl_object_hash($this)), 0, 8);
        }

        return $this->id() . '-' . sanitize_html_class(str_replace(array('[', ']'), '-', $seed));
    }

    /**
     * Convertit le type CodeTool en type input HTML.
     */
    private function htmlInputType($type)
    {
        switch ($type) {
            case 'password':
            case 'number':
            case 'date':
            case 'time':
                return $type;

            case 'datetime':
                return 'datetime-local';

            default:
                return 'text';
        }
    }

    /**
     * Adapte une valeur SQL datetime "YYYY-MM-DD HH:MM:SS" pour input datetime-local.
     */
    private function datetimeLocalValue($value)
    {
        if (!is_string($value) || $value === '') {
            return $value;
        }

        $value = str_replace(' ', 'T', $value);

        return strlen($value) > 16 ? substr($value, 0, 16) : $value;
    }

    /**
     * ID compatible wp_editor().
     *
     * WordPress recommande un identifiant tres simple pour l'editeur : lettres, chiffres
     * et underscores. On derive donc l'ID HTML du champ sans garder les tirets/crochets.
     */
    private function editorId()
    {
        $id = strtolower(str_replace('-', '_', $this->id()));
        $id = preg_replace('/[^a-z0-9_]/', '_', $id);

        return trim($id, '_') ?: 'smbb_codetool_editor';
    }

    /**
     * Apercu serveur d'un attachment deja selectionne.
     */
    private function mediaPreviewHtml($attachment_id, $prefer_image)
    {
        if (!$attachment_id) {
            return '<div class="smbb-codetool-media-empty">' . esc_html__('Aucun média sélectionné.', 'smbb-wpcodetool') . '</div>';
        }

        $image = wp_get_attachment_image($attachment_id, 'thumbnail', false, array(
            'class' => 'smbb-codetool-media-image',
        ));

        if ($image) {
            return $image . '<div class="smbb-codetool-media-meta">#' . esc_html((string) $attachment_id) . '</div>';
        }

        $title = get_the_title($attachment_id);
        $url = wp_get_attachment_url($attachment_id);

        if ($title === '') {
            $title = $url ? basename((string) $url) : sprintf(__('Pièce jointe n° %d', 'smbb-wpcodetool'), $attachment_id);
        }

        $icon = $prefer_image ? 'dashicons-format-image' : 'dashicons-media-default';

        return '<div class="smbb-codetool-media-file">'
            . '<span class="dashicons ' . esc_attr($icon) . '" aria-hidden="true"></span>'
            . '<span>' . esc_html($title) . '</span>'
            . '<small>#' . esc_html((string) $attachment_id) . '</small>'
            . '</div>';
    }

    /**
     * Apercu serveur d'une galerie deja remplie.
     */
    private function galleryPreviewHtml(array $attachment_ids)
    {
        if (!$attachment_ids) {
            return '<div class="smbb-codetool-media-empty">' . esc_html__('Aucun média sélectionné.', 'smbb-wpcodetool') . '</div>';
        }

        $html = '<div class="smbb-codetool-gallery-grid">';

        foreach ($attachment_ids as $attachment_id) {
            $thumbnail = wp_get_attachment_image($attachment_id, 'thumbnail', false, array(
                'class' => 'smbb-codetool-gallery-image',
            ));
            $title = get_the_title($attachment_id);

            if ($title === '') {
                $title = sprintf(__('Pièce jointe n° %d', 'smbb-wpcodetool'), $attachment_id);
            }

            if ($thumbnail) {
                $html .= '<figure class="smbb-codetool-gallery-item">' . $thumbnail . '<figcaption>#' . esc_html((string) $attachment_id) . '</figcaption></figure>';
                continue;
            }

            $html .= '<div class="smbb-codetool-gallery-item is-file">'
                . '<span class="dashicons dashicons-media-default" aria-hidden="true"></span>'
                . '<strong>' . esc_html($title) . '</strong>'
                . '<small>#' . esc_html((string) $attachment_id) . '</small>'
                . '</div>';
        }

        $html .= '</div>';

        return $html;
    }

    /**
     * Normalise une liste d'IDs d'attachments.
     */
    private function normaliseAttachmentIds($value)
    {
        if (is_string($value) && $value !== '') {
            $decoded = json_decode($value, true);

            if (json_last_error() === JSON_ERROR_NONE) {
                $value = $decoded;
            }
        }

        if (!is_array($value)) {
            return array();
        }

        $ids = array();

        foreach ($value as $id) {
            $id = absint($id);

            if ($id > 0) {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * Etat serveur d'un champ relationnel deja rempli.
     */
    private function searchSelectionState($resource_name, $label_field, $value_field, $current_value)
    {
        if ($resource_name === \Smbb\WpCodeTool\Support\UserLookup::RESOURCE) {
            $item = is_scalar($current_value) && (string) $current_value !== '' ? \Smbb\WpCodeTool\Support\UserLookup::item((int) $current_value) : null;

            return array(
                'label' => $item ? $item['label'] : '',
                'meta' => $item ? $item['meta'] : '',
                'value' => $item ? $item['value'] : '',
                'value_field' => 'ID',
            );
        }

        if ($resource_name === \Smbb\WpCodeTool\Support\ProductLookup::RESOURCE) {
            $item = is_scalar($current_value) && (string) $current_value !== '' ? \Smbb\WpCodeTool\Support\ProductLookup::item((int) $current_value) : null;

            return array(
                'label' => $item ? $item['label'] : '',
                'meta' => $item ? $item['meta'] : '',
                'image' => $item ? $item['image'] : '',
                'value' => $item ? $item['value'] : '',
                'value_field' => 'ID',
            );
        }

        $resource = $this->searchTargetResource($resource_name);
        $value_field = $value_field !== '' ? $value_field : ($resource ? $resource->primaryKey() : 'id');
        $selected_value = is_scalar($current_value) ? (string) $current_value : '';
        $label = '';

        if ($resource && $selected_value !== '') {
            $store = new TableStore($resource);
            $row = $store->find($selected_value);

            if (is_array($row)) {
                $label_value = $this->valueFromArray($row, $label_field);

                if (is_scalar($label_value) && $label_value !== '') {
                    $label = (string) $label_value;
                } else {
                    $label = (string) $this->valueFromArray($row, $value_field);
                }
            }
        }

        return array(
            'label' => $label,
            'value' => $selected_value,
            'value_field' => $value_field,
        );
    }

    /**
     * Ressource cible d'un champ relationnel.
     */
    private function searchTargetResource($resource_name)
    {
        $resources = isset($this->context['resources']) && is_array($this->context['resources']) ? $this->context['resources'] : array();

        return isset($resources[$resource_name]) && is_object($resources[$resource_name]) ? $resources[$resource_name] : null;
    }

    /**
     * Indique si le champ est requis par la view.
     */
    private function isRequired()
    {
        return !empty($this->attributes['required']);
    }

    /**
     * Marqueur cache des champs obligatoires.
     *
     * Ce petit input permet au moteur admin de refaire une validation serveur
     * generique, meme si la validation HTML5 du navigateur est contournee.
     * Le JS sait aussi reindexer sa valeur dans les repeaters imbriques.
     */
    private function requiredFieldMarker()
    {
        if (!$this->isRequired() || $this->name === '') {
            return '';
        }

        return '<input type="hidden" name="_smbb_required[]" value="' . esc_attr($this->fieldName()) . '" data-smbb-required-field>';
    }

    private function attributeHtml(array $skip = array())
    {
        $html = '';

        foreach ($this->attributes as $key => $value) {
            if ($key === 'description' || in_array($key, $skip, true)) {
                continue;
            }

            if (is_array($value) || is_object($value) || $value === null || $value === false) {
                continue;
            }

            if ($value === true) {
                $html .= ' ' . esc_attr($key);
                continue;
            }

            $html .= ' ' . esc_attr($key) . '="' . esc_attr((string) $value) . '"';
        }

        return $html;
    }

    /**
     * Attributs complets d'un controle : attributs declares + etat d'erreur/accessibilite.
     */
    private function controlAttributes(array $extra = array(), array $skip = array())
    {
        $attributes = $this->attributeHtml($skip);

        foreach ($extra as $key => $value) {
            if ($value === null || $value === false) {
                continue;
            }

            if ($value === true) {
                $attributes .= ' ' . esc_attr($key);
                continue;
            }

            $attributes .= ' ' . esc_attr($key) . '="' . esc_attr((string) $value) . '"';
        }

        $attributes .= $this->invalidAttribute();
        $attributes .= $this->describedByAttribute();

        return $attributes;
    }

    /**
     * Attributs data-* pour la visibilite conditionnelle simple.
     *
     * Le JS lit ces attributs pour montrer/masquer le bloc et desactiver ses champs
     * quand il n'est pas visible, ce qui evite de polluer la sauvegarde.
     */
    private function visibilityAttributes()
    {
        $condition = $this->visibilityCondition();

        if (!$condition) {
            return '';
        }

        return ' data-smbb-visibility="' . esc_attr($condition['mode']) . '"'
            . ' data-smbb-condition-field="' . esc_attr($condition['field']) . '"'
            . ' data-smbb-condition-value="' . esc_attr($condition['value']) . '"';
    }

    /**
     * Regle de visibilite courante, si la view en a declare une.
     */
    private function visibilityCondition()
    {
        foreach (array('showIf' => 'show', 'hideIf' => 'hide') as $key => $mode) {
            if (empty($this->attributes[$key]) || !is_array($this->attributes[$key])) {
                continue;
            }

            $field = isset($this->attributes[$key]['field']) ? $this->resolvedConditionFieldName($this->attributes[$key]['field']) : '';
            $value = isset($this->attributes[$key]['value']) ? (string) $this->attributes[$key]['value'] : '1';

            if ($field === '') {
                continue;
            }

            return array(
                'mode' => $mode,
                'field' => $field,
                'value' => $value,
            );
        }

        return array();
    }

    /**
     * Resolut un nom de champ relatif dans le contexte courant.
     *
     * Exemple dans un repeater :
     * - showIf('enabled') devient pricing[0][enabled]
     * - showIf('settings[mode]') devient pricing[0][settings][mode]
     */
    private function resolvedConditionFieldName($field_name)
    {
        $field_name = (string) $field_name;

        if ($field_name === '') {
            return '';
        }

        if ($this->name_prefix === '') {
            return $field_name;
        }

        $name = $this->name_prefix;

        foreach ($this->nameParts($field_name) as $part) {
            $name .= '[' . $part . ']';
        }

        return $name;
    }

    /**
     * Message d'erreur du champ courant, si le moteur en a injecte un.
     */
    private function fieldErrorMessage()
    {
        $path = $this->fieldPath();
        $errors = isset($this->context['field_errors']) && is_array($this->context['field_errors']) ? $this->context['field_errors'] : array();

        if ($path === '' || empty($errors[$path]['message'])) {
            return '';
        }

        return (string) $errors[$path]['message'];
    }

    /**
     * Chemin canonique du champ, en notation pointee.
     */
    private function fieldPath()
    {
        $name = $this->fieldName();

        if ($name === '') {
            return '';
        }

        $parts = preg_split('/\\[|\\]/', $name, -1, PREG_SPLIT_NO_EMPTY);

        return implode('.', array_map('strval', $parts));
    }

    /**
     * ID du paragraphe d'aide.
     */
    private function helpId()
    {
        return $this->id() . '--help';
    }

    /**
     * ID du paragraphe d'erreur inline.
     */
    private function errorId()
    {
        return $this->id() . '--error';
    }

    /**
     * aria-invalid pour les champs en erreur.
     */
    private function invalidAttribute()
    {
        return $this->fieldErrorMessage() !== '' ? ' aria-invalid="true"' : '';
    }

    /**
     * aria-describedby construit a partir de l'aide et de l'erreur du champ.
     */
    private function describedByAttribute()
    {
        $ids = array();

        if ($this->help !== '') {
            $ids[] = $this->helpId();
        }

        if ($this->fieldErrorMessage() !== '') {
            $ids[] = $this->errorId();
        }

        if (!$ids) {
            return '';
        }

        return ' aria-describedby="' . esc_attr(implode(' ', $ids)) . '"';
    }

    /**
     * Nom HTML final du champ.
     *
     * Hors repeater : "amount".
     * Dans un repeater json_table index 2 : "json_table[2][amount]".
     */
    private function fieldName()
    {
        if ($this->name === '') {
            return '';
        }

        if ($this->name_prefix === '') {
            return $this->name;
        }

        $name = $this->name_prefix;

        foreach ($this->nameParts($this->name) as $part) {
            $name .= '[' . $part . ']';
        }

        return $name;
    }

    /**
     * Découpe un nom HTML en morceaux.
     *
     * Exemple : api[endpoint] -> array('api', 'endpoint').
     */
    private function nameParts($name)
    {
        return preg_split('/\\[|\\]/', (string) $name, -1, PREG_SPLIT_NO_EMPTY);
    }

    /**
     * Nettoie la valeur d'un repeater avant rendu.
     *
     * La donnée peut arriver sous forme de tableau PHP ou de chaîne JSON depuis la base.
     */
    private function normaliseRepeaterRows($value)
    {
        if (is_string($value) && $value !== '') {
            $decoded = json_decode($value, true);

            if (json_last_error() === JSON_ERROR_NONE) {
                $value = $decoded;
            }
        }

        if (!is_array($value)) {
            return array();
        }

        if (!$this->isList($value)) {
            return array($value);
        }

        return array_values($value);
    }

    /**
     * Rend une ligne de repeater.
     */
    private function renderRepeaterItem($index, array $row, $is_template)
    {
        $field_name = $this->fieldName();
        $prefix = $field_name . '[' . $index . ']';
        $context = $this->context;
        $context['item'] = $row;
        $types = $this->repeaterTypes();
        $type_field = $types ? (string) $this->attributes['typeField'] : '';
        $type = $type_field !== '' && isset($row[$type_field]) ? sanitize_key((string) $row[$type_field]) : '';
        $title_from = isset($this->attributes['titleFrom']) ? (string) $this->attributes['titleFrom'] : '';
        $title = $title_from !== '' && isset($row[$title_from]) && is_scalar($row[$title_from]) ? trim(wp_strip_all_tags((string) $row[$title_from])) : '';
        $width_from = isset($this->attributes['widthFrom']) ? (string) $this->attributes['widthFrom'] : '';
        $width = $width_from !== '' && isset($row[$width_from]) ? sanitize_key((string) $row[$width_from]) : '';
        $uid_field = isset($this->attributes['uidField']) ? (string) $this->attributes['uidField'] : '';
        $attrs = ' data-smbb-repeater-item' . ($is_template ? ' data-smbb-repeater-template-item="1"' : '');

        if ($type !== '') {
            $attrs .= ' data-type="' . esc_attr($type) . '"';
        }

        if ($width !== '') {
            $attrs .= ' data-width="' . esc_attr($width) . '"';
        }

        $html = '<li class="smbb-codetool-repeater-item"' . $attrs . '>';

        $html .= '<div class="smbb-codetool-repeater-item-header">';
        $html .= '<span class="smbb-codetool-repeater-handle dashicons dashicons-menu" aria-hidden="true" title="' . esc_attr__('Glisser pour déplacer', 'smbb-wpcodetool') . '"></span>';
        $html .= '<strong class="smbb-codetool-repeater-title">'
            . '<span data-smbb-repeater-label' . ($title === '' ? ' hidden' : '') . '>' . esc_html($title) . '</span>'
            . '<span data-smbb-repeater-fallback' . ($title === '' ? '' : ' hidden') . '>' . esc_html__('Élément', 'smbb-wpcodetool') . ' <span data-smbb-repeater-number>' . esc_html(is_numeric($index) ? ((int) $index + 1) : '') . '</span></span>'
            . '</strong>';

        if ($types) {
            $html .= '<span class="smbb-codetool-repeater-type" data-smbb-repeater-type-label>' . esc_html($type !== '' && isset($types[$type]) ? $types[$type]['label'] : '') . '</span>';
        }

        $html .= '<div class="smbb-codetool-repeater-actions">';
        $html .= $this->renderRepeaterActionButton('toggle', 'dashicons-arrow-up-alt2', __('Replier', 'smbb-wpcodetool'), array('aria-expanded' => 'true'));
        $html .= $this->renderRepeaterActionButton('move-up', 'dashicons-arrow-up', __('Monter', 'smbb-wpcodetool'));
        $html .= $this->renderRepeaterActionButton('move-down', 'dashicons-arrow-down', __('Descendre', 'smbb-wpcodetool'));
        $html .= $this->renderRepeaterActionButton('duplicate', 'dashicons-admin-page', __('Dupliquer', 'smbb-wpcodetool'));
        $html .= $this->renderRepeaterActionButton('remove', 'dashicons-trash', __('Retirer', 'smbb-wpcodetool'), array(), 'is-danger');
        $html .= '</div></div>';

        if ($this->repeaterMode() === 'preview') {
            // Rempli par le JS (window.SmbbRepeaterPreviews) depuis les valeurs de la ligne.
            $html .= '<div class="smbb-codetool-repeater-preview" data-smbb-repeater-preview></div>';
        }

        $html .= '<div class="smbb-codetool-repeater-item-body" data-smbb-repeater-body>';

        // Champs techniques de la ligne : type (types()) et identifiant stable (uid()).
        if ($type_field !== '') {
            $html .= '<input type="hidden" name="' . esc_attr($prefix . '[' . $type_field . ']') . '" value="' . esc_attr($type) . '" data-smbb-repeater-type>';
        }

        if ($uid_field !== '') {
            $uid = isset($row[$uid_field]) && is_scalar($row[$uid_field]) ? sanitize_key((string) $row[$uid_field]) : '';
            $html .= '<input type="hidden" name="' . esc_attr($prefix . '[' . $uid_field . ']') . '" value="' . esc_attr($uid) . '" data-smbb-repeater-uid>';
        }

        $html .= $this->renderChildrenForContext($context, $prefix);
        $html .= '</div></li>';

        return $html;
    }

    /**
     * Rend un bouton d'action de repeater sous forme d'icone.
     *
     * Le texte reste present pour les lecteurs d'ecran, mais l'interface visuelle
     * reste plus compacte et plus moderne que des liens "Collapse / Up / Down".
     */
    private function renderRepeaterActionButton($action, $icon, $label, array $attributes = array(), $extra_class = '')
    {
        $classes = trim('smbb-codetool-icon-button ' . $extra_class);
        $html = '<button type="button" class="' . esc_attr($classes) . '" data-smbb-repeater-action="' . esc_attr($action) . '" title="' . esc_attr($label) . '" aria-label="' . esc_attr($label) . '"';

        foreach ($attributes as $name => $value) {
            $html .= ' ' . esc_attr($name) . '="' . esc_attr((string) $value) . '"';
        }

        $html .= '>';
        $html .= '<span class="dashicons ' . esc_attr($icon) . '" aria-hidden="true"></span>';
        $html .= '<span class="screen-reader-text">' . esc_html($label) . '</span>';
        $html .= '</button>';

        return $html;
    }

    /**
     * Rend les champs enfants avec un contexte et un préfixe de nom donnés.
     */
    private function renderChildrenForContext(array $context, $name_prefix)
    {
        $html = '';

        foreach ($this->fields as $field) {
            $html .= $field instanceof self ? $field->withContext($context, $name_prefix)->render() : (string) $field;
        }

        return $html;
    }

    /**
     * Équivalent compatible PHP 7.4 de array_is_list().
     */
    private function isList(array $value)
    {
        $expected = 0;

        foreach ($value as $key => $_) {
            if ($key !== $expected) {
                return false;
            }

            $expected++;
        }

        return true;
    }
}
