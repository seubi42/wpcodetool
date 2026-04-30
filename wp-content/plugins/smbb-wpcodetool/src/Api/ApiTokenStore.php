<?php

namespace Smbb\WpCodeTool\Api;

defined('ABSPATH') || exit;

/**
 * Stocke les bearer tokens geres directement par CodeTool dans wp_options.
 */
final class ApiTokenStore
{
    private const OPTION_NAME = 'smbb_wpcodetool_api_tokens';
    private const LAST_USED_TOUCH_INTERVAL = 900;

    private $tokens_cache = null;

    /**
     * Return all tokens as an associative array keyed by id.
     */
    public function all()
    {
        if ($this->tokens_cache !== null) {
            return $this->tokens_cache;
        }

        $value = get_option(self::OPTION_NAME, array());
        $this->tokens_cache = is_array($value) ? $value : array();

        return $this->tokens_cache;
    }

    /**
     * Human list sorted by creation date descending.
     */
    public function listing()
    {
        $tokens = $this->all();

        uasort($tokens, function ($left, $right) {
            $left_date = isset($left['created_at']) ? (string) $left['created_at'] : '';
            $right_date = isset($right['created_at']) ? (string) $right['created_at'] : '';

            return strcmp($right_date, $left_date);
        });

        return $tokens;
    }

    /**
     * Indicates whether at least one active managed token exists.
     */
    public function hasActiveTokens()
    {
        foreach ($this->all() as $token) {
            if (!empty($token['active'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Create a new token and return the plain token once.
     */
    public function create($label)
    {
        $label = trim((string) $label);
        $label = $label !== '' ? $label : __('API token', 'smbb-wpcodetool');
        $plain_token = 'ct_' . wp_generate_password(40, false, false);
        $id = function_exists('wp_generate_uuid4') ? wp_generate_uuid4() : uniqid('token_', true);
        $now = current_time('mysql');
        $tokens = $this->all();

        $tokens[$id] = array(
            'id' => $id,
            'label' => $label,
            'active' => true,
            'token_hash' => $this->hashToken($plain_token),
            'token_prefix' => substr($plain_token, 0, 12),
            'created_at' => $now,
            'updated_at' => $now,
            'last_used_at' => '',
        );

        $this->persistTokens($tokens);

        return array(
            'id' => $id,
            'label' => $label,
            'plain_token' => $plain_token,
        );
    }

    /**
     * Activate or deactivate one token.
     */
    public function setActive($id, $active)
    {
        $tokens = $this->all();

        if (empty($tokens[$id]) || !is_array($tokens[$id])) {
            return false;
        }

        $tokens[$id]['active'] = (bool) $active;
        $tokens[$id]['updated_at'] = current_time('mysql');

        $this->persistTokens($tokens);

        return true;
    }

    /**
     * Delete one token.
     */
    public function delete($id)
    {
        $tokens = $this->all();

        if (!isset($tokens[$id])) {
            return false;
        }

        unset($tokens[$id]);
        $this->persistTokens($tokens);

        return true;
    }

    /**
     * Verify a bearer token and update its last usage metadata.
     */
    public function verify($plain_token)
    {
        $plain_token = trim((string) $plain_token);

        if ($plain_token === '') {
            return false;
        }

        $hash = $this->hashToken($plain_token);
        $tokens = $this->all();

        foreach ($tokens as $id => $token) {
            if (empty($token['active']) || empty($token['token_hash'])) {
                continue;
            }

            if (!hash_equals((string) $token['token_hash'], $hash)) {
                continue;
            }

            if ($this->shouldTouchLastUsed($token)) {
                $tokens[$id]['last_used_at'] = current_time('mysql');
                $tokens[$id]['updated_at'] = current_time('mysql');
                $this->persistTokens($tokens);
            }

            return true;
        }

        return false;
    }

    /**
     * Stable token hash stored in wp_options.
     */
    private function hashToken($plain_token)
    {
        return hash_hmac('sha256', (string) $plain_token, wp_salt('auth'));
    }

    /**
     * Persiste l'etat courant des tokens tout en gardant un cache requete local.
     */
    private function persistTokens(array $tokens)
    {
        $this->tokens_cache = $tokens;

        update_option(self::OPTION_NAME, $tokens, false);
    }

    /**
     * Evite une ecriture wp_options sur chaque requete authentifiee.
     *
     * @param array<string,mixed> $token
     */
    private function shouldTouchLastUsed(array $token)
    {
        $last_used_at = isset($token['last_used_at']) ? (string) $token['last_used_at'] : '';
        $touch_before = wp_date('Y-m-d H:i:s', time() - self::LAST_USED_TOUCH_INTERVAL, wp_timezone());

        return $last_used_at === '' || $last_used_at <= $touch_before;
    }
}
