<?php

namespace Smbb\WpCodeTool\Schema;

use Smbb\WpCodeTool\Resource\ResourceDefinition;

// Le builder produit du SQL pour WordPress/dbDelta, il ne doit pas etre appele hors WP.
defined('ABSPATH') || exit;

/**
 * Transforme une definition JSON CodeTool en SQL CREATE TABLE.
 *
 * Cette classe ne touche jamais la base. Elle est purement declarative :
 * - le JSON dit "colonnes/indexes" ;
 * - le builder fabrique le SQL attendu ;
 * - le synchronizer decide ensuite quoi en faire.
 */
final class SchemaBuilder
{
    /**
     * Genere le SQL complet compatible dbDelta().
     */
    public function createTableSql(ResourceDefinition $resource)
    {
        if ($resource->storageType() !== 'custom_table') {
            return '';
        }

        $columns = $resource->columns();

        if (!$columns) {
            return '';
        }

        global $wpdb;

        $lines = array();

        foreach ($columns as $column_name => $definition) {
            if (!is_array($definition)) {
                continue;
            }

            $lines[] = '  ' . $this->columnSql($column_name, $definition);
        }

        $primary_columns = $this->primaryColumns($resource);

        if ($primary_columns) {
            // dbDelta aime historiquement cette forme avec deux espaces apres PRIMARY KEY.
            $lines[] = '  PRIMARY KEY  (' . implode(', ', $primary_columns) . ')';
        }

        foreach ($resource->indexes() as $index) {
            if (!is_array($index)) {
                continue;
            }

            $index_sql = $this->indexSql($index);

            if ($index_sql !== '') {
                $lines[] = '  ' . $index_sql;
            }
        }

        if (!$lines) {
            return '';
        }

        $charset_collate = $wpdb->get_charset_collate();

        return 'CREATE TABLE ' . $resource->tableName() . " (\n"
            . implode(",\n", $lines)
            . "\n) " . $charset_collate . ';';
    }

    /**
     * Genere une ligne de colonne SQL.
     */
    private function columnSql($column_name, array $definition)
    {
        $auto_increment = !empty($definition['autoIncrement']);
        $nullable = !empty($definition['nullable']) && !$auto_increment;
        $parts = array(
            $this->identifier($column_name),
            $this->typeSql($definition),
            $nullable ? 'NULL' : 'NOT NULL',
        );

        $default_sql = $this->defaultSql($definition, $nullable, $auto_increment);

        if ($default_sql !== '') {
            $parts[] = $default_sql;
        }

        if ($auto_increment) {
            $parts[] = 'AUTO_INCREMENT';
        }

        return implode(' ', $parts);
    }

    /**
     * Convertit le type abstrait du JSON en type SQL MySQL/MariaDB.
     */
    private function typeSql(array $definition)
    {
        $type = isset($definition['type']) ? strtolower((string) $definition['type']) : 'varchar';
        $unsigned = !empty($definition['unsigned']);

        switch ($type) {
            case 'bigint':
            case 'int':
            case 'integer':
            case 'mediumint':
            case 'smallint':
            case 'tinyint':
                $sql = $type === 'integer' ? 'int' : $type;
                break;

            case 'bool':
            case 'boolean':
                $sql = 'tinyint(1)';
                break;

            case 'decimal':
                $precision = isset($definition['precision']) ? max(1, (int) $definition['precision']) : 10;
                $scale = isset($definition['scale']) ? max(0, (int) $definition['scale']) : 0;
                $sql = 'decimal(' . $precision . ',' . $scale . ')';
                break;

            case 'float':
            case 'double':
                $sql = $type;
                break;

            case 'char':
                $sql = 'char(' . $this->length($definition, 191) . ')';
                break;

            case 'varchar':
                $sql = 'varchar(' . $this->length($definition, 191) . ')';
                break;

            case 'text':
            case 'mediumtext':
            case 'longtext':
                $sql = $type;
                break;

            case 'json':
                // MariaDB et certaines installs MySQL/WordPress restent plus previsibles en longtext.
                $sql = 'longtext';
                break;

            case 'date':
            case 'time':
            case 'datetime':
            case 'timestamp':
                $sql = $type;
                break;

            default:
                $sql = 'varchar(191)';
                break;
        }

        if ($unsigned && in_array($type, array('bigint', 'int', 'integer', 'mediumint', 'smallint', 'tinyint', 'decimal', 'float', 'double'), true)) {
            $sql .= ' unsigned';
        }

        return $sql;
    }

    /**
     * Longueur de champ texte courte avec borne raisonnable.
     */
    private function length(array $definition, $default)
    {
        return isset($definition['length']) ? max(1, (int) $definition['length']) : $default;
    }

    /**
     * Genere la clause DEFAULT quand elle est declaree et valide pour le type.
     */
    private function defaultSql(array $definition, $nullable, $auto_increment)
    {
        if ($auto_increment || !array_key_exists('default', $definition)) {
            return '';
        }

        $default = $definition['default'];

        if ($default === null) {
            return $nullable ? 'DEFAULT NULL' : '';
        }

        if (is_bool($default)) {
            return 'DEFAULT ' . ($default ? '1' : '0');
        }

        if (is_int($default) || is_float($default)) {
            return 'DEFAULT ' . $default;
        }

        return "DEFAULT '" . esc_sql((string) $default) . "'";
    }

    /**
     * Colonnes de cle primaire.
     */
    private function primaryColumns(ResourceDefinition $resource)
    {
        $primary = array();

        foreach ($resource->columns() as $column_name => $definition) {
            if (is_array($definition) && !empty($definition['primary'])) {
                $primary[] = $this->identifier($column_name);
            }
        }

        if (!$primary && $resource->primaryKey()) {
            $primary[] = $this->identifier($resource->primaryKey());
        }

        return array_values(array_unique($primary));
    }

    /**
     * Genere une ligne d'index SQL.
     */
    private function indexSql(array $index)
    {
        if (empty($index['name']) || empty($index['columns']) || !is_array($index['columns'])) {
            return '';
        }

        $type = isset($index['type']) ? strtolower((string) $index['type']) : 'index';

        if ($type === 'primary') {
            return '';
        }

        $columns = array();

        foreach ($index['columns'] as $column) {
            $column_sql = $this->indexColumnSql($column);

            if ($column_sql !== '') {
                $columns[] = $column_sql;
            }
        }

        if (!$columns) {
            return '';
        }

        switch ($type) {
            case 'unique':
                $prefix = 'UNIQUE KEY';
                break;
            case 'fulltext':
                $prefix = 'FULLTEXT KEY';
                break;
            case 'spatial':
                $prefix = 'SPATIAL KEY';
                break;
            case 'index':
            default:
                $prefix = 'KEY';
                break;
        }

        return $prefix . ' ' . $this->identifier($index['name']) . ' (' . implode(', ', $columns) . ')';
    }

    /**
     * Genere une colonne dans une definition d'index.
     */
    private function indexColumnSql($column)
    {
        if (is_string($column)) {
            return $this->identifier($column);
        }

        if (!is_array($column) || empty($column['name'])) {
            return '';
        }

        $sql = $this->identifier($column['name']);

        if (!empty($column['length'])) {
            $sql .= '(' . max(1, (int) $column['length']) . ')';
        }

        if (!empty($column['order'])) {
            $order = strtoupper((string) $column['order']);

            if (in_array($order, array('ASC', 'DESC'), true)) {
                $sql .= ' ' . $order;
            }
        }

        return $sql;
    }

    /**
     * Nettoie un identifiant SQL avant insertion dans le SQL genere.
     *
     * On evite les backticks pour rester proche des conventions dbDelta().
     */
    private function identifier($name)
    {
        $name = strtolower(str_replace('-', '_', (string) $name));
        $name = preg_replace('/[^a-z0-9_]/', '_', $name);
        $name = trim($name, '_');

        if ($name === '') {
            return 'field';
        }

        if (preg_match('/^[0-9]/', $name)) {
            return 'f_' . $name;
        }

        return $name;
    }
}
