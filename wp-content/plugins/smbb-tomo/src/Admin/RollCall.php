<?php

namespace Smbb\Tomo\Admin;

use Smbb\Tomo\Repository\Creneaux;
use Smbb\Tomo\Repository\Inscriptions;
use Smbb\Tomo\Repository\Lookup;
use Smbb\Tomo\Service\Booking;
use Smbb\Tomo\Service\Tickets;

defined('ABSPATH') || exit;

/**
 * Écran d'appel : enregistrement immédiat (Ajax) de chaque pointage, de chaque note et des billets scannés.
 *
 * Une requête = un participant. La réponse renvoie les compteurs du créneau pour la jauge.
 */
final class RollCall
{
    const AJAX = 'tomo_appel';

    public static function hooks()
    {
        add_action('wp_ajax_' . self::AJAX, array(__CLASS__, 'ajax'));
    }

    public static function nonce($creneau_id)
    {
        return wp_create_nonce(self::AJAX . '_' . (int) $creneau_id);
    }

    public static function ajax()
    {
        $d = 'smbb-tomo';
        $creneau_id = isset($_POST['creneau']) ? absint($_POST['creneau']) : 0;
        check_ajax_referer(self::AJAX . '_' . $creneau_id, 'nonce');
        $creneau = Creneaux::find($creneau_id);

        if (!$creneau || !Actions::canSeeCreneau($creneau_id)) {
            wp_send_json_error(array('message' => __('Action non autorisée.', $d)), 403);
        }

        $op = isset($_POST['op']) ? sanitize_key(wp_unslash($_POST['op'])) : '';

        if ($op === 'scan') {
            self::scan($creneau, isset($_POST['qr']) ? (string) wp_unslash($_POST['qr']) : '');
        }

        $id = isset($_POST['inscription']) ? absint($_POST['inscription']) : 0;
        $inscription = $id ? Inscriptions::find($id) : null;

        if (!$inscription || (int) $inscription['creneau_id'] !== (int) $creneau_id) {
            wp_send_json_error(array('message' => __('Participant introuvable sur cette date.', $d)));
        }

        if ($op === 'note') {
            self::saveNote($inscription, isset($_POST['value']) ? (string) wp_unslash($_POST['value']) : '');
            wp_send_json_success(array('message' => __('Note enregistrée', $d)) + self::counts($creneau_id));
        }

        if ($op === 'status') {
            if (!Actions::canPoint($creneau, Actions::forced())) {
                wp_send_json_error(array('message' => __("Ce n'est pas le jour de l'atelier : activez « Pointer quand même » d'abord.", $d)));
            }

            $result = self::setStatus($inscription, isset($_POST['value']) ? sanitize_key(wp_unslash($_POST['value'])) : '');

            if (empty($result['success'])) {
                wp_send_json_error(array('message' => $result['message']));
            }

            wp_send_json_success(array('message' => __('Enregistré', $d), 'id' => $id, 'status' => self::state(Inscriptions::find($id))) + self::counts($creneau_id));
        }

        wp_send_json_error(array('message' => __('Action inconnue.', $d)));
    }

    /**
     * QR du billet (lien de pointage) : on retrouve la réservation de cette date, on la pointe présente.
     * Pour un pass, le QR est celui de la réservation principale : on pointe le jour affiché.
     */
    private static function scan(array $creneau, $qr)
    {
        $d = 'smbb-tomo';

        if (!Actions::canPoint($creneau, Actions::forced())) {
            wp_send_json_error(array('message' => __("Ce n'est pas le jour de l'atelier : activez « Pointer quand même » d'abord.", $d)));
        }

        $query = array();
        parse_str((string) wp_parse_url(trim($qr), PHP_URL_QUERY), $query);
        $ticket_id = isset($query['id']) ? absint($query['id']) : 0;
        $token = isset($query['k']) ? (string) $query['k'] : '';
        $ticket = $ticket_id ? Inscriptions::find($ticket_id) : null;

        if (!$ticket || !Tickets::valid($ticket, $token)) {
            wp_send_json_error(array('message' => __("Ce QR code n'est pas un billet valide.", $d), 'kind' => 'invalid'));
        }

        $match = null;

        foreach (Inscriptions::forCreneau($creneau['id']) as $row) {
            if ((int) $row['id'] === $ticket_id || (int) $row['pass_id'] === $ticket_id) {
                $match = $row;
                break;
            }
        }

        if (!$match) {
            $other = !empty($ticket['creneau_id']) ? Creneaux::find($ticket['creneau_id']) : null;
            $where = $other ? Lookup::creneauLabel($other) : __('aucune date', $d);

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

        wp_send_json_success(array(
            'id' => (int) $match['id'],
            'status' => 'present',
            'already' => $already,
            'message' => sprintf($already ? __('%s était déjà pointé présent', $d) : __('%s : présent', $d), Lookup::personName($match)),
        ) + self::counts($creneau['id']));
    }

    /**
     * @return array{success:bool,message:string}
     */
    private static function setStatus(array $inscription, $wanted)
    {
        $current = self::state($inscription);

        if ($wanted === $current) {
            return array('success' => true, 'message' => '');
        }

        if (in_array($wanted, array('present', 'absent'), true)) {
            return Booking::presence((int) $inscription['id'], $wanted);
        }

        if ($wanted === 'none') {
            return Booking::presence((int) $inscription['id'], 'confirmed');
        }

        return array('success' => false, 'message' => __('Pointage impossible.', 'smbb-tomo'));
    }

    private static function saveNote(array $inscription, $note)
    {
        global $wpdb;

        $note = trim(sanitize_textarea_field($note));

        if ($note === trim((string) $inscription['intervenant_note'])) {
            return;
        }

        $wpdb->update(Lookup::table('tomo_inscription'), array(
            'intervenant_note' => $note !== '' ? $note : null,
            'lastupdate_date' => current_time('mysql'),
            'lastupdate_by' => get_current_user_id() ?: null,
        ), array('id' => (int) $inscription['id']));
    }

    /**
     * État d'appel d'une réservation : present, absent ou none (pas encore pointée).
     */
    public static function state(array $inscription)
    {
        return in_array($inscription['status'], array('present', 'absent'), true) ? $inscription['status'] : 'none';
    }

    /**
     * @return array{present:int,absent:int,none:int,capacity:int}
     */
    public static function counts($creneau_id)
    {
        $out = array('present' => 0, 'absent' => 0, 'none' => 0, 'extra' => 0, 'capacity' => 0);
        $creneau = Creneaux::find($creneau_id);
        $out['capacity'] = $creneau ? max(0, (int) $creneau['capacity']) : 0;

        foreach (Inscriptions::forCreneau($creneau_id) as $row) {
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
        $d = 'smbb-tomo';
        $taken = $counts['present'] + $counts['absent'] + $counts['none'];
        $total = max(1, $counts['capacity'], $taken);
        // Peut être négatif quand des imprévus ont été ajoutés au-delà de la jauge.
        $free = $counts['capacity'] - $taken;
        $bar = '';

        foreach (array('present', 'absent', 'none') as $key) {
            $bar .= '<span class="tomo-gauge-' . $key . '" data-gauge-bar="' . $key . '" style="width:' . esc_attr((string) round($counts[$key] / $total * 100, 2)) . '%"></span>';
        }

        $legend = array(
            'present' => __('présents', $d),
            'absent' => __('absents', $d),
            'none' => __('à pointer', $d),
            'extra' => __('additionnels', $d),
            'free' => __('places libres', $d),
        );
        $values = $counts + array('free' => $free, 'extra' => 0);
        $html = '<div class="tomo-gauge" data-tomo-gauge data-capacity="' . (int) $counts['capacity'] . '"><div class="tomo-gauge-bar">' . $bar . '</div><ul class="tomo-gauge-legend">';

        foreach ($legend as $key => $label) {
            $html .= '<li class="tomo-gauge-key--' . $key . ($key === 'free' && $free < 0 ? ' is-over' : '') . '"' . ($key === 'extra' && !$values['extra'] ? ' hidden' : '') . '><strong data-gauge-count="' . $key . '">' . (int) $values[$key] . '</strong> ' . esc_html($label) . '</li>';
        }

        return $html . '</ul></div>';
    }
}
