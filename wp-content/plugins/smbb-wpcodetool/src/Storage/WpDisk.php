<?php

namespace Smbb\WpCodeTool\Storage;

defined('ABSPATH') || exit;

if (!function_exists('wp_tempnam')) {
    require_once ABSPATH . 'wp-admin/includes/file.php';
}

/**
 * Unité « WordPress » : un dossier privé dans wp-content/uploads.
 *
 * uploads/smbb-storage-<clé imprévisible>/… fermé au web (.htaccess « deny » pour Apache,
 * nom imprévisible pour Nginx). Les fichiers ne sont pas dans la médiathèque et se servent par PHP.
 */
final class WpDisk implements DiskInterface
{
    const DIR_OPTION = 'smbb_codetool_storage_dir';

    /** @var string */
    private $root;

    public function __construct($root = '')
    {
        $this->root = $root !== '' ? untrailingslashit($root) : self::defaultRoot();
    }

    public static function defaultRoot()
    {
        $key = get_option(self::DIR_OPTION);

        if (!is_string($key) || $key === '') {
            $key = strtolower(wp_generate_password(16, false, false));
            update_option(self::DIR_OPTION, $key, false);
        }

        $uploads = wp_upload_dir(null, false);

        return trailingslashit($uploads['basedir']) . 'smbb-storage-' . sanitize_key($key);
    }

    /**
     * Ferme un dossier au web (Apache) et évite la liste des fichiers.
     */
    public static function protect($dir)
    {
        if (!is_dir($dir)) {
            wp_mkdir_p($dir);
        }

        if (!file_exists($dir . '/.htaccess')) {
            @file_put_contents($dir . '/.htaccess', "<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n  Deny from all\n</IfModule>\n"); // phpcs:ignore
        }

        if (!file_exists($dir . '/index.php')) {
            @file_put_contents($dir . '/index.php', "<?php // Silence.\n"); // phpcs:ignore
        }
    }

    public function root()
    {
        return $this->root;
    }

    /**
     * Chemin local d'une clé (jamais hors de la racine).
     */
    public function path($key)
    {
        $parts = array();

        foreach (explode('/', str_replace('\\', '/', (string) $key)) as $part) {
            $part = sanitize_file_name($part);

            if ($part !== '' && $part !== '.' && $part !== '..') {
                $parts[] = $part;
            }
        }

        if (!$parts) {
            throw new \RuntimeException(__('Nom de fichier vide.', 'smbb-wpcodetool'));
        }

        return $this->root . '/' . implode('/', $parts);
    }

    private function prepare($key)
    {
        self::protect($this->root);
        $path = $this->path($key);

        if (!is_dir(dirname($path)) && !wp_mkdir_p(dirname($path))) {
            throw new \RuntimeException(__('Le dossier de stockage n\'a pas pu être créé (droits d\'écriture ?).', 'smbb-wpcodetool'));
        }

        return $path;
    }

    public function putFile($key, $path, $content_type = 'application/octet-stream')
    {
        $target = $this->prepare($key);

        if (!@copy($path, $target)) { // phpcs:ignore
            throw new \RuntimeException(__('Le fichier n\'a pas pu être écrit dans le stockage WordPress.', 'smbb-wpcodetool'));
        }

        @chmod($target, 0640); // phpcs:ignore
    }

    public function putString($key, $content, $content_type = 'text/plain')
    {
        if (@file_put_contents($this->prepare($key), (string) $content) === false) { // phpcs:ignore
            throw new \RuntimeException(__('Le fichier n\'a pas pu être écrit dans le stockage WordPress.', 'smbb-wpcodetool'));
        }
    }

    public function download($key)
    {
        $source = $this->path($key);
        $tmp = wp_tempnam('smbb-storage');

        if (!is_readable($source) || !$tmp || !@copy($source, $tmp)) { // phpcs:ignore
            throw new \RuntimeException(__('Le fichier stocké est introuvable.', 'smbb-wpcodetool'));
        }

        return $tmp;
    }

    public function getString($key)
    {
        $path = $this->path($key);

        if (!is_readable($path)) {
            throw new \RuntimeException(__('Le fichier stocké est introuvable.', 'smbb-wpcodetool'));
        }

        return (string) file_get_contents($path); // phpcs:ignore
    }

    public function delete($key)
    {
        if ((string) $key === '') {
            return;
        }

        $path = $this->path($key);

        if (is_file($path)) {
            @unlink($path); // phpcs:ignore
        }

        // Dossiers vidés : retirés jusqu'à la racine.
        $dir = dirname($path);

        while (strlen($dir) > strlen($this->root) && is_dir($dir) && count((array) scandir($dir)) <= 2) {
            @rmdir($dir); // phpcs:ignore
            $dir = dirname($dir);
        }
    }

    public function exists($key)
    {
        return is_file($this->path($key));
    }

    public function temporaryUrl($key, $seconds = 600)
    {
        return null;
    }

    public function test()
    {
        $key = 'codetool-test/' . gmdate('Ymd-His') . '-' . wp_generate_password(8, false, false) . '.txt';
        $content = 'CodeTool ' . gmdate('c');
        $this->putString($key, $content);
        $ok = $this->getString($key) === $content;
        $this->delete($key);

        if (!$ok) {
            throw new \RuntimeException(__('Le fichier de test a été écrit mais sa relecture ne correspond pas.', 'smbb-wpcodetool'));
        }

        return __('Stockage WordPress OK : écriture, lecture et suppression réussies.', 'smbb-wpcodetool');
    }
}
