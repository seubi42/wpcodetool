<?php
/**
 * Réglages de la plateforme. Aucune clé API ici : elles sont portées par chaque organisation.
 *
 * @var \Smbb\WpCodeTool\Admin\Form $form
 */

use Smbb\SignConnectProxima\Admin\View;
use Smbb\SignConnectProxima\Support\FrontPages;

defined('ABSPATH') || exit;

$d = 'smbb-signaconnect-proxima';
$page_options = array(__('— Choisir —', $d) => '0');

foreach (get_pages(array('sort_column' => 'post_title', 'post_status' => array('publish', 'private', 'draft'))) as $page) {
    $page_options[$page->post_title . ' (#' . $page->ID . ')'] = (string) $page->ID;
}

$close_days = array(__('Manuelle (bouton Clôturer)', $d) => '0');

foreach (array(1, 2, 3, 5, 7, 10, 15) as $day) {
    $close_days[sprintf(_n('Le %d du mois suivant', 'Le %d du mois suivant', $day, $d), $day)] = (string) $day;
}

$fields = array(
    $form->section(__('Pages front', $d), __('Chaque page doit contenir son shortcode : [signaconnect_app] et [signaconnect_signature].', $d), array(
        $form->row(
            $form->select(__("Page de l'app", $d))->setName('app_page_id')->setOptions($page_options),
            $form->select(__('Page de signature publique', $d))->setName('signing_page_id')->setOptions($page_options)
        ),
        $form->toggle(__('Formulaire de connexion soigné', $d))->setName('style_login')->setHelp(__("Sur la page de l'app, un visiteur non connecté voit une carte de connexion aux couleurs de SignaConnect. Décochez pour garder le formulaire WordPress stylé par le thème.", $d)),
        $form->toggle(__('Favicon SignaConnect', $d))->setName('favicon')->setHelp(__("Affiche le logo SignaConnect comme icône d'onglet du site (front, admin et page de connexion), à la place de l'icône du site WordPress.", $d)),
        '<div class="scp-admin scp-page-checks">' . FrontPages::checksHtml() . '<p class="description">'
            . esc_html__("Contrôle fait sur les pages enregistrées : après un changement de page, enregistrez d'abord.", $d) . '</p></div>',
    ))->setIcon('dashicons-admin-page'),
    $form->section(__('Facturation', $d), __('Clôture des proformas du mois précédent pour toutes les organisations. Un mois clôturé est figé (prix et quantités) ; il peut être rouvert depuis l\'écran Facturation.', $d), array(
        $form->select(__('Clôture automatique', $d))->setName('billing_auto_close_day')->setOptions($close_days),
    ))->setIcon('dashicons-money-alt'),
    $form->section(__('Universign', $d), __("Niveau Universign demandé pour le palier « Qualifiée ». Il doit être activé sur le compte Universign de l'organisation, sinon l'envoi est refusé (erreur 403 « feature unavailable »).", $d), array(
        $form->select(__('Palier « Qualifiée »', $d))->setName('universign_qualified_level')->setOptions(array(
            __('level4 : signature qualifiée eIDAS (QCP-n-qscd, identité PVID)', $d) => 'level4',
            __('level3 : signature avancée avec certificat qualifié (QCP-n, identité PVID)', $d) => 'level3',
            __('level2 : signature avancée avec certificat LCP (vérification standard)', $d) => 'level2',
        )),
        $form->toggle(__('Faire dessiner la signature', $d))->setName('universign_handwritten')->setHelp(__("Sur la page Universign, le signataire dessine sa signature à la main, qui s'affiche dans le champ du PDF. Option non documentée par Universign pour l'API : si elle est refusée, l'envoi part quand même sans elle (tracé dans le journal).", $d)),
    ))->setIcon('dashicons-shield'),
    View::note(
        __('Emails', $d),
        '<p>' . esc_html__("Les emails partent par wp_mail(). Pour choisir l'expéditeur ou passer par un SMTP, utilisez un plugin dédié (ex. WP Mail SMTP).", $d) . '</p>',
        'dashicons-email'
    ),
    View::note(
        __('Clés API', $d),
        '<p>' . esc_html__('Les clés Universign, OpenAI et Twilio se règlent dans chaque organisation. Il n\'y a volontairement aucune clé par défaut.', $d) . '</p>',
        'dashicons-lock'
    ),
);

$html = '<div class="form_container">' . $form->save(__('Enregistrer', $d))->setFields($fields) . '</div>';

View::page($html, isset($page_header_html) ? $page_header_html : '', isset($notices_html) ? $notices_html : '', __('Réglages SignaConnect', $d));
