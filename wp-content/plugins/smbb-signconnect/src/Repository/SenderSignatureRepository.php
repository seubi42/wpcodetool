<?php

namespace Smbb\SignConnect\Repository;

defined('ABSPATH') || exit;

final class SenderSignatureRepository
{
    public function findForDocument($document_id, $user_id)
    {
        global $wpdb;

        $table = $wpdb->prefix . 'signusersignature';

        if (!$this->tableExists($table)) {
            return null;
        }

        $sql = $wpdb->prepare(
            "SELECT *
             FROM {$table}
             WHERE document_id = %d
               AND user_id = %d
               AND deleted_flag = 0
             ORDER BY id DESC
             LIMIT 1",
            (int) $document_id,
            (int) $user_id
        );
        $row = $wpdb->get_row($sql, ARRAY_A);

        return is_array($row) ? $row : null;
    }

    public function findReusableForUser($user_id)
    {
        global $wpdb;

        $table = $wpdb->prefix . 'signusersignature';

        if (!$this->tableExists($table)) {
            return null;
        }

        $sql = $wpdb->prepare(
            "SELECT *
             FROM {$table}
             WHERE user_id = %d
               AND is_reusable = 1
               AND deleted_flag = 0
             ORDER BY lastupdate_date DESC, id DESC
             LIMIT 1",
            (int) $user_id
        );
        $row = $wpdb->get_row($sql, ARRAY_A);

        return is_array($row) ? $row : null;
    }

    public function saveForDocument($document_id, $user_id, $signature_data, $remember)
    {
        if (!$this->isSignatureDataUrl($signature_data)) {
            return false;
        }

        $document_row = $this->saveRow($user_id, $document_id, $signature_data, $remember ? 1 : 0);

        if (!$document_row) {
            return false;
        }

        if ($remember) {
            $this->saveRow($user_id, null, $signature_data, 1);
        }

        return true;
    }

    private function saveRow($user_id, $document_id, $signature_data, $is_reusable)
    {
        global $wpdb;

        $table = $wpdb->prefix . 'signusersignature';

        if (!$this->tableExists($table)) {
            return false;
        }

        $now = current_time('mysql');
        $data = array(
            'user_id' => (int) $user_id,
            'document_id' => $document_id !== null ? (int) $document_id : null,
            'signature_data' => (string) $signature_data,
            'is_reusable' => !empty($is_reusable) ? 1 : 0,
            'creation_date' => $now,
            'creation_by' => (int) $user_id,
            'lastupdate_date' => $now,
            'lastupdate_by' => (int) $user_id,
        );

        return $wpdb->insert(
            $table,
            $data,
            array('%d', '%d', '%s', '%d', '%s', '%d', '%s', '%d')
        ) !== false;
    }

    private function isSignatureDataUrl($signature)
    {
        return is_string($signature)
            && preg_match('/^data:image\/png;base64,[a-zA-Z0-9+\/=]+$/', $signature) === 1
            && strlen($signature) > 200;
    }

    private function tableExists($table)
    {
        global $wpdb;

        return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table;
    }
}
