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

final class UniversignSignatureFlowService
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
        UniversignSignatureFlowStore $store = null,
        SignedPdfGenerator $pdf_generator = null
    ) {
        $this->documents = $documents ?: new DocumentRepository();
        $this->storages = $storages ?: new StorageRepository();
        $this->signature_fields = $signature_fields ?: new SignatureFieldRepository();
        $this->audit = $audit ?: new DocumentAuditRepository();
        $this->store = $store ?: new UniversignSignatureFlowStore();
        $this->pdf_generator = $pdf_generator ?: new SignedPdfGenerator($this->storages, $this->signature_fields);
        $this->api_key = SignConnectSettings::universignApiKey();
        $this->base_url = SignConnectSettings::universignBaseUrl();
        $this->timeout = SignConnectSettings::universignTimeout();
    }

    public function start(array $document, $encoded_id, $token, array $post)
    {
        if (!SignConnectSettings::documentUsesUniversignSignatureFlow($document) || !SignConnectSettings::certificationMethodAvailable(SignConnectSettings::documentCertificationMethod($document))) {
            throw new \RuntimeException(__('Universign signing flow is not configured.', 'smbb-signconnect'));
        }

        $document_id = isset($document['id']) ? (int) $document['id'] : 0;
        $identity = $this->identityFromPost($post);
        $contact = $this->signerContact($document);

        if ($document_id < 1 || $token === '') {
            throw new \RuntimeException(__('Invalid signature link.', 'smbb-signconnect'));
        }

        if ($contact['email'] === '') {
            throw new \RuntimeException(__('Universign signing requires a recipient email address.', 'smbb-signconnect'));
        }

        if ($identity['first_name'] === '' || $identity['last_name'] === '') {
            throw new \RuntimeException(__('Please enter your first and last name.', 'smbb-signconnect'));
        }

        if ($identity['place'] === '') {
            throw new \RuntimeException(__('Please enter the signing place or use geolocation.', 'smbb-signconnect'));
        }

        $storage = $this->storages->find(isset($document['storage_id']) ? (int) $document['storage_id'] : 0);
        $source_pdf = $this->downloadSourcePdf($storage, isset($document['storage_path']) ? (string) $document['storage_path'] : '');
        $prepared_pdf = wp_tempnam('signconnect-universign-prepared.pdf');
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
            $transaction = $this->createTransaction($document, $encoded_id, $token, $return_key);
            $transaction_id = isset($transaction['id']) ? (string) $transaction['id'] : '';
            $file = $this->uploadFile($prepared_pdf, isset($document['filename']) ? (string) $document['filename'] : 'document.pdf');
            $file_id = isset($file['id']) ? (string) $file['id'] : '';
            $universign_document = $this->addDocument($transaction_id, $file_id, isset($document['filename']) ? (string) $document['filename'] : 'document.pdf');
            $universign_document_id = isset($universign_document['id']) ? (string) $universign_document['id'] : '';
            $field = $this->addSignatureField($document_id, $transaction_id, $universign_document_id, $position_pdf);
            $signature = $this->addSignature($transaction_id, $universign_document_id, $field, $identity, $contact);
            $started = $this->startTransaction($transaction_id);
            $signature_link = $this->signatureLink($started, $signature, $encoded_id, $token, $return_key);

            if ($signature_link === '') {
                throw new \RuntimeException(__('Universign did not return a signature link.', 'smbb-signconnect'));
            }

            $this->store->save($document_id, array(
                'transaction_id' => $transaction_id,
                'file_id' => $file_id,
                'universign_document_id' => $universign_document_id,
                'signature_id' => isset($signature['id']) ? (string) $signature['id'] : '',
                'return_key' => $return_key,
                'return_url' => $this->returnUrl($encoded_id, $token, $return_key),
                'token' => (string) $token,
                'identity' => $identity,
                'contact' => $contact['email'],
                'last_status' => 'started',
                'started_at' => current_time('mysql'),
            ));

            $this->audit->record($document_id, 'universign_signature_started', array(
                'transaction_id' => $transaction_id,
                'universign_document_id' => $universign_document_id,
                'return_url' => $this->returnUrl($encoded_id, $token, $return_key),
            ), 'signer', null, __('Universign signing flow started.', 'smbb-signconnect'));

            return array(
                'signature_link' => $signature_link,
                'transaction_id' => $transaction_id,
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
            throw new \RuntimeException(__('Invalid Universign return link.', 'smbb-signconnect'));
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

                $transaction_id = isset($flow['transaction_id']) ? (string) $flow['transaction_id'] : '';

                if ($transaction_id === '') {
                    throw new \RuntimeException(__('Universign transaction id is missing.', 'smbb-signconnect'));
                }

                $transaction = $this->getTransaction($transaction_id);
                $status = $this->transactionStatus($transaction);

                if (!$this->transactionCompleted($status)) {
                    $flow['last_status'] = $status !== '' ? $status : 'unknown';
                    $flow['last_checked_at'] = current_time('mysql');
                    $this->store->save($document_id, $flow);
                    continue;
                }

                $token = $this->storedPublicToken($flow, $document);

                if ($token === '') {
                    throw new \RuntimeException(__('Universign pending signature cannot be finalized because the public token was not stored.', 'smbb-signconnect'));
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
        $transaction_id = isset($flow['transaction_id']) ? (string) $flow['transaction_id'] : '';
        $universign_document_id = isset($flow['universign_document_id']) ? (string) $flow['universign_document_id'] : '';
        $transaction = $this->waitForCompletedTransaction($transaction_id);
        $status = $this->transactionStatus($transaction);

        if (!$this->transactionCompleted($status)) {
            throw new \RuntimeException(sprintf(
                __('Universign has not completed the signature yet. Current status: %s', 'smbb-signconnect'),
                $status !== '' ? $status : 'unknown'
            ));
        }

        $signed_pdf = wp_tempnam('signconnect-universign-signed.pdf');

        if (!$signed_pdf) {
            throw new \RuntimeException(__('The temporary signed file could not be created.', 'smbb-signconnect'));
        }

        try {
            $this->downloadSignedDocument($transaction_id, $universign_document_id, $signed_pdf);
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
                'signed_storage_path' => $storage_path,
                'signer_ip' => isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field((string) wp_unslash($_SERVER['REMOTE_ADDR'])) : '',
                'signer_user_agent' => isset($_SERVER['HTTP_USER_AGENT']) ? substr(sanitize_text_field((string) wp_unslash($_SERVER['HTTP_USER_AGENT'])), 0, 255) : '',
                'source_hash_sha256' => $source_hash,
                'signed_hash_sha256' => $signed_hash,
                'cryptographic_signature_applied' => 1,
                'cryptographic_signature_status' => 'universign_signature_done',
                'cryptographic_signature_message' => 'Universign transaction ' . $transaction_id,
            ));

            $this->store->delete($document_id);
            $this->audit->record($document_id, 'universign_signature_completed', array(
                'transaction_id' => $transaction_id,
                'universign_document_id' => $universign_document_id,
                'storage_path' => $storage_path,
            ), 'signer', null, __('Document signed through Universign.', 'smbb-signconnect'));

            return $saved ?: $this->documents->find($document_id);
        } finally {
            @unlink($signed_pdf);
        }
    }

    private function rememberPendingError($document_id, array $flow, \Throwable $exception)
    {
        $flow['last_error'] = $exception->getMessage();
        $flow['last_error_at'] = current_time('mysql');
        $this->store->save($document_id, $flow);

        $this->documents->appendSystemLog($document_id, 'universign_signature_cron_error', array(
            'message' => $exception->getMessage(),
            'class' => get_class($exception),
        ));

        $this->audit->record($document_id, 'universign_signature_cron_error', array(
            'message' => $exception->getMessage(),
            'class' => get_class($exception),
        ), 'system', null, __('Universign pending signature check failed.', 'smbb-signconnect'));
    }

    private function storedPublicToken(array $flow, array $document)
    {
        if (!empty($flow['token'])) {
            return (string) $flow['token'];
        }

        return !empty($document['token']) ? (string) $document['token'] : '';
    }

    private function createTransaction(array $document, $encoded_id, $token, $return_key)
    {
        $payload = array(
            'type' => 'sign',
            'name' => !empty($document['filename']) ? (string) $document['filename'] : 'SignConnect document',
            'description' => 'SignConnect document',
        );

        return $this->request('POST', '/v1/transactions', $payload);
    }

    private function uploadFile($pdf_path, $filename)
    {
        $boundary = 'smbb-signconnect-' . wp_generate_password(24, false, false);
        $body = $this->multipartBody($boundary, array(
            array('name' => 'file', 'path' => $pdf_path, 'filename' => $this->pdfFilename($filename), 'type' => 'application/pdf'),
        ));

        return $this->request('POST', '/v1/files', null, array(
            'Content-Type' => 'multipart/form-data; boundary=' . $boundary,
        ), $body);
    }

    private function addDocument($transaction_id, $file_id, $filename)
    {
        if ($transaction_id === '' || $file_id === '') {
            throw new \RuntimeException(__('Universign transaction or file id is missing.', 'smbb-signconnect'));
        }

        return $this->request('POST', '/v1/transactions/' . rawurlencode($transaction_id) . '/documents', array(
            'document' => $file_id,
            'name' => $this->pdfFilename($filename),
        ));
    }

    private function addSignatureField($document_id, $transaction_id, $universign_document_id, $pdf_path)
    {
        $field = $this->signatureFieldPayload($document_id, $pdf_path);

        return $this->request('POST', '/v1/transactions/' . rawurlencode($transaction_id) . '/documents/' . rawurlencode($universign_document_id) . '/fields', $field);
    }

    private function addSignature($transaction_id, $universign_document_id, array $field, array $identity, array $contact)
    {
        $field_id = isset($field['id']) ? (string) $field['id'] : '';

        if ($field_id === '') {
            throw new \RuntimeException(__('Universign signature field id is missing.', 'smbb-signconnect'));
        }

        $payload = array(
            'signer' => $contact['email'],
            'level' => SignConnectSettings::universignSignatureLevel(),
            'field' => $field_id,
        );

        if ($contact['phone'] !== '') {
            $payload['phone'] = $contact['phone'];
        }

        return $this->request('POST', '/v1/transactions/' . rawurlencode($transaction_id) . '/signatures', $payload);
    }

    private function startTransaction($transaction_id)
    {
        return $this->request('POST', '/v1/transactions/' . rawurlencode($transaction_id) . '/start');
    }

    private function getTransaction($transaction_id)
    {
        return $this->request('GET', '/v1/transactions/' . rawurlencode($transaction_id));
    }

    private function waitForCompletedTransaction($transaction_id)
    {
        $started_at = time();
        $max_wait_seconds = SignConnectSettings::universignMaxWaitSeconds();
        $poll_interval = SignConnectSettings::universignPollInterval();
        $transaction = array();

        do {
            $transaction = $this->getTransaction($transaction_id);
            $status = $this->transactionStatus($transaction);

            if ($this->transactionCompleted($status) || $this->transactionHasCompletedSignatures($transaction)) {
                return $transaction;
            }

            sleep($poll_interval);
        } while ((time() - $started_at) < $max_wait_seconds);

        return $transaction;
    }

    private function downloadSignedDocument($transaction_id, $document_id, $output_path)
    {
        if ($transaction_id === '' || $document_id === '') {
            throw new \RuntimeException(__('Universign signed document id is missing.', 'smbb-signconnect'));
        }

        $binary = $this->requestRaw(
            'GET',
            '/v1/transactions/' . rawurlencode($transaction_id) . '/archive/documents/' . rawurlencode($document_id) . '/download',
            null,
            array('Accept' => 'application/pdf')
        );

        if (file_put_contents($output_path, $binary) === false) {
            throw new \RuntimeException(__('The Universign signed PDF could not be written.', 'smbb-signconnect'));
        }
    }

    private function signatureFieldPayload($document_id, $pdf_path)
    {
        $field = $this->firstSignatureField($document_id);
        $size = $this->pageSizePoints($pdf_path, (int) $field['page_number']);
        $field_width = max(60, min(180, (int) round((float) $field['width'] * $size['width'])));
        $field_height = max(30, min(45, (int) round((float) $field['height'] * $size['height'])));
        $x = max(0, (int) round((float) $field['x'] * $size['width']));
        $y = max(0, (int) round((1 - (float) $field['y'] - (float) $field['height']) * $size['height']));
        $safe_width = max(0, (int) floor($size['width']) - 24);
        $safe_height = max(0, (int) floor($size['height']) - 24);
        $universign_signature_width = 200;
        $universign_signature_height = 50;

        $x = min($x, max(0, $safe_width - $universign_signature_width));
        $y = min($y, max(0, $safe_height - $universign_signature_height));

        return array(
            'type' => 'signature',
            'page' => (int) $field['page_number'],
            'x' => $x,
            'y' => $y,
            'width' => $field_width,
            'height' => $field_height,
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
            $output = wp_tempnam('signconnect-universign-normalized.pdf');

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
        $temporary_path = wp_tempnam('signconnect-universign-source.pdf');

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

        return $directory . $filename . '_universign_signed.pdf';
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

    private function returnUrl($encoded_id, $token, $return_key)
    {
        return add_query_arg(array(
            'signconnect_sign' => (string) $encoded_id,
            'signconnect_token' => (string) $token,
            'signconnect_universign_return' => '1',
            'signconnect_universign_key' => (string) $return_key,
        ), SignConnectSettings::universignReturnUrl());
    }

    private function transactionStatus(array $transaction)
    {
        foreach (array('state', 'status') as $key) {
            if (!empty($transaction[$key])) {
                return strtolower((string) $transaction[$key]);
            }
        }

        return '';
    }

    private function transactionCompleted($status)
    {
        return in_array((string) $status, array('completed', 'done', 'finished', 'archived', 'closed', 'signed'), true);
    }

    private function transactionHasCompletedSignatures(array $transaction)
    {
        if (empty($transaction['signatures']) || !is_array($transaction['signatures'])) {
            return false;
        }

        $found = false;

        foreach ($transaction['signatures'] as $signature) {
            if (!is_array($signature)) {
                continue;
            }

            $status = '';

            foreach (array('status', 'state') as $key) {
                if (!empty($signature[$key])) {
                    $status = strtolower((string) $signature[$key]);
                    break;
                }
            }

            if ($status === '') {
                continue;
            }

            $found = true;

            if (!in_array($status, array('completed', 'done', 'finished', 'signed'), true)) {
                return false;
            }
        }

        return $found;
    }

    private function signatureLink(array $transaction, array $signature, $encoded_id, $token, $return_key)
    {
        $return_url = SignConnectSettings::universignDisableReturnRedirect()
            ? ''
            : $this->returnUrl($encoded_id, $token, $return_key);

        if (!empty($signature['url'])) {
            return $this->withRedirectUrl((string) $signature['url'], $return_url);
        }

        if (!empty($signature['action']['url'])) {
            return $this->withRedirectUrl((string) $signature['action']['url'], $return_url);
        }

        $signature_id = isset($signature['id']) ? (string) $signature['id'] : '';

        if (!empty($transaction['actions']) && is_array($transaction['actions'])) {
            foreach ($transaction['actions'] as $action) {
                if ($signature_id !== '' && !empty($action['signature']) && (string) $action['signature'] !== $signature_id) {
                    continue;
                }

                if (!empty($action['url'])) {
                    return $this->withRedirectUrl((string) $action['url'], $return_url);
                }
            }
        }

        if (!empty($transaction['signatures']) && is_array($transaction['signatures'])) {
            foreach ($transaction['signatures'] as $transaction_signature) {
                if ($signature_id !== '' && isset($transaction_signature['id']) && (string) $transaction_signature['id'] !== $signature_id) {
                    continue;
                }

                if (!empty($transaction_signature['url'])) {
                    return $this->withRedirectUrl((string) $transaction_signature['url'], $return_url);
                }

                if (!empty($transaction_signature['action']['url'])) {
                    return $this->withRedirectUrl((string) $transaction_signature['action']['url'], $return_url);
                }
            }
        }

        return '';
    }

    private function withRedirectUrl($signature_url, $return_url)
    {
        if ($signature_url === '' || $return_url === '') {
            return $signature_url;
        }

        $separator = strpos($signature_url, '?') === false ? '?' : '&';

        return $signature_url
            . $separator
            . 'redirect_url=' . rawurlencode($return_url)
            . '&redirect_time_out=1';
    }

    private function request($method, $endpoint, array $payload = null, array $headers = array(), $body = null)
    {
        $response = $this->requestRaw($method, $endpoint, $payload, $headers, $body);
        $decoded = json_decode($response, true);

        if (!is_array($decoded)) {
            throw new \RuntimeException(sprintf(__('Invalid Universign JSON response: %s', 'smbb-signconnect'), $response));
        }

        return $decoded;
    }

    private function requestRaw($method, $endpoint, array $payload = null, array $headers = array(), $body = null)
    {
        if ($this->api_key === '') {
            throw new \RuntimeException(__('Universign API key is missing.', 'smbb-signconnect'));
        }

        $extra_headers = $headers;
        $auth_modes = array(SignConnectSettings::universignAuthMode());
        $alternate_mode = $auth_modes[0] === 'basic' ? 'bearer' : 'basic';
        $auth_modes[] = $alternate_mode;
        $last_error = '';

        foreach ($auth_modes as $auth_mode) {
            $headers = array_merge($this->defaultHeadersForAuthMode($auth_mode), $extra_headers);
            $request_body = $body;

            if ($payload !== null) {
                $headers['Content-Type'] = 'application/x-www-form-urlencoded';
                $request_body = http_build_query($this->cleanPayload($payload), '', '&');
            }

            $response = wp_remote_request($this->base_url . $endpoint, array(
                'method' => $method,
                'headers' => $headers,
                'body' => $request_body,
                'timeout' => $this->timeout,
            ));

            if (is_wp_error($response)) {
                throw new \RuntimeException(sprintf(
                    __('Universign request failed: %s', 'smbb-signconnect'),
                    $response->get_error_message()
                ));
            }

            $code = (int) wp_remote_retrieve_response_code($response);
            $response_body = (string) wp_remote_retrieve_body($response);

            if ($code >= 200 && $code < 300) {
                return $response_body;
            }

            $last_error = sprintf(
                __('Universign HTTP %1$d using %2$s auth at %3$s: %4$s', 'smbb-signconnect'),
                $code,
                $auth_mode,
                $this->base_url,
                $response_body
            );

            if (!$this->isAuthenticationRequiredResponse($code, $response_body)) {
                throw new \RuntimeException($last_error);
            }
        }

        throw new \RuntimeException($last_error !== '' ? $last_error : __('Universign authentication failed.', 'smbb-signconnect'));
    }

    private function defaultHeadersForAuthMode($auth_mode)
    {
        $headers = array('Accept' => 'application/json');

        if ($auth_mode === 'bearer') {
            $headers['Authorization'] = 'Bearer ' . $this->api_key;

            return $headers;
        }

        $headers['Authorization'] = 'Basic ' . base64_encode($this->api_key . ':');

        return $headers;
    }

    private function isAuthenticationRequiredResponse($code, $response_body)
    {
        if (!in_array((int) $code, array(401, 403), true)) {
            return false;
        }

        $body = strtolower((string) $response_body);

        return strpos($body, 'authentication_required') !== false
            || strpos($body, 'authentication required') !== false
            || strpos($body, 'authentication_error') !== false;
    }

    private function cleanPayload(array $payload)
    {
        return array_filter($payload, function ($value) {
            return $value !== null && $value !== '';
        });
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
