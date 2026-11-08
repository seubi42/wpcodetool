<?php

namespace Smbb\WpCodeTool\Forms;

defined('ABSPATH') || exit;

/**
 * Types de champs des formulaires, et nettoyage de leur définition.
 *
 * Type « rating » : note de 1 à 5 étoiles (valeur '1' à '5'), pour les questionnaires de satisfaction.
 *
 * Une définition de champ (stockée en JSON dans smbb_forms.fields) :
 * key, type, label, required, help, placeholder, choices (une ligne par choix), width
 * (full | half | third | two-thirds), default (valeur d'un champ caché, balises admises),
 * content (texte libre), file_types (pdf,jpg…), file_max (Mo).
 *
 * Filtre smbb_wpcodetool_form_field_types pour ajouter un type (rendu : filtre
 * smbb_wpcodetool_form_field_html, contrôle : smbb_wpcodetool_form_field_value).
 */
final class FieldTypes
{
    const WIDTHS = array('full', 'half', 'third', 'two-thirds');
    const RATING_MAX = 5;

    /**
     * @return array<string,array{label:string,icon:string,group:string,input:bool,choices:bool,placeholder:bool}>
     */
    public static function all()
    {
        $base = __('Base', 'smbb-wpcodetool');
        $choice = __('Choix', 'smbb-wpcodetool');
        $more = __('Avancé', 'smbb-wpcodetool');
        $layout = __('Mise en page', 'smbb-wpcodetool');

        $types = array(
            'text' => array('label' => __('Texte', 'smbb-wpcodetool'), 'icon' => 'dashicons-editor-textcolor', 'group' => $base, 'placeholder' => true),
            'textarea' => array('label' => __('Paragraphe', 'smbb-wpcodetool'), 'icon' => 'dashicons-editor-paragraph', 'group' => $base, 'placeholder' => true),
            'email' => array('label' => __('E-mail', 'smbb-wpcodetool'), 'icon' => 'dashicons-email', 'group' => $base, 'placeholder' => true),
            'phone' => array('label' => __('Téléphone', 'smbb-wpcodetool'), 'icon' => 'dashicons-phone', 'group' => $base, 'placeholder' => true),
            'number' => array('label' => __('Nombre', 'smbb-wpcodetool'), 'icon' => 'dashicons-calculator', 'group' => $base, 'placeholder' => true),
            'date' => array('label' => __('Date', 'smbb-wpcodetool'), 'icon' => 'dashicons-calendar-alt', 'group' => $base),
            'select' => array('label' => __('Liste', 'smbb-wpcodetool'), 'icon' => 'dashicons-menu-alt', 'group' => $choice, 'choices' => true),
            'radio' => array('label' => __('Choix unique', 'smbb-wpcodetool'), 'icon' => 'dashicons-marker', 'group' => $choice, 'choices' => true),
            'checkbox' => array('label' => __('Cases à cocher', 'smbb-wpcodetool'), 'icon' => 'dashicons-yes-alt', 'group' => $choice, 'choices' => true),
            'rating' => array('label' => __('Note (étoiles)', 'smbb-wpcodetool'), 'icon' => 'dashicons-star-filled', 'group' => $choice),
            'url' => array('label' => __('Adresse web', 'smbb-wpcodetool'), 'icon' => 'dashicons-admin-links', 'group' => $more, 'placeholder' => true),
            'file' => array('label' => __('Fichier', 'smbb-wpcodetool'), 'icon' => 'dashicons-upload', 'group' => $more),
            'hidden' => array('label' => __('Caché', 'smbb-wpcodetool'), 'icon' => 'dashicons-hidden', 'group' => $more),
            'gdpr' => array('label' => __('Consentement', 'smbb-wpcodetool'), 'icon' => 'dashicons-shield', 'group' => $more),
            'section' => array('label' => __('Titre de section', 'smbb-wpcodetool'), 'icon' => 'dashicons-heading', 'group' => $layout, 'input' => false),
            'html' => array('label' => __('Texte libre', 'smbb-wpcodetool'), 'icon' => 'dashicons-text', 'group' => $layout, 'input' => false),
        );

        $types = (array) apply_filters('smbb_wpcodetool_form_field_types', $types);
        $out = array();

        foreach ($types as $key => $def) {
            $key = sanitize_key((string) $key);

            if ($key === '' || !is_array($def)) {
                continue;
            }

            $out[$key] = array(
                'label' => isset($def['label']) ? (string) $def['label'] : $key,
                'icon' => isset($def['icon']) ? (string) $def['icon'] : 'dashicons-forms',
                'group' => isset($def['group']) ? (string) $def['group'] : $more,
                'input' => !array_key_exists('input', $def) || !empty($def['input']),
                'choices' => !empty($def['choices']),
                'placeholder' => !empty($def['placeholder']),
            );
        }

        return $out;
    }

    public static function exists($type)
    {
        $all = self::all();

        return isset($all[$type]);
    }

    public static function label($type)
    {
        $all = self::all();

        return isset($all[$type]) ? $all[$type]['label'] : (string) $type;
    }

    /** Types qui recueillent une réponse (pas la mise en page). */
    public static function isInput($type)
    {
        $all = self::all();

        return isset($all[$type]) && $all[$type]['input'];
    }

    /** Types dont le clic « Obligatoire » n'a pas de sens. */
    public static function noRequired()
    {
        return array('section', 'html', 'hidden');
    }

    /**
     * Nettoie la liste des champs envoyée par le builder (ou par une reprise WPForms).
     *
     * @return array<int,array<string,string>>
     */
    public static function sanitizeList($fields)
    {
        if (!is_array($fields)) {
            return array();
        }

        $out = array();
        $keys = array();

        foreach (array_values($fields) as $field) {
            $clean = self::sanitize(is_array($field) ? $field : array());

            if (!$clean) {
                continue;
            }

            // Clé unique et stable : elle relie les entrées à leur champ.
            $key = $clean['key'];

            while ($key === '' || isset($keys[$key])) {
                $key = 'f' . strtolower(wp_generate_password(6, false, false));
            }

            $clean['key'] = $key;
            $keys[$key] = true;
            $out[] = $clean;
        }

        // Condition sur un champ disparu (ou sur lui-même) : retirée.
        foreach ($out as $i => $clean) {
            if ($clean['logic'] !== '' && ($clean['logic_field'] === $clean['key'] || !isset($keys[$clean['logic_field']]))) {
                $out[$i]['logic'] = '';
            }
        }

        return $out;
    }

    /**
     * Identifiant saisi par l'utilisateur → clé de champ : « Prénom client » → prenom_client.
     */
    public static function normalizeKey($text)
    {
        $key = strtolower(remove_accents(trim((string) $text)));
        $key = preg_replace('/[^a-z0-9_\-]+/', '_', $key);
        $key = trim((string) $key, '_-');

        return substr($key, 0, 40);
    }

    /**
     * @return array<string,string>|array{}
     */
    public static function sanitize(array $field)
    {
        $type = isset($field['type']) ? sanitize_key((string) $field['type']) : '';

        if (!self::exists($type)) {
            return array();
        }

        $text = static function ($key, $max = 500) use ($field) {
            return isset($field[$key]) && is_scalar($field[$key]) ? mb_substr(sanitize_text_field((string) $field[$key]), 0, $max) : '';
        };
        $width = isset($field['width']) ? sanitize_key((string) $field['width']) : 'full';
        $choices = isset($field['choices']) && is_scalar($field['choices']) ? (string) $field['choices'] : '';
        $choices = implode("\n", array_values(array_filter(array_map(static function ($line) {
            return mb_substr(sanitize_text_field($line), 0, 200);
        }, preg_split('/\r\n|\r|\n/', $choices)), 'strlen')));

        return array(
            'key' => isset($field['key']) ? substr(sanitize_key((string) $field['key']), 0, 40) : '',
            'type' => $type,
            'label' => $text('label', 300),
            'required' => !empty($field['required']) && !in_array($type, self::noRequired(), true) ? '1' : '0',
            'help' => $text('help', 500),
            'placeholder' => $text('placeholder', 200),
            'choices' => $choices,
            'width' => in_array($width, self::WIDTHS, true) ? $width : 'full',
            'default' => $text('default', 500),
            'content' => isset($field['content']) && is_scalar($field['content']) ? wp_kses_post((string) $field['content']) : '',
            'file_types' => strtolower(preg_replace('/[^a-z0-9,]/i', '', str_replace(array(' ', ';', '.'), array('', ',', ''), $text('file_types', 200)))),
            'file_max' => isset($field['file_max']) && is_numeric($field['file_max']) ? (string) max(1, min(64, (int) $field['file_max'])) : '10',
            // Logique conditionnelle (Logic) : afficher / masquer selon la réponse à un autre champ.
            'logic' => isset($field['logic']) && in_array($field['logic'], array('show', 'hide'), true) && $type !== 'hidden' ? $field['logic'] : '',
            'logic_field' => isset($field['logic_field']) ? substr(sanitize_key((string) $field['logic_field']), 0, 40) : '',
            'logic_op' => isset($field['logic_op']) && array_key_exists((string) $field['logic_op'], Logic::operators()) ? (string) $field['logic_op'] : 'is',
            'logic_value' => $text('logic_value', 200),
        );
    }

    /**
     * @return string[]
     */
    public static function choices(array $field)
    {
        return array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', (string) ($field['choices'] ?? ''))), 'strlen'));
    }

    /**
     * Extensions acceptées pour un champ fichier (réglage du champ, sinon une liste sûre).
     *
     * @return string[]
     */
    public static function fileTypes(array $field)
    {
        $wanted = array_filter(explode(',', (string) ($field['file_types'] ?? '')));
        $safe = array('pdf', 'jpg', 'jpeg', 'png', 'gif', 'webp', 'heic', 'doc', 'docx', 'odt', 'xls', 'xlsx', 'ods', 'csv', 'txt', 'zip');
        $allowed = array_keys(self::flatExtensions());
        $list = $wanted ? array_values(array_intersect($wanted, $allowed ?: $safe)) : array();

        return $list ?: $safe;
    }

    /**
     * Extensions que WordPress accepte au téléversement.
     *
     * @return array<string,bool>
     */
    private static function flatExtensions()
    {
        $out = array();

        foreach (array_keys(get_allowed_mime_types()) as $pattern) {
            foreach (explode('|', $pattern) as $ext) {
                $out[$ext] = true;
            }
        }

        return $out;
    }

    /**
     * Contrôle et nettoie la valeur envoyée pour un champ.
     *
     * @param mixed $raw
     * @return array{0:mixed,1:string} [valeur nettoyée, message d'erreur ou '']
     */
    public static function validate(array $field, $raw)
    {
        $type = $field['type'];
        $required = $field['required'] === '1';
        $empty_message = __('Ce champ est obligatoire.', 'smbb-wpcodetool');

        switch ($type) {
            case 'checkbox':
                $values = is_array($raw) ? array_map(static function ($v) {
                    return sanitize_text_field((string) $v);
                }, $raw) : array();
                $values = array_values(array_intersect($values, self::choices($field)));

                return array($values, $required && !$values ? __('Cochez au moins une case.', 'smbb-wpcodetool') : '');

            case 'gdpr':
                $on = !empty($raw);

                return array($on ? '1' : '', $required && !$on ? __('Merci de cocher cette case pour continuer.', 'smbb-wpcodetool') : '');

            case 'select':
            case 'radio':
                $value = is_scalar($raw) ? sanitize_text_field((string) $raw) : '';

                if ($value !== '' && !in_array($value, self::choices($field), true)) {
                    return array('', __('Choisissez une valeur de la liste.', 'smbb-wpcodetool'));
                }

                return array($value, $required && $value === '' ? __('Faites un choix.', 'smbb-wpcodetool') : '');

            case 'rating':
                $value = is_scalar($raw) ? (int) $raw : 0;
                $value = $value >= 1 && $value <= self::RATING_MAX ? (string) $value : '';

                return array($value, $required && $value === '' ? __('Choisissez une note.', 'smbb-wpcodetool') : '');

            case 'textarea':
                $value = is_scalar($raw) ? trim(sanitize_textarea_field((string) $raw)) : '';
                $value = mb_substr($value, 0, 20000);

                return array($value, $required && $value === '' ? $empty_message : '');

            case 'email':
                $value = is_scalar($raw) ? trim(sanitize_text_field((string) $raw)) : '';

                if ($value !== '' && !is_email($value)) {
                    return array($value, __('Cette adresse e-mail ne semble pas valide.', 'smbb-wpcodetool'));
                }

                return array($value !== '' ? sanitize_email($value) : '', $required && $value === '' ? $empty_message : '');

            case 'phone':
                $value = is_scalar($raw) ? trim(sanitize_text_field((string) $raw)) : '';

                if ($value !== '' && (!preg_match('/^[0-9 +().\-\/]{6,25}$/', $value) || strlen(preg_replace('/\D/', '', $value)) < 6)) {
                    return array($value, __('Ce numéro de téléphone ne semble pas valide.', 'smbb-wpcodetool'));
                }

                return array($value, $required && $value === '' ? $empty_message : '');

            case 'number':
                $value = is_scalar($raw) ? trim(str_replace(',', '.', sanitize_text_field((string) $raw))) : '';

                if ($value !== '' && !is_numeric($value)) {
                    return array($value, __('Indiquez un nombre.', 'smbb-wpcodetool'));
                }

                return array($value, $required && $value === '' ? $empty_message : '');

            case 'url':
                $value = is_scalar($raw) ? trim(sanitize_text_field((string) $raw)) : '';

                if ($value !== '' && !preg_match('#^https?://#i', $value)) {
                    $value = 'https://' . $value;
                }

                if ($value !== '' && !wp_http_validate_url($value)) {
                    return array($value, __('Cette adresse web ne semble pas valide.', 'smbb-wpcodetool'));
                }

                return array($value !== '' ? esc_url_raw($value) : '', $required && $value === '' ? $empty_message : '');

            case 'date':
                $value = is_scalar($raw) ? sanitize_text_field((string) $raw) : '';

                if ($value !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
                    return array('', __('Indiquez une date valide.', 'smbb-wpcodetool'));
                }

                return array($value, $required && $value === '' ? $empty_message : '');

            case 'hidden':
                // La valeur d'un champ caché vient de sa définition (balises remplacées à l'envoi), pas du navigateur.
                return array(is_scalar($raw) ? sanitize_text_field((string) $raw) : '', '');

            case 'text':
            default:
                $value = is_scalar($raw) ? trim(sanitize_text_field((string) $raw)) : '';
                $value = mb_substr($value, 0, 2000);
                $result = array($value, $required && $value === '' ? $empty_message : '');

                return (array) apply_filters('smbb_wpcodetool_form_field_value', $result, $field, $raw);
        }
    }

    /**
     * Valeur lisible (listes jointes par des virgules, case cochée = « Oui »).
     *
     * @param mixed $value
     */
    public static function display($type, $value)
    {
        if (is_array($value)) {
            return implode(', ', array_map('strval', $value));
        }

        if ($type === 'gdpr') {
            return $value ? __('Oui', 'smbb-wpcodetool') : __('Non', 'smbb-wpcodetool');
        }

        if ($type === 'rating' && (string) $value !== '') {
            return sprintf(__('%1$d / %2$d', 'smbb-wpcodetool'), (int) $value, self::RATING_MAX);
        }

        if ($type === 'date' && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $value)) {
            return wp_date(get_option('date_format'), strtotime($value . ' 12:00:00'));
        }

        return (string) $value;
    }
}
