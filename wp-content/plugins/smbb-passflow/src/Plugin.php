<?php

namespace Smbb\Passflow;

defined('ABSPATH') || exit;

/**
 * Point d'entrée du plugin.
 *
 * Les écrans CRUD (ateliers, créneaux, intervenants, inscriptions, liste d'attente, réglages)
 * sont générés par CodeTool à partir de codetool/models. Ce plugin y ajoute :
 * - les rôles « Gestionnaire Passflow » et « Intervenant Passflow » ;
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
        Admin\Onboarding::hooks();
        Admin\RollCall::hooks();
        Admin\DayBoard::hooks();
        Admin\Cashdesk::hooks();
        Admin\TodayBar::hooks();
        Admin\Menu::hooks();
        Admin\DashboardWidgets::hooks();
        Admin\PaletteSearch::hooks();
        Service\Availability::hooks();
        Service\Campaigns::hooks();
        Service\Broadcasts::hooks();
        Service\Reviews::hooks();
        Service\Recap::hooks();
        Service\Notifier::hooks();
        Service\EmailBrand::hooks();
        Service\Reminders::hooks();
        Service\Tickets::hooks();
        Service\Transfers::hooks();
        Service\WalletSync::hooks();
        Service\CertificateAlerts::hooks();
        Service\Accounts::hooks();
        Service\Documents::hooks();
        Front\Shortcodes::hooks();
        WooCommerce\Module::hooks();
        add_action('admin_enqueue_scripts', array($this, 'adminAssets'));
        // Clés secrètes de Passflow (Twilio, Apple et Google Wallet) listées dans CodeTool › Chiffrement :
        // chiffrer / déchiffrer / rechiffrer après un changement de clé. Même liste que l'export (ConfigTransfer::SECRETS).
        add_filter('smbb_wpcodetool_option_secrets', static function ($secrets) {
            $names = array(
                'twilio_token' => __('Jeton Twilio', 'smbb-passflow'),
                'apple_private_key' => __('Apple Wallet : clé privée', 'smbb-passflow'),
                'apple_key_password' => __('Apple Wallet : mot de passe de la clé', 'smbb-passflow'),
                'google_service_account' => __('Google Wallet : compte de service (JSON)', 'smbb-passflow'),
            );

            foreach (Admin\ConfigTransfer::SECRETS as $key) {
                $secrets[] = array('label' => 'Passflow', 'name' => isset($names[$key]) ? $names[$key] : $key, 'option' => Support\Settings::OPTION, 'path' => array($key));
            }

            return $secrets;
        });

        add_filter('login_redirect', array($this, 'loginRedirect'), 10, 3);

        // WooCommerce renvoie vers Mon compte tout compte sans edit_posts : les intervenants et les
        // gestionnaires Passflow doivent pouvoir entrer dans l'administration.
        add_filter('woocommerce_prevent_admin_access', array($this, 'allowAdmin'));
        add_filter('woocommerce_disable_admin_bar', array($this, 'allowAdmin'));
        add_filter('woocommerce_login_redirect', array($this, 'wooLoginRedirect'), 10, 2);
    }

    /**
     * @param bool $prevent
     */
    public function allowAdmin($prevent)
    {
        return current_user_can(Support\Roles::CAP_HOST) ? false : $prevent;
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
        $wizard = isset($_GET['page'], $_GET['wizard']) && $_GET['page'] === 'smbb-wpcodetool-wizard' && $_GET['wizard'] === Service\Onboarding::WIZARD;

        if (strpos((string) $hook_suffix, 'smbb-codetool-passflow') === false && !$wizard) {
            return;
        }

        wp_enqueue_style('smbb-passflow-admin', SMBB_PASSFLOW_URL . 'assets/admin.css', array(), SMBB_PASSFLOW_VERSION . '.' . (int) @filemtime(SMBB_PASSFLOW_PATH . 'assets/admin.css'));
        // Fiche Journée vivante (jauge + compte à rebours, Admin\SlotPulse).
        wp_enqueue_style('smbb-passflow-slot-pulse', SMBB_PASSFLOW_URL . 'assets/slot-pulse.css', array('smbb-passflow-admin'), SMBB_PASSFLOW_VERSION . '.' . (int) @filemtime(SMBB_PASSFLOW_PATH . 'assets/slot-pulse.css'));

        // Peau CodeTool (thème Aubergine…) : pont pour les couleurs propres à Passflow.
        if (Support\Theme::skinActive()) {
            wp_enqueue_style('smbb-passflow-skin', SMBB_PASSFLOW_URL . 'assets/skin.css', array('smbb-passflow-admin'), SMBB_PASSFLOW_VERSION . '.' . (int) @filemtime(SMBB_PASSFLOW_PATH . 'assets/skin.css'));
        }
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
        if ($user instanceof \WP_User && Support\Roles::isHostOnly($user)) {
            // Lien reçu par e-mail ou SMS (demande de dispo, appel d'une date…) : on garde la page demandée.
            foreach (array((string) $requested, (string) $redirect_to) as $wanted) {
                if ($wanted !== '' && strpos($wanted, 'page=smbb-codetool-passflow_portal') !== false) {
                    return $wanted;
                }
            }

            return Support\Roles::spaceUrl();
        }

        return $redirect_to;
    }
}
