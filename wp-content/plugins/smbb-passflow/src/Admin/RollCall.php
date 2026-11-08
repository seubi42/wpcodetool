<?php

namespace Smbb\Passflow\Admin;

use Smbb\Passflow\Repository\Slots;
use Smbb\Passflow\Repository\Bookings;
use Smbb\Passflow\Repository\Lookup;
use Smbb\Passflow\Service\Booking;
use Smbb\Passflow\Service\Tickets;

defined('ABSPATH') || exit;

/**
 * Écran d'appel : enregistrement immédiat (Ajax) de chaque pointage, de chaque note et des billets scannés.
 *
 * Une requête = un participant. La réponse renvoie les compteurs du créneau pour la jauge.
 */
final class RollCall
{
    const AJAX = 'passflow_rollcall';

    public static function hooks()
    {
        add_action('wp_ajax_' . self::AJAX, array(__CLASS__, 'ajax'));
    }

    public static function nonce($slot_id)
    {
        return wp_create_nonce(self::AJAX . '_' . (int) $slot_id);
    }

    public static function ajax()
    {
        $d = 'smbb-passflow';
        $slot_id = isset($_POST['creneau']) ? absint($_POST['creneau']) : 0;
        check_ajax_referer(self::AJAX . '_' . $slot_id, 'nonce');
        $slot = Slots::find($slot_id);

        if (!$slot || !Actions::canSeeSlot($slot_id)) {
            wp_send_json_error(array('message' => __('Action non autorisée.', $d)), 403);
        }

        $op = isset($_POST['op']) ? sanitize_key(wp_unslash($_POST['op'])) : '';

        if ($op === 'scan') {
            self::scan($slot, isset($_POST['qr']) ? (string) wp_unslash($_POST['qr']) : '');
        }

        // Plusieurs téléphones pointent la même date : chacun relit l'état de tous toutes les 8 s.
        if ($op === 'sync') {
            $states = array();

            foreach (Bookings::forSlot($slot_id) as $row) {
                $states[(int) $row['id']] = self::state($row);
            }

            wp_send_json_success(array('states' => $states) + self::counts($slot_id));
        }

        $id = isset($_POST['inscription']) ? absint($_POST['inscription']) : 0;
        $booking = $id ? Bookings::find($id) : null;

        if (!$booking || (int) $booking['slot_id'] !== (int) $slot_id) {
            wp_send_json_error(array('message' => __('Participant introuvable sur cette date.', $d)));
        }

        if ($op === 'note') {
            self::saveNote($booking, isset($_POST['value']) ? (string) wp_unslash($_POST['value']) : '');
            wp_send_json_success(array('message' => __('Note enregistrée', $d)) + self::counts($slot_id));
        }

        if ($op === 'status') {
            if (!Actions::canPoint($slot, Actions::forced())) {
                wp_send_json_error(array('message' => __("Ce n'est pas le jour de l'atelier : activez « Pointer quand même » d'abord.", $d)));
            }

            $result = self::setStatus($booking, isset($_POST['value']) ? sanitize_key(wp_unslash($_POST['value'])) : '');

            if (empty($result['success'])) {
                wp_send_json_error(array('message' => $result['message']));
            }

            wp_send_json_success(array('message' => __('Enregistré', $d), 'id' => $id, 'status' => self::state(Bookings::find($id))) + self::counts($slot_id));
        }

        wp_send_json_error(array('message' => __('Action inconnue.', $d)));
    }

    /**
     * QR du billet (lien de pointage) : on retrouve la réservation de cette date, on la pointe présente.
     * Pour un pass, le QR est celui de la réservation principale : on pointe le jour affiché.
     */
    private static function scan(array $slot, $qr)
    {
        $d = 'smbb-passflow';

        if (!Actions::canPoint($slot, Actions::forced())) {
            wp_send_json_error(array('message' => __("Ce n'est pas le jour de l'atelier : activez « Pointer quand même » d'abord.", $d)));
        }

        $ticket_id = self::ticketId($qr);
        $ticket = $ticket_id ? Bookings::find($ticket_id) : null;

        if (!$ticket) {
            wp_send_json_error(array('message' => sprintf(__('Badge inconnu : %s', $d), trim($qr) !== '' ? mb_substr(trim($qr), 0, 40) : '—'), 'kind' => 'invalid'));
        }

        $match = null;

        foreach (Bookings::forSlot($slot['id']) as $row) {
            if ((int) $row['id'] === $ticket_id || (int) $row['pass_id'] === $ticket_id) {
                $match = $row;
                break;
            }
        }

        if (!$match) {
            $other = !empty($ticket['slot_id']) ? Slots::find($ticket['slot_id']) : null;
            $where = $other ? Lookup::slotLabel($other) : __('aucune date', $d);

            wp_send_json_error(array(
                'message' => sprintf(__('%1$s n\'est pas inscrit sur cette date (billet : %2$s).', $d), Lookup::personName($ticket), $where),
                'kind' => 'elsewhere',
            ));
        }

        $already = $match['status'] === 'present';

        if (!$already) {
            $result = Booking::presence((int) $match['id'], 'present');

            if (empty($result['success'])) {
                wp_send_json_error(array('message' => $result['message']));
            }
        }

        // Documents à signer encore manquants : l'écran de pointage le signale en jaune.
        $docs = array();

        if (\Smbb\Passflow\Service\Documents::enabled()) {
            foreach (\Smbb\Passflow\Service\Documents::missing(\Smbb\Passflow\Service\Documents::answersFor((int) $match['id'])) as $doc) {
                $docs[] = $doc['title'];
            }
        }

        wp_send_json_success(array(
            'id' => (int) $match['id'],
            'name' => Lookup::personName($match),
            'status' => 'present',
            'already' => $already,
            'docs' => $docs,
            'message' => sprintf($already ? __('%s était déjà pointé présent', $d) : __('%s : présent', $d), Lookup::personName($match)),
        ) + self::counts($slot['id']));
    }

    /**
     * Numéro du billet lu par la caméra, une douchette ou tapé à la main :
     * lien du QR code (jeton vérifié), référence « CODE-T-00042 », « T-00042 » ou « 42 ».
     * Sans jeton, on se fie à l'équipe connectée : elle peut de toute façon pointer à la main.
     */
    private static function ticketId($raw)
    {
        $raw = trim((string) $raw);

        if ($raw === '') {
            return 0;
        }

        if (strpos($raw, 'id=') !== false) {
            $query = array();
            parse_str((string) wp_parse_url($raw, PHP_URL_QUERY), $query);
            $id = isset($query['id']) ? absint($query['id']) : 0;
            $ticket = $id ? Bookings::find($id) : null;

            return $ticket && Tickets::valid($ticket, isset($query['k']) ? (string) $query['k'] : '') ? $id : 0;
        }

        // Douchette réglée en QWERTY sur un clavier AZERTY : « &é"'(-è_çà » au lieu des chiffres.
        if (!preg_match('/\d/', $raw)) {
            $raw = strtr($raw, array('&' => '1', 'é' => '2', '"' => '3', "'" => '4', '(' => '5', '-' => '6', 'è' => '7', '_' => '8', 'ç' => '9', 'à' => '0'));
        }

        return preg_match('/^(?:[A-Z0-9]{2,4}-)?(?:T-?)?0*(\d{1,9})$/i', $raw, $m) ? (int) $m[1] : 0;
    }

    /**
     * @return array{success:bool,message:string}
     */
    private static function setStatus(array $booking, $wanted)
    {
        $current = self::state($booking);

        if ($wanted === $current) {
            return array('success' => true, 'message' => '');
        }

        if (in_array($wanted, array('present', 'absent'), true)) {
            return Booking::presence((int) $booking['id'], $wanted);
        }

        if ($wanted === 'none') {
            return Booking::presence((int) $booking['id'], 'confirmed');
        }

        return array('success' => false, 'message' => __('Pointage impossible.', 'smbb-passflow'));
    }

    private static function saveNote(array $booking, $note)
    {
        global $wpdb;

        $note = trim(sanitize_textarea_field($note));

        if ($note === trim((string) $booking['host_note'])) {
            return;
        }

        $wpdb->update(Lookup::table('passflow_booking'), array(
            'host_note' => $note !== '' ? $note : null,
            'lastupdate_date' => current_time('mysql'),
            'lastupdate_by' => get_current_user_id() ?: null,
        ), array('id' => (int) $booking['id']));
    }

    /**
     * État d'appel d'une réservation : present, absent ou none (pas encore pointée).
     */
    public static function state(array $booking)
    {
        return in_array($booking['status'], array('present', 'absent'), true) ? $booking['status'] : 'none';
    }

    /**
     * @return array{present:int,absent:int,none:int,capacity:int}
     */
    public static function counts($slot_id)
    {
        $out = array('present' => 0, 'absent' => 0, 'none' => 0, 'extra' => 0, 'capacity' => 0);
        $slot = Slots::find($slot_id);
        $out['capacity'] = $slot ? max(0, (int) $slot['capacity']) : 0;

        foreach (Bookings::forSlot($slot_id) as $row) {
            $out[self::state($row)]++;

            // Participants imprévus ajoutés sur place (comptés aussi dans présents / absents).
            if (isset($row['source']) && $row['source'] === 'walkin') {
                $out['extra']++;
            }
        }

        return $out;
    }

    /**
     * Jauge de l'appel : présents, absents, à pointer, places libres (barre empilée + légende).
     */
    public static function gauge(array $counts)
    {
        $d = 'smbb-passflow';
        $taken = $counts['present'] + $counts['absent'] + $counts['none'];
        $total = max(1, $counts['capacity'], $taken);
        // Peut être négatif quand des imprévus ont été ajoutés au-delà de la jauge.
        $free = $counts['capacity'] - $taken;
        $bar = '';

        foreach (array('present', 'absent', 'none') as $key) {
            $bar .= '<span class="passflow-gauge-' . $key . '" data-gauge-bar="' . $key . '" style="width:' . esc_attr((string) round($counts[$key] / $total * 100, 2)) . '%"></span>';
        }

        $legend = array(
            'present' => __('présents', $d),
            'absent' => __('absents', $d),
            'none' => __('à pointer', $d),
            'extra' => __('additionnels', $d),
            'free' => __('places libres', $d),
        );
        $values = $counts + array('free' => $free, 'extra' => 0);
        $html = '<div class="passflow-gauge" data-passflow-gauge data-capacity="' . (int) $counts['capacity'] . '"><div class="passflow-gauge-bar">' . $bar . '</div><ul class="passflow-gauge-legend">';

        foreach ($legend as $key => $label) {
            $html .= '<li class="passflow-gauge-key--' . $key . ($key === 'free' && $free < 0 ? ' is-over' : '') . '"' . ($key === 'extra' && !$values['extra'] ? ' hidden' : '') . '><strong data-gauge-count="' . $key . '">' . (int) $values[$key] . '</strong> ' . esc_html($label) . '</li>';
        }

        return $html . '</ul></div>';
    }
}
