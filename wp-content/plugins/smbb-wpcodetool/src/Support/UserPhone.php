<?php

namespace Smbb\WpCodeTool\Support;

defined('ABSPATH') || exit;

/**
 * Champ « Téléphone » sur la fiche utilisateur WordPress quand WooCommerce n'est pas là.
 *
 * Avec WooCommerce, le numéro vit dans la méta billing_phone (bloc « Adresse de facturation »).
 * Sans WooCommerce, ce champ n'existe nulle part : la double authentification par SMS,
 * la recherche de comptes (UserLookup) et les plugins n'avaient donc aucun numéro.
 *
 * On ajoute le champ dans « Coordonnées » (profil et édition d'un utilisateur) et on l'enregistre
 * dans la MÊME méta billing_phone : le jour où WooCommerce est activé, le numéro est déjà à sa place,
 * sans doublon. UserLookup::phone() la lit en premier.
 *
 * Coupé par add_filter('smbb_wpcodetool_user_phone_field', '__return_false');
 */
class UserPhone
{
    const META = 'billing_phone';

    public static function hooks()
    {
        add_filter('user_contactmethods', array(__CLASS__, 'contactMethod'), 20);
    }

    public static function enabled()
    {
        // WooCommerce affiche déjà billing_phone : on ne crée pas de deuxième champ.
        return !class_exists('WooCommerce') && (bool) apply_filters('smbb_wpcodetool_user_phone_field', true);
    }

    /**
     * WordPress affiche, nettoie (sanitize_text_field) et enregistre lui-même les champs de « Coordonnées »
     * dans la méta du même nom.
     */
    public static function contactMethod($methods)
    {
        if (self::enabled()) {
            $methods[self::META] = __('Téléphone', 'smbb-wpcodetool');
        }

        return $methods;
    }
}
