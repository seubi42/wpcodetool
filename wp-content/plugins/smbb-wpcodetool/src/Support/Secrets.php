<?php

namespace Smbb\WpCodeTool\Support;

defined('ABSPATH') || exit;

/**
 * Chiffrement des valeurs sensibles (clés API, jetons) stockées en base.
 *
 * Format stocké : "enc1:<empreinte>:<base64(nonce + texte chiffré)>", avec libsodium
 * (XSalsa20-Poly1305). L'empreinte (8 caractères) identifie la clé qui a chiffré :
 * on sait ainsi relire une valeur chiffrée avec une ancienne clé connue, et signaler
 * celles qu'aucune clé connue ne peut ouvrir.
 *
 * Clé utilisée, dans l'ordre :
 * 1. la constante SMBB_SECRET_KEY de wp-config.php (base64 de 32 octets, ou toute chaîne longue) ;
 * 2. sinon une clé dérivée des salts WordPress (AUTH_KEY, SECURE_AUTH_KEY), propres au site.
 *
 * Une valeur sans préfixe est considérée comme en clair : elle reste lisible telle quelle.
 */
final class Secrets
{
    const PREFIX = 'enc1:';
    const SOURCE_CONSTANT = 'constant';
    const SOURCE_SALTS = 'salts';

    /** @var array<string,array{key:string,source:string}>|null */
    private static $keys = null;

    public static function available()
    {
        return function_exists('sodium_crypto_secretbox') && self::currentKey() !== null;
    }

    /**
     * Source de la clé courante : constant, salts, ou '' si aucune.
     */
    public static function source()
    {
        $key = self::currentKey();

        return $key ? $key['source'] : '';
    }

    /**
     * Empreinte de la clé courante (8 caractères hexadécimaux).
     */
    public static function currentFingerprint()
    {
        $keys = self::keys();

        return $keys ? (string) key($keys) : '';
    }

    public static function isEncrypted($value)
    {
        return is_string($value) && strpos($value, self::PREFIX) === 0;
    }

    /**
     * Empreinte de la clé qui a chiffré une valeur ('' si la valeur est en clair).
     */
    public static function fingerprintOf($value)
    {
        if (!self::isEncrypted($value)) {
            return '';
        }

        $parts = explode(':', $value, 3);

        return isset($parts[1]) ? $parts[1] : '';
    }

    /**
     * État d'une valeur : empty, plain, current (clé courante), known (ancienne clé connue), unknown.
     */
    public static function state($value)
    {
        if ($value === null || $value === '') {
            return 'empty';
        }

        if (!self::isEncrypted($value)) {
            return 'plain';
        }

        $fingerprint = self::fingerprintOf($value);

        if ($fingerprint === self::currentFingerprint()) {
            return 'current';
        }

        return isset(self::keys()[$fingerprint]) ? 'known' : 'unknown';
    }

    /**
     * Chiffre une valeur avec la clé courante. Valeur vide, déjà chiffrée avec la clé
     * courante, ou chiffrement indisponible : renvoyée telle quelle.
     */
    public static function encrypt($value)
    {
        if ($value === null || $value === '' || !is_scalar($value) || !self::available()) {
            return $value;
        }

        $value = (string) $value;

        if (self::isEncrypted($value)) {
            if (self::state($value) !== 'known') {
                return $value;
            }

            $plain = self::decrypt($value);

            if ($plain === null) {
                return $value;
            }

            $value = $plain;
        }

        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $key = self::currentKey();

        return self::PREFIX . self::currentFingerprint() . ':' . base64_encode($nonce . sodium_crypto_secretbox($value, $nonce, $key['key']));
    }

    /**
     * Déchiffre une valeur. Valeur en clair : renvoyée telle quelle.
     * Valeur chiffrée qu'aucune clé connue n'ouvre : null.
     */
    public static function decrypt($value)
    {
        if (!self::isEncrypted($value)) {
            return $value;
        }

        if (!function_exists('sodium_crypto_secretbox_open')) {
            return null;
        }

        $parts = explode(':', (string) $value, 3);
        $keys = self::keys();

        if (count($parts) !== 3 || !isset($keys[$parts[1]])) {
            return null;
        }

        $raw = base64_decode($parts[2], true);

        if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return null;
        }

        $plain = sodium_crypto_secretbox_open(
            substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            $keys[$parts[1]]['key']
        );

        return $plain === false ? null : $plain;
    }

    /**
     * Nouvelle clé aléatoire à placer dans wp-config.php (base64 de 32 octets).
     */
    public static function generateKey()
    {
        return base64_encode(random_bytes(32));
    }

    /**
     * Clés connues, la courante en premier : empreinte => clé.
     *
     * @return array<string,array{key:string,source:string}>
     */
    private static function keys()
    {
        if (self::$keys !== null) {
            return self::$keys;
        }

        self::$keys = array();

        if (!function_exists('sodium_crypto_generichash')) {
            return self::$keys;
        }

        if (defined('SMBB_SECRET_KEY') && is_string(SMBB_SECRET_KEY) && strlen(SMBB_SECRET_KEY) >= 16) {
            $decoded = base64_decode(SMBB_SECRET_KEY, true);
            $material = $decoded !== false && strlen($decoded) === 32 ? $decoded : SMBB_SECRET_KEY;
            self::add(sodium_crypto_generichash('smbb-codetool|' . $material, '', 32), self::SOURCE_CONSTANT);
        }

        $salts = (defined('AUTH_KEY') ? AUTH_KEY : '') . (defined('SECURE_AUTH_KEY') ? SECURE_AUTH_KEY : '');

        if (strlen($salts) >= 32 && strpos($salts, 'put your unique phrase here') === false) {
            self::add(sodium_crypto_generichash('smbb-codetool-salts|' . $salts, '', 32), self::SOURCE_SALTS);
        }

        return self::$keys;
    }

    private static function add($key, $source)
    {
        $fingerprint = substr(bin2hex(sodium_crypto_generichash('fingerprint|' . $key, '', 16)), 0, 8);

        if (!isset(self::$keys[$fingerprint])) {
            self::$keys[$fingerprint] = array('key' => $key, 'source' => $source);
        }
    }

    private static function currentKey()
    {
        $keys = self::keys();

        return $keys ? reset($keys) : null;
    }
}
