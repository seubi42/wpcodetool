<?php

namespace Smbb\SignConnect\Support;

defined('ABSPATH') || exit;

/**
 * Formatage centralise des tailles de fichiers.
 *
 * WordPress fournit size_format() dans le contexte normal du site. Le fallback garde
 * quand meme le plugin lisible dans les tests ou dans des contextes partiellement charges.
 */
final class FileSizeFormatter
{
    public static function format($bytes)
    {
        $bytes = max(0, (int) $bytes);

        if (function_exists('size_format')) {
            return size_format($bytes, 1);
        }

        if ($bytes >= 1073741824) {
            return round($bytes / 1073741824, 1) . ' GB';
        }

        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 1) . ' MB';
        }

        if ($bytes >= 1024) {
            return round($bytes / 1024, 1) . ' KB';
        }

        return $bytes . ' B';
    }
}
