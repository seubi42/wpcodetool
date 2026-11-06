<?php

namespace Smbb\Tomo\Service;

use Smbb\Tomo\Repository\Creneaux;
use Smbb\Tomo\Repository\Lookup;
use Smbb\Tomo\Support\Roles;
use Smbb\Tomo\Support\Settings;

defined('ABSPATH') || exit;

/**
 * Disponibilités des intervenants sur les dates où ils sont prévus.
 *
 * Une ligne par (créneau, intervenant) dans tomo_dispo : réponse (yes / no / vide), date de la
 * demande, date et auteur de la réponse. L'intervenant répond depuis son espace (liste ou calendrier),
 * l'équipe peut répondre à sa place depuis la fiche de la date.
 */
final class Availability
{
    const AJAX = 'tomo_dispo';

    public static function hooks()
    {
        add_action('wp_ajax_' . self::AJAX, array(__CLASS__, 'ajax'));
    }

    // ------------------------------------------------------------------
    // Lecture
    // ------------------------------------------------------------------

    /**
     * Réponses par date puis par intervenant : array(creneau_id => array(intervenant_id => row)).
     *
     * @param int[] $creneau_ids
     * @return array<int,array<int,array<string,mixed>>>
     */
    public static function forCreneaux(array $creneau_ids)
    {
        global $wpdb;

        $ids = array_values(array_filter(array_map('intval', $creneau_ids)));

        if (!$ids) {
            return array();
        }

        $rows = $wpdb->get_results('SELECT * FROM ' . Lookup::table('tomo_dispo') . ' WHERE deleted_flag = 0 AND creneau_id IN (' . implode(',', $ids) . ') ORDER BY id ASC', ARRAY_A);
        $out = array();

        foreach (is_array($rows) ? $rows : array() as $row) {
            $out[(int) $row['creneau_id']][(int) $row['intervenant_id']] = $row;
        }

        return $out;
    }

    /**
     * @return array<string,mixed>|null
     */
    public static function get($creneau_id, $intervenant_id)
    {
        $all = self::forCreneaux(array((int) $creneau_id));

        return isset($all[(int) $creneau_id][(int) $intervenant_id]) ? $all[(int) $creneau_id][(int) $intervenant_id] : null;
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
        $d = 'smbb-tomo';
        $map = array(
            'yes' => array('confirmed', __('Dispo', $d)),
            'no' => array('absent', __('Non dispo', $d)),
            'asked' => array('pending', __('En attente', $d)),
            'none' => array('draft', __('À demander', $d)),
        );
        $item = isset($map[$state]) ? $map[$state] : $map['none'];

        return '<span class="tomo-badge tomo-badge--' . $item[0] . ' tomo-dispo-badge" data-dispo-state="' . esc_attr($state) . '">' . esc_html($item[1]) . '</span>';
    }

    /**
     * L'intervenant est prévu sur la date, ou on lui a demandé s'il était disponible (demande groupée).
     */
    public static function concerns(array $creneau, $intervenant_id)
    {
        return in_array((int) $intervenant_id, Lookup::ids($creneau['intervenant_ids']), true) || self::get($creneau['id'], $intervenant_id) !== null;
    }

    /**
     * Une date réclame-t-elle encore des réponses ? (à venir, ni annulée ni pass)
     */
    public static function open(array $creneau)
    {
        return $creneau['status'] !== 'cancelled' && !Passes::isPass($creneau) && !Creneaux::isPast($creneau);
    }

    // ------------------------------------------------------------------
    // Écriture
    // ------------------------------------------------------------------

    /**
     * Enregistre une réponse. $source : intervenant (depuis son espace) ou admin (à sa place).
     *
     * @return array<string,mixed>|null la ligne à jour
     */
    public static function set($creneau_id, $intervenant_id, $answer, $source = 'admin')
    {
        global $wpdb;

        $answer = in_array($answer, array('yes', 'no'), true) ? $answer : '';
        $row = self::get($creneau_id, $intervenant_id);
        $data = array(
            'answer' => $answer,
            'answered_at' => $answer !== '' ? current_time('mysql') : null,
            'answered_by' => $answer !== '' ? (get_current_user_id() ?: null) : null,
            'source' => $answer !== '' ? $source : '',
        );

        if ($row) {
            $wpdb->update(Lookup::table('tomo_dispo'), $data, array('id' => (int) $row['id']));
        } else {
            $wpdb->insert(Lookup::table('tomo_dispo'), $data + array('creneau_id' => (int) $creneau_id, 'intervenant_id' => (int) $intervenant_id));
        }

        $fresh = self::get($creneau_id, $intervenant_id);
        $changed = !$row || (string) $row['answer'] !== $answer;

        if ($changed && $answer !== '' && $source === 'intervenant') {
            self::notifyTeam((int) $creneau_id, (int) $intervenant_id, $answer);
        }

        do_action('smbb_tomo_dispo_saved', (int) $creneau_id, (int) $intervenant_id, $answer, $source);

        return $fresh;
    }

    /**
     * Demande de disponibilités : un message par intervenant, avec toutes ses dates sans réponse.
     *
     * @param int[]|null $creneau_ids dates concernées ; null = toutes les dates à venir
     * @return array{people:int,dates:int,messages:int}
     */
    public static function request($creneau_ids = null)
    {
        global $wpdb;

        $creneaux = $creneau_ids === null
            ? Creneaux::between(current_time('Y-m-d'), null, array('statuses' => array('draft', 'open', 'full')))
            : array_values(array_filter(array_map(array(Creneaux::class, 'find'), array_map('intval', (array) $creneau_ids))));
        $creneaux = array_values(array_filter($creneaux, array(__CLASS__, 'open')));
        $answers = self::forCreneaux(array_column($creneaux, 'id'));
        $todo = array();

        foreach ($creneaux as $creneau) {
            foreach (Lookup::ids($creneau['intervenant_ids']) as $intervenant_id) {
                $row = isset($answers[(int) $creneau['id']][$intervenant_id]) ? $answers[(int) $creneau['id']][$intervenant_id] : null;

                if (!in_array(self::state($row), array('yes', 'no'), true)) {
                    $todo[$intervenant_id][] = $creneau;
                }
            }
        }

        $messages = 0;
        $dates = 0;

        foreach ($todo as $intervenant_id => $list) {
            $lines = array();

            foreach ($list as $creneau) {
                $lines[] = '- ' . Lookup::atelierName($creneau['atelier_id']) . ' : ' . Lookup::creneauWhen($creneau);
            }

            $messages += Notifier::toIntervenant('dispo_request', $intervenant_id, $list[0], array('dates' => implode("\n", $lines)), '', null);
            $dates += count($list);

            foreach ($list as $creneau) {
                $row = isset($answers[(int) $creneau['id']][$intervenant_id]) ? $answers[(int) $creneau['id']][$intervenant_id] : null;

                if ($row) {
                    $wpdb->update(Lookup::table('tomo_dispo'), array('asked_at' => current_time('mysql')), array('id' => (int) $row['id']));
                } else {
                    $wpdb->insert(Lookup::table('tomo_dispo'), array('creneau_id' => (int) $creneau['id'], 'intervenant_id' => (int) $intervenant_id, 'answer' => '', 'asked_at' => current_time('mysql')));
                }
            }
        }

        return array('people' => count($todo), 'dates' => $dates, 'messages' => $messages);
    }

    /**
     * Alerte à l'équipe (e-mail et téléphone de contact) quand un intervenant répond.
     */
    private static function notifyTeam($creneau_id, $intervenant_id, $answer)
    {
        $creneau = Creneaux::find($creneau_id);
        $intervenants = Lookup::intervenants();
        $intervenant = isset($intervenants[$intervenant_id]) ? $intervenants[$intervenant_id] : null;
        $email = (string) Settings::get('contact_email');
        $phone = (string) Settings::get('contact_phone');

        if (!$creneau || !$intervenant || ($email === '' && $phone === '')) {
            return;
        }

        $channels = array();

        foreach (array('email', 'sms') as $channel) {
            if (Settings::channelOn('dispo_answer', $channel)) {
                $channels[] = $channel;
            }
        }

        if (!$channels) {
            return;
        }

        Notifier::toCustomer('dispo_answer', array(
            'first_name' => Settings::businessName(),
            'last_name' => '',
            'email' => $email,
            'phone' => $phone,
        ), $creneau, array(
            'intervenant_nom' => Lookup::personName($intervenant),
            'reponse' => $answer === 'yes' ? __('disponible', 'smbb-tomo') : __('pas disponible', 'smbb-tomo'),
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
        $d = 'smbb-tomo';
        check_ajax_referer(self::AJAX . '_' . get_current_user_id(), 'nonce');

        $creneau = Creneaux::find(isset($_POST['creneau']) ? absint($_POST['creneau']) : 0);
        $answer = isset($_POST['answer']) ? sanitize_key(wp_unslash($_POST['answer'])) : '';
        $me = Lookup::intervenantForUser(get_current_user_id());
        $intervenant_id = isset($_POST['intervenant']) && Roles::canManage() ? absint($_POST['intervenant']) : ($me ? (int) $me['id'] : 0);

        if (!$creneau || !$intervenant_id || !self::concerns($creneau, $intervenant_id)) {
            wp_send_json_error(array('message' => __("Vous n'êtes pas prévu sur cette date.", $d)), 403);
        }

        if (!self::open($creneau)) {
            wp_send_json_error(array('message' => __('Cette date est passée ou annulée.', $d)));
        }

        $source = $me && (int) $me['id'] === $intervenant_id ? 'intervenant' : 'admin';
        $row = self::set((int) $creneau['id'], $intervenant_id, $answer, $source);

        wp_send_json_success(array('state' => self::state($row), 'badge' => self::badge(self::state($row)), 'message' => __('Enregistré', $d)));
    }
}
