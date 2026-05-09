<?php

namespace Smbb\SignConnect\Service;

defined('ABSPATH') || exit;

final class S3DocumentStreamer
{
    public function stream(array $storage, array $document, $disposition = 'inline')
    {
        if (!class_exists('\Smbb\WpCodeTool\Connector\SmbbS3Client')) {
            throw new \RuntimeException(__('The S3 connector from SMBB WP CodeTool is unavailable.', 'smbb-signconnect'));
        }

        $storage_path = !empty($document['signed_storage_path'])
            ? (string) $document['signed_storage_path']
            : (isset($document['storage_path']) ? (string) $document['storage_path'] : '');

        if ($storage_path === '') {
            throw new \RuntimeException(__('The associated file is unavailable.', 'smbb-signconnect'));
        }

        $client = \Smbb\WpCodeTool\Connector\SmbbS3Client::fromSettings($storage);
        $object = $client->getObject($storage_path);
        $body = isset($object['Body']) ? $object['Body'] : null;

        if (!$body) {
            throw new \RuntimeException(__('The PDF file cannot be read.', 'smbb-signconnect'));
        }

        $filename = !empty($document['filename']) ? sanitize_file_name((string) $document['filename']) : 'document.pdf';

        if ($filename === '') {
            $filename = 'document.pdf';
        }

        if (strtolower(pathinfo($filename, PATHINFO_EXTENSION)) !== 'pdf') {
            $filename .= '.pdf';
        }

        if (!empty($document['signed_storage_path'])) {
            $info = pathinfo($filename);
            $filename = (isset($info['filename']) ? $info['filename'] : 'document') . '_signed.pdf';
        }

        $disposition = $disposition === 'attachment' ? 'attachment' : 'inline';

        $this->clearOutputBuffers();
        nocache_headers();

        header('Content-Type: application/pdf');
        header('Content-Disposition: ' . $disposition . '; filename="' . str_replace('"', '', $filename) . '"');
        header('X-Content-Type-Options: nosniff');

        if (isset($object['ContentLength']) && (int) $object['ContentLength'] > 0) {
            header('Content-Length: ' . (int) $object['ContentLength']);
        }

        if (is_object($body) && method_exists($body, 'eof') && method_exists($body, 'read')) {
            while (!$body->eof()) {
                echo $body->read(1048576);
                flush();
            }
        } elseif (is_object($body) && method_exists($body, 'getContents')) {
            echo $body->getContents();
        } else {
            echo (string) $body;
        }

        exit;
    }

    private function clearOutputBuffers()
    {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
    }
}
