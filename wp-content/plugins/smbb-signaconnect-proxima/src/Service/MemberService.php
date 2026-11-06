<?php

namespace Smbb\SignConnectProxima\Service;

use Smbb\SignConnectProxima\Repository\AuditLog;
use Smbb\SignConnectProxima\Repository\Lookup;
use Smbb\SignConnectProxima\Repository\Memberships;
use Smbb\SignConnectProxima\Support\Choices;

defined('ABSPATH') || exit;

/**
 * Gestion des utilisateurs par l'admin d'une organisation (côté front) :
 * inviter, choisir les équipes, le rôle et le droit Modèles. Le forfait reste
 * celui de l'organisation par défaut : seul SMBB le change dans l'admin.
 */
final class MemberService
{
    /**
     * Invite un utilisateur (compte WordPress créé si l'email est inconnu) et le rattache.
     */
    public static function invite($actor_id, $organization_id, array $input)
    {
        global $wpdb;

        $organization = self::adminOrganization($actor_id, $organization_id);
        $email = sanitize_email(isset($input['email']) ? $input['email'] : '');
        $first = sanitize_text_field(isset($input['first_name']) ? $input['first_name'] : '');
        $last = sanitize_text_field(isset($input['last_name']) ? $input['last_name'] : '');
        $values = self::values($organization, $input);
        $errors = $values['errors'];

        if (!is_email($email)) {
            $errors[] = __('Indiquez un email valide.', 'smbb-signaconnect-proxima');
        }

        $user = $email !== '' ? get_user_by('email', $email) : false;

        if (!$user && ($first === '' || $last === '')) {
            $errors[] = __('Indiquez le prénom et le nom de la personne invitée.', 'smbb-signaconnect-proxima');
        }

        if (empty($organization['default_plan_id'])) {
            $errors[] = __("Votre organisation n'a pas de forfait par défaut : contactez votre prestataire pour l'ajouter.", 'smbb-signaconnect-proxima');
        }

        if ($user && $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM ' . Lookup::table('scp_membership') . ' WHERE deleted_flag = 0 AND user_id = %d AND organization_id = %d',
            (int) $user->ID,
            (int) $organization['id']
        ))) {
            $errors[] = __('Cette personne fait déjà partie de votre organisation.', 'smbb-signaconnect-proxima');
        }

        if ($errors) {
            throw new \InvalidArgumentException(implode("\n", $errors));
        }

        $created = false;

        if (!$user) {
            $user_id = self::createUser($email, $first, $last);
            $created = true;
        } else {
            $user_id = (int) $user->ID;
        }

        $now = current_time('mysql');
        $ok = $wpdb->insert(Lookup::table('scp_membership'), array(
            'user_id' => $user_id,
            'organization_id' => (int) $organization['id'],
            'plan_id' => (int) $organization['default_plan_id'],
            'team_ids' => wp_json_encode(array_map('strval', $values['team_ids'])),
            'role' => $values['role'],
            'can_manage_templates' => $values['can_manage_templates'],
            'is_active' => 1,
            'active_since' => $now,
            'creation_date' => $now,
            'creation_by' => (int) $actor_id,
            'lastupdate_date' => $now,
            'lastupdate_by' => (int) $actor_id,
        ));

        if ($ok === false) {
            throw new \RuntimeException(sprintf(__('Erreur base de données : %s', 'smbb-signaconnect-proxima'), $wpdb->last_error ?: '?'));
        }

        Lookup::flush();
        AuditLog::record('membership_created', $created
            ? __('Compte créé et invité par email par l\'admin de l\'organisation.', 'smbb-signaconnect-proxima')
            : __('Utilisateur existant rattaché par l\'admin de l\'organisation.', 'smbb-signaconnect-proxima'), array(
            'user_id' => $user_id,
            'role' => $values['role'],
            'team_ids' => $values['team_ids'],
            'plan_id' => (int) $organization['default_plan_id'],
        ), (int) $organization['id'], null, 'user', (int) $actor_id);

        return $created;
    }

    /**
     * Modifie équipes, rôle, droit Modèles et activation d'un rattachement de son organisation.
     */
    public static function update($actor_id, $membership_id, array $input)
    {
        global $wpdb;

        $membership = Memberships::find($membership_id);

        if (!$membership || (int) $membership['deleted_flag'] === 1) {
            throw new \RuntimeException(__('Utilisateur introuvable.', 'smbb-signaconnect-proxima'));
        }

        $organization = self::adminOrganization($actor_id, (int) $membership['organization_id']);
        $values = self::values($organization, $input);
        $is_self = (int) $membership['user_id'] === (int) $actor_id;
        $active = !empty($input['is_active']) ? 1 : 0;

        if ($is_self && ($values['role'] !== 'org_admin' || !$active)) {
            $values['errors'][] = __('Vous ne pouvez pas retirer votre propre rôle d\'admin ni vous désactiver.', 'smbb-signaconnect-proxima');
        }

        if ($values['errors']) {
            throw new \InvalidArgumentException(implode("\n", $values['errors']));
        }

        $data = array(
            'team_ids' => wp_json_encode(array_map('strval', $values['team_ids'])),
            'role' => $values['role'],
            'can_manage_templates' => $values['can_manage_templates'],
            'is_active' => $active,
            'lastupdate_date' => current_time('mysql'),
            'lastupdate_by' => (int) $actor_id,
        );

        if ((int) $membership['is_active'] === 1 && !$active) {
            $data['inactive_since'] = current_time('mysql');
        } elseif ($active) {
            $data['inactive_since'] = null;
        }

        $changes = array();

        foreach (array('team_ids', 'role', 'can_manage_templates', 'is_active') as $key) {
            if ((string) $membership[$key] !== (string) $data[$key]) {
                $changes[$key] = array('avant' => $membership[$key], 'après' => $data[$key]);
            }
        }

        if (!$changes) {
            return false;
        }

        if ($wpdb->update(Lookup::table('scp_membership'), $data, array('id' => (int) $membership['id'])) === false) {
            throw new \RuntimeException(sprintf(__('Erreur base de données : %s', 'smbb-signaconnect-proxima'), $wpdb->last_error ?: '?'));
        }

        AuditLog::record('membership_updated', __("Rattachement modifié par l'admin de l'organisation.", 'smbb-signaconnect-proxima'), array_merge(array('user_id' => (int) $membership['user_id']), $changes), (int) $organization['id'], null, 'user', (int) $actor_id);

        return true;
    }

    /**
     * Organisation que l'utilisateur administre (rôle org_admin sur une ligne active).
     */
    public static function adminOrganization($actor_id, $organization_id)
    {
        $scope = Memberships::scope($actor_id);

        if (!in_array((int) $organization_id, $scope['admin_org_ids'], true)) {
            throw new \RuntimeException(__("Vous n'administrez pas cette organisation.", 'smbb-signaconnect-proxima'));
        }

        global $wpdb;

        return $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . Lookup::table('scp_organization') . ' WHERE id = %d AND deleted_flag = 0', (int) $organization_id), ARRAY_A);
    }

    /**
     * Équipes actives de l'organisation : id => nom.
     */
    public static function teams($organization_id)
    {
        $teams = array();

        foreach (Lookup::teams() as $id => $team) {
            if ((int) $team['organization_id'] === (int) $organization_id && $team['status'] === 'active') {
                $teams[(int) $id] = (string) $team['name'];
            }
        }

        return $teams;
    }

    private static function values(array $organization, array $input)
    {
        $teams = self::teams($organization['id']);
        $team_ids = isset($input['team_ids']) && is_array($input['team_ids']) ? array_map('intval', $input['team_ids']) : array();
        $team_ids = array_values(array_intersect($team_ids, array_keys($teams)));
        $role = isset($input['role']) ? sanitize_key($input['role']) : 'user';
        $errors = array();

        if (!$team_ids) {
            $errors[] = __('Choisissez au moins une équipe.', 'smbb-signaconnect-proxima');
        }

        if (!array_key_exists($role, Choices::roles())) {
            $errors[] = __('Rôle inconnu.', 'smbb-signaconnect-proxima');
        }

        return array(
            'team_ids' => $team_ids,
            'role' => $role,
            'can_manage_templates' => !empty($input['can_manage_templates']) ? 1 : 0,
            'errors' => $errors,
        );
    }

    private static function createUser($email, $first, $last)
    {
        $base = sanitize_user(strtolower((string) strstr($email, '@', true)), true);
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
            'first_name' => $first,
            'last_name' => $last,
            'display_name' => trim($first . ' ' . $last),
            'role' => get_option('default_role', 'subscriber'),
        ));

        if (is_wp_error($user_id)) {
            throw new \RuntimeException(sprintf(__("Le compte n'a pas pu être créé : %s", 'smbb-signaconnect-proxima'), $user_id->get_error_message()));
        }

        wp_new_user_notification($user_id, null, 'user');

        return (int) $user_id;
    }
}
