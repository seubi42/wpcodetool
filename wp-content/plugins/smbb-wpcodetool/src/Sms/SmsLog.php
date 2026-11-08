<?php

namespace Smbb\WpCodeTool\Sms;

use Smbb\WpCodeTool\Mail\MailLog;

defined('ABSPATH') || exit;

/**
 * Journal tournant des SMS envoyés par CodeTool (Sms::send), même principe que le journal des e-mails :
 * envoyés et en erreur, durée réglable (SMS › Réglages), purge quotidienne, renvoi et suppression.
 *
 * Table {prefix}smbb_sms_log créée à la première page d'admin. Purge : tâche cron smbb_codetool_sms_purge.
 */
final class SmsLog
{
    const DB_VERSION = '1';
    const DB_OPTION = 'smbb_codetool_sms_log_db';
    const CRON = 'smbb_codetool_sms_purge';

    public static function hooks()
    {
        add_action('admin_init', array(__CLASS__, 'install'));
        add_action('smbb_wpcodetool_register_cron_tasks', array(__CLASS__, 'registerCron'));
    }

    public static function table()
    {
        global $wpdb;

        return $wpdb->prefix . 'smbb_sms_log';
    }

    public static function days()
    {
        $settings = Sms::settings();
        $days = isset($settings['log_days']) ? (int) $settings['log_days'] : 15;

        return max(1, (int) apply_filters('smbb_wpcodetool_sms_log_days', $days > 0 ? $days : 15));
    }

    public static function ready()
    {
        return get_option(self::DB_OPTION) === self::DB_VERSION;
    }

    public static function enabled()
    {
        $settings = Sms::settings();

        return !empty($settings['log']) && self::ready();
    }

    public static function install()
    {
        if (self::ready()) {
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
  source varchar(20) NOT NULL DEFAULT '',
  to_number varchar(40) NOT NULL DEFAULT '',
  body text NOT NULL,
  provider varchar(40) NOT NULL DEFAULT '',
  provider_id varchar(80) NOT NULL DEFAULT '',
  error text NOT NULL,
  context text NOT NULL,
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
            'label' => __('Purge du journal des SMS', 'smbb-wpcodetool'),
            'description' => sprintf(__('Efface les SMS journalisés depuis plus de %d jours.', 'smbb-wpcodetool'), self::days()),
            'schedule' => 'daily',
            'callback' => array(__CLASS__, 'purge'),
        ));
    }

    /**
     * Note un envoi (appelé par Sms::send).
     */
    public static function record($status, $to, $body, $provider, $provider_id, $error, array $context)
    {
        if (!self::enabled()) {
            return 0;
        }

        global $wpdb;
        $source = isset($context['source']) ? (string) $context['source'] : (isset($context['plugin']) ? (string) $context['plugin'] : '');

        $wpdb->insert(self::table(), array(
            'created_at' => current_time('mysql', true),
            'status' => $status === 'failed' ? 'failed' : 'sent',
            'source' => substr($source, 0, 20),
            'to_number' => substr((string) $to, 0, 40),
            'body' => (string) $body,
            'provider' => (string) $provider,
            'provider_id' => substr((string) $provider_id, 0, 80),
            'error' => (string) $error,
            'context' => (string) wp_json_encode($context),
            'resent_from' => isset($context['resent_from']) ? (int) $context['resent_from'] : 0,
        ));

        return (int) $wpdb->insert_id;
    }

    public static function purge()
    {
        if (!self::ready()) {
            return 0;
        }

        global $wpdb;
        $limit = gmdate('Y-m-d H:i:s', time() - self::days() * DAY_IN_SECONDS);

        return (int) $wpdb->query($wpdb->prepare('DELETE FROM ' . self::table() . ' WHERE created_at < %s', $limit)); // phpcs:ignore
    }

    /**
     * Compteurs depuis N jours, même forme que MailLog::stats().
     */
    public static function stats($days = 7)
    {
        return MailLog::statsFrom(self::ready() ? self::table() : '', $days);
    }

    /**
     * @param string $status '' | sent | failed | lost (accepté mais non remis par l'opérateur)
     */
    public static function query($status = '', $search = '', $page = 1, $per_page = 50)
    {
        if (!self::ready()) {
            return array('rows' => array(), 'total' => 0);
        }

        global $wpdb;
        $where = array('1=1');
        $args = array();
        $table = self::table();
        $costs = SmsCosts::ready() ? SmsCosts::table() : '';

        if (in_array($status, array('sent', 'failed'), true)) {
            $where[] = 'l.status = %s';
            $args[] = $status;
        } elseif ($status === 'lost' && $costs !== '') {
            $where[] = "c.status IN ('undelivered','failed','canceled')";
        }

        if ($search !== '') {
            $like = '%' . $wpdb->esc_like($search) . '%';
            $where[] = '(l.to_number LIKE %s OR l.body LIKE %s OR l.error LIKE %s OR l.source LIKE %s)';
            array_push($args, $like, $like, $like, $like);
        }

        // Statut de remise et coût depuis le registre (gardé à vie), s'il existe.
        $join = $costs !== '' ? " LEFT JOIN {$costs} c ON c.provider = l.provider AND c.provider_id = l.provider_id AND l.provider_id <> ''" : '';
        $fields = $costs !== ''
            ? 'l.*, c.status AS delivery, c.error_code AS delivery_code, c.price, c.currency, c.segments'
            : "l.*, '' AS delivery, '' AS delivery_code, NULL AS price, '' AS currency, 0 AS segments";
        $sql_where = implode(' AND ', $where);
        $count_sql = "SELECT COUNT(*) FROM {$table} l{$join} WHERE {$sql_where}";
        $total = (int) $wpdb->get_var($args ? $wpdb->prepare($count_sql, $args) : $count_sql); // phpcs:ignore
        $offset = max(0, ($page - 1) * $per_page);
        $rows = $wpdb->get_results($wpdb->prepare("SELECT {$fields} FROM {$table} l{$join} WHERE {$sql_where} ORDER BY l.id DESC LIMIT %d OFFSET %d", array_merge($args, array($per_page, $offset))), ARRAY_A); // phpcs:ignore

        return array('rows' => (array) $rows, 'total' => $total);
    }

    /**
     * Nombre de SMS du journal acceptés mais non remis (filtre « Non remis »).
     */
    public static function lostCount()
    {
        if (!self::ready() || !SmsCosts::ready()) {
            return 0;
        }

        global $wpdb;

        return (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . self::table() . ' l INNER JOIN ' . SmsCosts::table() . " c ON c.provider = l.provider AND c.provider_id = l.provider_id AND l.provider_id <> '' WHERE c.status IN ('undelivered','failed','canceled')"); // phpcs:ignore
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

        return (int) $wpdb->query('DELETE FROM ' . self::table() . ' WHERE id IN (' . implode(',', $ids) . ')'); // phpcs:ignore -- entiers seulement.
    }

    /**
     * Renvoie des SMS du journal (même numéro, même texte) par le connecteur actuel.
     *
     * @return array{sent:int,failed:int,skipped:int}
     */
    public static function resend(array $ids)
    {
        global $wpdb;
        $result = array('sent' => 0, 'failed' => 0, 'skipped' => 0);

        foreach (array_filter(array_map('absint', $ids)) as $id) {
            $row = self::get($id);

            if (!$row || $row['to_number'] === '' || $row['body'] === '') {
                $result['skipped']++;
                continue;
            }

            $context = json_decode((string) $row['context'], true);
            $context = is_array($context) ? $context : array();
            $context['source'] = 'resend';
            $context['resent_from'] = (int) $row['id'];

            $sent = Sms::send($row['to_number'], $row['body'], $context);
            $wpdb->query($wpdb->prepare('UPDATE ' . self::table() . ' SET resends = resends + 1 WHERE id = %d', $row['id'])); // phpcs:ignore
            $result[is_wp_error($sent) ? 'failed' : 'sent']++;
        }

        return $result;
    }
}
