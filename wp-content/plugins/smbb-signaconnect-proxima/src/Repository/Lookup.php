<?php

namespace Smbb\SignConnectProxima\Repository;

defined('ABSPATH') || exit;

/**
 * Petites requêtes de lecture partagées par les vues d'admin et les hooks :
 * listes déroulantes, libellés et compteurs. Tout ignore les lignes supprimées
 * (soft delete CodeTool : deleted_flag = 1).
 */
final class Lookup
{
    /** @var array<string,mixed> */
    private static $cache = array();

    public static function table($name)
    {
        global $wpdb;

        return $wpdb->prefix . $name;
    }

    /**
     * @return array<int,array<string,mixed>> id => ligne
     */
    public static function organizations()
    {
        return self::remember('organizations', function () {
            return self::indexed('SELECT id, name, status, default_plan_id FROM ' . self::table('scp_organization') . ' WHERE deleted_flag = 0 ORDER BY name ASC');
        });
    }

    /**
     * @return array<int,array<string,mixed>> id => ligne
     */
    public static function teams()
    {
        return self::remember('teams', function () {
            return self::indexed('SELECT id, organization_id, name, status FROM ' . self::table('scp_team') . ' WHERE deleted_flag = 0 ORDER BY name ASC');
        });
    }

    /**
     * @return array<int,array<string,mixed>> id => ligne
     */
    public static function plans()
    {
        return self::remember('plans', function () {
            return self::indexed('SELECT id, name, status, monthly_fee FROM ' . self::table('scp_plan') . ' WHERE deleted_flag = 0 ORDER BY name ASC');
        });
    }

    public static function organizationName($id)
    {
        $organizations = self::organizations();

        return isset($organizations[(int) $id]) ? (string) $organizations[(int) $id]['name'] : '';
    }

    public static function teamName($id)
    {
        $teams = self::teams();

        return isset($teams[(int) $id]) ? (string) $teams[(int) $id]['name'] : '';
    }

    public static function planName($id)
    {
        $plans = self::plans();

        return isset($plans[(int) $id]) ? (string) $plans[(int) $id]['name'] : '';
    }

    public static function userLabel($user_id)
    {
        $user = $user_id ? get_userdata((int) $user_id) : false;

        if (!$user) {
            return '';
        }

        $name = trim($user->first_name . ' ' . $user->last_name);

        return ($name !== '' ? $name : $user->display_name) . ' <' . $user->user_email . '>';
    }

    /**
     * Options de select : libellé => id. $empty ajoute une première option vide.
     */
    public static function organizationOptions($empty = '')
    {
        $options = $empty !== '' ? array($empty => '') : array();

        foreach (self::organizations() as $id => $row) {
            $options[(string) $row['name']] = (string) $id;
        }

        return $options;
    }

    /**
     * Options « Organisation — Équipe » pour toutes les équipes actives.
     */
    public static function teamOptions($with_organization = true)
    {
        $options = array();

        foreach (self::teams() as $id => $row) {
            $label = (string) $row['name'];

            if ($with_organization) {
                $label = self::organizationName($row['organization_id']) . ' — ' . $label;
            }

            if ($row['status'] !== 'active') {
                $label .= ' (' . __('archivée', 'smbb-signaconnect-proxima') . ')';
            }

            $options[$label] = (string) $id;
        }

        ksort($options, SORT_NATURAL | SORT_FLAG_CASE);

        return $options;
    }

    public static function planOptions($empty = '', $active_only = false)
    {
        $options = $empty !== '' ? array($empty => '') : array();

        foreach (self::plans() as $id => $row) {
            if ($active_only && $row['status'] !== 'active') {
                continue;
            }

            $options[(string) $row['name']] = (string) $id;
        }

        return $options;
    }

    public static function userOptions($empty = '')
    {
        $options = $empty !== '' ? array($empty => '') : array();
        $users = get_users(array('orderby' => 'display_name', 'order' => 'ASC', 'fields' => array('ID', 'display_name', 'user_email'), 'number' => 2000));

        foreach ($users as $user) {
            $options[$user->display_name . ' <' . $user->user_email . '>'] = (string) $user->ID;
        }

        return $options;
    }

    /**
     * Compte les lignes non supprimées d'une table, avec une condition simple column = valeur.
     */
    public static function count($table, $column = '', $value = null)
    {
        global $wpdb;

        $sql = 'SELECT COUNT(*) FROM ' . self::table($table) . ' WHERE deleted_flag = 0';

        if ($column !== '') {
            $sql .= $wpdb->prepare(' AND ' . preg_replace('/[^a-z0-9_]/', '', $column) . ' = %s', (string) $value);
        }

        return (int) $wpdb->get_var($sql);
    }

    public static function flush()
    {
        self::$cache = array();
    }

    private static function remember($key, callable $loader)
    {
        if (!array_key_exists($key, self::$cache)) {
            self::$cache[$key] = $loader();
        }

        return self::$cache[$key];
    }

    private static function indexed($sql)
    {
        global $wpdb;

        $rows = $wpdb->get_results($sql, ARRAY_A);
        $indexed = array();

        foreach (is_array($rows) ? $rows : array() as $row) {
            $indexed[(int) $row['id']] = $row;
        }

        return $indexed;
    }
}
