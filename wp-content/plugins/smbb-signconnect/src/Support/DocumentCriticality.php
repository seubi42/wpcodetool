<?php

namespace Smbb\SignConnect\Support;

defined('ABSPATH') || exit;

final class DocumentCriticality
{
    const LOW = 'low';
    const MEDIUM = 'medium';
    const CRITICAL = 'critical';

    public static function all()
    {
        return array(self::LOW, self::MEDIUM, self::CRITICAL);
    }

    public static function normalize($value, $default = self::LOW)
    {
        $value = sanitize_key((string) $value);

        return in_array($value, self::all(), true) ? $value : $default;
    }

    public static function labels()
    {
        return array(
            self::LOW => __('Low', 'smbb-signconnect'),
            self::MEDIUM => __('Medium', 'smbb-signconnect'),
            self::CRITICAL => __('Critical', 'smbb-signconnect'),
        );
    }

    public static function label($value)
    {
        $labels = self::labels();
        $value = self::normalize($value);

        return isset($labels[$value]) ? $labels[$value] : $labels[self::LOW];
    }
}
