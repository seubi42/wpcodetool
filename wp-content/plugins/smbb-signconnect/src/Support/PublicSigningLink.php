<?php

namespace Smbb\SignConnect\Support;

defined('ABSPATH') || exit;

/**
 * Encode/decode l'identifiant document utilisé dans les liens publics.
 *
 * Ce n\'est pas une sécurité cryptographique : la sécurité vient du token.
 * L'encodage sert seulement à éviter un id brut trop visible dans l'URL.
 */
final class PublicSigningLink
{
    public static function encodeDocumentId($document_id)
    {
        return rtrim(strtr(base64_encode((string) absint($document_id)), '+/', '-_'), '=');
    }

    public static function decodeDocumentId($encoded)
    {
        $encoded = strtr((string) $encoded, '-_', '+/');
        $padding = strlen($encoded) % 4;

        if ($padding > 0) {
            $encoded .= str_repeat('=', 4 - $padding);
        }

        $decoded = base64_decode($encoded, true);

        return $decoded !== false ? absint($decoded) : 0;
    }

    public static function url($document_id, $token)
    {
        return add_query_arg(array(
            'signconnect_sign' => self::encodeDocumentId($document_id),
            'signconnect_token' => rawurlencode((string) $token),
        ), SignConnectSettings::signingPageUrl());
    }
}
