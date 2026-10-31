<?php

namespace Smbb\SignConnect\Service;

use Smbb\SignConnect\Support\SignConnectSettings;

defined('ABSPATH') || exit;

/**
 * Extracts PDF words with page coordinates through poppler's pdftotext -bbox.
 *
 * Coordinates are normalized to page ratios so they can be compared with the
 * rectangles drawn in the template editor.
 */
final class PdfPositionedTextExtractor
{
    private $last_debug = array();

    public function extract($pdf_path)
    {
        if (!is_readable($pdf_path) || !function_exists('proc_open')) {
            return array('success' => false, 'error' => 'PDF is not readable or proc_open is unavailable.');
        }

        $result = $this->runPdftotext($pdf_path);
        $xml = isset($result['stdout']) ? trim((string) $result['stdout']) : '';
        $this->last_debug = $result;

        if (empty($result['success']) || $xml === '') {
            return array(
                'success' => false,
                'error' => 'pdftotext did not return bbox XML.',
                'pdftotext' => $this->debugSummary($result),
            );
        }

        $parsed = $this->parseXml($xml);

        if (empty($parsed['success'])) {
            $parsed['pdftotext'] = $this->debugSummary($result);
        }

        return $parsed;
    }

    private function runPdftotext($pdf_path)
    {
        $executable = $this->pdftotextExecutable();
        $command = escapeshellarg($executable) . ' -bbox -enc UTF-8 -q ' . escapeshellarg($pdf_path) . ' -';
        $descriptor_spec = array(
            0 => array('pipe', 'r'),
            1 => array('pipe', 'w'),
            2 => array('pipe', 'w'),
        );
        $process = @proc_open($command, $descriptor_spec, $pipes);

        if (!is_resource($process)) {
            return array(
                'success' => false,
                'command' => $command,
                'executable' => $executable,
                'status' => null,
                'stdout' => '',
                'stderr' => 'proc_open could not start pdftotext.',
            );
        }

        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $status = proc_close($process);

        return array(
            'success' => $status === 0 && is_string($output) && trim($output) !== '',
            'command' => $command,
            'executable' => $executable,
            'status' => $status,
            'stdout' => is_string($output) ? $output : '',
            'stderr' => is_string($error) ? $error : '',
        );
    }

    private function pdftotextExecutable()
    {
        $configured = SignConnectSettings::templateDetectionPdftotextPath();

        if ($configured !== '') {
            return $configured;
        }

        foreach (array('/usr/bin/pdftotext', '/usr/local/bin/pdftotext', '/opt/local/bin/pdftotext') as $candidate) {
            if (is_executable($candidate)) {
                return $candidate;
            }
        }

        return 'pdftotext';
    }

    private function debugSummary(array $result)
    {
        return array(
            'executable' => isset($result['executable']) ? (string) $result['executable'] : '',
            'command' => isset($result['command']) ? (string) $result['command'] : '',
            'status' => array_key_exists('status', $result) ? $result['status'] : null,
            'stdout_length' => isset($result['stdout']) ? strlen((string) $result['stdout']) : 0,
            'stdout_preview' => isset($result['stdout']) ? $this->preview((string) $result['stdout']) : '',
            'stderr' => isset($result['stderr']) ? $this->preview((string) $result['stderr']) : '',
        );
    }

    private function preview($value)
    {
        $value = trim(preg_replace('/\s+/', ' ', (string) $value));

        if (function_exists('mb_substr')) {
            return mb_substr($value, 0, 700);
        }

        return substr($value, 0, 700);
    }

    private function parseXml($xml)
    {
        if (!function_exists('simplexml_load_string')) {
            return array('success' => false, 'error' => 'SimpleXML extension is unavailable.');
        }

        $previous = libxml_use_internal_errors(true);
        $document = simplexml_load_string((string) $xml);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (!$document) {
            return array('success' => false, 'error' => 'Unable to parse pdftotext bbox XML.');
        }

        $pages = $document->xpath('//*[local-name()="page"]');

        if (!is_array($pages) || !$pages) {
            return array('success' => false, 'error' => 'No page found in pdftotext bbox XML.');
        }

        $result_pages = array();
        $page_number = 1;

        foreach ($pages as $page) {
            $attrs = $page->attributes();
            $width = isset($attrs['width']) ? (float) $attrs['width'] : 0;
            $height = isset($attrs['height']) ? (float) $attrs['height'] : 0;

            if ($width <= 0 || $height <= 0) {
                $page_number++;
                continue;
            }

            $words = array();
            $word_nodes = $page->xpath('.//*[local-name()="word"]');

            if (is_array($word_nodes)) {
                foreach ($word_nodes as $word) {
                    $word_attrs = $word->attributes();
                    $text = trim(html_entity_decode((string) $word, ENT_QUOTES | ENT_HTML5, 'UTF-8'));

                    if ($text === '') {
                        continue;
                    }

                    $x_min = isset($word_attrs['xMin']) ? (float) $word_attrs['xMin'] : 0;
                    $y_min = isset($word_attrs['yMin']) ? (float) $word_attrs['yMin'] : 0;
                    $x_max = isset($word_attrs['xMax']) ? (float) $word_attrs['xMax'] : 0;
                    $y_max = isset($word_attrs['yMax']) ? (float) $word_attrs['yMax'] : 0;

                    $words[] = array(
                        'text' => $text,
                        'x' => $x_min / $width,
                        'y' => $y_min / $height,
                        'width' => max(0, ($x_max - $x_min) / $width),
                        'height' => max(0, ($y_max - $y_min) / $height),
                        'center_x' => (($x_min + $x_max) / 2) / $width,
                        'center_y' => (($y_min + $y_max) / 2) / $height,
                    );
                }
            }

            $result_pages[$page_number] = array(
                'width' => $width,
                'height' => $height,
                'words' => $words,
            );
            $page_number++;
        }

        return array(
            'success' => true,
            'page_count' => max(1, $page_number - 1),
            'pages' => $result_pages,
        );
    }
}
