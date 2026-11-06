<?php

namespace Smbb\Tomo\Support;

defined('ABSPATH') || exit;

/**
 * Rôles et capacités.
 *
 * - manage_tomo     : tout le back-office Tomo (administrateurs et « Gestionnaire Tomo ») ;
 * - tomo_intervenant : l'espace « Mes ateliers » d'un intervenant.
 */
final class Roles
{
    const CAP_MANAGE = 'manage_tomo';
    const CAP_INTERVENANT = 'tomo_intervenant';
    const ROLE_MANAGER = 'tomo_manager';
    const ROLE_INTERVENANT = 'tomo_intervenant';
    const OPTION = 'smbb_tomo_roles';
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
        add_role(self::ROLE_MANAGER, __('Gestionnaire Tomo', 'smbb-tomo'), array(
            'read' => true,
            'upload_files' => true,
            self::CAP_MANAGE => true,
        ));

        add_role(self::ROLE_INTERVENANT, __('Intervenant Tomo', 'smbb-tomo'), array(
            'read' => true,
            self::CAP_INTERVENANT => true,
        ));

        $admin = get_role('administrator');

        if ($admin) {
            $admin->add_cap(self::CAP_MANAGE);
            $admin->add_cap(self::CAP_INTERVENANT);
        }

        $manager = get_role(self::ROLE_MANAGER);

        if ($manager) {
            $manager->add_cap(self::CAP_INTERVENANT);
        }

        update_option(self::OPTION, self::VERSION, false);
    }

    /**
     * Adresse de l'espace intervenant (agenda et écran d'appel).
     */
    public static function spaceUrl(array $args = array())
    {
        return add_query_arg($args, admin_url('admin.php?page=smbb-codetool-tomo_espace'));
    }

    public static function canManage()
    {
        return current_user_can(self::CAP_MANAGE);
    }

    /**
     * Vrai pour un intervenant qui n'a pas aussi la gestion de Tomo.
     */
    public static function isIntervenantOnly(\WP_User $user)
    {
        return in_array(self::ROLE_INTERVENANT, (array) $user->roles, true) && !$user->has_cap(self::CAP_MANAGE);
    }
}
