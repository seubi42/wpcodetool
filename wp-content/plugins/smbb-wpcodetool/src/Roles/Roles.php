<?php

namespace Smbb\WpCodeTool\Roles;

use WP_Error;
use WP_User;

defined('ABSPATH') || exit;

/**
 * Gestion des rôles WordPress : créer, dupliquer, renommer (nom et identifiant), supprimer,
 * cocher les droits, comparer. Toutes les écritures passent par ici, avec les garde-fous :
 *
 * - identifiant : lettres minuscules, chiffres, _ et -, jamais tout en chiffres (repris de URE) ;
 * - le rôle administrator ne se supprime pas, ne change pas d'identifiant et garde ses droits vitaux ;
 * - le rôle par défaut des nouveaux comptes et les rôles de la personne connectée ne se suppriment pas ;
 * - un rôle encore porté par des comptes ne se supprime qu'en les basculant vers un autre rôle ;
 * - on ne peut pas se retirer à soi-même l'accès à cet écran (manage_options) ;
 * - on ne donne pas un droit qu'on n'a pas soi-même (sauf administrateur / super admin) ;
 * - une personne qui n'est pas administrateur ne touche pas à ses propres rôles (repris de URE) ;
 * - chaque modification garde une photo des rôles avant (historique restaurable).
 */
final class Roles
{
    const HISTORY_OPTION = 'smbb_codetool_roles_history';
    const ORIGIN_OPTION = 'smbb_codetool_roles_origin';
    const HISTORY_MAX = 20;
    const BUILTIN = array('administrator', 'editor', 'author', 'contributor', 'subscriber');

    /** Droits que le rôle administrator garde toujours. */
    const ADMIN_LOCKED = array('read', 'manage_options', 'activate_plugins', 'list_users', 'edit_users', 'create_users', 'promote_users', 'delete_users');

    private static $counts = null;

    /**
     * Droit WordPress pour ouvrir l'écran des rôles.
     */
    public static function capability()
    {
        return (string) apply_filters('smbb_wpcodetool_roles_capability', 'manage_options');
    }

    public static function allowed()
    {
        return current_user_can(self::capability()) && current_user_can('promote_users');
    }

    /**
     * Les rôles viennent de la base (et pas d'un $wp_user_roles figé dans wp-config).
     */
    public static function writable()
    {
        return (bool) wp_roles()->use_db;
    }

    /**
     * Rôles du site : id => [name, caps, users].
     *
     * @return array<string,array{name:string,caps:array<string,bool>,users:int}>
     */
    public static function all()
    {
        $counts = self::userCounts();
        $out = array();

        foreach (wp_roles()->roles as $id => $role) {
            $out[$id] = array(
                'name' => translate_user_role($role['name']),
                'raw_name' => $role['name'],
                'caps' => (array) $role['capabilities'],
                'users' => isset($counts[$id]) ? (int) $counts[$id] : 0,
            );
        }

        return $out;
    }

    public static function get($id)
    {
        $all = self::all();

        return isset($all[$id]) ? $all[$id] : null;
    }

    public static function userCounts()
    {
        if (self::$counts === null) {
            $counts = count_users();
            self::$counts = isset($counts['avail_roles']) ? (array) $counts['avail_roles'] : array();
        }

        return self::$counts;
    }

    public static function isBuiltin($id)
    {
        return in_array($id, self::BUILTIN, true);
    }

    public static function isDefault($id)
    {
        return get_option('default_role') === $id;
    }

    public static function isMine($id)
    {
        return in_array($id, (array) wp_get_current_user()->roles, true);
    }

    /**
     * Personne qui peut tout faire ici : super admin ou rôle administrator.
     */
    public static function fullAdmin()
    {
        return is_super_admin() || in_array('administrator', (array) wp_get_current_user()->roles, true);
    }

    public static function canGrant($cap)
    {
        return self::fullAdmin() || current_user_can($cap);
    }

    /**
     * Raison pour laquelle on ne peut pas modifier ce rôle, ou '' si on peut.
     */
    public static function editBlocker($id)
    {
        if (!self::writable()) {
            return __('Les rôles de ce site sont figés dans le code (variable $wp_user_roles) : ils ne peuvent pas être modifiés ici.', 'smbb-wpcodetool');
        }

        if (!self::fullAdmin() && self::isMine($id)) {
            return __('Vous ne pouvez pas modifier un rôle que vous avez vous-même : demandez à un administrateur.', 'smbb-wpcodetool');
        }

        return '';
    }

    /**
     * Raison pour laquelle on ne peut pas supprimer ce rôle, ou ''.
     */
    public static function deleteBlocker($id)
    {
        if ($id === 'administrator') {
            return __('Le rôle Administrateur ne se supprime pas.', 'smbb-wpcodetool');
        }

        if (self::isDefault($id)) {
            return __('C\'est le rôle donné aux nouveaux comptes : choisissez d\'abord un autre rôle par défaut.', 'smbb-wpcodetool');
        }

        if (self::isMine($id)) {
            return __('C\'est votre propre rôle : vous ne pouvez pas le supprimer.', 'smbb-wpcodetool');
        }

        return self::editBlocker($id);
    }

    /**
     * Droits qu'on ne peut pas retirer à ce rôle : cap => raison.
     *
     * @return array<string,string>
     */
    public static function lockedCaps($id)
    {
        $locked = array();

        if ($id === 'administrator') {
            foreach (self::ADMIN_LOCKED as $cap) {
                $locked[$cap] = __('Droit vital du rôle Administrateur.', 'smbb-wpcodetool');
            }
        }

        // Ne pas se couper l'accès à cet écran.
        if (self::isMine($id)) {
            foreach (array_unique(array(self::capability(), 'promote_users', 'read')) as $cap) {
                if (!self::otherRoleGrants($id, $cap)) {
                    $locked[$cap] = __('Vous perdriez l\'accès à cet écran : aucun autre de vos rôles ne donne ce droit.', 'smbb-wpcodetool');
                }
            }
        }

        return $locked;
    }

    private static function otherRoleGrants($except, $cap)
    {
        $roles = wp_roles()->roles;

        foreach ((array) wp_get_current_user()->roles as $role) {
            if ($role !== $except && !empty($roles[$role]['capabilities'][$cap])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Identifiant de rôle propre, ou WP_Error. $exists : false = doit être libre, true = doit exister.
     */
    public static function validateId($id, $exists = false)
    {
        $id = is_string($id) ? strtolower(trim($id)) : '';

        if ($id === '') {
            return new WP_Error('role_id', __('L\'identifiant du rôle est vide.', 'smbb-wpcodetool'));
        }

        if (!preg_match('/^[a-z0-9_\-]+$/', $id)) {
            return new WP_Error('role_id', __('L\'identifiant ne peut contenir que des lettres sans accent, des chiffres, des tirets et des soulignés (_).', 'smbb-wpcodetool'));
        }

        if (preg_match('/^\d+$/', $id)) {
            return new WP_Error('role_id', __('WordPress n\'accepte pas un identifiant uniquement fait de chiffres : ajoutez des lettres.', 'smbb-wpcodetool'));
        }

        if (strlen($id) > 60) {
            return new WP_Error('role_id', __('Identifiant trop long (60 caractères au plus).', 'smbb-wpcodetool'));
        }

        $taken = isset(wp_roles()->roles[$id]);

        if (!$exists && $taken) {
            return new WP_Error('role_id', sprintf(__('Le rôle « %s » existe déjà.', 'smbb-wpcodetool'), $id));
        }

        if ($exists && !$taken) {
            return new WP_Error('role_id', sprintf(__('Le rôle « %s » n\'existe pas.', 'smbb-wpcodetool'), $id));
        }

        return $id;
    }

    /**
     * Identifiant proposé à partir d'un nom (« Responsable atelier » → responsable_atelier).
     */
    public static function suggestId($name)
    {
        $id = strtolower(remove_accents((string) $name));
        $id = trim(preg_replace('/[^a-z0-9]+/', '_', $id), '_');

        if ($id === '' || preg_match('/^\d+$/', $id)) {
            $id = 'role_' . $id;
        }

        $base = substr($id, 0, 55);
        $id = $base;
        $i = 2;

        while (isset(wp_roles()->roles[$id])) {
            $id = $base . '_' . $i++;
        }

        return $id;
    }

    /**
     * Crée un rôle, vide (seulement « read ») ou copie d'un autre.
     */
    public static function create($id, $name, $from = '')
    {
        if (!self::writable()) {
            return new WP_Error('locked', self::editBlocker(''));
        }

        $name = sanitize_text_field((string) $name);
        $id = $id === '' ? self::suggestId($name) : $id;
        $id = self::validateId($id);

        if (is_wp_error($id)) {
            return $id;
        }

        if ($name === '') {
            $name = $id;
        }

        $caps = array('read' => true);

        if ($from !== '') {
            $source = wp_roles()->get_role($from);

            if (!$source) {
                return new WP_Error('role_from', __('Le rôle à copier n\'existe plus.', 'smbb-wpcodetool'));
            }

            $caps = array();
            $refused = array();

            foreach ((array) $source->capabilities as $cap => $grant) {
                if ($grant && !self::canGrant($cap)) {
                    $refused[] = $cap;
                    continue;
                }

                $caps[$cap] = (bool) $grant;
            }
        }

        self::snapshot($from !== ''
            ? sprintf(__('Avant la création de « %1$s » (copie de %2$s)', 'smbb-wpcodetool'), $name, $from)
            : sprintf(__('Avant la création de « %s »', 'smbb-wpcodetool'), $name));

        if (!add_role($id, $name, $caps)) {
            return new WP_Error('role_create', __('WordPress n\'a pas pu créer le rôle.', 'smbb-wpcodetool'));
        }

        do_action('smbb_wpcodetool_role_created', $id, $from);

        return array('id' => $id, 'refused' => isset($refused) ? $refused : array());
    }

    /**
     * Change le nom affiché et, si $new_id diffère, l'identifiant (les comptes suivent).
     *
     * @return array|WP_Error [id, users]
     */
    public static function rename($id, $name, $new_id = '')
    {
        $id = self::validateId($id, true);

        if (is_wp_error($id)) {
            return $id;
        }

        $blocker = self::editBlocker($id);

        if ($blocker !== '') {
            return new WP_Error('locked', $blocker);
        }

        $name = sanitize_text_field((string) $name);

        if ($name === '') {
            return new WP_Error('role_name', __('Le nom du rôle ne peut pas être vide.', 'smbb-wpcodetool'));
        }

        $new_id = $new_id === '' ? $id : strtolower(trim((string) $new_id));
        $wp_roles = wp_roles();
        $roles = $wp_roles->roles;
        $moved = 0;

        if ($new_id === $id) {
            if ($roles[$id]['name'] === $name) {
                return array('id' => $id, 'users' => 0);
            }

            self::snapshot(sprintf(__('Avant le renommage de « %1$s » en « %2$s »', 'smbb-wpcodetool'), $roles[$id]['name'], $name));
            $roles[$id]['name'] = $name;
            self::save($roles);
            do_action('smbb_wpcodetool_role_renamed', $id, $name);

            return array('id' => $id, 'users' => 0);
        }

        if ($id === 'administrator') {
            return new WP_Error('locked', __('L\'identifiant du rôle Administrateur ne peut pas changer : WordPress et les extensions s\'en servent partout.', 'smbb-wpcodetool'));
        }

        $new_id = self::validateId($new_id);

        if (is_wp_error($new_id)) {
            return $new_id;
        }

        self::snapshot(sprintf(__('Avant le changement d\'identifiant %1$s → %2$s', 'smbb-wpcodetool'), $id, $new_id));

        // 1. Le nouveau rôle prend la place de l'ancien dans la liste (même ordre, mêmes droits).
        $next = array();

        foreach ($roles as $key => $role) {
            if ($key === $id) {
                $next[$new_id] = array('name' => $name, 'capabilities' => $role['capabilities']);
            }

            $next[$key] = $role;
        }

        self::save($next);

        // 2. Les comptes passent de l'ancien au nouvel identifiant, au même rang (rôle principal gardé).
        $moved = self::moveUsers($id, $new_id);

        if (is_wp_error($moved)) {
            return $moved; // L'ancien rôle reste, rien n'est perdu : on peut relancer.
        }

        // 3. Rôle par défaut des nouveaux comptes.
        if (get_option('default_role') === $id) {
            update_option('default_role', $new_id);
        }

        // 4. L'ancien rôle disparaît.
        $roles = wp_roles()->roles;
        unset($roles[$id]);
        self::save($roles);
        self::$counts = null;

        /**
         * Un rôle a changé d'identifiant : les extensions qui stockent des identifiants de rôle
         * (réglages, règles d'accès…) peuvent les mettre à jour.
         */
        do_action('smbb_wpcodetool_role_id_renamed', $id, $new_id, $moved);

        return array('id' => $new_id, 'users' => $moved);
    }

    /**
     * Remplace $from par $to dans les rôles de chaque compte, à la même position.
     *
     * @return int|WP_Error nombre de comptes basculés
     */
    private static function moveUsers($from, $to, $replace = true)
    {
        $moved = 0;
        $guard = 0;

        do {
            $ids = get_users(array('role' => $from, 'fields' => 'ID', 'number' => 200, 'count_total' => false));

            foreach ($ids as $user_id) {
                $user = new WP_User((int) $user_id);
                $caps = array();
                $had_target = isset($user->caps[$to]);

                foreach ((array) $user->caps as $key => $value) {
                    if ($key === $from) {
                        if (!$had_target) {
                            $caps[$to] = $value;
                        }
                        continue;
                    }

                    $caps[$key] = $value;
                }

                update_user_meta($user->ID, $user->cap_key, $caps);
                clean_user_cache($user->ID);
                $user = new WP_User($user->ID);
                $user->update_user_level_from_caps();
                do_action('smbb_wpcodetool_user_role_moved', $user->ID, $from, $to);
                $moved++;
            }

            $guard++;
        } while (!empty($ids) && $guard < 500);

        if (!empty($ids)) {
            return new WP_Error('role_users', sprintf(__('%d comptes ont été basculés mais il en reste : relancez l\'opération.', 'smbb-wpcodetool'), $moved));
        }

        return $moved;
    }

    /**
     * Supprime un rôle. S'il est encore porté, $reassign dit vers quel rôle basculer les comptes
     * (ceux qui ont d'autres rôles perdent juste celui-ci).
     *
     * @return array|WP_Error [users]
     */
    public static function delete($id, $reassign = '')
    {
        $id = self::validateId($id, true);

        if (is_wp_error($id)) {
            return $id;
        }

        $blocker = self::deleteBlocker($id);

        if ($blocker !== '') {
            return new WP_Error('locked', $blocker);
        }

        $counts = self::userCounts();
        $users = isset($counts[$id]) ? (int) $counts[$id] : 0;
        $roles = wp_roles()->roles;

        if ($users > 0) {
            if ($reassign === '' || $reassign === $id || !isset($roles[$reassign])) {
                return new WP_Error('role_users', sprintf(_n('%d compte a encore ce rôle : choisissez vers quel rôle le basculer.', '%d comptes ont encore ce rôle : choisissez vers quel rôle les basculer.', $users, 'smbb-wpcodetool'), $users));
            }

            if ($reassign === 'administrator' && !self::fullAdmin()) {
                return new WP_Error('role_users', __('Seul un administrateur peut basculer des comptes vers le rôle Administrateur.', 'smbb-wpcodetool'));
            }
        }

        self::snapshot(sprintf(__('Avant la suppression de « %s »', 'smbb-wpcodetool'), $roles[$id]['name']));

        $moved = 0;

        if ($users > 0) {
            $moved = self::moveUsers($id, $reassign);

            if (is_wp_error($moved)) {
                return $moved;
            }
        }

        $roles = wp_roles()->roles;
        unset($roles[$id]);
        self::save($roles);
        self::$counts = null;
        do_action('smbb_wpcodetool_role_deleted', $id, $reassign, $moved);

        return array('users' => $moved);
    }

    /**
     * Coche / décoche des droits. Seuls les droits de $known sont touchés : ceux qui n'étaient pas
     * affichés (anciens niveaux masqués…) restent tels quels. Un refus explicite (false) est gardé
     * tant qu'on ne coche pas le droit.
     *
     * @return array|WP_Error [added[], removed[], refused[cap => raison]]
     */
    public static function setCaps($id, array $granted, array $known, $label = '')
    {
        $id = self::validateId($id, true);

        if (is_wp_error($id)) {
            return $id;
        }

        $blocker = self::editBlocker($id);

        if ($blocker !== '') {
            return new WP_Error('locked', $blocker);
        }

        $roles = wp_roles()->roles;
        $caps = (array) $roles[$id]['capabilities'];
        $granted = array_flip(array_filter(array_map('strval', $granted), array(Capabilities::class, 'validName')));
        $locked = self::lockedCaps($id);
        $result = array('added' => array(), 'removed' => array(), 'refused' => array());

        foreach (array_unique(array_map('strval', $known)) as $cap) {
            if (!Capabilities::validName($cap)) {
                continue;
            }

            $want = isset($granted[$cap]);
            $has = !empty($caps[$cap]);

            if ($want && !$has) {
                if (!self::canGrant($cap)) {
                    $result['refused'][$cap] = __('Vous n\'avez pas ce droit vous-même.', 'smbb-wpcodetool');
                    continue;
                }

                $caps[$cap] = true;
                $result['added'][] = $cap;
            } elseif (!$want && $has) {
                if (isset($locked[$cap])) {
                    $result['refused'][$cap] = $locked[$cap];
                    continue;
                }

                unset($caps[$cap]);
                $result['removed'][] = $cap;
            }
        }

        if ($result['added'] || $result['removed']) {
            self::snapshot($label !== '' ? $label : sprintf(__('Avant la modification des droits de « %s »', 'smbb-wpcodetool'), $roles[$id]['name']));
            $roles[$id]['capabilities'] = $caps;
            self::save($roles);
            do_action('smbb_wpcodetool_role_caps_changed', $id, $result['added'], $result['removed']);
        }

        return $result;
    }

    /**
     * Ajoute un droit nouveau (créé à la main) et le donne au rôle.
     */
    public static function addCustomCap($id, $cap)
    {
        $cap = strtolower(trim((string) $cap));

        if (!Capabilities::validName($cap)) {
            return new WP_Error('cap', __('Nom de droit invalide : lettres sans accent, chiffres, _ et -, au moins 2 caractères.', 'smbb-wpcodetool'));
        }

        Capabilities::addCustomCap($cap);

        return self::setCaps($id, array($cap), array($cap), sprintf(__('Avant l\'ajout du droit %1$s à « %2$s »', 'smbb-wpcodetool'), $cap, $id));
    }

    /**
     * Retire du site un droit créé à la main : de tous les rôles et du catalogue.
     */
    public static function removeCustomCap($cap)
    {
        if (!in_array($cap, Capabilities::customCaps(), true)) {
            return new WP_Error('cap', __('Seuls les droits ajoutés ici peuvent être retirés du site.', 'smbb-wpcodetool'));
        }

        if (!self::writable()) {
            return new WP_Error('locked', self::editBlocker(''));
        }

        $roles = wp_roles()->roles;
        $changed = false;

        foreach ($roles as $id => $role) {
            if (array_key_exists($cap, (array) $role['capabilities'])) {
                if ($id === 'administrator' || (self::isMine($id) && isset(self::lockedCaps($id)[$cap]))) {
                    continue;
                }

                if (!$changed) {
                    self::snapshot(sprintf(__('Avant le retrait du droit %s', 'smbb-wpcodetool'), $cap));
                }

                unset($roles[$id]['capabilities'][$cap]);
                $changed = true;
            }
        }

        // L'administrateur le perd aussi : c'est un droit créé ici, il n'est vital pour personne.
        if (isset($roles['administrator']['capabilities'][$cap])) {
            if (!$changed) {
                self::snapshot(sprintf(__('Avant le retrait du droit %s', 'smbb-wpcodetool'), $cap));
            }

            unset($roles['administrator']['capabilities'][$cap]);
            $changed = true;
        }

        if ($changed) {
            self::save($roles);
        }

        Capabilities::removeCustomCap($cap);

        return true;
    }

    public static function setDefault($id)
    {
        $id = self::validateId($id, true);

        if (is_wp_error($id)) {
            return $id;
        }

        if ($id === 'administrator') {
            return new WP_Error('locked', __('Donner le rôle Administrateur à chaque nouveau compte serait dangereux : refusé.', 'smbb-wpcodetool'));
        }

        update_option('default_role', $id);

        return true;
    }

    /**
     * Différences entre deux rôles : cap => [a(bool), b(bool)], seulement les droits présents dans l'un ou l'autre.
     */
    public static function diff($a, $b)
    {
        $roles = wp_roles()->roles;
        $ca = isset($roles[$a]) ? (array) $roles[$a]['capabilities'] : array();
        $cb = isset($roles[$b]) ? (array) $roles[$b]['capabilities'] : array();
        $out = array();

        foreach (array_unique(array_merge(array_keys($ca), array_keys($cb))) as $cap) {
            $out[$cap] = array(!empty($ca[$cap]), !empty($cb[$cap]));
        }

        return $out;
    }

    // ------------------------------------------------------------------ Historique

    /**
     * Photo des rôles avant une modification.
     */
    public static function snapshot($label)
    {
        $entry = array(
            'time' => time(),
            'user' => get_current_user_id(),
            'label' => (string) $label,
            'roles' => wp_roles()->roles,
            'default' => (string) get_option('default_role'),
        );

        if (get_option(self::ORIGIN_OPTION, null) === null) {
            $origin = $entry;
            $origin['label'] = __('Rôles d\'origine (avant la première modification depuis CodeTool)', 'smbb-wpcodetool');
            add_option(self::ORIGIN_OPTION, $origin, '', false);
        }

        $history = self::history();
        array_unshift($history, $entry);
        update_option(self::HISTORY_OPTION, array_slice($history, 0, self::HISTORY_MAX), false);
    }

    /**
     * @return array<int,array{time:int,user:int,label:string,roles:array,default:string}>
     */
    public static function history()
    {
        $history = get_option(self::HISTORY_OPTION, array());

        return is_array($history) ? array_values($history) : array();
    }

    public static function origin()
    {
        $origin = get_option(self::ORIGIN_OPTION, null);

        return is_array($origin) ? $origin : null;
    }

    /**
     * Remet les rôles d'une photo ('origin' ou index de l'historique). Les comptes ne bougent pas.
     */
    public static function restore($which)
    {
        if (!self::fullAdmin()) {
            return new WP_Error('locked', __('Seul un administrateur peut restaurer les rôles.', 'smbb-wpcodetool'));
        }

        if (!self::writable()) {
            return new WP_Error('locked', self::editBlocker(''));
        }

        if ($which === 'origin') {
            $entry = self::origin();
        } else {
            $history = self::history();
            $entry = isset($history[(int) $which]) ? $history[(int) $which] : null;
        }

        if (!$entry || empty($entry['roles']) || !is_array($entry['roles'])) {
            return new WP_Error('history', __('Cette sauvegarde n\'existe plus.', 'smbb-wpcodetool'));
        }

        $roles = $entry['roles'];

        // Garde-fou : on ne restaure pas un état où l'on perdrait soi-même l'accès.
        $mine = array_intersect((array) wp_get_current_user()->roles, array_keys($roles));
        $keeps = is_super_admin();

        foreach ($mine as $role) {
            if (!empty($roles[$role]['capabilities'][self::capability()])) {
                $keeps = true;
            }
        }

        if (!$keeps) {
            return new WP_Error('locked', __('Avec cette sauvegarde vous perdriez l\'accès à cet écran : restauration refusée.', 'smbb-wpcodetool'));
        }

        self::snapshot(sprintf(__('Avant la restauration « %s »', 'smbb-wpcodetool'), $entry['label']));
        self::save($roles);

        if (!empty($entry['default']) && isset($roles[$entry['default']])) {
            update_option('default_role', $entry['default']);
        }

        self::$counts = null;

        return true;
    }

    /**
     * Comptes dont un rôle n'existe plus (après restauration d'une vieille sauvegarde, par exemple).
     */
    public static function orphanCounts()
    {
        $roles = wp_roles()->roles;
        $orphans = array();

        foreach (self::userCounts() as $role => $count) {
            if ($role !== 'none' && !isset($roles[$role]) && $count > 0) {
                $orphans[$role] = (int) $count;
            }
        }

        return $orphans;
    }

    /**
     * Rattache les comptes d'un rôle disparu à un rôle existant.
     */
    public static function adoptOrphans($missing, $target)
    {
        $roles = wp_roles()->roles;

        if (isset($roles[$missing]) || !isset($roles[$target]) || !Capabilities::validName($missing)) {
            return new WP_Error('role', __('Rôle introuvable.', 'smbb-wpcodetool'));
        }

        if ($target === 'administrator' && !self::fullAdmin()) {
            return new WP_Error('locked', __('Seul un administrateur peut basculer des comptes vers le rôle Administrateur.', 'smbb-wpcodetool'));
        }

        // get_users(role=…) ne trouve pas un rôle inexistant : recherche directe dans les metas.
        global $wpdb;
        $key = $wpdb->get_blog_prefix() . 'capabilities';
        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = %s AND meta_value LIKE %s",
            $key,
            '%' . $wpdb->esc_like('"' . $missing . '"') . '%'
        ));
        $moved = 0;

        foreach ($ids as $user_id) {
            $user = new WP_User((int) $user_id);

            if (!isset($user->caps[$missing])) {
                continue;
            }

            $caps = array();

            foreach ((array) $user->caps as $k => $v) {
                if ($k === $missing) {
                    if (!isset($user->caps[$target])) {
                        $caps[$target] = true;
                    }
                    continue;
                }

                $caps[$k] = $v;
            }

            update_user_meta($user->ID, $user->cap_key, $caps);
            clean_user_cache($user->ID);
            $moved++;
        }

        self::$counts = null;

        return $moved;
    }

    /**
     * Écrit les rôles en base et recharge WP_Roles.
     */
    private static function save(array $roles)
    {
        $wp_roles = wp_roles();
        update_option($wp_roles->role_key, $roles);

        if (method_exists($wp_roles, 'for_site')) {
            $wp_roles->for_site(get_current_blog_id());
        } else {
            $wp_roles->roles = $roles;
            $wp_roles->role_objects = array();
            $wp_roles->role_names = array();

            foreach (array_keys($roles) as $role) {
                $wp_roles->role_objects[$role] = new \WP_Role($role, $roles[$role]['capabilities']);
                $wp_roles->role_names[$role] = $roles[$role]['name'];
            }
        }
    }
}
