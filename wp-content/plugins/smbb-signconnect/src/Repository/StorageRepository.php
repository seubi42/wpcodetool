<?php

namespace Smbb\SignConnect\Repository;

defined('ABSPATH') || exit;

final class StorageRepository
{
    public function allActive()
    {
        global $wpdb;

        $table = $wpdb->prefix . 'signstorage';
        $rows = $wpdb->get_results(
            "SELECT * FROM {$table} WHERE is_active = 1 AND deleted_flag = 0 ORDER BY name ASC",
            ARRAY_A
        );

        return is_array($rows) ? $rows : array();
    }

    public function find($storage_id)
    {
        global $wpdb;

        $table = $wpdb->prefix . 'signstorage';
        $sql = $wpdb->prepare(
            "SELECT * FROM {$table} WHERE id = %d AND deleted_flag = 0 LIMIT 1",
            (int) $storage_id
        );
        $row = $wpdb->get_row($sql, ARRAY_A);

        return is_array($row) ? $row : null;
    }
}
