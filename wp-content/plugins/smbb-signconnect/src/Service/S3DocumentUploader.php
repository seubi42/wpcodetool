<?php

namespace Smbb\SignConnect\Service;

defined('ABSPATH') || exit;

final class S3DocumentUploader
{
    public function upload(array $storage, array $file, $user_id)
    {
        if (!class_exists('\Smbb\WpCodeTool\Connector\SmbbS3Client')) {
            throw new \RuntimeException(__('The S3 connector from SMBB WP CodeTool is unavailable.', 'smbb-signconnect'));
        }

        if (empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
            throw new \RuntimeException(__('The uploaded file is missing.', 'smbb-signconnect'));
        }

        if (!empty($file['error'])) {
            throw new \RuntimeException($this->uploadErrorMessage((int) $file['error']));
        }

        $original_name = isset($file['name']) ? (string) $file['name'] : 'document';
        $safe_name = sanitize_file_name($original_name);
        $this->assertPdfFile($file, $safe_name);

        if ($safe_name === '') {
            $safe_name = 'document';
        }

        $storage_path = $this->buildStoragePath($safe_name, $user_id);
        $client = \Smbb\WpCodeTool\Connector\SmbbS3Client::fromSettings($storage);
        $body = fopen($file['tmp_name'], 'rb');

        if (!$body) {
            throw new \RuntimeException(__('The uploaded file cannot be read.', 'smbb-signconnect'));
        }

        try {
            $options = array();
            $options['ContentType'] = 'application/pdf';

            $client->putObject($storage_path, $body, $options);
        } finally {
            if (is_resource($body)) {
                fclose($body);
            }
        }

        return $storage_path;
    }

    private function assertPdfFile(array $file, $safe_name)
    {
        if (strtolower(pathinfo((string) $safe_name, PATHINFO_EXTENSION)) !== 'pdf') {
            throw new \RuntimeException(__('Only PDF files are allowed.', 'smbb-signconnect'));
        }

        if (!function_exists('wp_check_filetype_and_ext')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }

        $check = wp_check_filetype_and_ext(
            isset($file['tmp_name']) ? (string) $file['tmp_name'] : '',
            isset($file['name']) ? (string) $file['name'] : '',
            array('pdf' => 'application/pdf')
        );

        if (empty($check['ext']) || $check['ext'] !== 'pdf') {
            throw new \RuntimeException(__('The uploaded file does not appear to be a valid PDF.', 'smbb-signconnect'));
        }
    }

    private function buildStoragePath($safe_name, $user_id)
    {
        $extension = pathinfo($safe_name, PATHINFO_EXTENSION);
        $base = pathinfo($safe_name, PATHINFO_FILENAME);
        $base = sanitize_title($base);

        if ($base === '') {
            $base = 'document';
        }

        $unique = gmdate('Ymd-His') . '-' . wp_generate_password(10, false, false);
        $filename = $base . '-' . $unique;

        if ($extension !== '') {
            $filename .= '.' . strtolower($extension);
        }

        return 'users/' . absint($user_id) . '/' . gmdate('Y/m/d') . '/' . $filename;
    }

    private function uploadErrorMessage($code)
    {
        switch ($code) {
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                return __('The uploaded file is too large.', 'smbb-signconnect');
            case UPLOAD_ERR_PARTIAL:
                return __('The file was only partially uploaded.', 'smbb-signconnect');
            case UPLOAD_ERR_NO_FILE:
                return __('Please choose a file to upload.', 'smbb-signconnect');
            default:
                return __('File upload failed.', 'smbb-signconnect');
        }
    }
}
