<?php

namespace Smbb\WpCodeTool\Api;

defined('ABSPATH') || exit;

/**
 * Cree et maintient les tables SQL dediees aux clients API et aux access tokens.
 */
final class ApiAuthSchema
{
    private const OPTION_VERSION = 'smbb_wpcodetool_api_auth_schema_version';
    private const SCHEMA_VERSION = '2';
    private static $installed = array();

    /**
     * Ensure the dedicated auth tables exist.
     */
    public function ensureInstalled()
    {
        $cache_key = $this->schemaCacheKey();

        if (!empty(self::$installed[$cache_key])) {
            return;
        }

        if ($this->isInstalled()) {
            self::$installed[$cache_key] = true;
            return;
        }

        if (!function_exists('dbDelta')) {
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        }

        dbDelta($this->clientsTableSql());
        dbDelta($this->accessTokensTableSql());

        update_option(self::OPTION_VERSION, self::SCHEMA_VERSION, false);
        self::$installed[$cache_key] = true;
    }

    /**
     * SQL table storing API clients.
     */
    public function clientTableName()
    {
        global $wpdb;

        return $wpdb->prefix . 'smbb_codetool_api_clients';
    }

    /**
     * SQL table storing issued bearer access tokens.
     */
    public function accessTokenTableName()
    {
        global $wpdb;

        return $wpdb->prefix . 'smbb_codetool_api_access_tokens';
    }

    /**
     * Determine whether the schema is already present.
     */
    private function isInstalled()
    {
        return get_option(self::OPTION_VERSION, '') === self::SCHEMA_VERSION
            && $this->tableExists($this->clientTableName())
            && $this->tableExists($this->accessTokenTableName());
    }

    /**
     * Check one table existence.
     */
    private function tableExists($table_name)
    {
        global $wpdb;

        $like = $wpdb->esc_like((string) $table_name);

        return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $like)) === $table_name;
    }

    /**
     * Cle de cache locale a la requete pour un schema donne.
     */
    private function schemaCacheKey()
    {
        return $this->clientTableName() . '|' . $this->accessTokenTableName();
    }

    /**
     * CREATE TABLE for API clients.
     */
    private function clientsTableSql()
    {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();

        return 'CREATE TABLE ' . $this->clientTableName() . " (\n"
            . "id bigint(20) unsigned NOT NULL AUTO_INCREMENT,\n"
            . "client_id varchar(80) NOT NULL,\n"
            . "client_secret_hash char(64) NOT NULL,\n"
            . "secret_prefix varchar(16) NOT NULL DEFAULT '',\n"
            . "label varchar(191) NOT NULL,\n"
            . "contact_email varchar(191) NOT NULL DEFAULT '',\n"
            . "scopes longtext DEFAULT NULL,\n"
            . "token_ttl_seconds int(10) unsigned NOT NULL DEFAULT 259200,\n"
            . "expires_at datetime DEFAULT NULL,\n"
            . "last_token_at datetime DEFAULT NULL,\n"
            . "active tinyint(1) NOT NULL DEFAULT 1,\n"
            . "created_at datetime NOT NULL,\n"
            . "updated_at datetime NOT NULL,\n"
            . "PRIMARY KEY  (id),\n"
            . "UNIQUE KEY client_id (client_id),\n"
            . "KEY active (active),\n"
            . "KEY expires_at (expires_at)\n"
            . ') ' . $charset_collate . ';';
    }

    /**
     * CREATE TABLE for issued bearer tokens.
     */
    private function accessTokensTableSql()
    {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();

        return 'CREATE TABLE ' . $this->accessTokenTableName() . " (\n"
            . "id bigint(20) unsigned NOT NULL AUTO_INCREMENT,\n"
            . "api_client_id bigint(20) unsigned NOT NULL,\n"
            . "token_hash char(64) NOT NULL,\n"
            . "token_prefix varchar(16) NOT NULL DEFAULT '',\n"
            . "issued_at datetime NOT NULL,\n"
            . "expires_at datetime NOT NULL,\n"
            . "last_used_at datetime DEFAULT NULL,\n"
            . "revoked_at datetime DEFAULT NULL,\n"
            . "PRIMARY KEY  (id),\n"
            . "UNIQUE KEY token_hash (token_hash),\n"
            . "KEY api_client_id (api_client_id),\n"
            . "KEY expires_at (expires_at),\n"
            . "KEY revoked_at (revoked_at)\n"
            . ') ' . $charset_collate . ';';
    }
}
