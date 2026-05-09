<?php

namespace Smbb\SignConnect\Repository;

defined('ABSPATH') || exit;

final class DocumentRepository
{
    private static $has_system_log_column = null;

    public function find($document_id)
    {
        global $wpdb;

        $table = $wpdb->prefix . 'signdocument';
        $sql = $wpdb->prepare(
            "SELECT * FROM {$table} WHERE id = %d AND deleted_flag = 0 LIMIT 1",
            (int) $document_id
        );
        $row = $wpdb->get_row($sql, ARRAY_A);

        return is_array($row) ? $row : null;
    }

    public function findOwnedByUser($document_id, $user_id)
    {
        global $wpdb;

        $table = $wpdb->prefix . 'signdocument';
        $sql = $wpdb->prepare(
            "SELECT * FROM {$table} WHERE id = %d AND creation_by = %d AND deleted_flag = 0 LIMIT 1",
            (int) $document_id,
            (int) $user_id
        );
        $row = $wpdb->get_row($sql, ARRAY_A);

        return is_array($row) ? $row : null;
    }

    public function listOwnedByUser($user_id, $limit = 50)
    {
        global $wpdb;

        $table = $wpdb->prefix . 'signdocument';
        $limit = max(1, min(200, (int) $limit));
        $sql = $wpdb->prepare(
            "SELECT *
             FROM {$table}
             WHERE creation_by = %d
               AND deleted_flag = 0
             ORDER BY creation_date DESC, id DESC
             LIMIT %d",
            (int) $user_id,
            $limit
        );
        $rows = $wpdb->get_results($sql, ARRAY_A);

        return is_array($rows) ? $rows : array();
    }

    public function findByPublicToken($document_id, $token)
    {
        global $wpdb;

        $table = $wpdb->prefix . 'signdocument';
        $sql = $wpdb->prepare(
            "SELECT * FROM {$table} WHERE id = %d AND token = %s AND deleted_flag = 0 LIMIT 1",
            (int) $document_id,
            (string) $token
        );
        $row = $wpdb->get_row($sql, ARRAY_A);

        return is_array($row) ? $row : null;
    }

    public function createUploadedDocument(array $data)
    {
        global $wpdb;

        /*
         * Cette methode créé le pointeur metier vers le fichier S3.
         *
         * Le PDF lui-meme n\'est jamais stocke dans la base : on garde seulement
         * le stockage, le chemin S3, le nom original et quelques metadonnees
         * utiles au dashboard ou aux futurs traitements de signature.
         */
        $table = $wpdb->prefix . 'signdocument';
        $now = current_time('mysql');
        $user_id = get_current_user_id();

        $insert_data = array(
            'filename' => isset($data['filename']) ? (string) $data['filename'] : '',
            'storage_id' => isset($data['storage_id']) ? (int) $data['storage_id'] : null,
            'storage_path' => isset($data['storage_path']) ? (string) $data['storage_path'] : '',
            'file_size' => isset($data['file_size']) ? max(0, (int) $data['file_size']) : 0,
            'document_status' => 'draft',
            'creation_date' => $now,
            'creation_by' => $user_id,
            'token' => isset($data['token']) ? (string) $data['token'] : '',
        );

        $result = $wpdb->insert(
            $table,
            $insert_data,
            array('%s', '%d', '%s', '%d', '%s', '%s', '%d', '%s')
        );

        if ($result === false) {
            return false;
        }

        return isset($wpdb->insert_id) ? (int) $wpdb->insert_id : true;
    }

    public function findExpiredUnsigned($limit = 100)
    {
        global $wpdb;

        /*
         * La purge automatique ne touche pas aux documents déjà signes.
         * L'expiration concerne le lien de signature, pas la conservation du
         * document final.
         */
        $table = $wpdb->prefix . 'signdocument';
        $limit = max(1, min(500, (int) $limit));
        $now = current_time('mysql');
        $sql = $wpdb->prepare(
            "SELECT * FROM {$table}
             WHERE deleted_flag = 0
               AND link_expires_at IS NOT NULL
               AND link_expires_at <> ''
               AND link_expires_at <> '0000-00-00 00:00:00'
               AND link_expires_at <= %s
               AND (sign_date IS NULL OR sign_date = '' OR sign_date = '0000-00-00 00:00:00')
               AND (document_status IS NULL OR document_status <> 'signed')
             ORDER BY link_expires_at ASC
             LIMIT %d",
            $now,
            $limit
        );
        $rows = $wpdb->get_results($sql, ARRAY_A);

        return is_array($rows) ? $rows : array();
    }

    public function markDeletedByCron($document_id)
    {
        global $wpdb;

        $table = $wpdb->prefix . 'signdocument';
        $result = $wpdb->update(
            $table,
            array(
                'document_status' => 'expired_deleted',
                'deleted_flag' => 1,
                'deleted_date' => current_time('mysql'),
                'deleted_by' => 0,
            ),
            array(
                'id' => (int) $document_id,
            ),
            array('%s', '%d', '%s', '%d'),
            array('%d')
        );

        return $result !== false;
    }

    public function saveSendSettings($document_id, $user_id, array $data)
    {
        global $wpdb;

        $table = $wpdb->prefix . 'signdocument';
        $document = $this->findOwnedByUser($document_id, $user_id);

        if (!$document) {
            return false;
        }

        $signature_token = !empty($document['signature_token'])
            ? (string) $document['signature_token']
            : wp_generate_password(48, false, false);

        /*
         * On conserve le token s'il existe déjà.
         * C'est important pour que l'utilisateur puisse revenir modifier le
         * formulaire d\'envoi sans invalider un lien de signature déjà genere.
         */
        $update_data = array(
            'document_status' => 'ready_to_send',
            'send_channel' => isset($data['send_channel']) ? (string) $data['send_channel'] : 'email',
            'recipient_email' => isset($data['recipient_email']) ? (string) $data['recipient_email'] : '',
            'recipient_phone' => isset($data['recipient_phone']) ? (string) $data['recipient_phone'] : '',
            'send_message' => isset($data['send_message']) ? (string) $data['send_message'] : '',
            'require_identity_photo' => !empty($data['require_identity_photo']) ? 1 : 0,
            'return_expected' => isset($data['return_expected']) ? (string) $data['return_expected'] : 'signature',
            'link_expires_at' => isset($data['link_expires_at']) ? (string) $data['link_expires_at'] : null,
            'signature_token' => $signature_token,
        );

        $result = $wpdb->update(
            $table,
            $update_data,
            array(
                'id' => (int) $document_id,
                'creation_by' => (int) $user_id,
            ),
            array('%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s'),
            array('%d', '%d')
        );

        if ($result === false) {
            return false;
        }

        return $this->findOwnedByUser($document_id, $user_id);
    }

    public function saveAiSuggestedMessage($document_id, $user_id, $message)
    {
        global $wpdb;

        $message = trim((string) $message);

        if ($message === '') {
            return false;
        }

        $table = $wpdb->prefix . 'signdocument';
        $result = $wpdb->update(
            $table,
            array(
                'send_message' => $message,
            ),
            array(
                'id' => (int) $document_id,
                'creation_by' => (int) $user_id,
                'send_message' => '',
            ),
            array('%s'),
            array('%d', '%d', '%s')
        );

        if ($result === false || $result === 0) {
            $sql = $wpdb->prepare(
                "UPDATE {$table}
                 SET send_message = %s
                 WHERE id = %d
                   AND creation_by = %d
                   AND (send_message IS NULL OR send_message = '')",
                $message,
                (int) $document_id,
                (int) $user_id
            );

            $result = $wpdb->query($sql);
        }

        return $result !== false;
    }

    public function appendSystemLog($document_id, $title, array $context = array())
    {
        global $wpdb;

        if (!$this->hasSystemLogColumn()) {
            return false;
        }

        $table = $wpdb->prefix . 'signdocument';
        $entry = array(
            'date' => current_time('mysql'),
            'title' => (string) $title,
            'context' => $context,
        );
        $line = wp_json_encode($entry, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if (!is_string($line) || $line === '') {
            return false;
        }

        $sql = $wpdb->prepare(
            "UPDATE {$table}
             SET system_log = CONCAT(COALESCE(system_log, ''), %s)
             WHERE id = %d",
            "\n\n" . $line,
            (int) $document_id
        );

        return $wpdb->query($sql) !== false;
    }

    private function hasSystemLogColumn()
    {
        global $wpdb;

        if (self::$has_system_log_column !== null) {
            return self::$has_system_log_column;
        }

        $table = $wpdb->prefix . 'signdocument';
        $column = $wpdb->get_var("SHOW COLUMNS FROM {$table} LIKE 'system_log'");
        self::$has_system_log_column = !empty($column);

        return self::$has_system_log_column;
    }

    public function markSent($document_id, $user_id)
    {
        global $wpdb;

        $table = $wpdb->prefix . 'signdocument';
        $result = $wpdb->update(
            $table,
            array(
                'document_status' => 'sent',
                'sent_date' => current_time('mysql'),
                'sent_by' => (int) $user_id,
            ),
            array(
                'id' => (int) $document_id,
                'creation_by' => (int) $user_id,
            ),
            array('%s', '%s', '%d'),
            array('%d', '%d')
        );

        return $result !== false ? $this->findOwnedByUser($document_id, $user_id) : false;
    }

    public function markSigned($document_id, $token, array $data)
    {
        global $wpdb;

        $table = $wpdb->prefix . 'signdocument';
        $result = $wpdb->update(
            $table,
            array(
                'document_status' => 'signed',
                'sign_date' => isset($data['sign_date']) ? (string) $data['sign_date'] : current_time('mysql'),
                'signer_contact' => isset($data['signer_contact']) ? (string) $data['signer_contact'] : '',
                'signer_first_name' => isset($data['signer_first_name']) ? (string) $data['signer_first_name'] : '',
                'signer_last_name' => isset($data['signer_last_name']) ? (string) $data['signer_last_name'] : '',
                'signer_place' => isset($data['signer_place']) ? (string) $data['signer_place'] : '',
                'signer_return_status' => isset($data['signer_return_status']) ? (string) $data['signer_return_status'] : '',
                'signer_return_message' => isset($data['signer_return_message']) ? (string) $data['signer_return_message'] : '',
                'signer_latitude' => isset($data['signer_latitude']) && $data['signer_latitude'] !== '' ? (string) $data['signer_latitude'] : null,
                'signer_longitude' => isset($data['signer_longitude']) && $data['signer_longitude'] !== '' ? (string) $data['signer_longitude'] : null,
                'signature_data' => isset($data['signature_data']) ? (string) $data['signature_data'] : '',
                'signer_ip' => isset($data['signer_ip']) ? (string) $data['signer_ip'] : '',
                'signer_user_agent' => isset($data['signer_user_agent']) ? (string) $data['signer_user_agent'] : '',
                'signed_storage_path' => isset($data['signed_storage_path']) ? (string) $data['signed_storage_path'] : '',
                'signed_file_size' => isset($data['signed_file_size']) ? max(0, (int) $data['signed_file_size']) : 0,
                'signed_pdf_date' => isset($data['signed_pdf_date']) ? (string) $data['signed_pdf_date'] : current_time('mysql'),
                'identity_photo_storage_path' => isset($data['identity_photo_storage_path']) ? (string) $data['identity_photo_storage_path'] : '',
                'identity_photo_file_size' => isset($data['identity_photo_file_size']) ? max(0, (int) $data['identity_photo_file_size']) : 0,
                'identity_photo_mime_type' => isset($data['identity_photo_mime_type']) ? (string) $data['identity_photo_mime_type'] : '',
                'identity_photo_filename' => isset($data['identity_photo_filename']) ? (string) $data['identity_photo_filename'] : '',
            ),
            array(
                'id' => (int) $document_id,
                'token' => (string) $token,
            ),
            array('%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%d', '%s', '%s'),
            array('%d', '%s')
        );

        return $result !== false ? $this->findByPublicToken($document_id, $token) : false;
    }
}
