<?php

namespace Smbb\Passflow\Service;

use Smbb\Passflow\Repository\Lookup;
use Smbb\Passflow\Support\Input;
use Smbb\Passflow\Support\Roles;
use Smbb\WpCodeTool\Support\UserLookup;

defined('ABSPATH') || exit;

/**
 * Un intervenant = un compte WordPress. Nom et e-mail viennent du compte, le téléphone est celui du compte
 * (billing_phone) : les colonnes de passflow_host n'en sont qu'une copie pour les listes et les envois.
 *
 * - recherche de comptes (champ CodeTool search « wp_user ») ouverte aux gestionnaires Passflow ;
 * - création d'un compte depuis la fiche intervenant (Ajax) ;
 * - recopie quand le compte change (profil, prénom, nom, e-mail, téléphone).
 */
final class Accounts
{
    const AJAX_CREATE = 'passflow_create_account';
    const PHONE_META = 'billing_phone';

    public static function hooks()
    {
        add_filter('smbb_wpcodetool_user_lookup_allowed', array(__CLASS__, 'lookupAllowed'));
        add_action('wp_ajax_' . self::AJAX_CREATE, array(__CLASS__, 'ajaxCreate'));
        add_action('profile_update', array(__CLASS__, 'sync'));
        add_action('updated_user_meta', array(__CLASS__, 'metaChanged'), 10, 4);
        add_action('added_user_meta', array(__CLASS__, 'metaChanged'), 10, 4);
    }

    public static function lookupAllowed($allowed)
    {
        return $allowed || Roles::canManage();
    }

    /**
     * Données d'identité tirées du compte : first_name, last_name, email, phone.
     *
     * @return array<string,string>|null
     */
    public static function identity($user_id)
    {
        $user = get_userdata((int) $user_id);

        if (!$user) {
            return null;
        }

        $first = trim((string) $user->first_name);
        $last = trim((string) $user->last_name);

        if ($first === '' && $last === '') {
            $last = (string) $user->display_name;
        }

        return array(
            'first_name' => $first,
            'last_name' => $last,
            'email' => strtolower((string) $user->user_email),
            'phone' => class_exists(UserLookup::class) ? UserLookup::phone($user->ID) : (string) get_user_meta($user->ID, self::PHONE_META, true),
        );
    }

    /**
     * Recopie l'identité du compte sur la fiche intervenant qui lui est rattachée.
     */
    public static function sync($user_id)
    {
        global $wpdb;

        $identity = self::identity($user_id);

        if (!$identity || !\Smbb\Passflow\Support\Schema::ready()) {
            return;
        }

        $updated = $wpdb->update(Lookup::table('passflow_host'), $identity, array('user_id' => (int) $user_id, 'deleted_flag' => 0));

        if ($updated) {
            Lookup::flush();
        }
    }

    /**
     * Filet de sécurité (tâche planifiée) : recopie l'identité de tous les comptes rattachés, pour le cas
     * où un compte aurait été modifié sans passer par WordPress (import, requête SQL directe…).
     */
    public static function syncAll()
    {
        if (!\Smbb\Passflow\Support\Schema::ready()) {
            return;
        }

        foreach (Lookup::intervenants() as $row) {
            if (empty($row['user_id'])) {
                continue;
            }

            $identity = self::identity($row['user_id']);

            if ($identity && array_intersect_key($row, $identity) != $identity) {
                self::sync($row['user_id']);
            }
        }
    }

    public static function metaChanged($meta_id, $user_id, $key, $value = null)
    {
        if (in_array($key, array('first_name', 'last_name', self::PHONE_META), true)) {
            self::sync($user_id);
        }
    }

    /**
     * Enregistre le téléphone sur le compte (une seule source).
     */
    public static function savePhone($user_id, $phone)
    {
        if ((int) $user_id && get_userdata((int) $user_id)) {
            update_user_meta((int) $user_id, self::PHONE_META, (string) $phone);
        }
    }

    /**
     * Crée un compte « Intervenant Passflow » (ou reprend celui qui a déjà cet e-mail), puis renvoie
     * l'élément à placer dans le champ de recherche.
     */
    public static function ajaxCreate()
    {
        check_ajax_referer(self::AJAX_CREATE, 'nonce');

        if (!Roles::canManage()) {
            wp_send_json_error(array('message' => __('Action non autorisée.', 'smbb-passflow')), 403);
        }

        $email = isset($_POST['email']) ? strtolower(sanitize_email(wp_unslash($_POST['email']))) : '';
        $first = isset($_POST['first_name']) ? sanitize_text_field(wp_unslash($_POST['first_name'])) : '';
        $last = isset($_POST['last_name']) ? sanitize_text_field(wp_unslash($_POST['last_name'])) : '';
        $phone = isset($_POST['phone']) ? Input::phone(sanitize_text_field(wp_unslash($_POST['phone']))) : '';

        if (!is_email($email)) {
            wp_send_json_error(array('message' => __('Adresse e-mail invalide.', 'smbb-passflow')));
        }

        if ($last === '' && $first === '') {
            wp_send_json_error(array('message' => __('Indiquez au moins le nom.', 'smbb-passflow')));
        }

        $user_id = email_exists($email);

        if ($user_id) {
            $message = __('Un compte existait déjà avec cet e-mail : il est sélectionné.', 'smbb-passflow');
        } else {
            $base = sanitize_user(strstr($email, '@', true), true) ?: 'intervenant';
            $login = $base;
            $n = 1;

            while (username_exists($login)) {
                $login = $base . (++$n);
            }

            $user_id = wp_insert_user(array(
                'user_login' => $login,
                'user_email' => $email,
                'user_pass' => wp_generate_password(24),
                'first_name' => $first,
                'last_name' => $last,
                'display_name' => trim($first . ' ' . $last),
                'role' => Roles::ROLE_HOST,
            ));

            if (is_wp_error($user_id)) {
                wp_send_json_error(array('message' => $user_id->get_error_message()));
            }

            wp_new_user_notification((int) $user_id, null, 'user');
            $message = sprintf(__('Compte créé : %s a reçu un e-mail pour choisir son mot de passe.', 'smbb-passflow'), $email);
        }

        if ($phone !== '' && !get_user_meta((int) $user_id, self::PHONE_META, true)) {
            update_user_meta((int) $user_id, self::PHONE_META, $phone);
        }

        $item = class_exists(UserLookup::class) ? UserLookup::item((int) $user_id) : array('value' => (string) $user_id, 'label' => trim($first . ' ' . $last), 'meta' => $email);

        wp_send_json_success(array('item' => $item, 'message' => $message));
    }

    /**
     * Compte client d'une vente sur place ou d'un imprévu : repris s'il existe avec cet e-mail, sinon créé
     * (rôle client WooCommerce, coordonnées de facturation) et la personne reçoit un lien pour choisir
     * son mot de passe (message « Compte client créé », mode test respecté).
     *
     * @return array{id:int,created:bool}|\WP_Error
     */
    public static function customer($email, $first = '', $last = '', $phone = '', $slot = null)
    {
        $d = 'smbb-passflow';
        $email = strtolower(sanitize_email((string) $email));

        if ($email === '' || !is_email($email)) {
            return new \WP_Error('passflow_email', __("Un e-mail valide est nécessaire pour créer le compte client.", $d));
        }

        $existing = (int) email_exists($email);

        if ($existing) {
            return array('id' => $existing, 'created' => false);
        }

        $base = sanitize_user(strstr($email, '@', true), true) ?: 'client';
        $login = $base;
        $n = 1;

        while (username_exists($login)) {
            $login = $base . (++$n);
        }

        $user_id = wp_insert_user(array(
            'user_login' => $login,
            'user_email' => $email,
            'user_pass' => wp_generate_password(24),
            'first_name' => $first,
            'last_name' => $last,
            'display_name' => trim($first . ' ' . $last) ?: $login,
            'role' => get_role('customer') ? 'customer' : get_option('default_role', 'subscriber'),
        ));

        if (is_wp_error($user_id)) {
            return $user_id;
        }

        foreach (array('billing_first_name' => $first, 'billing_last_name' => $last, 'billing_email' => $email, 'billing_phone' => $phone) as $key => $value) {
            if ((string) $value !== '') {
                update_user_meta((int) $user_id, $key, $value);
            }
        }

        $user = get_userdata((int) $user_id);
        $key = $user ? get_password_reset_key($user) : '';

        if ($user && !is_wp_error($key)) {
            // Page « mot de passe » de WooCommerce (Mon compte) si elle existe, sinon celle de WordPress.
            $myaccount = function_exists('wc_get_page_permalink') ? wc_get_page_permalink('myaccount') : '';
            $url = $myaccount && function_exists('wc_get_endpoint_url')
                ? add_query_arg(array('key' => $key, 'id' => (int) $user_id), wc_get_endpoint_url('lost-password', '', $myaccount))
                : network_site_url('wp-login.php?action=rp&key=' . rawurlencode($key) . '&login=' . rawurlencode($user->user_login), 'login');

            Notifier::toCustomer('account_created', array('first_name' => $first, 'last_name' => $last, 'email' => $email, 'phone' => $phone), $slot, array(
                'lien_mot_de_passe' => $url,
                'identifiant' => $email,
                'lien_compte' => $myaccount ?: wp_login_url(),
            ), null);
        }

        return array('id' => (int) $user_id, 'created' => true);
    }
}
