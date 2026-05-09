<?php

namespace Smbb\SignConnect\Service;

defined('ABSPATH') || exit;

/**
 * Extraction texte volontairement legere pour limiter le cout IA.
 *
 * Cette classe ne cherche pas a remplacer un vrai moteur OCR/PDF. Elle extrait
 * les textes embarques dans les flux PDF courants, notamment les flux compresses
 * en FlateDecode. Pour un PDF scanne sous forme d'image, elle retournera souvent
 * une chaine vide, ce qui est acceptable pour le mode economique.
 */
final class PdfTextExtractor
{
    public function extract($pdf_path, $max_length = 6000)
    {
        if (!is_readable($pdf_path)) {
            return '';
        }

        $content = file_get_contents($pdf_path);

        if (!is_string($content) || $content === '') {
            return '';
        }

        $chunks = array($content);

        foreach ($this->extractStreams($content) as $stream) {
            $chunks[] = $stream;
        }

        $text = $this->decodeTextChunks($chunks);
        $text = preg_replace('/[ \t]+/', ' ', $text);
        $text = preg_replace('/\s{2,}/', "\n", (string) $text);
        $text = trim((string) $text);

        if ($text === '') {
            return '';
        }

        if (function_exists('mb_substr')) {
            return mb_substr($text, 0, max(500, (int) $max_length));
        }

        return substr($text, 0, max(500, (int) $max_length));
    }

    private function extractStreams($content)
    {
        $streams = array();

        if (!preg_match_all('/<<(.*?)>>\s*stream\r?\n(.*?)\r?\nendstream/s', $content, $matches, PREG_SET_ORDER)) {
            return $streams;
        }

        foreach ($matches as $match) {
            $dictionary = isset($match[1]) ? (string) $match[1] : '';
            $stream = isset($match[2]) ? (string) $match[2] : '';

            if (stripos($dictionary, 'FlateDecode') !== false && function_exists('gzuncompress')) {
                $decoded = @gzuncompress($stream);

                if (is_string($decoded) && $decoded !== '') {
                    $streams[] = $decoded;
                    continue;
                }
            }

            $streams[] = $stream;
        }

        return $streams;
    }

    private function decodeTextChunks(array $chunks)
    {
        $parts = array();

        foreach ($chunks as $chunk) {
            if (!is_string($chunk) || $chunk === '') {
                continue;
            }

            $parts = array_merge($parts, $this->extractLiteralStrings($chunk));
            $parts = array_merge($parts, $this->extractHexStrings($chunk));
        }

        $parts = array_filter(array_map('trim', $parts));

        return implode("\n", array_unique($parts));
    }

    private function extractLiteralStrings($chunk)
    {
        $values = array();

        if (!preg_match_all('/\((?:\\\\.|[^\\\\()])*\)\s*(?:Tj|TJ|\'|")/s', $chunk, $matches)) {
            return $values;
        }

        foreach ($matches[0] as $match) {
            if (preg_match('/^\((.*)\)\s*(?:Tj|TJ|\'|")/s', $match, $value)) {
                $decoded = $this->decodePdfString((string) $value[1]);

                if ($this->looksLikeText($decoded)) {
                    $values[] = $decoded;
                }
            }
        }

        return $values;
    }

    private function extractHexStrings($chunk)
    {
        $values = array();

        if (!preg_match_all('/<([0-9A-Fa-f\s]{4,})>\s*(?:Tj|TJ)/', $chunk, $matches)) {
            return $values;
        }

        foreach ($matches[1] as $hex) {
            $hex = preg_replace('/\s+/', '', (string) $hex);

            if ($hex === '' || strlen($hex) % 2 !== 0) {
                continue;
            }

            $decoded = @hex2bin($hex);

            if (!is_string($decoded) || $decoded === '') {
                continue;
            }

            $decoded = str_replace("\0", '', $decoded);

            if ($this->looksLikeText($decoded)) {
                $values[] = $decoded;
            }
        }

        return $values;
    }

    private function decodePdfString($value)
    {
        $value = preg_replace_callback('/\\\\([nrtbf\\\\()])/', function ($match) {
            switch ($match[1]) {
                case 'n':
                    return "\n";
                case 'r':
                    return "\r";
                case 't':
                    return "\t";
                case 'b':
                    return "\b";
                case 'f':
                    return "\f";
                default:
                    return $match[1];
            }
        }, (string) $value);

        $value = preg_replace_callback('/\\\\([0-7]{1,3})/', function ($match) {
            return chr(octdec($match[1]));
        }, (string) $value);

        return trim((string) $value);
    }

    private function looksLikeText($value)
    {
        $value = trim((string) $value);

        if ($value === '' || strlen($value) < 2) {
            return false;
        }

        return preg_match('/[A-Za-z0-9À-ÿ]/', $value) === 1;
    }
}
