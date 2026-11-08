<?php

namespace Smbb\WpCodeTool\Activity;

defined('ABSPATH') || exit;

/**
 * Branche le journal d'activité sur les événements de WordPress, de WooCommerce et de CodeTool.
 *
 * - Connexions : connexion, échec (regroupés par IP pendant 15 min), déconnexion, mot de passe perdu / réinitialisé.
 * - Contenus : création, publication, dépublication, corbeille, restauration, suppression, modification
 *   (titre, contenu, slug, auteur, date, parent, mot de passe, termes), médias, termes, menus.
 * - Extensions et thèmes : activation, désactivation, installation, mise à jour (avec versions), suppression,
 *   changement de thème, mise à jour de WordPress.
 * - Comptes et rôles : création, modification (e-mail, nom, mot de passe), rôle changé, suppression,
 *   mots de passe d'application, super admin, et les actions du gestionnaire de rôles CodeTool.
 * - Réglages : options du cœur suivies + options des plugins (préfixes smbb_, woocommerce_...), regroupées
 *   en une ligne par requête.
 * - Commandes WooCommerce : statut, champs modifiés par l'équipe, remboursements, corbeille, suppression.
 *
 * Les modifications d'un même contenu ou d'une même commande pendant une requête sont regroupées
 * et écrites en fin de requête (shutdown) : une ligne par enregistrement, pas une par champ.
 */
final class Watchers
{
    /** @var array<int,array> Contenus touchés pendant la requête. */
    private static $posts = array();

    /** @var array<int,array> Commandes touchées pendant la requête. */
    private static $orders = array();

    /** @var array<string,array> Réglages modifiés pendant la requête : option => [avant, après]. */
    private static $options = array();

    /** @var array<string,string> Versions avant mise à jour : fichier => version. */
    private static $versions = array();

    /** @var array<string,string> Noms des extensions en cours de suppression. */
    private static $deleting = array();

    private static $hooked = false;

    public static function hooks()
    {
        if (self::$hooked) {
            return;
        }

        self::$hooked = true;

        // Connexions.
        add_action('wp_login', array(__CLASS__, 'onLogin'), 10, 2);
        add_action('wp_login_failed', array(__CLASS__, 'onLoginFailed'), 10, 2);
        add_action('wp_logout', array(__CLASS__, 'onLogout'));
        add_action('retrieve_password', array(__CLASS__, 'onLostPassword'));
        add_action('after_password_reset', array(__CLASS__, 'onPasswordReset'));

        // Contenus.
        add_action('transition_post_status', array(__CLASS__, 'onPostStatus'), 10, 3);
        add_action('post_updated', array(__CLASS__, 'onPostUpdated'), 10, 3);
        add_action('set_object_terms', array(__CLASS__, 'onObjectTerms'), 10, 6);
        add_action('before_delete_post', array(__CLASS__, 'onPostDelete'), 10, 2);
        add_action('add_attachment', array(__CLASS__, 'onAttachmentAdded'));
        add_action('delete_attachment', array(__CLASS__, 'onAttachmentDeleted'), 10, 2);
        add_action('created_term', array(__CLASS__, 'onTermCreated'), 10, 3);
        add_action('edited_term', array(__CLASS__, 'onTermEdited'), 10, 3);
        add_action('pre_delete_term', array(__CLASS__, 'onTermDelete'), 10, 2);
        add_action('wp_update_nav_menu', array(__CLASS__, 'onMenuSaved'), 10, 1);
        add_action('wp_delete_nav_menu', array(__CLASS__, 'onMenuDeleted'), 10, 1);

        // Extensions et thèmes.
        add_action('activated_plugin', array(__CLASS__, 'onPluginActivated'), 10, 2);
        add_action('deactivated_plugin', array(__CLASS__, 'onPluginDeactivated'), 10, 2);
        add_action('delete_plugin', array(__CLASS__, 'onPluginDeleting'));
        add_action('deleted_plugin', array(__CLASS__, 'onPluginDeleted'), 10, 2);
        add_filter('upgrader_pre_install', array(__CLASS__, 'onPreInstall'), 10, 2);
        add_action('upgrader_process_complete', array(__CLASS__, 'onUpgraded'), 10, 2);
        add_action('switch_theme', array(__CLASS__, 'onThemeSwitched'), 10, 3);
        add_action('deleted_theme', array(__CLASS__, 'onThemeDeleted'), 10, 2);
        add_action('_core_updated_successfully', array(__CLASS__, 'onCoreUpdated'));

        // Comptes et rôles.
        add_action('user_register', array(__CLASS__, 'onUserCreated'), 10, 1);
        add_action('profile_update', array(__CLASS__, 'onUserUpdated'), 10, 2);
        add_action('set_user_role', array(__CLASS__, 'onUserRole'), 10, 3);
        add_action('add_user_role', array(__CLASS__, 'onUserRoleAdded'), 10, 2);
        add_action('remove_user_role', array(__CLASS__, 'onUserRoleRemoved'), 10, 2);
        add_action('delete_user', array(__CLASS__, 'onUserDelete'), 10, 2);
        add_action('wp_create_application_password', array(__CLASS__, 'onAppPasswordCreated'), 10, 2);
        add_action('wp_delete_application_password', array(__CLASS__, 'onAppPasswordDeleted'), 10, 2);
        add_action('granted_super_admin', array(__CLASS__, 'onSuperAdminGranted'));
        add_action('revoked_super_admin', array(__CLASS__, 'onSuperAdminRevoked'));
        add_action('smbb_wpcodetool_role_created', array(__CLASS__, 'onRoleCreated'), 10, 2);
        add_action('smbb_wpcodetool_role_renamed', array(__CLASS__, 'onRoleRenamed'), 10, 2);
        add_action('smbb_wpcodetool_role_id_renamed', array(__CLASS__, 'onRoleIdRenamed'), 10, 3);
        add_action('smbb_wpcodetool_role_deleted', array(__CLASS__, 'onRoleDeleted'), 10, 3);
        add_action('smbb_wpcodetool_role_caps_changed', array(__CLASS__, 'onRoleCaps'), 10, 3);

        // Réglages.
        add_action('updated_option', array(__CLASS__, 'onOptionUpdated'), 10, 3);
        add_action('added_option', array(__CLASS__, 'onOptionAdded'), 10, 2);

        // Commandes WooCommerce (sans effet si WooCommerce est absent).
        add_action('woocommerce_order_status_changed', array(__CLASS__, 'onOrderStatus'), 10, 4);
        add_action('woocommerce_before_order_object_save', array(__CLASS__, 'onOrderSave'), 10, 1);
        add_action('woocommerce_order_refunded', array(__CLASS__, 'onOrderRefunded'), 10, 2);
        add_action('woocommerce_trash_order', array(__CLASS__, 'onOrderTrashed'), 10, 1);
        add_action('woocommerce_untrash_order', array(__CLASS__, 'onOrderUntrashed'), 10, 2);
        add_action('woocommerce_before_delete_order', array(__CLASS__, 'onOrderDelete'), 10, 2);

        add_action('shutdown', array(__CLASS__, 'flush'), 1);
    }

    private static function log($event, array $args)
    {
        return ActivityLog::log($event, $args);
    }

    private static function settings()
    {
        return ActivityLog::settings();
    }

    /**
     * L'action vient-elle d'un membre de l'équipe (pas d'un client, d'un visiteur ou d'une tâche) ?
     */
    private static function staff()
    {
        return get_current_user_id() > 0 && (current_user_can('edit_posts') || current_user_can('edit_shop_orders') || current_user_can('list_users'));
    }

    private static function short($value, $length = 140)
    {
        if (is_bool($value)) {
            return $value ? 'oui' : 'non';
        }

        if (is_array($value) || is_object($value)) {
            $value = wp_json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        $value = trim(preg_replace('/\s+/', ' ', wp_strip_all_tags((string) $value)));

        return function_exists('mb_strimwidth') ? mb_strimwidth($value, 0, $length, '…') : substr($value, 0, $length);
    }

    // ------------------------------------------------------------------ Connexions

    public static function onLogin($login, $user = null)
    {
        $user = $user instanceof \WP_User ? $user : get_user_by('login', $login);

        if (!$user) {
            return;
        }

        self::log('login.success', array(
            'level' => 'info',
            'user_id' => $user->ID,
            'object_type' => 'user',
            'object_id' => $user->ID,
            'object_label' => $user->display_name,
            'message' => __('Connexion', 'smbb-wpcodetool'),
        ));
    }

    public static function onLoginFailed($username, $error = null)
    {
        if (empty(self::settings()['failed_logins'])) {
            return;
        }

        $username = sanitize_user((string) $username);
        $user = is_email($username) ? get_user_by('email', $username) : get_user_by('login', $username);
        $code = $error instanceof \WP_Error ? (string) $error->get_error_code() : '';
        $reasons = array(
            'invalid_username' => __('identifiant inconnu', 'smbb-wpcodetool'),
            'invalid_email' => __('e-mail inconnu', 'smbb-wpcodetool'),
            'incorrect_password' => __('mauvais mot de passe', 'smbb-wpcodetool'),
            'empty_password' => __('mot de passe vide', 'smbb-wpcodetool'),
        );
        $reason = isset($reasons[$code]) ? $reasons[$code] : $code;

        self::log('login.failed', array(
            'level' => $user && user_can($user, 'manage_options') ? 'warning' : 'notice',
            'user_id' => 0,
            'user_login' => $username,
            'object_type' => $user ? 'user' : '',
            'object_id' => $user ? $user->ID : '',
            'object_label' => $user ? $user->display_name : '',
            'message' => $reason !== ''
                ? sprintf(__('Échec de connexion avec « %1$s » (%2$s)', 'smbb-wpcodetool'), $username, $reason)
                : sprintf(__('Échec de connexion avec « %s »', 'smbb-wpcodetool'), $username),
            'context' => array('reason' => $code),
            'group' => 15 * MINUTE_IN_SECONDS,
        ));
    }

    public static function onLogout($user_id = 0)
    {
        $user_id = (int) $user_id ?: get_current_user_id();

        if ($user_id <= 0) {
            return;
        }

        self::log('login.logout', array(
            'user_id' => $user_id,
            'object_type' => 'user',
            'object_id' => $user_id,
            'message' => __('Déconnexion', 'smbb-wpcodetool'),
        ));
    }

    public static function onLostPassword($login)
    {
        $user = get_user_by('login', (string) $login);

        self::log('login.lost_password', array(
            'level' => 'notice',
            'user_id' => 0,
            'user_login' => (string) $login,
            'object_type' => $user ? 'user' : '',
            'object_id' => $user ? $user->ID : '',
            'object_label' => $user ? $user->display_name : '',
            'message' => sprintf(__('Lien « mot de passe oublié » demandé pour %s', 'smbb-wpcodetool'), (string) $login),
            'group' => 15 * MINUTE_IN_SECONDS,
        ));
    }

    public static function onPasswordReset($user)
    {
        if (!$user instanceof \WP_User) {
            return;
        }

        self::log('login.password_reset', array(
            'level' => 'notice',
            'user_id' => $user->ID,
            'object_type' => 'user',
            'object_id' => $user->ID,
            'object_label' => $user->display_name,
            'message' => __('Mot de passe réinitialisé par le lien envoyé par e-mail', 'smbb-wpcodetool'),
        ));
    }

    // ------------------------------------------------------------------ Contenus

    /**
     * Types de contenus non suivis : techniques, sans écran d'admin, ou suivis ailleurs (commandes).
     */
    private static function ignoredType($type)
    {
        $type = (string) $type;
        $ignored = array('revision', 'nav_menu_item', 'customize_changeset', 'oembed_cache', 'user_request', 'custom_css', 'wp_global_styles', 'scheduled-action', 'shop_order_refund', 'shop_order_placehold');

        if (in_array($type, $ignored, true) || self::isOrderType($type)) {
            return true;
        }

        $object = get_post_type_object($type);

        if ($object && empty($object->show_ui)) {
            $ignored_hidden = true;
        } else {
            $ignored_hidden = false;
        }

        return (bool) apply_filters('smbb_wpcodetool_activity_ignore_post_type', $ignored_hidden, $type);
    }

    private static function isOrderType($type)
    {
        return in_array((string) $type, array('shop_order', 'shop_subscription'), true);
    }

    private static function typeLabel($type)
    {
        $object = get_post_type_object((string) $type);

        return $object && isset($object->labels->singular_name) ? (string) $object->labels->singular_name : (string) $type;
    }

    private static function statusLabel($status)
    {
        $object = get_post_status_object((string) $status);

        return $object && isset($object->label) ? (string) $object->label : (string) $status;
    }

    private static function postTitle($post)
    {
        $title = $post instanceof \WP_Post ? trim((string) $post->post_title) : '';

        return $title !== '' ? $title : sprintf(__('(sans titre #%d)', 'smbb-wpcodetool'), $post instanceof \WP_Post ? $post->ID : 0);
    }

    private static function &postBucket(\WP_Post $post)
    {
        if (!isset(self::$posts[$post->ID])) {
            self::$posts[$post->ID] = array('post' => $post, 'status' => null, 'changes' => array(), 'user' => get_current_user_id());
        }

        self::$posts[$post->ID]['post'] = $post;

        return self::$posts[$post->ID];
    }

    public static function onPostStatus($new, $old, $post)
    {
        if (!$post instanceof \WP_Post || $new === $old || $new === 'auto-draft' || $new === 'inherit'
            || self::ignoredType($post->post_type) || wp_is_post_revision($post) || wp_is_post_autosave($post)) {
            return;
        }

        $bucket = &self::postBucket($post);

        if ($bucket['status'] === null) {
            $bucket['status'] = array($old, $new);
        } else {
            $bucket['status'][1] = $new;
        }
    }

    public static function onPostUpdated($post_id, $after, $before)
    {
        if (!$after instanceof \WP_Post || !$before instanceof \WP_Post || $after->post_status === 'auto-draft'
            || self::ignoredType($after->post_type) || wp_is_post_revision($after) || wp_is_post_autosave($after)) {
            return;
        }

        $fields = array(
            'post_title' => __('Titre', 'smbb-wpcodetool'),
            'post_name' => __('Adresse (slug)', 'smbb-wpcodetool'),
            'post_excerpt' => __('Extrait', 'smbb-wpcodetool'),
            'post_author' => __('Auteur', 'smbb-wpcodetool'),
            'post_date' => __('Date', 'smbb-wpcodetool'),
            'post_parent' => __('Parent', 'smbb-wpcodetool'),
            'menu_order' => __('Ordre', 'smbb-wpcodetool'),
            'comment_status' => __('Commentaires', 'smbb-wpcodetool'),
        );
        $changes = array();

        foreach ($fields as $field => $label) {
            if ((string) $before->$field !== (string) $after->$field) {
                $old = $before->$field;
                $new = $after->$field;

                if ($field === 'post_author') {
                    $a = get_userdata((int) $old);
                    $b = get_userdata((int) $new);
                    $old = $a ? $a->display_name : $old;
                    $new = $b ? $b->display_name : $new;
                }

                $changes[$label] = array(self::short($old), self::short($new));
            }
        }

        if ((string) $before->post_content !== (string) $after->post_content) {
            $changes[__('Contenu', 'smbb-wpcodetool')] = array(
                sprintf(__('%d caractères', 'smbb-wpcodetool'), strlen(wp_strip_all_tags($before->post_content))),
                sprintf(__('%d caractères', 'smbb-wpcodetool'), strlen(wp_strip_all_tags($after->post_content))),
            );
        }

        if ((string) $before->post_password !== (string) $after->post_password) {
            $changes[__('Protection par mot de passe', 'smbb-wpcodetool')] = array(
                $before->post_password !== '' ? __('oui', 'smbb-wpcodetool') : __('non', 'smbb-wpcodetool'),
                $after->post_password !== '' ? __('oui', 'smbb-wpcodetool') : __('non', 'smbb-wpcodetool'),
            );
        }

        if (!$changes) {
            return;
        }

        $bucket = &self::postBucket($after);
        $bucket['changes'] = array_merge($bucket['changes'], $changes);
    }

    public static function onObjectTerms($object_id, $terms, $tt_ids, $taxonomy, $append, $old_tt_ids)
    {
        $post = get_post((int) $object_id);
        $tax = get_taxonomy((string) $taxonomy);

        if (!$post || !$tax || empty($tax->show_ui) || $post->post_status === 'auto-draft' || self::ignoredType($post->post_type) || wp_is_post_revision($post)) {
            return;
        }

        $old = array_map('intval', (array) $old_tt_ids);
        $new = array_map('intval', (array) $tt_ids);
        sort($old);
        sort($new);

        if ($old === $new) {
            return;
        }

        $names = static function (array $ids) use ($taxonomy) {
            $out = array();

            foreach ($ids as $tt_id) {
                $term = get_term_by('term_taxonomy_id', $tt_id, $taxonomy);
                $out[] = $term ? $term->name : '#' . $tt_id;
            }

            return $out ? implode(', ', $out) : '—';
        };

        $bucket = &self::postBucket($post);
        $bucket['changes'][(string) $tax->labels->name] = array(self::short($names($old)), self::short($names($new)));
    }

    /**
     * Fin de requête : une ligne par contenu, par commande, et une pour les réglages.
     */
    public static function flush()
    {
        foreach (self::$posts as $id => $bucket) {
            self::flushPost($bucket);
        }

        foreach (self::$orders as $id => $bucket) {
            self::flushOrder($id, $bucket);
        }

        self::flushOptions();
        self::$posts = array();
        self::$orders = array();
        self::$options = array();
    }

    private static function flushPost(array $bucket)
    {
        $post = $bucket['post'];
        $type = self::typeLabel($post->post_type);
        $title = self::postTitle($post);
        $changes = $bucket['changes'];
        $level = 'info';
        $event = 'content.updated';

        if ($bucket['status'] !== null) {
            list($old, $new) = $bucket['status'];
            $changes = array(__('Statut', 'smbb-wpcodetool') => array(self::statusLabel($old), self::statusLabel($new))) + $changes;
            $created = in_array($old, array('new', 'auto-draft'), true);

            if ($new === 'trash') {
                $event = 'content.trashed';
                $level = 'notice';
                $message = sprintf(__('%1$s « %2$s » mis à la corbeille', 'smbb-wpcodetool'), $type, $title);
            } elseif ($old === 'trash') {
                $event = 'content.restored';
                $message = sprintf(__('%1$s « %2$s » sorti de la corbeille', 'smbb-wpcodetool'), $type, $title);
            } elseif ($new === 'publish') {
                $event = 'content.published';
                $message = $created
                    ? sprintf(__('%1$s « %2$s » créé et publié', 'smbb-wpcodetool'), $type, $title)
                    : sprintf(__('%1$s « %2$s » publié', 'smbb-wpcodetool'), $type, $title);
            } elseif ($old === 'publish') {
                $event = 'content.unpublished';
                $level = 'notice';
                $message = sprintf(__('%1$s « %2$s » dépublié (%3$s)', 'smbb-wpcodetool'), $type, $title, self::statusLabel($new));
            } elseif ($created) {
                $event = 'content.created';
                $message = sprintf(__('%1$s « %2$s » créé (%3$s)', 'smbb-wpcodetool'), $type, $title, self::statusLabel($new));
            } else {
                $event = 'content.status';
                $message = sprintf(__('%1$s « %2$s » : %3$s → %4$s', 'smbb-wpcodetool'), $type, $title, self::statusLabel($old), self::statusLabel($new));
            }
        } else {
            $message = sprintf(__('%1$s « %2$s » modifié', 'smbb-wpcodetool'), $type, $title);
        }

        self::log($event, array(
            'category' => 'content',
            'level' => $level,
            'user_id' => $bucket['user'],
            'object_type' => 'post',
            'object_id' => $post->ID,
            'object_label' => $title,
            'message' => $message,
            'context' => array('post_type' => $post->post_type, 'changes' => $changes),
        ));
    }

    public static function onPostDelete($post_id, $post = null)
    {
        $post = $post instanceof \WP_Post ? $post : get_post((int) $post_id);

        if (!$post || $post->post_status === 'auto-draft' || wp_is_post_revision($post)) {
            return;
        }

        // Commandes rangées en articles (sans HPOS).
        if (self::isOrderType($post->post_type)) {
            self::orderDeleted((int) $post->ID, null);
            return;
        }

        if ($post->post_type === 'attachment' || self::ignoredType($post->post_type)) {
            return;
        }

        unset(self::$posts[$post->ID]);

        self::log('content.deleted', array(
            'category' => 'content',
            'level' => 'warning',
            'object_type' => 'post',
            'object_id' => $post->ID,
            'object_label' => self::postTitle($post),
            'message' => sprintf(__('%1$s « %2$s » supprimé définitivement', 'smbb-wpcodetool'), self::typeLabel($post->post_type), self::postTitle($post)),
            'context' => array('post_type' => $post->post_type, 'status' => $post->post_status),
        ));
    }

    public static function onAttachmentAdded($post_id)
    {
        $post = get_post((int) $post_id);

        if (!$post) {
            return;
        }

        self::log('content.media_added', array(
            'category' => 'content',
            'object_type' => 'post',
            'object_id' => $post->ID,
            'object_label' => self::postTitle($post),
            'message' => sprintf(__('Média ajouté : %s', 'smbb-wpcodetool'), wp_basename((string) get_attached_file($post->ID)) ?: self::postTitle($post)),
            'context' => array('mime' => $post->post_mime_type),
        ));
    }

    public static function onAttachmentDeleted($post_id, $post = null)
    {
        $post = $post instanceof \WP_Post ? $post : get_post((int) $post_id);

        if (!$post) {
            return;
        }

        self::log('content.media_deleted', array(
            'category' => 'content',
            'level' => 'notice',
            'object_type' => 'post',
            'object_id' => $post->ID,
            'object_label' => self::postTitle($post),
            'message' => sprintf(__('Média supprimé : %s', 'smbb-wpcodetool'), wp_basename((string) get_attached_file($post->ID)) ?: self::postTitle($post)),
            'context' => array('mime' => $post->post_mime_type),
        ));
    }

    private static function termTracked($taxonomy)
    {
        $tax = get_taxonomy((string) $taxonomy);

        return $tax && !empty($tax->show_ui) && $taxonomy !== 'nav_menu';
    }

    private static function termLabel($taxonomy)
    {
        $tax = get_taxonomy((string) $taxonomy);

        return $tax && isset($tax->labels->singular_name) ? (string) $tax->labels->singular_name : (string) $taxonomy;
    }

    public static function onTermCreated($term_id, $tt_id, $taxonomy)
    {
        if (!self::termTracked($taxonomy) || !self::staff()) {
            return;
        }

        $term = get_term((int) $term_id, $taxonomy);

        self::log('content.term_created', array(
            'category' => 'content',
            'object_type' => 'term',
            'object_id' => (int) $term_id,
            'object_label' => $term && !is_wp_error($term) ? $term->name : '',
            'message' => sprintf(__('%1$s « %2$s » créé', 'smbb-wpcodetool'), self::termLabel($taxonomy), $term && !is_wp_error($term) ? $term->name : '#' . $term_id),
            'context' => array('taxonomy' => $taxonomy),
        ));
    }

    public static function onTermEdited($term_id, $tt_id, $taxonomy)
    {
        if (!self::termTracked($taxonomy) || !self::staff()) {
            return;
        }

        $term = get_term((int) $term_id, $taxonomy);

        self::log('content.term_updated', array(
            'category' => 'content',
            'object_type' => 'term',
            'object_id' => (int) $term_id,
            'object_label' => $term && !is_wp_error($term) ? $term->name : '',
            'message' => sprintf(__('%1$s « %2$s » modifié', 'smbb-wpcodetool'), self::termLabel($taxonomy), $term && !is_wp_error($term) ? $term->name : '#' . $term_id),
            'context' => array('taxonomy' => $taxonomy),
        ));
    }

    public static function onTermDelete($term_id, $taxonomy)
    {
        if (!self::termTracked($taxonomy)) {
            return;
        }

        $term = get_term((int) $term_id, $taxonomy);

        self::log('content.term_deleted', array(
            'category' => 'content',
            'level' => 'notice',
            'object_type' => 'term',
            'object_id' => (int) $term_id,
            'object_label' => $term && !is_wp_error($term) ? $term->name : '',
            'message' => sprintf(__('%1$s « %2$s » supprimé', 'smbb-wpcodetool'), self::termLabel($taxonomy), $term && !is_wp_error($term) ? $term->name : '#' . $term_id),
            'context' => array('taxonomy' => $taxonomy),
        ));
    }

    public static function onMenuSaved($menu_id)
    {
        if (!self::staff()) {
            return;
        }

        $menu = wp_get_nav_menu_object((int) $menu_id);

        self::log('content.menu_saved', array(
            'category' => 'content',
            'object_type' => 'menu',
            'object_id' => (int) $menu_id,
            'object_label' => $menu ? $menu->name : '',
            'message' => sprintf(__('Menu « %s » enregistré', 'smbb-wpcodetool'), $menu ? $menu->name : '#' . $menu_id),
        ));
    }

    public static function onMenuDeleted($menu_id)
    {
        self::log('content.menu_deleted', array(
            'category' => 'content',
            'level' => 'notice',
            'object_type' => 'menu',
            'object_id' => (int) $menu_id,
            'message' => sprintf(__('Menu #%d supprimé', 'smbb-wpcodetool'), (int) $menu_id),
        ));
    }

    // ------------------------------------------------------------------ Extensions et thèmes

    private static function pluginData($file)
    {
        $path = WP_PLUGIN_DIR . '/' . $file;

        if (!function_exists('get_plugin_data')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        if (!is_readable($path)) {
            return array('Name' => $file, 'Version' => '');
        }

        $data = get_plugin_data($path, false, false);

        return array('Name' => !empty($data['Name']) ? $data['Name'] : $file, 'Version' => isset($data['Version']) ? $data['Version'] : '');
    }

    public static function onPluginActivated($file, $network = false)
    {
        $data = self::pluginData($file);

        self::log('plugins.activated', array(
            'level' => 'notice',
            'object_type' => 'plugin',
            'object_id' => $file,
            'object_label' => $data['Name'],
            'message' => sprintf(__('Extension « %1$s » %2$s activée', 'smbb-wpcodetool'), $data['Name'], $data['Version']),
            'context' => array('file' => $file, 'version' => $data['Version'], 'network' => (bool) $network),
        ));
    }

    public static function onPluginDeactivated($file, $network = false)
    {
        $data = self::pluginData($file);

        self::log('plugins.deactivated', array(
            'level' => 'warning',
            'object_type' => 'plugin',
            'object_id' => $file,
            'object_label' => $data['Name'],
            'message' => sprintf(__('Extension « %s » désactivée', 'smbb-wpcodetool'), $data['Name']),
            'context' => array('file' => $file, 'version' => $data['Version'], 'network' => (bool) $network),
        ));
    }

    public static function onPluginDeleting($file)
    {
        self::$deleting[$file] = self::pluginData($file)['Name'];
    }

    public static function onPluginDeleted($file, $deleted = true)
    {
        if (!$deleted) {
            return;
        }

        $name = isset(self::$deleting[$file]) ? self::$deleting[$file] : $file;

        self::log('plugins.deleted', array(
            'level' => 'warning',
            'object_type' => 'plugin',
            'object_id' => $file,
            'object_label' => $name,
            'message' => sprintf(__('Extension « %s » supprimée', 'smbb-wpcodetool'), $name),
            'context' => array('file' => $file),
        ));
    }

    /**
     * Avant l'écrasement des fichiers : on garde la version installée pour l'afficher « 1.2 → 1.3 ».
     */
    public static function onPreInstall($return, $extra = array())
    {
        if (!empty($extra['plugin'])) {
            self::$versions['plugin:' . $extra['plugin']] = self::pluginData($extra['plugin'])['Version'];
        }

        if (!empty($extra['theme'])) {
            $theme = wp_get_theme($extra['theme']);
            self::$versions['theme:' . $extra['theme']] = $theme->exists() ? (string) $theme->get('Version') : '';
        }

        return $return;
    }

    public static function onUpgraded($upgrader, $extra)
    {
        $extra = is_array($extra) ? $extra : array();
        $type = isset($extra['type']) ? $extra['type'] : '';
        $action = isset($extra['action']) ? $extra['action'] : '';

        if (!in_array($type, array('plugin', 'theme'), true)) {
            return;
        }

        if ($action === 'install') {
            $name = '';
            $version = '';

            if (is_object($upgrader) && !empty($upgrader->new_plugin_data['Name'])) {
                $name = $upgrader->new_plugin_data['Name'];
                $version = isset($upgrader->new_plugin_data['Version']) ? $upgrader->new_plugin_data['Version'] : '';
            } elseif (is_object($upgrader) && !empty($upgrader->new_theme_data['Name'])) {
                $name = $upgrader->new_theme_data['Name'];
                $version = isset($upgrader->new_theme_data['Version']) ? $upgrader->new_theme_data['Version'] : '';
            } elseif (is_object($upgrader) && method_exists($upgrader, 'plugin_info') && $type === 'plugin') {
                $file = $upgrader->plugin_info();
                $name = $file ? self::pluginData($file)['Name'] : '';
            }

            self::log('plugins.installed', array(
                'level' => 'notice',
                'object_type' => $type,
                'object_label' => $name,
                'message' => $type === 'theme'
                    ? sprintf(__('Thème « %1$s » %2$s installé', 'smbb-wpcodetool'), $name !== '' ? $name : '?', $version)
                    : sprintf(__('Extension « %1$s » %2$s installée', 'smbb-wpcodetool'), $name !== '' ? $name : '?', $version),
            ));

            return;
        }

        if ($action !== 'update') {
            return;
        }

        $items = array();

        if ($type === 'plugin') {
            $items = !empty($extra['plugins']) ? (array) $extra['plugins'] : (!empty($extra['plugin']) ? array($extra['plugin']) : array());
        } else {
            $items = !empty($extra['themes']) ? (array) $extra['themes'] : (!empty($extra['theme']) ? array($extra['theme']) : array());
        }

        if ($type === 'plugin' && function_exists('wp_clean_plugins_cache')) {
            wp_clean_plugins_cache(false);
        }

        foreach ($items as $item) {
            if ($type === 'plugin') {
                $data = self::pluginData($item);
                $name = $data['Name'];
                $version = $data['Version'];
            } else {
                $theme = wp_get_theme($item);
                $name = $theme->exists() ? (string) $theme->get('Name') : $item;
                $version = $theme->exists() ? (string) $theme->get('Version') : '';
            }

            $old = isset(self::$versions[$type . ':' . $item]) ? self::$versions[$type . ':' . $item] : '';
            $versions = $old !== '' && $old !== $version ? $old . ' → ' . $version : $version;

            self::log('plugins.updated', array(
                'level' => 'notice',
                'object_type' => $type,
                'object_id' => $item,
                'object_label' => $name,
                'message' => $type === 'theme'
                    ? sprintf(__('Thème « %1$s » mis à jour (%2$s)', 'smbb-wpcodetool'), $name, $versions)
                    : sprintf(__('Extension « %1$s » mise à jour (%2$s)', 'smbb-wpcodetool'), $name, $versions),
                'context' => array('from' => $old, 'to' => $version),
            ));
        }
    }

    public static function onThemeSwitched($name, $new_theme = null, $old_theme = null)
    {
        $old = $old_theme instanceof \WP_Theme ? (string) $old_theme->get('Name') : '';

        self::log('plugins.theme_switched', array(
            'level' => 'warning',
            'object_type' => 'theme',
            'object_id' => $new_theme instanceof \WP_Theme ? $new_theme->get_stylesheet() : '',
            'object_label' => (string) $name,
            'message' => $old !== ''
                ? sprintf(__('Thème changé : %1$s → %2$s', 'smbb-wpcodetool'), $old, $name)
                : sprintf(__('Thème activé : %s', 'smbb-wpcodetool'), $name),
        ));
    }

    public static function onThemeDeleted($stylesheet, $deleted = true)
    {
        if (!$deleted) {
            return;
        }

        self::log('plugins.theme_deleted', array(
            'level' => 'warning',
            'object_type' => 'theme',
            'object_id' => (string) $stylesheet,
            'object_label' => (string) $stylesheet,
            'message' => sprintf(__('Thème « %s » supprimé', 'smbb-wpcodetool'), $stylesheet),
        ));
    }

    public static function onCoreUpdated($version)
    {
        self::log('plugins.core_updated', array(
            'level' => 'notice',
            'object_type' => 'core',
            'object_label' => 'WordPress',
            'message' => sprintf(__('WordPress mis à jour en %s', 'smbb-wpcodetool'), $version),
            'context' => array('version' => $version),
        ));
    }

    // ------------------------------------------------------------------ Comptes et rôles

    private static function roleNames(array $roles)
    {
        $names = wp_roles()->get_names();
        $out = array();

        foreach ($roles as $role) {
            $out[] = isset($names[$role]) ? translate_user_role($names[$role]) : $role;
        }

        return $out ? implode(', ', $out) : '—';
    }

    /**
     * Compte « client » : seulement des rôles client / abonné (inscriptions, commandes).
     */
    private static function isCustomer($user)
    {
        $roles = $user instanceof \WP_User ? (array) $user->roles : array();

        return !$roles || !array_diff($roles, array('customer', 'subscriber'));
    }

    public static function onUserCreated($user_id)
    {
        $user = get_userdata((int) $user_id);

        if (!$user) {
            return;
        }

        $actor = get_current_user_id();
        $self = $actor === 0 || $actor === (int) $user_id;

        // Inscriptions de clients (commande, Mon compte) : notées seulement si le réglage le demande.
        if ($self && self::isCustomer($user) && empty(self::settings()['customers'])) {
            return;
        }

        self::log('users.created', array(
            // Compte à droits créé sans être connecté : à regarder de près.
            'level' => $self && !self::isCustomer($user) ? 'critical' : 'notice',
            'object_type' => 'user',
            'object_id' => $user->ID,
            'object_label' => $user->display_name,
            'message' => sprintf(__('Compte « %1$s » créé (%2$s)', 'smbb-wpcodetool'), $user->user_login, self::roleNames((array) $user->roles)),
            'context' => array('email' => $user->user_email, 'roles' => array_values((array) $user->roles)),
        ));
    }

    public static function onUserUpdated($user_id, $old = null)
    {
        $user = get_userdata((int) $user_id);

        if (!$user || !$old instanceof \WP_User) {
            return;
        }

        $changes = array();
        $fields = array(
            'user_email' => __('E-mail', 'smbb-wpcodetool'),
            'display_name' => __('Nom affiché', 'smbb-wpcodetool'),
            'user_url' => __('Site web', 'smbb-wpcodetool'),
            'user_nicename' => __('Identifiant public', 'smbb-wpcodetool'),
        );

        foreach ($fields as $field => $label) {
            if ((string) $old->$field !== (string) $user->$field) {
                $changes[$label] = array(self::short($old->$field), self::short($user->$field));
            }
        }

        $password = (string) $old->user_pass !== (string) $user->user_pass;

        if (!$changes && !$password) {
            return;
        }

        $actor = get_current_user_id();
        $self = $actor === (int) $user_id;

        // Client qui modifie son propre compte : seuls l'e-mail et le mot de passe comptent.
        if ($self && self::isCustomer($user) && empty(self::settings()['customers'])) {
            if (!$password && !isset($changes[$fields['user_email']])) {
                return;
            }
        }

        if ($password) {
            $changes[__('Mot de passe', 'smbb-wpcodetool')] = array('', __('modifié', 'smbb-wpcodetool'));
        }

        self::log($password ? 'users.password_changed' : 'users.updated', array(
            'level' => $password || isset($changes[$fields['user_email']]) ? 'notice' : 'info',
            'object_type' => 'user',
            'object_id' => $user->ID,
            'object_label' => $user->display_name,
            'message' => $self
                ? sprintf(__('%s a modifié son compte', 'smbb-wpcodetool'), $user->user_login)
                : sprintf(__('Compte « %s » modifié', 'smbb-wpcodetool'), $user->user_login),
            'context' => array('changes' => $changes),
        ));
    }

    public static function onUserRole($user_id, $role, $old_roles = array())
    {
        $old_roles = array_values((array) $old_roles);

        // Rôle donné à la création : déjà dans « Compte créé ».
        if (!$old_roles || $old_roles === array($role)) {
            return;
        }

        $user = get_userdata((int) $user_id);

        if (!$user) {
            return;
        }

        $privileged = in_array($role, array('administrator', 'shop_manager', 'editor'), true);

        self::log('users.role_changed', array(
            'level' => $privileged ? 'warning' : 'notice',
            'object_type' => 'user',
            'object_id' => $user->ID,
            'object_label' => $user->display_name,
            'message' => sprintf(__('Rôle de « %1$s » : %2$s → %3$s', 'smbb-wpcodetool'), $user->user_login, self::roleNames($old_roles), self::roleNames(array($role))),
            'context' => array('changes' => array(__('Rôle', 'smbb-wpcodetool') => array(implode(', ', $old_roles), $role))),
        ));
    }

    public static function onUserRoleAdded($user_id, $role)
    {
        $user = get_userdata((int) $user_id);

        if (!$user || doing_action('user_register')) {
            return;
        }

        self::log('users.role_added', array(
            'level' => in_array($role, array('administrator', 'shop_manager'), true) ? 'warning' : 'notice',
            'object_type' => 'user',
            'object_id' => $user->ID,
            'object_label' => $user->display_name,
            'message' => sprintf(__('Rôle « %1$s » ajouté à « %2$s »', 'smbb-wpcodetool'), self::roleNames(array($role)), $user->user_login),
        ));
    }

    public static function onUserRoleRemoved($user_id, $role)
    {
        $user = get_userdata((int) $user_id);

        if (!$user) {
            return;
        }

        self::log('users.role_removed', array(
            'level' => 'notice',
            'object_type' => 'user',
            'object_id' => $user->ID,
            'object_label' => $user->display_name,
            'message' => sprintf(__('Rôle « %1$s » retiré à « %2$s »', 'smbb-wpcodetool'), self::roleNames(array($role)), $user->user_login),
        ));
    }

    public static function onUserDelete($user_id, $reassign = null)
    {
        $user = get_userdata((int) $user_id);

        if (!$user) {
            return;
        }

        $to = $reassign ? get_userdata((int) $reassign) : null;

        self::log('users.deleted', array(
            'level' => 'warning',
            'object_type' => 'user',
            'object_id' => $user->ID,
            'object_label' => $user->display_name,
            'message' => $to
                ? sprintf(__('Compte « %1$s » supprimé, contenus donnés à %2$s', 'smbb-wpcodetool'), $user->user_login, $to->user_login)
                : sprintf(__('Compte « %s » supprimé', 'smbb-wpcodetool'), $user->user_login),
            'context' => array('email' => $user->user_email, 'roles' => array_values((array) $user->roles)),
        ));
    }

    public static function onAppPasswordCreated($user_id, $item = array())
    {
        $user = get_userdata((int) $user_id);

        self::log('users.app_password_created', array(
            'level' => 'warning',
            'object_type' => 'user',
            'object_id' => (int) $user_id,
            'object_label' => $user ? $user->display_name : '',
            'message' => sprintf(__('Mot de passe d\'application « %1$s » créé pour %2$s', 'smbb-wpcodetool'), isset($item['name']) ? $item['name'] : '', $user ? $user->user_login : '#' . $user_id),
        ));
    }

    public static function onAppPasswordDeleted($user_id, $item = array())
    {
        $user = get_userdata((int) $user_id);

        self::log('users.app_password_deleted', array(
            'level' => 'notice',
            'object_type' => 'user',
            'object_id' => (int) $user_id,
            'object_label' => $user ? $user->display_name : '',
            'message' => sprintf(__('Mot de passe d\'application « %1$s » révoqué pour %2$s', 'smbb-wpcodetool'), isset($item['name']) ? $item['name'] : '', $user ? $user->user_login : '#' . $user_id),
        ));
    }

    public static function onSuperAdminGranted($user_id)
    {
        $user = get_userdata((int) $user_id);

        self::log('users.super_admin_granted', array(
            'level' => 'critical',
            'object_type' => 'user',
            'object_id' => (int) $user_id,
            'object_label' => $user ? $user->display_name : '',
            'message' => sprintf(__('Droits de super administrateur donnés à %s', 'smbb-wpcodetool'), $user ? $user->user_login : '#' . $user_id),
        ));
    }

    public static function onSuperAdminRevoked($user_id)
    {
        $user = get_userdata((int) $user_id);

        self::log('users.super_admin_revoked', array(
            'level' => 'warning',
            'object_type' => 'user',
            'object_id' => (int) $user_id,
            'object_label' => $user ? $user->display_name : '',
            'message' => sprintf(__('Droits de super administrateur retirés à %s', 'smbb-wpcodetool'), $user ? $user->user_login : '#' . $user_id),
        ));
    }

    private static function roleName($id)
    {
        $names = wp_roles()->get_names();

        return isset($names[$id]) ? translate_user_role($names[$id]) : (string) $id;
    }

    public static function onRoleCreated($id, $from = '')
    {
        self::log('users.role_created', array(
            'level' => 'notice',
            'object_type' => 'role',
            'object_id' => $id,
            'object_label' => self::roleName($id),
            'message' => $from !== ''
                ? sprintf(__('Rôle « %1$s » créé (copie de %2$s)', 'smbb-wpcodetool'), self::roleName($id), self::roleName($from))
                : sprintf(__('Rôle « %s » créé', 'smbb-wpcodetool'), self::roleName($id)),
            'source' => 'codetool-roles',
        ));
    }

    public static function onRoleRenamed($id, $name)
    {
        self::log('users.role_renamed', array(
            'object_type' => 'role',
            'object_id' => $id,
            'object_label' => (string) $name,
            'message' => sprintf(__('Rôle %1$s renommé « %2$s »', 'smbb-wpcodetool'), $id, $name),
            'source' => 'codetool-roles',
        ));
    }

    public static function onRoleIdRenamed($old, $new, $count = 0)
    {
        self::log('users.role_id_renamed', array(
            'level' => 'notice',
            'object_type' => 'role',
            'object_id' => $new,
            'object_label' => self::roleName($new),
            'message' => sprintf(_n('Identifiant du rôle changé : %1$s → %2$s (%3$d compte basculé)', 'Identifiant du rôle changé : %1$s → %2$s (%3$d comptes basculés)', (int) $count, 'smbb-wpcodetool'), $old, $new, (int) $count),
            'source' => 'codetool-roles',
        ));
    }

    public static function onRoleDeleted($id, $reassign = '', $moved = 0)
    {
        self::log('users.role_deleted', array(
            'level' => 'warning',
            'object_type' => 'role',
            'object_id' => $id,
            'object_label' => (string) $id,
            'message' => $moved
                ? sprintf(_n('Rôle %1$s supprimé, %2$d compte basculé vers %3$s', 'Rôle %1$s supprimé, %2$d comptes basculés vers %3$s', (int) $moved, 'smbb-wpcodetool'), $id, (int) $moved, self::roleName($reassign))
                : sprintf(__('Rôle %s supprimé', 'smbb-wpcodetool'), $id),
            'source' => 'codetool-roles',
        ));
    }

    public static function onRoleCaps($id, $added = array(), $removed = array())
    {
        $added = array_values((array) $added);
        $removed = array_values((array) $removed);
        $risky = array_intersect($added, array('manage_options', 'activate_plugins', 'install_plugins', 'edit_plugins', 'edit_users', 'promote_users', 'delete_users', 'unfiltered_html', 'edit_themes', 'update_core'));

        self::log('users.role_caps_changed', array(
            'level' => $risky ? 'warning' : 'notice',
            'object_type' => 'role',
            'object_id' => $id,
            'object_label' => self::roleName($id),
            'message' => sprintf(__('Droits du rôle « %1$s » modifiés : %2$d ajouté(s), %3$d retiré(s)', 'smbb-wpcodetool'), self::roleName($id), count($added), count($removed)),
            'context' => array('added' => $added, 'removed' => $removed),
            'source' => 'codetool-roles',
        ));
    }

    // ------------------------------------------------------------------ Réglages

    /**
     * Options du cœur suivies : nom => libellé.
     */
    private static function coreOptions()
    {
        return array(
            'blogname' => __('Titre du site', 'smbb-wpcodetool'),
            'blogdescription' => __('Slogan', 'smbb-wpcodetool'),
            'siteurl' => __('Adresse de WordPress', 'smbb-wpcodetool'),
            'home' => __('Adresse du site', 'smbb-wpcodetool'),
            'admin_email' => __('E-mail d\'administration', 'smbb-wpcodetool'),
            'new_admin_email' => __('Nouvel e-mail d\'administration (à confirmer)', 'smbb-wpcodetool'),
            'users_can_register' => __('Inscription ouverte à tous', 'smbb-wpcodetool'),
            'default_role' => __('Rôle par défaut des nouveaux comptes', 'smbb-wpcodetool'),
            'WPLANG' => __('Langue du site', 'smbb-wpcodetool'),
            'timezone_string' => __('Fuseau horaire', 'smbb-wpcodetool'),
            'gmt_offset' => __('Décalage horaire', 'smbb-wpcodetool'),
            'date_format' => __('Format de date', 'smbb-wpcodetool'),
            'time_format' => __('Format d\'heure', 'smbb-wpcodetool'),
            'start_of_week' => __('Premier jour de la semaine', 'smbb-wpcodetool'),
            'show_on_front' => __('Page d\'accueil affiche', 'smbb-wpcodetool'),
            'page_on_front' => __('Page d\'accueil', 'smbb-wpcodetool'),
            'page_for_posts' => __('Page des articles', 'smbb-wpcodetool'),
            'posts_per_page' => __('Articles par page', 'smbb-wpcodetool'),
            'blog_public' => __('Visible des moteurs de recherche', 'smbb-wpcodetool'),
            'permalink_structure' => __('Structure des permaliens', 'smbb-wpcodetool'),
            'category_base' => __('Préfixe des catégories', 'smbb-wpcodetool'),
            'tag_base' => __('Préfixe des étiquettes', 'smbb-wpcodetool'),
            'default_comment_status' => __('Commentaires autorisés par défaut', 'smbb-wpcodetool'),
            'comment_moderation' => __('Modération des commentaires', 'smbb-wpcodetool'),
            'comment_registration' => __('Commentaires réservés aux inscrits', 'smbb-wpcodetool'),
            'wp_page_for_privacy_policy' => __('Page de confidentialité', 'smbb-wpcodetool'),
            'template' => __('Thème parent', 'smbb-wpcodetool'),
            'stylesheet' => __('Thème', 'smbb-wpcodetool'),
            'active_plugins' => '',
        );
    }

    /**
     * Option suivie ? Libellé ('' = suivie sans libellé propre), ou null si ignorée.
     */
    private static function optionLabel($option)
    {
        $option = (string) $option;
        $core = self::coreOptions();

        if (isset($core[$option])) {
            // active_plugins / thème : déjà notés par les événements d'extensions.
            return in_array($option, array('active_plugins', 'template', 'stylesheet'), true) ? null : $core[$option];
        }

        // Bruit technique : caches, verrous, versions de schéma, compteurs, historiques, files d'attente...
        if (preg_match('/(^_|transient|_db(_version)?$|_version$|_db_|cache|_lock$|lock_|history|_health|_last_|_queue|_flash|_stats|_count$|cron|_dir$|_origin$|_seen|_notice|dismiss|_import$|_tracking_|session|_state$|_nonce|_hash$|_rewrite|rewrite_rules|_meta_box|wizard|task_list|_ces_|onboarding|marketplace|_tracker|tracking|_usage|inbox|remote_|jetpack|_feature|_notes|_milestone|_activated|_installed|_variant)/i', $option)) {
            return null;
        }

        $known = array(
            'smbb_codetool_mail' => __('CodeTool › E-mails', 'smbb-wpcodetool'),
            'smbb_codetool_sms' => __('CodeTool › SMS', 'smbb-wpcodetool'),
            'smbb_codetool_appearance' => __('CodeTool › Apparence', 'smbb-wpcodetool'),
            'smbb_codetool_content' => __('CodeTool › Contenu WP', 'smbb-wpcodetool'),
            'smbb_codetool_woocommerce' => __('CodeTool › WooCommerce', 'smbb-wpcodetool'),
            'smbb_codetool_security' => __('CodeTool › Sécurité', 'smbb-wpcodetool'),
            'smbb_codetool_custom_caps' => __('CodeTool › Droits personnalisés', 'smbb-wpcodetool'),
            'smbb_passflow_settings' => __('Passflow › Réglages', 'smbb-wpcodetool'),
        );
        $prefixes = (array) apply_filters('smbb_wpcodetool_activity_option_prefixes', array('smbb_', 'woocommerce_', 'wc_settings_'));

        foreach ($prefixes as $prefix) {
            if ($prefix !== '' && strpos($option, (string) $prefix) === 0) {
                return (string) apply_filters('smbb_wpcodetool_activity_option_label', isset($known[$option]) ? $known[$option] : '', $option);
            }
        }

        return null;
    }

    public static function onOptionUpdated($option, $old, $new)
    {
        // Seulement les changements faits par une personne (pas les mises à jour automatiques des plugins).
        if (get_current_user_id() <= 0 || (function_exists('wp_doing_cron') && wp_doing_cron()) || !self::isWriteRequest()) {
            return;
        }

        if ($option === ActivityLog::OPTION || self::optionLabel($option) === null || maybe_serialize($old) === maybe_serialize($new)) {
            return;
        }

        if (isset(self::$options[$option])) {
            self::$options[$option][1] = $new;
        } else {
            self::$options[$option] = array($old, $new);
        }
    }

    /**
     * Requête d'enregistrement (formulaire, REST en écriture) : les plugins qui rangent leurs
     * compteurs ou notices en option pendant un simple affichage de page ne sont pas notés.
     */
    private static function isWriteRequest()
    {
        $method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper((string) $_SERVER['REQUEST_METHOD']) : 'GET'; // phpcs:ignore

        if (!in_array($method, array('POST', 'PUT', 'PATCH', 'DELETE'), true)) {
            return false;
        }

        // Battement de cœur de l'admin et autres appels automatiques.
        $action = isset($_REQUEST['action']) ? sanitize_key(wp_unslash($_REQUEST['action'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification

        return !in_array($action, array('heartbeat', 'wp-remove-post-lock', 'closed-postboxes', 'meta-box-order', 'dismiss-wp-pointer', 'wp-compression-test'), true);
    }

    public static function onOptionAdded($option, $value)
    {
        self::onOptionUpdated($option, null, $value);
    }

    /**
     * Différences d'un réglage : [champ => [avant, après]] (tableaux aplatis sur 3 niveaux).
     */
    private static function optionDiff($old, $new, $prefix = '', $depth = 0)
    {
        $old = maybe_unserialize($old);
        $new = maybe_unserialize($new);

        if ((is_array($old) || is_array($new)) && $depth < 3) {
            $old = is_array($old) ? $old : array();
            $new = is_array($new) ? $new : array();
            $out = array();

            foreach (array_unique(array_merge(array_keys($old), array_keys($new))) as $key) {
                $a = array_key_exists($key, $old) ? $old[$key] : null;
                $b = array_key_exists($key, $new) ? $new[$key] : null;

                if (maybe_serialize($a) === maybe_serialize($b)) {
                    continue;
                }

                $out += self::optionDiff($a, $b, $prefix === '' ? (string) $key : $prefix . '.' . $key, $depth + 1);
            }

            return $out;
        }

        $field = $prefix === '' ? 'valeur' : $prefix;

        return array($field => array($old === null ? '' : self::short($old, 120), $new === null ? '' : self::short($new, 120)));
    }

    private static function flushOptions()
    {
        if (!self::$options) {
            return;
        }

        $labels = array();
        $changes = array();

        foreach (self::$options as $option => $pair) {
            $label = (string) self::optionLabel($option);
            $labels[] = $label !== '' ? $label : $option;
            $diff = self::optionDiff($pair[0], $pair[1]);

            foreach (array_slice($diff, 0, 40, true) as $field => $values) {
                // Clé secrète dans le nom de l'option ou du champ : jamais de valeur dans le journal.
                if (ActivityLog::isSecretKey($option) || ActivityLog::isSecretKey($field)) {
                    $values = array('••••••', '••••••');
                }

                $changes[($label !== '' ? $label : $option) . ($field !== 'valeur' ? ' › ' . $field : '')] = $values;
            }

            if (count($diff) > 40) {
                $changes[$option . ' › …'] = array('', sprintf(__('+%d autres champs', 'smbb-wpcodetool'), count($diff) - 40));
            }
        }

        $shown = array_slice($labels, 0, 3);
        $more = count($labels) - count($shown);
        $page = isset($_REQUEST['page']) ? sanitize_key(wp_unslash($_REQUEST['page'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification

        self::log('settings.updated', array(
            'level' => array_intersect(array_keys(self::$options), array('siteurl', 'home', 'admin_email', 'users_can_register', 'default_role')) ? 'warning' : 'notice',
            'object_type' => 'option',
            'object_id' => count(self::$options) === 1 ? (string) key(self::$options) : '',
            'object_label' => $page,
            'message' => $more > 0
                ? sprintf(__('Réglages modifiés : %1$s (+%2$d)', 'smbb-wpcodetool'), implode(', ', $shown), $more)
                : sprintf(__('Réglages modifiés : %s', 'smbb-wpcodetool'), implode(', ', $shown)),
            'context' => array('options' => array_keys(self::$options), 'changes' => $changes),
        ));
    }

    // ------------------------------------------------------------------ Commandes WooCommerce

    private static function ordersTracked()
    {
        return !empty(self::settings()['orders_all']) || current_user_can('edit_shop_orders');
    }

    private static function &orderBucket($order_id)
    {
        if (!isset(self::$orders[$order_id])) {
            self::$orders[$order_id] = array('status' => null, 'changes' => array(), 'refunds' => array(), 'user' => get_current_user_id());
        }

        return self::$orders[$order_id];
    }

    private static function orderNumber($order)
    {
        return is_object($order) && method_exists($order, 'get_order_number') ? (string) $order->get_order_number() : '';
    }

    private static function orderStatusLabel($status)
    {
        if (function_exists('wc_get_order_status_name')) {
            return wc_get_order_status_name($status);
        }

        return (string) $status;
    }

    public static function onOrderStatus($order_id, $from, $to, $order = null)
    {
        if (!self::ordersTracked()) {
            return;
        }

        $bucket = &self::orderBucket((int) $order_id);

        if ($bucket['status'] === null) {
            $bucket['status'] = array((string) $from, (string) $to);
        } else {
            $bucket['status'][1] = (string) $to;
        }
    }

    /**
     * Champs modifiés par l'équipe dans l'écran de la commande (adresse, lignes, total...).
     * Les adresses sont des données personnelles : on note le champ, pas la valeur.
     */
    public static function onOrderSave($order)
    {
        if (!is_object($order) || !method_exists($order, 'get_changes') || !is_admin() || !current_user_can('edit_shop_orders')
            || !method_exists($order, 'get_id') || !$order->get_id() || (method_exists($order, 'get_type') && $order->get_type() !== 'shop_order')) {
            return;
        }

        $changes = (array) $order->get_changes();
        unset($changes['status'], $changes['date_modified'], $changes['version'], $changes['cart_hash'], $changes['date_paid'], $changes['date_completed']);

        if (!$changes) {
            return;
        }

        $out = array();

        foreach ($changes as $key => $value) {
            if (in_array($key, array('billing', 'shipping'), true) && is_array($value)) {
                $label = $key === 'billing' ? __('Facturation', 'smbb-wpcodetool') : __('Livraison', 'smbb-wpcodetool');

                foreach (array_keys($value) as $field) {
                    $out[$label . ' › ' . $field] = array('', __('modifié', 'smbb-wpcodetool'));
                }

                continue;
            }

            $before = '';

            // Valeur d'origine : get_data() garde les valeurs lues en base.
            if (method_exists($order, 'get_data')) {
                $data = $order->get_data();
                $before = isset($data[$key]) && !is_array($data[$key]) ? self::short($data[$key], 80) : '';
            }

            $out[$key] = array($before === self::short($value, 80) ? '' : $before, self::short(is_object($value) && method_exists($value, 'date') ? $value->date('Y-m-d H:i') : $value, 80));
        }

        $bucket = &self::orderBucket((int) $order->get_id());
        $bucket['changes'] = array_merge($bucket['changes'], $out);
    }

    public static function onOrderRefunded($order_id, $refund_id)
    {
        $refund = function_exists('wc_get_order') ? wc_get_order((int) $refund_id) : null;
        $amount = $refund && method_exists($refund, 'get_amount') ? (float) $refund->get_amount() : 0.0;
        $currency = $refund && method_exists($refund, 'get_currency') ? $refund->get_currency() : '';
        $bucket = &self::orderBucket((int) $order_id);
        $bucket['refunds'][] = array(
            'amount' => function_exists('wc_price') ? html_entity_decode(wp_strip_all_tags(wc_price($amount, array('currency' => $currency)))) : (string) $amount,
            'reason' => $refund && method_exists($refund, 'get_reason') ? (string) $refund->get_reason() : '',
        );
    }

    private static function flushOrder($order_id, array $bucket)
    {
        $order = function_exists('wc_get_order') ? wc_get_order((int) $order_id) : null;
        $number = $order ? self::orderNumber($order) : (string) $order_id;
        $changes = $bucket['changes'];
        $level = 'info';
        $parts = array();
        $event = 'woocommerce.order_updated';

        if ($bucket['status'] !== null) {
            list($from, $to) = $bucket['status'];
            $changes = array(__('Statut', 'smbb-wpcodetool') => array(self::orderStatusLabel($from), self::orderStatusLabel($to))) + $changes;
            $parts[] = sprintf(__('statut %1$s → %2$s', 'smbb-wpcodetool'), self::orderStatusLabel($from), self::orderStatusLabel($to));
            $event = 'woocommerce.order_status';
            $level = in_array($to, array('cancelled', 'refunded', 'failed'), true) ? 'notice' : 'info';
        }

        foreach ($bucket['refunds'] as $refund) {
            $parts[] = $refund['reason'] !== ''
                ? sprintf(__('remboursement de %1$s (%2$s)', 'smbb-wpcodetool'), $refund['amount'], $refund['reason'])
                : sprintf(__('remboursement de %s', 'smbb-wpcodetool'), $refund['amount']);
            $event = 'woocommerce.order_refunded';
            $level = 'warning';
        }

        if (!$parts && !$changes) {
            return;
        }

        if (!$parts) {
            $parts[] = sprintf(_n('%d champ modifié', '%d champs modifiés', count($changes), 'smbb-wpcodetool'), count($changes));
        }

        self::log($event, array(
            'category' => 'woocommerce',
            'level' => $level,
            'user_id' => $bucket['user'],
            'object_type' => 'order',
            'object_id' => (int) $order_id,
            'object_label' => sprintf(__('Commande n°%s', 'smbb-wpcodetool'), $number),
            'message' => sprintf(__('Commande n°%1$s : %2$s', 'smbb-wpcodetool'), $number, implode(', ', $parts)),
            'context' => array('changes' => $changes, 'refunds' => $bucket['refunds']),
        ));
    }

    public static function onOrderTrashed($order_id)
    {
        $order = function_exists('wc_get_order') ? wc_get_order((int) $order_id) : null;
        $number = $order ? self::orderNumber($order) : (string) $order_id;

        self::log('woocommerce.order_trashed', array(
            'category' => 'woocommerce',
            'level' => 'warning',
            'object_type' => 'order',
            'object_id' => (int) $order_id,
            'object_label' => sprintf(__('Commande n°%s', 'smbb-wpcodetool'), $number),
            'message' => sprintf(__('Commande n°%s mise à la corbeille', 'smbb-wpcodetool'), $number),
        ));
    }

    public static function onOrderUntrashed($order_id, $previous = '')
    {
        self::log('woocommerce.order_restored', array(
            'category' => 'woocommerce',
            'object_type' => 'order',
            'object_id' => (int) $order_id,
            'object_label' => sprintf(__('Commande n°%s', 'smbb-wpcodetool'), $order_id),
            'message' => sprintf(__('Commande n°%s sortie de la corbeille', 'smbb-wpcodetool'), $order_id),
        ));
    }

    public static function onOrderDelete($order_id, $order = null)
    {
        self::orderDeleted((int) $order_id, $order);
    }

    private static function orderDeleted($order_id, $order)
    {
        if (!$order && function_exists('wc_get_order')) {
            $order = wc_get_order($order_id);
        }

        if ($order && method_exists($order, 'get_type') && $order->get_type() !== 'shop_order') {
            return;
        }

        $number = $order ? self::orderNumber($order) : (string) $order_id;
        unset(self::$orders[$order_id]);

        self::log('woocommerce.order_deleted', array(
            'category' => 'woocommerce',
            'level' => 'critical',
            'object_type' => 'order',
            'object_id' => $order_id,
            'object_label' => sprintf(__('Commande n°%s', 'smbb-wpcodetool'), $number),
            'message' => sprintf(__('Commande n°%s supprimée définitivement', 'smbb-wpcodetool'), $number),
            'context' => $order && method_exists($order, 'get_total') ? array('total' => (string) $order->get_total(), 'status' => (string) $order->get_status()) : array(),
        ));
    }
}
