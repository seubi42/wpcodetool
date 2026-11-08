<?php

namespace Smbb\Passflow\Service;

use Smbb\Passflow\Admin\View;
use Smbb\Passflow\Repository\Slots;
use Smbb\Passflow\Repository\Lookup;
use Smbb\Passflow\Support\Roles;
use Smbb\Passflow\Support\Settings;

defined('ABSPATH') || exit;

/**
 * Demandes de dispo groupées (« campagnes ») : on choisit des dates à venir et des intervenants,
 * un message court, et chacun reçoit un e-mail et un SMS avec le lien vers « Mes disponibilités ».
 *
 * passflow_campaign : la demande (dates, intervenants, message, canaux).
 * passflow_campaign_recipient : une ligne par intervenant (envoyé par e-mail / SMS, vu le, message en retour).
 * Les réponses elles-mêmes restent dans passflow_availability (une par date et intervenant), avec la dernière
 * campagne qui les a demandées : on lit toujours la réponse à jour.
 */
final class Campaigns
{
    const SEND = 'passflow_campaign_send';
    const REMIND = 'passflow_campaign_remind';
    const AJAX_REPLY = 'passflow_campaign_reply';

    public static function hooks()
    {
        add_action('admin_post_' . self::SEND, array(__CLASS__, 'handleSend'));
        add_action('admin_post_' . self::REMIND, array(__CLASS__, 'handleRemind'));
        add_action('wp_ajax_' . self::AJAX_REPLY, array(__CLASS__, 'ajaxReply'));
    }

    // ------------------------------------------------------------------
    // Lecture
    // ------------------------------------------------------------------

    /**
     * @return array<string,mixed>|null
     */
    public static function find($id)
    {
        global $wpdb;

        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . Lookup::table('passflow_campaign') . ' WHERE deleted_flag = 0 AND id = %d', (int) $id), ARRAY_A);

        return $row ? self::hydrate($row) : null;
    }

    /**
     * Demandes envoyées, la plus récente d'abord.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function all($limit = 100)
    {
        global $wpdb;

        $rows = $wpdb->get_results('SELECT * FROM ' . Lookup::table('passflow_campaign') . ' WHERE deleted_flag = 0 ORDER BY sent_at DESC, id DESC LIMIT ' . (int) $limit, ARRAY_A);

        return array_map(array(__CLASS__, 'hydrate'), is_array($rows) ? $rows : array());
    }

    /**
     * @return array<int,array<string,mixed>> destinataires indexés par intervenant
     */
    public static function recipients($campaign_id)
    {
        global $wpdb;

        $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . Lookup::table('passflow_campaign_recipient') . ' WHERE deleted_flag = 0 AND campaign_id = %d ORDER BY id ASC', (int) $campaign_id), ARRAY_A);
        $out = array();

        foreach (is_array($rows) ? $rows : array() as $row) {
            $out[(int) $row['host_id']] = $row;
        }

        return $out;
    }

    /**
     * Demandes reçues par un intervenant, la plus récente d'abord, avec sa ligne de suivi (dest).
     *
     * @return array<int,array<string,mixed>>
     */
    public static function forHost($host_id)
    {
        global $wpdb;

        // Demandes adressées : ligne de suivi, ou à défaut une date demandée par la campagne.
        $ids = $wpdb->get_col($wpdb->prepare(
            'SELECT campaign_id FROM ' . Lookup::table('passflow_campaign_recipient') . ' WHERE deleted_flag = 0 AND host_id = %d'
            . ' UNION SELECT campaign_id FROM ' . Lookup::table('passflow_availability') . ' WHERE deleted_flag = 0 AND host_id = %d AND campaign_id IS NOT NULL AND campaign_id > 0',
            (int) $host_id,
            (int) $host_id
        ));
        $ids = array_map('intval', is_array($ids) ? $ids : array());

        foreach (self::all() as $campaign) {
            if (in_array((int) $host_id, $campaign['host_ids'], true)) {
                $ids[] = (int) $campaign['id'];
            }
        }

        $ids = array_values(array_unique(array_filter($ids)));
        $out = array();

        foreach ($ids as $id) {
            $campaign = self::find($id);

            if (!$campaign) {
                continue;
            }

            $dest = self::ensureRecipient($id, $host_id);
            $campaign['dest_id'] = $dest ? (int) $dest['id'] : 0;
            $campaign['opened_at'] = $dest ? $dest['opened_at'] : null;
            $campaign['reply'] = $dest ? $dest['reply'] : '';
            $campaign['replied_at'] = $dest ? $dest['replied_at'] : null;
            $out[] = $campaign;
        }

        usort($out, function ($a, $b) {
            return strcmp((string) $b['sent_at'], (string) $a['sent_at']) ?: ($b['id'] - $a['id']);
        });

        return $out;
    }

    /**
     * Ligne de suivi d'un intervenant (créée si elle manque).
     *
     * @return array<string,mixed>|null
     */
    public static function ensureRecipient($campaign_id, $host_id)
    {
        global $wpdb;

        $dests = self::recipients($campaign_id);

        if (!isset($dests[(int) $host_id])) {
            $wpdb->insert(Lookup::table('passflow_campaign_recipient'), array('campaign_id' => (int) $campaign_id, 'host_id' => (int) $host_id));
            $dests = self::recipients($campaign_id);
        }

        return isset($dests[(int) $host_id]) ? $dests[(int) $host_id] : null;
    }

    /**
     * Dates d'une demande encore ouvertes (à venir, pas annulées), dans l'ordre chronologique.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function openSlots(array $campaign)
    {
        $rows = array();

        foreach ($campaign['slot_ids'] as $id) {
            $slot = Slots::find($id);

            if ($slot && Availability::open($slot)) {
                $rows[] = $slot;
            }
        }

        usort($rows, function ($a, $b) {
            return strcmp($a['start_date'] . $a['start_time'], $b['start_date'] . $b['start_time']);
        });

        return $rows;
    }

    /**
     * Dates à venir qu'on peut demander, groupées par atelier (nom => liste).
     *
     * @return array<int,array{atelier:array<string,mixed>|null,creneaux:array<int,array<string,mixed>>}>
     */
    public static function tree()
    {
        $slots = array_values(array_filter(
            Slots::between(current_time('Y-m-d'), null, array('statuses' => array('draft', 'open', 'full'))),
            array(Availability::class, 'open')
        ));
        $groups = array();

        foreach ($slots as $slot) {
            $activity_id = (int) $slot['activity_id'];

            if (!isset($groups[$activity_id])) {
                $groups[$activity_id] = array('atelier' => Lookup::atelier($activity_id), 'creneaux' => array());
            }

            $groups[$activity_id]['creneaux'][] = $slot;
        }

        uasort($groups, function ($a, $b) {
            return strcasecmp($a['atelier'] ? $a['atelier']['name'] : '', $b['atelier'] ? $b['atelier']['name'] : '');
        });

        return $groups;
    }

    /**
     * Compte des réponses d'un intervenant sur les dates d'une demande.
     *
     * @param array<int,array<int,array<string,mixed>>> $answers Availability::forSlots()
     * @return array{yes:int,no:int,none:int,total:int}
     */
    public static function tally(array $campaign, $host_id, array $answers)
    {
        $out = array('yes' => 0, 'no' => 0, 'none' => 0, 'total' => 0);

        foreach ($campaign['slot_ids'] as $slot_id) {
            $row = isset($answers[$slot_id][(int) $host_id]) ? $answers[$slot_id][(int) $host_id] : null;
            $state = Availability::state($row);
            $out[in_array($state, array('yes', 'no'), true) ? $state : 'none']++;
            $out['total']++;
        }

        return $out;
    }

    /**
     * Lien direct vers la demande dans « Mes disponibilités ».
     */
    public static function link($campaign_id)
    {
        return Roles::spaceUrl(array('dispos' => 1, 'demande' => (int) $campaign_id)) . '#demande-' . (int) $campaign_id;
    }

    /**
     * L'intervenant a vu ses demandes (affichées en haut de « Mes disponibilités »).
     *
     * @param int[] $dest_ids
     */
    public static function markSeen(array $dest_ids)
    {
        global $wpdb;

        $ids = array_values(array_filter(array_map('intval', $dest_ids)));

        if ($ids) {
            $wpdb->query($wpdb->prepare('UPDATE ' . Lookup::table('passflow_campaign_recipient') . ' SET opened_at = %s WHERE opened_at IS NULL AND id IN (' . implode(',', $ids) . ')', current_time('mysql')));
        }
    }

    // ------------------------------------------------------------------
    // Envoi
    // ------------------------------------------------------------------

    /**
     * Crée la demande et l'envoie à chaque intervenant coché.
     *
     * @param int[] $slot_ids
     * @param int[] $host_ids
     * @param string[] $channels email, sms
     * @return array{success:bool,message:string,id?:int}
     */
    public static function create(array $slot_ids, array $host_ids, $message, array $channels)
    {
        global $wpdb;

        $d = 'smbb-passflow';
        $slots = array();

        foreach (array_unique(array_map('intval', $slot_ids)) as $id) {
            $slot = Slots::find($id);

            if ($slot && Availability::open($slot)) {
                $slots[(int) $slot['id']] = $slot;
            }
        }

        $all = Lookup::intervenants();
        $people = array_values(array_filter(array_unique(array_map('intval', $host_ids)), function ($id) use ($all) {
            return isset($all[$id]);
        }));
        $channels = array_values(array_intersect(array('email', 'sms'), $channels));

        if (!$slots) {
            return array('success' => false, 'message' => __('Cochez au moins une date à venir.', $d));
        }

        if (!$people) {
            return array('success' => false, 'message' => __('Cochez au moins un intervenant.', $d));
        }

        if (!$channels) {
            return array('success' => false, 'message' => __("Choisissez l'e-mail, le SMS ou les deux.", $d));
        }

        $wpdb->insert(Lookup::table('passflow_campaign'), array(
            'message' => trim(sanitize_textarea_field($message)),
            'slot_ids' => wp_json_encode(array_keys($slots)),
            'host_ids' => wp_json_encode($people),
            'channels' => implode(',', $channels),
            'sent_at' => current_time('mysql'),
            'sent_by' => get_current_user_id() ?: null,
        ));
        $campaign_id = (int) $wpdb->insert_id;

        if (!$campaign_id) {
            return array('success' => false, 'message' => __("La demande n'a pas pu être enregistrée.", $d));
        }

        // Chaque date est « demandée » à chaque intervenant, qu'il soit déjà prévu dessus ou non.
        $answers = Availability::forSlots(array_keys($slots));

        foreach ($slots as $slot_id => $slot) {
            foreach ($people as $host_id) {
                $row = isset($answers[$slot_id][$host_id]) ? $answers[$slot_id][$host_id] : null;
                $data = array('asked_at' => current_time('mysql'), 'campaign_id' => $campaign_id);

                if ($row) {
                    $wpdb->update(Lookup::table('passflow_availability'), $data, array('id' => (int) $row['id']));
                } else {
                    $wpdb->insert(Lookup::table('passflow_availability'), $data + array('slot_id' => $slot_id, 'host_id' => $host_id, 'answer' => ''));
                }
            }
        }

        $campaign = self::find($campaign_id);
        $sent = 0;
        $reached = 0;

        foreach ($people as $host_id) {
            $wpdb->insert(Lookup::table('passflow_campaign_recipient'), array('campaign_id' => $campaign_id, 'host_id' => $host_id));
            $count = self::deliver($campaign, $host_id, (int) $wpdb->insert_id, array_values($slots));
            $sent += $count;
            $reached += $count > 0 ? 1 : 0;
        }

        $result = array(
            'success' => true,
            'id' => $campaign_id,
            'message' => sprintf(_n('Demande envoyée : %1$d message pour %2$d intervenant.', 'Demande envoyée : %1$d messages pour %2$d intervenants.', $reached, $d), $sent, $reached),
        );

        if ($reached < count($people)) {
            $result['message'] .= ' ' . sprintf(_n("%d intervenant n'a ni e-mail ni téléphone utilisable : la demande l'attend tout de même dans son espace.", "%d intervenants n'ont ni e-mail ni téléphone utilisable : la demande les attend tout de même dans leur espace.", count($people) - $reached, $d), count($people) - $reached);
        }

        return $result;
    }

    /**
     * Relance : ceux qui n'ont pas répondu à toutes les dates encore ouvertes.
     *
     * @return array{success:bool,message:string}
     */
    public static function remind($campaign_id)
    {
        global $wpdb;

        $d = 'smbb-passflow';
        $campaign = self::find($campaign_id);

        if (!$campaign) {
            return array('success' => false, 'message' => __('Demande introuvable.', $d));
        }

        $slots = self::openSlots($campaign);
        $answers = Availability::forSlots(array_column($slots, 'id'));
        $people = 0;

        foreach (self::recipients($campaign_id) as $host_id => $dest) {
            $missing = array_values(array_filter($slots, function ($slot) use ($answers, $host_id) {
                $row = isset($answers[(int) $slot['id']][$host_id]) ? $answers[(int) $slot['id']][$host_id] : null;

                return !in_array(Availability::state($row), array('yes', 'no'), true);
            }));

            if ($missing && self::deliver($campaign, $host_id, (int) $dest['id'], $missing)) {
                $people++;
            }
        }

        if ($people) {
            $wpdb->update(Lookup::table('passflow_campaign'), array('reminded_at' => current_time('mysql')), array('id' => (int) $campaign_id));
        }

        return $people
            ? array('success' => true, 'message' => sprintf(_n('Relance envoyée à %d intervenant.', 'Relance envoyée à %d intervenants.', $people, $d), $people))
            : array('success' => false, 'message' => __('Personne à relancer : tout le monde a répondu pour les dates encore à venir.', $d));
    }

    /**
     * Un message (e-mail et/ou SMS) pour un intervenant, avec ses dates.
     *
     * @param array<int,array<string,mixed>> $slots
     * @return int messages mis en file
     */
    private static function deliver(array $campaign, $host_id, $dest_id, array $slots)
    {
        global $wpdb;

        if (!$slots) {
            return 0;
        }

        $lines = array();

        foreach ($slots as $slot) {
            $lines[] = '- ' . Lookup::activityName($slot['activity_id']) . ' : ' . Lookup::slotWhen($slot);
        }

        $extra = array(
            'dates' => implode("\n", $lines),
            'message_demande' => (string) $campaign['message'],
            'lien_dispos' => self::link($campaign['id']),
        );
        $flags = array();
        $total = 0;

        foreach ($campaign['channels'] as $channel) {
            $count = Notifier::toHost('availability_campaign', $host_id, $slots[0], $extra, '', array($channel));
            $total += $count;

            if ($count) {
                $flags[$channel . '_sent'] = 1;
            }
        }

        if ($flags && $dest_id) {
            $wpdb->update(Lookup::table('passflow_campaign_recipient'), $flags, array('id' => (int) $dest_id));
        }

        return $total;
    }

    // ------------------------------------------------------------------
    // Réponse écrite de l'intervenant
    // ------------------------------------------------------------------

    public static function nonce()
    {
        return wp_create_nonce(self::AJAX_REPLY . '_' . get_current_user_id());
    }

    public static function ajaxReply()
    {
        global $wpdb;

        $d = 'smbb-passflow';
        check_ajax_referer(self::AJAX_REPLY . '_' . get_current_user_id(), 'nonce');

        $me = Lookup::hostForUser(get_current_user_id());
        $campaign = self::find(isset($_POST['campagne']) ? absint($_POST['campagne']) : 0);
        $reply = isset($_POST['reply']) ? trim(sanitize_textarea_field(wp_unslash($_POST['reply']))) : '';
        $mine = $me ? array_column(self::forHost((int) $me['id']), 'id') : array();
        $dests = $campaign && in_array((int) $campaign['id'], array_map('intval', $mine), true) ? self::recipients($campaign['id']) : array();

        if (!$me || !isset($dests[(int) $me['id']])) {
            wp_send_json_error(array('message' => __("Cette demande ne vous est pas adressée.", $d)), 403);
        }

        $dest = $dests[(int) $me['id']];
        $wpdb->update(Lookup::table('passflow_campaign_recipient'), array(
            'reply' => $reply,
            'replied_at' => $reply !== '' ? current_time('mysql') : null,
            'opened_at' => $dest['opened_at'] ?: current_time('mysql'),
        ), array('id' => (int) $dest['id']));

        if ($reply !== '' && $reply !== (string) $dest['reply']) {
            self::notifyTeam($campaign, $me, $reply);
        }

        wp_send_json_success(array('message' => $reply !== '' ? __('Message envoyé, merci.', $d) : __('Message effacé.', $d)));
    }

    private static function notifyTeam(array $campaign, array $host, $reply)
    {
        $email = (string) Settings::get('contact_email');
        $phone = (string) Settings::get('contact_phone');
        $channels = array();

        foreach (array('email', 'sms') as $channel) {
            if (Settings::channelOn('availability_reply', $channel)) {
                $channels[] = $channel;
            }
        }

        if (!$channels || ($email === '' && $phone === '')) {
            return;
        }

        Notifier::toCustomer('availability_reply', array(
            'first_name' => Settings::businessName(),
            'last_name' => '',
            'email' => $email,
            'phone' => $phone,
        ), null, array(
            'intervenant_nom' => Lookup::personName($host),
            'message_intervenant' => $reply,
            'lien_demande' => View::url('passflow_campaigns', array('demande' => (int) $campaign['id'])),
        ), $channels);
    }

    // ------------------------------------------------------------------
    // Actions admin
    // ------------------------------------------------------------------

    public static function handleSend()
    {
        check_admin_referer(self::SEND . '_0');

        if (!Roles::canManage()) {
            wp_die(esc_html__('Accès refusé.', 'smbb-passflow'), 403);
        }

        $slot_ids = isset($_POST['creneaux']) ? array_map('absint', (array) wp_unslash($_POST['creneaux'])) : array();
        $host_ids = isset($_POST['intervenants']) ? array_map('absint', (array) wp_unslash($_POST['intervenants'])) : array();
        $channels = isset($_POST['canaux']) ? array_map('sanitize_key', (array) wp_unslash($_POST['canaux'])) : array();
        $message = isset($_POST['message']) ? (string) wp_unslash($_POST['message']) : '';
        $result = self::create($slot_ids, $host_ids, $message, $channels);

        View::flash($result);

        if (!empty($result['id'])) {
            wp_safe_redirect(View::url('passflow_campaigns', array('demande' => (int) $result['id'])));
        } else {
            set_transient('smbb_passflow_campaign_draft_' . get_current_user_id(), compact('slot_ids', 'host_ids', 'channels', 'message'), 600);
            wp_safe_redirect(View::url('passflow_campaigns', array('nouvelle' => 1, 'brouillon' => 1)));
        }

        exit;
    }

    public static function handleRemind()
    {
        $id = isset($_GET['id']) ? absint($_GET['id']) : 0;
        check_admin_referer(self::REMIND . '_' . $id);

        if (!Roles::canManage()) {
            wp_die(esc_html__('Accès refusé.', 'smbb-passflow'), 403);
        }

        View::flash(self::remind($id));
        wp_safe_redirect(View::url('passflow_campaigns', array('demande' => $id)));
        exit;
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private static function hydrate(array $row)
    {
        $row['id'] = (int) $row['id'];
        $row['slot_ids'] = Lookup::ids(isset($row['slot_ids']) ? $row['slot_ids'] : array());
        $row['host_ids'] = Lookup::ids(isset($row['host_ids']) ? $row['host_ids'] : array());
        $row['channels'] = array_values(array_filter(explode(',', (string) $row['channels'])));

        return $row;
    }
}
