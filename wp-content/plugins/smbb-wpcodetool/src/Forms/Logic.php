<?php

namespace Smbb\WpCodeTool\Forms;

defined('ABSPATH') || exit;

/**
 * Logique conditionnelle : un champ s'affiche (ou se masque) selon la réponse à un autre champ.
 *
 * Réglage d'un champ : logic = '' (toujours affiché) | show | hide, logic_field (clé du champ observé),
 * logic_op (is, is_not, contains, empty, not_empty), logic_value.
 *
 * La même règle tourne dans le navigateur (assets/forms.js) pour l'affichage en direct, et ici à l'envoi :
 * un champ masqué n'est ni contrôlé (même obligatoire) ni gardé dans l'entrée ou les e-mails.
 * Un champ qui dépend d'un champ masqué voit une réponse vide.
 */
final class Logic
{
    public static function modes()
    {
        return array(
            '' => __('Toujours affiché', 'smbb-wpcodetool'),
            'show' => __('Afficher seulement si…', 'smbb-wpcodetool'),
            'hide' => __('Masquer si…', 'smbb-wpcodetool'),
        );
    }

    public static function operators()
    {
        return array(
            'is' => __('est', 'smbb-wpcodetool'),
            'is_not' => __('n\'est pas', 'smbb-wpcodetool'),
            'contains' => __('contient', 'smbb-wpcodetool'),
            'not_empty' => __('est rempli / coché', 'smbb-wpcodetool'),
            'empty' => __('est vide / pas coché', 'smbb-wpcodetool'),
        );
    }

    public static function has(array $field)
    {
        return in_array($field['logic'] ?? '', array('show', 'hide'), true) && (string) ($field['logic_field'] ?? '') !== '';
    }

    /**
     * La condition est-elle remplie pour cette réponse ?
     *
     * @param mixed $value réponse du champ observé (texte ou liste pour les cases à cocher)
     */
    public static function matches($op, $value, $expected)
    {
        $values = is_array($value) ? array_map('strval', $value) : (((string) $value) === '' ? array() : array((string) $value));
        $norm = static function ($text) {
            return mb_strtolower(trim((string) $text));
        };
        $expected = $norm($expected);
        $values = array_map($norm, $values);

        switch ($op) {
            case 'empty':
                return !$values;
            case 'not_empty':
                return (bool) $values;
            case 'is_not':
                return !in_array($expected, $values, true);
            case 'contains':
                foreach ($values as $one) {
                    if ($expected !== '' && strpos($one, $expected) !== false) {
                        return true;
                    }
                }

                return false;
            case 'is':
            default:
                return in_array($expected, $values, true);
        }
    }

    /**
     * Champs visibles pour ces réponses : clé => bool.
     *
     * @param array $fields champs du formulaire
     * @param array $values réponses par clé (brutes ou nettoyées)
     * @return array<string,bool>
     */
    public static function visibility(array $fields, array $values)
    {
        $visible = array();

        foreach ($fields as $field) {
            $visible[$field['key']] = true;
        }

        // Quelques passes : un champ masqué rend vide la réponse de ceux qui en dépendent (chaînes de conditions).
        for ($pass = 0; $pass < 5; $pass++) {
            $changed = false;

            foreach ($fields as $field) {
                if (!self::has($field)) {
                    continue;
                }

                $ref = (string) $field['logic_field'];
                $value = !empty($visible[$ref]) && array_key_exists($ref, $values) ? $values[$ref] : '';
                $hit = self::matches($field['logic_op'] ?? 'is', $value, $field['logic_value'] ?? '');
                $show = $field['logic'] === 'show' ? $hit : !$hit;

                if ($visible[$field['key']] !== $show) {
                    $visible[$field['key']] = $show;
                    $changed = true;
                }
            }

            if (!$changed) {
                break;
            }
        }

        return $visible;
    }

    /**
     * Attributs HTML de la règle, lus par assets/forms.js.
     */
    public static function attributes(array $field)
    {
        if (!self::has($field)) {
            return '';
        }

        return ' data-logic="' . esc_attr($field['logic']) . '" data-logic-field="' . esc_attr($field['logic_field']) . '" data-logic-op="' . esc_attr($field['logic_op'] ?? 'is') . '" data-logic-value="' . esc_attr($field['logic_value'] ?? '') . '"';
    }

    /**
     * Phrase lisible de la règle (aperçu du builder, détail).
     */
    public static function describe(array $field, array $form_fields)
    {
        if (!self::has($field)) {
            return '';
        }

        $label = $field['logic_field'];

        foreach ($form_fields as $other) {
            if ($other['key'] === $field['logic_field']) {
                $label = $other['label'] !== '' ? $other['label'] : $other['key'];
            }
        }

        $ops = self::operators();
        $op = $field['logic_op'] ?? 'is';

        return sprintf(
            $field['logic'] === 'show' ? __('Affiché si « %1$s » %2$s%3$s', 'smbb-wpcodetool') : __('Masqué si « %1$s » %2$s%3$s', 'smbb-wpcodetool'),
            $label,
            $ops[$op] ?? $op,
            in_array($op, array('empty', 'not_empty'), true) ? '' : ' « ' . $field['logic_value'] . ' »'
        );
    }
}
