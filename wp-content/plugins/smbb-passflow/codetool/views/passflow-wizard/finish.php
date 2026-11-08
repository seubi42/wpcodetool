<?php
/**
 * Assistant › C'est prêt : raccourcis, ce qui reste à faire, passage en production.
 *
 * @var bool                     $demo_exists
 * @var array<string,mixed>|null $offer
 */

use Smbb\Passflow\Admin\View;
use Smbb\Passflow\Support\Settings;

defined('ABSPATH') || exit;

$d = 'smbb-passflow';
$links = array(
    array(__('Voir le calendrier', $d), View::url('passflow_calendar'), 'dashicons-calendar'),
    array(__('Faire l\'appel d\'une date', $d), View::url('passflow_slot'), 'dashicons-groups'),
);

if ($offer && !empty($offer['product_id']) && get_permalink((int) $offer['product_id'])) {
    $links[] = array(__('Voir la fiche sur le site', $d), get_permalink((int) $offer['product_id']), 'dashicons-external');
}

echo '<p>';

foreach ($links as $link) {
    echo '<a class="button" href="' . esc_url($link[1]) . '"' . ($link[2] === 'dashicons-external' ? ' target="_blank" rel="noopener"' : '') . '><span class="dashicons ' . esc_attr($link[2]) . '" aria-hidden="true"></span> ' . esc_html($link[0]) . '</a> ';
}

echo '</p><div class="smbb-wizard-recap"><strong>' . esc_html__('Pour vendre pour de vrai :', $d) . '</strong><ul>'
    . '<li>' . esc_html__('brancher le paiement en ligne (WooCommerce › Réglages › Paiements) ;', $d) . '</li>'
    . '<li>' . esc_html__('les SMS (Réglages › Envoi, compte Twilio) ;', $d) . '</li>'
    . '<li>' . esc_html__('Apple et Google Wallet (Réglages › Billets) ;', $d) . '</li>'
    . '<li>' . esc_html__('relire Entreprise et TVA (Réglages › Configuration › Entreprise et TVA) ;', $d) . '</li>'
    . '<li>' . esc_html__('couper le mode test.', $d) . '</li>'
    . '</ul></div>';

echo '<h3>' . esc_html__('Passer en production', $d) . '</h3>';

if ($demo_exists) {
    echo '<label class="smbb-wizard-check"><input type="checkbox" name="wizard_delete_demo" value="1"> ' . esc_html__('Supprimer les données de démo', $d) . '</label><br>';
}

echo '<label class="smbb-wizard-check"><input type="checkbox" name="wizard_go_live" value="1"' . ((int) Settings::get('test_mode') ? '' : ' disabled') . '> '
    . esc_html__('Couper le mode test, le mode démo des factures et la carte fictive : les messages partent aux vrais destinataires.', $d) . '</label>';
echo '<p class="description">' . esc_html__('Sinon, terminez simplement : tout reste modifiable dans Réglages, et l\'assistant se relance depuis Réglages › Configuration.', $d) . '</p>';
