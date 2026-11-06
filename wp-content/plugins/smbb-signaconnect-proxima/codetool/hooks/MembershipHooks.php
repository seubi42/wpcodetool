<?php

namespace Smbb\SignConnectProxima\CodeTool;

use Smbb\SignConnectProxima\Repository\AuditLog;
use Smbb\SignConnectProxima\Repository\Lookup;
use Smbb\SignConnectProxima\Support\Choices;
use Smbb\SignConnectProxima\Support\Input;

defined('ABSPATH') || exit;

final class MembershipHooks
{
    /** Compte WordPress créé pendant cet enregistrement (pour le journal). */
    private static $created_user_id = 0;

    public function beforeValidate(array $data, array $context = array())
    {
        $data = Input::stripManaged($data);
        $data = $this->readNewUserFields($data, $context);
        $data = Input::checkboxList($data, 'team_ids', array_keys(Lookup::teams()));
        $data = Input::nullableIds($data, array('plan_id'));
        unset($data['active_since'], $data['inactive_since']);

        $current = isset($context['current']) && is_array($context['current']) ? $context['current'] : array();

        // L'utilisateur et l'organisation d'un rattachement ne changent pas : on crée une autre ligne.
        foreach (array('user_id', 'organization_id') as $key) {
            if (!empty($current[$key])) {
                $data[$key] = $current[$key];
            }
        }

        // Sans forfait choisi, on applique le forfait par défaut de l'organisation.
        if (empty($data['plan_id']) && !empty($data['organization_id'])) {
            $organizations = Lookup::organizations();
            $org_id = (int) $data['organization_id'];

            if (!empty($organizations[$org_id]['default_plan_id'])) {
                $data['plan_id'] = (string) $organizations[$org_id]['default_plan_id'];
            }
        }

        return $data;
    }

    public function validate(array $data, array $context = array())
    {
        global $wpdb;

        $errors = array();
        $user_id = isset($data['user_id']) ? (int) $data['user_id'] : 0;
        $org_id = isset($data['organization_id']) ? (int) $data['organization_id'] : 0;

        $new_user = $this->wantsNewUser($data);

        if ($new_user) {
            $errors = array_merge($errors, $this->newUserErrors($data));
        } elseif ($user_id < 1 || !get_userdata($user_id)) {
            $errors['user_id'] = __('Choisissez un utilisateur WordPress, ou activez « Créer un nouveau compte ».', 'smbb-signaconnect-proxima');
        }

        if ($org_id < 1 || !array_key_exists($org_id, Lookup::organizations())) {
            $errors['organization_id'] = __('Choisissez une organisation.', 'smbb-signaconnect-proxima');
        }

        if (!$new_user && $user_id > 0 && $org_id > 0) {
            $exists = (int) $wpdb->get_var($wpdb->prepare(
                'SELECT COUNT(*) FROM ' . Lookup::table('scp_membership') . ' WHERE deleted_flag = 0 AND user_id = %d AND organization_id = %d AND id <> %d',
                $user_id,
                $org_id,
                isset($context['id']) ? (int) $context['id'] : 0
            ));

            if ($exists > 0) {
                $errors['organization_id'] = __('Cet utilisateur a déjà une ligne pour cette organisation.', 'smbb-signaconnect-proxima');
            }
        }

        if (empty($data['plan_id'])) {
            $errors['plan_id'] = __("Choisissez un forfait (ou définissez un forfait par défaut sur l'organisation).", 'smbb-signaconnect-proxima');
        } elseif (!array_key_exists((int) $data['plan_id'], Lookup::plans())) {
            $errors['plan_id'] = __('Forfait introuvable.', 'smbb-signaconnect-proxima');
        }

        $team_ids = isset($data['team_ids']) && is_array($data['team_ids']) ? $data['team_ids'] : array();
        $teams = Lookup::teams();

        if (!$team_ids) {
            $errors['team_ids'] = __('Choisissez au moins une équipe.', 'smbb-signaconnect-proxima');
        }

        foreach ($team_ids as $team_id) {
            if (!isset($teams[(int) $team_id]) || (int) $teams[(int) $team_id]['organization_id'] !== $org_id) {
                $errors['team_ids'] = __("Les équipes doivent appartenir à l'organisation choisie.", 'smbb-signaconnect-proxima');
                break;
            }
        }

        if (isset($data['role']) && !array_key_exists($data['role'], Choices::roles())) {
            $errors['role'] = __('Rôle inconnu.', 'smbb-signaconnect-proxima');
        }

        return $errors;
    }

    public function beforeSave(array $data, array $context = array())
    {
        self::$created_user_id = 0;

        if ($this->wantsNewUser($data)) {
            $data['user_id'] = (string) $this->createWordPressUser($data);
        }

        if (isset($data['team_ids']) && is_array($data['team_ids'])) {
            $data['team_ids'] = wp_json_encode(array_values(array_map('strval', $data['team_ids'])));
        }

        $is_create = isset($context['action']) && $context['action'] === 'create';
        $current = isset($context['current']) && is_array($context['current']) ? $context['current'] : array();

        if ($is_create) {
            $data['active_since'] = current_time('mysql');
        }

        // Désactivation : le mois entamé reste dû, on note donc la date pour la facturation.
        if (array_key_exists('is_active', $data)) {
            $was_active = $is_create || !isset($current['is_active']) || (int) $current['is_active'] === 1;

            if ((int) $data['is_active'] === 0 && $was_active) {
                $data['inactive_since'] = current_time('mysql');
            } elseif ((int) $data['is_active'] === 1) {
                $data['inactive_since'] = null;
            }
        }

        return $data;
    }

    public function afterSave(array $row, array $context = array())
    {
        $current = isset($context['current']) && is_array($context['current']) ? $context['current'] : array();
        $changes = array();

        foreach (array('plan_id', 'team_ids', 'role', 'can_manage_templates', 'is_active') as $key) {
            $before = isset($current[$key]) ? $current[$key] : null;
            $after = isset($row[$key]) ? $row[$key] : null;

            if (wp_json_encode($before) !== wp_json_encode($after)) {
                $changes[$key] = array('avant' => $before, 'après' => $after);
            }
        }

        $is_create = isset($context['action']) && $context['action'] === 'create';

        if (!$is_create && !$changes) {
            return;
        }

        if ($is_create && self::$created_user_id > 0) {
            $changes['compte_wordpress'] = __('créé et invité par email', 'smbb-signaconnect-proxima');
        }

        $message = $is_create
            ? (self::$created_user_id > 0
                ? __('Compte WordPress créé et utilisateur rattaché à l\'organisation.', 'smbb-signaconnect-proxima')
                : __('Utilisateur rattaché à l\'organisation.', 'smbb-signaconnect-proxima'))
            : (isset($changes['plan_id'])
                ? __('Forfait changé (appliqué à tout le mois en cours).', 'smbb-signaconnect-proxima')
                : __('Rattachement modifié.', 'smbb-signaconnect-proxima'));

        AuditLog::record(
            $is_create ? 'membership_created' : 'membership_updated',
            $message,
            array_merge(array('user_id' => isset($row['user_id']) ? (int) $row['user_id'] : 0), $changes),
            isset($row['organization_id']) ? (int) $row['organization_id'] : null
        );
    }

    public function beforeDelete(array $row, array $context = array())
    {
        AuditLog::record('membership_deleted', __('Rattachement supprimé.', 'smbb-signaconnect-proxima'), array('user_id' => isset($row['user_id']) ? (int) $row['user_id'] : 0), isset($row['organization_id']) ? (int) $row['organization_id'] : null);

        return true;
    }

    /**
     * Les champs « nouveau compte » ne sont pas des colonnes : le framework ne les transmet pas.
     * On les lit dans le POST (nonce déjà vérifié par CodeTool), uniquement à la création.
     */
    private function readNewUserFields(array $data, array $context)
    {
        $is_create = !isset($context['action']) || $context['action'] === 'create';

        if (!$is_create || empty($_POST['scp_new_user']) || (string) wp_unslash($_POST['scp_new_user']) !== '1') {
            unset($data['scp_new_user']);
            return $data;
        }

        $data['scp_new_user'] = '1';
        $data['scp_new_user_email'] = isset($_POST['scp_new_user_email']) ? sanitize_email(wp_unslash($_POST['scp_new_user_email'])) : '';
        $data['scp_new_user_first_name'] = isset($_POST['scp_new_user_first_name']) ? sanitize_text_field(wp_unslash($_POST['scp_new_user_first_name'])) : '';
        $data['scp_new_user_last_name'] = isset($_POST['scp_new_user_last_name']) ? sanitize_text_field(wp_unslash($_POST['scp_new_user_last_name'])) : '';
        $data['user_id'] = '';

        return $data;
    }

    private function wantsNewUser(array $data)
    {
        return isset($data['scp_new_user']) && (string) $data['scp_new_user'] === '1';
    }

    private function newUserErrors(array $data)
    {
        $errors = array();
        $email = isset($data['scp_new_user_email']) ? (string) $data['scp_new_user_email'] : '';

        if ($email === '' || !is_email($email)) {
            $errors['scp_new_user_email'] = __('Indiquez un email valide.', 'smbb-signaconnect-proxima');
        } elseif (email_exists($email)) {
            $errors['scp_new_user_email'] = __('Un compte existe déjà avec cet email : désactivez « Créer un nouveau compte » et choisissez-le dans la liste.', 'smbb-signaconnect-proxima');
        }

        if (empty($data['scp_new_user_first_name'])) {
            $errors['scp_new_user_first_name'] = __('Indiquez le prénom.', 'smbb-signaconnect-proxima');
        }

        if (empty($data['scp_new_user_last_name'])) {
            $errors['scp_new_user_last_name'] = __('Indiquez le nom.', 'smbb-signaconnect-proxima');
        }

        if (!current_user_can('create_users')) {
            $errors['scp_new_user'] = __("Vous n'avez pas le droit de créer des comptes WordPress.", 'smbb-signaconnect-proxima');
        }

        return $errors;
    }

    /**
     * Crée le compte (rôle WordPress par défaut du site) et envoie l'email « choisir son mot de passe ».
     */
    private function createWordPressUser(array $data)
    {
        $email = (string) $data['scp_new_user_email'];
        $first_name = (string) $data['scp_new_user_first_name'];
        $last_name = (string) $data['scp_new_user_last_name'];

        $base = sanitize_user(strtolower(strstr($email, '@', true)), true);
        $base = $base !== '' ? $base : 'user';
        $login = $base;
        $suffix = 2;

        while (username_exists($login)) {
            $login = $base . $suffix;
            $suffix++;
        }

        $user_id = wp_insert_user(array(
            'user_login' => $login,
            'user_email' => $email,
            'user_pass' => wp_generate_password(24, true, true),
            'first_name' => $first_name,
            'last_name' => $last_name,
            'display_name' => trim($first_name . ' ' . $last_name),
            'role' => get_option('default_role', 'subscriber'),
        ));

        if (is_wp_error($user_id)) {
            // Cas rare (les contrôles sont faits en validation) : on stoppe avant d'écrire une ligne orpheline.
            wp_die(
                esc_html(sprintf(__('Le compte WordPress n\'a pas pu être créé : %s', 'smbb-signaconnect-proxima'), $user_id->get_error_message())),
                esc_html__('SignaConnect', 'smbb-signaconnect-proxima'),
                array('back_link' => true)
            );
        }

        wp_new_user_notification($user_id, null, 'user');
        Lookup::flush();
        self::$created_user_id = (int) $user_id;

        return (int) $user_id;
    }
}
