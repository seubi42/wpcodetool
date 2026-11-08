<?php
/**
 * L'historique est alimenté automatiquement : pas de saisie.
 */

use Smbb\Passflow\Admin\View;

defined('ABSPATH') || exit;

View::page(
    View::card('', '<p>' . esc_html__("L'historique est alimenté automatiquement à chaque inscription, annulation, déplacement ou pointage.", 'smbb-passflow') . '</p><p><a class="button" href="' . esc_url(View::url('passflow_history')) . '">' . esc_html__("Retour à l'historique", 'smbb-passflow') . '</a></p>'),
    isset($page_header_html) ? $page_header_html : '',
    isset($notices_html) ? $notices_html : '',
    __('Historique', 'smbb-passflow')
);
