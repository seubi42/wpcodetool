<?php

namespace Smbb\Passflow\Service;

use Smbb\Passflow\Repository\Slots;
use Smbb\Passflow\Repository\Lookup;
use Smbb\Passflow\Support\Roles;
use Smbb\Passflow\Support\Settings;

defined('ABSPATH') || exit;

/**
 * Disponibilités des intervenants sur les dates où ils sont prévus.
 *
 * Une ligne par (créneau, intervenant) dans passflow_availability : réponse (yes / no / vide), date de la
 * demande, date et auteur de la réponse. L'intervenant répond depuis son espace (liste ou calendrier),
 * l'équipe peut répondre à sa place depuis la fiche de la date.
 */
final class Availability
{
    const AJAX = 'passflow_availability';

    public static function hooks()
    {
        add_action('wp_ajax_' . self::AJAX, array(__CLASS__, 'ajax'));
    }

    // ------------------------------------------------------------------
    // Lecture
    // ------------------------------------------------------------------

    /**
     * Réponses par date puis par intervenant : array(slot_id => array(host_id => row)).
     *
     * @param int[] $slot_ids
     * @return array<int,array<int,array<string,mixed>>>
     */
    public static function forSlots(array $slot_ids)
    {
        global $wpdb;

        $ids = array_values(array_filter(array_map('intval', $slot_ids)));

        if (!$ids) {
            return array();
        }

        $rows = $wpdb->get_results('SELECT * FROM ' . Lookup::table('passflow_availability') . ' WHERE deleted_flag = 0 AND slot_id IN (' . implode(',', $ids) . ') ORDER BY id ASC', ARRAY_A);
        $out = array();

        foreach (is_array($rows) ? $rows : array() as $row) {
            $out[(int) $row['slot_id']][(int) $row['host_id']] = $row;
        }

        return $out;
    }

    /**
     * @return array<string,mixed>|null
     */
    public static function get($slot_id, $host_id)
    {
        $all = self::forSlots(array((int) $slot_id));

        return isset($all[(int) $slot_id][(int) $host_id]) ? $all[(int) $slot_id][(int) $host_id] : null;
    }

    /**
     * yes, no, asked (demandé, pas de réponse) ou none (jamais demandé).
     *
     * @param array<string,mixed>|null $row
     */
    public static function state($row)
    {
        if (!$row) {
            return 'none';
        }

        if (in_array($row['answer'], array('yes', 'no'), true)) {
            return $row['answer'];
        }

        return !empty($row['asked_at']) ? 'asked' : 'none';
    }

    /**
     * Pastille : DISPO (vert), NON DISPO (rouge), EN ATTENTE (orange), À DEMANDER (gris).
     */
    public static function badge($state)
    {
        $d = 'smbb-passflow';
        $map = array(
            'yes' => array('confirmed', __('Dispo', $d)),
            'no' => array('absent', __('Non dispo', $d)),
            'asked' => array('pending', __('En attente', $d)),
            'none' => array('draft', __('À demander', $d)),
        );
        $item = isset($map[$state]) ? $map[$state] : $map['none'];

        return '<span class="passflow-badge passflow-badge--' . $item[0] . ' passflow-availability-badge" data-availability-state="' . esc_attr($state) . '">' . esc_html($item[1]) . '</span>';
    }

    /**
     * L'intervenant est prévu sur la date, ou on lui a demandé s'il était disponible (demande groupée).
     */
    public static function concerns(array $slot, $host_id)
    {
        return in_array((int) $host_id, Lookup::ids($slot['host_ids']), true) || self::get($slot['id'], $host_id) !== null;
    }

    /**
     * Une date réclame-t-elle encore des réponses ? (à venir, ni annulée ni pass)
     */
    public static function open(array $slot)
    {
        return $slot['status'] !== 'cancelled' && !Passes::isPass($slot) && !Slots::isPast($slot);
    }

    // ------------------------------------------------------------------
    // Écriture
    // ------------------------------------------------------------------

    /**
     * Enregistre une réponse. $source : intervenant (depuis son espace) ou admin (à sa place).
     *
     * @return array<string,mixed>|null la ligne à jour
     */
    public static function set($slot_id, $host_id, $answer, $source = 'admin')
    {
        global $wpdb;

        $answer = in_array($answer, array('yes', 'no'), true) ? $answer : '';
        $row = self::get($slot_id, $host_id);
        $data = array(
            'answer' => $answer,
            'answered_at' => $answer !== '' ? current_time('mysql') : null,
            'answered_by' => $answer !== '' ? (get_current_user_id() ?: null) : null,
            'source' => $answer !== '' ? $source : '',
        );

        if ($row) {
            $wpdb->update(Lookup::table('passflow_availability'), $data, array('id' => (int) $row['id']));
        } else {
            $wpdb->insert(Lookup::table('passflow_availability'), $data + array('slot_id' => (int) $slot_id, 'host_id' => (int) $host_id));
        }

        $fresh = self::get($slot_id, $host_id);
        $changed = !$row || (string) $row['answer'] !== $answer;

        if ($changed && $answer !== '' && $source === 'intervenant') {
            self::notifyTeam((int) $slot_id, (int) $host_id, $answer);
        }

        do_action('smbb_passflow_availability_saved', (int) $slot_id, (int) $host_id, $answer, $source);

        return $fresh;
    }

    /**
     * Demande de disponibilités : un message par intervenant, avec toutes ses dates sans réponse.
     *
     * @param int[]|null $slot_ids dates concernées ; null = toutes les dates à venir
     * @return array{people:int,dates:int,messages:int}
     */
    public static function request($slot_ids = null)
    {
        global $wpdb;

        $slots = $slot_ids === null
            ? Slots::between(current_time('Y-m-d'), null, array('statuses' => array('draft', 'open', 'full')))
            : array_values(array_filter(array_map(array(Slots::class, 'find'), array_map('intval', (array) $slot_ids))));
        $slots = array_values(array_filter($slots, array(__CLASS__, 'open')));
        $answers = self::forSlots(array_column($slots, 'id'));
        $todo = array();

        foreach ($slots as $slot) {
            foreach (Lookup::ids($slot['host_ids']) as $host_id) {
                $row = isset($answers[(int) $slot['id']][$host_id]) ? $answers[(int) $slot['id']][$host_id] : null;

                if (!in_array(self::state($row), array('yes', 'no'), true)) {
                    $todo[$host_id][] = $slot;
                }
            }
        }

        $messages = 0;
        $dates = 0;

        foreach ($todo as $host_id => $list) {
            $lines = array();

            foreach ($list as $slot) {
                $lines[] = '- ' . Lookup::activityName($slot['activity_id']) . ' : ' . Lookup::slotWhen($slot);
            }

            $messages += Notifier::toHost('availability_request', $host_id, $list[0], array('dates' => implode("\n", $lines)), '', null);
            $dates += count($list);

            foreach ($list as $slot) {
                $row = isset($answers[(int) $slot['id']][$host_id]) ? $answers[(int) $slot['id']][$host_id] : null;

                if ($row) {
                    $wpdb->update(Lookup::table('passflow_availability'), array('asked_at' => current_time('mysql')), array('id' => (int) $row['id']));
                } else {
                    $wpdb->insert(Lookup::table('passflow_availability'), array('slot_id' => (int) $slot['id'], 'host_id' => (int) $host_id, 'answer' => '', 'asked_at' => current_time('mysql')));
                }
            }
        }

        return array('people' => count($todo), 'dates' => $dates, 'messages' => $messages);
    }

    /**
     * Alerte à l'équipe (e-mail et téléphone de contact) quand un intervenant répond.
     */
    private static function notifyTeam($slot_id, $host_id, $answer)
    {
        $slot = Slots::find($slot_id);
        $hosts = Lookup::intervenants();
        $host = isset($hosts[$host_id]) ? $hosts[$host_id] : null;
        $email = (string) Settings::get('contact_email');
        $phone = (string) Settings::get('contact_phone');

        if (!$slot || !$host || ($email === '' && $phone === '')) {
            return;
        }

        $channels = array();

        foreach (array('email', 'sms') as $channel) {
            if (Settings::channelOn('availability_answer', $channel)) {
                $channels[] = $channel;
            }
        }

        if (!$channels) {
            return;
        }

        Notifier::toCustomer('availability_answer', array(
            'first_name' => Settings::businessName(),
            'last_name' => '',
            'email' => $email,
            'phone' => $phone,
        ), $slot, array(
            'intervenant_nom' => Lookup::personName($host),
            'reponse' => $answer === 'yes' ? __('disponible', 'smbb-passflow') : __('pas disponible', 'smbb-passflow'),
        ), $channels);
    }

    // ------------------------------------------------------------------
    // Ajax : réponse depuis l'espace intervenant (liste ou calendrier)
    // ------------------------------------------------------------------

    public static function nonce()
    {
        return wp_create_nonce(self::AJAX . '_' . get_current_user_id());
    }

    public static function ajax()
    {
        $d = 'smbb-passflow';
        check_ajax_referer(self::AJAX . '_' . get_current_user_id(), 'nonce');

        $slot = Slots::find(isset($_POST['creneau']) ? absint($_POST['creneau']) : 0);
        $answer = isset($_POST['answer']) ? sanitize_key(wp_unslash($_POST['answer'])) : '';
        $me = Lookup::hostForUser(get_current_user_id());
        $host_id = isset($_POST['intervenant']) && Roles::canManage() ? absint($_POST['intervenant']) : ($me ? (int) $me['id'] : 0);

        if (!$slot || !$host_id || !self::concerns($slot, $host_id)) {
            wp_send_json_error(array('message' => __("Vous n'êtes pas prévu sur cette date.", $d)), 403);
        }

        if (!self::open($slot)) {
            wp_send_json_error(array('message' => __('Cette date est passée ou annulée.', $d)));
        }

        $source = $me && (int) $me['id'] === $host_id ? 'intervenant' : 'admin';
        $row = self::set((int) $slot['id'], $host_id, $answer, $source);

        wp_send_json_success(array('state' => self::state($row), 'badge' => self::badge(self::state($row)), 'message' => __('Enregistré', $d)));
    }
}
