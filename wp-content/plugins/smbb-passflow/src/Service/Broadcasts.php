<?php

namespace Smbb\Passflow\Service;

use Smbb\Passflow\Repository\Bookings;
use Smbb\Passflow\Repository\Lookup;
use Smbb\Passflow\Repository\Slots;
use Smbb\Passflow\Support\Templates;
use Smbb\WpCodeTool\Outbox\Outbox;

defined('ABSPATH') || exit;

/**
 * Communication aux inscrits d'une journée : un message SMS et/ou e-mail rédigé par l'équipe
 * (urgence, changement de lieu, rappel…), envoyé à une cible (tous, présents, absents, imprévus,
 * non pointés).
 *
 * Les messages passent par la file d'envoi commune de CodeTool (Outbox, CodeTool › Communication ›
 * File d'envoi) : la diffusion démarre juste après la réponse au navigateur, puis continue par lots
 * à la cadence réglée dans CodeTool. Passflow s'y déclare comme source « passflow » (contexte
 * « slot » = la journée) et personnalise chaque message au moment de l'envoi (sendItem). Chaque envoi
 * est journalisé dans Messages, comme les autres (mode test et SMS CodeTool respectés).
 *
 * Deux modes (RGPD) : « Avec consentement » (par défaut) ne vise que les participants qui ont répondu
 * « Oui » au document qui porte le rôle Consentement RGPD, et le vérifie encore au moment de l'envoi ;
 * « Cas d'urgence » vise tout le monde, pour une information liée à la réservation.
 * Les demandes d'avis automatiques (Reviews) passent aussi par cette file (kind = review).
 *
 * Les anciennes tables passflow_broadcast(_queue) ne servent plus qu'à reprendre l'historique
 * une fois (migrate()).
 */
final class Broadcasts
{
    const SOURCE = 'passflow';
    const CONTEXT = 'slot';
    const EVENT = 'broadcast';
    const KIND_MANUAL = 'manual';
    const KIND_REVIEW = 'review';
    /** Anciens crochets de la file Passflow, retirés à la migration. */
    const LEGACY_CRON = 'smbb_passflow_broadcasts';
    const LEGACY_NOW = 'smbb_passflow_broadcast_now';
    const MIGRATED = 'smbb_passflow_broadcast_migrated';

    /** @var array<int,array|null> journées déjà lues pendant un lot */
    private static $slots = array();

    public static function hooks()
    {
        add_filter('smbb_wpcodetool_outbox_sources', array(__CLASS__, 'registerSource'));
        add_action('admin_init', array(__CLASS__, 'migrate'), 20);
    }

    /**
     * Déclare Passflow dans la file d'envoi de CodeTool.
     *
     * @param array<string,array<string,mixed>> $sources
     * @return array<string,array<string,mixed>>
     */
    public static function registerSource($sources)
    {
        $sources[self::SOURCE] = array(
            'label' => __('Passflow', 'smbb-passflow'),
            'send' => array(__CLASS__, 'sendItem'),
            'link' => function (array $outbox) {
                return $outbox['context_type'] === self::CONTEXT
                    ? \Smbb\Passflow\Admin\View::url('passflow_slot', array('view' => 'communication', 'id' => (int) $outbox['context_id']))
                    : '';
            },
            'context' => function (array $outbox) {
                if ($outbox['context_type'] !== self::CONTEXT) {
                    return '';
                }

                $slot = Slots::find((int) $outbox['context_id']);

                return $slot ? Lookup::activityName($slot['activity_id']) . ' · ' . Lookup::slotWhen($slot) : '';
            },
        );

        return $sources;
    }

    // ------------------------------------------------------------------
    // Cibles
    // ------------------------------------------------------------------

    /**
     * @return array<string,array{label:string,hint:string}>
     */
    public static function targets()
    {
        $d = 'smbb-passflow';

        return array(
            'all' => array('label' => __('Tous les inscrits', $d), 'hint' => __('Toutes les places occupées, pointées ou non.', $d)),
            'present' => array('label' => __('Les présents', $d), 'hint' => __("Pointés présents à l'appel.", $d)),
            'absent' => array('label' => __('Les absents', $d), 'hint' => __("Pointés absents à l'appel.", $d)),
            'walkin' => array('label' => __('Les imprévus', $d), 'hint' => __('Ajoutés sur place le jour même.', $d)),
            'unpointed' => array('label' => __('Les non pointés', $d), 'hint' => __("Inscrits que l'appel n'a pas encore vus.", $d)),
        );
    }

    public static function targetLabel($target)
    {
        $targets = self::targets();

        if ($target === 'attended') {
            return __('Présents et non pointés', 'smbb-passflow');
        }

        return isset($targets[$target]) ? $targets[$target]['label'] : (string) $target;
    }

    /**
     * Inscriptions visées par une cible (places occupées seulement : jamais les annulés).
     *
     * @return array<int,array<string,mixed>>
     */
    public static function bookingsFor($slot_id, $target, $consent = false)
    {
        $rows = array_values(array_filter(Bookings::forSlot((int) $slot_id), function ($b) use ($target) {
            switch ($target) {
                case 'attended': // présents et non pointés : tous sauf les absents (demande d'avis)
                    return $b['status'] !== 'absent';
                case 'present':
                    return $b['status'] === 'present';
                case 'absent':
                    return $b['status'] === 'absent';
                case 'walkin':
                    return $b['source'] === 'walkin';
                case 'unpointed':
                    return in_array($b['status'], array('pending', 'confirmed'), true);
                default:
                    return true;
            }
        }));

        return $consent ? self::consenting($rows) : $rows;
    }

    /**
     * Inscriptions dont le participant a dit « Oui » au consentement RGPD.
     *
     * @param array<int,array<string,mixed>> $bookings
     * @return array<int,array<string,mixed>>
     */
    public static function consenting(array $bookings)
    {
        if (!$bookings) {
            return array();
        }

        $answers = Documents::gdprAnswers(array_map('intval', array_column($bookings, 'id')));

        return array_values(array_filter($bookings, function ($b) use ($answers) {
            return isset($answers[(int) $b['id']]) && $answers[(int) $b['id']] === 'yes';
        }));
    }

    /**
     * Consentements RGPD des inscrits d'une journée : yes / no / none (pas répondu).
     *
     * @return array{yes:int,no:int,none:int}
     */
    public static function consentTally($slot_id)
    {
        $rows = Bookings::forSlot((int) $slot_id);
        $out = array('yes' => 0, 'no' => 0, 'none' => 0);

        foreach (Documents::gdprAnswers(array_map('intval', array_column($rows, 'id'))) as $answer) {
            $out[$answer === 'yes' ? 'yes' : ($answer === 'no' ? 'no' : 'none')]++;
        }

        return $out;
    }

    /**
     * Nombre de personnes par cible, et combien ont un e-mail / un téléphone.
     *
     * @return array<string,array{people:int,email:int,sms:int}>
     */
    public static function counts($slot_id, $consent = false)
    {
        $out = array();

        foreach (array_keys(self::targets()) as $target) {
            $rows = self::bookingsFor($slot_id, $target, $consent);
            $out[$target] = array(
                'people' => count($rows),
                'email' => count(self::addresses($rows, 'email')),
                'sms' => count(self::addresses($rows, 'sms')),
            );
        }

        return $out;
    }

    /**
     * Une adresse ne reçoit le message qu'une fois (parent qui a inscrit deux enfants…).
     *
     * @return array<string,array<string,mixed>> adresse => inscription
     */
    private static function addresses(array $bookings, $channel)
    {
        $out = array();

        foreach ($bookings as $b) {
            $address = $channel === 'email' ? strtolower(trim((string) $b['email'])) : preg_replace('/[^0-9+]/', '', (string) $b['phone']);

            if ($address !== '' && !isset($out[$address])) {
                $out[$address] = $b;
            }
        }

        return $out;
    }

    // ------------------------------------------------------------------
    // Création
    // ------------------------------------------------------------------

    /**
     * Met la communication dans la file d'envoi de CodeTool (la diffusion démarre après la réponse).
     *
     * @param array<int,string> $channels email, sms
     * @param bool              $consent  vrai : seulement les consentants RGPD (faux : cas d'urgence)
     * @param array{kind?:string,bookings?:array} $args kind (manual, review), bookings (liste déjà choisie)
     * @return array{success:bool,message:string,id?:int}
     */
    public static function create(array $slot, $target, array $channels, $subject, $message, $consent = true, array $args = array())
    {
        $d = 'smbb-passflow';
        $kind = isset($args['kind']) && $args['kind'] === self::KIND_REVIEW ? self::KIND_REVIEW : self::KIND_MANUAL;
        $target = array_key_exists($target, self::targets()) || $target === 'attended' ? $target : 'all';
        $channels = array_values(array_intersect(array('email', 'sms'), $channels));
        $message = trim((string) $message);
        $subject = trim((string) $subject);

        if (!$channels) {
            return array('success' => false, 'message' => __('Choisissez au moins un canal : SMS ou e-mail.', $d));
        }

        if ($message === '') {
            return array('success' => false, 'message' => __('Écrivez le message à envoyer.', $d));
        }

        if (in_array('email', $channels, true) && $subject === '') {
            $subject = self::defaultSubject();
        }

        if ($consent && !Documents::gdprDoc()) {
            return array('success' => false, 'message' => __('Aucun document ne porte le rôle « Consentement RGPD » : personne n\'a pu donner son accord. Réglez-le dans le menu Documents, ou passez en « Cas d\'urgence » si le message le justifie.', $d));
        }

        $bookings = isset($args['bookings']) ? (array) $args['bookings'] : self::bookingsFor($slot['id'], $target, $consent);
        $rows = array();

        foreach ($channels as $channel) {
            foreach (self::addresses($bookings, $channel) as $booking) {
                $rows[] = array(
                    'channel' => $channel,
                    'address' => $channel === 'email' ? (string) $booking['email'] : (string) $booking['phone'],
                    'ref' => (int) $booking['id'],
                    'name' => trim($booking['first_name'] . ' ' . $booking['last_name']),
                );
            }
        }

        if (!$rows) {
            return array('success' => false, 'message' => $consent
                ? __('Personne à prévenir : aucun inscrit consentant de cette cible n\'a d\'adresse sur ces canaux.', $d)
                : __('Personne à prévenir : aucun inscrit de cette cible n\'a d\'adresse sur ces canaux.', $d));
        }

        if (!class_exists('Smbb\\WpCodeTool\\Outbox\\Outbox')) {
            return array('success' => false, 'message' => __("Mettez à jour SMBB WP CodeTool (0.4.4 ou plus) : la file d'envoi des communications en fait partie.", $d));
        }

        $id = Outbox::queue(array(
            'source' => self::SOURCE,
            'context_type' => self::CONTEXT,
            'context_id' => (int) $slot['id'],
            'kind' => $kind,
            'label' => self::targetLabel($target) . ($kind === self::KIND_REVIEW ? ' · ' . __("Demande d'avis", $d) : ''),
            'channels' => $channels,
            'subject' => $subject,
            'message' => $message,
            'meta' => array('target' => $target, 'consent' => $consent ? 1 : 0),
            'recipients' => $rows,
        ));

        if (is_wp_error($id)) {
            return array('success' => false, 'message' => $id->get_error_message());
        }

        return array(
            'success' => true,
            'id' => $id,
            'message' => sprintf(_n('%d message en file : la diffusion démarre tout de suite.', '%d messages en file : la diffusion démarre tout de suite.', count($rows), $d), count($rows)),
        );
    }

    public static function defaultSubject()
    {
        return __('{atelier} du {date_courte} : information importante', 'smbb-passflow');
    }

    /**
     * Arrête une communication : les messages pas encore partis sont abandonnés.
     */
    public static function stop($id)
    {
        $broadcast = self::find($id);

        return $broadcast ? Outbox::stop((int) $id) : 0;
    }

    // ------------------------------------------------------------------
    // Lecture
    // ------------------------------------------------------------------

    /**
     * Un envoi de la file, seulement s'il vient de Passflow.
     */
    public static function find($id)
    {
        if (!class_exists('Smbb\\WpCodeTool\\Outbox\\Outbox')) {
            return null;
        }

        $row = Outbox::find((int) $id);

        return $row && $row['source'] === self::SOURCE ? self::shape($row) : null;
    }

    /**
     * Ligne de la file au format attendu par les écrans Passflow (target, consent, slot_id).
     */
    private static function shape(array $row)
    {
        $row['target'] = isset($row['meta']['target']) ? (string) $row['meta']['target'] : 'all';
        $row['consent'] = !empty($row['meta']['consent']) ? 1 : 0;
        $row['slot_id'] = $row['context_type'] === self::CONTEXT ? (int) $row['context_id'] : 0;

        return $row;
    }

    /**
     * @return array<int,array<string,mixed>> communications de la journée, les plus récentes d'abord, avec leurs compteurs
     */
    public static function forSlot($slot_id, $limit = 30)
    {
        if (!class_exists('Smbb\\WpCodeTool\\Outbox\\Outbox')) {
            return array();
        }

        return array_map(array(__CLASS__, 'shape'), Outbox::forContext(self::SOURCE, self::CONTEXT, (int) $slot_id, (int) $limit));
    }

    /**
     * Personnes (inscriptions) jointes avec succès par les communications d'une journée, d'un type donné.
     */
    public static function sentCount($slot_id, $kind = self::KIND_MANUAL)
    {
        return class_exists('Smbb\\WpCodeTool\\Outbox\\Outbox') ? Outbox::sentCount(self::SOURCE, self::CONTEXT, (int) $slot_id, (string) $kind) : 0;
    }

    /**
     * Communications pas encore terminées sur une journée (pour la tuile).
     */
    public static function pendingCount($slot_id)
    {
        return class_exists('Smbb\\WpCodeTool\\Outbox\\Outbox') ? Outbox::pendingCount(self::SOURCE, self::CONTEXT, (int) $slot_id) : 0;
    }

    // ------------------------------------------------------------------
    // Diffusion (appelée par la file de CodeTool, message par message)
    // ------------------------------------------------------------------

    /**
     * Envoie UN message de la file : contrôle du consentement, personnalisation, journal Messages.
     *
     * @param array<string,mixed> $item   ligne smbb_outbox_item (ref = id de l'inscription)
     * @param array<string,mixed> $outbox ligne smbb_outbox
     * @return array{status:string,error:string,to?:string}
     */
    public static function sendItem(array $item, array $outbox)
    {
        $broadcast = self::shape($outbox);
        $booking = Bookings::find((int) $item['ref']);

        if (!$booking) {
            return array('status' => 'skipped', 'error' => __('Inscription introuvable.', 'smbb-passflow'));
        }

        // Consentement retiré depuis la mise en file : le message ne part pas.
        if (!empty($broadcast['consent']) && !Documents::hasGdprConsent((int) $booking['id'])) {
            return array('status' => 'skipped', 'error' => __('Pas (ou plus) de consentement RGPD.', 'smbb-passflow'));
        }

        $slot_id = (int) $broadcast['slot_id'];

        if (!array_key_exists($slot_id, self::$slots)) {
            self::$slots[$slot_id] = Slots::find($slot_id);
        }

        $result = self::sendTo($broadcast, $booking, self::$slots[$slot_id], (string) $item['channel']);

        return array(
            'status' => (string) $result['status'],
            'error' => (string) $result['error'],
            'to' => (string) ($result['to'] ?? ''),
        );
    }

    /**
     * @return array{channel:string,to:string,status:string,error:string}
     */
    private static function sendTo(array $broadcast, array $booking, $slot, $channel)
    {
        $recipient = array(
            'type' => 'participant',
            'first_name' => (string) $booking['first_name'],
            'last_name' => (string) $booking['last_name'],
            'email' => (string) $booking['email'],
            'phone' => (string) $booking['phone'],
            'booking_id' => (int) $booking['id'],
            'slot_id' => (int) $broadcast['slot_id'],
            'activity_id' => (int) $booking['activity_id'],
        );
        $vars = Notifier::vars($slot ?: null, $recipient);
        $body = Templates::render((string) $broadcast['message'], $vars);
        $subject = $channel === 'email' ? Templates::render((string) $broadcast['subject'], $vars) : '';
        $address = $channel === 'email' ? $recipient['email'] : $recipient['phone'];

        $event = isset($broadcast['kind']) && $broadcast['kind'] === self::KIND_REVIEW ? Reviews::EVENT : self::EVENT;

        return Notifier::sendOne($event, $recipient, $channel, $address, $subject, $body);
    }

    // ------------------------------------------------------------------
    // Reprise de l'ancienne file Passflow (une fois)
    // ------------------------------------------------------------------

    /**
     * Recopie passflow_broadcast(_queue) dans la file CodeTool, statuts compris, puis retire les
     * anciens crochets cron. Les messages encore en attente repartent par CodeTool.
     */
    public static function migrate()
    {
        global $wpdb;

        if (get_option(self::MIGRATED) || !class_exists('Smbb\\WpCodeTool\\Outbox\\Outbox')) {
            return;
        }

        Outbox::install();

        if (!Outbox::ready()) {
            return;
        }

        wp_clear_scheduled_hook(self::LEGACY_NOW);
        wp_clear_scheduled_hook(self::LEGACY_CRON);

        $old = Lookup::table('passflow_broadcast');
        $old_queue = Lookup::table('passflow_broadcast_queue');
        $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($old))) === $old;
        $moved = 0;
        $pending = false;

        if ($exists) {
            $rows = $wpdb->get_results('SELECT * FROM ' . $old . ' ORDER BY id ASC', ARRAY_A);

            foreach ((array) $rows as $b) {
                $kind = isset($b['kind']) && $b['kind'] !== '' ? (string) $b['kind'] : self::KIND_MANUAL;
                $consent = isset($b['consent']) ? (int) $b['consent'] : 0;
                $wpdb->insert(Outbox::table(), array(
                    'source' => self::SOURCE,
                    'context_type' => self::CONTEXT,
                    'context_id' => (string) (int) $b['slot_id'],
                    'kind' => $kind,
                    'label' => self::targetLabel((string) $b['target']) . ($kind === self::KIND_REVIEW ? ' · ' . __("Demande d'avis", 'smbb-passflow') : ''),
                    'channels' => (string) $b['channels'],
                    'subject' => (string) $b['subject'],
                    'message' => (string) $b['message'],
                    'meta' => wp_json_encode(array('target' => (string) $b['target'], 'consent' => $consent, 'legacy_id' => (int) $b['id'])),
                    'status' => (string) $b['status'],
                    'total' => (int) $b['total'],
                    'created_at' => (string) $b['created_at'],
                    'created_by' => (int) $b['created_by'],
                    'finished_at' => !empty($b['finished_at']) ? (string) $b['finished_at'] : null,
                ));
                $new_id = (int) $wpdb->insert_id;

                if (!$new_id) {
                    continue;
                }

                $pending = $pending || in_array($b['status'], array('queued', 'sending'), true);
                $items = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . $old_queue . ' WHERE broadcast_id = %d ORDER BY id ASC', (int) $b['id']), ARRAY_A);

                foreach ((array) $items as $q) {
                    $booking = Bookings::find((int) $q['booking_id']);
                    $wpdb->insert(Outbox::itemsTable(), array(
                        'outbox_id' => $new_id,
                        'channel' => (string) $q['channel'],
                        'address' => $booking ? (string) ($q['channel'] === 'email' ? $booking['email'] : $booking['phone']) : '',
                        'ref' => (string) (int) $q['booking_id'],
                        'name' => $booking ? trim($booking['first_name'] . ' ' . $booking['last_name']) : '',
                        'status' => (string) $q['status'],
                        'attempts' => (int) $q['attempts'],
                        'error' => isset($q['error']) ? $q['error'] : null,
                        'sent_at' => !empty($q['sent_at']) ? (string) $q['sent_at'] : null,
                    ));
                }

                $moved++;
            }
        }

        update_option(self::MIGRATED, array('at' => current_time('mysql'), 'broadcasts' => $moved), false);
        delete_option('smbb_passflow_broadcast_last_run');

        if ($pending) {
            Outbox::kick();
        }
    }
}
