<?php
/**
 * Assistant › Boutique : sous la liste des extensions, ce que Passflow règle dans WooCommerce.
 *
 * @var array<string,bool> $checklist
 * @var string             $fake_card  admins | all | none
 */

defined('ABSPATH') || exit;

$d = 'smbb-passflow';

// Carte bancaire fictive de CodeTool : pour qui ? (posé en continuant, réglable ensuite dans CodeTool › WooCommerce).
$fake_card = isset($fake_card) ? (string) $fake_card : 'admins';
$choices = array(
    'admins' => array(__('Oui, pour les administrateurs seulement', $d), __('Vous testez une commande de bout en bout, vos clients ne la voient pas.', $d)),
    'all' => array(__('Oui, pour tout le monde', $d), __('Pour une démo publique : n\'importe quel visiteur peut « payer » sans être débité, et sa réservation est créée. À couper avant d\'ouvrir les ventes.', $d)),
    'none' => array(__('Non', $d), __('Vous branchez directement un vrai moyen de paiement.', $d)),
);
$picker = '<fieldset class="smbb-wizard-recap"><legend><strong>' . esc_html__('Activer une carte bancaire fictive (7 jours) ?', $d) . '</strong></legend>';

foreach ($choices as $value => $choice) {
    $picker .= '<label class="smbb-wizard-check"><input type="radio" name="wizard_fake_card" value="' . esc_attr($value) . '"' . checked($fake_card, $value, false) . '> <span><strong>' . esc_html($choice[0]) . '</strong><br><span class="description">' . esc_html($choice[1]) . '</span></span></label><br>';
}

$picker .= '</fieldset>';

if (empty($checklist)) {
    echo '<div class="smbb-wizard-recap"><strong>' . esc_html__('Une fois WooCommerce actif, en continuant, Passflow règle :', $d) . '</strong><ul>'
        . '<li>' . esc_html__('la France et l\'euro (format 1 234,50 €) ;', $d) . '</li>'
        . '<li>' . esc_html__('les pages Panier, Commande et Mon compte ;', $d) . '</li>'
        . '<li>' . esc_html__('un compte client à chaque commande (billets, factures et « Mes ateliers » y sont rangés) ;', $d) . '</li>'
        . '<li>' . esc_html__('la carte bancaire fictive de CodeTool, selon votre choix ci-dessous.', $d) . '</li>'
        . '</ul></div>' . $picker;

    return;
}

echo '<div class="smbb-wizard-recap"><strong>' . esc_html__('Réglages de la boutique (posés en continuant) :', $d) . '</strong><ul>';

foreach ($checklist as $label => $ok) {
    echo '<li>' . ($ok ? '✓ ' : '○ ') . esc_html($label) . '</li>';
}

echo '</ul><p class="description">' . esc_html__('Le vrai moyen de paiement (Stripe, SumUp, PayPal…) se branche ensuite dans WooCommerce › Réglages › Paiements.', $d) . '</p></div>' . $picker;
