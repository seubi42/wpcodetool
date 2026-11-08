<?php

namespace Smbb\WpCodeTool\Mail;

defined('ABSPATH') || exit;

/**
 * Journal tournant des e-mails : envoyés et en erreur, gardés 15 jours puis effacés.
 *
 * Table {prefix}smbb_mail_log, créée à la première page d'admin (dbDelta). Alimentée par les
 * actions wp_mail_succeeded / wp_mail_failed de WordPress : on journalise tous les e-mails,
 * que CodeTool les envoie ou non (module coupé = méthode par défaut de WordPress).
 * Purge : tâche cron quotidienne smbb_codetool_mail_purge (visible dans CodeTool › Tâches cron).
 */
final class MailLog
{
    const DB_VERSION = '2';
    const DB_OPTION = 'smbb_codetool_mail_log_db';
    const CRON = 'smbb_codetool_mail_purge';
    const MAX_BODY = 262144; // 256 Ko : au-delà on coupe, le renvoi n'est alors plus proposé.
    const DIR_OPTION = 'smbb_codetool_mail_dir';

    /** @var string Origine du prochain envoi : '' (site), 'test', 'resend'. */
    public static $source = '';

    /** @var int Ligne d'origine du prochain envoi quand c'est un renvoi. */
    public static $resentFrom = 0;

    /** @var int Identifiant de la dernière ligne écrite. */
    public static $lastId = 0;

    /** @var string Type de contenu vu par PHPMailer (WooCommerce le donne par filtre, pas par en-tête). */
    private static $contentType = '';

    public static function hooks()
    {
        add_action('phpmailer_init', array(__CLASS__, 'captureType'), PHP_INT_MAX);
        add_action('wp_mail_succeeded', array(__CLASS__, 'onSucceeded'));
        add_action('wp_mail_failed', array(__CLASS__, 'onFailed'));
        add_action('admin_init', array(__CLASS__, 'install'));
        add_action('smbb_wpcodetool_register_cron_tasks', array(__CLASS__, 'registerCron'));
    }

    public static function table()
    {
        global $wpdb;

        return $wpdb->prefix . 'smbb_mail_log';
    }

    /**
     * Durées proposées pour le journal (jours => libellé).
     */
    public static function durations()
    {
        return array(
            7 => __('7 jours', 'smbb-wpcodetool'),
            15 => __('15 jours', 'smbb-wpcodetool'),
            30 => __('30 jours', 'smbb-wpcodetool'),
            60 => __('60 jours', 'smbb-wpcodetool'),
            90 => __('90 jours', 'smbb-wpcodetool'),
        );
    }

    /**
     * Durée de conservation (réglage « log_days », 15 jours par défaut ; filtre smbb_wpcodetool_mail_log_days).
     */
    public static function days()
    {
        $settings = Mailer::settings();
        $days = isset($settings['log_days']) ? (int) $settings['log_days'] : 15;

        return max(1, (int) apply_filters('smbb_wpcodetool_mail_log_days', $days > 0 ? $days : 15));
    }

    /**
     * Taille maximale des pièces jointes gardées pour un e-mail (20 Mo ; filtre smbb_wpcodetool_mail_attachments_max).
     */
    public static function maxAttachmentBytes()
    {
        return max(0, (int) apply_filters('smbb_wpcodetool_mail_attachments_max', 20 * MB_IN_BYTES));
    }

    /**
     * Dossier des copies de pièces jointes : uploads/smbb-mail-log-<clé aléatoire>/<id de l'e-mail>/.
     * Nom imprévisible (Nginx ignore .htaccess) + .htaccess « deny » + index.php vides.
     *
     * @param bool $create Créer le dossier s'il manque.
     * @return string Chemin sans / final, '' si indisponible.
     */
    public static function attachmentsDir($create = false)
    {
        $key = get_option(self::DIR_OPTION);

        if (!is_string($key) || $key === '') {
            if (!$create) {
                return '';
            }

            $key = strtolower(wp_generate_password(16, false, false));
            update_option(self::DIR_OPTION, $key, false);
        }

        $uploads = wp_upload_dir(null, false);

        if (!empty($uploads['error'])) {
            return '';
        }

        $dir = trailingslashit($uploads['basedir']) . 'smbb-mail-log-' . sanitize_key($key);

        if ($create && !is_dir($dir)) {
            if (!wp_mkdir_p($dir)) {
                return '';
            }

            @file_put_contents($dir . '/.htaccess', "<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n  Deny from all\n</IfModule>\n"); // phpcs:ignore
            @file_put_contents($dir . '/index.php', "<?php // Silence.\n"); // phpcs:ignore
        }

        return is_dir($dir) ? $dir : '';
    }

    /**
     * Copie les pièces jointes d'un e-mail du journal (les originaux sont souvent des fichiers temporaires,
     * effacés juste après l'envoi : factures PDF, exports...).
     *
     * @return string[] Noms des fichiers copiés (relatifs au dossier de l'e-mail).
     */
    private static function keepAttachments($id, array $files)
    {
        $settings = Mailer::settings();

        if (empty($settings['log_attachments']) || !$files) {
            return array();
        }

        $budget = self::maxAttachmentBytes();
        $base = self::attachmentsDir(true);

        if ($base === '' || $budget <= 0) {
            return array();
        }

        $dir = $base . '/' . (int) $id;
        $kept = array();

        foreach ($files as $file) {
            $file = (string) $file;
            $size = is_readable($file) && is_file($file) ? (int) filesize($file) : -1;

            if ($size < 0 || $size > $budget) {
                continue;
            }

            if (!is_dir($dir) && !wp_mkdir_p($dir)) {
                break;
            }

            $name = sanitize_file_name(wp_basename($file));
            $name = $name === '' ? 'piece-jointe' : $name;
            $target = $dir . '/' . wp_unique_filename($dir, $name);

            if (@copy($file, $target)) { // phpcs:ignore
                $kept[] = wp_basename($target);
                $budget -= $size;
            }
        }

        return $kept;
    }

    /**
     * Chemins des copies gardées pour une ligne du journal (seulement celles encore présentes).
     *
     * @return array<int,string> index => chemin complet
     */
    public static function storedAttachments(array $row)
    {
        $names = json_decode(isset($row['stored']) ? (string) $row['stored'] : '', true);
        $base = self::attachmentsDir();
        $out = array();

        if (!is_array($names) || $base === '') {
            return $out;
        }

        foreach (array_values($names) as $index => $name) {
            $path = $base . '/' . (int) $row['id'] . '/' . wp_basename((string) $name);

            if (is_readable($path)) {
                $out[$index] = $path;
            }
        }

        return $out;
    }

    /**
     * Efface les copies de pièces jointes des e-mails donnés.
     */
    private static function removeAttachments(array $ids)
    {
        $base = self::attachmentsDir();

        if ($base === '') {
            return;
        }

        foreach ($ids as $id) {
            self::removeDir($base . '/' . (int) $id);
        }
    }

    private static function removeDir($dir)
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach ((array) scandir($dir) as $name) {
            if ($name !== '.' && $name !== '..' && is_file($dir . '/' . $name)) {
                @unlink($dir . '/' . $name); // phpcs:ignore
            }
        }

        @rmdir($dir); // phpcs:ignore
    }

    public static function install()
    {
        if (get_option(self::DB_OPTION) === self::DB_VERSION) {
            return;
        }

        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $table = self::table();
        $charset = $wpdb->get_charset_collate();

        dbDelta("CREATE TABLE {$table} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  created_at datetime NOT NULL,
  status varchar(10) NOT NULL DEFAULT 'sent',
  source varchar(10) NOT NULL DEFAULT '',
  to_email text NOT NULL,
  subject text NOT NULL,
  message longtext NOT NULL,
  headers longtext NOT NULL,
  attachments text NOT NULL,
  stored text NOT NULL,
  error text NOT NULL,
  transport varchar(40) NOT NULL DEFAULT '',
  truncated tinyint(1) NOT NULL DEFAULT 0,
  resent_from bigint(20) unsigned NOT NULL DEFAULT 0,
  resends int(10) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  KEY created_at (created_at),
  KEY status (status)
) {$charset};");

        update_option(self::DB_OPTION, self::DB_VERSION, true);
    }

    public static function registerCron($registry)
    {
        $registry->register(self::CRON, array(
            'label' => __('Purge du journal des e-mails', 'smbb-wpcodetool'),
            'description' => sprintf(__('Efface les e-mails journalisés depuis plus de %d jours.', 'smbb-wpcodetool'), self::days()),
            'schedule' => 'daily',
            'callback' => array(__CLASS__, 'purge'),
        ));
    }

    public static function enabled()
    {
        $settings = Mailer::settings();

        return !empty($settings['log']) && get_option(self::DB_OPTION) === self::DB_VERSION;
    }

    public static function captureType($phpmailer)
    {
        self::$contentType = isset($phpmailer->ContentType) ? (string) $phpmailer->ContentType : '';
    }

    public static function onSucceeded($mail)
    {
        self::record('sent', is_array($mail) ? $mail : array(), '');
    }

    public static function onFailed($error)
    {
        $data = is_wp_error($error) ? $error->get_error_data() : array();
        $message = is_wp_error($error) ? $error->get_error_message() : __('Erreur inconnue', 'smbb-wpcodetool');

        self::record('failed', is_array($data) ? $data : array(), $message);
    }

    private static function record($status, array $mail, $error)
    {
        $source = self::$source;
        $resent_from = self::$resentFrom;
        $content_type = self::$contentType;
        self::$lastId = 0;
        self::$contentType = '';

        if (!self::enabled()) {
            return;
        }

        global $wpdb;
        $settings = Mailer::settings();
        $message = isset($mail['message']) ? (string) $mail['message'] : '';
        $truncated = 0;

        if (empty($settings['log_body'])) {
            $message = '';
            $truncated = 1;
        } elseif (strlen($message) > self::MAX_BODY) {
            $message = substr($message, 0, self::MAX_BODY);
            $truncated = 1;
        }

        $to = isset($mail['to']) ? $mail['to'] : array();
        $to = is_array($to) ? $to : explode(',', (string) $to);
        $attachments = isset($mail['attachments']) ? (array) $mail['attachments'] : array();
        $headers = isset($mail['headers']) ? $mail['headers'] : array();

        // HTML donné par le filtre wp_mail_content_type : on l'écrit dans les en-têtes gardés,
        // sinon un renvoi partirait en texte brut.
        if ($content_type === 'text/html') {
            $flat = is_array($headers) ? implode("\n", array_map('strval', $headers)) : (string) $headers;

            if (stripos($flat, 'content-type') === false) {
                if (is_array($headers)) {
                    $headers[] = 'Content-Type: text/html; charset=UTF-8';
                } else {
                    $headers = trim((string) $headers . "\r\nContent-Type: text/html; charset=UTF-8");
                }
            }
        }

        $transport = Mailer::active() || $source === 'test' ? (string) $settings['transport'] : 'wordpress';

        $wpdb->insert(self::table(), array(
            'created_at' => current_time('mysql', true),
            'status' => $status,
            'source' => $source,
            'to_email' => implode(', ', array_filter(array_map('trim', array_map('strval', $to)))),
            'subject' => isset($mail['subject']) ? (string) $mail['subject'] : '',
            'message' => $message,
            'headers' => wp_json_encode($headers),
            'attachments' => wp_json_encode(array_values(array_map('strval', $attachments))),
            'error' => (string) $error,
            'transport' => $transport,
            'truncated' => $truncated,
            'resent_from' => (int) $resent_from,
            'stored' => '[]',
        ));

        self::$lastId = (int) $wpdb->insert_id;

        if (self::$lastId && $attachments) {
            $kept = self::keepAttachments(self::$lastId, $attachments);

            if ($kept) {
                $wpdb->update(self::table(), array('stored' => wp_json_encode($kept)), array('id' => self::$lastId));
            }
        }
    }

    public static function purge()
    {
        if (get_option(self::DB_OPTION) !== self::DB_VERSION) {
            return 0;
        }

        global $wpdb;
        $limit = gmdate('Y-m-d H:i:s', time() - self::days() * DAY_IN_SECONDS);
        $ids = $wpdb->get_col($wpdb->prepare('SELECT id FROM ' . self::table() . ' WHERE created_at < %s', $limit)); // phpcs:ignore
        $deleted = (int) $wpdb->query($wpdb->prepare('DELETE FROM ' . self::table() . ' WHERE created_at < %s', $limit)); // phpcs:ignore

        self::removeAttachments(array_map('intval', (array) $ids));

        // Copies orphelines (ligne supprimée à la main, import interrompu...).
        $base = self::attachmentsDir();

        if ($base !== '') {
            $known = array_flip(array_map('intval', (array) $wpdb->get_col('SELECT id FROM ' . self::table() . " WHERE stored <> '[]'"))); // phpcs:ignore

            foreach ((array) glob($base . '/*', GLOB_ONLYDIR) as $dir) {
                $id = (int) wp_basename($dir);

                if ($id > 0 && !isset($known[$id])) {
                    self::removeDir($dir);
                }
            }
        }

        return $deleted;
    }

    /**
     * Compteurs depuis N jours : ['sent' => n, 'failed' => n, 'days' => [Y-m-d => [sent, failed]]].
     */
    public static function stats($days = 7)
    {
        return self::statsFrom(get_option(self::DB_OPTION) === self::DB_VERSION ? self::table() : '', $days);
    }

    /**
     * Compteurs d'une table de journal (colonnes created_at en GMT et status sent/failed) : e-mails ou SMS.
     */
    public static function statsFrom($table, $days = 7)
    {
        $empty = array('sent' => 0, 'failed' => 0, 'days' => array());
        $tz = wp_timezone();

        for ($i = $days - 1; $i >= 0; $i--) {
            $day = (new \DateTimeImmutable('now', $tz))->modify('-' . $i . ' days')->format('Y-m-d');
            $empty['days'][$day] = array('sent' => 0, 'failed' => 0);
        }

        if ($table === '') {
            return $empty;
        }

        global $wpdb;
        $first = array_keys($empty['days'])[0];
        $since = get_gmt_from_date($first . ' 00:00:00');
        $rows = $wpdb->get_results($wpdb->prepare('SELECT created_at, status FROM ' . $table . ' WHERE created_at >= %s', $since), ARRAY_A); // phpcs:ignore

        foreach ((array) $rows as $row) {
            $status = $row['status'] === 'failed' ? 'failed' : 'sent';
            $day = get_date_from_gmt($row['created_at'], 'Y-m-d');
            $empty[$status]++;

            if (isset($empty['days'][$day])) {
                $empty['days'][$day][$status]++;
            }
        }

        return $empty;
    }

    /**
     * Page du journal : ['rows' => [...], 'total' => n].
     */
    public static function query($status = '', $search = '', $page = 1, $per_page = 50)
    {
        if (get_option(self::DB_OPTION) !== self::DB_VERSION) {
            return array('rows' => array(), 'total' => 0);
        }

        global $wpdb;
        $where = array('1=1');
        $args = array();

        if (in_array($status, array('sent', 'failed'), true)) {
            $where[] = 'status = %s';
            $args[] = $status;
        }

        if ($search !== '') {
            $like = '%' . $wpdb->esc_like($search) . '%';
            $where[] = '(to_email LIKE %s OR subject LIKE %s OR error LIKE %s)';
            array_push($args, $like, $like, $like);
        }

        $sql_where = implode(' AND ', $where);
        $table = self::table();
        $count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$sql_where}";
        $total = (int) $wpdb->get_var($args ? $wpdb->prepare($count_sql, $args) : $count_sql); // phpcs:ignore

        $offset = max(0, ($page - 1) * $per_page);
        $list_sql = "SELECT id, created_at, status, source, to_email, subject, error, transport, truncated, resent_from, resends, headers, attachments, stored FROM {$table} WHERE {$sql_where} ORDER BY id DESC LIMIT %d OFFSET %d";
        $rows = $wpdb->get_results($wpdb->prepare($list_sql, array_merge($args, array($per_page, $offset))), ARRAY_A); // phpcs:ignore

        return array('rows' => (array) $rows, 'total' => $total);
    }

    public static function get($id)
    {
        global $wpdb;

        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table() . ' WHERE id = %d', $id), ARRAY_A); // phpcs:ignore

        return is_array($row) ? $row : null;
    }

    public static function delete(array $ids)
    {
        $ids = array_filter(array_map('absint', $ids));

        if (!$ids) {
            return 0;
        }

        global $wpdb;
        $deleted = (int) $wpdb->query('DELETE FROM ' . self::table() . ' WHERE id IN (' . implode(',', $ids) . ')'); // phpcs:ignore -- entiers seulement.
        self::removeAttachments($ids);

        return $deleted;
    }

    public static function canResend(array $row)
    {
        return empty($row['truncated']) && trim((string) $row['to_email']) !== '';
    }

    /**
     * Renvoie des e-mails du journal (destinataires, sujet, contenu et en-têtes d'origine).
     * Les pièces jointes sont reprises si le fichier existe encore sur le serveur.
     *
     * @return array{sent:int,failed:int,skipped:int}
     */
    public static function resend(array $ids)
    {
        $result = array('sent' => 0, 'failed' => 0, 'skipped' => 0);
        global $wpdb;

        foreach (array_filter(array_map('absint', $ids)) as $id) {
            $row = self::get($id);

            if (!$row || !self::canResend($row)) {
                $result['skipped']++;
                continue;
            }

            $headers = json_decode((string) $row['headers'], true);
            $attachments = self::resendAttachments($row);

            self::$source = 'resend';
            self::$resentFrom = (int) $row['id'];
            $ok = wp_mail(array_map('trim', explode(',', $row['to_email'])), $row['subject'], $row['message'], $headers === null ? array() : $headers, $attachments);
            self::$source = '';
            self::$resentFrom = 0;

            $wpdb->query($wpdb->prepare('UPDATE ' . self::table() . ' SET resends = resends + 1 WHERE id = %d', $row['id'])); // phpcs:ignore
            $result[$ok ? 'sent' : 'failed']++;
        }

        return $result;
    }

    /**
     * Pièces jointes à joindre au renvoi : la copie gardée, sinon l'original s'il existe encore.
     *
     * @return string[]
     */
    public static function resendAttachments(array $row)
    {
        $originals = json_decode((string) $row['attachments'], true);
        $originals = is_array($originals) ? array_values($originals) : array();
        $stored = self::storedAttachments($row);
        $files = array_values($stored);

        // Originaux sans copie (trop gros, réglage coupé) : repris s'ils sont toujours là.
        $kept_names = array_map('wp_basename', $files);

        foreach ($originals as $file) {
            if (is_string($file) && $file !== '' && is_readable($file) && !in_array(sanitize_file_name(wp_basename($file)), $kept_names, true)) {
                $files[] = $file;
            }
        }

        return $files;
    }

    /**
     * Pièces jointes manquantes au renvoi (ni copie ni original).
     */
    public static function missingAttachments(array $row)
    {
        $originals = json_decode((string) $row['attachments'], true);
        $count = is_array($originals) ? count($originals) : 0;

        return max(0, $count - count(self::resendAttachments($row)));
    }

    /**
     * Le contenu est-il du HTML ? (en-tête Content-Type ou balises).
     */
    public static function isHtml(array $row)
    {
        $headers = json_decode((string) $row['headers'], true);
        $flat = is_array($headers) ? implode("\n", array_map('strval', $headers)) : (string) $headers;

        if (stripos($flat, 'text/html') !== false) {
            return true;
        }

        return (bool) preg_match('/<(html|body|table|div|p|br)\b/i', (string) $row['message']);
    }
}
