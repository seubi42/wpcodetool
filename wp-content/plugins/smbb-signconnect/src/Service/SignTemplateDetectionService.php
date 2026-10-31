<?php

namespace Smbb\SignConnect\Service;

use setasign\Fpdi\Fpdi;
use Smbb\SignConnect\Repository\DocumentRepository;
use Smbb\SignConnect\Repository\SignTemplateRepository;
use Smbb\SignConnect\Repository\SignatureFieldRepository;
use Smbb\SignConnect\Support\SignatureFieldType;

defined('ABSPATH') || exit;

final class SignTemplateDetectionService
{
    private $templates;
    private $signature_fields;
    private $documents;
    private $extractor;

    public function __construct(
        SignTemplateRepository $templates = null,
        SignatureFieldRepository $signature_fields = null,
        DocumentRepository $documents = null,
        PdfPositionedTextExtractor $extractor = null
    ) {
        $this->templates = $templates ?: new SignTemplateRepository();
        $this->signature_fields = $signature_fields ?: new SignatureFieldRepository();
        $this->documents = $documents ?: new DocumentRepository();
        $this->extractor = $extractor ?: new PdfPositionedTextExtractor();
    }

    public function applyAfterUpload($document_id, $user_id, array $file)
    {
        $debug = array(
            'document_id' => (int) $document_id,
            'filename' => isset($file['name']) ? (string) $file['name'] : '',
            'templates' => array(),
        );

        if ($this->signature_fields->listForDocument($document_id)) {
            return array('matched' => false, 'reason' => 'document_already_has_fields', 'debug' => $debug);
        }

        $pdf_path = isset($file['tmp_name']) ? (string) $file['tmp_name'] : '';

        if ($pdf_path === '' || !is_readable($pdf_path)) {
            return array('matched' => false, 'reason' => 'unreadable_pdf', 'debug' => $debug);
        }

        $templates = $this->templates->listActive();
        $debug['active_template_count'] = count($templates);

        if (!$templates) {
            return array('matched' => false, 'reason' => 'no_active_template', 'debug' => $debug);
        }

        $extracted = $this->extractor->extract($pdf_path);

        if (empty($extracted['success'])) {
            $debug['extraction'] = array(
                'success' => false,
                'error' => isset($extracted['error']) ? (string) $extracted['error'] : '',
                'pdftotext' => isset($extracted['pdftotext']) && is_array($extracted['pdftotext']) ? $extracted['pdftotext'] : array(),
            );

            return array(
                'matched' => false,
                'reason' => 'text_position_extraction_failed',
                'error' => isset($extracted['error']) ? (string) $extracted['error'] : '',
                'debug' => $debug,
            );
        }

        $page_count = max($this->pdfPageCount($file), isset($extracted['page_count']) ? (int) $extracted['page_count'] : 1);
        $debug['extraction'] = array(
            'success' => true,
            'page_count' => $page_count,
            'word_count_by_page' => $this->wordCountByPage($extracted),
        );

        foreach ($templates as $template) {
            $match = $this->templateMatches($template, $extracted, $page_count);
            $template_debug = array(
                'template_id' => isset($template['id']) ? (int) $template['id'] : 0,
                'template_name' => isset($template['name']) ? (string) $template['name'] : '',
                'matched' => !empty($match['matched']),
                'reason' => isset($match['reason']) ? (string) $match['reason'] : '',
                'checks' => isset($match['checks']) ? $match['checks'] : array(),
            );

            if (empty($match['matched'])) {
                $debug['templates'][] = $template_debug;
                continue;
            }

            $fields = $this->signatureFieldsFromTemplate($template, $page_count);
            $template_debug['field_count'] = count($fields);

            if (!$fields) {
                $template_debug['matched'] = false;
                $template_debug['reason'] = 'template_has_no_signature_zones';
                $debug['templates'][] = $template_debug;
                continue;
            }

            $saved = $this->signature_fields->saveForDocument($document_id, $fields);

            if ($saved === false) {
                $template_debug['matched'] = false;
                $template_debug['reason'] = 'field_save_failed';
                $debug['templates'][] = $template_debug;

                return array('matched' => false, 'reason' => 'field_save_failed', 'debug' => $debug);
            }

            $this->documents->markZoneReady($document_id, $user_id);
            $this->documents->applyTemplateCriticality($document_id, $user_id, $template);
            $debug['templates'][] = $template_debug;

            return array(
                'matched' => true,
                'template_id' => isset($template['id']) ? (int) $template['id'] : 0,
                'template_name' => isset($template['name']) ? (string) $template['name'] : '',
                'criticality' => !empty($template['criticality']) ? (string) $template['criticality'] : '',
                'field_count' => count($fields),
                'checks' => isset($match['checks']) ? $match['checks'] : array(),
                'debug' => $debug,
            );
        }

        return array('matched' => false, 'reason' => 'no_template_match', 'debug' => $debug);
    }

    private function templateMatches(array $template, array $extracted, $page_count)
    {
        $zones = $this->decodeZones(isset($template['detection_zones']) ? $template['detection_zones'] : '');

        if (!$zones) {
            return array('matched' => false, 'reason' => 'template_has_no_detection_zones');
        }

        $checks = array();

        foreach ($zones as $zone) {
            $expected = isset($zone['expected_text']) ? trim((string) $zone['expected_text']) : '';

            if ($expected === '') {
                return array('matched' => false, 'reason' => 'empty_expected_text');
            }

            $page_number = $this->resolvePageNumber($zone, $page_count);
            $actual = $this->textInsideZone($extracted, $page_number, $zone);
            $matched = $this->textMatches($actual, $expected);
            $checks[] = array(
                'page_number' => $page_number,
                'page_rule' => isset($zone['page_rule']) ? (string) $zone['page_rule'] : 'absolute',
                'page_from_end' => isset($zone['page_from_end']) ? (int) $zone['page_from_end'] : 0,
                'zone' => array(
                    'x' => $this->ratio(isset($zone['x']) ? $zone['x'] : 0),
                    'y' => $this->ratio(isset($zone['y']) ? $zone['y'] : 0),
                    'width' => $this->ratio(isset($zone['width']) ? $zone['width'] : 0),
                    'height' => $this->ratio(isset($zone['height']) ? $zone['height'] : 0),
                ),
                'expected_text' => $expected,
                'actual_text' => $actual,
                'normalized_expected' => $this->normalizeText($expected),
                'normalized_actual' => $this->normalizeText($actual),
                'matched' => $matched,
            );

            if (!$matched) {
                return array('matched' => false, 'reason' => 'expected_text_not_found', 'checks' => $checks);
            }
        }

        return array('matched' => true, 'checks' => $checks);
    }

    private function signatureFieldsFromTemplate(array $template, $page_count)
    {
        $zones = $this->decodeZones(isset($template['signature_zones']) ? $template['signature_zones'] : '');
        $fields = array();

        foreach ($zones as $zone) {
            $field_type = SignatureFieldType::normalize(isset($zone['field_type']) ? $zone['field_type'] : SignatureFieldType::SIGNATURE);
            $width = $this->ratio(isset($zone['width']) ? $zone['width'] : 0);
            $height = $this->ratio(isset($zone['height']) ? $zone['height'] : 0);

            if ($width <= 0 || $height <= 0) {
                continue;
            }

            $fields[] = array(
                'page_number' => $this->resolvePageNumber($zone, $page_count),
                'x' => min(1 - $width, $this->ratio(isset($zone['x']) ? $zone['x'] : 0)),
                'y' => min(1 - $height, $this->ratio(isset($zone['y']) ? $zone['y'] : 0)),
                'width' => $width,
                'height' => $height,
                'field_type' => $field_type,
                'label' => isset($zone['label']) && (string) $zone['label'] !== '' ? (string) $zone['label'] : SignatureFieldType::label($field_type),
            );
        }

        return $fields;
    }

    private function textInsideZone(array $extracted, $page_number, array $zone)
    {
        if (empty($extracted['pages'][$page_number]['words']) || !is_array($extracted['pages'][$page_number]['words'])) {
            return '';
        }

        $x = $this->ratio(isset($zone['x']) ? $zone['x'] : 0);
        $y = $this->ratio(isset($zone['y']) ? $zone['y'] : 0);
        $width = $this->ratio(isset($zone['width']) ? $zone['width'] : 0);
        $height = $this->ratio(isset($zone['height']) ? $zone['height'] : 0);
        $right = $x + $width;
        $bottom = $y + $height;
        $words = array();

        foreach ($extracted['pages'][$page_number]['words'] as $word) {
            $center_x = isset($word['center_x']) ? (float) $word['center_x'] : 0;
            $center_y = isset($word['center_y']) ? (float) $word['center_y'] : 0;

            if ($center_x >= $x && $center_x <= $right && $center_y >= $y && $center_y <= $bottom) {
                $words[] = isset($word['text']) ? (string) $word['text'] : '';
            }
        }

        return trim(preg_replace('/\s+/', ' ', implode(' ', array_filter($words))));
    }

    private function textMatches($actual, $expected)
    {
        $actual = $this->normalizeText($actual);
        $expected = $this->normalizeText($expected);

        if ($actual === '' || $expected === '') {
            return false;
        }

        if (strpos($actual, $expected) !== false) {
            return true;
        }

        $tokens = preg_split('/\s+/', $expected, -1, PREG_SPLIT_NO_EMPTY);

        if (!$tokens) {
            return false;
        }

        foreach ($tokens as $token) {
            if (strlen($token) < 2) {
                continue;
            }

            if (strpos($actual, $token) === false) {
                return false;
            }
        }

        return true;
    }

    private function normalizeText($text)
    {
        $text = strtolower(remove_accents((string) $text));
        $text = preg_replace('/[^a-z0-9]+/', ' ', $text);

        return trim(preg_replace('/\s+/', ' ', (string) $text));
    }

    private function resolvePageNumber(array $zone, $page_count)
    {
        $page_count = max(1, (int) $page_count);

        if (!empty($zone['page_rule']) && (string) $zone['page_rule'] === 'from_end') {
            $from_end = isset($zone['page_from_end']) ? max(1, (int) $zone['page_from_end']) : 1;

            return max(1, min($page_count, $page_count - $from_end + 1));
        }

        $page_number = isset($zone['page_number']) ? max(1, (int) $zone['page_number']) : 1;

        return max(1, min($page_count, $page_number));
    }

    private function decodeZones($value)
    {
        $decoded = is_string($value) && $value !== '' ? json_decode($value, true) : array();

        return is_array($decoded) ? array_values(array_filter($decoded, 'is_array')) : array();
    }

    private function wordCountByPage(array $extracted)
    {
        $counts = array();
        $pages = isset($extracted['pages']) && is_array($extracted['pages']) ? $extracted['pages'] : array();

        foreach ($pages as $page_number => $page) {
            $counts[(string) $page_number] = !empty($page['words']) && is_array($page['words']) ? count($page['words']) : 0;
        }

        return $counts;
    }

    private function ratio($value)
    {
        if (!is_numeric($value)) {
            return 0;
        }

        $value = (float) $value;

        if ($value > 1 && $value <= 100) {
            $value = $value / 100;
        }

        return max(0, min(1, $value));
    }

    private function pdfPageCount(array $file)
    {
        if (!class_exists(Fpdi::class) || empty($file['tmp_name']) || !file_exists($file['tmp_name'])) {
            return 1;
        }

        try {
            $pdf = new Fpdi();

            return max(1, (int) $pdf->setSourceFile((string) $file['tmp_name']));
        } catch (\Throwable $exception) {
            return 1;
        }
    }
}
