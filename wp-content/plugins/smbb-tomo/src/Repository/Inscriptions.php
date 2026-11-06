<?php

namespace Smbb\Tomo\Repository;

use Smbb\Tomo\Support\Choices;

defined('ABSPATH') || exit;

/**
 * Lectures des inscriptions, de l'historique et de la liste d'attente.
 */
final class Inscriptions
{
    public static function find($id)
    {
        global $wpdb;

        if ((int) $id < 1) {
            return null;
        }

        return $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . Lookup::table('tomo_inscription') . ' WHERE id = %d AND deleted_flag = 0', (int) $id), ARRAY_A) ?: null;
    }

    /**
     * Participants d'un créneau : places occupées d'abord, puis annulés si demandé.
     */
    public static function forCreneau($creneau_id, $with_cancelled = false)
    {
        global $wpdb;

        $statuses = Choices::seatStatuses();

        if ($with_cancelled) {
            $statuses[] = 'cancelled';
        }

        $sql = 'SELECT * FROM ' . Lookup::table('tomo_inscription')
            . ' WHERE deleted_flag = 0 AND creneau_id = %d AND status IN (' . implode(',', array_fill(0, count($statuses), '%s')) . ')'
            . " ORDER BY FIELD(status, 'pending', 'confirmed', 'present', 'absent', 'cancelled'), last_name ASC, first_name ASC";

        $rows = $wpdb->get_results($wpdb->prepare($sql, array_merge(array((int) $creneau_id), $statuses)), ARRAY_A);

        return is_array($rows) ? $rows : array();
    }

    /**
     * Inscriptions confirmées sur des créneaux à venir.
     */
    public static function upcomingConfirmed(array $args = array())
    {
        global $wpdb;

        $sql = 'SELECT i.*, c.start_date, c.start_time, c.end_time FROM ' . Lookup::table('tomo_inscription') . ' i'
            . ' INNER JOIN ' . Lookup::table('tomo_creneau') . ' c ON c.id = i.creneau_id AND c.deleted_flag = 0'
            . " WHERE i.deleted_flag = 0 AND i.pass_id = 0 AND i.status = 'confirmed' AND c.status IN ('draft','open','full') AND c.start_date >= %s";
        $params = array(current_time('Y-m-d'));

        if (!empty($args['atelier_id'])) {
            $sql .= ' AND i.atelier_id = %d';
            $params[] = (int) $args['atelier_id'];
        }

        if (!empty($args['to'])) {
            $sql .= ' AND c.start_date <= %s';
            $params[] = $args['to'];
        }

        $sql .= ' ORDER BY c.start_date ASC, c.start_time ASC, i.last_name ASC';
        $rows = $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A);

        return is_array($rows) ? $rows : array();
    }

    /**
     * Réservations en attente d'une date (à confirmer, sans créneau) : reports et créneaux annulés.
     */
    public static function withoutDate(array $args = array())
    {
        global $wpdb;

        $sql = 'SELECT * FROM ' . Lookup::table('tomo_inscription') . " WHERE deleted_flag = 0 AND status = 'pending' AND (creneau_id IS NULL OR creneau_id = 0)";
        $params = array();

        if (!empty($args['atelier_id'])) {
            $sql .= ' AND atelier_id = %d';
            $params[] = (int) $args['atelier_id'];
        }

        $sql .= ' ORDER BY id ASC';
        $rows = $wpdb->get_results($params ? $wpdb->prepare($sql, $params) : $sql, ARRAY_A);

        return is_array($rows) ? $rows : array();
    }

    public static function countByStatus($status)
    {
        return Lookup::count('tomo_inscription', 'status', $status);
    }

    /**
     * Historique d'une inscription, le plus récent d'abord.
     */
    public static function history($inscription_id)
    {
        global $wpdb;

        $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . Lookup::table('tomo_historique') . ' WHERE inscription_id = %d ORDER BY id DESC', (int) $inscription_id), ARRAY_A);

        return is_array($rows) ? $rows : array();
    }

    /**
     * Derniers mouvements, tous créneaux confondus.
     */
    public static function latestHistory($limit = 10)
    {
        global $wpdb;

        $rows = $wpdb->get_results('SELECT * FROM ' . Lookup::table('tomo_historique') . ' ORDER BY id DESC LIMIT ' . (int) $limit, ARRAY_A);

        return is_array($rows) ? $rows : array();
    }

    public static function findWaitlist($id)
    {
        global $wpdb;

        return $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . Lookup::table('tomo_attente') . ' WHERE id = %d AND deleted_flag = 0', (int) $id), ARRAY_A) ?: null;
    }

    /**
     * Liste d'attente active d'un créneau, dans l'ordre d'arrivée.
     */
    public static function waitlist($creneau_id)
    {
        global $wpdb;

        $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . Lookup::table('tomo_attente')
            . " WHERE deleted_flag = 0 AND creneau_id = %d AND status IN ('waiting','notified') ORDER BY id ASC", (int) $creneau_id), ARRAY_A);

        return is_array($rows) ? $rows : array();
    }

    public static function countWithoutDate()
    {
        return count(self::withoutDate());
    }

    /**
     * Réservations à confirmer qui ont déjà une date.
     */
    public static function countPendingDated()
    {
        global $wpdb;

        return (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . Lookup::table('tomo_inscription') . " WHERE deleted_flag = 0 AND status = 'pending' AND creneau_id > 0");
    }

    /**
     * Statuts des inscriptions par créneau : array(creneau_id => array(status => nombre)).
     *
     * @param int[] $creneau_ids
     * @return array<int,array<string,int>>
     */
    public static function statusCounts(array $creneau_ids)
    {
        global $wpdb;

        $ids = array_filter(array_map('intval', $creneau_ids));

        if (!$ids) {
            return array();
        }

        $rows = $wpdb->get_results('SELECT creneau_id, status, COUNT(*) AS total FROM ' . Lookup::table('tomo_inscription')
            . ' WHERE deleted_flag = 0 AND creneau_id IN (' . implode(',', $ids) . ') GROUP BY creneau_id, status', ARRAY_A);
        $counts = array();

        foreach (is_array($rows) ? $rows : array() as $row) {
            $counts[(int) $row['creneau_id']][$row['status']] = (int) $row['total'];
        }

        return $counts;
    }
}
