<?php

namespace Smbb\WpCodeTool\Outbox;

defined('ABSPATH') || exit;

/**
 * File d'envoi commune : les plugins y déposent des envois groupés (SMS, e-mails) que CodeTool
 * diffuse par lots, au rythme réglé dans CodeTool › Communication › File d'envoi.
 *
 * Un « envoi » (table {prefix}smbb_outbox) = un message pour une liste de destinataires.
 * Chaque destinataire est une ligne de {prefix}smbb_outbox_item (canal, adresse, statut,
 * tentatives, erreur). La diffusion démarre juste après la réponse au navigateur, puis continue
 * par lots (événement unique smbb_codetool_outbox_now) ; la tâche CodeTool toutes les 15 minutes
 * sert de filet de sécurité. Trois tentatives par message.
 *
 * Côté plugin :
 *
 *     add_filter('smbb_wpcodetool_outbox_sources', function ($sources) {
 *         $sources['passflow'] = array(
 *             'label' => 'Passflow',
 *             // Envoie UN message. Sans 'send' : wp_mail() ou Sms::send() avec le texte tel quel.
 *             'send'  => function (array $item, array $outbox) {
 *                 return array('status' => 'sent', 'error' => ''); // sent | test | skipped | failed
 *             },
 *             // Optionnels : lien vers l'objet d'origine et son nom dans la file.
 *             'link'    => function (array $outbox) { return admin_url('…'); },
 *             'context' => function (array $outbox) { return 'Atelier du 12/11'; },
 *         );
 *         return $sources;
 *     });
 *
 *     $id = Outbox::queue(array(
 *         'source' => 'passflow', 'context_type' => 'slot', 'context_id' => 42, 'kind' => 'manual',
 *         'label' => 'Les présents', 'channels' => array('sms', 'email'),
 *         'subject' => '…', 'message' => '…', 'meta' => array('target' => 'present'),
 *         'recipients' => array(array('channel' => 'sms', 'address' => '+336…', 'ref' => 17, 'name' => 'Jeanne')),
 *     )); // int | WP_Error
 */
final class Outbox
{
    const DB_VERSION = '1';
    const DB_OPTION = 'smbb_codetool_outbox_db';
    const OPTION = 'smbb_codetool_outbox';
    const LAST_RUN = 'smbb_codetool_outbox_last_run';
    const CRON = 'smbb_codetool_outbox';
    const NOW = 'smbb_codetool_outbox_now';
    const MAX_ATTEMPTS = 3;

    /** Statuts d'un envoi (file) et d'un message (destinataire). */
    const RUNNING = array('queued', 'sending');

    private static $run_after_response = false;

    public static function hooks()
    {
        add_action('admin_init', array(__CLASS__, 'install'));
        add_action('smbb_wpcodetool_register_cron_tasks', array(__CLASS__, 'registerCron'));
        add_action(self::NOW, array(__CLASS__, 'run'));
    }

    public static function table()
    {
        global $wpdb;

        return $wpdb->prefix . 'smbb_outbox';
    }

    public static function itemsTable()
    {
        global $wpdb;

        return $wpdb->prefix . 'smbb_outbox_item';
    }

    /**
     * Droit pour voir la file et la régler (filtre smbb_wpcodetool_outbox_capability).
     */
    public static function capability()
    {
        return (string) apply_filters('smbb_wpcodetool_outbox_capability', 'manage_options');
    }

    public static function ready()
    {
        return get_option(self::DB_OPTION) === self::DB_VERSION;
    }

    public static function install()
    {
        if (self::ready()) {
            return;
        }

        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $table = self::table();
        $items = self::itemsTable();
        $charset = $wpdb->get_charset_collate();

        dbDelta("CREATE TABLE {$table} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  source varchar(60) NOT NULL DEFAULT '',
  context_type varchar(40) NOT NULL DEFAULT '',
  context_id varchar(64) NOT NULL DEFAULT '',
  kind varchar(40) NOT NULL DEFAULT '',
  label varchar(255) NOT NULL DEFAULT '',
  channels varchar(40) NOT NULL DEFAULT '',
  subject varchar(255) NOT NULL DEFAULT '',
  message longtext NOT NULL,
  meta longtext NOT NULL,
  status varchar(20) NOT NULL DEFAULT 'queued',
  total int(10) unsigned NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  created_by bigint(20) unsigned NOT NULL DEFAULT 0,
  started_at datetime NULL,
  finished_at datetime NULL,
  PRIMARY KEY  (id),
  KEY status (status),
  KEY context (source, context_type, context_id),
  KEY created_at (created_at)
) {$charset};");

        dbDelta("CREATE TABLE {$items} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  outbox_id bigint(20) unsigned NOT NULL,
  channel varchar(20) NOT NULL DEFAULT '',
  address varchar(190) NOT NULL DEFAULT '',
  ref varchar(64) NOT NULL DEFAULT '',
  name varchar(190) NOT NULL DEFAULT '',
  status varchar(20) NOT NULL DEFAULT 'queued',
  attempts tinyint(3) unsigned NOT NULL DEFAULT 0,
  error text NULL,
  sent_at datetime NULL,
  PRIMARY KEY  (id),
  KEY outbox_status (outbox_id, status),
  KEY status (status)
) {$charset};");

        update_option(self::DB_OPTION, self::DB_VERSION, true);
    }

    /**
     * @param \Smbb\WpCodeTool\Cron\CronRegistry $registry
     */
    public static function registerCron($registry)
    {
        $registry->register(self::CRON, array(
            'label' => __('File d\'envoi', 'smbb-wpcodetool'),
            'description' => __('Diffuse les SMS et e-mails en attente dans la file d\'envoi (filet de sécurité : la diffusion démarre déjà tout de suite).', 'smbb-wpcodetool'),
            'schedule' => 'smbb_every_15_minutes',
            'callback' => array(__CLASS__, 'run'),
        ));
    }

    // ------------------------------------------------------------------ Réglages

    /**
     * Cadence : X messages par lot, un lot toutes les Y minutes. À la première lecture, reprend
     * les valeurs que Passflow avait dans Réglages › Envoi (broadcast_batch / broadcast_every).
     *
     * @return array{batch:int,every:int}
     */
    public static function settings()
    {
        $stored = get_option(self::OPTION, null);

        if (!is_array($stored)) {
            $stored = array('batch' => 60, 'every' => 1);
            $legacy = get_option('smbb_passflow_settings');

            if (is_array($legacy)) {
                if (!empty($legacy['broadcast_batch'])) {
                    $stored['batch'] = (int) $legacy['broadcast_batch'];
                }

                if (!empty($legacy['broadcast_every'])) {
                    $stored['every'] = (int) $legacy['broadcast_every'];
                }
            }

            update_option(self::OPTION, self::cleanSettings($stored), false);
        }

        return self::cleanSettings($stored);
    }

    public static function saveSettings(array $values)
    {
        $clean = self::cleanSettings(array_merge(self::settings(), $values));
        update_option(self::OPTION, $clean, false);

        return $clean;
    }

    private static function cleanSettings(array $values)
    {
        return array(
            'batch' => min(500, max(1, (int) ($values['batch'] ?? 60))),
            'every' => min(60, max(1, (int) ($values['every'] ?? 1))),
        );
    }

    public static function batchSize()
    {
        return (int) apply_filters('smbb_wpcodetool_outbox_batch', self::settings()['batch']);
    }

    public static function interval()
    {
        return max(1, (int) self::settings()['every']) * MINUTE_IN_SECONDS;
    }

    // ------------------------------------------------------------------ Sources

    /**
     * Plugins qui déposent des envois : clé => [label, send, link, context].
     *
     * @return array<string,array<string,mixed>>
     */
    public static function sources()
    {
        $sources = (array) apply_filters('smbb_wpcodetool_outbox_sources', array());

        return array_filter($sources, 'is_array');
    }

    public static function sourceLabel($key)
    {
        $sources = self::sources();

        return isset($sources[$key]['label']) ? (string) $sources[$key]['label'] : (string) $key;
    }

    /**
     * Lien vers l'objet d'origine (fiche de la journée Passflow, document SignaConnect…).
     */
    public static function contextLink(array $outbox)
    {
        $sources = self::sources();
        $source = isset($sources[$outbox['source']]) ? $sources[$outbox['source']] : array();
        $url = isset($source['link']) && is_callable($source['link']) ? (string) call_user_func($source['link'], $outbox) : '';

        return (string) apply_filters('smbb_wpcodetool_outbox_link', $url, $outbox);
    }

    public static function contextLabel(array $outbox)
    {
        $sources = self::sources();
        $source = isset($sources[$outbox['source']]) ? $sources[$outbox['source']] : array();
        $label = isset($source['context']) && is_callable($source['context']) ? (string) call_user_func($source['context'], $outbox) : '';

        if ($label === '' && $outbox['context_type'] !== '') {
            $label = $outbox['context_type'] . ($outbox['context_id'] !== '' ? ' #' . $outbox['context_id'] : '');
        }

        return $label;
    }

    // ------------------------------------------------------------------ Création

    /**
     * Met un envoi en file et lance la diffusion après la réponse.
     *
     * @param array<string,mixed> $args voir l'en-tête de la classe
     * @return int|\WP_Error identifiant de l'envoi
     */
    public static function queue(array $args)
    {
        global $wpdb;

        self::install();

        $source = sanitize_key((string) ($args['source'] ?? ''));
        $message = trim((string) ($args['message'] ?? ''));
        $recipients = isset($args['recipients']) && is_array($args['recipients']) ? $args['recipients'] : array();
        $rows = array();
        $channels = array();

        if ($source === '') {
            return new \WP_Error('smbb_outbox_source', __('Envoi sans origine : précisez la source.', 'smbb-wpcodetool'));
        }

        if ($message === '') {
            return new \WP_Error('smbb_outbox_message', __('Écrivez le message à envoyer.', 'smbb-wpcodetool'));
        }

        foreach ($recipients as $r) {
            $channel = sanitize_key((string) ($r['channel'] ?? ''));

            if (!in_array($channel, array('email', 'sms'), true)) {
                continue;
            }

            $channels[$channel] = true;
            $rows[] = array(
                $channel,
                substr((string) ($r['address'] ?? ''), 0, 190),
                substr((string) ($r['ref'] ?? ''), 0, 64),
                substr(wp_strip_all_tags((string) ($r['name'] ?? '')), 0, 190),
            );
        }

        if (!$rows) {
            return new \WP_Error('smbb_outbox_empty', __('Personne à prévenir : aucun destinataire.', 'smbb-wpcodetool'));
        }

        $channels = isset($args['channels']) ? array_values(array_intersect(array('email', 'sms'), (array) $args['channels'])) : array_keys($channels);

        $wpdb->insert(self::table(), array(
            'source' => $source,
            'context_type' => sanitize_key((string) ($args['context_type'] ?? '')),
            'context_id' => substr((string) ($args['context_id'] ?? ''), 0, 64),
            'kind' => sanitize_key((string) ($args['kind'] ?? '')),
            'label' => substr(wp_strip_all_tags((string) ($args['label'] ?? '')), 0, 255),
            'channels' => implode(',', $channels),
            'subject' => substr((string) ($args['subject'] ?? ''), 0, 255),
            'message' => $message,
            'meta' => wp_json_encode(isset($args['meta']) && is_array($args['meta']) ? $args['meta'] : array()),
            'status' => 'queued',
            'total' => count($rows),
            'created_at' => current_time('mysql'),
            'created_by' => isset($args['created_by']) ? (int) $args['created_by'] : (int) get_current_user_id(),
        ));
        $id = (int) $wpdb->insert_id;

        if (!$id) {
            return new \WP_Error('smbb_outbox_db', __('L\'envoi n\'a pas pu être enregistré.', 'smbb-wpcodetool'));
        }

        foreach (array_chunk($rows, 200) as $chunk) {
            $values = array();

            foreach ($chunk as $row) {
                $values[] = $wpdb->prepare('(%d, %s, %s, %s, %s, %s, 0)', $id, $row[0], $row[1], $row[2], $row[3], 'queued');
            }

            $wpdb->query('INSERT INTO ' . self::itemsTable() . ' (outbox_id, channel, address, ref, name, status, attempts) VALUES ' . implode(',', $values));
        }

        do_action('smbb_wpcodetool_outbox_queued', $id, $args);

        if (empty($args['defer'])) {
            self::kick();
        }

        return $id;
    }

    /**
     * Arrête un envoi : les messages pas encore partis sont abandonnés.
     *
     * @return int messages abandonnés
     */
    public static function stop($id)
    {
        global $wpdb;

        $outbox = self::find($id);

        if (!$outbox || !in_array($outbox['status'], self::RUNNING, true)) {
            return 0;
        }

        $count = (int) $wpdb->query($wpdb->prepare('UPDATE ' . self::itemsTable() . " SET status = 'cancelled' WHERE outbox_id = %d AND status = 'queued'", (int) $id));
        $wpdb->update(self::table(), array('status' => 'stopped', 'finished_at' => current_time('mysql')), array('id' => (int) $id));

        do_action('smbb_wpcodetool_activity', 'outbox.stopped', array(
            'category' => 'communication',
            'level' => 'notice',
            'message' => sprintf(__('Envoi arrêté : %1$s (%2$d message(s) abandonné(s))', 'smbb-wpcodetool'), self::title($outbox), $count),
            'object_type' => 'outbox',
            'object_id' => (int) $id,
            'source' => 'smbb-wpcodetool',
        ));

        return $count;
    }

    /**
     * Remet en file les messages en échec d'un envoi (nouvelles tentatives).
     *
     * @return int messages remis en file
     */
    public static function retry($id)
    {
        global $wpdb;

        $outbox = self::find($id);

        if (!$outbox) {
            return 0;
        }

        $count = (int) $wpdb->query($wpdb->prepare('UPDATE ' . self::itemsTable() . " SET status = 'queued', attempts = 0, error = NULL WHERE outbox_id = %d AND status = 'failed'", (int) $id));

        if ($count > 0) {
            $wpdb->update(self::table(), array('status' => 'sending', 'finished_at' => null), array('id' => (int) $id));
            self::kick();
        }

        return $count;
    }

    // ------------------------------------------------------------------ Lecture

    public static function find($id)
    {
        global $wpdb;

        if (!self::ready()) {
            return null;
        }

        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table() . ' WHERE id = %d', (int) $id), ARRAY_A);

        return is_array($row) ? self::hydrate($row) : null;
    }

    private static function hydrate(array $row)
    {
        $meta = json_decode((string) $row['meta'], true);
        $row['meta'] = is_array($meta) ? $meta : array();

        return $row;
    }

    /**
     * Nom court d'un envoi : son libellé, sinon le début du message.
     */
    public static function title(array $outbox)
    {
        return $outbox['label'] !== '' ? (string) $outbox['label'] : wp_trim_words((string) $outbox['message'], 8);
    }

    /**
     * Envois d'un objet (journée Passflow…), les plus récents d'abord, avec leurs compteurs.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function forContext($source, $context_type, $context_id, $limit = 30)
    {
        global $wpdb;

        if (!self::ready()) {
            return array();
        }

        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM ' . self::table() . ' WHERE source = %s AND context_type = %s AND context_id = %s ORDER BY id DESC LIMIT %d',
            (string) $source,
            (string) $context_type,
            (string) $context_id,
            (int) $limit
        ), ARRAY_A);

        return self::withTally(is_array($rows) ? $rows : array());
    }

    /**
     * Destinataires distincts (ref) qui ont reçu au moins un message d'un objet, pour un type d'envoi.
     */
    public static function sentCount($source, $context_type, $context_id, $kind = '')
    {
        global $wpdb;

        if (!self::ready()) {
            return 0;
        }

        $sql = $wpdb->prepare(
            'SELECT COUNT(DISTINCT i.ref) FROM ' . self::itemsTable() . ' i INNER JOIN ' . self::table() . " o ON o.id = i.outbox_id WHERE o.source = %s AND o.context_type = %s AND o.context_id = %s AND i.status = 'sent' AND i.ref <> ''",
            (string) $source,
            (string) $context_type,
            (string) $context_id
        );

        if ((string) $kind !== '') {
            $sql .= $wpdb->prepare(' AND o.kind = %s', (string) $kind);
        }

        return (int) $wpdb->get_var($sql);
    }

    /**
     * Envois pas encore terminés d'un objet.
     */
    public static function pendingCount($source, $context_type, $context_id)
    {
        global $wpdb;

        if (!self::ready()) {
            return 0;
        }

        return (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM ' . self::table() . " WHERE source = %s AND context_type = %s AND context_id = %s AND status IN ('queued', 'sending')",
            (string) $source,
            (string) $context_type,
            (string) $context_id
        ));
    }

    /**
     * Liste de la file pour l'écran : filtres status (running|done|stopped|failed), source, s.
     *
     * @return array{rows:array<int,array<string,mixed>>,total:int}
     */
    public static function query(array $filters, $page = 1, $per_page = 30)
    {
        global $wpdb;

        if (!self::ready()) {
            return array('rows' => array(), 'total' => 0);
        }

        $where = array('1=1');
        $status = (string) ($filters['status'] ?? '');

        if ($status === 'running') {
            $where[] = "o.status IN ('queued', 'sending')";
        } elseif (in_array($status, array('done', 'stopped'), true)) {
            $where[] = $wpdb->prepare('o.status = %s', $status);
        } elseif ($status === 'failed') {
            $where[] = 'EXISTS (SELECT 1 FROM ' . self::itemsTable() . " i WHERE i.outbox_id = o.id AND i.status = 'failed')";
        }

        if (!empty($filters['source'])) {
            $where[] = $wpdb->prepare('o.source = %s', (string) $filters['source']);
        }

        if (!empty($filters['s'])) {
            $like = '%' . $wpdb->esc_like((string) $filters['s']) . '%';
            $where[] = $wpdb->prepare('(o.label LIKE %s OR o.subject LIKE %s OR o.message LIKE %s)', $like, $like, $like);
        }

        $sql_where = implode(' AND ', $where);
        $total = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . self::table() . ' o WHERE ' . $sql_where);
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT o.* FROM ' . self::table() . ' o WHERE ' . $sql_where . ' ORDER BY (o.status IN (\'queued\', \'sending\')) DESC, o.id DESC LIMIT %d OFFSET %d',
            (int) $per_page,
            max(0, ((int) $page - 1) * (int) $per_page)
        ), ARRAY_A);

        return array('rows' => self::withTally(is_array($rows) ? $rows : array()), 'total' => $total);
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     * @return array<int,array<string,mixed>>
     */
    private static function withTally(array $rows)
    {
        global $wpdb;

        if (!$rows) {
            return array();
        }

        $ids = array_map('intval', wp_list_pluck($rows, 'id'));
        $tally = $wpdb->get_results('SELECT outbox_id, status, COUNT(*) AS n FROM ' . self::itemsTable() . ' WHERE outbox_id IN (' . implode(',', $ids) . ') GROUP BY outbox_id, status', ARRAY_A);
        $by = array();

        foreach ((array) $tally as $t) {
            $by[(int) $t['outbox_id']][$t['status']] = (int) $t['n'];
        }

        foreach ($rows as &$row) {
            $row = self::hydrate($row);
            $row['tally'] = array_merge(self::emptyTally(), isset($by[(int) $row['id']]) ? $by[(int) $row['id']] : array());
        }
        unset($row);

        return $rows;
    }

    /**
     * Compteurs d'un seul envoi (queued, sent, failed, skipped, cancelled).
     */
    public static function tally($id)
    {
        $rows = self::withTally(array(array('id' => (int) $id, 'meta' => '')));

        return $rows ? $rows[0]['tally'] : self::emptyTally();
    }

    public static function emptyTally()
    {
        return array('queued' => 0, 'sent' => 0, 'failed' => 0, 'skipped' => 0, 'cancelled' => 0);
    }

    /**
     * Messages d'un envoi (écran de détail), filtre par statut.
     *
     * @return array{rows:array<int,array<string,mixed>>,total:int}
     */
    public static function items($outbox_id, $status = '', $page = 1, $per_page = 50)
    {
        global $wpdb;

        $where = $wpdb->prepare('outbox_id = %d', (int) $outbox_id);

        if ($status !== '') {
            $where .= $wpdb->prepare(' AND status = %s', (string) $status);
        }

        $total = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . self::itemsTable() . ' WHERE ' . $where);
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM ' . self::itemsTable() . ' WHERE ' . $where . ' ORDER BY (status = \'failed\') DESC, id ASC LIMIT %d OFFSET %d',
            (int) $per_page,
            max(0, ((int) $page - 1) * (int) $per_page)
        ), ARRAY_A);

        return array('rows' => is_array($rows) ? $rows : array(), 'total' => $total);
    }

    /**
     * Chiffres du haut de l'écran : messages en attente, envois en cours, envoyés et échecs sur 24 h.
     *
     * @return array{waiting:int,running:int,sent_24h:int,failed_24h:int,next:int,last:int}
     */
    public static function stats()
    {
        global $wpdb;

        $out = array('waiting' => 0, 'running' => 0, 'sent_24h' => 0, 'failed_24h' => 0, 'next' => 0, 'last' => (int) get_option(self::LAST_RUN, 0));

        if (!self::ready()) {
            return $out;
        }

        $since = wp_date('Y-m-d H:i:s', time() - DAY_IN_SECONDS);
        $out['waiting'] = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . self::itemsTable() . ' i INNER JOIN ' . self::table() . " o ON o.id = i.outbox_id WHERE i.status = 'queued' AND o.status IN ('queued', 'sending')");
        $out['running'] = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . self::table() . " WHERE status IN ('queued', 'sending')");
        $out['sent_24h'] = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . self::itemsTable() . " WHERE status = 'sent' AND sent_at >= %s", $since));
        $out['failed_24h'] = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . self::itemsTable() . " WHERE status = 'failed' AND sent_at >= %s", $since));
        $out['next'] = (int) wp_next_scheduled(self::NOW);

        return $out;
    }

    // ------------------------------------------------------------------ Diffusion

    /**
     * Démarre la diffusion après la réponse au navigateur, et programme la suite au cas où.
     */
    public static function kick()
    {
        if (!self::$run_after_response) {
            self::$run_after_response = true;
            add_action('shutdown', array(__CLASS__, 'runAfterResponse'), 110);
        }

        self::scheduleNext(self::interval());
    }

    private static function scheduleNext($delay)
    {
        if (!wp_next_scheduled(self::NOW)) {
            wp_schedule_single_event(time() + max(10, (int) $delay), self::NOW);
        }
    }

    public static function runAfterResponse()
    {
        if (function_exists('fastcgi_finish_request') && !wp_doing_cron()) {
            fastcgi_finish_request();
        }

        ignore_user_abort(true);
        self::run();
    }

    /**
     * Envoie un lot de messages en file. Un seul traitement à la fois (verrou MySQL).
     * Pas plus d'un lot par intervalle, d'où qu'il soit lancé, sauf $force (bouton de l'écran).
     *
     * @return int messages traités
     */
    public static function run($force = false)
    {
        global $wpdb;

        if (!self::ready()) {
            return 0;
        }

        $force = $force === true;
        $interval = self::interval();
        $last = (int) get_option(self::LAST_RUN, 0);

        if (!$force && $last > 0 && time() - $last < $interval - 5) {
            self::scheduleNext($last + $interval - time());

            return 0;
        }

        $lock = $wpdb->prefix . 'smbb_outbox_run';

        if ((int) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)', $lock)) !== 1) {
            return 0;
        }

        $items = self::itemsTable();
        $table = self::table();
        $started = time();
        $done = 0;

        if (function_exists('set_time_limit')) {
            @set_time_limit(180);
        }

        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT i.* FROM ' . $items . ' i INNER JOIN ' . $table . " o ON o.id = i.outbox_id WHERE i.status = 'queued' AND o.status IN ('queued', 'sending') ORDER BY i.id ASC LIMIT %d",
            self::batchSize()
        ), ARRAY_A);

        if ($rows) {
            update_option(self::LAST_RUN, $started, false);
        }

        $outboxes = array();
        $sources = self::sources();

        foreach ((array) $rows as $row) {
            if (time() - $started > max(50, min(110, $interval - 10))) {
                break; // la suite au prochain passage
            }

            $oid = (int) $row['outbox_id'];

            if (!isset($outboxes[$oid])) {
                $outboxes[$oid] = self::find($oid);

                if ($outboxes[$oid] && $outboxes[$oid]['status'] === 'queued') {
                    $wpdb->update($table, array('status' => 'sending', 'started_at' => current_time('mysql')), array('id' => $oid));
                }
            }

            $outbox = $outboxes[$oid];

            if (!$outbox) {
                $wpdb->update($items, array('status' => 'skipped', 'error' => __('Envoi introuvable.', 'smbb-wpcodetool')), array('id' => (int) $row['id']));
                continue;
            }

            $result = self::deliver($row, $outbox, isset($sources[$outbox['source']]) ? $sources[$outbox['source']] : array());
            $attempts = (int) $row['attempts'] + 1;
            $status = in_array($result['status'], array('sent', 'test'), true) ? 'sent' : ($result['status'] === 'skipped' ? 'skipped' : 'failed');

            // Échec : on retente au prochain passage, trois fois au plus.
            if ($status === 'failed' && $attempts < self::MAX_ATTEMPTS) {
                $status = 'queued';
            }

            $update = array(
                'status' => $status,
                'attempts' => $attempts,
                'error' => $result['error'] !== '' ? substr($result['error'], 0, 1000) : null,
                'sent_at' => current_time('mysql'),
            );

            if (!empty($result['to']) && $row['address'] === '') {
                $update['address'] = substr((string) $result['to'], 0, 190);
            }

            $wpdb->update($items, $update, array('id' => (int) $row['id']));
            $done++;
        }

        // Envois terminés : plus rien en file.
        $finished = $wpdb->get_col("SELECT o.id FROM " . $table . " o WHERE o.status IN ('queued', 'sending') AND NOT EXISTS (SELECT 1 FROM " . $items . " i WHERE i.outbox_id = o.id AND i.status = 'queued')");

        foreach ((array) $finished as $fid) {
            $wpdb->update($table, array('status' => 'done', 'finished_at' => current_time('mysql')), array('id' => (int) $fid));
            do_action('smbb_wpcodetool_outbox_done', (int) $fid);
        }

        $left = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . $items . ' i INNER JOIN ' . $table . " o ON o.id = i.outbox_id WHERE i.status = 'queued' AND o.status IN ('queued', 'sending')");
        $wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock));

        if ($left > 0) {
            self::scheduleNext($interval);
        }

        return $done;
    }

    /**
     * Envoie un message : par la source (Passflow personnalise, journalise, mode test…),
     * sinon tel quel par wp_mail() ou les SMS de CodeTool.
     *
     * @return array{status:string,error:string,to?:string}
     */
    private static function deliver(array $item, array $outbox, array $source)
    {
        try {
            if (isset($source['send']) && is_callable($source['send'])) {
                $result = call_user_func($source['send'], $item, $outbox);
            } elseif ($item['channel'] === 'email') {
                $ok = wp_mail($item['address'], (string) $outbox['subject'], (string) $outbox['message']);
                $result = array('status' => $ok ? 'sent' : 'failed', 'error' => $ok ? '' : __('wp_mail() a refusé l\'envoi.', 'smbb-wpcodetool'));
            } elseif ($item['channel'] === 'sms' && class_exists('\Smbb\WpCodeTool\Sms\Sms') && \Smbb\WpCodeTool\Sms\Sms::ready() === true) {
                $sent = \Smbb\WpCodeTool\Sms\Sms::send($item['address'], (string) $outbox['message'], array('source' => 'outbox:' . $outbox['source']));
                $result = is_wp_error($sent) ? array('status' => 'failed', 'error' => $sent->get_error_message()) : array('status' => 'sent', 'error' => '');
            } else {
                $result = array('status' => 'failed', 'error' => __('Aucun moyen d\'envoyer ce message (SMS non configurés ?).', 'smbb-wpcodetool'));
            }
        } catch (\Throwable $e) {
            $result = array('status' => 'failed', 'error' => $e->getMessage());
        }

        if (is_wp_error($result)) {
            return array('status' => 'failed', 'error' => $result->get_error_message());
        }

        $result = is_array($result) ? $result : array('status' => $result ? 'sent' : 'failed');

        return array(
            'status' => (string) ($result['status'] ?? 'failed'),
            'error' => (string) ($result['error'] ?? ''),
            'to' => (string) ($result['to'] ?? ''),
        );
    }
}
