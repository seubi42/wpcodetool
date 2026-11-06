<?php

namespace Smbb\Tomo\Admin;

use Smbb\Tomo\Repository\Lookup;
use Smbb\Tomo\Support\Roles;
use Smbb\Tomo\Support\Schema;

defined('ABSPATH') || exit;

/**
 * Menu d'administration : « Mes ateliers » (espace intervenant) n'est affiché qu'aux comptes
 * rattachés à une fiche intervenant. Un gestionnaire qui n'anime pas garde l'accès à l'appel
 * et à la caisse depuis le tableau de bord et la barre du haut ; la page reste ouverte par URL,
 * et le menu Tomo est alors surligné.
 *
 * Les groupes du menu (Au quotidien, Suivi, Paramétrage) sont déclarés dans les modèles JSON
 * (admin.menu.group) et dessinés par CodeTool.
 */
final class Menu
{
    const SPACE = 'smbb-codetool-tomo_espace';
    const MAIN = 'smbb-codetool-tomo';

    public static function hooks()
    {
        add_action('admin_menu', array(__CLASS__, 'hideSpace'), 1000);
        add_filter('parent_file', array(__CLASS__, 'parentFile'), 20);
    }

    /**
     * Gestionnaire sans fiche intervenant : pas d'espace intervenant dans le menu.
     */
    public static function hidesSpace()
    {
        if (!Roles::canManage()) {
            return false;
        }

        if (!Schema::ready()) {
            return true;
        }

        return !Lookup::intervenantForUser(get_current_user_id());
    }

    public static function hideSpace()
    {
        if (self::hidesSpace()) {
            remove_menu_page(self::SPACE);
        }
    }

    public static function parentFile($parent_file)
    {
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification

        return $page === self::SPACE && self::hidesSpace() ? self::MAIN : $parent_file;
    }
}
