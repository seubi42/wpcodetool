<?php

namespace Smbb\WpCodeTool\Schema;

use Smbb\WpCodeTool\Resource\ResourceDefinition;

// La synchro manipule wpdb, options et dbDelta : contexte WordPress obligatoire.
defined('ABSPATH') || exit;

/**
 * Compare et applique le schema SQL attendu pour les ressources custom table.
 *
 * V1 volontairement prudente :
 * - creation de table ;
 * - ajout/mise a jour additive via dbDelta() ;
 * - aucun drop automatique ;
 * - aucune migration de donnees complexe.
 */
final class SchemaSynchronizer
{
    const OPTION_HASHES = 'smbb_wpcodetool_schema_hashes';

    private $builder;
    private $sql_cache = array();
    private $table_exists_cache = array();
    private $stored_hashes = null;

    public function __construct(SchemaBuilder $builder = null)
    {
        $this->builder = $builder ?: new SchemaBuilder();
    }

    /**
     * Donne l'etat lisible d'une ressource.
     */
    public function status(ResourceDefinition $resource)
    {
        if ($resource->storageType() !== 'custom_table') {
            return array(
                'state' => 'not_applicable',
                'label' => __('Not applicable', 'smbb-wpcodetool'),
                'table' => '',
                'hash' => '',
                'stored_hash' => '',
            );
        }

        $sql = $this->previewSql($resource);

        if ($sql === '') {
            return array(
                'state' => 'invalid',
                'label' => __('Invalid schema', 'smbb-wpcodetool'),
                'table' => $resource->tableName(),
                'hash' => '',
                'stored_hash' => '',
            );
        }

        $hash = $this->schemaHash($resource);
        $stored_hash = $this->storedHash($resource);
        $table_exists = $this->tableExists($resource->tableName());

        if (!$table_exists) {
            $state = 'missing';
            $label = __('Table missing', 'smbb-wpcodetool');
        } elseif ($stored_hash !== $hash) {
            $state = 'needs_update';
            $label = __('Update required', 'smbb-wpcodetool');
        } else {
            $state = 'ok';
            $label = __('Up to date', 'smbb-wpcodetool');
        }

        return array(
            'state' => $state,
            'label' => $label,
            'table' => $resource->tableName(),
            'hash' => $hash,
            'stored_hash' => $stored_hash,
        );
    }

    /**
     * Retourne le SQL qui sera envoye a dbDelta().
     */
    public function previewSql(ResourceDefinition $resource)
    {
        $cache_key = $resource->name();

        if (!array_key_exists($cache_key, $this->sql_cache)) {
            $this->sql_cache[$cache_key] = $this->builder->createTableSql($resource);
        }

        return $this->sql_cache[$cache_key];
    }

    /**
     * Applique la synchro manuelle.
     */
    public function apply(ResourceDefinition $resource)
    {
        global $wpdb;

        $sql = $this->previewSql($resource);

        if ($sql === '') {
            return array(
                'success' => false,
                'message' => __('No SQL could be generated for this resource.', 'smbb-wpcodetool'),
                'changes' => array(),
            );
        }

        if (!function_exists('dbDelta')) {
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        }

        $changes = dbDelta($sql);

        if (!empty($wpdb->last_error)) {
            return array(
                'success' => false,
                'message' => $wpdb->last_error,
                'changes' => is_array($changes) ? $changes : array(),
            );
        }

        $this->markApplied($resource);
        $this->table_exists_cache[$resource->tableName()] = true;

        return array(
            'success' => true,
            'message' => __('Database schema synchronized.', 'smbb-wpcodetool'),
            'changes' => is_array($changes) ? $changes : array(),
        );
    }

    /**
     * Verifie l'existence physique d'une table.
     */
    private function tableExists($table_name)
    {
        global $wpdb;

        if (array_key_exists($table_name, $this->table_exists_cache)) {
            return $this->table_exists_cache[$table_name];
        }

        // SHOW TABLES LIKE traite "_" et "%" comme des jokers. Nos tables utilisent
        // beaucoup les underscores, donc on passe par esc_like() avant prepare().
        $like = $wpdb->esc_like($table_name);

        $this->table_exists_cache[$table_name] = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $like)) === $table_name;

        return $this->table_exists_cache[$table_name];
    }

    /**
     * Hash stable du schema attendu.
     */
    private function schemaHash(ResourceDefinition $resource)
    {
        return md5($this->previewSql($resource));
    }

    /**
     * Hash stocke lors de la derniere application manuelle reussie.
     */
    private function storedHash(ResourceDefinition $resource)
    {
        $hashes = $this->storedHashes();
        $name = $resource->name();

        if (!isset($hashes[$name]) || !is_array($hashes[$name])) {
            return '';
        }

        return isset($hashes[$name]['hash']) ? (string) $hashes[$name]['hash'] : '';
    }

    /**
     * Marque le schema comme applique.
     */
    private function markApplied(ResourceDefinition $resource)
    {
        $hashes = $this->storedHashes();

        $hashes[$resource->name()] = array(
            'hash' => $this->schemaHash($resource),
            'table' => $resource->tableName(),
            'model_file' => $resource->modelFile(),
            'applied_at' => current_time('mysql'),
        );

        update_option(self::OPTION_HASHES, $hashes, false);
        $this->stored_hashes = $hashes;
    }

    /**
     * Charge l'option interne de suivi.
     */
    private function storedHashes()
    {
        if ($this->stored_hashes !== null) {
            return $this->stored_hashes;
        }

        $hashes = get_option(self::OPTION_HASHES, array());
        $this->stored_hashes = is_array($hashes) ? $hashes : array();

        return $this->stored_hashes;
    }
}
