<?php

namespace Smbb\Passflow\Service;

use Smbb\Passflow\Repository\Lookup;
use Smbb\Passflow\Repository\Slots;
use Smbb\Passflow\Support\Choices;
use Smbb\Passflow\WooCommerce\Module;
use Smbb\Passflow\WooCommerce\OnsiteSales;

defined('ABSPATH') || exit;

/**
 * Comparaison de plusieurs dates (créneaux) ou de plusieurs ateliers sur une période :
 * remplissage, présence et chiffre d'affaires, avec les mêmes définitions que le Bilan d'une journée.
 * Mêmes chiffres pour l'écran (page « Comparer ») et pour l'export CSV.
 */
final class Compare
{
    /** Nombre maximum de colonnes comparées côte à côte. */
    const MAX = 8;

    /**
     * Indicateurs, dans l'ordre d'affichage : clé => (libellé, format, sens).
     * Format : int, pct (0..1), money. Sens : 1 = plus c'est haut mieux c'est, -1 = l'inverse, 0 = neutre.
     *
     * @return array<string,array{0:string,1:string,2:int,3:string}>
     */
    public static function metrics()
    {
        $d = 'smbb-passflow';

        return array(
            'sessions' => array(__('Dates', $d), 'int', 0, 'fill'),
            'capacity' => array(__('Places proposées', $d), 'int', 0, 'fill'),
            'booked' => array(__('Inscrits', $d), 'int', 1, 'fill'),
            'fill' => array(__('Remplissage', $d), 'pct', 1, 'fill'),
            'waiting' => array(__("Liste d'attente", $d), 'int', 0, 'fill'),
            'cancelled' => array(__('Annulations', $d), 'int', -1, 'fill'),
            'present' => array(__('Présents', $d), 'int', 1, 'presence'),
            'rate' => array(__('Taux de présence', $d), 'pct', 1, 'presence'),
            'absent' => array(__('Absents', $d), 'int', -1, 'presence'),
            'unpointed' => array(__('Non pointés', $d), 'int', -1, 'presence'),
            'walkin' => array(__('Imprévus (ajoutés sur place)', $d), 'int', 0, 'presence'),
            'revenue_bookings' => array(__('Montant des réservations', $d), 'money', 1, 'revenue'),
            'revenue_onsite' => array(__('Ventes sur place', $d), 'money', 1, 'revenue'),
            'revenue' => array(__("Chiffre d'affaires total", $d), 'money', 1, 'revenue'),
            'per_booked' => array(__('CA moyen par inscrit', $d), 'money', 1, 'revenue'),
        );
    }

    /**
     * Groupes d'indicateurs (blocs du tableau et du CSV).
     *
     * @return array<string,string>
     */
    public static function groups()
    {
        $d = 'smbb-passflow';

        return array(
            'fill' => __('Remplissage', $d),
            'presence' => __('Présence', $d),
            'revenue' => __("Chiffre d'affaires", $d),
        );
    }

    /**
     * Lit la demande (URL de la page ou du lien d'export) : quoi comparer, sur quelle période, la référence.
     * comparer=dates|ateliers, creneaux=1,2,3 (ou creneaux[]), ateliers=4,5, from/to, ref, depuis (créneau du Bilan).
     *
     * @param array<string,mixed> $q
     * @return array<string,mixed>
     */
    public static function query(array $q)
    {
        $ids = function ($value) {
            if (is_string($value)) {
                $value = explode(',', $value);
            }

            return is_array($value) ? array_values(array_unique(array_filter(array_map('absint', $value)))) : array();
        };
        $date = function ($key) use ($q) {
            $value = isset($q[$key]) ? sanitize_text_field(wp_unslash((string) $q[$key])) : '';

            return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : '';
        };

        $mode = isset($q['comparer']) && $q['comparer'] === 'ateliers' ? 'activities' : 'slots';
        $slot_ids = $ids(isset($q['creneaux']) ? $q['creneaux'] : array());
        $activity_ids = $ids(isset($q['ateliers']) ? $q['ateliers'] : array());
        $from = $date('from');
        $to = $date('to');
        $since = isset($q['depuis']) ? absint($q['depuis']) : 0;
        $ref = isset($q['ref']) ? absint($q['ref']) : 0;

        // Ouvert depuis le Bilan : la date, plus les 5 précédentes du même atelier.
        if ($mode === 'slots' && !$slot_ids && $since && ($origin = Slots::find($since))) {
            $before = Slots::between(null, $origin['start_date'], array('activity_id' => (int) $origin['activity_id'], 'statuses' => array('open', 'full', 'draft')));
            $before = array_values(array_filter($before, function ($s) use ($since, $origin) {
                return (int) $s['id'] !== $since && ($s['start_date'] < $origin['start_date'] || ($s['start_date'] === $origin['start_date'] && (string) $s['start_time'] < (string) $origin['start_time'])) && (int) $s['booked'] > 0;
            }));
            $slot_ids = array_merge(array($since), array_reverse(array_map('intval', array_column(array_slice($before, -5), 'id'))));
            $ref = $ref ?: $since;
        }

        if ($from === '' || $to === '' || $from > $to) {
            $today = current_time('Y-m-d');
            $from = gmdate('Y-m-d', strtotime($today . ' -6 months'));
            $to = $mode === 'slots' ? gmdate('Y-m-d', strtotime($today . ' +1 month')) : $today;
        }

        $columns = $mode === 'activities'
            ? self::byActivities($activity_ids, $from, $to)
            : self::bySlots($slot_ids);

        // Référence : la colonne demandée, sinon la première.
        $ref_index = 0;

        foreach ($columns as $i => $c) {
            if ($ref && $c['id'] === $ref) {
                $ref_index = $i;
            }
        }

        return array(
            'mode' => $mode,
            'slot_ids' => $slot_ids,
            'activity_ids' => $activity_ids,
            'from' => $from,
            'to' => $to,
            'since' => $since,
            'columns' => $columns,
            'ref' => $columns ? $columns[$ref_index]['id'] : 0,
            'ref_index' => $ref_index,
            'title' => $mode === 'activities'
                ? sprintf(__('Ateliers du %1$s au %2$s', 'smbb-passflow'), date_i18n('j M Y', strtotime($from)), date_i18n('j M Y', strtotime($to)))
                : __('Dates choisies', 'smbb-passflow'),
        );
    }

    /**
     * Paramètres d'URL qui redonnent la même comparaison.
     *
     * @param array<string,mixed> $c résultat de query()
     * @return array<string,string>
     */
    public static function args(array $c)
    {
        if ($c['mode'] === 'activities') {
            return array('comparer' => 'ateliers', 'ateliers' => implode(',', $c['activity_ids']), 'from' => $c['from'], 'to' => $c['to'], 'ref' => (string) $c['ref']);
        }

        return array('comparer' => 'dates', 'creneaux' => implode(',', array_column($c['columns'], 'id')), 'ref' => (string) $c['ref']);
    }

    /**
     * Colonnes « une date chacune ».
     *
     * @param int[] $slot_ids
     * @return array<int,array<string,mixed>>
     */
    public static function bySlots(array $slot_ids)
    {
        $slots = array();

        foreach (array_slice(array_values(array_unique(array_filter(array_map('intval', $slot_ids)))), 0, self::MAX) as $id) {
            $slot = Slots::find($id);

            // Les pass regroupent plusieurs jours : ce ne sont pas des séances comparables.
            if ($slot && !Passes::isPass($slot)) {
                $slots[] = $slot;
            }
        }

        usort($slots, function ($a, $b) {
            return strcmp($a['start_date'] . ' ' . $a['start_time'], $b['start_date'] . ' ' . $b['start_time']);
        });

        $stats = self::slotStats($slots);
        $columns = array();

        foreach ($slots as $slot) {
            $row = $stats[(int) $slot['id']];
            $columns[] = array(
                'key' => 'slot-' . (int) $slot['id'],
                'id' => (int) $slot['id'],
                'title' => Lookup::activityName($slot['activity_id']),
                'subtitle' => Lookup::slotWhen($slot),
                'status' => $slot['status'],
                'past' => Slots::isPast($slot),
                'values' => self::finish($row),
            );
        }

        return $columns;
    }

    /**
     * Colonnes « un atelier chacune », toutes ses dates de la période additionnées.
     *
     * @param int[] $activity_ids vide = tous les ateliers qui ont eu des dates sur la période
     * @return array<int,array<string,mixed>>
     */
    public static function byActivities(array $activity_ids, $from, $to)
    {
        $wanted = array_values(array_unique(array_filter(array_map('intval', $activity_ids))));
        $slots = Slots::between($from, $to, array('statuses' => array('open', 'full', 'draft')));

        $slots = array_values(array_filter($slots, function ($slot) use ($wanted) {
            // Brouillon vide : pas encore proposé, il fausserait le remplissage.
            if ($slot['status'] === 'draft' && (int) $slot['booked'] === 0) {
                return false;
            }

            return !$wanted || in_array((int) $slot['activity_id'], $wanted, true);
        }));

        $stats = self::slotStats($slots);
        $sums = array();

        foreach ($slots as $slot) {
            $aid = (int) $slot['activity_id'];

            if (!isset($sums[$aid])) {
                $sums[$aid] = self::blank();
            }

            foreach ($stats[(int) $slot['id']] as $key => $value) {
                $sums[$aid][$key] += $value;
            }
        }

        // L'ordre de la sélection, sinon du plus gros chiffre d'affaires au plus petit.
        $order = $wanted ?: array_keys($sums);

        if (!$wanted) {
            usort($order, function ($a, $b) use ($sums) {
                return ($sums[$b]['revenue_bookings'] + $sums[$b]['revenue_onsite']) <=> ($sums[$a]['revenue_bookings'] + $sums[$a]['revenue_onsite']);
            });
            $order = array_slice($order, 0, self::MAX);
        }

        $columns = array();

        foreach (array_slice($order, 0, self::MAX) as $aid) {
            $row = isset($sums[$aid]) ? $sums[$aid] : self::blank();
            $columns[] = array(
                'key' => 'activity-' . $aid,
                'id' => $aid,
                'title' => Lookup::activityName($aid),
                'subtitle' => sprintf(_n('%d date', '%d dates', $row['sessions'], 'smbb-passflow'), $row['sessions']),
                'status' => '',
                'past' => $to < current_time('Y-m-d'),
                'values' => self::finish($row),
            );
        }

        return $columns;
    }

    /**
     * Écart d'une valeur par rapport à la référence : texte « +12 % » / « -3 pts » et tonalité.
     *
     * @return array{text:string,tone:string}|null
     */
    public static function delta($key, $value, $reference)
    {
        $metrics = self::metrics();

        if (!isset($metrics[$key]) || $value === null || $reference === null) {
            return null;
        }

        list(, $format, $sense) = $metrics[$key];

        if ($format === 'pct') {
            $diff = ($value - $reference) * 100;

            if (abs($diff) < 0.5) {
                return array('text' => '=', 'tone' => 'same');
            }

            $text = sprintf(__('%s pts', 'smbb-passflow'), ($diff > 0 ? '+' : '') . number_format_i18n($diff, 0));
        } else {
            $diff = $value - $reference;

            if (abs($diff) < 0.005) {
                return array('text' => '=', 'tone' => 'same');
            }

            if ((float) $reference === 0.0) {
                $text = ($diff > 0 ? '+' : '') . ($format === 'money' ? self::money($diff) : number_format_i18n($diff));
            } else {
                $text = sprintf('%s %%', ($diff > 0 ? '+' : '') . number_format_i18n($diff * 100 / abs($reference), 0));
            }
        }

        $tone = $sense === 0 ? 'neutral' : (($diff > 0) === ($sense > 0) ? 'up' : 'down');

        return array('text' => $text, 'tone' => $tone);
    }

    /**
     * Valeur lisible d'un indicateur (texte brut, à échapper).
     */
    public static function format($key, $value)
    {
        $metrics = self::metrics();
        $format = isset($metrics[$key]) ? $metrics[$key][1] : 'int';

        if ($value === null) {
            return '–';
        }

        if ($format === 'pct') {
            return number_format_i18n($value * 100, 0) . ' %';
        }

        if ($format === 'money') {
            return self::money($value);
        }

        return number_format_i18n($value);
    }

    /**
     * Comparaison en CSV (« ; » et BOM pour Excel) : une ligne par indicateur, une colonne par date ou atelier.
     *
     * @param array<int,array<string,mixed>> $columns
     */
    public static function csv(array $columns, $title)
    {
        $d = 'smbb-passflow';
        $metrics = self::metrics();

        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . sanitize_file_name('comparaison-' . current_time('Y-m-d') . '.csv') . '"');

        $out = fopen('php://output', 'w');
        $put = function (array $row) use ($out) {
            fputcsv($out, $row, ';');
        };
        $raw = function ($key, $value) use ($metrics) {
            if ($value === null) {
                return '';
            }

            if ($metrics[$key][1] === 'pct') {
                return (string) round($value * 100);
            }

            return $metrics[$key][1] === 'money' ? number_format((float) $value, 2, ',', '') : (string) $value;
        };

        fwrite($out, "\xEF\xBB\xBF");
        $put(array(__('Comparaison', $d), $title));
        $put(array());
        $put(array_merge(array(__('Indicateur', $d)), array_map(function ($c) {
            return $c['title'] . ' · ' . $c['subtitle'];
        }, $columns)));

        foreach (self::groups() as $group => $group_label) {
            $put(array($group_label));

            foreach ($metrics as $key => $metric) {
                if ($metric[3] !== $group || !self::shown($key, $columns)) {
                    continue;
                }

                $label = $metric[0] . ($metric[1] === 'pct' ? ' (%)' : '');
                $put(array_merge(array($label), array_map(function ($c) use ($key, $raw) {
                    return $raw($key, $c['values'][$key]);
                }, $columns)));
            }
        }

        fclose($out);
        exit;
    }

    /**
     * Un indicateur mérite-t-il sa ligne ? (« Dates » seulement quand une colonne en regroupe plusieurs,
     * les lignes toujours à zéro sont masquées.)
     */
    public static function shown($key, array $columns)
    {
        if ($key === 'sessions') {
            foreach ($columns as $c) {
                if ($c['values']['sessions'] > 1) {
                    return true;
                }
            }

            return false;
        }

        if (in_array($key, array('waiting', 'walkin', 'revenue_onsite', 'cancelled', 'unpointed'), true)) {
            foreach ($columns as $c) {
                if (!empty($c['values'][$key])) {
                    return true;
                }
            }

            return false;
        }

        return true;
    }

    // ---------------------------------------------------------------------------------------

    private static function blank()
    {
        return array(
            'sessions' => 0, 'capacity' => 0, 'booked' => 0, 'waiting' => 0, 'cancelled' => 0,
            'present' => 0, 'absent' => 0, 'unpointed' => 0, 'walkin' => 0,
            'revenue_bookings' => 0.0, 'revenue_onsite' => 0.0,
        );
    }

    /**
     * Ratios calculés à partir des sommes (jamais de moyenne de pourcentages).
     */
    private static function finish(array $row)
    {
        $row['fill'] = $row['capacity'] > 0 ? min(1, $row['booked'] / $row['capacity']) : null;
        // Comme le Bilan : présents sur inscrits.
        $row['rate'] = $row['booked'] > 0 ? $row['present'] / $row['booked'] : null;
        $row['revenue'] = $row['revenue_bookings'] + $row['revenue_onsite'];
        $row['per_booked'] = $row['booked'] > 0 ? $row['revenue'] / $row['booked'] : null;

        return $row;
    }

    /**
     * Chiffres bruts de chaque créneau, en deux requêtes (inscriptions) plus les ventes sur place.
     *
     * @param array<int,array<string,mixed>> $slots
     * @return array<int,array<string,int|float>>
     */
    private static function slotStats(array $slots)
    {
        global $wpdb;

        $out = array();

        foreach ($slots as $slot) {
            $row = self::blank();
            $row['sessions'] = 1;
            $row['capacity'] = max(0, (int) $slot['capacity']);
            $row['waiting'] = isset($slot['waiting']) ? (int) $slot['waiting'] : 0;
            $out[(int) $slot['id']] = $row;
        }

        if (!$out) {
            return $out;
        }

        $ids = implode(',', array_keys($out));
        $rows = $wpdb->get_results('SELECT slot_id, status, source, COUNT(*) AS total, SUM(COALESCE(price_paid, 0)) AS amount FROM ' . Lookup::table('passflow_booking')
            . ' WHERE deleted_flag = 0 AND slot_id IN (' . $ids . ') GROUP BY slot_id, status, source', ARRAY_A);
        $seat = Choices::seatStatuses();

        foreach (is_array($rows) ? $rows : array() as $r) {
            $id = (int) $r['slot_id'];
            $n = (int) $r['total'];

            if (!in_array($r['status'], $seat, true)) {
                // Même règle que le Bilan : tout ce qui n'occupe plus de place compte comme annulé.
                $out[$id]['cancelled'] += $r['status'] === 'cancelled' ? $n : 0;
                continue;
            }

            $out[$id]['booked'] += $n;
            $out[$id]['revenue_bookings'] += (float) $r['amount'];
            $out[$id]['walkin'] += $r['source'] === 'walkin' ? $n : 0;

            if ($r['status'] === 'present') {
                $out[$id]['present'] += $n;
            } elseif ($r['status'] === 'absent') {
                $out[$id]['absent'] += $n;
            } else {
                $out[$id]['unpointed'] += $n;
            }
        }

        foreach (self::onsiteBySlot(array_keys($out), $slots) as $id => $amount) {
            if (isset($out[$id])) {
                $out[$id]['revenue_onsite'] += $amount;
            }
        }

        return $out;
    }

    /**
     * Encaissé à la caisse par créneau (payé, net des retours), comme OnsiteSales::summary.
     *
     * @param int[] $ids
     * @param array<int,array<string,mixed>> $slots
     * @return array<int,float>
     */
    private static function onsiteBySlot(array $ids, array $slots)
    {
        if (!Module::active() || !function_exists('wc_get_orders')) {
            return array();
        }

        $out = array();

        if (count($ids) <= 12) {
            foreach ($ids as $id) {
                $out[(int) $id] = (float) OnsiteSales::summary(OnsiteSales::forSlot((int) $id))['paid'];
            }

            return $out;
        }

        // Beaucoup de dates : une seule requête sur les ventes de caisse de la période, rangées par créneau.
        $dates = array_filter(array_column($slots, 'start_date'));
        $from = gmdate('Y-m-d', strtotime(min($dates) . ' -1 day'));
        $to = gmdate('Y-m-d', strtotime(max($dates) . ' +7 days'));
        $orders = wc_get_orders(array('limit' => -1, 'created_via' => OnsiteSales::GATEWAY, 'date_created' => $from . '...' . $to));
        $grouped = array();

        foreach ($orders as $order) {
            $sid = (int) $order->get_meta(OnsiteSales::META_SLOT);

            if (in_array($sid, $ids, true)) {
                $grouped[$sid][] = $order;
            }
        }

        foreach ($grouped as $sid => $list) {
            $out[$sid] = (float) OnsiteSales::summary($list)['paid'];
        }

        return $out;
    }

    private static function money($amount)
    {
        return Module::active() ? OnsiteSales::money($amount) : number_format_i18n((float) $amount, 2) . ' €';
    }
}
