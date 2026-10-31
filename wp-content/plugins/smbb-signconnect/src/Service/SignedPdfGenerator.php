<?php

namespace Smbb\SignConnect\Service;

use setasign\Fpdi\Fpdi;
use Smbb\SignConnect\Repository\SenderSignatureRepository;
use Smbb\SignConnect\Repository\SignatureFieldRepository;
use Smbb\SignConnect\Repository\StampRepository;
use Smbb\SignConnect\Repository\StorageRepository;
use Smbb\SignConnect\User\UserStorageProfileFields;
use Smbb\SignConnect\Support\SignConnectSettings;
use Smbb\SignConnect\Support\SignatureFieldType;

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
    private $sender_signatures;
    private $stamps;

    public function __construct(StorageRepository $storages = null, SignatureFieldRepository $signature_fields = null, SenderSignatureRepository $sender_signatures = null, StampRepository $stamps = null)
    {
        $this->storages = $storages ?: new StorageRepository();
        $this->signature_fields = $signature_fields ?: new SignatureFieldRepository();
        $this->sender_signatures = $sender_signatures ?: new SenderSignatureRepository();
        $this->stamps = $stamps ?: new StampRepository();
    }

    /**
     * Génère le PDF signé et l'uploade dans S3 à côté du fichier source.
     *
     * Le fichier source n'est jamais modifié : ce service produit une variante
     * suffixée "_signed.pdf". Les deux fichiers coexistent dans S3 pour permettre
     * la vérification d'intégrité (hash SHA-256 source vs signé).
     *
     * Si FPDI ne peut pas lire le PDF source (format compressé incompatible),
     * normalizePdfForFpdi() tente une conversion via qpdf, pdftocairo ou Ghostscript.
     *
     * @param array<string,mixed> $document          Ligne de la table wp_signdocument.
     * @param string              $signature_data_url Data-URL PNG de la signature manuscrite (ou '' si refus).
     * @param string              $contact            Email ou téléphone du signataire.
     * @param string              $signed_at          Date de signature au format MySQL.
     * @param array<string,mixed> $identity           Clés : first_name, last_name, place, latitude, longitude.
     * @param array<string,mixed> $return             Clés : status ('signed'|'approved'|'refused'), message.
     * @param array<string,mixed> $identity_photo     Résultat de IdentityPhotoAttachmentService::upload().
     * @return array<string,mixed>  Clés : storage_path, file_size, source_sha256, signed_sha256,
     *                              certification_fingerprint, cryptographic_signature_applied,
     *                              cryptographic_signature_status.
     * @throws \RuntimeException Si le fichier source est inaccessible, si FPDI est absent,
     *                           ou si le PDF normalisé ne peut pas être produit.
     */
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
        $source_hash = SignConnectSettings::certificationHashProofEnabled() && is_readable($source_pdf)
            ? hash_file('sha256', $source_pdf)
            : '';
        $signature_png = $signature_data_url !== '' ? $this->writeSignatureImage($signature_data_url) : '';
        $sender_signature_png = '';
        $sender_signature_data = $this->senderSignatureData($document);
        if ($sender_signature_data !== '') {
            $sender_signature_png = $this->writeSignatureImage($sender_signature_data);
        }
        $sender_stamp = $this->senderStampImage($document);
        $sender_identity = $this->senderIdentity($document);
        $signed_pdf = wp_tempnam('signconnect-signed.pdf');
        $normalized_source_pdf = '';

        if (!$signed_pdf) {
            @unlink($source_pdf);
            @unlink($signature_png);
            throw new \RuntimeException(__('The temporary signed file could not be created.', 'smbb-signconnect'));
        }

        try {
            $cryptographic_signature_status = $this->cryptographicSignatureStatus($document);
            try {
                $cryptographic_signature_applied = $this->writeSignedPdf($source_pdf, $signed_pdf, $signature_png, $fields, $contact, $signed_at, $identity, $return, $identity_photo, $cryptographic_signature_status, $sender_signature_png, $sender_stamp, $sender_identity);
            } catch (\Throwable $exception) {
                if (!$this->isFpdiParserCompatibilityError($exception)) {
                    throw $exception;
                }

                $normalized_source_pdf = $this->normalizePdfForFpdi($source_pdf);

                if ($normalized_source_pdf === '') {
                    throw new \RuntimeException(__('This PDF uses a compression format unsupported by the bundled FPDI parser. Install qpdf, Poppler pdftocairo, or Ghostscript on the server to enable automatic PDF normalization.', 'smbb-signconnect'));
                }

                @unlink($signed_pdf);
                $signed_pdf = wp_tempnam('signconnect-signed.pdf');

                if (!$signed_pdf) {
                    throw new \RuntimeException(__('The temporary signed file could not be created.', 'smbb-signconnect'));
                }

                $cryptographic_signature_applied = $this->writeSignedPdf($normalized_source_pdf, $signed_pdf, $signature_png, $fields, $contact, $signed_at, $identity, $return, $identity_photo, $cryptographic_signature_status, $sender_signature_png, $sender_stamp, $sender_identity);
            }
            $signed_hash = SignConnectSettings::certificationHashProofEnabled() && is_readable($signed_pdf)
                ? hash_file('sha256', $signed_pdf)
                : '';
            $yousign_result = $this->applyYousignSeal($signed_pdf, $document);

            if (!empty($yousign_result['applied'])) {
                $signed_hash = SignConnectSettings::certificationHashProofEnabled() && is_readable($signed_pdf)
                    ? hash_file('sha256', $signed_pdf)
                    : '';
            }

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
                'source_sha256' => is_string($source_hash) ? $source_hash : '',
                'signed_sha256' => is_string($signed_hash) ? $signed_hash : '',
                'certification_fingerprint' => SignConnectSettings::certificationCertificateFingerprint(),
                'cryptographic_signature_applied' => ($cryptographic_signature_applied || !empty($yousign_result['applied'])) ? 1 : 0,
                'cryptographic_signature_status' => $this->combinedSignatureStatus($cryptographic_signature_status['reason'], $yousign_result),
            );
        } finally {
            @unlink($source_pdf);
            if ($signature_png !== '') {
                @unlink($signature_png);
            }
            if ($sender_signature_png !== '') {
                @unlink($sender_signature_png);
            }
            if ($normalized_source_pdf !== '') {
                @unlink($normalized_source_pdf);
            }
            @unlink($signed_pdf);
        }
    }

    public function prepareForExternalSignature(array $document, $source_pdf, $prepared_pdf, array $identity = array(), array $return = array())
    {
        $this->assertDependencies();

        if (!is_readable($source_pdf)) {
            throw new \RuntimeException(__('The source PDF could not be read.', 'smbb-signconnect'));
        }

        $fields = $this->fieldsForExternalSignature(isset($document['id']) ? (int) $document['id'] : 0);

        if (!$fields) {
            if (!copy($source_pdf, $prepared_pdf)) {
                throw new \RuntimeException(__('The prepared PDF could not be created.', 'smbb-signconnect'));
            }

            return;
        }

        $sender_signature_png = '';
        $normalized_source_pdf = '';
        $sender_signature_data = $this->senderSignatureData($document);

        if ($sender_signature_data !== '') {
            $sender_signature_png = $this->writeSignatureImage($sender_signature_data);
        }

        $sender_stamp = $this->senderStampImage($document);
        $sender_identity = $this->senderIdentity($document);
        $contact = !empty($document['recipient_email']) ? sanitize_email((string) $document['recipient_email']) : '';
        $signed_at = current_time('mysql');

        try {
            try {
                $this->writeSignedPdf($source_pdf, $prepared_pdf, '', $fields, $contact, $signed_at, $identity, $return, array(), array('enabled' => false, 'reason' => 'external_signature'), $sender_signature_png, $sender_stamp, $sender_identity);
            } catch (\Throwable $exception) {
                if (!$this->isFpdiParserCompatibilityError($exception)) {
                    throw $exception;
                }

                $normalized_source_pdf = $this->normalizePdfForFpdi($source_pdf);

                if ($normalized_source_pdf === '') {
                    throw new \RuntimeException(__('This PDF uses a compression format unsupported by the bundled FPDI parser. Install qpdf, Poppler pdftocairo, or Ghostscript on the server to enable automatic PDF normalization.', 'smbb-signconnect'));
                }

                $this->writeSignedPdf($normalized_source_pdf, $prepared_pdf, '', $fields, $contact, $signed_at, $identity, $return, array(), array('enabled' => false, 'reason' => 'external_signature'), $sender_signature_png, $sender_stamp, $sender_identity);
            }
        } finally {
            if ($sender_signature_png !== '') {
                @unlink($sender_signature_png);
            }
            if ($normalized_source_pdf !== '') {
                @unlink($normalized_source_pdf);
            }
        }
    }

    private function fieldsForExternalSignature($document_id)
    {
        $fields = array();

        foreach ($this->signature_fields->listForDocument($document_id) as $field) {
            $type = SignatureFieldType::normalize(isset($field['field_type']) ? (string) $field['field_type'] : SignatureFieldType::SIGNATURE);

            if ($type !== SignatureFieldType::SIGNATURE) {
                $fields[] = $field;
            }
        }

        return $fields;
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

    private function isFpdiParserCompatibilityError(\Throwable $exception)
    {
        $message = strtolower($exception->getMessage());

        return strpos($message, 'compression technique') !== false
            || strpos($message, 'object stream') !== false
            || strpos($message, 'xref') !== false
            || strpos($message, 'fpdi-pdf-parser') !== false;
    }

    /**
     * Tente de normaliser un PDF incompatible avec le parser FPDI libre (fpdi-pdf-parser).
     *
     * FPDI gratuit ne supporte pas les PDF 1.5+ avec cross-reference streams ni les
     * object streams compressés (erreurs "compression technique" / "xref"). On essaie
     * trois outils dans l'ordre de fiabilité :
     *   1. qpdf --object-streams=disable  → le plus fiable, préserve mieux le contenu ;
     *   2. pdftocairo -pdf                → re-rasterise, perd les calques vectoriels ;
     *   3. gs -sDEVICE=pdfwrite           → dernier recours, peut réencoder les images.
     *
     * @param string $source_pdf Chemin temporaire du PDF source.
     * @return string Chemin du fichier normalisé, ou '' si aucun outil n'est disponible.
     */
    private function normalizePdfForFpdi($source_pdf)
    {
        if (!is_readable($source_pdf) || !function_exists('proc_open')) {
            return '';
        }

        $commands = array(
            array('qpdf', array('--object-streams=disable', '--decode-level=none', $source_pdf)),
            array('pdftocairo', array('-pdf', $source_pdf)),
            array('gs', array('-sDEVICE=pdfwrite', '-dCompatibilityLevel=1.4', '-dNOPAUSE', '-dBATCH', '-dSAFER', $source_pdf)),
        );

        foreach ($commands as $command) {
            $output = wp_tempnam('signconnect-normalized.pdf');

            if (!$output) {
                continue;
            }

            @unlink($output);

            if ($this->runPdfNormalizationCommand($command[0], $command[1], $output) && is_readable($output) && filesize($output) > 0) {
                return $output;
            }

            @unlink($output);
        }

        return '';
    }

    private function runPdfNormalizationCommand($binary, array $arguments, $output)
    {
        $candidates = $this->executableCandidates($binary);

        foreach ($candidates as $candidate) {
            $parts = array(escapeshellarg($candidate));

            foreach ($arguments as $argument) {
                $parts[] = escapeshellarg((string) $argument);
            }

            if ($binary === 'gs') {
                array_splice($parts, count($parts) - 1, 0, '-sOutputFile=' . escapeshellarg($output));
            } else {
                $parts[] = escapeshellarg($output);
            }

            $status = $this->runCommand(implode(' ', $parts));

            if ($status === 0 && is_readable($output) && filesize($output) > 0) {
                return true;
            }
        }

        return false;
    }

    private function executableCandidates($binary)
    {
        $map = array(
            'qpdf' => array('qpdf', '/usr/bin/qpdf', '/usr/local/bin/qpdf', '/opt/local/bin/qpdf'),
            'pdftocairo' => array('pdftocairo', '/usr/bin/pdftocairo', '/usr/local/bin/pdftocairo', '/opt/local/bin/pdftocairo'),
            'gs' => array('gs', '/usr/bin/gs', '/usr/local/bin/gs', '/opt/local/bin/gs'),
        );

        return isset($map[$binary]) ? $map[$binary] : array($binary);
    }

    private function runCommand($command)
    {
        $descriptor_spec = array(
            0 => array('pipe', 'r'),
            1 => array('pipe', 'w'),
            2 => array('pipe', 'w'),
        );
        $process = @proc_open($command, $descriptor_spec, $pipes);

        if (!is_resource($process)) {
            return -1;
        }

        fclose($pipes[0]);
        stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[2]);

        return (int) proc_close($process);
    }

    private function writeSignedPdf($source_pdf, $signed_pdf, $signature_png, array $fields, $contact, $signed_at, array $identity, array $return, array $identity_photo, array $cryptographic_signature_status, $sender_signature_png, array $sender_stamp, array $sender_identity)
    {
        $cryptographic_signature = !empty($cryptographic_signature_status['enabled']);
        $pdf = $cryptographic_signature ? new \setasign\Fpdi\Tcpdf\Fpdi() : new Fpdi();

        if (method_exists($pdf, 'setPrintHeader')) {
            $pdf->setPrintHeader(false);
        }

        if (method_exists($pdf, 'setPrintFooter')) {
            $pdf->setPrintFooter(false);
        }

        if (method_exists($pdf, 'SetAutoPageBreak')) {
            $pdf->SetAutoPageBreak(false, 0);
        }

        if ($cryptographic_signature) {
            $this->applyCryptographicSignature($pdf, $contact, $signed_at, $identity, $cryptographic_signature_status);
        }

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

                $this->drawFieldBlock($pdf, $signature_png, $field, $size, $mention, $index + 1, $status, $identity, $signed_at, array(
                    'identity' => $sender_identity,
                    'signature_png' => $sender_signature_png,
                    'stamp' => $sender_stamp,
                ));
            }
        }

        $this->appendIdentityPhotoPage($pdf, $identity_photo, $contact, $signed_at, $identity);

        if ($cryptographic_signature) {
            $pdf->Output($signed_pdf, 'F');
        } else {
            $pdf->Output('F', $signed_pdf);
        }

        return $cryptographic_signature;
    }

    private function cryptographicSignatureStatus(array $document)
    {
        $certificate_mode = SignConnectSettings::documentCertificationCertificateMode($document);
        $certificate_path = SignConnectSettings::certificationCertificatePathForMode($certificate_mode);
        $private_key_path = SignConnectSettings::certificationPrivateKeyPathForMode($certificate_mode);

        if (!SignConnectSettings::documentUsesSignConnectCertification($document)) {
            return array('enabled' => false, 'reason' => 'policy_not_signconnect_certification');
        }

        if (!SignConnectSettings::certificationMethodAvailable(SignConnectSettings::documentCertificationMethod($document))) {
            return array('enabled' => false, 'reason' => 'certification_method_disabled');
        }

        if (!SignConnectSettings::certificationEnabled()) {
            return array('enabled' => false, 'reason' => 'certification_disabled');
        }

        if ($certificate_path === '' || !is_readable($certificate_path)) {
            return array('enabled' => false, 'reason' => 'certificate_missing_or_unreadable');
        }

        if ($private_key_path === '' || !is_readable($private_key_path)) {
            return array('enabled' => false, 'reason' => 'private_key_missing_or_unreadable');
        }

        if (!class_exists('\TCPDF')) {
            return array('enabled' => false, 'reason' => 'tcpdf_missing');
        }

        if (!class_exists('\setasign\Fpdi\Tcpdf\Fpdi')) {
            return array('enabled' => false, 'reason' => 'fpdi_tcpdf_missing');
        }

        return array('enabled' => true, 'reason' => 'ready', 'certificate_mode' => $certificate_mode);
    }

    private function applyYousignSeal($signed_pdf, array $document)
    {
        if (!SignConnectSettings::documentUsesYousignSeal($document)) {
            return array('applied' => false, 'reason' => 'policy_not_yousign_seal');
        }

        if (SignConnectSettings::yousignApiKey() === '') {
            throw new \RuntimeException(__('Yousign seal is enabled but the API key is missing.', 'smbb-signconnect'));
        }

        $sealed_pdf = wp_tempnam('signconnect-yousign-sealed.pdf');

        if (!$sealed_pdf) {
            throw new \RuntimeException(__('The temporary Yousign sealed file could not be created.', 'smbb-signconnect'));
        }

        try {
            $result = ElectronicSealService::fromSettings()->sealPdf(
                $signed_pdf,
                $sealed_pdf,
                'qualified_electronic_signature',
                SignConnectSettings::yousignTimestamp(),
                $this->yousignExternalId($document)
            );

            if (!is_readable($sealed_pdf) || filesize($sealed_pdf) < 1) {
                throw new \RuntimeException(__('Yousign returned an empty sealed PDF.', 'smbb-signconnect'));
            }

            if (!copy($sealed_pdf, $signed_pdf)) {
                throw new \RuntimeException(__('The Yousign sealed PDF could not replace the local signed PDF.', 'smbb-signconnect'));
            }

            return array(
                'applied' => true,
                'reason' => 'yousign_done',
                'seal_id' => isset($result['seal']['id']) ? (string) $result['seal']['id'] : '',
                'document_id' => isset($result['document']['id']) ? (string) $result['document']['id'] : '',
            );
        } finally {
            @unlink($sealed_pdf);
        }
    }

    private function yousignExternalId(array $document)
    {
        $id = isset($document['id']) ? absint($document['id']) : 0;

        return $id > 0 ? 'signconnect-document-' . $id : null;
    }

    private function combinedSignatureStatus($local_status, array $yousign_result)
    {
        $status = (string) $local_status;

        if (!empty($yousign_result['applied'])) {
            return $status . '+yousign_done';
        }

        if (!empty($yousign_result['reason'])) {
            return $status . '+' . (string) $yousign_result['reason'];
        }

        return $status;
    }

    private function applyCryptographicSignature($pdf, $contact, $signed_at, array $identity, array $cryptographic_signature_status)
    {
        if (!method_exists($pdf, 'setSignature')) {
            return;
        }

        $certificate_mode = isset($cryptographic_signature_status['certificate_mode']) ? (string) $cryptographic_signature_status['certificate_mode'] : SignConnectSettings::certificationCertificateMode();
        $certificate_path = SignConnectSettings::certificationCertificatePathForMode($certificate_mode);
        $private_key_path = SignConnectSettings::certificationPrivateKeyPathForMode($certificate_mode);
        $name = trim((isset($identity['first_name']) ? (string) $identity['first_name'] : '') . ' ' . (isset($identity['last_name']) ? (string) $identity['last_name'] : ''));
        $info = array(
            'Name' => $name !== '' ? $name : 'SignConnect',
            'Location' => isset($identity['place']) ? (string) $identity['place'] : '',
            'Reason' => __('SignConnect electronic signature', 'smbb-signconnect'),
            'ContactInfo' => (string) $contact,
        );

        $pdf->setSignature('file://' . $certificate_path, 'file://' . $private_key_path, SignConnectSettings::certificationPrivateKeyPassphraseForMode($certificate_mode), '', 2, $info);
    }

    private function appendIdentityPhotoPage($pdf, array $identity_photo, $contact, $signed_at, array $identity)
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
        $pdf->SetFont($this->pdfFontFamily(), 'B', 14);
        $pdf->SetXY($margin, $margin);
        $pdf->Cell($max_width, 8, $this->pdfText($pdf, __('Appendix - identity photo', 'smbb-signconnect')), 0, 2, 'L');
        $pdf->SetFont($this->pdfFontFamily(), '', 8);
        $pdf->MultiCell($max_width, 4, $this->pdfText($pdf, sprintf(
            __('Attached to the electronic signature of %s on %s%s', 'smbb-signconnect'),
            $contact !== '' ? $contact : __('recipient', 'smbb-signconnect'),
            mysql2date('d/m/Y H:i', $signed_at),
            $signer_name !== '' ? ' - ' . $signer_name : ''
        )), 0, 'L');
        $pdf->Image($photo_path, $x, $y, $draw_width, $draw_height, $image_type);
    }

    private function drawFieldBlock($pdf, $signature_png, array $field, array $page_size, $mention, $index, $status, array $identity, $signed_at, array $sender_assets = array())
    {
        $field_type = SignatureFieldType::normalize(isset($field['field_type']) ? $field['field_type'] : SignatureFieldType::SIGNATURE);

        if ($field_type === SignatureFieldType::SENDER_SIGNATURE) {
            $this->drawImageField($pdf, isset($sender_assets['signature_png']) ? (string) $sender_assets['signature_png'] : '', 'PNG', $field, $page_size);
            return;
        }

        if ($field_type === SignatureFieldType::SENDER_STAMP) {
            $stamp = isset($sender_assets['stamp']) && is_array($sender_assets['stamp']) ? $sender_assets['stamp'] : array();
            $this->drawImageField($pdf, isset($stamp['path']) ? (string) $stamp['path'] : '', isset($stamp['type']) ? (string) $stamp['type'] : '', $field, $page_size);
            return;
        }

        if ($field_type !== SignatureFieldType::SIGNATURE) {
            $sender_identity = isset($sender_assets['identity']) && is_array($sender_assets['identity']) ? $sender_assets['identity'] : array();
            $this->drawTextField($pdf, $field, $page_size, $field_type, $identity, $signed_at, $status, $sender_identity);
            return;
        }

        // Les coordonnées du champ sont stockées en valeurs relatives (0–1) dans la BDD
        // pour être indépendantes de la résolution/taille de la page. On les convertit
        // ici en millimètres FPDF en multipliant par les dimensions réelles de la page.
        $x = (float) $field['x'] * (float) $page_size['width'];
        $y = (float) $field['y'] * (float) $page_size['height'];
        $width = max(12, (float) $field['width'] * (float) $page_size['width']);
        $height = max(10, (float) $field['height'] * (float) $page_size['height']);

        // Marges internes et répartition verticale : 8% de hauteur pour le padding,
        // 34% pour le texte de mention (horodatage + signataire), le reste pour l'image.
        $padding = min(3, max(1, $height * 0.08));
        $text_height = min(14, max(8, $height * 0.34));
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
            $pdf->SetFont($this->pdfFontFamily(), 'B', 14);
            $pdf->SetTextColor(198, 40, 40);
            $pdf->SetXY($x + $padding, $y + $padding);
            $pdf->Cell($width - ($padding * 2), $image_height, 'REFUS', 0, 0, 'C');
        }
        $mention_y = $y + $padding + $image_height + $padding;
        $pdf->SetDrawColor(220, 220, 220);
        $pdf->SetFillColor(255, 255, 255);
        $pdf->Rect($x + $padding, $mention_y, $width - ($padding * 2), $text_height, 'DF');
        $pdf->SetFont($this->pdfFontFamily(), '', 7);
        $pdf->SetTextColor(45, 45, 45);
        $pdf->SetXY($x + $padding + 1, $mention_y + 1);
        $pdf->MultiCell($width - ($padding * 2) - 2, 3, $this->pdfText($pdf, $this->compactMention($mention)), 0, 'L');
    }

    private function drawImageField($pdf, $image_path, $image_type, array $field, array $page_size)
    {
        if ($image_path === '' || !file_exists($image_path)) {
            return;
        }

        $x = (float) $field['x'] * (float) $page_size['width'];
        $y = (float) $field['y'] * (float) $page_size['height'];
        $width = max(12, (float) $field['width'] * (float) $page_size['width']);
        $height = max(10, (float) $field['height'] * (float) $page_size['height']);
        $padding = min(2, max(0.8, $height * 0.06));

        $pdf->Image($image_path, $x + $padding, $y + $padding, $width - ($padding * 2), $height - ($padding * 2), $image_type ?: '');
    }

    private function drawTextField($pdf, array $field, array $page_size, $field_type, array $identity, $signed_at, $status, array $sender_identity = array())
    {
        $value = $this->fieldTextValue($field_type, $identity, $signed_at, $status, $sender_identity);

        if ($value === '') {
            return;
        }

        // Coordonnées en mm depuis les valeurs relatives (0–1) stockées en BDD.
        $x = (float) $field['x'] * (float) $page_size['width'];
        $y = (float) $field['y'] * (float) $page_size['height'];
        $width = max(12, (float) $field['width'] * (float) $page_size['width']);
        $height = max(5, (float) $field['height'] * (float) $page_size['height']);
        // Taille de police proportionnelle à la hauteur du champ (42%), bornée 7–13pt.
        $font_size = max(7, min(13, $height * 0.42));

        $pdf->SetDrawColor(255, 255, 255);
        $pdf->SetFillColor(255, 255, 255);
        $pdf->Rect($x, $y, $width, $height, 'F');
        $pdf->SetTextColor(30, 30, 30);
        $pdf->SetFont($this->pdfFontFamily(), $field_type === SignatureFieldType::APPROVAL ? 'B' : '', $font_size);
        $pdf->SetXY($x + 1.5, $y + max(0.5, ($height - $font_size * 0.5) / 2));
        $pdf->Cell($width - 3, max(4, $height - 1), $this->pdfText($pdf, $value), 0, 0, 'L');
    }

    private function fieldTextValue($field_type, array $identity, $signed_at, $status, array $sender_identity = array())
    {
        $first_name = isset($identity['first_name']) ? (string) $identity['first_name'] : '';
        $last_name = isset($identity['last_name']) ? (string) $identity['last_name'] : '';

        switch (SignatureFieldType::normalize($field_type)) {
            case SignatureFieldType::LAST_NAME:
                return $last_name;
            case SignatureFieldType::FIRST_NAME:
                return $first_name;
            case SignatureFieldType::FULL_NAME:
                return trim($first_name . ' ' . $last_name);
            case SignatureFieldType::PLACE:
                return isset($identity['place']) ? (string) $identity['place'] : '';
            case SignatureFieldType::DATE:
                return mysql2date('d/m/Y', $signed_at);
            case SignatureFieldType::APPROVAL:
                return $status === 'approved' ? __('Good for approval', 'smbb-signconnect') : ($status === 'refused' ? __('Refusal', 'smbb-signconnect') : '');
            case SignatureFieldType::SENDER_LAST_NAME:
                return isset($sender_identity['last_name']) ? (string) $sender_identity['last_name'] : '';
            case SignatureFieldType::SENDER_FIRST_NAME:
                return isset($sender_identity['first_name']) ? (string) $sender_identity['first_name'] : '';
            case SignatureFieldType::SENDER_FULL_NAME:
                return trim((isset($sender_identity['first_name']) ? (string) $sender_identity['first_name'] : '') . ' ' . (isset($sender_identity['last_name']) ? (string) $sender_identity['last_name'] : ''));
            default:
                return '';
        }
    }

    private function senderSignatureData(array $document)
    {
        $user_id = isset($document['creation_by']) ? (int) $document['creation_by'] : 0;
        $document_id = isset($document['id']) ? (int) $document['id'] : 0;

        if ($user_id < 1) {
            return '';
        }

        $row = $document_id > 0 ? $this->sender_signatures->findForDocument($document_id, $user_id) : null;

        if (!$row) {
            $row = $this->sender_signatures->findReusableForUser($user_id);
        }

        return $row && !empty($row['signature_data']) ? (string) $row['signature_data'] : '';
    }

    private function senderStampImage(array $document)
    {
        $user_id = isset($document['creation_by']) ? (int) $document['creation_by'] : 0;
        $stamp_id = $user_id > 0 ? (int) get_user_meta($user_id, UserStorageProfileFields::STAMP_META_KEY, true) : 0;
        $stamp = $stamp_id > 0 ? $this->stamps->find($stamp_id) : null;
        $attachment_id = $stamp && !empty($stamp['image_attachment_id']) ? (int) $stamp['image_attachment_id'] : 0;
        $path = $attachment_id > 0 ? get_attached_file($attachment_id) : '';

        if (!is_string($path) || $path === '' || !file_exists($path)) {
            return array();
        }

        $mime = get_post_mime_type($attachment_id);

        return array(
            'path' => $path,
            'type' => $mime === 'image/png' ? 'PNG' : ($mime === 'image/jpeg' ? 'JPG' : ''),
        );
    }

    private function senderIdentity(array $document)
    {
        $user_id = isset($document['creation_by']) ? (int) $document['creation_by'] : 0;
        $user = $user_id > 0 ? get_userdata($user_id) : false;

        if (!$user) {
            return array('first_name' => '', 'last_name' => '');
        }

        $first_name = get_user_meta($user_id, 'first_name', true);
        $last_name = get_user_meta($user_id, 'last_name', true);

        if ($first_name === '' && $last_name === '') {
            $parts = preg_split('/\s+/', trim((string) $user->display_name));
            $first_name = isset($parts[0]) ? (string) $parts[0] : '';
            $last_name = count($parts) > 1 ? implode(' ', array_slice($parts, 1)) : '';
        }

        return array(
            'first_name' => (string) $first_name,
            'last_name' => (string) $last_name,
        );
    }

    private function signedStoragePath($storage_path)
    {
        $info = pathinfo((string) $storage_path);
        $directory = !empty($info['dirname']) && $info['dirname'] !== '.' ? trim($info['dirname'], '/') . '/' : '';
        $filename = isset($info['filename']) ? $info['filename'] : 'document';

        return $directory . $filename . '_signed.pdf';
    }

    private function compactMention($mention)
    {
        $lines = array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', (string) $mention))));

        return implode("\n", array_slice($lines, 0, 3));
    }

    private function pdfText($pdf, $text)
    {
        if (class_exists('\TCPDF') && $pdf instanceof \TCPDF) {
            return (string) $text;
        }

        return utf8_decode((string) $text);
    }

    private function pdfFontFamily()
    {
        /*
         * TCPDF ne charge pas "Arial" comme FPDF. Helvetica est une police coeur
         * disponible dans les deux piles, ce qui garde le rendu stable avec ou sans
         * signature cryptographique.
         */
        return 'helvetica';
    }
}
