<?php

namespace Smbb\Passflow\Repository;


defined('ABSPATH') || exit;

/**
 * Chiffres de la page Statistiques. Deux angles :
 * - les créneaux de la période (date de l'atelier) : places, remplissage, présence ;
 * - les inscriptions prises pendant la période (date de création) : volume, sources, annulations, délai.
 */
final class Stats
{
    /**
     * Périodes proposées : clé => array(libellé, du, au).
     *
     * @return array<string,array{0:string,1:string,2:string}>
     */
    public static function periods()
    {
        $d = 'smbb-passflow';
        $today = current_time('Y-m-d');
        $year = substr($today, 0, 4);

        return array(
            '30d' => array(__('30 derniers jours', $d), self::shift($today, '-29 days'), $today),
            '90d' => array(__('3 derniers mois', $d), self::shift($today, '-89 days'), $today),
            '12m' => array(__('12 derniers mois', $d), self::shift(self::shift($today, 'first day of this month'), '-11 months'), $today),
            'year' => array(sprintf(__('Année %s', $d), $year), $year . '-01-01', $year . '-12-31'),
            'last' => array(sprintf(__('Année %s', $d), (int) $year - 1), ((int) $year - 1) . '-01-01', ((int) $year - 1) . '-12-31'),
            'next' => array(__('90 prochains jours', $d), $today, self::shift($today, '+89 days')),
        );
    }

    /**
     * Toutes les statistiques d'une période.
     *
     * @return array<string,mixed>
     */
    public static function compute($from, $to, $activity_id = 0, $slot_id = 0)
    {
        $today = current_time('Y-m-d');

        if ($slot_id) {
            // Un seul créneau : ses chiffres, quelle que soit la période.
            $one = Slots::find((int) $slot_id);
            $slots = $one ? array($one) : array();
        } else {
            $slots = Slots::between($from, $to, array('activity_id' => (int) $activity_id, 'statuses' => array('open', 'full', 'draft', 'cancelled')));
        }

        $counts = Bookings::statusCounts(array_column($slots, 'id'));

        $totals = array('sessions' => 0, 'cancelled_sessions' => 0, 'capacity' => 0, 'booked' => 0, 'past_sessions' => 0, 'present' => 0, 'absent' => 0, 'unchecked' => 0);
        $months = array();
        $weekdays = array();
        $activities = array();

        foreach ($slots as $c) {
            $aid = (int) $c['activity_id'];
            $activities[$aid] = isset($activities[$aid]) ? $activities[$aid] : self::emptyActivity();

            if ($c['status'] === 'cancelled') {
                $totals['cancelled_sessions']++;
                $activities[$aid]['cancelled_sessions']++;
                continue;
            }

            if ($c['status'] === 'draft' && (int) $c['booked'] === 0) {
                continue; // brouillon vide : pas encore proposé.
            }

            $capacity = max(0, (int) $c['capacity']);
            $booked = (int) $c['booked'];
            $status = isset($counts[(int) $c['id']]) ? $counts[(int) $c['id']] : array();
            $past = $c['start_date'] < $today;
            $month = substr($c['start_date'], 0, 7);
            $weekday = (int) date('N', strtotime($c['start_date']));

            $totals['sessions']++;
            $totals['capacity'] += $capacity;
            $totals['booked'] += $booked;
            $activities[$aid]['sessions']++;
            $activities[$aid]['capacity'] += $capacity;
            $activities[$aid]['booked'] += $booked;

            if (!isset($months[$month])) {
                $months[$month] = array('sessions' => 0, 'capacity' => 0, 'booked' => 0, 'present' => 0);
            }

            $months[$month]['sessions']++;
            $months[$month]['capacity'] += $capacity;
            $months[$month]['booked'] += $booked;

            if (!isset($weekdays[$weekday])) {
                $weekdays[$weekday] = array('sessions' => 0, 'capacity' => 0, 'booked' => 0);
            }

            $weekdays[$weekday]['sessions']++;
            $weekdays[$weekday]['capacity'] += $capacity;
            $weekdays[$weekday]['booked'] += $booked;

            if ($past) {
                $present = isset($status['present']) ? $status['present'] : 0;
                $absent = isset($status['absent']) ? $status['absent'] : 0;
                $unchecked = (isset($status['confirmed']) ? $status['confirmed'] : 0) + (isset($status['pending']) ? $status['pending'] : 0);
                $totals['past_sessions']++;
                $totals['present'] += $present;
                $totals['absent'] += $absent;
                $totals['unchecked'] += $unchecked;
                $months[$month]['present'] += $present;
                $activities[$aid]['present'] += $present;
                $activities[$aid]['absent'] += $absent;
            }
        }

        ksort($months);
        ksort($weekdays);

        $sales = self::sales($from, $to, $activity_id, $slot_id);

        foreach ($sales['by_activity'] as $aid => $row) {
            $activities[$aid] = isset($activities[$aid]) ? $activities[$aid] : self::emptyActivity();
            $activities[$aid]['sold'] = $row['sold'];
            $activities[$aid]['revenue'] = $row['revenue'];
            $activities[$aid]['cancelled'] = $row['cancelled'];
        }

        uasort($activities, function ($a, $b) {
            return $b['booked'] + $b['sold'] - $a['booked'] - $a['sold'];
        });

        return array(
            'from' => $from,
            'to' => $to,
            'totals' => $totals,
            'fill' => $totals['capacity'] ? $totals['booked'] / $totals['capacity'] : null,
            'presence' => ($totals['present'] + $totals['absent']) ? $totals['present'] / ($totals['present'] + $totals['absent']) : null,
            'months' => self::fillMonths($months, $from, $to),
            'weekdays' => $weekdays,
            'ateliers' => $activities,
            'sales' => $sales,
        );
    }

    /**
     * Inscriptions prises pendant la période (hors ajouts depuis la liste d'attente comptés à part).
     *
     * @return array<string,mixed>
     */
    public static function sales($from, $to, $activity_id = 0, $slot_id = 0)
    {
        global $wpdb;

        $sql = 'SELECT i.activity_id, i.status, i.source, i.price_paid, i.creation_date, c.start_date FROM ' . Lookup::table('passflow_booking') . ' i'
            . ' LEFT JOIN ' . Lookup::table('passflow_slot') . ' c ON c.id = i.slot_id AND c.deleted_flag = 0'
            . ' WHERE i.deleted_flag = 0 AND i.pass_id = 0';
        $params = array();

        // Un créneau : toutes ses inscriptions, quelle que soit la date de réservation.
        if ($slot_id) {
            $sql .= ' AND i.slot_id = %d';
            $params[] = (int) $slot_id;
        } else {
            $sql .= ' AND i.creation_date >= %s AND i.creation_date <= %s';
            $params[] = $from . ' 00:00:00';
            $params[] = $to . ' 23:59:59';
        }

        if ($activity_id && !$slot_id) {
            $sql .= ' AND i.activity_id = %d';
            $params[] = (int) $activity_id;
        }

        $rows = $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A);
        $out = array('sold' => 0, 'cancelled' => 0, 'revenue' => 0.0, 'sources' => array(), 'source_revenue' => array(), 'lead_days' => null, 'by_activity' => array(), 'by_month' => array());
        $leads = array();

        foreach (is_array($rows) ? $rows : array() as $row) {
            $aid = (int) $row['activity_id'];
            $month = substr($row['creation_date'], 0, 7);

            if (!isset($out['by_activity'][$aid])) {
                $out['by_activity'][$aid] = array('sold' => 0, 'cancelled' => 0, 'revenue' => 0.0);
            }

            if ($row['status'] === 'cancelled') {
                $out['cancelled']++;
                $out['by_activity'][$aid]['cancelled']++;
                continue;
            }

            $out['sold']++;
            $out['revenue'] += (float) $row['price_paid'];
            $out['by_activity'][$aid]['sold']++;
            $out['by_activity'][$aid]['revenue'] += (float) $row['price_paid'];
            $out['by_month'][$month] = (isset($out['by_month'][$month]) ? $out['by_month'][$month] : 0) + 1;
            $source = (string) $row['source'] !== '' ? (string) $row['source'] : 'manual';
            $out['sources'][$source] = (isset($out['sources'][$source]) ? $out['sources'][$source] : 0) + 1;
            $out['source_revenue'][$source] = (isset($out['source_revenue'][$source]) ? $out['source_revenue'][$source] : 0) + (float) $row['price_paid'];

            if (!empty($row['start_date'])) {
                $leads[] = max(0, (int) round((strtotime($row['start_date']) - strtotime(substr($row['creation_date'], 0, 10))) / DAY_IN_SECONDS));
            }
        }

        if ($leads) {
            sort($leads);
            $out['lead_days'] = $leads[(int) floor((count($leads) - 1) / 2)]; // médiane.
        }

        arsort($out['sources']);

        return $out;
    }

    /**
     * Stock non réalisé : places confirmées à venir et réservations sans date, avec les montants connus.
     *
     * @return array{upcoming:int,undated:int,amount:float}
     */
    public static function stock($activity_id = 0)
    {
        $upcoming = Bookings::upcomingConfirmed(array('activity_id' => (int) $activity_id));
        $undated = Bookings::withoutDate(array('activity_id' => (int) $activity_id));

        return array(
            'upcoming' => count($upcoming),
            'undated' => count($undated),
            'amount' => array_sum(array_map('floatval', array_column($upcoming, 'price_paid'))) + array_sum(array_map('floatval', array_column($undated, 'price_paid'))),
        );
    }

    /**
     * Ventes sur place (commandes WooCommerce de la caisse) : de la période, d'un atelier ou d'un créneau.
     * Montants nets des retours ; détail par moyen de paiement, par produit et par atelier.
     *
     * @return array<string,mixed>|null
     */
    public static function onsite($from, $to, $activity_id = 0, $slot_id = 0)
    {
        if (!function_exists('wc_get_orders') || !\Smbb\Passflow\WooCommerce\Module::active()) {
            return null;
        }

        $meta = \Smbb\Passflow\WooCommerce\OnsiteSales::META_SLOT;

        if ($slot_id) {
            $orders = \Smbb\Passflow\WooCommerce\OnsiteSales::forSlot((int) $slot_id);
        } else {
            $orders = wc_get_orders(array(
                'limit' => -1,
                'created_via' => \Smbb\Passflow\WooCommerce\OnsiteSales::GATEWAY,
                'date_created' => $from . '...' . $to,
            ));
        }

        $activities = array();
        $kept = array();
        $by_activity = array();

        foreach ($orders as $order) {
            $cid = (int) $order->get_meta($meta);

            if (!array_key_exists($cid, $activities)) {
                $c = $cid ? Slots::find($cid) : null;
                $activities[$cid] = $c ? (int) $c['activity_id'] : 0;
            }

            if ($activity_id && !$slot_id && $activities[$cid] !== (int) $activity_id) {
                continue;
            }

            $kept[] = $order;

            if ($order->is_paid() && !in_array($order->get_status(), array('cancelled', 'refunded', 'failed', 'trash'), true)) {
                $aid = $activities[$cid];
                $by_activity[$aid] = (isset($by_activity[$aid]) ? $by_activity[$aid] : 0) + (float) $order->get_total() - (float) $order->get_total_refunded();
            }
        }

        $out = \Smbb\Passflow\WooCommerce\OnsiteSales::summary($kept);
        $out['paid_count'] = 0;
        $out['pending_count'] = 0;

        foreach ($kept as $order) {
            if (in_array($order->get_status(), array('cancelled', 'refunded', 'failed', 'trash'), true)) {
                continue;
            }

            if ($order->is_paid()) {
                $out['paid_count']++;
            } else {
                $out['pending_count']++;
            }
        }

        $out['by_activity'] = $by_activity;
        uasort($out['products'], function ($a, $b) {
            return $b['total'] <=> $a['total'];
        });
        arsort($out['methods']);

        return $out;
    }

    private static function emptyActivity()
    {
        return array('sessions' => 0, 'cancelled_sessions' => 0, 'capacity' => 0, 'booked' => 0, 'present' => 0, 'absent' => 0, 'sold' => 0, 'revenue' => 0.0, 'cancelled' => 0);
    }

    /**
     * Mois sans créneau inclus, pour que le graphique garde un axe régulier.
     */
    private static function fillMonths(array $months, $from, $to)
    {
        $out = array();
        $cursor = substr($from, 0, 7) . '-01';
        $end = substr($to, 0, 7);
        $guard = 0;

        while (substr($cursor, 0, 7) <= $end && $guard++ < 36) {
            $key = substr($cursor, 0, 7);
            $out[$key] = isset($months[$key]) ? $months[$key] : array('sessions' => 0, 'capacity' => 0, 'booked' => 0, 'present' => 0);
            $cursor = self::shift($cursor, '+1 month');
        }

        return $out;
    }

    private static function shift($date, $modifier)
    {
        return date('Y-m-d', strtotime($modifier, strtotime($date)));
    }
}
