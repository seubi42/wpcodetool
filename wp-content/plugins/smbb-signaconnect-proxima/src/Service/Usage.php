<?php

namespace Smbb\SignConnectProxima\Service;

use Smbb\SignConnectProxima\Repository\Lookup;
use Smbb\SignConnectProxima\Support\Levels;

defined('ABSPATH') || exit;

/**
 * Consommation (sans les prix) : signatures abouties par rattachement, mois et niveau,
 * comparées aux signatures incluses du forfait. C'est ce que voient utilisateurs et superviseurs.
 */
final class Usage
{
    /**
     * Compteurs d'un ensemble de rattachements pour une période.
     *
     * @param int[] $membership_ids
     * @return array<int,array<string,array<string,int>>> membership_id => mois => niveau => nombre
     */
    public static function counts(array $membership_ids, $from_month, $to_month)
    {
        global $wpdb;

        $membership_ids = array_values(array_unique(array_filter(array_map('intval', $membership_ids))));

        if (!$membership_ids) {
            return array();
        }

        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT membership_id, billing_month, signature_level, COUNT(*) AS total FROM ' . Lookup::table('scp_document')
            . ' WHERE billable = 1 AND membership_id IN (' . implode(',', $membership_ids) . ') AND billing_month >= %s AND billing_month <= %s'
            . ' GROUP BY membership_id, billing_month, signature_level',
            $from_month,
            $to_month
        ), ARRAY_A);
        $counts = array();

        foreach (is_array($rows) ? $rows : array() as $row) {
            $level = Levels::normalize($row['signature_level']);
            $counts[(int) $row['membership_id']][$row['billing_month']][$level] = (isset($counts[(int) $row['membership_id']][$row['billing_month']][$level]) ? $counts[(int) $row['membership_id']][$row['billing_month']][$level] : 0) + (int) $row['total'];
        }

        return $counts;
    }

    /**
     * Jauges d'un rattachement pour un mois : niveau => consommées, incluses, restantes, dépassement.
     *
     * @param array<string,mixed> $membership ligne scp_membership
     * @param array<string,int>|null $counts niveau => nombre (sinon lu en base)
     */
    public static function gauges(array $membership, $month, $counts = null)
    {
        if ($counts === null) {
            $all = self::counts(array((int) $membership['id']), $month, $month);
            $counts = isset($all[(int) $membership['id']][$month]) ? $all[(int) $membership['id']][$month] : array();
        }

        $plan = self::plan(isset($membership['plan_id']) ? (int) $membership['plan_id'] : 0);
        $gauges = array();

        foreach (Levels::all() as $level) {
            $consumed = isset($counts[$level]) ? (int) $counts[$level] : 0;
            $included = $plan ? (int) $plan['included_' . $level] : 0;
            $unlimited = $plan && !empty($plan['unlimited_' . $level]);
            $gauges[$level] = array(
                'unlimited' => $unlimited,
                'consumed' => $consumed,
                'included' => $unlimited ? 0 : $included,
                'remaining' => $unlimited ? 0 : max(0, $included - $consumed),
                'overage' => $unlimited ? 0 : max(0, $consumed - $included),
                'price' => $plan ? (float) $plan['overage_price_' . $level] : 0.0,
                'pack_size' => $plan ? max(1, (int) $plan['recharge_size_' . $level]) : 1,
            );
        }

        return $gauges;
    }

    /**
     * Pour le sélecteur de niveau : texte court, remplissage de la jauge (0–100) et état.
     * Forfait épuisé : prix du supplément, ou place restante dans la recharge déjà entamée.
     *
     * @return array{text:string,ratio:int,state:string}
     */
    public static function credits(array $gauge)
    {
        $d = 'smbb-signaconnect-proxima';

        if (!empty($gauge['unlimited'])) {
            return array(
                'text' => $gauge['consumed'] > 0
                    ? sprintf(_n('Illimité · %d ce mois', 'Illimité · %d ce mois', $gauge['consumed'], $d), $gauge['consumed'])
                    : __('Illimité', $d),
                'ratio' => 0,
                'state' => 'unlimited',
            );
        }

        if ($gauge['remaining'] > 0) {
            return array(
                'text' => sprintf(_n('%1$d restante sur %2$d ce mois', '%1$d restantes sur %2$d ce mois', $gauge['remaining'], $d), $gauge['remaining'], $gauge['included']),
                'ratio' => (int) round($gauge['consumed'] * 100 / $gauge['included']),
                'state' => 'ok',
            );
        }

        $size = max(1, (int) $gauge['pack_size']);
        $left_in_pack = $gauge['overage'] > 0 ? ($size - $gauge['overage'] % $size) % $size : 0;

        if ($left_in_pack > 0) {
            return array(
                'text' => sprintf(_n('Forfait épuisé · %d encore comprise dans la recharge en cours', 'Forfait épuisé · %d encore comprises dans la recharge en cours', $left_in_pack, $d), $left_in_pack),
                'ratio' => 100,
                'state' => 'pack',
            );
        }

        if ($gauge['price'] <= 0) {
            return array('text' => $gauge['included'] > 0 ? __('Forfait épuisé · sans supplément', $d) : __('Sans supplément', $d), 'ratio' => 100, 'state' => 'pack');
        }

        $price = number_format((float) $gauge['price'], 2, ',', ' ') . ' €';
        $text = $size > 1
            ? sprintf(__('Supplément : recharge de %1$d à %2$s HT (%3$s / signature)', $d), $size, number_format($size * (float) $gauge['price'], 2, ',', ' ') . ' €', $price)
            : sprintf(__('Supplément : %s HT / signature', $d), $price);

        return array(
            'text' => ($gauge['included'] > 0 ? __('Forfait épuisé', $d) : __('Hors forfait', $d)) . ' · ' . $text,
            'ratio' => 100,
            'state' => 'over',
        );
    }

    public static function plan($plan_id)
    {
        global $wpdb;
        static $cache = array();

        $plan_id = (int) $plan_id;

        if ($plan_id < 1) {
            return null;
        }

        if (!array_key_exists($plan_id, $cache)) {
            $cache[$plan_id] = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . Lookup::table('scp_plan') . ' WHERE id = %d', $plan_id), ARRAY_A) ?: null;
        }

        return $cache[$plan_id];
    }

    /**
     * Les N derniers mois, du plus récent au plus ancien, sans remonter avant $first.
     *
     * @return string[]
     */
    public static function months($count, $first = '')
    {
        $months = array();
        $cursor = Billing::currentMonth();

        for ($i = 0; $i < $count && ($first === '' || $cursor >= $first); $i++) {
            $months[] = $cursor;
            $cursor = Billing::previousMonth($cursor);
        }

        return $months;
    }
}
