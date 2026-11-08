<?php
/**
 * Assistant › Image de marque : les sections des Réglages (Apparence des billets, Habillage des
 * e-mails, Expéditeur, Rappels). Valeurs de départ posées par le profil choisi.
 *
 * @var \Smbb\WpCodeTool\Admin\Form $form
 */

use Smbb\Passflow\Admin\SettingsSections as Sections;

defined('ABSPATH') || exit;

$d = 'smbb-passflow';

$fields = array(
    Sections::ticketLook($form),
    Sections::emailLook($form),
    Sections::sender($form),
    Sections::reminders($form),
    '<p class="description">' . esc_html__('Couleur unique des billets : elle devient aussi l\'accent du back-office Passflow. Le logo sert aussi à la page de connexion si vous l\'avez modernisée.', $d) . '</p>',
);
