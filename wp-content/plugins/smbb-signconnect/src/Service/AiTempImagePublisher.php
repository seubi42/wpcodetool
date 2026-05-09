<?php

namespace Smbb\SignConnect\Service;

defined('ABSPATH') || exit;

/**
 * Publie temporairement une image dans wp-content/ai-temp.
 *
 * Objectif : fournir a OpenAI une URL HTTP simple et joignable, plus proche de
 * l'expérience ChatGPT qu'une data URL base64 ou qu'une URL S3 pré-signée qui
 * peut varier selon les providers S3 compatibles.
 */
final class AiTempImagePublisher
{
    const RELATIVE_DIR = 'ai-temp';
    const TTL = 7200;

    public function publish($local_path)
    {
        if (!is_readable($local_path)) {
            return array('success' => false, 'error' => 'Local image is not readable.');
        }

        $directory = trailingslashit(WP_CONTENT_DIR) . self::RELATIVE_DIR;

        if (!wp_mkdir_p($directory)) {
            return array('success' => false, 'error' => 'Unable to create wp-content/ai-temp.');
        }

        $this->ensureIndexFile($directory);
        $this->cleanupOldFiles($directory);

        $filename = 'signconnect-' . gmdate('YmdHis') . '-' . wp_generate_password(16, false, false) . '.png';
        $target_path = trailingslashit($directory) . $filename;

        if (!copy($local_path, $target_path)) {
            return array('success' => false, 'error' => 'Unable to copy image into ai-temp.');
        }

        return array(
            'success' => true,
            'path' => $target_path,
            'url' => content_url(self::RELATIVE_DIR . '/' . $filename),
        );
    }

    public function cleanup(array $published)
    {
        if (defined('SMBB_SIGNCONNECT_KEEP_AI_TEMP_FILES') && SMBB_SIGNCONNECT_KEEP_AI_TEMP_FILES) {
            return;
        }

        if (!empty($published['path']) && is_string($published['path']) && file_exists($published['path'])) {
            @unlink($published['path']);
        }
    }

    private function ensureIndexFile($directory)
    {
        $index = trailingslashit($directory) . 'index.html';

        if (!file_exists($index)) {
            @file_put_contents($index, '');
        }
    }

    private function cleanupOldFiles($directory)
    {
        if (defined('SMBB_SIGNCONNECT_KEEP_AI_TEMP_FILES') && SMBB_SIGNCONNECT_KEEP_AI_TEMP_FILES) {
            return;
        }

        $files = glob(trailingslashit($directory) . 'signconnect-*.png');

        if (!is_array($files)) {
            return;
        }

        $limit = time() - self::TTL;

        foreach ($files as $file) {
            if (is_file($file) && filemtime($file) < $limit) {
                @unlink($file);
            }
        }
    }
}
