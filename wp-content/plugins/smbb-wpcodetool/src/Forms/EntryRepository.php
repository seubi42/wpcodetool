<?php

namespace Smbb\WpCodeTool\Forms;

defined('ABSPATH') || exit;

/**
 * Entrées des formulaires (table smbb_form_entries).
 *
 * Chaque entrée garde une photo des champs au moment de l'envoi : clé, libellé, type, valeur.
 * Modifier ou retirer un champ du formulaire ensuite ne fait donc rien perdre.
 * Statuts : unread, read, spam.
 */
final class EntryRepository
{
    const STATUSES = array('unread', 'read', 'spam');

    /**
     * @param array<int,array{key:string,label:string,type:string,value:mixed}> $fields
     */
    public static function insert($form_id, array $fields, array $meta = array())
    {
        if (!Forms::ready()) {
            return 0;
        }

        global $wpdb;
        $data = array(
            'form_id' => (int) $form_id,
            'status' => isset($meta['status']) && in_array($meta['status'], self::STATUSES, true) ? $meta['status'] : 'unread',
            'starred' => !empty($meta['starred']) ? 1 : 0,
            'fields' => (string) wp_json_encode(array_values($fields), JSON_UNESCAPED_UNICODE),
            'user_id' => isset($meta['user_id']) ? (int) $meta['user_id'] : get_current_user_id(),
            'ip' => substr((string) ($meta['ip'] ?? ''), 0, 45),
            'user_agent' => substr((string) ($meta['user_agent'] ?? ''), 0, 255),
            'page_url' => substr((string) ($meta['page_url'] ?? ''), 0, 255),
            'legacy_id' => isset($meta['legacy_id']) ? (int) $meta['legacy_id'] : 0,
            'source' => substr(sanitize_key((string) ($meta['source'] ?? '')), 0, 40),
            'source_ref' => substr((string) ($meta['source_ref'] ?? ''), 0, 190),
            'created_at' => isset($meta['created_at']) ? (string) $meta['created_at'] : current_time('mysql', true),
        );

        return $wpdb->insert(Forms::entriesTable(), $data) ? (int) $wpdb->insert_id : 0; // phpcs:ignore
    }

    public static function updateFields($id, array $fields)
    {
        global $wpdb;

        return (bool) $wpdb->update(Forms::entriesTable(), array('fields' => (string) wp_json_encode(array_values($fields), JSON_UNESCAPED_UNICODE)), array('id' => (int) $id)); // phpcs:ignore
    }

    public static function find($id)
    {
        if (!Forms::ready()) {
            return null;
        }

        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . Forms::entriesTable() . ' WHERE id = %d', (int) $id), ARRAY_A); // phpcs:ignore

        return $row ? self::hydrate($row) : null;
    }

    public static function hydrate(array $row)
    {
        $fields = json_decode((string) $row['fields'], true);
        $row['id'] = (int) $row['id'];
        $row['form_id'] = (int) $row['form_id'];
        $row['starred'] = (int) $row['starred'];
        $row['user_id'] = (int) $row['user_id'];
        $row['fields'] = is_array($fields) ? $fields : array();
        $row['source'] = isset($row['source']) ? (string) $row['source'] : '';
        $row['source_ref'] = isset($row['source_ref']) ? (string) $row['source_ref'] : '';

        return $row;
    }

    /**
     * Origine d'une entrée remplie dans le flux d'un plugin : array(label, url) ou null (formulaire du site).
     * Le plugin la décrit par le filtre smbb_wpcodetool_form_entry_source.
     *
     * @return array{label:string,url:string}|null
     */
    public static function source(array $entry)
    {
        if (($entry['source'] ?? '') === '') {
            return null;
        }

        $info = apply_filters('smbb_wpcodetool_form_entry_source', null, $entry);

        if (!is_array($info) || empty($info['label'])) {
            return array('label' => (string) $entry['source'] . ($entry['source_ref'] !== '' ? ' · ' . $entry['source_ref'] : ''), 'url' => '');
        }

        return array('label' => (string) $info['label'], 'url' => isset($info['url']) ? (string) $info['url'] : '');
    }

    /**
     * Entrées remplies dans le flux d'un plugin (colonnes source / source_ref), hors indésirables.
     * $refs vide : toutes les entrées de cette origine.
     *
     * @param string[] $refs
     * @return array<int,array<string,mixed>> entrées hydratées, des plus anciennes aux plus récentes
     */
    public static function forSource($source, array $refs = array(), $limit = 5000)
    {
        if (!Forms::ready()) {
            return array();
        }

        global $wpdb;
        $where = $wpdb->prepare("source = %s AND status <> 'spam'", sanitize_key((string) $source));
        $refs = array_values(array_unique(array_filter(array_map('strval', $refs), 'strlen')));

        if ($refs) {
            $where .= ' AND source_ref IN (' . implode(',', array_fill(0, count($refs), '%s')) . ')';
            $where = $wpdb->prepare($where, $refs); // phpcs:ignore
        }

        $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . Forms::entriesTable() . ' WHERE ' . $where . ' ORDER BY id ASC LIMIT %d', (int) $limit), ARRAY_A); // phpcs:ignore

        return array_map(array(__CLASS__, 'hydrate'), (array) $rows);
    }

    /**
     * Filtres admis : form (id), status (unread|read|spam|starred|'' = hors spam), s (recherche), from, to (Y-m-d).
     */
    public static function normalizeFilters(array $raw)
    {
        $status = isset($raw['status']) ? sanitize_key((string) $raw['status']) : '';

        return array(
            'form' => isset($raw['form']) ? (string) absint($raw['form']) : '',
            'status' => in_array($status, array('unread', 'read', 'spam', 'starred'), true) ? $status : '',
            's' => isset($raw['s']) ? mb_substr(sanitize_text_field((string) $raw['s']), 0, 100) : '',
            'from' => isset($raw['from']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $raw['from']) ? (string) $raw['from'] : '',
            'to' => isset($raw['to']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $raw['to']) ? (string) $raw['to'] : '',
        );
    }

    private static function where(array $filters)
    {
        global $wpdb;
        $where = array('1=1');

        if ($filters['form'] !== '' && $filters['form'] !== '0') {
            $where[] = $wpdb->prepare('form_id = %d', (int) $filters['form']);
        }

        if ($filters['status'] === 'starred') {
            $where[] = "starred = 1 AND status <> 'spam'";
        } elseif ($filters['status'] !== '') {
            $where[] = $wpdb->prepare('status = %s', $filters['status']);
        } else {
            $where[] = "status <> 'spam'";
        }

        if ($filters['s'] !== '') {
            $like = '%' . $wpdb->esc_like($filters['s']) . '%';
            $where[] = $wpdb->prepare('(fields LIKE %s OR ip LIKE %s)', $like, $like);
        }

        if ($filters['from'] !== '') {
            $where[] = $wpdb->prepare('created_at >= %s', get_gmt_from_date($filters['from'] . ' 00:00:00'));
        }

        if ($filters['to'] !== '') {
            $where[] = $wpdb->prepare('created_at <= %s', get_gmt_from_date($filters['to'] . ' 23:59:59'));
        }

        return implode(' AND ', $where);
    }

    /**
     * @return array{rows:array[],total:int}
     */
    public static function query(array $filters, $page = 1, $per_page = 30)
    {
        if (!Forms::ready()) {
            return array('rows' => array(), 'total' => 0);
        }

        global $wpdb;
        $table = Forms::entriesTable();
        $where = self::where($filters);
        $offset = max(0, ((int) $page - 1) * (int) $per_page);
        $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE {$where}"); // phpcs:ignore
        $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$table} WHERE {$where} ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d", (int) $per_page, $offset), ARRAY_A); // phpcs:ignore

        return array('rows' => array_map(array(__CLASS__, 'hydrate'), (array) $rows), 'total' => $total);
    }

    /**
     * Toutes les entrées d'un filtre, par paquets (export CSV).
     *
     * @return \Generator<array>
     */
    public static function iterate(array $filters, $max = 50000)
    {
        global $wpdb;
        $table = Forms::entriesTable();
        $where = self::where($filters);
        $last = PHP_INT_MAX;
        $done = 0;

        while ($done < $max) {
            $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$table} WHERE {$where} AND id < %d ORDER BY id DESC LIMIT 500", $last), ARRAY_A); // phpcs:ignore

            if (!$rows) {
                return;
            }

            foreach ($rows as $row) {
                $last = (int) $row['id'];
                $done++;
                yield self::hydrate($row);
            }
        }
    }

    /**
     * Compteurs par formulaire : form_id => [total, unread, last].
     *
     * @return array<int,array{total:int,unread:int,last:string}>
     */
    public static function countsByForm()
    {
        if (!Forms::ready()) {
            return array();
        }

        global $wpdb;
        $rows = $wpdb->get_results('SELECT form_id, COUNT(*) AS total, SUM(status = \'unread\') AS unread, MAX(created_at) AS last FROM ' . Forms::entriesTable() . " WHERE status <> 'spam' GROUP BY form_id", ARRAY_A); // phpcs:ignore
        $out = array();

        foreach ((array) $rows as $row) {
            $out[(int) $row['form_id']] = array('total' => (int) $row['total'], 'unread' => (int) $row['unread'], 'last' => (string) $row['last']);
        }

        return $out;
    }

    public static function unreadCount()
    {
        if (!Forms::ready()) {
            return 0;
        }

        global $wpdb;

        return (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . Forms::entriesTable() . " WHERE status = 'unread'"); // phpcs:ignore
    }

    /**
     * Compteurs des onglets de statut pour un formulaire (ou tous).
     */
    public static function statusCounts($form_id = 0)
    {
        if (!Forms::ready()) {
            return array('all' => 0, 'unread' => 0, 'starred' => 0, 'spam' => 0);
        }

        global $wpdb;
        $where = $form_id ? $wpdb->prepare('WHERE form_id = %d', (int) $form_id) : '';
        $row = $wpdb->get_row('SELECT SUM(status <> \'spam\') AS all_count, SUM(status = \'unread\') AS unread, SUM(starred = 1 AND status <> \'spam\') AS starred, SUM(status = \'spam\') AS spam FROM ' . Forms::entriesTable() . ' ' . $where, ARRAY_A); // phpcs:ignore

        return array(
            'all' => (int) ($row['all_count'] ?? 0),
            'unread' => (int) ($row['unread'] ?? 0),
            'starred' => (int) ($row['starred'] ?? 0),
            'spam' => (int) ($row['spam'] ?? 0),
        );
    }

    public static function setStatus(array $ids, $status)
    {
        if (!$ids || !in_array($status, self::STATUSES, true)) {
            return 0;
        }

        global $wpdb;
        $ids = array_map('intval', $ids);

        return (int) $wpdb->query($wpdb->prepare('UPDATE ' . Forms::entriesTable() . ' SET status = %s WHERE id IN (' . implode(',', $ids) . ')', $status)); // phpcs:ignore
    }

    /**
     * Identifiants de champs renommés dans le builder : les entrées déjà reçues suivent.
     *
     * @param array<string,string> $map ancienne clé => nouvelle
     */
    public static function renameKeys($form_id, array $map)
    {
        if (!$map || !Forms::ready()) {
            return 0;
        }

        global $wpdb;
        $table = Forms::entriesTable();
        $last = 0;
        $done = 0;

        while (true) {
            $rows = $wpdb->get_results($wpdb->prepare("SELECT id, fields FROM {$table} WHERE form_id = %d AND id > %d ORDER BY id ASC LIMIT 500", (int) $form_id, $last), ARRAY_A); // phpcs:ignore

            if (!$rows) {
                break;
            }

            foreach ($rows as $row) {
                $last = (int) $row['id'];
                $fields = json_decode((string) $row['fields'], true);

                if (!is_array($fields)) {
                    continue;
                }

                $changed = false;

                foreach ($fields as $i => $field) {
                    $key = is_array($field) ? (string) ($field['key'] ?? '') : '';

                    if ($key !== '' && isset($map[$key])) {
                        $fields[$i]['key'] = $map[$key];
                        $changed = true;
                    }
                }

                if ($changed) {
                    $wpdb->update($table, array('fields' => (string) wp_json_encode($fields, JSON_UNESCAPED_UNICODE)), array('id' => $last)); // phpcs:ignore
                    $done++;
                }
            }
        }

        return $done;
    }

    public static function setStarred(array $ids, $starred)
    {
        if (!$ids) {
            return 0;
        }

        global $wpdb;
        $ids = array_map('intval', $ids);

        return (int) $wpdb->query($wpdb->prepare('UPDATE ' . Forms::entriesTable() . ' SET starred = %d WHERE id IN (' . implode(',', $ids) . ')', $starred ? 1 : 0)); // phpcs:ignore
    }

    /**
     * Supprime des entrées et leurs fichiers.
     */
    public static function delete(array $ids)
    {
        if (!$ids) {
            return 0;
        }

        global $wpdb;
        $ids = array_map('intval', $ids);
        // Champs des entrées : pour effacer aussi les fichiers rangés sur une unité de stockage (S3…).
        $rows = $wpdb->get_results('SELECT id, fields FROM ' . Forms::entriesTable() . ' WHERE id IN (' . implode(',', $ids) . ')', ARRAY_A); // phpcs:ignore
        $fields = array();

        foreach ((array) $rows as $row) {
            $decoded = json_decode((string) $row['fields'], true);
            $fields[(int) $row['id']] = is_array($decoded) ? $decoded : array();
        }

        foreach ($ids as $id) {
            Uploads::deleteEntryFiles($id, $fields[$id] ?? null);
        }

        do_action('smbb_wpcodetool_form_entries_deleted', $ids);

        return (int) $wpdb->query('DELETE FROM ' . Forms::entriesTable() . ' WHERE id IN (' . implode(',', $ids) . ')'); // phpcs:ignore
    }

    public static function deleteForForm($form_id)
    {
        global $wpdb;
        $ids = $wpdb->get_col($wpdb->prepare('SELECT id FROM ' . Forms::entriesTable() . ' WHERE form_id = %d', (int) $form_id)); // phpcs:ignore

        return self::delete(array_map('intval', (array) $ids));
    }

    /**
     * Purge RGPD du formulaire. Une entrée rattachée à un plugin (document signé Passflow…) suit la
     * conservation de ce plugin, pas celle du formulaire : elle n'est pas purgée ici.
     */
    public static function purgeOlderThan($form_id, $days)
    {
        global $wpdb;
        $limit = gmdate('Y-m-d H:i:s', time() - ((int) $days * DAY_IN_SECONDS));
        $ids = $wpdb->get_col($wpdb->prepare('SELECT id FROM ' . Forms::entriesTable() . " WHERE form_id = %d AND source = '' AND created_at < %s LIMIT 5000", (int) $form_id, $limit)); // phpcs:ignore

        return self::delete(array_map('intval', (array) $ids));
    }

    public static function legacyImported($form_id, $legacy_id)
    {
        global $wpdb;

        return (bool) $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . Forms::entriesTable() . ' WHERE form_id = %d AND legacy_id = %d LIMIT 1', (int) $form_id, (int) $legacy_id)); // phpcs:ignore
    }

    /**
     * Entrées voisines (pour « précédente / suivante » dans le détail), selon les mêmes filtres.
     *
     * @return array{prev:int,next:int}
     */
    public static function neighbours(array $entry, array $filters)
    {
        global $wpdb;
        $table = Forms::entriesTable();
        $where = self::where($filters);
        $newer = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE {$where} AND (created_at > %s OR (created_at = %s AND id > %d)) ORDER BY created_at ASC, id ASC LIMIT 1", $entry['created_at'], $entry['created_at'], $entry['id'])); // phpcs:ignore
        $older = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE {$where} AND (created_at < %s OR (created_at = %s AND id < %d)) ORDER BY created_at DESC, id DESC LIMIT 1", $entry['created_at'], $entry['created_at'], $entry['id'])); // phpcs:ignore

        return array('prev' => $newer, 'next' => $older);
    }

    /**
     * Valeur d'un champ de l'entrée par sa clé.
     *
     * @return mixed
     */
    public static function value(array $entry, $key)
    {
        foreach ($entry['fields'] as $field) {
            if (isset($field['key']) && $field['key'] === $key) {
                return $field['value'] ?? '';
            }
        }

        return '';
    }

    /**
     * Petit résumé d'une entrée pour la liste : les 2 ou 3 premières réponses remplies.
     */
    public static function summary(array $entry, $count = 3)
    {
        $parts = array();

        foreach ($entry['fields'] as $field) {
            if (in_array($field['type'] ?? '', array('gdpr', 'hidden', 'file'), true)) {
                continue;
            }

            $value = FieldTypes::display($field['type'] ?? '', $field['value'] ?? '');

            if ($value === '' || in_array($value, $parts, true)) {
                continue;
            }

            $parts[] = $value;

            if (count($parts) >= $count) {
                break;
            }
        }

        return wp_html_excerpt(implode(' · ', $parts), 140, '…');
    }
}
