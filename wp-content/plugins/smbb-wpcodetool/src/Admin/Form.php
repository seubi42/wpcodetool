<?php

namespace Smbb\WpCodeTool\Admin;

// Le form builder utilise des helpers WordPress d'échappement et de nonce.
defined('ABSPATH') || exit;

/**
 * Petit form builder provisoire pour rendre les views d'exemple.
 *
 * Il n'a pas encore l'ambition d'être complet. Son rôle immédiat :
 * - éviter que les views form.php plantent ;
 * - nous permettre de tester le branchement admin ;
 * - poser une API proche de ce qu'on aime dans TypeRocket.
 */
final class Form
{
    // Contexte partagé avec tous les éléments du formulaire.
    private $context;

    public function __construct(array $context = array())
    {
        $this->context = $context;
    }

    public function fieldset($title, $description = '', array $fields = array())
    {
        return (new FormElement('fieldset', $title, array('description' => $description), $this->context))->setFields($fields);
    }

    public function section($title, $description = '', array $fields = array())
    {
        return (new FormElement('section', $title, array('description' => $description), $this->context))->setFields($fields);
    }

    public function tabs()
    {
        return (new FormElement('tabs', '', array(), $this->context))->setFields(func_get_args());
    }

    public function tab($title, $description = '', array $fields = array())
    {
        return (new FormElement('tab', $title, array('description' => $description), $this->context))->setFields($fields);
    }

    public function row()
    {
        return (new FormElement('row', '', array(), $this->context))->setFields(func_get_args());
    }

    public function text($label, array $attributes = array())
    {
        return new FormElement('text', $label, $attributes, $this->context);
    }

    public function password($label, array $attributes = array())
    {
        return new FormElement('password', $label, $attributes, $this->context);
    }

    public function number($label, array $attributes = array())
    {
        return new FormElement('number', $label, $attributes, $this->context);
    }

    public function date($label, array $attributes = array())
    {
        return new FormElement('date', $label, $attributes, $this->context);
    }

    public function time($label, array $attributes = array())
    {
        return new FormElement('time', $label, $attributes, $this->context);
    }

    public function datetime($label, array $attributes = array())
    {
        return new FormElement('datetime', $label, $attributes, $this->context);
    }

    public function textarea($label, array $attributes = array())
    {
        return new FormElement('textarea', $label, $attributes, $this->context);
    }

    public function editor($label, array $attributes = array())
    {
        return new FormElement('editor', $label, $attributes, $this->context);
    }

    public function wysiwyg($label, array $attributes = array())
    {
        return $this->editor($label, $attributes);
    }

    public function select($label, array $attributes = array())
    {
        return new FormElement('select', $label, $attributes, $this->context);
    }

    public function checkbox($label, array $attributes = array())
    {
        return new FormElement('checkbox', $label, $attributes, $this->context);
    }

    public function toggle($label, array $attributes = array())
    {
        return new FormElement('toggle', $label, $attributes, $this->context);
    }

    public function color($label, array $attributes = array())
    {
        return new FormElement('color', $label, $attributes, $this->context);
    }

    public function media($label, array $attributes = array())
    {
        return new FormElement('media', $label, $attributes, $this->context);
    }

    public function image($label, array $attributes = array())
    {
        return new FormElement('image', $label, $attributes, $this->context);
    }

    public function gallery($label, array $attributes = array())
    {
        return new FormElement('gallery', $label, $attributes, $this->context);
    }

    public function search($label, array $attributes = array())
    {
        return new FormElement('search', $label, $attributes, $this->context);
    }

    public function repeater($label, array $attributes = array())
    {
        return new FormElement('repeater', $label, $attributes, $this->context);
    }

    public function save($label)
    {
        return new FormElement('save', $label, array(), $this->context);
    }
}
