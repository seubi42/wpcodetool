<?php

namespace Smbb\SignConnect\Service;

use setasign\Fpdi\Fpdi;
use Smbb\SignConnect\Repository\DocumentRepository;
use Smbb\SignConnect\Repository\SignatureFieldRepository;
use Smbb\SignConnect\Support\SignConnectSettings;

defined('ABSPATH') || exit;

/**
 * Enrichissement IA lance juste apres le depot du PDF.
 *
 * Le service est volontairement non bloquant pour le parcours utilisateur :
 * si OpenAI est lent, indisponible ou retourne un JSON imparfait, le document
 * reste depose et l'utilisateur peut continuer normalement.
 */
final class DocumentAiEnrichmentService
{
    private $documents;
    private $signature_fields;
    private $prompt_builder;
    private $openai;

    public function __construct(
        DocumentRepository $documents = null,
        SignatureFieldRepository $signature_fields = null,
        DocumentAiPromptBuilder $prompt_builder = null,
        DocumentAiOpenAiClient $openai = null
    )
    {
        $this->documents = $documents ?: new DocumentRepository();
        $this->signature_fields = $signature_fields ?: new SignatureFieldRepository();
        $this->prompt_builder = $prompt_builder ?: new DocumentAiPromptBuilder();
        $this->openai = $openai ?: new DocumentAiOpenAiClient();
    }

    public function enrichAfterUpload($document_id, $user_id, array $file)
    {
        if (!SignConnectSettings::openAiConfigured()) {
            return;
        }

        if (!SignConnectSettings::openAiAutoSuggestMessage() && !SignConnectSettings::openAiSuggestSignatureZone()) {
            return;
        }

        if (!class_exists('\Smbb\WpCodeTool\Connector\SmbbOpenAiTextClient')) {
            return;
        }

        $document = $this->documents->findOwnedByUser($document_id, $user_id);

        if (!$document) {
            return;
        }

        $page_count = $this->pdfPageCount($file);
        $filename = isset($document['filename']) ? (string) $document['filename'] : '';
        $suggest_signature_zone = SignConnectSettings::openAiSuggestSignatureZone();
        $pdf_path = isset($file['tmp_name']) ? (string) $file['tmp_name'] : '';
        $document_text = $suggest_signature_zone ? '' : $this->extractDocumentText($pdf_path);
        $signature_page = $suggest_signature_zone ? $this->signaturePage($pdf_path, $page_count) : array();
        $page_image = $suggest_signature_zone ? $this->rasterizeSignaturePage($pdf_path, isset($signature_page['page_number']) ? (int) $signature_page['page_number'] : $page_count) : array();
        $public_page_image = $suggest_signature_zone ? $this->publishPageImage($page_image) : array();
        $prompt = $this->prompt_builder->build($filename, $page_count, $suggest_signature_zone, $document_text, $signature_page, $page_image);
        $answer = '';
        $image_transport = 'none';

        try {
            $ai_result = $this->openai->ask($prompt, $suggest_signature_zone, $page_image, $public_page_image);
            $answer = isset($ai_result['answer']) ? (string) $ai_result['answer'] : '';
            $image_transport = isset($ai_result['transport']) ? (string) $ai_result['transport'] : 'none';
            $data = $this->decodeJsonAnswer($answer);
        } catch (\Throwable $exception) {
            $this->documents->appendSystemLog($document_id, 'openai_enrichment_error', array(
                'prompt' => $prompt,
                'answer' => $answer,
                'error' => $exception->getMessage(),
                'document_text_length' => strlen($document_text),
                'signature_page' => $signature_page,
                'page_image' => $this->imageDebug($page_image),
                'public_page_image' => $this->publicImageDebug($public_page_image),
                'image_transport' => $image_transport,
                'raw_openai_debug' => $this->openai->rawDebug(),
                'api_debug' => $this->openai->connectorDebug(),
            ));
            $this->cleanupPublicPageImage($public_page_image);
            $this->cleanupPageImage($page_image);

            return;
        }

        $this->documents->appendSystemLog($document_id, 'openai_enrichment', array(
            'prompt' => $prompt,
            'answer' => $answer,
            'decoded' => $data,
            'document_text_length' => strlen($document_text),
            'signature_page' => $signature_page,
            'page_image' => $this->imageDebug($page_image),
            'public_page_image' => $this->publicImageDebug($public_page_image),
            'image_transport' => $image_transport,
            'raw_openai_debug' => $this->openai->rawDebug(),
            'api_debug' => $this->openai->connectorDebug(),
        ));
        $this->cleanupPublicPageImage($public_page_image);
        $this->cleanupPageImage($page_image);

        if (SignConnectSettings::openAiAutoSuggestMessage() && !empty($data['message'])) {
            $this->documents->saveAiSuggestedMessage($document_id, $user_id, $this->cleanMessage((string) $data['message']));
        }

        if (SignConnectSettings::openAiSuggestSignatureZone() && !empty($data['signature_zone']) && is_array($data['signature_zone'])) {
            $this->saveSuggestedSignatureZone($document_id, $data['signature_zone'], $page_count);
        }
    }

    private function extractDocumentText($pdf_path)
    {
        if ($pdf_path === '' || !is_readable($pdf_path)) {
            return '';
        }

        return (new PdfTextExtractor())->extract($pdf_path, 6000);
    }

    private function signaturePage($pdf_path, $page_count)
    {
        if ($pdf_path === '' || !is_readable($pdf_path)) {
            return array(
                'page_number' => max(1, (int) $page_count),
                'reason' => 'unreadable_pdf_fallback_last_page',
                'text_preview' => '',
            );
        }

        return (new PdfSignaturePageLocator())->locate($pdf_path, $page_count);
    }

    private function rasterizeSignaturePage($pdf_path, $page_number)
    {
        if ($pdf_path === '' || !is_readable($pdf_path)) {
            return array('success' => false, 'error' => 'PDF file is not readable.');
        }

        return (new PdfPageRasterizer())->rasterize($pdf_path, $page_number);
    }

    private function publishPageImage(array $page_image)
    {
        if (empty($page_image['success']) || empty($page_image['path'])) {
            return array('success' => false, 'error' => 'No local page image to publish.');
        }

        return (new AiTempImagePublisher())->publish((string) $page_image['path']);
    }

    private function imageDebug(array $page_image)
    {
        if (!$page_image) {
            return array();
        }

        $debug = $page_image;

        if (!empty($debug['path'])) {
            $debug['path'] = basename((string) $debug['path']);
        }

        return $debug;
    }

    private function publicImageDebug(array $public_page_image)
    {
        if (!$public_page_image) {
            return array();
        }

        $debug = $public_page_image;

        if (!empty($debug['path'])) {
            $debug['path'] = basename((string) $debug['path']);
        }

        return $debug;
    }

    private function cleanupPublicPageImage(array $public_page_image)
    {
        (new AiTempImagePublisher())->cleanup($public_page_image);
    }

    private function cleanupPageImage(array $page_image)
    {
        if (!empty($page_image['path']) && is_string($page_image['path']) && file_exists($page_image['path'])) {
            @unlink($page_image['path']);
        }
    }

    private function decodeJsonAnswer($answer)
    {
        $answer = trim((string) $answer);
        $answer = preg_replace('/^```json\s*/i', '', $answer);
        $answer = preg_replace('/^```\s*/', '', (string) $answer);
        $answer = preg_replace('/\s*```$/', '', (string) $answer);
        $decoded = json_decode((string) $answer, true);

        return is_array($decoded) ? $decoded : array();
    }

    private function cleanMessage($message)
    {
        $message = trim(wp_strip_all_tags((string) $message));

        return function_exists('mb_substr') ? mb_substr($message, 0, 500) : substr($message, 0, 500);
    }

    private function saveSuggestedSignatureZone($document_id, array $zone, $page_count)
    {
        if ($this->signature_fields->listForDocument($document_id)) {
            return;
        }

        $page_number = isset($zone['page_number']) ? (int) $zone['page_number'] : (int) $page_count;
        $field = array(
            'page_number' => max(1, min(max(1, (int) $page_count), $page_number)),
            'x' => $this->ratio(isset($zone['x']) ? $zone['x'] : 0.56, 0.56),
            'y' => $this->ratio(isset($zone['y']) ? $zone['y'] : 0.74, 0.74),
            'width' => $this->ratio(isset($zone['width']) ? $zone['width'] : 0.32, 0.32),
            'height' => $this->ratio(isset($zone['height']) ? $zone['height'] : 0.10, 0.10),
            'label' => __('Suggested signature', 'smbb-signconnect'),
        );

        $field['width'] = max(0.18, min(0.45, $field['width']));
        $field['height'] = max(0.06, min(0.18, $field['height']));

        $explicit_signature_found = $this->explicitSignatureFound($zone);

        if ($this->looksLikeRiskyStructuredBlockPlacement($field)) {
            $field = $this->compactExplicitSignatureZoneNearStructuredBlock($field);
        }

        $field['x'] = min(1 - $field['width'], $field['x']);
        $field['y'] = min(1 - $field['height'], $field['y']);

        $this->signature_fields->saveForDocument($document_id, array($field));
    }

    /**
     * Quand l'IA repere bien le libelle de signature dans une facture, elle
     * retourne parfois la grande cellule complete, avec le contenu voisin.
     * On conserve donc l'intention, mais on transforme la zone en une surface
     * d'ecriture plus petite, dans le blanc disponible autour du libelle.
     */
    private function compactExplicitSignatureZoneNearStructuredBlock(array $field)
    {
        $original_width = isset($field['width']) ? (float) $field['width'] : 0.30;
        $original_height = isset($field['height']) ? (float) $field['height'] : 0.10;
        $original_x = isset($field['x']) ? (float) $field['x'] : 0.58;
        $original_y = isset($field['y']) ? (float) $field['y'] : 0.72;

        $field['width'] = max(0.18, min(0.27, $original_width * 0.72));
        $field['height'] = max(0.055, min(0.085, $original_height * 0.62));

        /*
         * Quand le libelle est dans un cadre, l'IA a tendance a retourner tout
         * le bloc structure. On decale donc le rectangle vers une surface plus
         * petite, la ou l'utilisateur pourra vraiment signer.
         */
        $field['x'] = max(0.60, min(1 - $field['width'], $original_x + ($original_width * 0.35)));
        $field['y'] = max(0.68, min(0.88, $original_y + ($original_height * 0.42)));

        return $field;
    }

    private function looksLikeRiskyStructuredBlockPlacement(array $field)
    {
        $x = isset($field['x']) ? (float) $field['x'] : 0;
        $y = isset($field['y']) ? (float) $field['y'] : 0;
        $width = isset($field['width']) ? (float) $field['width'] : 0;

        /*
         * Les zones de signature explicites sont souvent proches de tableaux,
         * cadres ou blocs denses en bas de document. Une grande suggestion dans
         * cette zone masque facilement du contenu, donc on la compacte.
         */
        return $x >= 0.48 && ($x + $width) >= 0.70 && $y >= 0.50 && $y <= 0.78;
    }

    private function explicitSignatureFound(array $zone)
    {
        $reason = isset($zone['reason']) ? strtolower((string) $zone['reason']) : '';
        $anchor_text = isset($zone['anchor_text']) ? strtolower((string) $zone['anchor_text']) : '';
        $evidence = trim($reason . ' ' . $anchor_text);
        $has_explicit_reason = strpos($evidence, 'signature') !== false
            || strpos($evidence, 'signataire') !== false
            || strpos($evidence, 'bon pour accord') !== false
            || strpos($evidence, 'visa') !== false
            || strpos($evidence, 'paraphe') !== false;

        if ($evidence === '') {
            return false;
        }

        if (isset($zone['explicit_signature_found'])) {
            return filter_var($zone['explicit_signature_found'], FILTER_VALIDATE_BOOLEAN) && $has_explicit_reason;
        }

        return $has_explicit_reason;
    }

    private function ratio($value, $default)
    {
        if (!is_numeric($value)) {
            return (float) $default;
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
