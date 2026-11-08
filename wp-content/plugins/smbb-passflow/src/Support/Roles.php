<?php

namespace Smbb\Passflow\Support;

defined('ABSPATH') || exit;

/**
 * Rôles et capacités.
 *
 * - manage_passflow     : tout le back-office Passflow (administrateurs et « Gestionnaire Passflow ») ;
 * - passflow_host : l'espace « Mes ateliers » d'un intervenant.
 */
final class Roles
{
    const CAP_MANAGE = 'manage_passflow';
    const CAP_HOST = 'passflow_host';
    const ROLE_MANAGER = 'passflow_manager';
    const ROLE_HOST = 'passflow_host';
    const OPTION = 'smbb_passflow_roles';
    const VERSION = '1';

    public static function hooks()
    {
        add_action('admin_init', array(__CLASS__, 'ensure'));
    }

    public static function ensure()
    {
        if (get_option(self::OPTION) !== self::VERSION) {
            self::install();
        }
    }

    public static function install()
    {
        add_role(self::ROLE_MANAGER, __('Gestionnaire Passflow', 'smbb-passflow'), array(
            'read' => true,
            'upload_files' => true,
            self::CAP_MANAGE => true,
        ));

        add_role(self::ROLE_HOST, __('Intervenant Passflow', 'smbb-passflow'), array(
            'read' => true,
            self::CAP_HOST => true,
        ));

        $admin = get_role('administrator');

        if ($admin) {
            $admin->add_cap(self::CAP_MANAGE);
            $admin->add_cap(self::CAP_HOST);
        }

        $manager = get_role(self::ROLE_MANAGER);

        if ($manager) {
            $manager->add_cap(self::CAP_HOST);
        }

        update_option(self::OPTION, self::VERSION, false);
    }

    /**
     * Adresse de l'espace intervenant (agenda et écran d'appel).
     */
    public static function spaceUrl(array $args = array())
    {
        return add_query_arg($args, admin_url('admin.php?page=smbb-codetool-passflow_portal'));
    }

    public static function canManage()
    {
        return current_user_can(self::CAP_MANAGE);
    }

    /**
     * Vrai pour un intervenant qui n'a pas aussi la gestion de Passflow.
     */
    public static function isHostOnly(\WP_User $user)
    {
        return in_array(self::ROLE_HOST, (array) $user->roles, true) && !$user->has_cap(self::CAP_MANAGE);
    }
}
