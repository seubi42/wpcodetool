<?php

namespace Smbb\WpCodeTool\Roles;

defined('ABSPATH') || exit;

/**
 * Catalogue des droits (capabilities) connus du site, rangés par groupe avec un libellé lisible.
 *
 * Sources, comme User Role Editor : droits natifs de WordPress, droits déjà donnés à un rôle,
 * droits des types de contenu et taxonomies enregistrés, droits ajoutés à la main (option
 * smbb_codetool_custom_caps). Filtre smbb_wpcodetool_capability_groups pour ranger les droits
 * d'un plugin dans son propre groupe.
 */
final class Capabilities
{
    const CUSTOM_OPTION = 'smbb_codetool_custom_caps';

    /**
     * Droits natifs : clé => [groupe, libellé].
     *
     * @return array<string,array{0:string,1:string}>
     */
    public static function core()
    {
        $caps = array(
            // Général
            'read' => array('general', __('Accéder à l\'admin (tableau de bord, profil)', 'smbb-wpcodetool')),
            'manage_options' => array('general', __('Gérer les réglages du site', 'smbb-wpcodetool')),
            'upload_files' => array('general', __('Envoyer des fichiers dans la médiathèque', 'smbb-wpcodetool')),
            'unfiltered_upload' => array('general', __('Envoyer n\'importe quel type de fichier', 'smbb-wpcodetool')),
            'unfiltered_html' => array('general', __('Publier du HTML et du JavaScript sans filtre', 'smbb-wpcodetool')),
            'import' => array('general', __('Importer du contenu', 'smbb-wpcodetool')),
            'export' => array('general', __('Exporter du contenu', 'smbb-wpcodetool')),
            'edit_dashboard' => array('general', __('Personnaliser le tableau de bord', 'smbb-wpcodetool')),
            'update_core' => array('general', __('Mettre à jour WordPress', 'smbb-wpcodetool')),
            'install_languages' => array('general', __('Installer des langues', 'smbb-wpcodetool')),
            'view_site_health_checks' => array('general', __('Voir la santé du site', 'smbb-wpcodetool')),
            'manage_links' => array('general', __('Gérer les liens (ancien module)', 'smbb-wpcodetool')),
            'manage_privacy_options' => array('general', __('Gérer la confidentialité', 'smbb-wpcodetool')),
            // Articles
            'edit_posts' => array('posts', __('Écrire des articles', 'smbb-wpcodetool')),
            'edit_others_posts' => array('posts', __('Modifier les articles des autres', 'smbb-wpcodetool')),
            'edit_published_posts' => array('posts', __('Modifier les articles publiés', 'smbb-wpcodetool')),
            'edit_private_posts' => array('posts', __('Modifier les articles privés', 'smbb-wpcodetool')),
            'publish_posts' => array('posts', __('Publier des articles', 'smbb-wpcodetool')),
            'read_private_posts' => array('posts', __('Lire les articles privés', 'smbb-wpcodetool')),
            'delete_posts' => array('posts', __('Supprimer ses articles', 'smbb-wpcodetool')),
            'delete_others_posts' => array('posts', __('Supprimer les articles des autres', 'smbb-wpcodetool')),
            'delete_published_posts' => array('posts', __('Supprimer les articles publiés', 'smbb-wpcodetool')),
            'delete_private_posts' => array('posts', __('Supprimer les articles privés', 'smbb-wpcodetool')),
            'manage_categories' => array('posts', __('Gérer les catégories', 'smbb-wpcodetool')),
            'moderate_comments' => array('posts', __('Modérer les commentaires', 'smbb-wpcodetool')),
            // Pages
            'edit_pages' => array('pages', __('Écrire des pages', 'smbb-wpcodetool')),
            'edit_others_pages' => array('pages', __('Modifier les pages des autres', 'smbb-wpcodetool')),
            'edit_published_pages' => array('pages', __('Modifier les pages publiées', 'smbb-wpcodetool')),
            'edit_private_pages' => array('pages', __('Modifier les pages privées', 'smbb-wpcodetool')),
            'publish_pages' => array('pages', __('Publier des pages', 'smbb-wpcodetool')),
            'read_private_pages' => array('pages', __('Lire les pages privées', 'smbb-wpcodetool')),
            'delete_pages' => array('pages', __('Supprimer ses pages', 'smbb-wpcodetool')),
            'delete_others_pages' => array('pages', __('Supprimer les pages des autres', 'smbb-wpcodetool')),
            'delete_published_pages' => array('pages', __('Supprimer les pages publiées', 'smbb-wpcodetool')),
            'delete_private_pages' => array('pages', __('Supprimer les pages privées', 'smbb-wpcodetool')),
            // Apparence
            'switch_themes' => array('themes', __('Changer de thème', 'smbb-wpcodetool')),
            'edit_theme_options' => array('themes', __('Menus, widgets, personnaliser', 'smbb-wpcodetool')),
            'install_themes' => array('themes', __('Installer des thèmes', 'smbb-wpcodetool')),
            'update_themes' => array('themes', __('Mettre à jour les thèmes', 'smbb-wpcodetool')),
            'delete_themes' => array('themes', __('Supprimer des thèmes', 'smbb-wpcodetool')),
            'edit_themes' => array('themes', __('Modifier le code des thèmes', 'smbb-wpcodetool')),
            'resume_themes' => array('themes', __('Relancer un thème en erreur', 'smbb-wpcodetool')),
            // Extensions
            'activate_plugins' => array('plugins', __('Activer et désactiver les extensions', 'smbb-wpcodetool')),
            'install_plugins' => array('plugins', __('Installer des extensions', 'smbb-wpcodetool')),
            'update_plugins' => array('plugins', __('Mettre à jour les extensions', 'smbb-wpcodetool')),
            'delete_plugins' => array('plugins', __('Supprimer des extensions', 'smbb-wpcodetool')),
            'edit_plugins' => array('plugins', __('Modifier le code des extensions', 'smbb-wpcodetool')),
            'resume_plugins' => array('plugins', __('Relancer une extension en erreur', 'smbb-wpcodetool')),
            // Comptes
            'list_users' => array('users', __('Voir la liste des comptes', 'smbb-wpcodetool')),
            'create_users' => array('users', __('Créer des comptes', 'smbb-wpcodetool')),
            'edit_users' => array('users', __('Modifier les comptes', 'smbb-wpcodetool')),
            'promote_users' => array('users', __('Changer le rôle des comptes', 'smbb-wpcodetool')),
            'remove_users' => array('users', __('Retirer des comptes du site', 'smbb-wpcodetool')),
            'delete_users' => array('users', __('Supprimer des comptes', 'smbb-wpcodetool')),
            'add_users' => array('users', __('Ajouter des comptes (ancien droit)', 'smbb-wpcodetool')),
            // Anciens
            'edit_files' => array('deprecated', __('Modifier les fichiers (ancien droit)', 'smbb-wpcodetool')),
        );

        for ($i = 0; $i <= 10; $i++) {
            $caps['level_' . $i] = array('deprecated', sprintf(__('Niveau %d (WordPress 1.x, gardé pour compatibilité)', 'smbb-wpcodetool'), $i));
        }

        if (is_multisite()) {
            foreach (array(
                'create_sites' => __('Créer des sites', 'smbb-wpcodetool'),
                'delete_sites' => __('Supprimer des sites', 'smbb-wpcodetool'),
                'manage_network' => __('Gérer le réseau', 'smbb-wpcodetool'),
                'manage_sites' => __('Gérer les sites', 'smbb-wpcodetool'),
                'manage_network_users' => __('Gérer les comptes du réseau', 'smbb-wpcodetool'),
                'manage_network_plugins' => __('Gérer les extensions du réseau', 'smbb-wpcodetool'),
                'manage_network_themes' => __('Gérer les thèmes du réseau', 'smbb-wpcodetool'),
                'manage_network_options' => __('Gérer les réglages du réseau', 'smbb-wpcodetool'),
                'upgrade_network' => __('Mettre à jour le réseau', 'smbb-wpcodetool'),
            ) as $cap => $label) {
                $caps[$cap] = array('multisite', $label);
            }
        }

        return $caps;
    }

    /**
     * Groupes dans l'ordre d'affichage : clé => [libellé, dashicon].
     */
    public static function groupLabels()
    {
        return array(
            'general' => array(__('Général', 'smbb-wpcodetool'), 'dashicons-admin-generic'),
            'posts' => array(__('Articles et commentaires', 'smbb-wpcodetool'), 'dashicons-admin-post'),
            'pages' => array(__('Pages', 'smbb-wpcodetool'), 'dashicons-admin-page'),
            'media' => array(__('Médias', 'smbb-wpcodetool'), 'dashicons-admin-media'),
            'themes' => array(__('Apparence', 'smbb-wpcodetool'), 'dashicons-admin-appearance'),
            'plugins' => array(__('Extensions', 'smbb-wpcodetool'), 'dashicons-admin-plugins'),
            'users' => array(__('Comptes', 'smbb-wpcodetool'), 'dashicons-admin-users'),
            'multisite' => array(__('Réseau', 'smbb-wpcodetool'), 'dashicons-networking'),
            'woocommerce' => array(__('WooCommerce', 'smbb-wpcodetool'), 'dashicons-cart'),
            'codetool' => array(__('CodeTool et ses extensions', 'smbb-wpcodetool'), 'dashicons-editor-code'),
            'custom' => array(__('Autres droits', 'smbb-wpcodetool'), 'dashicons-admin-network'),
            'deprecated' => array(__('Anciens droits', 'smbb-wpcodetool'), 'dashicons-backup'),
        );
    }

    /**
     * Catalogue complet : cap => [group, label, core(bool)]. Les groupes de types de contenu
     * sont ajoutés à la volée (clé « type_<post_type> »).
     *
     * @return array{caps:array<string,array{group:string,label:string,core:bool}>,groups:array<string,array{0:string,1:string}>}
     */
    public static function catalogue()
    {
        $groups = self::groupLabels();
        $caps = array();

        foreach (self::core() as $cap => $def) {
            $caps[$cap] = array('group' => $def[0], 'label' => $def[1], 'core' => true);
        }

        // Types de contenu avec leurs propres droits (produits, ateliers…).
        $verbs = array(
            'edit_posts' => __('Écrire', 'smbb-wpcodetool'),
            'edit_others_posts' => __('Modifier ceux des autres', 'smbb-wpcodetool'),
            'edit_published_posts' => __('Modifier les publiés', 'smbb-wpcodetool'),
            'edit_private_posts' => __('Modifier les privés', 'smbb-wpcodetool'),
            'publish_posts' => __('Publier', 'smbb-wpcodetool'),
            'read_private_posts' => __('Lire les privés', 'smbb-wpcodetool'),
            'delete_posts' => __('Supprimer les siens', 'smbb-wpcodetool'),
            'delete_others_posts' => __('Supprimer ceux des autres', 'smbb-wpcodetool'),
            'delete_published_posts' => __('Supprimer les publiés', 'smbb-wpcodetool'),
            'delete_private_posts' => __('Supprimer les privés', 'smbb-wpcodetool'),
            'create_posts' => __('Créer', 'smbb-wpcodetool'),
        );

        foreach (get_post_types(array(), 'objects') as $type) {
            if (in_array($type->name, array('post', 'page'), true) || empty($type->cap)) {
                continue;
            }

            $group = 'type_' . $type->name;
            $label = isset($type->labels->name) ? $type->labels->name : $type->name;
            $added = false;

            foreach ($verbs as $generic => $verb) {
                if (empty($type->cap->$generic)) {
                    continue;
                }

                $cap = $type->cap->$generic;

                if (isset($caps[$cap])) {
                    continue; // Type qui réutilise les droits des articles : déjà rangé.
                }

                $caps[$cap] = array('group' => $group, 'label' => $verb . ' — ' . $label, 'core' => false);
                $added = true;
            }

            if ($added) {
                $groups[$group] = array($label, !empty($type->menu_icon) && strpos((string) $type->menu_icon, 'dashicons-') === 0 ? $type->menu_icon : 'dashicons-admin-post');
            }
        }

        // Taxonomies avec leurs propres droits.
        foreach (get_taxonomies(array(), 'objects') as $tax) {
            if (empty($tax->cap)) {
                continue;
            }

            foreach (array(
                'manage_terms' => __('Gérer', 'smbb-wpcodetool'),
                'edit_terms' => __('Modifier', 'smbb-wpcodetool'),
                'delete_terms' => __('Supprimer', 'smbb-wpcodetool'),
                'assign_terms' => __('Attribuer', 'smbb-wpcodetool'),
            ) as $generic => $verb) {
                $cap = isset($tax->cap->$generic) ? $tax->cap->$generic : '';

                if ($cap === '' || isset($caps[$cap])) {
                    continue;
                }

                $object_types = (array) $tax->object_type;
                $group = 'custom';

                foreach ($object_types as $object_type) {
                    if (isset($groups['type_' . $object_type])) {
                        $group = 'type_' . $object_type;
                        break;
                    }
                }

                $label = isset($tax->labels->name) ? $tax->labels->name : $tax->name;
                $caps[$cap] = array('group' => $group, 'label' => $verb . ' — ' . $label, 'core' => false);
            }
        }

        // Tous les droits déjà donnés à un rôle, plus ceux ajoutés à la main.
        $extra = array();

        foreach (wp_roles()->roles as $role) {
            foreach (array_keys((array) $role['capabilities']) as $cap) {
                $extra[(string) $cap] = true;
            }
        }

        foreach (self::customCaps() as $cap) {
            $extra[$cap] = true;
        }

        foreach (array_keys($extra) as $cap) {
            if (isset($caps[$cap]) || $cap === '') {
                continue;
            }

            $caps[$cap] = array('group' => self::guessGroup($cap), 'label' => self::humanize($cap), 'core' => false);
        }

        $catalogue = apply_filters('smbb_wpcodetool_capability_groups', array('caps' => $caps, 'groups' => $groups));

        // Les groupes déclarés mais vides ne s'affichent pas ; un groupe inconnu tombe dans « Autres ».
        $used = array();

        foreach ($catalogue['caps'] as $cap => $def) {
            if (!isset($catalogue['groups'][$def['group']])) {
                $catalogue['caps'][$cap]['group'] = 'custom';
            }

            $used[$catalogue['caps'][$cap]['group']] = true;
        }

        foreach (array_keys($catalogue['groups']) as $group) {
            if (!isset($used[$group])) {
                unset($catalogue['groups'][$group]);
            }
        }

        // Types de contenu juste avant WooCommerce / Autres.
        $ordered = array();

        foreach ($catalogue['groups'] as $key => $def) {
            if (in_array($key, array('woocommerce', 'codetool', 'custom', 'deprecated'), true)) {
                continue;
            }

            $ordered[$key] = $def;
        }

        foreach (array('woocommerce', 'codetool', 'custom', 'deprecated') as $key) {
            if (isset($catalogue['groups'][$key])) {
                $ordered[$key] = $catalogue['groups'][$key];
            }
        }

        $catalogue['groups'] = $ordered;

        return $catalogue;
    }

    /**
     * Droits ajoutés à la main depuis l'écran des rôles (gardés même quand aucun rôle ne les a).
     *
     * @return string[]
     */
    public static function customCaps()
    {
        $caps = get_option(self::CUSTOM_OPTION, array());

        return is_array($caps) ? array_values(array_filter(array_map('strval', $caps))) : array();
    }

    public static function addCustomCap($cap)
    {
        $caps = self::customCaps();

        if (!in_array($cap, $caps, true)) {
            $caps[] = $cap;
            update_option(self::CUSTOM_OPTION, $caps, false);
        }
    }

    public static function removeCustomCap($cap)
    {
        update_option(self::CUSTOM_OPTION, array_values(array_diff(self::customCaps(), array($cap))), false);
    }

    /**
     * Nom de droit valide : lettres, chiffres, _ et - (comme User Role Editor), 2 à 100 caractères.
     */
    public static function validName($cap)
    {
        return is_string($cap) && preg_match('/^[a-z0-9_\-]{2,100}$/', $cap) && !preg_match('/^\d+$/', $cap);
    }

    private static function guessGroup($cap)
    {
        if (preg_match('/(woocommerce|shop_|product|_order|coupon|wc_)/', $cap)) {
            return 'woocommerce';
        }

        if (preg_match('/^(smbb|codetool|passflow|tomo|scp_)/', $cap)) {
            return 'codetool';
        }

        return 'custom';
    }

    private static function humanize($cap)
    {
        $text = str_replace(array('_', '-'), ' ', $cap);

        return function_exists('mb_strtoupper')
            ? mb_strtoupper(mb_substr($text, 0, 1)) . mb_substr($text, 1)
            : ucfirst($text);
    }
}
