<?php

namespace Smbb\SignConnect\Service;

use setasign\Fpdi\Fpdi;
use Smbb\SignConnect\Repository\DocumentAuditRepository;
use Smbb\SignConnect\Repository\DocumentRepository;
use Smbb\SignConnect\Repository\SignatureFieldRepository;
use Smbb\SignConnect\Repository\StorageRepository;
use Smbb\SignConnect\Support\SignConnectSettings;
use Smbb\SignConnect\Support\SignatureFieldType;

defined('ABSPATH') || exit;

final class YousignSignatureFlowService
{
    private $documents;
    private $storages;
    private $signature_fields;
    private $audit;
    private $store;
    private $pdf_generator;
    private $base_url;
    private $api_key;
    private $timeout;

    public function __construct(
        DocumentRepository $documents = null,
        StorageRepository $storages = null,
        SignatureFieldRepository $signature_fields = null,
        DocumentAuditRepository $audit = null,
        YousignSignatureFlowStore $store = null,
        SignedPdfGenerator $pdf_generator = null
    ) {
        $this->documents = $documents ?: new DocumentRepository();
        $this->storages = $storages ?: new StorageRepository();
        $this->signature_fields = $signature_fields ?: new SignatureFieldRepository();
        $this->audit = $audit ?: new DocumentAuditRepository();
        $this->store = $store ?: new YousignSignatureFlowStore();
        $this->pdf_generator = $pdf_generator ?: new SignedPdfGenerator($this->storages, $this->signature_fields);
        $this->api_key = SignConnectSettings::yousignApiKey();
        $this->base_url = SignConnectSettings::yousignSandbox()
            ? 'https://api-sandbox.yousign.app/v3'
            : 'https://api.yousign.app/v3';
        $this->timeout = SignConnectSettings::yousignTimeout();
    }

    public function start(array $document, $encoded_id, $token, array $post)
    {
        if (!SignConnectSettings::documentUsesYousignSignatureFlow($document) || !SignConnectSettings::certificationMethodAvailable(SignConnectSettings::documentCertificationMethod($document))) {
            throw new \RuntimeException(__('Yousign signing flow is not configured.', 'smbb-signconnect'));
        }

        $document_id = isset($document['id']) ? (int) $document['id'] : 0;
        $identity = $this->identityFromPost($post);
        $contact = $this->signerContact($document);

        if ($document_id < 1 || $token === '') {
            throw new \RuntimeException(__('Invalid signature link.', 'smbb-signconnect'));
        }

        if ($contact['email'] === '') {
            throw new \RuntimeException(__('Yousign signing requires a recipient email address.', 'smbb-signconnect'));
        }

        if ($identity['first_name'] === '' || $identity['last_name'] === '') {
            throw new \RuntimeException(__('Please enter your first and last name.', 'smbb-signconnect'));
        }

        if ($identity['place'] === '') {
            throw new \RuntimeException(__('Please enter the signing place or use geolocation.', 'smbb-signconnect'));
        }

        $storage = $this->storages->find(isset($document['storage_id']) ? (int) $document['storage_id'] : 0);
        $source_pdf = $this->downloadSourcePdf($storage, isset($document['storage_path']) ? (string) $document['storage_path'] : '');
        $prepared_pdf = wp_tempnam('signconnect-yousign-prepared.pdf');
        $position_pdf = '';
        $return_key = wp_generate_password(32, false, false);

        if (!$prepared_pdf) {
            @unlink($source_pdf);
            throw new \RuntimeException(__('The prepared PDF could not be created.', 'smbb-signconnect'));
        }

        try {
            $return = array(
                'status' => $this->externalSignatureReturnStatus($document),
                'message' => '',
            );
            $this->pdf_generator->prepareForExternalSignature($document, $source_pdf, $prepared_pdf, $identity, $return);
            $position_pdf = $this->pdfForPositioning($prepared_pdf);
            $signature_request = $this->createSignatureRequest($document);
            $yousign_document = $this->uploadDocument($signature_request['id'], $prepared_pdf, isset($document['filename']) ? (string) $document['filename'] : 'document.pdf');
            $field = $this->signatureFieldPayload($document_id, $position_pdf, $yousign_document['id']);
            $redirect_urls = SignConnectSettings::yousignSignatureDisableReturnRedirect()
                ? array()
                : $this->returnUrls($encoded_id, $token, $return_key);
            $signer = $this->addSigner($signature_request['id'], $yousign_document['id'], $field, $identity, $contact, $redirect_urls);
            $activated = $this->activateSignatureRequest($signature_request['id']);
            $signature_link = $this->signatureLink($activated, isset($signer['id']) ? (string) $signer['id'] : '');

            if ($signature_link === '') {
                throw new \RuntimeException(__('Yousign did not return a signature link.', 'smbb-signconnect'));
            }

            $this->store->save($document_id, array(
                'signature_request_id' => (string) $signature_request['id'],
                'yousign_document_id' => (string) $yousign_document['id'],
                'signer_id' => isset($signer['id']) ? (string) $signer['id'] : '',
                'return_key' => $return_key,
                'token' => (string) $token,
                'identity' => $identity,
                'contact' => $contact['email'],
                'last_status' => 'started',
                'started_at' => current_time('mysql'),
            ));

            $this->audit->record($document_id, 'yousign_signature_started', array(
                'signature_request_id' => (string) $signature_request['id'],
                'yousign_document_id' => (string) $yousign_document['id'],
            ), 'signer', null, __('Yousign signing flow started.', 'smbb-signconnect'));

            return array(
                'signature_link' => $signature_link,
                'signature_request_id' => (string) $signature_request['id'],
            );
        } finally {
            if ($position_pdf !== '' && $position_pdf !== $prepared_pdf) {
                @unlink($position_pdf);
            }
            @unlink($prepared_pdf);
            @unlink($source_pdf);
        }
    }

    public function finalize(array $document, $token, $return_key)
    {
        $document_id = isset($document['id']) ? (int) $document['id'] : 0;
        $flow = $this->store->get($document_id);

        if (!$flow || empty($flow['return_key']) || !hash_equals((string) $flow['return_key'], (string) $return_key)) {
            throw new \RuntimeException(__('Invalid Yousign return link.', 'smbb-signconnect'));
        }

        if (!empty($document['sign_date'])) {
            return $document;
        }

        return $this->finalizeFlow($document, $token, $flow);
    }

    public function checkPending($limit = 10)
    {
        $limit = max(1, min(50, (int) $limit));
        $checked = 0;
        $completed = 0;
        $errors = 0;

        foreach ($this->store->all() as $document_id => $flow) {
            if ($checked >= $limit) {
                break;
            }

            $document_id = absint($document_id);

            if ($document_id < 1 || !is_array($flow)) {
                continue;
            }

            $checked += 1;

            try {
                $document = $this->documents->find($document_id);

                if (!$document) {
                    $this->store->delete($document_id);
                    continue;
                }

                if (!empty($document['sign_date'])) {
                    $this->store->delete($document_id);
                    continue;
                }

                $request_id = isset($flow['signature_request_id']) ? (string) $flow['signature_request_id'] : '';

                if ($request_id === '') {
                    throw new \RuntimeException(__('Yousign signature request id is missing.', 'smbb-signconnect'));
                }

                $request = $this->getSignatureRequest($request_id);
                $status = isset($request['status']) ? (string) $request['status'] : '';

                if ($status !== 'done') {
                    $flow['last_status'] = $status !== '' ? $status : 'unknown';
                    $flow['last_checked_at'] = current_time('mysql');
                    $this->store->save($document_id, $flow);
                    continue;
                }

                $token = $this->storedPublicToken($flow, $document);

                if ($token === '') {
                    throw new \RuntimeException(__('Yousign pending signature cannot be finalized because the public token was not stored.', 'smbb-signconnect'));
                }

                $this->finalizeFlow($document, $token, $flow);
                $completed += 1;
            } catch (\Throwable $exception) {
                $errors += 1;
                $this->rememberPendingError($document_id, $flow, $exception);
            }
        }

        return array(
            'checked' => $checked,
            'completed' => $completed,
            'errors' => $errors,
        );
    }

    private function finalizeFlow(array $document, $token, array $flow)
    {
        $document_id = isset($document['id']) ? (int) $document['id'] : 0;
        $request_id = isset($flow['signature_request_id']) ? (string) $flow['signature_request_id'] : '';
        $yousign_document_id = isset($flow['yousign_document_id']) ? (string) $flow['yousign_document_id'] : '';
        $request = $this->getSignatureRequest($request_id);

        if ((isset($request['status']) ? (string) $request['status'] : '') !== 'done') {
            throw new \RuntimeException(__('Yousign has not completed the signature yet.', 'smbb-signconnect'));
        }

        $signed_pdf = wp_tempnam('signconnect-yousign-signed.pdf');

        if (!$signed_pdf) {
            throw new \RuntimeException(__('The temporary signed file could not be created.', 'smbb-signconnect'));
        }

        try {
            $this->downloadSignedDocument($request_id, $yousign_document_id, $signed_pdf);
            $storage_path = $this->uploadSignedPdf($document, $signed_pdf);
            $identity = isset($flow['identity']) && is_array($flow['identity']) ? $flow['identity'] : array();
            $signed_at = current_time('mysql');
            $source_hash = SignConnectSettings::certificationHashProofEnabled() && !empty($document['storage_path'])
                ? $this->sourceHash($document)
                : '';
            $signed_hash = SignConnectSettings::certificationHashProofEnabled() && is_readable($signed_pdf)
                ? hash_file('sha256', $signed_pdf)
                : '';

            $saved = $this->documents->markSigned($document_id, $token, array(
                'sign_date' => $signed_at,
                'signer_contact' => isset($flow['contact']) ? (string) $flow['contact'] : '',
                'signer_first_name' => isset($identity['first_name']) ? (string) $identity['first_name'] : '',
                'signer_last_name' => isset($identity['last_name']) ? (string) $identity['last_name'] : '',
                'signer_place' => isset($identity['place']) ? (string) $identity['place'] : '',
                'signer_return_status' => $this->externalSignatureReturnStatus($document),
                'signer_return_message' => '',
                'signer_latitude' => isset($identity['latitude']) ? (string) $identity['latitude'] : '',
                'signer_longitude' => isset($identity['longitude']) ? (string) $identity['longitude'] : '',
                'signature_data' => '',
                'signer_ip' => isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field((string) wp_unslash($_SERVER['REMOTE_ADDR'])) : '',
                'signer_user_agent' => isset($_SERVER['HTTP_USER_AGENT']) ? substr(sanitize_text_field((string) wp_unslash($_SERVER['HTTP_USER_AGENT'])), 0, 255) : '',
                'signed_storage_path' => $storage_path,
                'signed_file_size' => file_exists($signed_pdf) ? (int) filesize($signed_pdf) : 0,
                'source_sha256' => is_string($source_hash) ? $source_hash : '',
                'signed_sha256' => is_string($signed_hash) ? $signed_hash : '',
                'certification_fingerprint' => '',
                'cryptographic_signature_applied' => 1,
                'cryptographic_signature_status' => 'yousign_signature_done',
                'signed_pdf_date' => $signed_at,
            ));

            if (!$saved) {
                throw new \RuntimeException(__('The signature could not be saved.', 'smbb-signconnect'));
            }

            $this->audit->record($document_id, 'yousign_signature_completed', array(
                'signature_request_id' => $request_id,
                'yousign_document_id' => $yousign_document_id,
                'signed_sha256' => is_string($signed_hash) ? $signed_hash : '',
            ), 'signer', null, __('Document signed through Yousign.', 'smbb-signconnect'));
            $this->store->delete($document_id);

            return $saved;
        } finally {
            @unlink($signed_pdf);
        }
    }

    private function rememberPendingError($document_id, array $flow, \Throwable $exception)
    {
        $flow['last_checked_at'] = current_time('mysql');
        $flow['last_error'] = $exception->getMessage();
        $flow['error_count'] = isset($flow['error_count']) ? absint($flow['error_count']) + 1 : 1;
        $this->store->save($document_id, $flow);

        $this->documents->appendSystemLog($document_id, 'yousign_signature_cron_error', array(
            'message' => $exception->getMessage(),
            'class' => get_class($exception),
        ));

        $this->audit->record($document_id, 'yousign_signature_cron_error', array(
            'message' => $exception->getMessage(),
            'class' => get_class($exception),
        ), 'system', null, __('Yousign pending signature check failed.', 'smbb-signconnect'));
    }

    private function storedPublicToken(array $flow, array $document)
    {
        if (!empty($flow['token'])) {
            return (string) $flow['token'];
        }

        return !empty($document['token']) ? (string) $document['token'] : '';
    }

    private function createSignatureRequest(array $document)
    {
        return $this->request('POST', '/signature_requests', array(
            'name' => !empty($document['filename']) ? (string) $document['filename'] : 'SignConnect document',
            'delivery_mode' => 'none',
            'external_id' => isset($document['id']) ? 'signconnect-document-' . absint($document['id']) : null,
            'timezone' => $this->timezone(),
        ));
    }

    private function uploadDocument($signature_request_id, $pdf_path, $filename)
    {
        $boundary = 'smbb-signconnect-' . wp_generate_password(24, false, false);
        $body = $this->multipartBody($boundary, array(
            array('name' => 'nature', 'contents' => 'signable_document'),
            array('name' => 'file', 'path' => $pdf_path, 'filename' => $this->pdfFilename($filename), 'type' => 'application/pdf'),
        ));

        return $this->request('POST', '/signature_requests/' . rawurlencode($signature_request_id) . '/documents', null, array(
            'Content-Type' => 'multipart/form-data; boundary=' . $boundary,
        ), $body);
    }

    private function addSigner($signature_request_id, $document_id, array $field, array $identity, array $contact, array $redirect_urls = array())
    {
        $info = array(
            'first_name' => $identity['first_name'],
            'last_name' => $identity['last_name'],
            'email' => $contact['email'],
            'locale' => 'fr',
        );

        if ($contact['phone'] !== '' && $this->signatureAuthenticationMode() === 'otp_sms') {
            $info['phone_number'] = $contact['phone'];
        }

        $payload = array(
            'info' => $info,
            'signature_level' => $this->signatureLevel(),
            'signature_authentication_mode' => $this->signatureAuthenticationMode(),
            'delivery_mode' => 'none',
            'fields' => array($field),
        );

        if (!empty($redirect_urls)) {
            $payload['redirect_urls'] = $redirect_urls;
        }

        return $this->request('POST', '/signature_requests/' . rawurlencode($signature_request_id) . '/signers', $payload);
    }

    private function activateSignatureRequest($signature_request_id)
    {
        return $this->request('POST', '/signature_requests/' . rawurlencode($signature_request_id) . '/activate');
    }

    private function getSignatureRequest($signature_request_id)
    {
        return $this->request('GET', '/signature_requests/' . rawurlencode($signature_request_id));
    }

    private function downloadSignedDocument($signature_request_id, $document_id, $output_path)
    {
        $binary = $this->requestRaw(
            'GET',
            '/signature_requests/' . rawurlencode($signature_request_id) . '/documents/' . rawurlencode($document_id) . '/download?version=completed',
            null,
            array('Accept' => 'application/pdf')
        );

        if (file_put_contents($output_path, $binary) === false) {
            throw new \RuntimeException(__('The Yousign signed PDF could not be written.', 'smbb-signconnect'));
        }
    }

    private function signatureFieldPayload($document_id, $pdf_path, $yousign_document_id)
    {
        $field = $this->firstSignatureField($document_id);
        $size = $this->pageSizePoints($pdf_path, (int) $field['page_number']);

        return array(
            'type' => 'signature',
            'document_id' => $yousign_document_id,
            'page' => (int) $field['page_number'],
            'x' => max(0, (int) round((float) $field['x'] * $size['width'])),
            'y' => max(0, (int) round((float) $field['y'] * $size['height'])),
            'width' => max(60, (int) round((float) $field['width'] * $size['width'])),
            'height' => max(30, (int) round((float) $field['height'] * $size['height'])),
            'reason' => 'SignConnect',
        );
    }

    private function firstSignatureField($document_id)
    {
        foreach ($this->signature_fields->listForDocument($document_id) as $field) {
            $type = SignatureFieldType::normalize(isset($field['field_type']) ? (string) $field['field_type'] : SignatureFieldType::SIGNATURE);

            if ($type === SignatureFieldType::SIGNATURE) {
                return $field;
            }
        }

        throw new \RuntimeException(__('No signature area is defined.', 'smbb-signconnect'));
    }

    private function pageSizePoints($pdf_path, $page_number)
    {
        if (!class_exists(Fpdi::class)) {
            throw new \RuntimeException(__('The FPDI/FPDF PDF libraries are unavailable.', 'smbb-signconnect'));
        }

        $pdf = new Fpdi();
        $page_count = $pdf->setSourceFile($pdf_path);
        $page_number = max(1, min((int) $page_number, (int) $page_count));
        $template_id = $pdf->importPage($page_number);
        $size = $pdf->getTemplateSize($template_id);

        return array(
            'width' => (float) $size['width'] * 72 / 25.4,
            'height' => (float) $size['height'] * 72 / 25.4,
        );
    }

    private function pdfForPositioning($source_pdf)
    {
        try {
            $this->pageSizePoints($source_pdf, 1);

            return $source_pdf;
        } catch (\Throwable $exception) {
            if (!$this->isFpdiParserCompatibilityError($exception)) {
                throw $exception;
            }

            $normalized_pdf = $this->normalizePdfForFpdi($source_pdf);

            if ($normalized_pdf === '') {
                throw new \RuntimeException(__('This PDF uses a compression format unsupported by the bundled FPDI parser. Install qpdf, Poppler pdftocairo, or Ghostscript on the server to enable automatic PDF normalization.', 'smbb-signconnect'));
            }

            return $normalized_pdf;
        }
    }

    private function isFpdiParserCompatibilityError(\Throwable $exception)
    {
        $message = strtolower($exception->getMessage());

        return strpos($message, 'compression technique') !== false
            || strpos($message, 'object stream') !== false
            || strpos($message, 'xref') !== false
            || strpos($message, 'fpdi-pdf-parser') !== false;
    }

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
            $output = wp_tempnam('signconnect-yousign-normalized.pdf');

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

    private function downloadSourcePdf(array $storage = null, $storage_path)
    {
        if (!class_exists('\Smbb\WpCodeTool\Connector\SmbbS3Client') || !$storage || $storage_path === '') {
            throw new \RuntimeException(__('The source file is unavailable.', 'smbb-signconnect'));
        }

        $client = \Smbb\WpCodeTool\Connector\SmbbS3Client::fromSettings($storage);
        $object = $client->getObject($storage_path);
        $body = isset($object['Body']) ? $object['Body'] : null;
        $temporary_path = wp_tempnam('signconnect-yousign-source.pdf');

        if (!$body || !$temporary_path) {
            throw new \RuntimeException(__('The source PDF could not be read.', 'smbb-signconnect'));
        }

        $target = fopen($temporary_path, 'wb');

        if (!$target) {
            @unlink($temporary_path);
            throw new \RuntimeException(__('The temporary source PDF could not be created.', 'smbb-signconnect'));
        }

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

    private function uploadSignedPdf(array $document, $signed_pdf)
    {
        $storage = $this->storages->find(isset($document['storage_id']) ? (int) $document['storage_id'] : 0);
        $source_path = isset($document['storage_path']) ? (string) $document['storage_path'] : '';

        if (!class_exists('\Smbb\WpCodeTool\Connector\SmbbS3Client') || !$storage || $source_path === '') {
            throw new \RuntimeException(__('The document storage is unavailable.', 'smbb-signconnect'));
        }

        $signed_path = $this->signedStoragePath($source_path);
        $body = fopen($signed_pdf, 'rb');

        if (!$body) {
            throw new \RuntimeException(__('The signed PDF could not be reread.', 'smbb-signconnect'));
        }

        try {
            $client = \Smbb\WpCodeTool\Connector\SmbbS3Client::fromSettings($storage);
            $client->putObject($signed_path, $body, array('ContentType' => 'application/pdf'));
        } finally {
            if (is_resource($body)) {
                fclose($body);
            }
        }

        return $signed_path;
    }

    private function sourceHash(array $document)
    {
        $storage = $this->storages->find(isset($document['storage_id']) ? (int) $document['storage_id'] : 0);
        $source = $this->downloadSourcePdf($storage, isset($document['storage_path']) ? (string) $document['storage_path'] : '');

        try {
            return is_readable($source) ? hash_file('sha256', $source) : '';
        } finally {
            @unlink($source);
        }
    }

    private function signedStoragePath($storage_path)
    {
        $info = pathinfo((string) $storage_path);
        $directory = !empty($info['dirname']) && $info['dirname'] !== '.' ? trim($info['dirname'], '/') . '/' : '';
        $filename = isset($info['filename']) ? $info['filename'] : 'document';

        return $directory . $filename . '_yousign_signed.pdf';
    }

    private function signerContact(array $document)
    {
        return array(
            'email' => !empty($document['recipient_email']) ? sanitize_email((string) $document['recipient_email']) : '',
            'phone' => !empty($document['recipient_phone']) ? sanitize_text_field((string) $document['recipient_phone']) : '',
        );
    }

    private function externalSignatureReturnStatus(array $document)
    {
        return isset($document['return_expected']) && (string) $document['return_expected'] === 'approval_refusal'
            ? 'approved'
            : 'signed';
    }

    private function identityFromPost(array $post)
    {
        $latitude = isset($post['signer_latitude']) ? sanitize_text_field((string) wp_unslash($post['signer_latitude'])) : '';
        $longitude = isset($post['signer_longitude']) ? sanitize_text_field((string) wp_unslash($post['signer_longitude'])) : '';
        $place = isset($post['signer_place']) ? sanitize_text_field((string) wp_unslash($post['signer_place'])) : '';

        if ($place === '' && $latitude !== '' && $longitude !== '') {
            $place = 'gps';
        }

        return array(
            'first_name' => isset($post['signer_first_name']) ? sanitize_text_field((string) wp_unslash($post['signer_first_name'])) : '',
            'last_name' => isset($post['signer_last_name']) ? sanitize_text_field((string) wp_unslash($post['signer_last_name'])) : '',
            'place' => $place,
            'latitude' => is_numeric($latitude) ? (string) $latitude : '',
            'longitude' => is_numeric($longitude) ? (string) $longitude : '',
        );
    }

    private function returnUrls($encoded_id, $token, $return_key)
    {
        $url = add_query_arg(array(
            'signconnect_sign' => (string) $encoded_id,
            'signconnect_token' => (string) $token,
            'signconnect_yousign_return' => '1',
            'signconnect_yousign_key' => (string) $return_key,
        ), SignConnectSettings::signingPageUrl());

        return array(
            'success' => $url,
            'error' => $url,
            'decline' => $url,
        );
    }

    private function signatureLevel()
    {
        $level = SignConnectSettings::yousignSignatureLevel();

        return in_array($level, array('electronic_signature', 'advanced_electronic_signature'), true)
            ? $level
            : 'electronic_signature';
    }

    private function signatureAuthenticationMode()
    {
        /*
         * The first implementation deliberately stays on SES/AES-compatible
         * authentication. QES requires an identity verification journey that we
         * should wire explicitly instead of silently sending an invalid request.
         */
        return 'no_otp';
    }

    private function timezone()
    {
        $timezone = function_exists('wp_timezone_string') ? (string) wp_timezone_string() : '';

        if ($timezone !== '' && in_array($timezone, \DateTimeZone::listIdentifiers(), true)) {
            return $timezone;
        }

        return 'Europe/Paris';
    }

    private function signatureLink(array $activated, $signer_id)
    {
        if (!empty($activated['signers']) && is_array($activated['signers'])) {
            foreach ($activated['signers'] as $signer) {
                if ($signer_id !== '' && isset($signer['id']) && (string) $signer['id'] !== $signer_id) {
                    continue;
                }

                if (!empty($signer['signature_link'])) {
                    return (string) $signer['signature_link'];
                }
            }
        }

        return '';
    }

    private function request($method, $endpoint, array $json_payload = null, array $headers = array(), $body = null)
    {
        $response = $this->requestRaw($method, $endpoint, $json_payload, $headers, $body);
        $decoded = json_decode($response, true);

        if (!is_array($decoded)) {
            throw new \RuntimeException(sprintf(__('Invalid Yousign JSON response: %s', 'smbb-signconnect'), $response));
        }

        return $decoded;
    }

    private function requestRaw($method, $endpoint, array $json_payload = null, array $headers = array(), $body = null)
    {
        if ($this->api_key === '') {
            throw new \RuntimeException(__('Yousign API key is missing.', 'smbb-signconnect'));
        }

        $headers = array_merge(array(
            'Authorization' => 'Bearer ' . $this->api_key,
            'Accept' => 'application/json',
        ), $headers);

        if ($json_payload !== null) {
            $headers['Content-Type'] = 'application/json';
            $body = wp_json_encode(array_filter($json_payload, function ($value) {
                return $value !== null;
            }));
        }

        $response = wp_remote_request($this->base_url . $endpoint, array(
            'method' => $method,
            'headers' => $headers,
            'body' => $body,
            'timeout' => $this->timeout,
        ));

        if (is_wp_error($response)) {
            throw new \RuntimeException(sprintf(__('Yousign request failed: %s', 'smbb-signconnect'), $response->get_error_message()));
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $response_body = (string) wp_remote_retrieve_body($response);

        if ($code < 200 || $code >= 300) {
            throw new \RuntimeException(sprintf(__('Yousign HTTP %1$d: %2$s', 'smbb-signconnect'), $code, $response_body));
        }

        return $response_body;
    }

    private function multipartBody($boundary, array $parts)
    {
        $eol = "\r\n";
        $body = '';

        foreach ($parts as $part) {
            $body .= '--' . $boundary . $eol;

            if (isset($part['path'])) {
                $contents = file_get_contents((string) $part['path']);

                if ($contents === false) {
                    throw new \RuntimeException(__('The PDF to sign could not be read.', 'smbb-signconnect'));
                }

                $body .= 'Content-Disposition: form-data; name="' . $part['name'] . '"; filename="' . $part['filename'] . '"' . $eol;
                $body .= 'Content-Type: ' . $part['type'] . $eol . $eol;
                $body .= $contents . $eol;
                continue;
            }

            $body .= 'Content-Disposition: form-data; name="' . $part['name'] . '"' . $eol . $eol;
            $body .= (string) $part['contents'] . $eol;
        }

        return $body . '--' . $boundary . '--' . $eol;
    }

    private function pdfFilename($filename)
    {
        $filename = sanitize_file_name((string) $filename);

        if ($filename === '') {
            $filename = 'document.pdf';
        }

        if (strtolower(pathinfo($filename, PATHINFO_EXTENSION)) !== 'pdf') {
            $filename .= '.pdf';
        }

        return $filename;
    }
}
