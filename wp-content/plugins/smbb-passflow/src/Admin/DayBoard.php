<?php

namespace Smbb\Passflow\Admin;

use Smbb\Passflow\Repository\Bookings;
use Smbb\Passflow\Repository\Lookup;
use Smbb\Passflow\Repository\Slots;
use Smbb\Passflow\Service\Documents;
use Smbb\Passflow\Support\Roles;

defined('ABSPATH') || exit;

/**
 * Mode « Jour J » : tableau de bord plein écran pour l'accueil (tablette) pendant une date.
 * Arrivées, attendus / retardataires et documents manquants, rafraîchis en direct (Ajax).
 *
 * Les pointages passent par l'Ajax de l'écran d'appel (RollCall) : mêmes règles, mêmes compteurs.
 */
final class DayBoard
{
    const AJAX = 'passflow_dayboard';

    public static function hooks()
    {
        add_action('wp_ajax_' . self::AJAX, array(__CLASS__, 'ajax'));
    }

    public static function nonce($slot_id)
    {
        return wp_create_nonce(self::AJAX . '_' . (int) $slot_id);
    }

    public static function url($slot_id)
    {
        return View::url('passflow_slot', array('view' => 'dayboard', 'id' => (int) $slot_id));
    }

    /**
     * Le mode Jour J a du sens pour une date simple (pas un pass), non annulée.
     */
    public static function available(array $slot)
    {
        return $slot['status'] !== 'cancelled' && !empty($slot['start_date']) && !\Smbb\Passflow\Service\Passes::isPass($slot);
    }

    public static function ajax()
    {
        $slot_id = isset($_REQUEST['creneau']) ? absint($_REQUEST['creneau']) : 0;
        check_ajax_referer(self::AJAX . '_' . $slot_id, 'nonce');
        $slot = Slots::find($slot_id);

        if (!$slot || !Roles::canManage()) {
            wp_send_json_error(array('message' => __('Action non autorisée.', 'smbb-passflow')), 403);
        }

        wp_send_json_success(self::data($slot));
    }

    /**
     * Instant d'une date + heure du site (fuseau WordPress) en timestamp Unix, 0 si inconnu.
     */
    private static function ts($date, $time = '')
    {
        if (empty($date)) {
            return 0;
        }

        try {
            $dt = new \DateTimeImmutable(trim($date . ' ' . ($time !== '' && $time !== null ? $time : '00:00:00')), wp_timezone());

            return $dt->getTimestamp();
        } catch (\Exception $e) {
            return 0;
        }
    }

    /**
     * Tout ce qu'affiche l'écran, en données simples (JSON).
     *
     * @param array<string,mixed> $slot
     * @return array<string,mixed>
     */
    public static function data(array $slot)
    {
        $id = (int) $slot['id'];
        $seats = Bookings::forSlot($id);
        $all_day = Lookup::allDay($slot);
        $start = $all_day ? 0 : self::ts($slot['start_date'], (string) $slot['start_time']);
        $end = $all_day || empty($slot['end_time']) ? 0 : self::ts($slot['start_date'], (string) $slot['end_time']);
        $now = time();
        $docs_on = (bool) Documents::all();
        $answers = $docs_on && $seats ? Documents::answers(array_map('intval', array_column($seats, 'id'))) : array();

        $counts = array('booked' => count($seats), 'present' => 0, 'absent' => 0, 'none' => 0, 'walkin' => 0, 'capacity' => max(0, (int) $slot['capacity']), 'docs' => 0);
        $arrivals = array();
        $expected = array();
        $missing = array();
        // Référence imprimée sur le billet (« PCCF-T-00042 ») ; un jour de pass porte celle du pass.
        $prefix = \Smbb\Passflow\Support\Settings::companyCode() !== '' ? \Smbb\Passflow\Support\Settings::companyCode() . '-' : '';

        foreach ($seats as $b) {
            $ref = $prefix . sprintf('T-%05d', !empty($b['pass_id']) ? (int) $b['pass_id'] : (int) $b['id']);
            $state = RollCall::state($b);
            $counts[$state]++;
            $counts['walkin'] += $b['source'] === 'walkin' ? 1 : 0;
            $name = Lookup::personName($b);
            $mine = isset($answers[(int) $b['id']]) ? $answers[(int) $b['id']] : array();
            $titles = $docs_on ? array_values(array_map(function ($doc) {
                return (string) $doc['title'];
            }, Documents::missing($mine))) : array();

            if ($state === 'present') {
                $at = !empty($b['checked_at']) ? self::ts(substr((string) $b['checked_at'], 0, 10), substr((string) $b['checked_at'], 11)) : 0;
                $arrivals[] = array(
                    'id' => (int) $b['id'],
                    'name' => $name,
                    'ref' => $ref,
                    'at' => $at,
                    'time' => $at ? wp_date('H:i', $at) : '',
                    'walkin' => $b['source'] === 'walkin',
                    'docs' => count($titles),
                    // Arrivé après le début (marge de 5 minutes).
                    'late' => $start && $at && $at > $start + 5 * MINUTE_IN_SECONDS ? (int) floor(($at - $start) / MINUTE_IN_SECONDS) : 0,
                );
            } elseif ($state === 'none') {
                $expected[] = array(
                    'id' => (int) $b['id'],
                    'name' => $name,
                    'ref' => $ref,
                    'phone' => (string) $b['phone'],
                    'note' => trim((string) $b['allergies']),
                    'walkin' => $b['source'] === 'walkin',
                );
            }

            // Les absents ne sont pas à relancer pour leurs documents.
            if ($titles && $state !== 'absent') {
                $counts['docs']++;
                $missing[] = array(
                    'id' => (int) $b['id'],
                    'name' => $name,
                    'ref' => $ref,
                    'titles' => $titles,
                    'here' => $state === 'present',
                    'url' => Documents::url($b, true),
                );
            }
        }

        // Dernières arrivées en tête ; présents d'abord dans les documents (ils sont là, on peut les faire signer).
        usort($arrivals, function ($a, $b) {
            return $b['at'] - $a['at'];
        });
        usort($missing, function ($a, $b) {
            return ($b['here'] ? 1 : 0) - ($a['here'] ? 1 : 0) ?: strcmp($a['name'], $b['name']);
        });

        $counts['rate'] = $counts['booked'] > 0 ? (int) round($counts['present'] * 100 / $counts['booked']) : 0;
        $today = $slot['start_date'] === current_time('Y-m-d');

        return array(
            'now' => $now,
            'start' => $start,
            'end' => $end,
            'today' => $today,
            'canPoint' => Actions::canPoint($slot),
            'phase' => !$today ? ($slot['start_date'] < current_time('Y-m-d') ? 'past' : 'future') : ($start && $now < $start ? 'before' : ($end && $now > $end ? 'after' : 'during')),
            'counts' => $counts,
            'arrivals' => $arrivals,
            'expected' => $expected,
            'missing' => $missing,
            'docsOn' => $docs_on,
        );
    }
}
