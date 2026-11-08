<?php

namespace Smbb\WpCodeTool\Forms;

defined('ABSPATH') || exit;

/**
 * Balises remplacées dans les notifications, confirmations et champs cachés.
 *
 * {all_fields} {field:cle} {form_name} {site_name} {site_url} {admin_email} {page_url}
 * {date} {entry_id} {entry_url} {user_email} {user_name} {ip} {visitor_email}
 *
 * Filtre smbb_wpcodetool_form_smart_tags pour en ajouter : tableau balise => valeur.
 */
final class SmartTags
{
    /**
     * Balises proposées dans l'aide de l'admin : balise => description.
     */
    public static function help()
    {
        return array(
            '{all_fields}' => __('toutes les réponses', 'smbb-wpcodetool'),
            '{field:cle}' => __('une réponse (clé visible dans « Avancé » du champ)', 'smbb-wpcodetool'),
            '{form_name}' => __('nom du formulaire', 'smbb-wpcodetool'),
            '{site_name}' => __('nom du site', 'smbb-wpcodetool'),
            '{admin_email}' => __('e-mail de l\'administrateur', 'smbb-wpcodetool'),
            '{page_url}' => __('page d\'envoi', 'smbb-wpcodetool'),
            '{date}' => __('date et heure', 'smbb-wpcodetool'),
            '{entry_url}' => __('lien vers l\'entrée dans l\'admin', 'smbb-wpcodetool'),
            '{visitor_email}' => __('e-mail saisi par le visiteur (1er champ e-mail)', 'smbb-wpcodetool'),
            '{user_email}' => __('e-mail de la personne connectée', 'smbb-wpcodetool'),
        );
    }

    /**
     * @param array $context form, fields (photo de l'entrée), entry_id, page_url, ip
     */
    public static function replace($text, array $context, $html = false)
    {
        $text = (string) $text;

        if (strpos($text, '{') === false) {
            return $text;
        }

        $form = isset($context['form']) ? $context['form'] : array('title' => '');
        $fields = isset($context['fields']) ? (array) $context['fields'] : array();
        $user = wp_get_current_user();
        $entry_id = isset($context['entry_id']) ? (int) $context['entry_id'] : 0;
        $tags = array(
            '{form_name}' => (string) $form['title'],
            '{site_name}' => wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES),
            '{site_url}' => home_url('/'),
            '{admin_email}' => (string) get_option('admin_email'),
            '{page_url}' => (string) ($context['page_url'] ?? ''),
            '{date}' => wp_date(get_option('date_format') . ' ' . get_option('time_format')),
            '{entry_id}' => $entry_id ? (string) $entry_id : '',
            '{entry_url}' => $entry_id ? admin_url('admin.php?page=' . \Smbb\WpCodeTool\Admin\Pages\FormsPage::ENTRIES_SLUG . '&entry=' . $entry_id) : '',
            '{user_email}' => $user && $user->exists() ? (string) $user->user_email : '',
            '{user_name}' => $user && $user->exists() ? (string) $user->display_name : '',
            '{ip}' => (string) ($context['ip'] ?? ''),
            '{visitor_email}' => self::visitorEmail($fields),
        );

        $tags = (array) apply_filters('smbb_wpcodetool_form_smart_tags', $tags, $context);

        if ($html) {
            $tags = array_map('esc_html', array_map('strval', $tags));
            $tags['{all_fields}'] = self::allFieldsHtml($fields, !empty($context['file_links']) ? $entry_id : 0);
        } else {
            $tags['{all_fields}'] = self::allFieldsText($fields);
        }

        $text = strtr($text, $tags);

        // {field:cle} — et {field_id="3"} gardé par une reprise WPForms non convertie.
        $links = $html && !empty($context['file_links']) ? $entry_id : 0;

        return (string) preg_replace_callback('/\{field:([a-z0-9_\-]+)\}/i', static function ($m) use ($fields, $html, $links) {
            foreach ($fields as $field) {
                if (isset($field['key']) && strtolower($field['key']) === strtolower($m[1])) {
                    if ($links && ($field['type'] ?? '') === 'file') {
                        return self::fileLinksHtml($field, $links);
                    }

                    $value = self::valueText($field);

                    return $html ? nl2br(esc_html($value)) : $value;
                }
            }

            return '';
        }, $text);
    }

    /**
     * Adresse saisie par le visiteur : premier champ e-mail rempli.
     */
    public static function visitorEmail(array $fields)
    {
        foreach ($fields as $field) {
            if (($field['type'] ?? '') === 'email' && is_string($field['value'] ?? null) && is_email($field['value'])) {
                return $field['value'];
            }
        }

        return '';
    }

    public static function valueText(array $field)
    {
        $value = $field['value'] ?? '';

        if (($field['type'] ?? '') === 'file') {
            return implode(', ', array_map(static function ($file) {
                return is_array($file) ? (string) ($file['name'] ?? '') : (string) $file;
            }, is_array($value) ? $value : array()));
        }

        return FieldTypes::display($field['type'] ?? '', $value);
    }

    public static function allFieldsText(array $fields)
    {
        $lines = array();

        foreach ($fields as $field) {
            if (($field['type'] ?? '') === 'hidden') {
                continue;
            }

            $value = self::valueText($field);
            $lines[] = ($field['label'] ?? '') . ' : ' . ($value !== '' ? $value : '—');
        }

        return implode("\n", $lines);
    }

    /**
     * Fichiers d'un champ en liens de téléchargement (connexion à l'admin demandée).
     */
    public static function fileLinksHtml(array $field, $entry_id)
    {
        $links = array();

        foreach (is_array($field['value'] ?? null) ? $field['value'] : array() as $i => $file) {
            if (!is_array($file) || (string) ($file['name'] ?? '') === '') {
                continue;
            }

            $url = !empty($file['url']) ? (string) $file['url'] : Uploads::url($entry_id, (string) $field['key'], $i);
            $size = isset($file['size']) ? ' <span style="color:#6e6e73;">(' . esc_html(size_format((int) $file['size'])) . ')</span>' : '';
            $links[] = '<a href="' . esc_url($url) . '" style="color:#0a66d8;">' . esc_html($file['name']) . '</a>' . $size;
        }

        return implode('<br>', $links);
    }

    public static function allFieldsHtml(array $fields, $file_links_entry = 0)
    {
        $rows = '';

        foreach ($fields as $field) {
            if (($field['type'] ?? '') === 'hidden') {
                continue;
            }

            $value = self::valueText($field);
            $cell = $value !== '' ? nl2br(esc_html($value)) : '<span style="color:#999;">—</span>';

            if ($file_links_entry && ($field['type'] ?? '') === 'file' && $value !== '') {
                $cell = self::fileLinksHtml($field, $file_links_entry);
            }

            $rows .= '<tr><th style="text-align:left;vertical-align:top;padding:8px 12px 8px 0;border-bottom:1px solid #eee;font-weight:600;width:35%;">' . esc_html($field['label'] ?? '') . '</th>'
                . '<td style="vertical-align:top;padding:8px 0;border-bottom:1px solid #eee;">' . $cell . '</td></tr>';
        }

        return '<table role="presentation" style="width:100%;border-collapse:collapse;font-size:14px;line-height:1.5;">' . $rows . '</table>';
    }
}
