<?php
/**
 * Assistant › Profil d'activité : sous les cartes, ce qu'applique un profil.
 */

use Smbb\Passflow\Admin\View;

defined('ABSPATH') || exit;

$d = 'smbb-passflow';

echo '<div class="smbb-wizard-recap"><strong>' . esc_html__('Un profil règle, sans toucher à vos textes de messages :', $d) . '</strong><ul>'
    . '<li>' . esc_html__('le vocabulaire partout (atelier, stage, pack ; chef, moniteur, instructeur ; billet ou badge…) ;', $d) . '</li>'
    . '<li>' . esc_html__('les informations demandées aux participants (allergies, niveau, âge) ;', $d) . '</li>'
    . '<li>' . esc_html__('les documents à faire signer (décharge, droit à l\'image), ajoutés à ceux que vous avez déjà ;', $d) . '</li>'
    . '<li>' . esc_html__('les couleurs de départ des billets et des e-mails, et le style de l\'administration ;', $d) . '</li>'
    . '<li>' . esc_html__('l\'exemple de première offre et le catalogue de la démo.', $d) . '</li>'
    . '</ul><p class="description">' . sprintf(
        /* translators: %s: lien vers Réglages › Vocabulaire */
        esc_html__('Le fichier d\'un profil a le format de l\'export des réglages : il s\'importe aussi depuis Réglages › Configuration. Affinez ensuite dans %s.', $d),
        '<a href="' . esc_url(View::url('passflow_settings')) . '">' . esc_html__('Réglages › Vocabulaire', $d) . '</a>'
    ) . '</p></div>';
