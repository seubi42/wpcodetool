<?php

namespace Smbb\SignConnect\Repository;

defined('ABSPATH') || exit;

final class StampRepository
{
    public function allActive()
    {
        global $wpdb;

        $table = $wpdb->prefix . 'signstamp';

        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) {
            return array();
        }

        $rows = $wpdb->get_results(
            "SELECT *
             FROM {$table}
             WHERE deleted_flag = 0
               AND is_active = 1
             ORDER BY name ASC, id ASC",
            ARRAY_A
        );

        return is_array($rows) ? $rows : array();
    }

    public function find($stamp_id)
    {
        global $wpdb;

        $table = $wpdb->prefix . 'signstamp';

        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) {
            return null;
        }

        $sql = $wpdb->prepare(
            "SELECT *
             FROM {$table}
             WHERE id = %d
               AND deleted_flag = 0
               AND is_active = 1
             LIMIT 1",
            (int) $stamp_id
        );
        $row = $wpdb->get_row($sql, ARRAY_A);

        return is_array($row) ? $row : null;
    }
}
