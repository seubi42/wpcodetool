<?php

namespace Smbb\SignConnect\Service;

use setasign\Fpdi\Fpdi;
use Smbb\SignConnect\Repository\SignatureFieldRepository;
use Smbb\SignConnect\Repository\StorageRepository;

defined('ABSPATH') || exit;

/**
 * Genere la variante PDF signee d'un document SignConnect.
 *
 * On ne remplace jamais le PDF original dans S3. Le fichier source reste la
 * preuve du depot initial, et ce service produit une variante suffixee
 * "_signed.pdf" qui contient la signature visible et la mention d'horodatage.
 */
final class SignedPdfGenerator
{
    private $storages;
    private $signature_fields;

    public function __construct(StorageRepository $storages = null, SignatureFieldRepository $signature_fields = null)
    {
        $this->storages = $storages ?: new StorageRepository();
        $this->signature_fields = $signature_fields ?: new SignatureFieldRepository();
    }

    public function generateAndUpload(array $document, $signature_data_url, $contact, $signed_at, array $identity = array(), array $return = array(), array $identity_photo = array())
    {
        $this->assertDependencies();

        $storage_id = isset($document['storage_id']) ? (int) $document['storage_id'] : 0;
        $storage_path = isset($document['storage_path']) ? (string) $document['storage_path'] : '';
        $storage = $this->storages->find($storage_id);

        if (!$storage || $storage_path === '') {
            throw new \RuntimeException(__('The source file is unavailable.', 'smbb-signconnect'));
        }

        $fields = $this->signature_fields->listForDocument(isset($document['id']) ? (int) $document['id'] : 0);

        if (!$fields) {
            throw new \RuntimeException(__('No signature area is defined.', 'smbb-signconnect'));
        }

        $client = \Smbb\WpCodeTool\Connector\SmbbS3Client::fromSettings($storage);
        $source_pdf = $this->downloadSourcePdf($client, $storage_path);
        $signature_png = $signature_data_url !== '' ? $this->writeSignatureImage($signature_data_url) : '';
        $signed_pdf = wp_tempnam('signconnect-signed.pdf');

        if (!$signed_pdf) {
            @unlink($source_pdf);
            @unlink($signature_png);
            throw new \RuntimeException(__('The temporary signed file could not be created.', 'smbb-signconnect'));
        }

        try {
            $this->writeSignedPdf($source_pdf, $signed_pdf, $signature_png, $fields, $contact, $signed_at, $identity, $return, $identity_photo);
            $signed_path = $this->signedStoragePath($storage_path);
            $body = fopen($signed_pdf, 'rb');

            if (!$body) {
                throw new \RuntimeException(__('The signed PDF could not be reread.', 'smbb-signconnect'));
            }

            $client->putObject($signed_path, $body, array(
                'ContentType' => 'application/pdf',
            ));

            fclose($body);

            return array(
                'storage_path' => $signed_path,
                'file_size' => file_exists($signed_pdf) ? (int) filesize($signed_pdf) : 0,
            );
        } finally {
            @unlink($source_pdf);
            if ($signature_png !== '') {
                @unlink($signature_png);
            }
            @unlink($signed_pdf);
        }
    }

    private function assertDependencies()
    {
        if (!class_exists('\Smbb\WpCodeTool\Connector\SmbbS3Client')) {
            throw new \RuntimeException(__('The S3 connector from SMBB WP CodeTool is unavailable.', 'smbb-signconnect'));
        }

        if (!class_exists(Fpdi::class)) {
            throw new \RuntimeException(__('The FPDI/FPDF PDF libraries are unavailable.', 'smbb-signconnect'));
        }
    }

    private function downloadSourcePdf($client, $storage_path)
    {
        $object = $client->getObject($storage_path);
        $body = isset($object['Body']) ? $object['Body'] : null;
        $temporary_path = wp_tempnam('signconnect-source.pdf');

        if (!$body || !$temporary_path) {
            throw new \RuntimeException(__('The source PDF could not be read.', 'smbb-signconnect'));
        }

        $target = fopen($temporary_path, 'wb');

        if (!$target) {
            @unlink($temporary_path);
            throw new \RuntimeException(__('The temporary source PDF could not be created.', 'smbb-signconnect'));
        }

        // Ecriture par flux pour garder un comportement correct avec des PDF volumineux.
        if (is_object($body) && method_exists($body, 'eof') && method_exists($body, 'read')) {
            while (!$body->eof()) {
                fwrite($target, $body->read(1048576));
            }
        } elseif (is_object($body) && method_exists($body, 'getContents')) {
            fwrite($target, $body->getContents());
        } else {
            fwrite($target, (string) $body);
        }

        fclose($target);

        return $temporary_path;
    }

    private function writeSignatureImage($signature_data_url)
    {
        if (strpos((string) $signature_data_url, 'data:image/png;base64,') !== 0) {
            throw new \RuntimeException(__('The received signature is invalid.', 'smbb-signconnect'));
        }

        $temporary_path = wp_tempnam('signconnect-signature.png');

        if (!$temporary_path) {
            throw new \RuntimeException(__('The temporary signature image could not be created.', 'smbb-signconnect'));
        }

        $binary = base64_decode(substr((string) $signature_data_url, strlen('data:image/png;base64,')), true);

        if ($binary === false || file_put_contents($temporary_path, $binary) === false) {
            @unlink($temporary_path);
            throw new \RuntimeException(__('The signature image could not be written.', 'smbb-signconnect'));
        }

        return $temporary_path;
    }

    private function writeSignedPdf($source_pdf, $signed_pdf, $signature_png, array $fields, $contact, $signed_at, array $identity, array $return, array $identity_photo)
    {
        $pdf = new Fpdi();
        $page_count = $pdf->setSourceFile($source_pdf);
        $mention_lines = array();
        $mention_lines[] = sprintf(
            __('Electronically signed from %s on %s', 'smbb-signconnect'),
            $contact !== '' ? $contact : __('recipient', 'smbb-signconnect'),
            mysql2date('d/m/Y H:i', $signed_at)
        );
        $signer_name = trim((isset($identity['first_name']) ? (string) $identity['first_name'] : '') . ' ' . (isset($identity['last_name']) ? (string) $identity['last_name'] : ''));

        if ($signer_name !== '') {
            $mention_lines[] = __('Signer:', 'smbb-signconnect') . ' ' . $signer_name;
        }

        if (!empty($identity['place'])) {
            $mention_lines[] = __('Place:', 'smbb-signconnect') . ' ' . (string) $identity['place'];
        }
        $status = isset($return['status']) ? (string) $return['status'] : 'signed';
        if ($status === 'approved') {
            $mention_lines[] = __('Response: Good for approval', 'smbb-signconnect');
        } elseif ($status === 'refused') {
            $mention_lines[] = __('Response: Refusal', 'smbb-signconnect');
            if (!empty($return['message'])) {
                $mention_lines[] = __('Comment:', 'smbb-signconnect') . ' ' . (string) $return['message'];
            }
        }

        $mention = implode("\n", $mention_lines);

        for ($page_number = 1; $page_number <= $page_count; $page_number += 1) {
            $template_id = $pdf->importPage($page_number);
            $size = $pdf->getTemplateSize($template_id);
            $orientation = $size['width'] > $size['height'] ? 'L' : 'P';

            $pdf->AddPage($orientation, array($size['width'], $size['height']));
            $pdf->useTemplate($template_id);

            foreach ($fields as $index => $field) {
                if ((int) $field['page_number'] !== $page_number) {
                    continue;
                }

                $this->drawSignatureBlock($pdf, $signature_png, $field, $size, $mention, $index + 1, $status);
            }
        }

        $this->appendIdentityPhotoPage($pdf, $identity_photo, $contact, $signed_at, $identity);

        $pdf->Output('F', $signed_pdf);
    }

    private function appendIdentityPhotoPage(Fpdi $pdf, array $identity_photo, $contact, $signed_at, array $identity)
    {
        $photo_path = isset($identity_photo['tmp_name']) ? (string) $identity_photo['tmp_name'] : '';

        if ($photo_path === '' || !file_exists($photo_path)) {
            return;
        }

        $mime_type = isset($identity_photo['mime_type']) ? (string) $identity_photo['mime_type'] : '';
        $image_type = $mime_type === 'image/png' ? 'PNG' : 'JPG';
        $page_width = 210;
        $page_height = 297;
        $margin = 16;
        $title_height = 28;
        $max_width = $page_width - ($margin * 2);
        $max_height = $page_height - ($margin * 2) - $title_height;
        $image_size = @getimagesize($photo_path);

        if (!$image_size || empty($image_size[0]) || empty($image_size[1])) {
            return;
        }

        $ratio = min($max_width / (float) $image_size[0], $max_height / (float) $image_size[1]);
        $draw_width = max(20, (float) $image_size[0] * $ratio);
        $draw_height = max(20, (float) $image_size[1] * $ratio);
        $x = ($page_width - $draw_width) / 2;
        $y = $margin + $title_height;
        $signer_name = trim((isset($identity['first_name']) ? (string) $identity['first_name'] : '') . ' ' . (isset($identity['last_name']) ? (string) $identity['last_name'] : ''));

        $pdf->AddPage('P', array($page_width, $page_height));
        $pdf->SetTextColor(35, 35, 35);
        $pdf->SetFont('Arial', 'B', 14);
        $pdf->SetXY($margin, $margin);
        $pdf->Cell($max_width, 8, utf8_decode(__('Appendix - identity photo', 'smbb-signconnect')), 0, 2, 'L');
        $pdf->SetFont('Arial', '', 8);
        $pdf->MultiCell($max_width, 4, utf8_decode(sprintf(
            __('Attached to the electronic signature of %s on %s%s', 'smbb-signconnect'),
            $contact !== '' ? $contact : __('recipient', 'smbb-signconnect'),
            mysql2date('d/m/Y H:i', $signed_at),
            $signer_name !== '' ? ' - ' . $signer_name : ''
        )), 0, 'L');
        $pdf->Image($photo_path, $x, $y, $draw_width, $draw_height, $image_type);
    }

    private function drawSignatureBlock(Fpdi $pdf, $signature_png, array $field, array $page_size, $mention, $index, $status)
    {
        $x = (float) $field['x'] * (float) $page_size['width'];
        $y = (float) $field['y'] * (float) $page_size['height'];
        $width = max(12, (float) $field['width'] * (float) $page_size['width']);
        $height = max(10, (float) $field['height'] * (float) $page_size['height']);
        $padding = min(3, max(1, $height * 0.08));
        $text_height = min(10, max(6, $height * 0.28));
        $image_height = max(4, $height - ($padding * 3) - $text_height);

        if ($status === 'approved') {
            $pdf->SetDrawColor(46, 125, 50);
        } elseif ($status === 'refused') {
            $pdf->SetDrawColor(198, 40, 40);
        } else {
            $pdf->SetDrawColor(120, 120, 120);
        }
        $pdf->SetFillColor(248, 248, 248);
        $pdf->Rect($x, $y, $width, $height, 'DF');
        if ($signature_png !== '') {
            $pdf->Image($signature_png, $x + $padding, $y + $padding, $width - ($padding * 2), $image_height, 'PNG');
        } else {
            $pdf->SetFont('Arial', 'B', 14);
            $pdf->SetTextColor(198, 40, 40);
            $pdf->SetXY($x + $padding, $y + $padding);
            $pdf->Cell($width - ($padding * 2), $image_height, 'REFUS', 0, 0, 'C');
        }
        $pdf->SetFont('Arial', '', 6);
        $pdf->SetTextColor(45, 45, 45);
        $pdf->SetXY($x + $padding, $y + $padding + $image_height + $padding);
        $pdf->MultiCell($width - ($padding * 2), 3, utf8_decode($mention), 0, 'L');
    }

    private function signedStoragePath($storage_path)
    {
        $info = pathinfo((string) $storage_path);
        $directory = !empty($info['dirname']) && $info['dirname'] !== '.' ? trim($info['dirname'], '/') . '/' : '';
        $filename = isset($info['filename']) ? $info['filename'] : 'document';

        return $directory . $filename . '_signed.pdf';
    }
}
