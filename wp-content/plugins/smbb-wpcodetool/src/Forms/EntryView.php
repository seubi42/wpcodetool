<?php

namespace Smbb\WpCodeTool\Forms;

defined('ABSPATH') || exit;

/**
 * Affichage des réponses d'une entrée (fiche Entrée de CodeTool › Formulaires, et plugins qui
 * montrent une entrée dans leurs propres écrans : documents signés Passflow…).
 */
final class EntryView
{
    /**
     * Styles des réponses (forms-admin.css, sous .smbb-codetool) pour une page d'admin hors Formulaires.
     */
    public static function enqueue()
    {
        if (!defined('SMBB_WPCODETOOL_URL')) {
            return;
        }

        $path = SMBB_WPCODETOOL_PATH . 'assets/forms-admin.css';
        $version = SMBB_WPCODETOOL_VERSION . (is_readable($path) ? '.' . filemtime($path) : '');
        wp_enqueue_style('smbb-wpcodetool-forms-admin', SMBB_WPCODETOOL_URL . 'assets/forms-admin.css', array(), $version);
    }

    /**
     * Liste <dl> des réponses. $args : compact (bool, sans cadre), empty (texte si aucune réponse).
     *
     * @param array<string,mixed> $entry entrée hydratée (EntryRepository::find)
     */
    public static function html(array $entry, array $args = array())
    {
        $classes = 'smbb-forms-answers' . (!empty($args['compact']) ? ' smbb-forms-answers--compact' : '');
        $html = '<dl class="' . esc_attr($classes) . '">';

        foreach ((array) $entry['fields'] as $field) {
            $html .= self::answer($entry, (array) $field);
        }

        if (!$entry['fields']) {
            $html .= '<div class="smbb-forms-answer"><dd class="smbb-forms-empty">' . esc_html(isset($args['empty']) ? (string) $args['empty'] : __('Aucune réponse.', 'smbb-wpcodetool')) . '</dd></div>';
        }

        return $html . '</dl>';
    }

    private static function answer(array $entry, array $field)
    {
        $type = (string) ($field['type'] ?? '');
        $value = $field['value'] ?? '';
        $dd = '';

        if ($type === 'file') {
            foreach ((array) $value as $i => $file) {
                if (!is_array($file)) {
                    continue;
                }

                if (!empty($file['url'])) {
                    $dd .= '<a class="smbb-forms-file" href="' . esc_url($file['url']) . '" target="_blank" rel="noopener"><span class="dashicons dashicons-media-default" aria-hidden="true"></span>' . esc_html($file['name'] ?? wp_basename($file['url'])) . '</a>';
                } else {
                    $dd .= '<a class="smbb-forms-file" href="' . esc_url(Uploads::url($entry['id'], $field['key'] ?? '', $i)) . '"><span class="dashicons dashicons-download" aria-hidden="true"></span>' . esc_html(($file['name'] ?? '') . (isset($file['size']) ? ' (' . size_format((int) $file['size']) . ')' : '')) . '</a>';
                }
            }

            if (!$value) {
                $dd = '<span class="smbb-forms-empty">—</span>';
            }
        } elseif ($type === 'email' && is_email((string) $value)) {
            $dd = '<a href="mailto:' . esc_attr($value) . '">' . esc_html($value) . '</a>';
        } elseif ($type === 'phone' && $value !== '') {
            $dd = '<a href="tel:' . esc_attr(preg_replace('/[^0-9+]/', '', (string) $value)) . '">' . esc_html($value) . '</a>';
        } elseif ($type === 'url' && $value !== '') {
            $dd = '<a href="' . esc_url($value) . '" target="_blank" rel="noopener nofollow">' . esc_html($value) . '</a>';
        } else {
            $text = FieldTypes::display($type, $value);
            $dd = $text !== '' ? nl2br(esc_html($text)) : '<span class="smbb-forms-empty">—</span>';
        }

        return '<div class="smbb-forms-answer smbb-forms-answer--' . esc_attr($type) . '"><dt>' . esc_html($field['label'] ?? '') . '</dt><dd>' . $dd . '</dd></div>';
    }
}
