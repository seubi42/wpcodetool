<?php

namespace Smbb\SignConnect\Service;

defined('ABSPATH') || exit;

/**
 * Repere la page la plus probable pour placer une signature.
 *
 * On parcourt de la dernière page vers la premiere. Quand l'outil systeme
 * pdftotext est disponible, on extrait le texte page par page. Sinon on garde
 * une règle prudente et prévisible : dernière page du document.
 */
final class PdfSignaturePageLocator
{
    private $keywords = array(
        'signature',
        'signatures',
        'signataire',
        'signer',
        'signée',
        'signe',
        'bon pour accord',
        'accord et execution',
        'visa',
        'paraphe',
    );

    public function locate($pdf_path, $page_count)
    {
        $page_count = max(1, (int) $page_count);
        $reason = 'fallback_last_page';

        for ($page = $page_count; $page >= 1; $page--) {
            $text = $this->extractPageText($pdf_path, $page);

            if ($text === '') {
                continue;
            }

            if ($this->containsSignatureKeyword($text)) {
                return array(
                    'page_number' => $page,
                    'reason' => 'keyword_match',
                    'text_preview' => $this->preview($text),
                );
            }
        }

        return array(
            'page_number' => $page_count,
            'reason' => $reason,
            'text_preview' => '',
        );
    }

    private function extractPageText($pdf_path, $page)
    {
        if (!is_readable($pdf_path) || !function_exists('proc_open')) {
            return '';
        }

        $command = 'pdftotext -f ' . (int) $page . ' -l ' . (int) $page . ' -enc UTF-8 -q ' . escapeshellarg($pdf_path) . ' -';
        $descriptor_spec = array(
            0 => array('pipe', 'r'),
            1 => array('pipe', 'w'),
            2 => array('pipe', 'w'),
        );
        $process = @proc_open($command, $descriptor_spec, $pipes);

        if (!is_resource($process)) {
            return '';
        }

        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($process);

        if ($status !== 0 || !is_string($output)) {
            return '';
        }

        return trim($output);
    }

    private function containsSignatureKeyword($text)
    {
        $text = strtolower(remove_accents((string) $text));

        foreach ($this->keywords as $keyword) {
            if (strpos($text, strtolower(remove_accents($keyword))) !== false) {
                return true;
            }
        }

        return false;
    }

    private function preview($text)
    {
        $text = preg_replace('/\s+/', ' ', trim((string) $text));

        if (function_exists('mb_substr')) {
            return mb_substr($text, 0, 500);
        }

        return substr($text, 0, 500);
    }
}
