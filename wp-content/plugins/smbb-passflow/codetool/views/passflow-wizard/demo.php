<?php
/**
 * Assistant › Données de démo.
 *
 * @var string[] $summary
 * @var bool     $demo_exists
 */

use Smbb\Passflow\Admin\Onboarding;
use Smbb\Passflow\Admin\View;

defined('ABSPATH') || exit;

$d = 'smbb-passflow';

if ($demo_exists) {
    echo '<p>' . esc_html__('Les données de démo sont déjà là. Pour les retirer : Réglages › Configuration, ou le bouton ci-dessous.', $d) . '</p>'
        . '<p><a class="button" href="' . esc_url(View::actionUrl(Onboarding::DEMO_DELETE)) . '" onclick="return confirm(' . esc_attr(wp_json_encode(__('Supprimer les données de démo ? Le reste n\'est pas touché.', $d))) . ');">'
        . '<span class="dashicons dashicons-trash" aria-hidden="true"></span> ' . esc_html__('Supprimer les données de démo', $d) . '</a></p>';

    return;
}

echo '<label class="smbb-wizard-check"><input type="hidden" name="wizard_demo" value="0"><input type="checkbox" name="wizard_demo" value="1" checked> <strong>' . esc_html__('Créer les données de démo', $d) . '</strong></label>';
echo '<div class="smbb-wizard-recap"><ul>';

foreach ($summary as $line) {
    echo '<li>' . esc_html($line) . '</li>';
}

echo '</ul><p class="description">' . esc_html__('Adresses en @example.com, jamais délivrées. Tant que la démo existe, le mode test reste actif (les messages partent à votre adresse) et les factures vont dans la série DEMO-, sans toucher à la vraie numérotation.', $d) . '</p></div>';
