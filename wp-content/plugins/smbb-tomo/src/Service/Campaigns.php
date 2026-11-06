<?php

namespace Smbb\Tomo\Service;

use Smbb\Tomo\Admin\View;
use Smbb\Tomo\Repository\Creneaux;
use Smbb\Tomo\Repository\Lookup;
use Smbb\Tomo\Support\Roles;
use Smbb\Tomo\Support\Settings;

defined('ABSPATH') || exit;

/**
 * Demandes de dispo groupées (« campagnes ») : on choisit des dates à venir et des intervenants,
 * un message court, et chacun reçoit un e-mail et un SMS avec le lien vers « Mes disponibilités ».
 *
 * tomo_campagne : la demande (dates, intervenants, message, canaux).
 * tomo_campagne_dest : une ligne par intervenant (envoyé par e-mail / SMS, vu le, message en retour).
 * Les réponses elles-mêmes restent dans tomo_dispo (une par date et intervenant), avec la dernière
 * campagne qui les a demandées : on lit toujours la réponse à jour.
 */
final class Campaigns
{
    const SEND = 'tomo_campaign_send';
    const REMIND = 'tomo_campaign_remind';
    const AJAX_REPLY = 'tomo_campaign_reply';

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

        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . Lookup::table('tomo_campagne') . ' WHERE deleted_flag = 0 AND id = %d', (int) $id), ARRAY_A);

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

        $rows = $wpdb->get_results('SELECT * FROM ' . Lookup::table('tomo_campagne') . ' WHERE deleted_flag = 0 ORDER BY sent_at DESC, id DESC LIMIT ' . (int) $limit, ARRAY_A);

        return array_map(array(__CLASS__, 'hydrate'), is_array($rows) ? $rows : array());
    }

    /**
     * @return array<int,array<string,mixed>> destinataires indexés par intervenant
     */
    public static function recipients($campagne_id)
    {
        global $wpdb;

        $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . Lookup::table('tomo_campagne_dest') . ' WHERE deleted_flag = 0 AND campagne_id = %d ORDER BY id ASC', (int) $campagne_id), ARRAY_A);
        $out = array();

        foreach (is_array($rows) ? $rows : array() as $row) {
            $out[(int) $row['intervenant_id']] = $row;
        }

        return $out;
    }

    /**
     * Demandes reçues par un intervenant, la plus récente d'abord, avec sa ligne de suivi (dest).
     *
     * @return array<int,array<string,mixed>>
     */
    public static function forIntervenant($intervenant_id)
    {
        global $wpdb;

        // Demandes adressées : ligne de suivi, ou à défaut une date demandée par la campagne.
        $ids = $wpdb->get_col($wpdb->prepare(
            'SELECT campagne_id FROM ' . Lookup::table('tomo_campagne_dest') . ' WHERE deleted_flag = 0 AND intervenant_id = %d'
            . ' UNION SELECT campagne_id FROM ' . Lookup::table('tomo_dispo') . ' WHERE deleted_flag = 0 AND intervenant_id = %d AND campagne_id IS NOT NULL AND campagne_id > 0',
            (int) $intervenant_id,
            (int) $intervenant_id
        ));
        $ids = array_map('intval', is_array($ids) ? $ids : array());

        foreach (self::all() as $campagne) {
            if (in_array((int) $intervenant_id, $campagne['intervenant_ids'], true)) {
                $ids[] = (int) $campagne['id'];
            }
        }

        $ids = array_values(array_unique(array_filter($ids)));
        $out = array();

        foreach ($ids as $id) {
            $campagne = self::find($id);

            if (!$campagne) {
                continue;
            }

            $dest = self::ensureRecipient($id, $intervenant_id);
            $campagne['dest_id'] = $dest ? (int) $dest['id'] : 0;
            $campagne['opened_at'] = $dest ? $dest['opened_at'] : null;
            $campagne['reply'] = $dest ? $dest['reply'] : '';
            $campagne['replied_at'] = $dest ? $dest['replied_at'] : null;
            $out[] = $campagne;
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
    public static function ensureRecipient($campagne_id, $intervenant_id)
    {
        global $wpdb;

        $dests = self::recipients($campagne_id);

        if (!isset($dests[(int) $intervenant_id])) {
            $wpdb->insert(Lookup::table('tomo_campagne_dest'), array('campagne_id' => (int) $campagne_id, 'intervenant_id' => (int) $intervenant_id));
            $dests = self::recipients($campagne_id);
        }

        return isset($dests[(int) $intervenant_id]) ? $dests[(int) $intervenant_id] : null;
    }

    /**
     * Dates d'une demande encore ouvertes (à venir, pas annulées), dans l'ordre chronologique.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function openCreneaux(array $campagne)
    {
        $rows = array();

        foreach ($campagne['creneau_ids'] as $id) {
            $creneau = Creneaux::find($id);

            if ($creneau && Availability::open($creneau)) {
                $rows[] = $creneau;
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
        $creneaux = array_values(array_filter(
            Creneaux::between(current_time('Y-m-d'), null, array('statuses' => array('draft', 'open', 'full'))),
            array(Availability::class, 'open')
        ));
        $groups = array();

        foreach ($creneaux as $creneau) {
            $atelier_id = (int) $creneau['atelier_id'];

            if (!isset($groups[$atelier_id])) {
                $groups[$atelier_id] = array('atelier' => Lookup::atelier($atelier_id), 'creneaux' => array());
            }

            $groups[$atelier_id]['creneaux'][] = $creneau;
        }

        uasort($groups, function ($a, $b) {
            return strcasecmp($a['atelier'] ? $a['atelier']['name'] : '', $b['atelier'] ? $b['atelier']['name'] : '');
        });

        return $groups;
    }

    /**
     * Compte des réponses d'un intervenant sur les dates d'une demande.
     *
     * @param array<int,array<int,array<string,mixed>>> $answers Availability::forCreneaux()
     * @return array{yes:int,no:int,none:int,total:int}
     */
    public static function tally(array $campagne, $intervenant_id, array $answers)
    {
        $out = array('yes' => 0, 'no' => 0, 'none' => 0, 'total' => 0);

        foreach ($campagne['creneau_ids'] as $creneau_id) {
            $row = isset($answers[$creneau_id][(int) $intervenant_id]) ? $answers[$creneau_id][(int) $intervenant_id] : null;
            $state = Availability::state($row);
            $out[in_array($state, array('yes', 'no'), true) ? $state : 'none']++;
            $out['total']++;
        }

        return $out;
    }

    /**
     * Lien direct vers la demande dans « Mes disponibilités ».
     */
    public static function link($campagne_id)
    {
        return Roles::spaceUrl(array('dispos' => 1, 'demande' => (int) $campagne_id)) . '#demande-' . (int) $campagne_id;
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
            $wpdb->query($wpdb->prepare('UPDATE ' . Lookup::table('tomo_campagne_dest') . ' SET opened_at = %s WHERE opened_at IS NULL AND id IN (' . implode(',', $ids) . ')', current_time('mysql')));
        }
    }

    // ------------------------------------------------------------------
    // Envoi
    // ------------------------------------------------------------------

    /**
     * Crée la demande et l'envoie à chaque intervenant coché.
     *
     * @param int[] $creneau_ids
     * @param int[] $intervenant_ids
     * @param string[] $channels email, sms
     * @return array{success:bool,message:string,id?:int}
     */
    public static function create(array $creneau_ids, array $intervenant_ids, $message, array $channels)
    {
        global $wpdb;

        $d = 'smbb-tomo';
        $creneaux = array();

        foreach (array_unique(array_map('intval', $creneau_ids)) as $id) {
            $creneau = Creneaux::find($id);

            if ($creneau && Availability::open($creneau)) {
                $creneaux[(int) $creneau['id']] = $creneau;
            }
        }

        $all = Lookup::intervenants();
        $people = array_values(array_filter(array_unique(array_map('intval', $intervenant_ids)), function ($id) use ($all) {
            return isset($all[$id]);
        }));
        $channels = array_values(array_intersect(array('email', 'sms'), $channels));

        if (!$creneaux) {
            return array('success' => false, 'message' => __('Cochez au moins une date à venir.', $d));
        }

        if (!$people) {
            return array('success' => false, 'message' => __('Cochez au moins un intervenant.', $d));
        }

        if (!$channels) {
            return array('success' => false, 'message' => __("Choisissez l'e-mail, le SMS ou les deux.", $d));
        }

        $wpdb->insert(Lookup::table('tomo_campagne'), array(
            'message' => trim(sanitize_textarea_field($message)),
            'creneau_ids' => wp_json_encode(array_keys($creneaux)),
            'intervenant_ids' => wp_json_encode($people),
            'channels' => implode(',', $channels),
            'sent_at' => current_time('mysql'),
            'sent_by' => get_current_user_id() ?: null,
        ));
        $campagne_id = (int) $wpdb->insert_id;

        if (!$campagne_id) {
            return array('success' => false, 'message' => __("La demande n'a pas pu être enregistrée.", $d));
        }

        // Chaque date est « demandée » à chaque intervenant, qu'il soit déjà prévu dessus ou non.
        $answers = Availability::forCreneaux(array_keys($creneaux));

        foreach ($creneaux as $creneau_id => $creneau) {
            foreach ($people as $intervenant_id) {
                $row = isset($answers[$creneau_id][$intervenant_id]) ? $answers[$creneau_id][$intervenant_id] : null;
                $data = array('asked_at' => current_time('mysql'), 'campagne_id' => $campagne_id);

                if ($row) {
                    $wpdb->update(Lookup::table('tomo_dispo'), $data, array('id' => (int) $row['id']));
                } else {
                    $wpdb->insert(Lookup::table('tomo_dispo'), $data + array('creneau_id' => $creneau_id, 'intervenant_id' => $intervenant_id, 'answer' => ''));
                }
            }
        }

        $campagne = self::find($campagne_id);
        $sent = 0;
        $reached = 0;

        foreach ($people as $intervenant_id) {
            $wpdb->insert(Lookup::table('tomo_campagne_dest'), array('campagne_id' => $campagne_id, 'intervenant_id' => $intervenant_id));
            $count = self::deliver($campagne, $intervenant_id, (int) $wpdb->insert_id, array_values($creneaux));
            $sent += $count;
            $reached += $count > 0 ? 1 : 0;
        }

        $result = array(
            'success' => true,
            'id' => $campagne_id,
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
    public static function remind($campagne_id)
    {
        global $wpdb;

        $d = 'smbb-tomo';
        $campagne = self::find($campagne_id);

        if (!$campagne) {
            return array('success' => false, 'message' => __('Demande introuvable.', $d));
        }

        $creneaux = self::openCreneaux($campagne);
        $answers = Availability::forCreneaux(array_column($creneaux, 'id'));
        $people = 0;

        foreach (self::recipients($campagne_id) as $intervenant_id => $dest) {
            $missing = array_values(array_filter($creneaux, function ($creneau) use ($answers, $intervenant_id) {
                $row = isset($answers[(int) $creneau['id']][$intervenant_id]) ? $answers[(int) $creneau['id']][$intervenant_id] : null;

                return !in_array(Availability::state($row), array('yes', 'no'), true);
            }));

            if ($missing && self::deliver($campagne, $intervenant_id, (int) $dest['id'], $missing)) {
                $people++;
            }
        }

        if ($people) {
            $wpdb->update(Lookup::table('tomo_campagne'), array('reminded_at' => current_time('mysql')), array('id' => (int) $campagne_id));
        }

        return $people
            ? array('success' => true, 'message' => sprintf(_n('Relance envoyée à %d intervenant.', 'Relance envoyée à %d intervenants.', $people, $d), $people))
            : array('success' => false, 'message' => __('Personne à relancer : tout le monde a répondu pour les dates encore à venir.', $d));
    }

    /**
     * Un message (e-mail et/ou SMS) pour un intervenant, avec ses dates.
     *
     * @param array<int,array<string,mixed>> $creneaux
     * @return int messages mis en file
     */
    private static function deliver(array $campagne, $intervenant_id, $dest_id, array $creneaux)
    {
        global $wpdb;

        if (!$creneaux) {
            return 0;
        }

        $lines = array();

        foreach ($creneaux as $creneau) {
            $lines[] = '- ' . Lookup::atelierName($creneau['atelier_id']) . ' : ' . Lookup::creneauWhen($creneau);
        }

        $extra = array(
            'dates' => implode("\n", $lines),
            'message_demande' => (string) $campagne['message'],
            'lien_dispos' => self::link($campagne['id']),
        );
        $flags = array();
        $total = 0;

        foreach ($campagne['channels'] as $channel) {
            $count = Notifier::toIntervenant('dispo_campaign', $intervenant_id, $creneaux[0], $extra, '', array($channel));
            $total += $count;

            if ($count) {
                $flags[$channel . '_sent'] = 1;
            }
        }

        if ($flags && $dest_id) {
            $wpdb->update(Lookup::table('tomo_campagne_dest'), $flags, array('id' => (int) $dest_id));
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

        $d = 'smbb-tomo';
        check_ajax_referer(self::AJAX_REPLY . '_' . get_current_user_id(), 'nonce');

        $me = Lookup::intervenantForUser(get_current_user_id());
        $campagne = self::find(isset($_POST['campagne']) ? absint($_POST['campagne']) : 0);
        $reply = isset($_POST['reply']) ? trim(sanitize_textarea_field(wp_unslash($_POST['reply']))) : '';
        $mine = $me ? array_column(self::forIntervenant((int) $me['id']), 'id') : array();
        $dests = $campagne && in_array((int) $campagne['id'], array_map('intval', $mine), true) ? self::recipients($campagne['id']) : array();

        if (!$me || !isset($dests[(int) $me['id']])) {
            wp_send_json_error(array('message' => __("Cette demande ne vous est pas adressée.", $d)), 403);
        }

        $dest = $dests[(int) $me['id']];
        $wpdb->update(Lookup::table('tomo_campagne_dest'), array(
            'reply' => $reply,
            'replied_at' => $reply !== '' ? current_time('mysql') : null,
            'opened_at' => $dest['opened_at'] ?: current_time('mysql'),
        ), array('id' => (int) $dest['id']));

        if ($reply !== '' && $reply !== (string) $dest['reply']) {
            self::notifyTeam($campagne, $me, $reply);
        }

        wp_send_json_success(array('message' => $reply !== '' ? __('Message envoyé, merci.', $d) : __('Message effacé.', $d)));
    }

    private static function notifyTeam(array $campagne, array $intervenant, $reply)
    {
        $email = (string) Settings::get('contact_email');
        $phone = (string) Settings::get('contact_phone');
        $channels = array();

        foreach (array('email', 'sms') as $channel) {
            if (Settings::channelOn('dispo_reply', $channel)) {
                $channels[] = $channel;
            }
        }

        if (!$channels || ($email === '' && $phone === '')) {
            return;
        }

        Notifier::toCustomer('dispo_reply', array(
            'first_name' => Settings::businessName(),
            'last_name' => '',
            'email' => $email,
            'phone' => $phone,
        ), null, array(
            'intervenant_nom' => Lookup::personName($intervenant),
            'message_intervenant' => $reply,
            'lien_demande' => View::url('tomo_campagnes', array('demande' => (int) $campagne['id'])),
        ), $channels);
    }

    // ------------------------------------------------------------------
    // Actions admin
    // ------------------------------------------------------------------

    public static function handleSend()
    {
        check_admin_referer(self::SEND . '_0');

        if (!Roles::canManage()) {
            wp_die(esc_html__('Accès refusé.', 'smbb-tomo'), 403);
        }

        $creneau_ids = isset($_POST['creneaux']) ? array_map('absint', (array) wp_unslash($_POST['creneaux'])) : array();
        $intervenant_ids = isset($_POST['intervenants']) ? array_map('absint', (array) wp_unslash($_POST['intervenants'])) : array();
        $channels = isset($_POST['canaux']) ? array_map('sanitize_key', (array) wp_unslash($_POST['canaux'])) : array();
        $message = isset($_POST['message']) ? (string) wp_unslash($_POST['message']) : '';
        $result = self::create($creneau_ids, $intervenant_ids, $message, $channels);

        View::flash($result);

        if (!empty($result['id'])) {
            wp_safe_redirect(View::url('tomo_campagnes', array('demande' => (int) $result['id'])));
        } else {
            set_transient('smbb_tomo_campaign_draft_' . get_current_user_id(), compact('creneau_ids', 'intervenant_ids', 'channels', 'message'), 600);
            wp_safe_redirect(View::url('tomo_campagnes', array('nouvelle' => 1, 'brouillon' => 1)));
        }

        exit;
    }

    public static function handleRemind()
    {
        $id = isset($_GET['id']) ? absint($_GET['id']) : 0;
        check_admin_referer(self::REMIND . '_' . $id);

        if (!Roles::canManage()) {
            wp_die(esc_html__('Accès refusé.', 'smbb-tomo'), 403);
        }

        View::flash(self::remind($id));
        wp_safe_redirect(View::url('tomo_campagnes', array('demande' => $id)));
        exit;
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private static function hydrate(array $row)
    {
        $row['id'] = (int) $row['id'];
        $row['creneau_ids'] = Lookup::ids(isset($row['creneau_ids']) ? $row['creneau_ids'] : array());
        $row['intervenant_ids'] = Lookup::ids(isset($row['intervenant_ids']) ? $row['intervenant_ids'] : array());
        $row['channels'] = array_values(array_filter(explode(',', (string) $row['channels'])));

        return $row;
    }
}
