<?php

namespace Smbb\SignConnectProxima\Repository;

defined('ABSPATH') || exit;

/**
 * Accès aux documents et à leurs champs. Les écritures passent ici (et pas par CodeTool),
 * car un document est créé par l'app, pas par un formulaire d'admin.
 */
final class Documents
{
    public static function find($id)
    {
        global $wpdb;

        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . Lookup::table('scp_document') . ' WHERE id = %d AND deleted_flag = 0', (int) $id), ARRAY_A);

        if ($row) {
            $channels = json_decode((string) $row['delivery_channels'], true);
            $row['delivery_channels'] = is_array($channels) ? $channels : array();
        }

        return $row ?: null;
    }

    /**
     * Nom complet du destinataire (anciens envois : prénom + nom).
     */
    public static function recipientName(array $document)
    {
        $name = isset($document['recipient_name']) ? trim((string) $document['recipient_name']) : '';

        if ($name !== '') {
            return $name;
        }

        return trim((isset($document['recipient_first_name']) ? $document['recipient_first_name'] : '') . ' ' . (isset($document['recipient_last_name']) ? $document['recipient_last_name'] : ''));
    }

    /**
     * Nom du PDF remis à l'utilisateur (téléchargement, pièce jointe). Un document renommé par la
     * stratégie de nommage de son modèle garde exactement ce nom, signé compris ; sinon suffixe -signe / -preuve.
     *
     * @param string $what source, signed ou evidence
     */
    public static function downloadName(array $document, $what = 'source')
    {
        $base = preg_replace('/\.pdf$/i', '', (string) $document['filename']);
        $renamed = !empty($document['source_filename']);

        if ($what === 'evidence') {
            return $base . ($renamed ? ' - preuve' : '-preuve') . '.pdf';
        }

        return $base . ($what === 'signed' && !$renamed ? '-signe' : '') . '.pdf';
    }

    public static function insert(array $data)
    {
        global $wpdb;

        $now = current_time('mysql');
        $data = array_merge(array(
            'creation_date' => $now,
            'creation_by' => get_current_user_id() ?: null,
            'lastupdate_date' => $now,
            'lastupdate_by' => get_current_user_id() ?: null,
        ), self::encode($data));

        if ($wpdb->insert(Lookup::table('scp_document'), $data) === false) {
            throw new \RuntimeException(__("Le document n'a pas pu être enregistré.", 'smbb-signaconnect-proxima'));
        }

        return (int) $wpdb->insert_id;
    }

    public static function update($id, array $data)
    {
        global $wpdb;

        $data = self::encode($data);
        $data['lastupdate_date'] = current_time('mysql');

        if (get_current_user_id()) {
            $data['lastupdate_by'] = get_current_user_id();
        }

        if ($wpdb->update(Lookup::table('scp_document'), $data, array('id' => (int) $id)) === false) {
            throw new \RuntimeException(sprintf(__('Erreur base de données : %s', 'smbb-signaconnect-proxima'), $wpdb->last_error ?: '?'));
        }

        return true;
    }

    /**
     * Passage de statut atomique : évite qu'un double clic signe deux fois.
     */
    public static function transition($id, array $from_statuses, array $data)
    {
        global $wpdb;

        $data = self::encode($data);
        $data['lastupdate_date'] = current_time('mysql');
        $sets = array();
        $values = array();

        foreach ($data as $column => $value) {
            if ($value === null) {
                $sets[] = '`' . $column . '` = NULL';
                continue;
            }

            $sets[] = '`' . $column . '` = %s';
            $values[] = $value;
        }

        $placeholders = implode(',', array_fill(0, count($from_statuses), '%s'));
        $values[] = (int) $id;
        $values = array_merge($values, $from_statuses);
        $sql = 'UPDATE ' . Lookup::table('scp_document') . ' SET ' . implode(', ', $sets) . ' WHERE id = %d AND deleted_flag = 0 AND status IN (' . $placeholders . ')';

        $result = $wpdb->query($wpdb->prepare($sql, $values));

        if ($result === false) {
            // Erreur SQL (souvent une colonne absente : schéma à synchroniser dans CodeTool).
            throw new \RuntimeException(sprintf(__('Erreur base de données : %s', 'smbb-signaconnect-proxima'), $wpdb->last_error ?: '?'));
        }

        return (int) $result === 1;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public static function fields($document_id)
    {
        global $wpdb;

        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM ' . Lookup::table('scp_document_field') . ' WHERE document_id = %d AND deleted_flag = 0 ORDER BY page_number ASC, y ASC, id ASC',
            (int) $document_id
        ), ARRAY_A);

        return is_array($rows) ? $rows : array();
    }

    /**
     * Remplace tous les champs d'un document.
     *
     * @param array<int,array<string,mixed>> $fields
     */
    public static function replaceFields($document_id, array $fields)
    {
        global $wpdb;

        $table = Lookup::table('scp_document_field');
        $wpdb->delete($table, array('document_id' => (int) $document_id));
        $now = current_time('mysql');

        foreach ($fields as $field) {
            $wpdb->insert($table, array(
                'document_id' => (int) $document_id,
                'page_number' => (int) $field['page_number'],
                'x' => (string) $field['x'],
                'y' => (string) $field['y'],
                'width' => (string) $field['width'],
                'height' => (string) $field['height'],
                'field_type' => (string) $field['field_type'],
                'label' => isset($field['label']) ? (string) $field['label'] : null,
                'creation_date' => $now,
                'creation_by' => get_current_user_id() ?: null,
            ));
        }
    }

    /**
     * Derniers documents visibles pour un utilisateur.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function listForSender($user_id, $limit = 50)
    {
        global $wpdb;

        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM ' . Lookup::table('scp_document') . " WHERE sender_id = %d AND deleted_flag = 0 AND status <> 'draft' ORDER BY id DESC LIMIT %d",
            (int) $user_id,
            (int) $limit
        ), ARRAY_A);

        return is_array($rows) ? $rows : array();
    }

    /**
     * Historique filtrable : ses propres envois, plus le périmètre d'un superviseur.
     *
     * @param array<string,mixed> $scope Memberships::scope()
     * @param array<string,mixed> $filters who (me|all|user id), team_id, status, level, month
     * @return array<int,array<string,mixed>>
     */
    public static function listScoped($user_id, array $scope, array $filters, $limit = 200)
    {
        global $wpdb;

        $visible = array($wpdb->prepare('sender_id = %d', (int) $user_id));

        if ($scope['org_ids']) {
            $visible[] = 'organization_id IN (' . implode(',', array_map('intval', $scope['org_ids'])) . ')';
        }

        if ($scope['team_ids']) {
            $visible[] = 'team_id IN (' . implode(',', array_map('intval', $scope['team_ids'])) . ')';
        }

        $where = array('deleted_flag = 0', "status <> 'draft'", '(' . implode(' OR ', $visible) . ')');
        $who = isset($filters['who']) ? (string) $filters['who'] : 'me';

        if ($who === 'me') {
            $where[] = $wpdb->prepare('sender_id = %d', (int) $user_id);
        } elseif (ctype_digit($who)) {
            $where[] = $wpdb->prepare('sender_id = %d', (int) $who);
        }

        if (!empty($filters['team_id'])) {
            $where[] = $wpdb->prepare('team_id = %d', (int) $filters['team_id']);
        }

        if (!empty($filters['status'])) {
            $where[] = $wpdb->prepare('status = %s', (string) $filters['status']);
        }

        if (!empty($filters['level'])) {
            $where[] = $wpdb->prepare('signature_level = %s', (string) $filters['level']);
        }

        if (!empty($filters['month'])) {
            $where[] = $wpdb->prepare('sent_at >= %s AND sent_at < %s', $filters['month'] . '-01 00:00:00', gmdate('Y-m-01 00:00:00', strtotime($filters['month'] . '-01 00:00:00 UTC +1 month')));
        }

        $rows = $wpdb->get_results('SELECT * FROM ' . Lookup::table('scp_document') . ' WHERE ' . implode(' AND ', $where) . ' ORDER BY id DESC LIMIT ' . (int) $limit, ARRAY_A);

        return is_array($rows) ? $rows : array();
    }

    private static function encode(array $data)
    {
        if (isset($data['delivery_channels']) && is_array($data['delivery_channels'])) {
            $data['delivery_channels'] = wp_json_encode(array_values($data['delivery_channels']));
        }

        return $data;
    }
}
