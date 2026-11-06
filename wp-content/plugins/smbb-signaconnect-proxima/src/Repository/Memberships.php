<?php

namespace Smbb\SignConnectProxima\Repository;

defined('ABSPATH') || exit;

/**
 * Rattachements d'un utilisateur : ce qu'il peut faire dans l'app.
 */
final class Memberships
{
    /**
     * Lignes actives de l'utilisateur, avec le nom de l'organisation et ses équipes.
     *
     * @return array<int,array<string,mixed>> membership_id => ligne
     */
    public static function forUser($user_id)
    {
        global $wpdb;

        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT m.*, o.name AS organization_name, o.status AS organization_status FROM ' . Lookup::table('scp_membership') . ' m '
            . 'INNER JOIN ' . Lookup::table('scp_organization') . ' o ON o.id = m.organization_id AND o.deleted_flag = 0 '
            . 'WHERE m.user_id = %d AND m.deleted_flag = 0 AND m.is_active = 1 ORDER BY o.name ASC',
            (int) $user_id
        ), ARRAY_A);
        $teams = Lookup::teams();
        $result = array();

        foreach (is_array($rows) ? $rows : array() as $row) {
            $team_ids = json_decode((string) $row['team_ids'], true);
            $row['teams'] = array();

            foreach (is_array($team_ids) ? $team_ids : array() as $team_id) {
                $team_id = (int) $team_id;

                if (isset($teams[$team_id]) && (int) $teams[$team_id]['organization_id'] === (int) $row['organization_id'] && $teams[$team_id]['status'] === 'active') {
                    $row['teams'][$team_id] = (string) $teams[$team_id]['name'];
                }
            }

            $result[(int) $row['id']] = $row;
        }

        return $result;
    }

    public static function find($id)
    {
        global $wpdb;

        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . Lookup::table('scp_membership') . ' WHERE id = %d', (int) $id), ARRAY_A);

        return is_array($row) ? $row : null;
    }

    /**
     * Retrouve le rattachement de l'utilisateur pour une équipe donnée.
     */
    public static function forUserTeam($user_id, $team_id)
    {
        foreach (self::forUser($user_id) as $membership) {
            if (isset($membership['teams'][(int) $team_id])) {
                return $membership;
            }
        }

        return null;
    }

    /**
     * Ce membre peut-il voir ce document ? (expéditeur, superviseur d'équipe ou d'organisation)
     */
    public static function canView($user_id, array $document)
    {
        if ((int) $document['sender_id'] === (int) $user_id) {
            return true;
        }

        if (user_can($user_id, 'manage_options')) {
            return true;
        }

        foreach (self::forUser($user_id) as $membership) {
            if ((int) $membership['organization_id'] !== (int) $document['organization_id']) {
                continue;
            }

            if (in_array($membership['role'], array('org_supervisor', 'org_admin'), true)) {
                return true;
            }

            if ($membership['role'] === 'team_supervisor' && isset($membership['teams'][(int) $document['team_id']])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Relancer ou annuler : l'expéditeur, l'admin de l'organisation, ou SMBB.
     */
    public static function canManage($user_id, array $document)
    {
        if ((int) $document['sender_id'] === (int) $user_id || user_can($user_id, 'manage_options')) {
            return true;
        }

        foreach (self::forUser($user_id) as $membership) {
            if ((int) $membership['organization_id'] === (int) $document['organization_id'] && $membership['role'] === 'org_admin') {
                return true;
            }
        }

        return false;
    }

    /**
     * Périmètre de l'utilisateur : organisations supervisées ou administrées, équipes supervisées.
     *
     * @return array{memberships:array,org_ids:int[],team_ids:int[],admin_org_ids:int[]}
     */
    public static function scope($user_id)
    {
        $scope = array('memberships' => self::forUser($user_id), 'org_ids' => array(), 'team_ids' => array(), 'admin_org_ids' => array());

        foreach ($scope['memberships'] as $membership) {
            $org_id = (int) $membership['organization_id'];

            if (in_array($membership['role'], array('org_supervisor', 'org_admin'), true)) {
                $scope['org_ids'][] = $org_id;
            }

            if ($membership['role'] === 'org_admin' && $membership['organization_status'] !== 'blocked') {
                $scope['admin_org_ids'][] = $org_id;
            }

            if ($membership['role'] === 'team_supervisor') {
                $scope['team_ids'] = array_merge($scope['team_ids'], array_keys($membership['teams']));
            }
        }

        $scope['org_ids'] = array_values(array_unique($scope['org_ids']));
        $scope['team_ids'] = array_values(array_unique(array_map('intval', $scope['team_ids'])));

        return $scope;
    }

    public static function isSupervisor(array $scope)
    {
        return !empty($scope['org_ids']) || !empty($scope['team_ids']);
    }

    /**
     * Rattachements visibles par un superviseur (ceux de ses organisations, ou partageant une de ses équipes),
     * avec nom et email de l'utilisateur. $with_inactive pour l'écran de gestion des utilisateurs.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function inScope(array $scope, $with_inactive = false, $organization_id = 0)
    {
        global $wpdb;

        $teams = Lookup::teams();
        $org_ids = $scope['org_ids'];

        foreach ($scope['team_ids'] as $team_id) {
            if (isset($teams[$team_id])) {
                $org_ids[] = (int) $teams[$team_id]['organization_id'];
            }
        }

        $org_ids = array_values(array_unique(array_map('intval', $org_ids)));

        if ($organization_id > 0) {
            $org_ids = array_values(array_intersect($org_ids, array((int) $organization_id)));
        }

        if (!$org_ids) {
            return array();
        }

        $rows = $wpdb->get_results(
            'SELECT * FROM ' . Lookup::table('scp_membership') . ' WHERE deleted_flag = 0 AND organization_id IN (' . implode(',', $org_ids) . ')'
            . ($with_inactive ? '' : ' AND is_active = 1'),
            ARRAY_A
        );
        $result = array();

        foreach (is_array($rows) ? $rows : array() as $row) {
            $team_ids = array_map('intval', (array) json_decode((string) $row['team_ids'], true));
            $visible = in_array((int) $row['organization_id'], $scope['org_ids'], true) || array_intersect($team_ids, $scope['team_ids']);

            if (!$visible) {
                continue;
            }

            $user = get_userdata((int) $row['user_id']);
            $name = $user ? trim($user->first_name . ' ' . $user->last_name) : '';
            $row['team_id_list'] = $team_ids;
            $row['user_name'] = $user ? ($name !== '' ? $name : (string) $user->display_name) : sprintf(__('Utilisateur #%d', 'smbb-signaconnect-proxima'), (int) $row['user_id']);
            $row['user_email'] = $user ? (string) $user->user_email : '';
            $result[(int) $row['id']] = $row;
        }

        uasort($result, function ($a, $b) {
            return strcasecmp($a['user_name'], $b['user_name']);
        });

        return $result;
    }
}
