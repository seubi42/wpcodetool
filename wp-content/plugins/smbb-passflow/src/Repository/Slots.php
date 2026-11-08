<?php

namespace Smbb\Passflow\Repository;

use Smbb\Passflow\Support\Choices;

defined('ABSPATH') || exit;

/**
 * Lectures des créneaux avec leur remplissage.
 */
final class Slots
{
    public static function find($id)
    {
        global $wpdb;

        if ((int) $id < 1) {
            return null;
        }

        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . Lookup::table('passflow_slot') . ' WHERE id = %d AND deleted_flag = 0', (int) $id), ARRAY_A);

        return $row ? self::withStats(array($row))[0] : null;
    }

    /**
     * Créneaux entre deux dates (incluses), avec leur remplissage.
     *
     * @param array<string,mixed> $args activity_id, statuses, host_id, limit, with_passes
     * @return array<int,array<string,mixed>>
     */
    public static function between($from, $to, array $args = array())
    {
        global $wpdb;

        $sql = 'SELECT * FROM ' . Lookup::table('passflow_slot') . ' WHERE deleted_flag = 0';
        $params = array();

        if ($from !== null) {
            $sql .= ' AND start_date >= %s';
            $params[] = $from;
        }

        if ($to !== null) {
            $sql .= ' AND start_date <= %s';
            $params[] = $to;
        }

        if (!empty($args['activity_id'])) {
            $sql .= ' AND activity_id = %d';
            $params[] = (int) $args['activity_id'];
        }

        // Les pass (créneaux qui regroupent plusieurs jours) ne sont pas des séances : on ne les
        // liste que sur demande (vente en ligne, fiches).
        if (empty($args['with_passes'])) {
            $sql .= " AND kind <> 'pass'";
        }

        if (!empty($args['statuses'])) {
            $sql .= ' AND status IN (' . implode(',', array_fill(0, count($args['statuses']), '%s')) . ')';
            $params = array_merge($params, array_values($args['statuses']));
        }

        $sql .= ' ORDER BY start_date ASC, start_time ASC, id ASC';

        if (!empty($args['limit'])) {
            $sql .= ' LIMIT ' . (int) $args['limit'];
        }

        $rows = $wpdb->get_results($params ? $wpdb->prepare($sql, $params) : $sql, ARRAY_A);
        $rows = self::withStats(is_array($rows) ? $rows : array());

        if (!empty($args['host_id'])) {
            $rows = array_values(array_filter($rows, function ($row) use ($args) {
                return in_array((int) $args['host_id'], Lookup::ids($row['host_ids']), true);
            }));
        }

        return $rows;
    }

    /**
     * Prochains créneaux (à partir d'aujourd'hui), hors annulés.
     */
    public static function upcoming($limit = 0, array $args = array())
    {
        $args = array_merge(array('statuses' => array('draft', 'open', 'full')), $args);

        if ($limit) {
            $args['limit'] = $limit;
        }

        return self::between(current_time('Y-m-d'), null, $args);
    }

    /**
     * Ajoute à chaque créneau : booked (places occupées), remaining, fill (0..1), waiting (liste d'attente).
     *
     * @param array<int,array<string,mixed>> $rows
     * @return array<int,array<string,mixed>>
     */
    public static function withStats(array $rows)
    {
        global $wpdb;

        if (!$rows) {
            return $rows;
        }

        $ids = array_map('intval', array_column($rows, 'id'));
        $in = implode(',', $ids);
        $seat = "'" . implode("','", Choices::seatStatuses()) . "'";
        $booked = array();
        $waiting = array();

        $counts = $wpdb->get_results('SELECT slot_id, COUNT(*) AS total FROM ' . Lookup::table('passflow_booking')
            . " WHERE deleted_flag = 0 AND status IN ({$seat}) AND slot_id IN ({$in}) GROUP BY slot_id", ARRAY_A);

        foreach (is_array($counts) ? $counts : array() as $count) {
            $booked[(int) $count['slot_id']] = (int) $count['total'];
        }

        $counts = $wpdb->get_results('SELECT slot_id, SUM(places) AS total FROM ' . Lookup::table('passflow_waitlist')
            . " WHERE deleted_flag = 0 AND status IN ('waiting','notified') AND slot_id IN ({$in}) GROUP BY slot_id", ARRAY_A);

        foreach (is_array($counts) ? $counts : array() as $count) {
            $waiting[(int) $count['slot_id']] = (int) $count['total'];
        }

        foreach ($rows as &$row) {
            $id = (int) $row['id'];
            $capacity = max(0, (int) $row['capacity']);
            $row['booked'] = isset($booked[$id]) ? $booked[$id] : 0;
            $row['remaining'] = max(0, $capacity - $row['booked']);
            $row['fill'] = $capacity > 0 ? min(1, $row['booked'] / $capacity) : 0;
            $row['waiting'] = isset($waiting[$id]) ? $waiting[$id] : 0;

            if (\Smbb\Passflow\Service\Passes::isPass($row)) {
                $row['remaining'] = \Smbb\Passflow\Service\Passes::remaining($row);
                $row['fill'] = ($row['booked'] + $row['remaining']) > 0 ? $row['booked'] / ($row['booked'] + $row['remaining']) : 1;
            }
        }
        unset($row);

        return $rows;
    }

    /**
     * Options de select pour déplacer une inscription : créneaux à venir non annulés.
     */
    public static function moveOptions($exclude_id = 0, $empty = '')
    {
        $options = $empty !== '' ? array($empty => '') : array();

        foreach (self::upcoming(0, array('statuses' => array('open', 'full', 'draft'), 'with_passes' => true)) as $row) {
            if ((int) $row['id'] === (int) $exclude_id) {
                continue;
            }

            $label = Lookup::slotLabel($row) . ' · ' . sprintf(
                /* translators: %d: places restantes */
                _n('%d place libre', '%d places libres', $row['remaining'], 'smbb-passflow'),
                $row['remaining']
            );
            $options[$label] = (string) $row['id'];
        }

        return $options;
    }

    /**
     * Le créneau a-t-il déjà eu lieu ?
     */
    public static function isPast(array $slot)
    {
        // Un pass est passé quand son dernier jour l'est.
        if (\Smbb\Passflow\Service\Passes::isPass($slot)) {
            $days = \Smbb\Passflow\Service\Passes::days($slot);

            if ($days) {
                $slot = end($days);
            }
        }

        return !empty($slot['start_date']) && $slot['start_date'] < current_time('Y-m-d');
    }
}
