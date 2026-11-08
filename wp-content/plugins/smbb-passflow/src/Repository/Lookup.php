<?php

namespace Smbb\Passflow\Repository;

defined('ABSPATH') || exit;

/**
 * Lectures courtes et mises en cache pour la durée de la requête (ateliers, intervenants, libellés).
 */
final class Lookup
{
    private static $cache = array();

    /**
     * Nom SQL complet d'une table Passflow : Lookup::table('passflow_slot').
     */
    public static function table($name)
    {
        global $wpdb;

        return $wpdb->prefix . preg_replace('/[^a-z0-9_]/', '', (string) $name);
    }

    /**
     * @return array<int,array<string,mixed>> ateliers indexés par id (archivés compris)
     */
    public static function ateliers()
    {
        return self::remember('ateliers', function () {
            return self::indexed('SELECT * FROM ' . self::table('passflow_activity') . ' WHERE deleted_flag = 0 ORDER BY name ASC');
        });
    }

    /**
     * @return array<int,array<string,mixed>> intervenants indexés par id (archivés compris)
     */
    public static function intervenants()
    {
        return self::remember('intervenants', function () {
            return self::indexed('SELECT * FROM ' . self::table('passflow_host') . ' WHERE deleted_flag = 0 ORDER BY last_name ASC, first_name ASC');
        });
    }

    public static function atelier($id)
    {
        $activities = self::ateliers();

        return isset($activities[(int) $id]) ? $activities[(int) $id] : null;
    }

    public static function activityName($id)
    {
        $activity = self::atelier($id);

        return $activity ? (string) $activity['name'] : '';
    }

    public static function hostName($id)
    {
        $hosts = self::intervenants();

        if (!isset($hosts[(int) $id])) {
            return '';
        }

        return self::personName($hosts[(int) $id]);
    }

    /**
     * Intervenant rattaché à un compte WordPress.
     */
    public static function hostForUser($user_id)
    {
        foreach (self::intervenants() as $row) {
            if ((int) $row['user_id'] === (int) $user_id && (int) $user_id > 0) {
                return $row;
            }
        }

        return null;
    }

    /**
     * « Prénom Nom » d'une ligne qui a first_name / last_name.
     */
    public static function personName(array $row)
    {
        return trim((isset($row['first_name']) ? $row['first_name'] : '') . ' ' . (isset($row['last_name']) ? $row['last_name'] : ''));
    }

    /**
     * Noms des intervenants d'un créneau, séparés par des virgules.
     *
     * @param mixed $ids tableau d'ids ou JSON
     */
    public static function hostNames($ids)
    {
        $names = array();

        foreach (self::ids($ids) as $id) {
            $name = self::hostName($id);

            if ($name !== '') {
                $names[] = $name;
            }
        }

        return implode(', ', $names);
    }

    /**
     * Normalise une liste d'ids (tableau ou JSON) en entiers.
     *
     * @return array<int,int>
     */
    public static function ids($ids)
    {
        if (is_string($ids)) {
            $decoded = json_decode($ids, true);
            $ids = is_array($decoded) ? $decoded : array();
        }

        return array_values(array_filter(array_map('intval', is_array($ids) ? $ids : array())));
    }

    /**
     * Options de select : libellé => id.
     */
    public static function activityOptions($empty = '', $active_only = false, $keep_id = 0)
    {
        $options = $empty !== '' ? array($empty => '') : array();

        foreach (self::ateliers() as $id => $row) {
            if ($active_only && $row['status'] !== 'active' && (int) $id !== (int) $keep_id) {
                continue;
            }

            $label = (string) $row['name'];

            if ($row['status'] !== 'active') {
                $label .= ' (' . __('archivé', 'smbb-passflow') . ')';
            }

            $options[$label] = (string) $id;
        }

        return $options;
    }

    /**
     * Options de cases à cocher : libellé => id. Les archivés ne restent que s'ils sont déjà cochés.
     *
     * @param array<int,int> $keep_ids
     */
    public static function hostOptions(array $keep_ids = array())
    {
        $options = array();

        foreach (self::intervenants() as $id => $row) {
            if ($row['status'] !== 'active' && !in_array((int) $id, $keep_ids, true)) {
                continue;
            }

            $options[self::personName($row) ?: ('#' . $id)] = (string) $id;
        }

        return $options;
    }

    /**
     * Libellé lisible d'un créneau : « Atelier — sam. 6 déc. 2026, 19h30-21h00 ».
     */
    public static function slotLabel(array $slot, $with_activity = true)
    {
        $parts = array();

        if ($with_activity) {
            $parts[] = self::activityName($slot['activity_id']);
        }

        $parts[] = self::slotWhen($slot);

        return implode(' — ', array_filter($parts));
    }

    public static function slotWhen(array $slot)
    {
        if (\Smbb\Passflow\Service\Passes::isPass($slot)) {
            $days = \Smbb\Passflow\Service\Passes::daysLabel($slot);

            return sprintf(__('Pass : %s', 'smbb-passflow'), $days !== '' ? $days : __('jours à choisir', 'smbb-passflow'));
        }

        if (empty($slot['start_date'])) {
            return __('Date à définir', 'smbb-passflow');
        }

        $date = date_i18n('D j M Y', strtotime($slot['start_date'] . ' 12:00:00'));
        $hours = self::slotHours($slot);

        return $hours !== '' ? $date . ', ' . $hours : $date;
    }

    /**
     * Date « toute la journée » : on n'affiche pas d'heure (l'heure de début, 7h par défaut, ne sert
     * qu'aux rappels et au tri).
     */
    public static function allDay(array $slot)
    {
        return !empty($slot['all_day']);
    }

    /**
     * Heures d'une date (« 19h30-21h00 »), vide pour une date « toute la journée ».
     * $all_day_label : texte à afficher à la place pour une journée entière (admin).
     */
    public static function slotHours(array $slot, $with_end = true, $all_day_label = '')
    {
        if (self::allDay($slot)) {
            return (string) $all_day_label;
        }

        return self::hours(isset($slot['start_time']) ? $slot['start_time'] : '', $with_end && isset($slot['end_time']) ? $slot['end_time'] : '');
    }

    /**
     * 19:30:00 + 21:00:00 -> « 19h30-21h00 ».
     */
    public static function hours($start, $end = '')
    {
        $format = function ($time) {
            if (!$time || !preg_match('/^(\d{1,2}):(\d{2})/', (string) $time, $matches)) {
                return '';
            }

            return (int) $matches[1] . 'h' . $matches[2];
        };

        $start = $format($start);
        $end = $format($end);

        if ($start === '') {
            return '';
        }

        return $end !== '' ? $start . '-' . $end : $start;
    }

    public static function userLabel($user_id)
    {
        $user = $user_id ? get_userdata((int) $user_id) : null;

        if (!$user) {
            return '';
        }

        return $user->display_name . ' <' . $user->user_email . '>';
    }

    /**
     * Comptage simple, en ignorant les lignes supprimées.
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
