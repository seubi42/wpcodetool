<?php

namespace Smbb\WpCodeTool\Store;

use Smbb\WpCodeTool\Resource\ResourceDefinition;

// Le store SQL depend de wpdb et ne doit etre charge que dans WordPress.
defined('ABSPATH') || exit;

/**
 * Store generique pour les ressources stockees dans une table custom.
 *
 * Le store reste volontairement bas niveau : il sait parler a la table SQL, mais il ne
 * decide pas des regles metier. Validation, hooks, droits et champs d'audit sont orchestres
 * par AdminManager/API autour de lui.
 */
final class TableStore
{
    // Cache par requete de l'existence des tables pour eviter les SHOW TABLES repetes.
    private static $table_exists_cache = array();

    // Definition CodeTool de la ressource.
    private $resource;

    // Derniere erreur lisible, utile pour l'admin ou les tests.
    private $last_error = '';

    public function __construct(ResourceDefinition $resource)
    {
        $this->resource = $resource;
    }

    /**
     * Liste les lignes de la table custom.
     *
     * Arguments supportes :
     * - search     : terme de recherche ;
     * - orderby    : colonne de tri ;
     * - order      : asc|desc ;
     * - per_page   : limite ;
     * - page       : page numerique, 1 minimum.
     */
    public function list(array $args = array())
    {
        global $wpdb;

        $this->last_error = '';

        if (!$this->tableExists()) {
            return array();
        }

        $params = array();
        $sql = 'SELECT * FROM ' . $this->resource->tableName();
        $where = $this->whereSql($args, $params);

        if ($where !== '') {
            $sql .= ' WHERE ' . $where;
        }

        $sql .= ' ' . $this->orderSql($args);
        $sql .= ' ' . $this->limitSql($args, $params);

        if ($params) {
            $sql = $this->prepare($sql, $params);
        }

        $rows = $wpdb->get_results($sql, ARRAY_A);
        $this->last_error = isset($wpdb->last_error) ? (string) $wpdb->last_error : '';

        if (!is_array($rows)) {
            return array();
        }

        return $this->decodeRows($rows);
    }

    /**
     * Compte le nombre total de lignes correspondant aux filtres courants.
     *
     * Cette methode sert a afficher une pagination admin correcte sans devoir
     * reexecuter toute la liste ou embarquer des metadonnees dans list().
     */
    public function count(array $args = array())
    {
        global $wpdb;

        $this->last_error = '';

        if (!$this->tableExists()) {
            return 0;
        }

        $params = array();
        $sql = 'SELECT COUNT(*) FROM ' . $this->resource->tableName();
        $where = $this->whereSql($args, $params);

        if ($where !== '') {
            $sql .= ' WHERE ' . $where;
        }

        if ($params) {
            $sql = $this->prepare($sql, $params);
        }

        $count = $wpdb->get_var($sql);
        $this->last_error = isset($wpdb->last_error) ? (string) $wpdb->last_error : '';

        return $count === null ? 0 : (int) $count;
    }

    /**
     * Trouve une ligne par cle primaire.
     */
    public function find($id)
    {
        global $wpdb;

        $this->last_error = '';

        if ($id === null || $id === '' || !$this->tableExists()) {
            return null;
        }

        $primary_key = $this->identifier($this->resource->primaryKey());
        $format = $this->columnIsNumeric($this->resource->primaryKey()) ? '%d' : '%s';
        $value = $format === '%d' ? (int) $id : (string) $id;
        $sql = $wpdb->prepare(
            'SELECT * FROM ' . $this->resource->tableName() . ' WHERE ' . $primary_key . ' = ' . $format . ' LIMIT 1',
            $value
        );

        $row = $wpdb->get_row($sql, ARRAY_A);
        $this->last_error = isset($wpdb->last_error) ? (string) $wpdb->last_error : '';

        return is_array($row) ? $this->decodeRow($row) : null;
    }

    /**
     * Recherche simple reutilisable par les endpoints custom.
     *
     * L'exemple TestApi envoie "name" ; l'admin enverra plutot "search".
     * Les deux reviennent au meme moteur de liste.
     */
    public function search(array $args = array())
    {
        if (!isset($args['search']) && isset($args['name'])) {
            $args['search'] = $args['name'];
        }

        if (isset($args['limit']) && !isset($args['per_page'])) {
            $args['per_page'] = $args['limit'];
        }

        return $this->list($args);
    }

    /**
     * Cree une ligne et retourne son identifiant.
     *
     * @return int|string|false
     */
    public function create(array $data)
    {
        global $wpdb;

        $this->last_error = '';
        $data = $this->prepareWriteData($data);

        if (!$data) {
            $this->last_error = __('No writable data was provided.', 'smbb-wpcodetool');
            return false;
        }

        $result = $wpdb->insert(
            $this->resource->tableName(),
            $data,
            $this->formatsForData($data)
        );

        $this->last_error = isset($wpdb->last_error) ? (string) $wpdb->last_error : '';

        if ($result === false) {
            return false;
        }

        return isset($wpdb->insert_id) ? $wpdb->insert_id : true;
    }

    /**
     * Met a jour une ligne existante.
     */
    public function update($id, array $data)
    {
        global $wpdb;

        $this->last_error = '';
        $data = $this->prepareWriteData($data);

        if (!$data) {
            $this->last_error = __('No writable data was provided.', 'smbb-wpcodetool');
            return false;
        }

        $primary_key = $this->resource->primaryKey();
        $where = array($primary_key => $this->normalizeValue($primary_key, $id));
        $result = $wpdb->update(
            $this->resource->tableName(),
            $data,
            $where,
            $this->formatsForData($data),
            $this->formatsForData($where)
        );

        $this->last_error = isset($wpdb->last_error) ? (string) $wpdb->last_error : '';

        return $result !== false;
    }

    /**
     * Supprime une ligne par cle primaire.
     */
    public function delete($id)
    {
        global $wpdb;

        $this->last_error = '';

        $primary_key = $this->resource->primaryKey();
        $where = array($primary_key => $this->normalizeValue($primary_key, $id));
        $result = $wpdb->delete(
            $this->resource->tableName(),
            $where,
            $this->formatsForData($where)
        );

        $this->last_error = isset($wpdb->last_error) ? (string) $wpdb->last_error : '';

        return $result !== false;
    }

    /**
     * Derniere erreur wpdb observee.
     */
    public function lastError()
    {
        return $this->last_error;
    }

    /**
     * Genere le WHERE de recherche.
     */
    private function whereSql(array $args, array &$params)
    {
        $conditions = array();
        $search = isset($args['search']) ? trim((string) $args['search']) : '';

        if ($search !== '') {
            $search_sql = $this->searchSql($args, $search, $params);

            if ($search_sql !== '') {
                $conditions[] = $search_sql;
            }
        }

        $filter_sql = $this->filterSql($args, $params);

        if ($filter_sql !== '') {
            $conditions[] = $filter_sql;
        }

        if (!$conditions) {
            return '';
        }

        return implode(' AND ', $conditions);
    }

    /**
     * Genere le filtre simple de liste.
     */
    private function filterSql(array $args, array &$params)
    {
        $filter = isset($args['filter']) && is_array($args['filter']) ? $args['filter'] : array();
        $field = isset($filter['field']) ? sanitize_key((string) $filter['field']) : '';
        $operator = isset($filter['operator']) ? sanitize_key((string) $filter['operator']) : '';
        $value = isset($filter['value']) ? (string) $filter['value'] : '';

        if ($field === '' || $operator === '' || !in_array($field, $this->filterColumns(), true)) {
            return '';
        }

        if (!in_array($operator, $this->allowedFilterOperators($field), true)) {
            return '';
        }

        $column = $this->identifier($field);

        switch ($operator) {
            case 'empty':
                return '(' . $column . " IS NULL OR " . $column . " = '')";

            case 'not_empty':
                return '(' . $column . " IS NOT NULL AND " . $column . " <> '')";

            case 'contains':
                $params[] = '%' . $this->likeValue($value) . '%';
                return $column . ' LIKE %s';

            case 'starts_with':
                $params[] = $this->likeValue($value) . '%';
                return $column . ' LIKE %s';

            case 'ends_with':
                $params[] = '%' . $this->likeValue($value);
                return $column . ' LIKE %s';

            case 'eq':
                return $this->comparisonSql($column, $field, '=', $value, $params);

            case 'neq':
                return $this->comparisonSql($column, $field, '!=', $value, $params);

            case 'gt':
                return $this->comparisonSql($column, $field, '>', $value, $params);

            case 'gte':
                return $this->comparisonSql($column, $field, '>=', $value, $params);

            case 'lt':
                return $this->comparisonSql($column, $field, '<', $value, $params);

            case 'lte':
                return $this->comparisonSql($column, $field, '<=', $value, $params);
        }

        return '';
    }

    /**
     * Genere la recherche libre : standard ou clause custom.
     */
    private function searchSql(array $args, $search, array &$params)
    {
        $custom = $this->customSearchSql($args, $params);

        if ($custom !== '') {
            return '(' . $custom . ')';
        }

        global $wpdb;

        $columns = $this->searchColumns();

        if (!$columns) {
            return '';
        }

        $likes = array();
        $like = '%' . $wpdb->esc_like($search) . '%';

        foreach ($columns as $column) {
            $likes[] = $this->identifier($column) . ' LIKE %s';
            $params[] = $like;
        }

        $glue = $this->resource->listSearchMode() === 'and' ? ' AND ' : ' OR ';

        return '(' . implode($glue, $likes) . ')';
    }

    /**
     * Clause custom provenant du hook PHP de la ressource.
     */
    private function customSearchSql(array $args, array &$params)
    {
        if (empty($args['search_clause']) || !is_array($args['search_clause'])) {
            return '';
        }

        $sql = isset($args['search_clause']['sql']) ? (string) $args['search_clause']['sql'] : '';
        $clause_params = isset($args['search_clause']['params']) && is_array($args['search_clause']['params']) ? $args['search_clause']['params'] : array();

        if ($sql === '') {
            return '';
        }

        foreach ($clause_params as $param) {
            $params[] = $param;
        }

        return $sql;
    }

    /**
     * Colonnes autorisees pour la recherche.
     */
    private function searchColumns()
    {
        $allowed = array_keys($this->resource->columns());
        $columns = array();

        foreach ($this->resource->listSearchColumns() as $column) {
            if (in_array($column, $allowed, true)) {
                $columns[] = $column;
            }
        }

        return array_values(array_unique($columns));
    }

    /**
     * Colonnes autorisees pour le filtre simple.
     */
    private function filterColumns()
    {
        $allowed = array_keys($this->resource->columns());
        $columns = array();

        foreach ($this->resource->listFilterColumns() as $column) {
            if (in_array($column, $allowed, true)) {
                $columns[] = $column;
            }
        }

        return array_values(array_unique($columns));
    }

    /**
     * Operateurs effectivement autorises pour un champ filtrable.
     */
    private function allowedFilterOperators($field)
    {
        $defaults = array('contains', 'eq', 'neq', 'gt', 'gte', 'lt', 'lte', 'starts_with', 'ends_with', 'empty', 'not_empty');
        $definitions = $this->resource->listFilterDefinitions();

        if (empty($definitions[$field]['operators']) || !is_array($definitions[$field]['operators'])) {
            return $defaults;
        }

        $allowed = array();

        foreach ($definitions[$field]['operators'] as $operator) {
            if (in_array($operator, $defaults, true)) {
                $allowed[] = $operator;
            }
        }

        return $allowed ?: $defaults;
    }

    /**
     * Genere une comparaison simple protegee par prepare().
     */
    private function comparisonSql($column_sql, $field, $operator, $value, array &$params)
    {
        if (trim((string) $value) === '') {
            return '';
        }

        $params[] = $this->normalizeValue($field, $value);

        return $column_sql . ' ' . $operator . ' ' . $this->formatForColumn($field);
    }

    /**
     * Echappe une valeur LIKE.
     */
    private function likeValue($value)
    {
        global $wpdb;

        return $wpdb->esc_like((string) $value);
    }

    /**
     * Genere le ORDER BY en respectant les colonnes declarees.
     */
    private function orderSql(array $args)
    {
        $columns = array_keys($this->resource->columns());
        $config = $this->resource->listConfig();
        $default_order = isset($config['defaultOrder']) && is_array($config['defaultOrder']) ? $config['defaultOrder'] : array();
        $orderby = isset($args['orderby']) ? (string) $args['orderby'] : (isset($default_order['by']) ? (string) $default_order['by'] : $this->resource->primaryKey());

        if (!in_array($orderby, $columns, true)) {
            $orderby = $this->resource->primaryKey();
        }

        $order = isset($args['order']) ? strtolower((string) $args['order']) : (isset($default_order['direction']) ? strtolower((string) $default_order['direction']) : 'desc');
        $order = $order === 'asc' ? 'ASC' : 'DESC';

        return 'ORDER BY ' . $this->identifier($orderby) . ' ' . $order;
    }

    /**
     * Genere LIMIT/OFFSET.
     */
    private function limitSql(array $args, array &$params)
    {
        $config = $this->resource->listConfig();
        $default_per_page = isset($config['perPage']) ? (int) $config['perPage'] : 20;
        $per_page = isset($args['per_page']) ? (int) $args['per_page'] : $default_per_page;
        $per_page = min(200, max(1, $per_page));
        $page = isset($args['page']) ? max(1, (int) $args['page']) : 1;
        $offset = ($page - 1) * $per_page;

        $params[] = $per_page;
        $params[] = $offset;

        return 'LIMIT %d OFFSET %d';
    }

    /**
     * Prepare un SQL avec un nombre variable de parametres.
     */
    private function prepare($sql, array $params)
    {
        global $wpdb;

        return call_user_func_array(array($wpdb, 'prepare'), array_merge(array($sql), $params));
    }

    /**
     * Verifie l'existence de la table avant SELECT.
     */
    private function tableExists()
    {
        global $wpdb;

        $table_name = $this->resource->tableName();

        if (array_key_exists($table_name, self::$table_exists_cache)) {
            return self::$table_exists_cache[$table_name];
        }

        $like = $wpdb->esc_like($table_name);

        self::$table_exists_cache[$table_name] = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $like)) === $table_name;

        return self::$table_exists_cache[$table_name];
    }

    /**
     * Decode les lignes SQL avant injection dans les views.
     */
    private function decodeRows(array $rows)
    {
        foreach ($rows as $index => $row) {
            $rows[$index] = $this->decodeRow($row);
        }

        return $rows;
    }

    /**
     * Decode les valeurs JSON stockees dans des colonnes texte.
     *
     * Cela permet au form builder de recevoir json_table/json_object sous forme de
     * tableaux PHP, donc les repeaters et champs imbriques peuvent se pre-remplir.
     */
    private function decodeRow(array $row)
    {
        foreach ($row as $key => $value) {
            if (!$this->resource->columnStoresJson($key)) {
                continue;
            }

            if (!is_string($value) || $value === '') {
                continue;
            }

            $trimmed = ltrim($value);

            if ($trimmed === '' || ($trimmed[0] !== '{' && $trimmed[0] !== '[')) {
                continue;
            }

            $decoded = json_decode($value, true);

            if (json_last_error() === JSON_ERROR_NONE) {
                $row[$key] = $decoded;
            }
        }

        return $row;
    }

    /**
     * Prepare les valeurs avant insert/update.
     *
     * Le store ne garde que les colonnes declarees dans le modele, ignore la cle primaire
     * auto-increment, et convertit les tableaux en JSON pour les colonnes texte.
     */
    private function prepareWriteData(array $data)
    {
        $columns = $this->resource->columns();
        $prepared = array();

        foreach ($columns as $column => $definition) {
            if (!is_array($definition)) {
                continue;
            }

            if (!array_key_exists($column, $data)) {
                continue;
            }

            if (!empty($definition['primary']) && !empty($definition['autoIncrement'])) {
                continue;
            }

            $prepared[$column] = $this->normalizeValue($column, $data[$column]);
        }

        return $prepared;
    }

    /**
     * Normalise une valeur PHP vers une valeur acceptable par wpdb.
     */
    private function normalizeValue($column, $value)
    {
        if (is_array($value) || is_object($value)) {
            return wp_json_encode($value);
        }

        if ($value === null) {
            return null;
        }

        $definition = $this->columnDefinition($column);
        $type = isset($definition['type']) ? strtolower((string) $definition['type']) : '';

        if ($value === '') {
            /*
             * Pour les types non textuels nullable, une chaine vide est rarement utile :
             * un input date vide ou un media non selectionne doit devenir NULL, pas 0
             * ni "0000-00-00". Les textes gardent "" pour rester previsibles.
             */
            if (!empty($definition['nullable']) && in_array($type, array('bigint', 'int', 'integer', 'mediumint', 'smallint', 'tinyint', 'decimal', 'float', 'double', 'date', 'time', 'datetime', 'timestamp'), true)) {
                return null;
            }

            return '';
        }

        if (in_array($type, array('bigint', 'int', 'integer', 'mediumint', 'smallint', 'tinyint'), true)) {
            return (int) $value;
        }

        if (in_array($type, array('float', 'double'), true)) {
            return (float) $value;
        }

        if (in_array($type, array('date', 'time', 'datetime', 'timestamp'), true)) {
            return $this->normalizeTemporalValue($type, (string) $value);
        }

        return $value;
    }

    /**
     * Normalise les valeurs issues des inputs HTML date/time/datetime-local.
     */
    private function normalizeTemporalValue($type, $value)
    {
        $value = trim($value);

        if ($value === '') {
            return '';
        }

        if ($type === 'date') {
            return substr($value, 0, 10);
        }

        if ($type === 'time') {
            return strlen($value) === 5 ? $value . ':00' : $value;
        }

        $value = str_replace('T', ' ', $value);

        return strlen($value) === 16 ? $value . ':00' : $value;
    }

    /**
     * Formats wpdb correspondant aux colonnes envoyees.
     */
    private function formatsForData(array $data)
    {
        $formats = array();

        foreach ($data as $column => $_) {
            $formats[] = $this->formatForColumn($column);
        }

        return $formats;
    }

    /**
     * Format wpdb d'une colonne.
     */
    private function formatForColumn($column)
    {
        $definition = $this->columnDefinition($column);
        $type = isset($definition['type']) ? strtolower((string) $definition['type']) : '';

        if (in_array($type, array('bigint', 'int', 'integer', 'mediumint', 'smallint', 'tinyint'), true)) {
            return '%d';
        }

        if (in_array($type, array('float', 'double'), true)) {
            return '%f';
        }

        // decimal reste en chaine pour eviter les surprises de float.
        return '%s';
    }

    /**
     * Indique si une colonne est numerique.
     */
    private function columnIsNumeric($column)
    {
        $definition = $this->columnDefinition($column);
        $type = isset($definition['type']) ? strtolower((string) $definition['type']) : '';

        return in_array($type, array('bigint', 'int', 'integer', 'mediumint', 'smallint', 'tinyint'), true);
    }

    /**
     * Definition d'une colonne, centralisee pour eviter les duplications.
     */
    private function columnDefinition($column)
    {
        $columns = $this->resource->columns();

        return isset($columns[$column]) && is_array($columns[$column]) ? $columns[$column] : array();
    }

    /**
     * Nettoie un identifiant SQL issu du JSON.
     */
    private function identifier($name)
    {
        $name = strtolower(str_replace('-', '_', (string) $name));
        $name = preg_replace('/[^a-z0-9_]/', '_', $name);
        $name = trim($name, '_');

        return $name !== '' ? $name : 'field';
    }
}
