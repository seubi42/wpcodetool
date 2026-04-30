<?php

namespace Smbb\WpCodeTool\Api;

defined('ABSPATH') || exit;

/**
 * Emet et verifie les bearer access tokens stockes dans une table SQL dediee.
 */
final class ApiAccessTokenStore
{
    private const LAST_USED_TOUCH_INTERVAL = 900;
    private const PURGE_INTERVAL = 600;
    private const OPTION_LAST_PURGE = 'smbb_wpcodetool_api_access_token_last_purge';

    private $schema;
    private $scope_authorizer;

    public function __construct(ApiAuthSchema $schema = null, ApiScopeAuthorizer $scope_authorizer = null)
    {
        $this->schema = $schema ?: new ApiAuthSchema();
        $this->scope_authorizer = $scope_authorizer ?: new ApiScopeAuthorizer();
    }

    /**
     * Issue a new bearer token for one API client.
     *
     * @return array<string,mixed>|null
     */
    public function issue($api_client_id, $ttl_seconds)
    {
        global $wpdb;

        $this->schema->ensureInstalled();
        $this->maybePurgeExpired();

        $ttl_seconds = max(1, min(31536000, (int) $ttl_seconds));
        $access_token = 'atk_' . wp_generate_password(64, false, false);
        $issued_at = current_time('mysql');
        $expires_at = wp_date('Y-m-d H:i:s', time() + $ttl_seconds, wp_timezone());

        $inserted = $wpdb->insert(
            $this->schema->accessTokenTableName(),
            array(
                'api_client_id' => (int) $api_client_id,
                'token_hash' => $this->hashToken($access_token),
                'token_prefix' => substr($access_token, 0, 12),
                'issued_at' => $issued_at,
                'expires_at' => $expires_at,
                'last_used_at' => null,
                'revoked_at' => null,
            )
        );

        if (!$inserted) {
            return null;
        }

        return array(
            'access_token' => $access_token,
            'token_type' => 'bearer',
            'expires_in' => $ttl_seconds,
            'expires_at' => $expires_at,
        );
    }

    /**
     * Validate one bearer token and update its last usage date.
     */
    public function verify($plain_token)
    {
        return $this->resolveClient($plain_token) !== null;
    }

    /**
     * Resolve one active client from a bearer token.
     *
     * @return array<string,mixed>|null
     */
    public function resolveClient($plain_token)
    {
        global $wpdb;

        $this->schema->ensureInstalled();
        $this->maybePurgeExpired();

        $plain_token = trim((string) $plain_token);

        if ($plain_token === '') {
            return false;
        }

        $token_table = $this->schema->accessTokenTableName();
        $client_table = $this->schema->clientTableName();
        $now = current_time('mysql');
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT
                    t.id AS token_id,
                    t.last_used_at,
                    c.id AS api_client_id,
                    c.client_id,
                    c.label,
                    c.contact_email,
                    c.scopes,
                    c.token_ttl_seconds,
                    c.expires_at,
                    c.active
                FROM {$token_table} t
                INNER JOIN {$client_table} c ON c.id = t.api_client_id
                WHERE t.token_hash = %s
                    AND t.revoked_at IS NULL
                    AND t.expires_at > %s
                    AND c.active = 1
                    AND (c.expires_at IS NULL OR c.expires_at > %s)
                LIMIT 1",
                $this->hashToken($plain_token),
                $now,
                $now
            ),
            ARRAY_A
        );

        if (!$row) {
            return null;
        }

        $this->touchLastUsedIfDue($token_table, $row);
        $row['scopes'] = $this->scope_authorizer->normalizeScopeList(isset($row['scopes']) ? $row['scopes'] : null);

        return $row;
    }

    /**
     * Remove expired or revoked tokens from the dedicated table only when due.
     */
    private function maybePurgeExpired()
    {
        global $wpdb;

        if (!$this->shouldPurgeExpired()) {
            return 0;
        }

        $wpdb->query(
            $wpdb->prepare(
                'DELETE FROM ' . $this->schema->accessTokenTableName() . ' WHERE revoked_at IS NOT NULL OR expires_at <= %s',
                current_time('mysql')
            )
        );

        $this->markPurgeComplete();

        return 1;
    }

    /**
     * Met a jour last_used_at seulement quand cela apporte de l'information utile.
     *
     * @param array<string,mixed> $row
     */
    private function touchLastUsedIfDue($token_table, array $row)
    {
        global $wpdb;

        $last_used_at = isset($row['last_used_at']) ? (string) $row['last_used_at'] : '';
        $touch_before = wp_date('Y-m-d H:i:s', time() - self::LAST_USED_TOUCH_INTERVAL, wp_timezone());

        if ($last_used_at !== '' && $last_used_at > $touch_before) {
            return;
        }

        $wpdb->update(
            $token_table,
            array('last_used_at' => current_time('mysql')),
            array('id' => (int) $row['token_id']),
            array('%s'),
            array('%d')
        );
    }

    /**
     * Determine whether the periodic purge should run again.
     */
    private function shouldPurgeExpired()
    {
        $last_purge = (int) get_option(self::OPTION_LAST_PURGE, 0);

        return $last_purge <= (time() - self::PURGE_INTERVAL);
    }

    /**
     * Remember the last purge time.
     */
    private function markPurgeComplete()
    {
        update_option(self::OPTION_LAST_PURGE, time(), false);
    }

    /**
     * Stable token hashing stored in SQL.
     */
    private function hashToken($plain_token)
    {
        return hash_hmac('sha256', trim((string) $plain_token), wp_salt('auth'));
    }
}
