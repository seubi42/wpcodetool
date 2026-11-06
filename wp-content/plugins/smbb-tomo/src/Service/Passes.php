<?php

namespace Smbb\Tomo\Service;

use Smbb\Tomo\Repository\Creneaux;
use Smbb\Tomo\Repository\Inscriptions;
use Smbb\Tomo\Repository\Lookup;

defined('ABSPATH') || exit;

/**
 * Pass plusieurs jours (exemple : week-end samedi + dimanche).
 *
 * - Un pass est un créneau de type « pass » (kind = pass) qui regroupe des créneaux jour
 *   (pass_creneau_ids). Il n'a ni appel ni jauge propre : ses places restantes sont le minimum
 *   des jours inclus (et de sa propre jauge si elle est renseignée).
 * - Réserver un pass crée une inscription « mère » sur le pass (elle porte le prix, le billet, le QR
 *   et les documents), puis une inscription « fille » par jour (pass_id = id de la mère), pointée à
 *   l'appel de chaque jour.
 * - Les changements de la mère (confirmation, annulation, déplacement) suivent sur les filles.
 *   Une fille peut être annulée seule (client absent un des jours) sans toucher au reste.
 */
final class Passes
{
    const KIND = 'pass';

    /** @var bool évite de propager une cascade dans une autre */
    private static $busy = false;

    public static function isPass($creneau)
    {
        return is_array($creneau) && isset($creneau['kind']) && $creneau['kind'] === self::KIND;
    }

    /**
     * Ids des jours d'un pass, dans l'ordre des dates.
     *
     * @return int[]
     */
    public static function dayIds(array $pass)
    {
        return Lookup::ids(isset($pass['pass_creneau_ids']) ? $pass['pass_creneau_ids'] : array());
    }

    /**
     * Créneaux jour d'un pass, avec leur remplissage, triés par date.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function days(array $pass)
    {
        $days = array();

        foreach (self::dayIds($pass) as $id) {
            $day = Creneaux::find($id);

            if ($day && !self::isPass($day)) {
                $days[] = $day;
            }
        }

        usort($days, function ($a, $b) {
            return strcmp($a['start_date'] . ' ' . $a['start_time'], $b['start_date'] . ' ' . $b['start_time']);
        });

        return $days;
    }

    /**
     * Passes qui incluent un créneau jour.
     *
     * @return int[]
     */
    public static function passesOf($day_id)
    {
        global $wpdb;

        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT id, pass_creneau_ids FROM ' . Lookup::table('tomo_creneau') . " WHERE deleted_flag = 0 AND kind = %s AND status IN ('draft','open','full')",
            self::KIND
        ), ARRAY_A);
        $ids = array();

        foreach (is_array($rows) ? $rows : array() as $row) {
            if (in_array((int) $day_id, Lookup::ids($row['pass_creneau_ids']), true)) {
                $ids[] = (int) $row['id'];
            }
        }

        return $ids;
    }

    /**
     * Places restantes d'un pass : le jour le plus rempli décide.
     *
     * @param array<string,mixed> $pass ligne de créneau (avec capacity et booked)
     */
    public static function remaining(array $pass)
    {
        $days = self::days($pass);

        if (!$days) {
            return 0;
        }

        $remaining = null;

        foreach ($days as $day) {
            $left = $day['status'] === 'cancelled' ? 0 : (int) $day['remaining'];
            $remaining = $remaining === null ? $left : min($remaining, $left);
        }

        if ((int) $pass['capacity'] > 0) {
            $remaining = min($remaining, max(0, (int) $pass['capacity'] - (int) $pass['booked']));
        }

        return max(0, (int) $remaining);
    }

    /**
     * Libellé court des jours : « sam. 6 et dim. 7 juin 2026 ».
     */
    public static function daysLabel(array $pass)
    {
        $labels = array();

        foreach (self::days($pass) as $day) {
            $labels[] = date_i18n('D j M Y', strtotime($day['start_date'] . ' 12:00:00'));
        }

        if (count($labels) < 2) {
            return implode('', $labels);
        }

        $last = array_pop($labels);

        return implode(', ', $labels) . ' ' . __('et', 'smbb-tomo') . ' ' . $last;
    }

    /**
     * Inscription qui porte le billet, le QR et les documents : la mère pour une fille, sinon elle-même.
     *
     * @param array<string,mixed> $inscription
     * @return array<string,mixed>
     */
    public static function root(array $inscription)
    {
        if (empty($inscription['pass_id'])) {
            return $inscription;
        }

        $mother = Inscriptions::find($inscription['pass_id']);

        return $mother ?: $inscription;
    }

    /**
     * Inscriptions filles d'une mère (annulées comprises si demandé).
     *
     * @return array<int,array<string,mixed>>
     */
    public static function children($mother_id, $with_cancelled = false)
    {
        global $wpdb;

        $sql = 'SELECT i.*, c.start_date, c.start_time, c.end_time FROM ' . Lookup::table('tomo_inscription') . ' i'
            . ' LEFT JOIN ' . Lookup::table('tomo_creneau') . ' c ON c.id = i.creneau_id'
            . ' WHERE i.deleted_flag = 0 AND i.pass_id = %d' . ($with_cancelled ? '' : " AND i.status <> 'cancelled'")
            . ' ORDER BY c.start_date ASC, c.start_time ASC, i.id ASC';
        $rows = $wpdb->get_results($wpdb->prepare($sql, (int) $mother_id), ARRAY_A);

        return is_array($rows) ? $rows : array();
    }

    /**
     * Fille du jour d'une mère (pour le pointage par QR), ou null.
     */
    public static function childOn($mother_id, $date)
    {
        foreach (self::children($mother_id) as $child) {
            if ($child['start_date'] === $date) {
                return Inscriptions::find($child['id']);
            }
        }

        return null;
    }

    /**
     * Crée une fille par jour du pass pour une mère qui n'en a pas (ou plus d'actives).
     *
     * @return int nombre de filles créées
     */
    public static function createChildren($mother_id)
    {
        $mother = Inscriptions::find($mother_id);
        $pass = $mother && !empty($mother['creneau_id']) ? Creneaux::find($mother['creneau_id']) : null;

        if (!$mother || !self::isPass($pass) || $mother['status'] === 'cancelled') {
            return 0;
        }

        // Un jour déjà présent, même annulé seul par l'équipe, n'est pas recréé.
        $existing = array();

        foreach (self::children($mother['id'], true) as $child) {
            $existing[(int) $child['creneau_id']] = true;
        }

        $status = $mother['status'] === 'pending' ? 'pending' : 'confirmed';
        $created = 0;

        foreach (self::days($pass) as $day) {
            if (isset($existing[(int) $day['id']]) || $day['status'] === 'cancelled') {
                continue;
            }

            $id = Booking::insertInscription(array(
                'creneau_id' => (int) $day['id'],
                'atelier_id' => (int) $day['atelier_id'],
                'customer_id' => $mother['customer_id'] ? (int) $mother['customer_id'] : null,
                'first_name' => $mother['first_name'],
                'last_name' => $mother['last_name'],
                'email' => $mother['email'],
                'phone' => $mother['phone'],
                'allergies' => $mother['allergies'],
                'level' => $mother['level'],
                'age' => $mother['age'] !== null && $mother['age'] !== '' ? (int) $mother['age'] : null,
                'notes' => $mother['notes'],
                'source' => $mother['source'],
                'order_id' => $mother['order_id'] ? (int) $mother['order_id'] : null,
                'order_item_id' => $mother['order_item_id'] ? (int) $mother['order_item_id'] : null,
                'price_paid' => 0,
                'status' => $status,
                'pass_id' => (int) $mother['id'],
            ));

            if ($id) {
                $created++;
                History::record($id, 'created', sprintf(__('Jour du pass %1$s (réservation T-%2$05d).', 'smbb-tomo'), Lookup::creneauLabel($pass), (int) $mother['id']), (int) $day['id']);
                Capacity::refresh($day['id']);
            }
        }

        return $created;
    }

    /**
     * Après une modification de la mère : les filles suivent.
     *
     * - mère annulée, ou reportée sans date : filles annulées ;
     * - mère confirmée : filles à confirmer ou annulées en même temps repassent confirmées ;
     * - mère déplacée : anciennes filles annulées, nouvelles filles créées si la cible est un pass.
     *
     * @param array<string,mixed> $data colonnes modifiées
     */
    public static function cascade($inscription_id, array $data)
    {
        if (self::$busy || (!isset($data['status']) && !array_key_exists('creneau_id', $data))) {
            return;
        }

        $mother = Inscriptions::find($inscription_id);

        if (!$mother || !empty($mother['pass_id'])) {
            return;
        }

        $children = self::children($mother['id'], true);
        $pass = !empty($mother['creneau_id']) ? Creneaux::find($mother['creneau_id']) : null;

        if (!$children && !self::isPass($pass)) {
            return;
        }

        self::$busy = true;
        $touched = array();
        $moved = array_key_exists('creneau_id', $data);
        $active_days = array();

        foreach ($children as $child) {
            if ($child['status'] !== 'cancelled') {
                $active_days[(int) $child['creneau_id']] = true;
            }
        }

        $target_days = self::isPass($pass) ? array_map('intval', array_column(self::days($pass), 'id')) : array();
        $same_days = $moved && $target_days && !array_diff(array_keys($active_days), $target_days) && !array_diff($target_days, array_keys($active_days));

        if ($mother['status'] === 'cancelled' || !self::isPass($pass) || ($moved && !$same_days)) {
            $keep = $mother['status'] !== 'cancelled' && self::isPass($pass) ? $target_days : array();
            $status = $mother['status'] === 'pending' ? 'pending' : 'confirmed';

            foreach ($children as $child) {
                // Déplacé vers un autre pass : les jours communs sont gardés (ou repris).
                if (in_array((int) $child['creneau_id'], $keep, true)) {
                    if ($child['status'] === 'cancelled') {
                        self::setChild($child['id'], array('status' => $status, 'checked_at' => null));
                        History::record($child['id'], 'moved', __('Jour repris dans le nouveau pass.', 'smbb-tomo'), (int) $child['creneau_id']);
                        $touched[(int) $child['creneau_id']] = true;
                    }

                    continue;
                }

                if ($child['status'] !== 'cancelled') {
                    self::setChild($child['id'], array('status' => 'cancelled', 'checked_at' => null));
                    History::record($child['id'], 'cancelled', __('Jour du pass annulé avec la réservation principale.', 'smbb-tomo'), (int) $child['creneau_id']);
                    $touched[(int) $child['creneau_id']] = true;
                }
            }
        } elseif (in_array($mother['status'], array('confirmed', 'present', 'absent'), true)) {
            // Des jours annulés ne reviennent que s'ils l'avaient tous été avec la réservation principale
            // (paiement reçu après libération) ; un jour annulé seul par l'équipe le reste.
            $revive = !$active_days;

            foreach ($children as $child) {
                if (($child['status'] === 'pending' || ($revive && $child['status'] === 'cancelled')) && in_array((int) $child['creneau_id'], $target_days, true)) {
                    self::setChild($child['id'], array('status' => 'confirmed', 'checked_at' => null));
                    History::record($child['id'], 'confirmed', __('Jour du pass confirmé avec la réservation principale.', 'smbb-tomo'), (int) $child['creneau_id']);
                    $touched[(int) $child['creneau_id']] = true;
                }
            }
        }

        self::$busy = false;

        foreach (array_keys($touched) as $creneau_id) {
            Capacity::refresh($creneau_id);
        }

        if (self::isPass($pass) && $mother['status'] !== 'cancelled') {
            self::createChildren($mother['id']);
        }
    }

    /**
     * Nombre de mères actives d'un pass, et personnes inscrites par jour.
     *
     * @return array<int,array<string,mixed>> mères avec leurs filles
     */
    public static function holders($pass_id)
    {
        $rows = Inscriptions::forCreneau($pass_id, true);

        foreach ($rows as &$row) {
            $row['children'] = self::children($row['id'], true);
        }
        unset($row);

        return $rows;
    }

    private static function setChild($id, array $data)
    {
        global $wpdb;

        $data['lastupdate_date'] = current_time('mysql');
        $data['lastupdate_by'] = get_current_user_id() ?: null;

        return $wpdb->update(Lookup::table('tomo_inscription'), $data, array('id' => (int) $id));
    }
}
