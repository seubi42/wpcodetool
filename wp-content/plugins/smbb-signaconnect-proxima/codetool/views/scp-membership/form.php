<?php
/**
 * Ligne de rattachement : un utilisateur WordPress dans une organisation.
 *
 * @var \Smbb\WpCodeTool\Admin\Form $form
 */

use Smbb\SignConnectProxima\Admin\View;
use Smbb\SignConnectProxima\Repository\Lookup;
use Smbb\SignConnectProxima\Support\Choices;

defined('ABSPATH') || exit;

$d = 'smbb-signaconnect-proxima';
$item = isset($item) && is_array($item) ? $item : array();
$is_new = empty($item['id']);

if ($is_new) {
    $identity = array(
        $form->toggle(__('Créer un nouveau compte', $d))->setName('scp_new_user')
            ->setHelp(__("Le compte WordPress n'existe pas encore : on le crée ici et la personne reçoit l'email WordPress pour choisir son mot de passe.", $d)),
        $form->select(__('Utilisateur WordPress', $d))->setName('user_id')->setOptions(Lookup::userOptions(__('— Choisir —', $d)))
            ->hideIf('scp_new_user', '1'),
        $form->row(
            $form->text(__('Email', $d))->setName('scp_new_user_email')->showIf('scp_new_user', '1'),
            $form->text(__('Prénom', $d))->setName('scp_new_user_first_name')->showIf('scp_new_user', '1'),
            $form->text(__('Nom', $d))->setName('scp_new_user_last_name')->showIf('scp_new_user', '1')
        ),
        $form->select(__('Organisation', $d))->setName('organization_id')->setOptions(Lookup::organizationOptions(__('— Choisir —', $d)))->required(),
    );
    $team_options = Lookup::teamOptions(true);
} else {
    $identity = array(
        View::note(
            __('Rattachement', $d),
            View::definitionList(array(
                __('Utilisateur', $d) => esc_html(Lookup::userLabel($item['user_id'])),
                __('Organisation', $d) => esc_html(Lookup::organizationName($item['organization_id'])),
                __('Actif depuis', $d) => View::datetime(isset($item['active_since']) ? $item['active_since'] : ''),
            )) . '<p class="description">' . esc_html__('Pour une autre organisation, créez une nouvelle ligne.', $d) . '</p>',
            'dashicons-admin-users'
        ),
    );
    $team_options = array();

    foreach (Lookup::teams() as $team_id => $team) {
        if ((int) $team['organization_id'] === (int) $item['organization_id']) {
            $team_options[(string) $team['name']] = (string) $team_id;
        }
    }
}

$fields = array(
    $form->section(__('Utilisateur et organisation', $d), __('Une seule ligne par organisation pour un même utilisateur.', $d), $identity)->setIcon('dashicons-admin-users'),
    $form->section(__('Équipes et droits', $d), '', array(
        $form->checkbox(__('Équipes', $d))->setName('team_ids')->setOptions($team_options)->required()
            ->setHelp(__("À l'envoi, l'utilisateur choisit pour quelle équipe il travaille.", $d)),
        $form->row(
            $form->select(__('Rôle', $d))->setName('role')->setOptions(Choices::options('roles')),
            $form->toggle(__('Droit Modèles', $d))->setName('can_manage_templates')
                ->setHelp(__('Peut créer et modifier les modèles de détection de l\'organisation.', $d)),
            $form->toggle(__('Actif', $d))->setName('is_active')
                ->setHelp(__('Désactivé : plus d\'accès à cette organisation. Le mois entamé reste dû.', $d))
        ),
    ))->setIcon('dashicons-groups'),
    $form->section(__('Forfait', $d), __('Facturé à cette organisation. Un changement est immédiat et s\'applique à tout le mois en cours.', $d), array(
        $form->select(__('Forfait', $d))->setName('plan_id')->setOptions(Lookup::planOptions(__("— Forfait par défaut de l'organisation —", $d), true)),
    ))->setIcon('dashicons-tag'),
);

$html = '<div class="form_container">' . $form->save($is_new ? __('Rattacher', $d) : __('Enregistrer', $d))->setFields($fields) . '</div>';

View::page($html, isset($page_header_html) ? $page_header_html : '', isset($notices_html) ? $notices_html : '', __('Rattachement', $d));
