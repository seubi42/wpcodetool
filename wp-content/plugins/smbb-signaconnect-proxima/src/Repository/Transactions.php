<?php

namespace Smbb\SignConnectProxima\Repository;

use Smbb\SignConnectProxima\Support\Schema;

defined('ABSPATH') || exit;

/**
 * Page « Transactions » : liste paginée et cherchable des envois visibles par un utilisateur,
 * plus son rangement personnel (libellés façon Gmail, archivage, suivi par étoile).
 * Le rangement est propre à chaque utilisateur : archiver ne change rien pour les collègues.
 */
final class Transactions
{
    const PER_PAGE = 25;
    const MAX_TAGS = 50;

    /** Couleurs proposées pour les libellés (lisibles en pastille claire comme en point plein). */
    const COLORS = array('#db1f26', '#f97316', '#eab308', '#16a34a', '#0d9488', '#2563eb', '#7c3aed', '#db2777', '#64748b');

    /** Boîtes de la colonne de gauche => statuts (null = tous). */
    public static function boxes()
    {
        return array(
            'inbox' => null,
            'pending' => array('sent', 'opened'),
            'signed' => array('signed'),
            'closed' => array('refused', 'expired', 'cancelled'),
            'starred' => null,
            'archived' => null,
        );
    }

    /**
     * Conditions SQL (alias d) des documents visibles et filtrés, hors boîte, libellé et recherche.
     *
     * @param array<string,mixed> $filters who, team_id, level, month
     * @return string[]
     */
    public static function baseWhere($user_id, array $scope, array $filters)
    {
        global $wpdb;

        $visible = array($wpdb->prepare('d.sender_id = %d', (int) $user_id));

        if (!empty($scope['org_ids'])) {
            $visible[] = 'd.organization_id IN (' . implode(',', array_map('intval', $scope['org_ids'])) . ')';
        }

        if (!empty($scope['team_ids'])) {
            $visible[] = 'd.team_id IN (' . implode(',', array_map('intval', $scope['team_ids'])) . ')';
        }

        $where = array('d.deleted_flag = 0', "d.status <> 'draft'", '(' . implode(' OR ', $visible) . ')');
        $who = isset($filters['who']) ? (string) $filters['who'] : 'me';

        if ($who === 'me') {
            $where[] = $wpdb->prepare('d.sender_id = %d', (int) $user_id);
        } elseif (ctype_digit($who)) {
            $where[] = $wpdb->prepare('d.sender_id = %d', (int) $who);
        }

        if (!empty($filters['team_id'])) {
            $where[] = $wpdb->prepare('d.team_id = %d', (int) $filters['team_id']);
        }

        if (!empty($filters['level'])) {
            $where[] = $wpdb->prepare('d.signature_level = %s', (string) $filters['level']);
        }

        if (!empty($filters['month'])) {
            $where[] = $wpdb->prepare('d.sent_at >= %s AND d.sent_at < %s', $filters['month'] . '-01 00:00:00', gmdate('Y-m-01 00:00:00', strtotime($filters['month'] . '-01 00:00:00 UTC +1 month')));
        }

        return $where;
    }

    /**
     * Recherche : chaque mot doit se trouver dans le nom, l'email, le téléphone du signataire ou le nom du fichier.
     * Pour le téléphone, on compare les chiffres seuls (06 12 34 56 78 trouve +33612345678).
     */
    public static function searchWhere($query)
    {
        global $wpdb;

        $where = array();
        $words = preg_split('/\s+/u', trim((string) $query), -1, PREG_SPLIT_NO_EMPTY);
        $digits = preg_replace('/\D+/', '', (string) $query);

        // Un numéro tapé avec des espaces forme un seul « mot » de chiffres.
        if (strlen($digits) >= 4 && preg_match('/^[\d\s.+()\-]+$/', trim((string) $query))) {
            $words = array();
        }

        $columns = array('d.recipient_name', 'd.recipient_first_name', 'd.recipient_last_name', 'd.recipient_email', 'd.recipient_phone', 'd.filename');

        // 0.11.0 : champs perso du modèle (N° de commande…) et nom d'origine du PDF renommé.
        if (Schema::atLeast('0.11.0')) {
            $columns[] = 'd.custom_values';
            $columns[] = 'd.source_filename';
        }

        $phone = "REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(d.recipient_phone, ''), ' ', ''), '.', ''), '-', ''), '+', '')";

        foreach (array_slice($words, 0, 6) as $word) {
            $like = '%' . $wpdb->esc_like($word) . '%';
            $parts = array();

            foreach ($columns as $column) {
                $parts[] = $wpdb->prepare($column . ' LIKE %s', $like);
            }

            $word_digits = preg_replace('/\D+/', '', $word);

            if (strlen($word_digits) >= 4 && strlen($word_digits) === strlen(preg_replace('/[\s.+()\-]+/', '', $word))) {
                $parts = array_merge($parts, self::phoneParts($phone, $word_digits));
            }

            $where[] = '(' . implode(' OR ', $parts) . ')';
        }

        if (!$words && $digits !== '') {
            $where[] = '(' . implode(' OR ', self::phoneParts($phone, $digits)) . ')';
        }

        return $where;
    }

    private static function phoneParts($column, $digits)
    {
        global $wpdb;

        $parts = array($wpdb->prepare($column . ' LIKE %s', '%' . $wpdb->esc_like($digits) . '%'));

        // Numéro national (06…) contre numéro international enregistré (+336…).
        if (strlen($digits) >= 2 && $digits[0] === '0' && $digits[1] !== '0') {
            $parts[] = $wpdb->prepare($column . ' LIKE %s', '%33' . $wpdb->esc_like(substr($digits, 1)) . '%');
        }

        return $parts;
    }

    /**
     * Une page de transactions.
     *
     * @param array<string,mixed> $view box, tag, q, page
     * @return array{rows:array,total:int,page:int,pages:int,per_page:int}
     */
    public static function page($user_id, array $scope, array $filters, array $view)
    {
        global $wpdb;

        $user_id = (int) $user_id;
        $where = array_merge(self::baseWhere($user_id, $scope, $filters), self::searchWhere(isset($view['q']) ? $view['q'] : ''));
        $box = isset($view['box']) ? (string) $view['box'] : 'inbox';
        $tag_id = isset($view['tag']) ? (int) $view['tag'] : 0;
        $searching = isset($view['q']) && trim((string) $view['q']) !== '';
        $boxes = self::boxes();

        if ($tag_id) {
            $where[] = $wpdb->prepare('EXISTS (SELECT 1 FROM ' . Lookup::table('scp_document_tag') . ' t WHERE t.document_id = d.id AND t.user_id = %d AND t.tag_id = %d)', $user_id, $tag_id);
        } elseif ($box === 'starred') {
            $where[] = 'u.starred = 1';
        } elseif ($box === 'archived') {
            $where[] = 'u.archived_at IS NOT NULL';
        } elseif (!$searching) {
            // Une recherche fouille aussi les archives, comme dans Gmail.
            $where[] = 'u.archived_at IS NULL';
        }

        if (!$tag_id && isset($boxes[$box]) && is_array($boxes[$box])) {
            $where[] = "d.status IN ('" . implode("','", array_map('esc_sql', $boxes[$box])) . "')";
        }

        $from = ' FROM ' . Lookup::table('scp_document') . ' d LEFT JOIN ' . Lookup::table('scp_document_user') . ' u ON u.document_id = d.id AND u.user_id = ' . $user_id
            . ' WHERE ' . implode(' AND ', $where);
        $total = (int) $wpdb->get_var('SELECT COUNT(*)' . $from);
        $per_page = self::PER_PAGE;
        $pages = max(1, (int) ceil($total / $per_page));
        $page = min(max(1, isset($view['page']) ? (int) $view['page'] : 1), $pages);
        $rows = $wpdb->get_results('SELECT d.*, u.starred AS scp_starred, u.archived_at AS scp_archived_at' . $from
            . ' ORDER BY COALESCE(d.sent_at, d.creation_date) DESC, d.id DESC LIMIT ' . (int) $per_page . ' OFFSET ' . (int) (($page - 1) * $per_page), ARRAY_A);

        return array(
            'rows' => is_array($rows) ? $rows : array(),
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
            'per_page' => $per_page,
        );
    }

    /**
     * Compteurs des boîtes (avec les filtres de personne, équipe, niveau et mois).
     *
     * @return array<string,int>
     */
    public static function boxCounts($user_id, array $scope, array $filters)
    {
        global $wpdb;

        $user_id = (int) $user_id;
        $sql = 'SELECT d.status, CASE WHEN u.archived_at IS NULL THEN 0 ELSE 1 END AS archived, COALESCE(u.starred, 0) AS starred, COUNT(*) AS n'
            . ' FROM ' . Lookup::table('scp_document') . ' d LEFT JOIN ' . Lookup::table('scp_document_user') . ' u ON u.document_id = d.id AND u.user_id = ' . $user_id
            . ' WHERE ' . implode(' AND ', self::baseWhere($user_id, $scope, $filters))
            . ' GROUP BY d.status, CASE WHEN u.archived_at IS NULL THEN 0 ELSE 1 END, COALESCE(u.starred, 0)';
        $counts = array_fill_keys(array_keys(self::boxes()), 0);

        foreach ((array) $wpdb->get_results($sql, ARRAY_A) as $row) {
            $n = (int) $row['n'];

            if ((int) $row['starred']) {
                $counts['starred'] += $n;
            }

            if ((int) $row['archived']) {
                $counts['archived'] += $n;
                continue;
            }

            $counts['inbox'] += $n;

            foreach (self::boxes() as $box => $statuses) {
                if (is_array($statuses) && in_array($row['status'], $statuses, true)) {
                    $counts[$box] += $n;
                }
            }
        }

        return $counts;
    }

    /**
     * Chiffres du bandeau : en attente, signées ce mois, taux de signature et délai moyen sur 90 jours.
     *
     * @return array{pending:int,signed_month:int,rate:int|null,delay:float|null,expiring:int}
     */
    public static function stats($user_id, array $scope, array $filters)
    {
        global $wpdb;

        $where = implode(' AND ', self::baseWhere($user_id, $scope, $filters));
        $table = Lookup::table('scp_document');
        $now = current_time('mysql');
        $since = gmdate('Y-m-d H:i:s', strtotime($now . ' UTC -90 days'));
        $month = substr($now, 0, 7) . '-01 00:00:00';
        $pending = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} d WHERE {$where} AND d.status IN ('sent','opened')");
        $expiring = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} d WHERE {$where} AND d.status IN ('sent','opened') AND d.expires_at > %s AND d.expires_at <= %s", $now, gmdate('Y-m-d H:i:s', strtotime($now . ' UTC +3 days'))));
        $signed_month = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} d WHERE {$where} AND d.status = 'signed' AND d.signed_at >= %s", $month));
        $recent = $wpdb->get_results($wpdb->prepare("SELECT d.status, d.sent_at, d.signed_at FROM {$table} d WHERE {$where} AND d.sent_at >= %s AND d.status IN ('signed','refused','expired') LIMIT 5000", $since), ARRAY_A);
        $closed = 0;
        $signed = 0;
        $hours = 0.0;

        foreach ((array) $recent as $row) {
            $closed++;

            if ($row['status'] === 'signed' && $row['signed_at'] && $row['sent_at']) {
                $signed++;
                $hours += max(0, strtotime($row['signed_at'] . ' UTC') - strtotime($row['sent_at'] . ' UTC')) / 3600;
            }
        }

        return array(
            'pending' => $pending,
            'expiring' => $expiring,
            'signed_month' => $signed_month,
            'rate' => $closed ? (int) round($signed * 100 / $closed) : null,
            'delay' => $signed ? $hours / $signed : null,
        );
    }

    /* ------------------------------------------------------------------ Libellés */

    /**
     * @return array<int,array{id:int,name:string,color:string,count:int}>
     */
    public static function tags($user_id)
    {
        global $wpdb;

        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT g.id, g.name, g.color, (SELECT COUNT(*) FROM ' . Lookup::table('scp_document_tag') . ' t JOIN ' . Lookup::table('scp_document') . ' d ON d.id = t.document_id AND d.deleted_flag = 0 WHERE t.tag_id = g.id AND t.user_id = g.user_id) AS n'
            . ' FROM ' . Lookup::table('scp_tag') . ' g WHERE g.user_id = %d ORDER BY g.sort_order ASC, g.name ASC',
            (int) $user_id
        ), ARRAY_A);
        $tags = array();

        foreach ((array) $rows as $row) {
            $tags[(int) $row['id']] = array('id' => (int) $row['id'], 'name' => (string) $row['name'], 'color' => self::color($row['color']), 'count' => (int) $row['n']);
        }

        return $tags;
    }

    /**
     * Libellés de l'utilisateur posés sur ces documents.
     *
     * @param int[] $document_ids
     * @return array<int,int[]> document id => tag ids
     */
    public static function documentTags($user_id, array $document_ids)
    {
        global $wpdb;

        $document_ids = array_filter(array_map('intval', $document_ids));

        if (!$document_ids) {
            return array();
        }

        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT document_id, tag_id FROM ' . Lookup::table('scp_document_tag') . ' WHERE user_id = %d AND document_id IN (' . implode(',', $document_ids) . ')',
            (int) $user_id
        ), ARRAY_A);
        $map = array();

        foreach ((array) $rows as $row) {
            $map[(int) $row['document_id']][] = (int) $row['tag_id'];
        }

        return $map;
    }

    public static function createTag($user_id, $name, $color = '')
    {
        global $wpdb;

        $name = self::cleanName($name);
        $existing = self::tags($user_id);

        foreach ($existing as $tag) {
            if (self::same($tag['name'], $name)) {
                return $tag['id'];
            }
        }

        if (count($existing) >= self::MAX_TAGS) {
            throw new \InvalidArgumentException(sprintf(__('%d libellés au maximum.', 'smbb-signaconnect-proxima'), self::MAX_TAGS));
        }

        $color = $color !== '' ? self::color($color) : self::COLORS[count($existing) % count(self::COLORS)];
        $wpdb->insert(Lookup::table('scp_tag'), array(
            'user_id' => (int) $user_id,
            'name' => $name,
            'color' => $color,
            'sort_order' => count($existing),
            'creation_date' => current_time('mysql'),
        ));

        if (!$wpdb->insert_id) {
            throw new \RuntimeException(__("Le libellé n'a pas pu être créé.", 'smbb-signaconnect-proxima'));
        }

        return (int) $wpdb->insert_id;
    }

    public static function updateTag($user_id, $tag_id, $name, $color)
    {
        global $wpdb;

        $tags = self::tags($user_id);
        $tag_id = (int) $tag_id;

        if (!isset($tags[$tag_id])) {
            throw new \InvalidArgumentException(__('Libellé introuvable.', 'smbb-signaconnect-proxima'));
        }

        $name = self::cleanName($name);

        foreach ($tags as $tag) {
            if ($tag['id'] !== $tag_id && self::same($tag['name'], $name)) {
                throw new \InvalidArgumentException(__('Un libellé porte déjà ce nom.', 'smbb-signaconnect-proxima'));
            }
        }

        $wpdb->update(Lookup::table('scp_tag'), array('name' => $name, 'color' => self::color($color)), array('id' => $tag_id, 'user_id' => (int) $user_id));
    }

    public static function deleteTag($user_id, $tag_id)
    {
        global $wpdb;

        $wpdb->delete(Lookup::table('scp_document_tag'), array('tag_id' => (int) $tag_id, 'user_id' => (int) $user_id));
        $wpdb->delete(Lookup::table('scp_tag'), array('id' => (int) $tag_id, 'user_id' => (int) $user_id));
    }

    /**
     * Pose ou retire un libellé sur des documents (déjà contrôlés par l'appelant).
     *
     * @param int[] $document_ids
     */
    public static function setTag($user_id, array $document_ids, $tag_id, $on)
    {
        global $wpdb;

        $tags = self::tags($user_id);

        if (!isset($tags[(int) $tag_id])) {
            throw new \InvalidArgumentException(__('Libellé introuvable.', 'smbb-signaconnect-proxima'));
        }

        $table = Lookup::table('scp_document_tag');

        foreach ($document_ids as $document_id) {
            $where = array('user_id' => (int) $user_id, 'document_id' => (int) $document_id, 'tag_id' => (int) $tag_id);
            $wpdb->delete($table, $where);

            if ($on) {
                $wpdb->insert($table, $where);
            }
        }
    }

    /**
     * Archive / désarchive, ou suit / ne suit plus, des documents pour cet utilisateur.
     *
     * @param int[]  $document_ids
     * @param string $what archived|starred
     */
    public static function setState($user_id, array $document_ids, $what, $on)
    {
        global $wpdb;

        $table = Lookup::table('scp_document_user');
        $value = $what === 'archived' ? ($on ? current_time('mysql') : null) : ($on ? 1 : 0);

        foreach ($document_ids as $document_id) {
            $key = array('user_id' => (int) $user_id, 'document_id' => (int) $document_id);
            $id = $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . $table . ' WHERE user_id = %d AND document_id = %d', $key['user_id'], $key['document_id']));

            if ($id) {
                if ($value === null) {
                    $wpdb->query($wpdb->prepare('UPDATE ' . $table . ' SET archived_at = NULL WHERE id = %d', (int) $id));
                } else {
                    $wpdb->update($table, array($what === 'archived' ? 'archived_at' : 'starred' => $value), array('id' => (int) $id));
                }
            } elseif ($on) {
                $wpdb->insert($table, $key + array($what === 'archived' ? 'archived_at' : 'starred' => $value));
            }
        }
    }

    public static function color($color)
    {
        $color = strtolower(trim((string) $color));

        return preg_match('/^#[0-9a-f]{6}$/', $color) ? $color : self::COLORS[count(self::COLORS) - 1];
    }

    private static function cleanName($name)
    {
        $name = trim(preg_replace('/\s+/u', ' ', wp_strip_all_tags((string) $name)));

        if ($name === '') {
            throw new \InvalidArgumentException(__('Donnez un nom au libellé.', 'smbb-signaconnect-proxima'));
        }

        return mb_substr($name, 0, 40);
    }

    private static function same($a, $b)
    {
        return mb_strtolower(remove_accents($a)) === mb_strtolower(remove_accents($b));
    }
}
