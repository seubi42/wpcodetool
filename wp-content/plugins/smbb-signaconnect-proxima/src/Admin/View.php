<?php

namespace Smbb\SignConnectProxima\Admin;

defined('ABSPATH') || exit;

/**
 * Petits gabarits partagés par les vues d'admin CodeTool du plugin.
 */
final class View
{
    /**
     * Affiche une page d'admin : en-tête CodeTool, notices, puis le contenu.
     */
    public static function page($content, $page_header_html = '', $notices_html = '', $fallback_title = '')
    {
        echo '<div class="wrap smbb-codetool scp-admin">';

        if ($page_header_html !== '') {
            echo $page_header_html; // phpcs:ignore -- HTML fourni par CodeTool.
        } elseif ($fallback_title !== '') {
            echo '<h1>' . esc_html($fallback_title) . '</h1>';
        }

        if ($notices_html !== '') {
            echo $notices_html; // phpcs:ignore -- HTML fourni par CodeTool.
        }

        echo $content; // phpcs:ignore -- HTML construit et échappé par les vues.
        echo '</div>';
    }

    /**
     * Rend une liste CodeTool (la Table produit elle-même l'en-tête de page).
     *
     * @param \Smbb\WpCodeTool\Admin\Table $table
     */
    public static function table($table)
    {
        echo '<div class="scp-admin">';
        $table->render();
        echo '</div>';
    }

    /**
     * Encart d'information à glisser dans un formulaire (le Form accepte du HTML brut).
     */
    public static function note($title, $html, $icon = 'dashicons-info-outline')
    {
        return '<div class="smbb-codetool-section scp-note">'
            . '<header class="smbb-codetool-section-header">'
            . '<span class="smbb-codetool-section-icon" aria-hidden="true"><span class="dashicons ' . esc_attr($icon) . '"></span></span>'
            . '<div class="smbb-codetool-section-heading"><h3>' . esc_html($title) . '</h3></div>'
            . '</header>'
            . '<div class="smbb-codetool-section-body">' . $html . '</div>'
            . '</div>';
    }

    /**
     * Liste de paires libellé / valeur (valeurs déjà échappées).
     *
     * @param array<string,string> $pairs
     */
    public static function definitionList(array $pairs)
    {
        $html = '<dl class="scp-dl">';

        foreach ($pairs as $label => $value) {
            $html .= '<dt>' . esc_html($label) . '</dt><dd>' . ($value !== '' ? $value : '<span class="scp-muted">—</span>') . '</dd>';
        }

        return $html . '</dl>';
    }

    public static function money($value)
    {
        return esc_html(number_format_i18n((float) $value, 2) . ' € HT');
    }

    public static function percent($value)
    {
        return (float) $value > 0 ? esc_html(number_format_i18n((float) $value, 2) . ' %') : '';
    }

    public static function datetime($value)
    {
        if (empty($value) || $value === '0000-00-00 00:00:00') {
            return '';
        }

        return esc_html(mysql2date(get_option('date_format') . ' H:i', (string) $value));
    }
}
