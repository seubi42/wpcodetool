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
    const SENDER_SIGNATURE = 'sender_signature';
    const SENDER_FULL_NAME = 'sender_full_name';
    const SENDER_LAST_NAME = 'sender_last_name';
    const SENDER_FIRST_NAME = 'sender_first_name';
    const SENDER_STAMP = 'sender_stamp';

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
            self::SENDER_SIGNATURE,
            self::SENDER_FULL_NAME,
            self::SENDER_LAST_NAME,
            self::SENDER_FIRST_NAME,
            self::SENDER_STAMP,
        );
    }

    public static function senderTypes()
    {
        return array(
            self::SENDER_SIGNATURE,
            self::SENDER_FULL_NAME,
            self::SENDER_LAST_NAME,
            self::SENDER_FIRST_NAME,
            self::SENDER_STAMP,
        );
    }

    public static function requiresSenderSignature($type)
    {
        return self::isSenderType($type);
    }

    public static function isSenderType($type)
    {
        return in_array(self::normalize($type), self::senderTypes(), true);
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
            case self::SENDER_SIGNATURE:
                return __('Sender signature', 'smbb-signconnect');
            case self::SENDER_FULL_NAME:
                return __('Sender full name', 'smbb-signconnect');
            case self::SENDER_LAST_NAME:
                return __('Sender last name', 'smbb-signconnect');
            case self::SENDER_FIRST_NAME:
                return __('Sender first name', 'smbb-signconnect');
            case self::SENDER_STAMP:
                return __('Sender stamp', 'smbb-signconnect');
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
