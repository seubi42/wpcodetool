<?php

namespace Smbb\SignConnect\Repository;

defined('ABSPATH') || exit;

final class SignatureFieldRepository
{
    public function findForDocument($document_id)
    {
        $fields = $this->listForDocument($document_id);

        return $fields ? reset($fields) : null;
    }

    public function listForDocument($document_id)
    {
        global $wpdb;

        $table = $wpdb->prefix . 'signdocumentfield';
        $sql = $wpdb->prepare(
            "SELECT * FROM {$table} WHERE document_id = %d AND deleted_flag = 0 ORDER BY page_number ASC, id ASC",
            (int) $document_id
        );
        $rows = $wpdb->get_results($sql, ARRAY_A);

        return is_array($rows) ? $rows : array();
    }

    public function saveForDocument($document_id, array $fields)
    {
        global $wpdb;

        $table = $wpdb->prefix . 'signdocumentfield';
        $existing = $this->listForDocument($document_id);
        $now = current_time('mysql');
        $user_id = get_current_user_id();
        $kept_ids = array();

        foreach ($fields as $field) {
            if (!is_array($field)) {
                continue;
            }

            $field_id = !empty($field['id']) ? (int) $field['id'] : 0;
            $data = array(
                'document_id' => (int) $document_id,
                'page_number' => max(1, (int) $field['page_number']),
                'x' => $this->ratio($field['x']),
                'y' => $this->ratio($field['y']),
                'width' => $this->ratio($field['width']),
                'height' => $this->ratio($field['height']),
                'unit' => 'page_ratio',
                'label' => isset($field['label']) ? sanitize_text_field((string) $field['label']) : __('Signature', 'smbb-signconnect'),
                'lastupdate_date' => $now,
                'lastupdate_by' => $user_id,
            );
            $formats = array('%d', '%d', '%f', '%f', '%f', '%f', '%s', '%s', '%s', '%d');

            if ($field_id > 0 && $this->documentOwnsField($existing, $field_id)) {
                $result = $wpdb->update($table, $data, array('id' => $field_id), $formats, array('%d'));

                if ($result === false) {
                    return false;
                }

                $kept_ids[] = $field_id;
                continue;
            }

            $data['creation_date'] = $now;
            $data['creation_by'] = $user_id;
            $insert_formats = $formats;
            $insert_formats[] = '%s';
            $insert_formats[] = '%d';
            $result = $wpdb->insert($table, $data, $insert_formats);

            if ($result === false) {
                return false;
            }

            $kept_ids[] = isset($wpdb->insert_id) ? (int) $wpdb->insert_id : 0;
        }

        foreach ($existing as $existing_field) {
            $existing_id = (int) $existing_field['id'];

            if (in_array($existing_id, $kept_ids, true)) {
                continue;
            }

            $wpdb->update(
                $table,
                array(
                    'deleted_flag' => 1,
                    'deleted_date' => $now,
                    'deleted_by' => $user_id,
                ),
                array('id' => $existing_id),
                array('%d', '%s', '%d'),
                array('%d')
            );
        }

        return $kept_ids;
    }

    private function documentOwnsField(array $fields, $field_id)
    {
        foreach ($fields as $field) {
            if ((int) $field['id'] === (int) $field_id) {
                return true;
            }
        }

        return false;
    }

    private function ratio($value)
    {
        $value = (float) $value;

        if ($value < 0) {
            return 0;
        }

        if ($value > 1) {
            return 1;
        }

        return $value;
    }
}
