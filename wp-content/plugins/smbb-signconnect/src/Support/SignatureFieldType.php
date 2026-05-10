<?php

namespace Smbb\SignConnect\Support;

defined('ABSPATH') || exit;

final class SignatureFieldType
{
    const SIGNATURE = 'signature';
    const LAST_NAME = 'last_name';
    const FIRST_NAME = 'first_name';
    const FULL_NAME = 'full_name';
    const PLACE = 'place';
    const DATE = 'date';
    const APPROVAL = 'approval';

    public static function all()
    {
        return array(
            self::SIGNATURE,
            self::LAST_NAME,
            self::FIRST_NAME,
            self::FULL_NAME,
            self::PLACE,
            self::DATE,
            self::APPROVAL,
        );
    }

    public static function normalize($type)
    {
        $type = sanitize_key((string) $type);

        return in_array($type, self::all(), true) ? $type : self::SIGNATURE;
    }

    public static function label($type)
    {
        switch (self::normalize($type)) {
            case self::LAST_NAME:
                return __('Last name', 'smbb-signconnect');
            case self::FIRST_NAME:
                return __('First name', 'smbb-signconnect');
            case self::FULL_NAME:
                return __('Full name', 'smbb-signconnect');
            case self::PLACE:
                return __('Place', 'smbb-signconnect');
            case self::DATE:
                return __('Date', 'smbb-signconnect');
            case self::APPROVAL:
                return __('Good for approval', 'smbb-signconnect');
            case self::SIGNATURE:
            default:
                return __('Signature', 'smbb-signconnect');
        }
    }

    public static function labels()
    {
        $labels = array();

        foreach (self::all() as $type) {
            $labels[$type] = self::label($type);
        }

        return $labels;
    }
}
