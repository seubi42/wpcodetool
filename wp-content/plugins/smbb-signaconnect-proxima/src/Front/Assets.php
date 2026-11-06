<?php

namespace Smbb\SignConnectProxima\Front;

defined('ABSPATH') || exit;

/**
 * Feuilles de style et scripts du front, chargés seulement par les shortcodes.
 */
final class Assets
{
    public static function enqueue($script, array $config)
    {
        wp_enqueue_style('scp-front', SMBB_SCP_URL . 'assets/front.css', array(), SMBB_SCP_VERSION);
        wp_enqueue_script('scp-viewer', SMBB_SCP_URL . 'assets/viewer.js', array(), SMBB_SCP_VERSION, true);
        wp_enqueue_script('scp-zones', SMBB_SCP_URL . 'assets/zones.js', array('scp-viewer'), SMBB_SCP_VERSION, true);
        wp_enqueue_script('scp-' . $script, SMBB_SCP_URL . 'assets/' . $script . '.js', array('scp-viewer', 'scp-zones'), SMBB_SCP_VERSION, true);
        wp_localize_script('scp-' . $script, 'ScpConfig', $config);
    }

    public static function wrap($html, $class = '', $color = '')
    {
        $style = $color !== '' ? ' style="--scp-brand:' . esc_attr($color) . '"' : '';

        return '<div class="scp ' . esc_attr($class) . '"' . $style . '>' . $html . '</div>';
    }

    public static function message($title, $text, $tone = 'info')
    {
        return '<div class="scp-state scp-state--' . esc_attr($tone) . '"><div class="scp-state-icon" aria-hidden="true"></div><h2>' . esc_html($title) . '</h2>'
            . ($text !== '' ? '<p>' . esc_html($text) . '</p>' : '') . '</div>';
    }
}
