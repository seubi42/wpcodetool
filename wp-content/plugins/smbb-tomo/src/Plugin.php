<?php

namespace Smbb\Tomo;

defined('ABSPATH') || exit;

/**
 * Point d'entrée du plugin.
 *
 * Les écrans CRUD (ateliers, créneaux, intervenants, inscriptions, liste d'attente, réglages)
 * sont générés par CodeTool à partir de codetool/models. Ce plugin y ajoute :
 * - les rôles « Gestionnaire Tomo » et « Intervenant Tomo » ;
 * - la création automatique des tables ;
 * - les actions métier (annuler, déplacer, pointer, inscrire depuis la liste d'attente, export CSV) ;
 * - les e-mails et SMS (événements, rappels planifiés, liste d'attente) ;
 * - la vente des places avec WooCommerce (fiche produit, panier, commandes, Mon compte) ;
 * - les styles des écrans.
 */
final class Plugin
{
    public function hooks()
    {
        Support\Vocabulary::hooks();
        Support\Theme::hooks();
        Support\Roles::hooks();
        Support\LoginLink::hooks();
        Support\Schema::hooks();
        Admin\Actions::hooks();
        Admin\ConfigTransfer::hooks();
        Admin\RollCall::hooks();
        Admin\Caisse::hooks();
        Admin\TodayBar::hooks();
        Admin\Menu::hooks();
        Service\Availability::hooks();
        Service\Campaigns::hooks();
        Service\Notifier::hooks();
        Service\Reminders::hooks();
        Service\Tickets::hooks();
        Service\WalletSync::hooks();
        Service\CertificateAlerts::hooks();
        Service\Accounts::hooks();
        Service\Documents::hooks();
        Front\Shortcodes::hooks();
        WooCommerce\Module::hooks();
        add_action('admin_enqueue_scripts', array($this, 'adminAssets'));
        add_filter('login_redirect', array($this, 'loginRedirect'), 10, 3);

        // WooCommerce renvoie vers Mon compte tout compte sans edit_posts : les intervenants et les
        // gestionnaires Tomo doivent pouvoir entrer dans l'administration.
        add_filter('woocommerce_prevent_admin_access', array($this, 'allowAdmin'));
        add_filter('woocommerce_disable_admin_bar', array($this, 'allowAdmin'));
        add_filter('woocommerce_login_redirect', array($this, 'wooLoginRedirect'), 10, 2);
    }

    /**
     * @param bool $prevent
     */
    public function allowAdmin($prevent)
    {
        return current_user_can(Support\Roles::CAP_INTERVENANT) ? false : $prevent;
    }

    /**
     * Connexion depuis le formulaire de Mon compte.
     *
     * @param string   $redirect
     * @param \WP_User $user
     */
    public function wooLoginRedirect($redirect, $user)
    {
        return $this->loginRedirect($redirect, '', $user);
    }

    public function adminAssets($hook_suffix)
    {
        if (strpos((string) $hook_suffix, 'smbb-codetool-tomo') === false) {
            return;
        }

        wp_enqueue_style('smbb-tomo-admin', SMBB_TOMO_URL . 'assets/admin.css', array(), SMBB_TOMO_VERSION);
    }

    /**
     * Un intervenant arrive directement sur « Mes ateliers ».
     *
     * @param string           $redirect_to
     * @param string           $requested
     * @param \WP_User|\WP_Error $user
     */
    public function loginRedirect($redirect_to, $requested, $user)
    {
        if ($user instanceof \WP_User && Support\Roles::isIntervenantOnly($user)) {
            // Lien reçu par e-mail ou SMS (demande de dispo, appel d'une date…) : on garde la page demandée.
            foreach (array((string) $requested, (string) $redirect_to) as $wanted) {
                if ($wanted !== '' && strpos($wanted, 'page=smbb-codetool-tomo_espace') !== false) {
                    return $wanted;
                }
            }

            return Support\Roles::spaceUrl();
        }

        return $redirect_to;
    }
}
