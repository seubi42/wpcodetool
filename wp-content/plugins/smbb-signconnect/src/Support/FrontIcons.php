<?php

namespace Smbb\SignConnect\Support;

defined('ABSPATH') || exit;

/**
 * Petites icones SVG utilisées sur le front.
 *
 * On garde ce helper volontairement simple :
 * - pas de dependance JS/CSS externe pour quelques pictos ;
 * - les icones heritent de la couleur du bouton via currentColor ;
 * - le texte du bouton reste present, donc les icones sont aria-hidden.
 */
final class FrontIcons
{
    public static function icon($name)
    {
        $icons = array(
            'arrow-right' => '<path d="M5 12h14"></path><path d="m13 6 6 6-6 6"></path>',
            'download' => '<path d="M12 3v12"></path><path d="m7 10 5 5 5-5"></path><path d="M5 21h14"></path>',
            'eye' => '<path d="M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6-10-6-10-6Z"></path><circle cx="12" cy="12" r="3"></circle>',
            'mail' => '<rect x="3" y="5" width="18" height="14" rx="2"></rect><path d="m3 7 9 6 9-6"></path>',
            'message' => '<path d="M21 15a4 4 0 0 1-4 4H8l-5 3V7a4 4 0 0 1 4-4h10a4 4 0 0 1 4 4Z"></path>',
            'plus' => '<path d="M12 5v14"></path><path d="M5 12h14"></path>',
            'check' => '<path d="m20 6-11 11-5-5"></path>',
            'x' => '<path d="M18 6 6 18"></path><path d="m6 6 12 12"></path>',
            'clock' => '<circle cx="12" cy="12" r="9"></circle><path d="M12 7v5l3 2"></path>',
            'refresh' => '<path d="M21 12a9 9 0 0 1-15 6.7L3 16"></path><path d="M3 21v-5h5"></path><path d="M3 12a9 9 0 0 1 15-6.7L21 8"></path><path d="M21 3v5h-5"></path>',
            'file' => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8Z"></path><path d="M14 2v6h6"></path>',
        );

        if (empty($icons[$name])) {
            return '';
        }

        return '<svg class="smbb-signconnect-button-icon" viewBox="0 0 24 24" width="16" height="16" aria-hidden="true" focusable="false">' . $icons[$name] . '</svg>';
    }
}
