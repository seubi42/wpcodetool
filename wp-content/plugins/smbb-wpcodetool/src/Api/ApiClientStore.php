<?php

namespace Smbb\WpCodeTool\Api;

defined('ABSPATH') || exit;

/**
 * Regroupe les operations de lecture/ecriture autour de la table SQL des clients API.
 */
final class ApiClientStore
{
    private const DEFAULT_TOKEN_TTL = 259200;
    private const MIN_DEFAULT_TOKEN_TTL = 1200;
    private const MAX_TOKEN_TTL = 864000;
    private const EXPIRATION_SWEEP_INTERVAL = 300;
    private const OPTION_LAST_SWEEP = 'smbb_wpcodetool_api_client_expiration_sweep';

    private $schema;
    private $scope_authorizer;

    public function __construct(ApiAuthSchema $schema = null, ApiScopeAuthorizer $scope_authorizer = null)
    {
        $this->schema = $schema ?: new ApiAuthSchema();
        $this->scope_authorizer = $scope_authorizer ?: new ApiScopeAuthorizer();
    }

    /**
     * Full client listing for the admin table.
     *
     * @return array<int,array<string,mixed>>
     */
    public function listing()
    {
        global $wpdb;

        $this->schema->ensureInstalled();
        $this->deactivateExpiredClients();

        $table = $this->schema->clientTableName();

        $rows = $wpdb->get_results("SELECT * FROM {$table} ORDER BY created_at DESC, id DESC", ARRAY_A);

        if (!is_array($rows)) {
            return array();
        }

        return array_map(array($this, 'normalizeClientRow'), $rows);
    }

    /**
     * Count all configured API clients.
     */
    public function count()
    {
        global $wpdb;

        $this->schema->ensureInstalled();

        $table = $this->schema->clientTableName();

        return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
    }

    /**
     * Whether at least one active client exists.
     */
    public function hasActiveClients()
    {
        global $wpdb;

        $this->schema->ensureInstalled();
        $this->deactivateExpiredClients();

        $table = $this->schema->clientTableName();

        return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE active = 1") > 0;
    }

    /**
     * Create a new client and return the plain credentials once.
     */
    public function create($label, $contact_email = '', $token_ttl_seconds = 259200, $expires_at = '', $scopes = array())
    {
        global $wpdb;

        $this->schema->ensureInstalled();

        $label = trim((string) $label);
        $label = $label !== '' ? $label : __('API client', 'smbb-wpcodetool');
        $contact_email = sanitize_email((string) $contact_email);
        $scopes = $this->scope_authorizer->normalizeScopeList($scopes);
        $token_ttl_seconds = $this->normalizeDefaultTtl($token_ttl_seconds);
        $expires_at = $this->normalizeDateTime($expires_at);
        $client_id = $this->generateUniqueClientId();
        $client_secret = 'cs_' . wp_generate_password(48, false, false);
        $now = current_time('mysql');

        $inserted = $wpdb->insert(
            $this->schema->clientTableName(),
            array(
                'client_id' => $client_id,
                'client_secret_hash' => $this->hashSecret($client_secret),
                'secret_prefix' => substr($client_secret, 0, 12),
                'label' => $label,
                'contact_email' => $contact_email,
                'scopes' => $this->encodeScopes($scopes),
                'token_ttl_seconds' => $token_ttl_seconds,
                'expires_at' => $expires_at !== '' ? $expires_at : null,
                'last_token_at' => null,
                'active' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            )
        );

        if (!$inserted) {
            return null;
        }

        return array(
            'id' => (int) $wpdb->insert_id,
            'label' => $label,
            'client_id' => $client_id,
            'client_secret' => $client_secret,
            'contact_email' => $contact_email,
            'scopes' => $scopes,
            'token_ttl_seconds' => $token_ttl_seconds,
            'expires_at' => $expires_at,
        );
    }

    /**
     * Enable or disable one client.
     */
    public function setActive($id, $active)
    {
        global $wpdb;

        $this->schema->ensureInstalled();
        $id = (int) $id;
        $row = $this->findById($id);

        if (!$row) {
            return false;
        }

        if ($active && $this->isExpired($row)) {
            return false;
        }

        $updated = $wpdb->update(
            $this->schema->clientTableName(),
            array(
                'active' => $active ? 1 : 0,
                'updated_at' => current_time('mysql'),
            ),
            array('id' => $id),
            array('%d', '%s'),
            array('%d')
        );

        if ($updated === false) {
            return false;
        }

        if (!$active) {
            $this->revokeTokensForClientId($id);
        }

        return true;
    }

    /**
     * Update the editable metadata of one client.
     */
    public function update($id, $label, $contact_email = '', $token_ttl_seconds = 259200, $expires_at = '', $scopes = array())
    {
        global $wpdb;

        $this->schema->ensureInstalled();
        $id = (int) $id;
        $row = $this->findById($id);

        if (!$row) {
            return false;
        }

        $label = trim((string) $label);
        $label = $label !== '' ? $label : __('API client', 'smbb-wpcodetool');
        $contact_email = sanitize_email((string) $contact_email);
        $scopes = $this->scope_authorizer->normalizeScopeList($scopes);
        $token_ttl_seconds = $this->normalizeDefaultTtl($token_ttl_seconds);
        $expires_at = $this->normalizeDateTime($expires_at);
        $is_expired = $expires_at !== '' && strtotime($expires_at) !== false && strtotime($expires_at) <= time();
        $active = !empty($row['active']) && !$is_expired ? 1 : 0;

        $updated = $wpdb->update(
            $this->schema->clientTableName(),
            array(
                'label' => $label,
                'contact_email' => $contact_email,
                'scopes' => $this->encodeScopes($scopes),
                'token_ttl_seconds' => $token_ttl_seconds,
                'expires_at' => $expires_at !== '' ? $expires_at : null,
                'active' => $active,
                'updated_at' => current_time('mysql'),
            ),
            array('id' => $id),
            array('%s', '%s', '%s', '%d', '%s', '%d', '%s'),
            array('%d')
        );

        if ($updated === false) {
            return false;
        }

        if ($is_expired) {
            $this->revokeTokensForClientId($id);
        }

        return true;
    }

    /**
     * Delete one client and every issued token tied to it.
     */
    public function delete($id)
    {
        global $wpdb;

        $this->schema->ensureInstalled();
        $id = (int) $id;

        $this->deleteTokensForClientId($id);

        return (bool) $wpdb->delete(
            $this->schema->clientTableName(),
            array('id' => $id),
            array('%d')
        );
    }

    /**
     * Resolve a client from its public credentials.
     *
     * @return array<string,mixed>|null
     */
    public function findActiveByCredentials($client_id, $client_secret)
    {
        global $wpdb;

        $this->schema->ensureInstalled();
        $now = current_time('mysql');

        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT * FROM ' . $this->schema->clientTableName() . ' WHERE client_id = %s AND active = 1 AND (expires_at IS NULL OR expires_at > %s) LIMIT 1',
                sanitize_text_field((string) $client_id),
                $now
            ),
            ARRAY_A
        );

        if (!$row) {
            return null;
        }

        $expected_hash = isset($row['client_secret_hash']) ? (string) $row['client_secret_hash'] : '';
        $provided_hash = $this->hashSecret($client_secret);

        return ($expected_hash !== '' && hash_equals($expected_hash, $provided_hash)) ? $this->normalizeClientRow($row) : null;
    }

    /**
     * @param mixed $value
     */
    public function scopesTextarea($value)
    {
        return $this->scope_authorizer->textareaValue($value);
    }

    /**
     * Remember the last successful token issuance.
     */
    public function recordTokenIssued($id)
    {
        global $wpdb;

        $this->schema->ensureInstalled();

        return $wpdb->update(
            $this->schema->clientTableName(),
            array(
                'last_token_at' => current_time('mysql'),
                'updated_at' => current_time('mysql'),
            ),
            array('id' => (int) $id),
            array('%s', '%s'),
            array('%d')
        ) !== false;
    }

    /**
     * Resolve the final TTL for one token request.
     */
    public function resolveTokenTtl(array $client, $requested_ttl = 0)
    {
        $default_ttl = $this->normalizeDefaultTtl(isset($client['token_ttl_seconds']) ? (int) $client['token_ttl_seconds'] : self::DEFAULT_TOKEN_TTL);
        $requested_ttl = (int) $requested_ttl;

        if ($requested_ttl <= 0) {
            return $default_ttl;
        }

        return min($default_ttl, $this->normalizeRequestedTtl($requested_ttl));
    }

    /**
     * Auto-disable clients that have passed their expiration date.
     */
    public function deactivateExpiredClients($force = false)
    {
        global $wpdb;

        $this->schema->ensureInstalled();

        if (!$force && !$this->shouldSweepExpiredClients()) {
            return 0;
        }

        $table = $this->schema->clientTableName();
        $now = current_time('mysql');
        $ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT id FROM {$table} WHERE active = 1 AND expires_at IS NOT NULL AND expires_at <= %s",
                $now
            )
        );

        if (!$ids) {
            $this->markSweepComplete();
            return 0;
        }

        $ids = array_map('intval', $ids);
        $placeholders = implode(', ', array_fill(0, count($ids), '%d'));
        $args = array_merge(array(current_time('mysql')), $ids);

        $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$table} SET active = 0, updated_at = %s WHERE id IN ({$placeholders})",
                $args
            )
        );

        foreach ($ids as $id) {
            $this->revokeTokensForClientId($id);
        }

        $this->markSweepComplete();

        return count($ids);
    }

    /**
     * Internal row fetch by numeric id.
     *
     * @return array<string,mixed>|null
     */
    private function findById($id)
    {
        global $wpdb;

        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT * FROM ' . $this->schema->clientTableName() . ' WHERE id = %d LIMIT 1',
                (int) $id
            ),
            ARRAY_A
        );

        return $row ? $this->normalizeClientRow($row) : null;
    }

    /**
     * Whether one row is past its optional expiration date.
     */
    private function isExpired(array $row)
    {
        if (empty($row['expires_at'])) {
            return false;
        }

        $timestamp = strtotime((string) $row['expires_at']);

        return $timestamp !== false && $timestamp <= time();
    }

    /**
     * Revoke every still-active access token for one client.
     */
    private function revokeTokensForClientId($id)
    {
        global $wpdb;

        $wpdb->query(
            $wpdb->prepare(
                'UPDATE ' . $this->schema->accessTokenTableName() . ' SET revoked_at = %s WHERE api_client_id = %d AND revoked_at IS NULL',
                current_time('mysql'),
                (int) $id
            )
        );
    }

    /**
     * Delete all access tokens for one client.
     */
    private function deleteTokensForClientId($id)
    {
        global $wpdb;

        $wpdb->delete(
            $this->schema->accessTokenTableName(),
            array('api_client_id' => (int) $id),
            array('%d')
        );
    }

    /**
     * Evite de refaire la passe d'expiration a chaque requete.
     */
    private function shouldSweepExpiredClients()
    {
        $last_sweep = (int) get_option(self::OPTION_LAST_SWEEP, 0);

        return $last_sweep <= (time() - self::EXPIRATION_SWEEP_INTERVAL);
    }

    /**
     * Memorise la derniere passe d'expiration executee.
     */
    private function markSweepComplete()
    {
        update_option(self::OPTION_LAST_SWEEP, time(), false);
    }

    /**
     * TTL guardrails for both client defaults and requested tokens.
     */
    private function normalizeDefaultTtl($value)
    {
        $value = (int) $value > 0 ? (int) $value : self::DEFAULT_TOKEN_TTL;

        return max(self::MIN_DEFAULT_TOKEN_TTL, min(self::MAX_TOKEN_TTL, $value));
    }

    /**
     * Guardrails for one token request lifetime.
     */
    private function normalizeRequestedTtl($value)
    {
        $value = (int) $value;

        return max(1, min(self::MAX_TOKEN_TTL, $value));
    }

    /**
     * Normalize the optional expiration datetime.
     */
    private function normalizeDateTime($value)
    {
        $value = trim((string) $value);

        if ($value === '') {
            return '';
        }

        $timezone = wp_timezone();
        $formats = array(
            'Y-m-d\TH:i:s',
            'Y-m-d\TH:i',
            'Y-m-d H:i:s',
            'Y-m-d H:i',
        );

        foreach ($formats as $format) {
            $date = \DateTimeImmutable::createFromFormat($format, $value, $timezone);

            if ($date instanceof \DateTimeImmutable) {
                return $date->format('Y-m-d H:i:s');
            }
        }

        $timestamp = strtotime($value);

        return $timestamp ? wp_date('Y-m-d H:i:s', $timestamp, $timezone) : '';
    }

    /**
     * Create a public client_id with collision protection.
     */
    private function generateUniqueClientId()
    {
        global $wpdb;

        $table = $this->schema->clientTableName();

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $client_id = 'cli_' . wp_generate_password(24, false, false);
            $existing = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE client_id = %s LIMIT 1", $client_id));

            if (!$existing) {
                return $client_id;
            }
        }

        return 'cli_' . uniqid('', true);
    }

    /**
     * Stable secret hashing stored in SQL.
     */
    private function hashSecret($client_secret)
    {
        return hash_hmac('sha256', trim((string) $client_secret), wp_salt('auth'));
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function normalizeClientRow(array $row)
    {
        $row['scopes'] = $this->scope_authorizer->normalizeScopeList(isset($row['scopes']) ? $row['scopes'] : null);

        return $row;
    }

    /**
     * @param array<int,string> $scopes
     */
    private function encodeScopes(array $scopes)
    {
        return json_encode(array_values($scopes));
    }
}
