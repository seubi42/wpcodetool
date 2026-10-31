<?php

namespace Smbb\SignConnect\Support;

defined('ABSPATH') || exit;

final class CertificationMethod
{
    const NONE = 'none';
    const SELF_SIGNED = 'self_signed_certificate';
    const LOCAL_CERTIFICATE = 'local_certificate';
    const YOUSIGN_QUALIFIED_SEAL = 'yousign_qualified_seal';
    const YOUSIGN_SIGNATURE_FLOW = 'yousign_signature_flow';
    const UNIVERSIGN_SIGNATURE_FLOW = 'universign_signature_flow';

    public static function all()
    {
        return array(self::NONE, self::SELF_SIGNED, self::LOCAL_CERTIFICATE, self::YOUSIGN_QUALIFIED_SEAL, self::YOUSIGN_SIGNATURE_FLOW, self::UNIVERSIGN_SIGNATURE_FLOW);
    }

    public static function normalize($value, $default = self::NONE)
    {
        $value = sanitize_key((string) $value);

        if ($value === 'signconnect') {
            return self::SELF_SIGNED;
        }

        if ($value === 'yousign_seal') {
            return self::YOUSIGN_QUALIFIED_SEAL;
        }

        return in_array($value, self::all(), true) ? $value : $default;
    }

    public static function labels()
    {
        return array(
            self::NONE => __('No certification', 'smbb-signconnect'),
            self::SELF_SIGNED => __('Self-signed certificate', 'smbb-signconnect'),
            self::LOCAL_CERTIFICATE => __('Local certificate', 'smbb-signconnect'),
            self::YOUSIGN_QUALIFIED_SEAL => __('Qualified Yousign seal', 'smbb-signconnect'),
            self::YOUSIGN_SIGNATURE_FLOW => __('Yousign signing flow', 'smbb-signconnect'),
            self::UNIVERSIGN_SIGNATURE_FLOW => __('Universign signing flow', 'smbb-signconnect'),
        );
    }

    public static function label($value)
    {
        $labels = self::labels();
        $value = self::normalize($value);

        return isset($labels[$value]) ? $labels[$value] : $labels[self::NONE];
    }
}
