<?php

namespace Smbb\SignConnect\Repository;

defined('ABSPATH') || exit;

final class DocumentAuditRepository
{
    private static $table_exists = null;
    private static $column_cache = array();

    public function record($document_id, $event_type, array $context = array(), $actor_type = 'system', $actor_id = null, $message = '')
    {
        global $wpdb;

        $document_id = (int) $document_id;
        $event_type = sanitize_key((string) $event_type);

        if ($document_id < 1 || $event_type === '' || !$this->tableExists()) {
            return false;
        }

        $payload = wp_json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $data = array(
            'document_id' => $document_id,
            'event_type' => $event_type,
            'event_date' => current_time('mysql'),
            'actor_id' => $actor_id !== null ? (int) $actor_id : (is_user_logged_in() ? get_current_user_id() : 0),
            'actor_ip' => $this->currentIp(),
            'actor_user_agent' => $this->currentUserAgent(),
            'context' => is_string($payload) ? $payload : '',
        );
        $formats = array('%d', '%s', '%s', '%d', '%s', '%s', '%s');

        if ($this->hasColumn('actor_type')) {
            $data['actor_type'] = $this->sanitizeActorType($actor_type);
            $formats[] = '%s';
        }

        if ($this->hasColumn('message')) {
            $data['message'] = $message !== '' ? sanitize_text_field((string) $message) : '';
            $formats[] = '%s';
        }

        $result = $wpdb->insert($wpdb->prefix . 'signauditlog', $data, $formats);

        return $result !== false;
    }

    public function listForDocument($document_id, $limit = 50)
    {
        global $wpdb;

        if (!$this->tableExists()) {
            return array();
        }

        $limit = max(1, min(200, (int) $limit));
        $table = $wpdb->prefix . 'signauditlog';
        $sql = $wpdb->prepare(
            "SELECT * FROM {$table} WHERE document_id = %d ORDER BY event_date DESC, id DESC LIMIT %d",
            (int) $document_id,
            $limit
        );
        $rows = $wpdb->get_results($sql, ARRAY_A);

        return is_array($rows) ? $rows : array();
    }

    private function tableExists()
    {
        global $wpdb;

        if (self::$table_exists !== null) {
            return self::$table_exists;
        }

        $table = $wpdb->prefix . 'signauditlog';
        self::$table_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table;

        return self::$table_exists;
    }

    private function sanitizeActorType($actor_type)
    {
        $actor_type = sanitize_key((string) $actor_type);

        return in_array($actor_type, array('owner', 'signer', 'system', 'admin'), true) ? $actor_type : 'system';
    }

    private function currentIp()
    {
        return isset($_SERVER['REMOTE_ADDR']) ? substr(sanitize_text_field((string) wp_unslash($_SERVER['REMOTE_ADDR'])), 0, 60) : '';
    }

    private function currentUserAgent()
    {
        return isset($_SERVER['HTTP_USER_AGENT']) ? substr(sanitize_text_field((string) wp_unslash($_SERVER['HTTP_USER_AGENT'])), 0, 255) : '';
    }

    private function hasColumn($column)
    {
        global $wpdb;

        $column = (string) $column;

        if (array_key_exists($column, self::$column_cache)) {
            return self::$column_cache[$column];
        }

        $table = $wpdb->prefix . 'signauditlog';
        $found = $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM {$table} LIKE %s", $column));
        self::$column_cache[$column] = !empty($found);

        return self::$column_cache[$column];
    }
}
