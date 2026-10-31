<?php

namespace Smbb\SignConnect\Repository;

defined('ABSPATH') || exit;

final class SignTemplateRepository
{
    public function listActive($limit = 100)
    {
        global $wpdb;

        $table = $wpdb->prefix . 'signtemplate';

        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) {
            return array();
        }

        $limit = max(1, min(500, (int) $limit));
        $sql = $wpdb->prepare(
            "SELECT *
             FROM {$table}
             WHERE deleted_flag = 0
               AND is_active = 1
             ORDER BY id ASC
             LIMIT %d",
            $limit
        );
        $rows = $wpdb->get_results($sql, ARRAY_A);

        return is_array($rows) ? $rows : array();
    }
}
