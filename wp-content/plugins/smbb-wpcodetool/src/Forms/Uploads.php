<?php

namespace Smbb\WpCodeTool\Forms;

use Smbb\WpCodeTool\Storage\Storage;

defined('ABSPATH') || exit;

/**
 * Fichiers envoyés par les formulaires.
 *
 * Rangés dans uploads/smbb-forms-<clé imprévisible>/<id de l'entrée>/, dossier fermé au web
 * (.htaccess « deny » + nom imprévisible pour Nginx). Téléchargement par l'admin seulement,
 * via admin-post.php?action=smbb_form_file (droit Forms::capability() + jeton).
 *
 * Valeur gardée dans l'entrée pour un champ fichier : liste de
 * { name: nom d'origine, file: nom sur le disque, size: octets } ou { name, url } pour une reprise WPForms.
 * Formulaire rangé sur une autre unité de stockage (réglage « Stockage des pièces jointes », CodeTool › Stockage) :
 * + { unit: id de l'unité, path: clé forms/<formulaire>/<entrée>/<fichier> } et le fichier local est retiré.
 */
final class Uploads
{
    public static function dir($create = false)
    {
        $key = get_option(Forms::DIR_OPTION);

        if (!is_string($key) || $key === '') {
            if (!$create) {
                return '';
            }

            $key = strtolower(wp_generate_password(16, false, false));
            update_option(Forms::DIR_OPTION, $key, false);
        }

        $uploads = wp_upload_dir(null, false);

        if (!empty($uploads['error'])) {
            return '';
        }

        $dir = trailingslashit($uploads['basedir']) . 'smbb-forms-' . sanitize_key($key);

        if ($create && !is_dir($dir)) {
            if (!wp_mkdir_p($dir)) {
                return '';
            }

            @file_put_contents($dir . '/.htaccess', "<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n  Deny from all\n</IfModule>\n"); // phpcs:ignore
            @file_put_contents($dir . '/index.php', "<?php // Silence.\n"); // phpcs:ignore
        }

        return is_dir($dir) ? $dir : '';
    }

    /**
     * Contrôle les fichiers envoyés pour un champ (sans encore les ranger).
     *
     * @return array{0:array[],1:string} [fichiers acceptés (tmp_name, name, size), erreur ou '']
     */
    public static function check(array $field, $input_name)
    {
        // phpcs:ignore WordPress.Security.NonceVerification
        $raw = isset($_FILES[$input_name]) && is_array($_FILES[$input_name]) ? $_FILES[$input_name] : null;
        $files = array();

        if ($raw && isset($raw['name'])) {
            $names = (array) $raw['name'];
            $tmp = (array) $raw['tmp_name'];
            $errors = (array) $raw['error'];
            $sizes = (array) $raw['size'];

            foreach ($names as $i => $name) {
                if ((int) ($errors[$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE || (string) $name === '') {
                    continue;
                }

                if ((int) $errors[$i] !== UPLOAD_ERR_OK || !is_uploaded_file((string) $tmp[$i])) {
                    return array(array(), __('Le fichier n\'a pas pu être reçu. Réessayez avec un fichier plus léger.', 'smbb-wpcodetool'));
                }

                $files[] = array('tmp_name' => (string) $tmp[$i], 'name' => sanitize_file_name(wp_basename((string) $name)), 'size' => (int) $sizes[$i]);
            }
        }

        if (!$files) {
            return array(array(), $field['required'] === '1' ? __('Ajoutez un fichier.', 'smbb-wpcodetool') : '');
        }

        if (count($files) > 5) {
            return array(array(), __('5 fichiers au plus.', 'smbb-wpcodetool'));
        }

        $max = max(1, (int) $field['file_max']) * MB_IN_BYTES;
        $allowed = FieldTypes::fileTypes($field);

        foreach ($files as $file) {
            if ($file['size'] > $max) {
                return array(array(), sprintf(__('« %1$s » dépasse %2$d Mo.', 'smbb-wpcodetool'), $file['name'], (int) $field['file_max']));
            }

            $check = wp_check_filetype_and_ext($file['tmp_name'], $file['name']);
            $ext = strtolower((string) ($check['ext'] ?: pathinfo($file['name'], PATHINFO_EXTENSION)));

            if (!$check['ext'] || !in_array($ext, $allowed, true)) {
                return array(array(), sprintf(__('Format refusé pour « %1$s ». Formats acceptés : %2$s.', 'smbb-wpcodetool'), $file['name'], implode(', ', $allowed)));
            }
        }

        return array($files, '');
    }

    /**
     * Range les fichiers contrôlés d'une entrée. $folder : id de l'entrée, ou un dossier temporaire.
     *
     * @return array<int,array{name:string,file:string,size:int}>
     */
    public static function store(array $files, $folder)
    {
        $base = self::dir(true);

        if ($base === '' || !$files) {
            return array();
        }

        $dir = $base . '/' . sanitize_file_name((string) $folder);

        if (!is_dir($dir) && !wp_mkdir_p($dir)) {
            return array();
        }

        $out = array();

        foreach ($files as $file) {
            $name = wp_unique_filename($dir, $file['name']);

            if (@move_uploaded_file($file['tmp_name'], $dir . '/' . $name)) { // phpcs:ignore
                @chmod($dir . '/' . $name, 0640); // phpcs:ignore
                $out[] = array('name' => $file['name'], 'file' => $name, 'size' => (int) $file['size']);
            }
        }

        return $out;
    }

    /**
     * Déplace les fichiers d'un dossier temporaire vers celui de l'entrée.
     */
    public static function moveFolder($from, $to)
    {
        $base = self::dir();

        if ($base === '') {
            return false;
        }

        $source = $base . '/' . sanitize_file_name((string) $from);
        $target = $base . '/' . sanitize_file_name((string) $to);

        return is_dir($source) && !file_exists($target) ? @rename($source, $target) : false; // phpcs:ignore
    }

    /**
     * Chemins disque des fichiers d'une valeur de champ (pour les pièces jointes).
     *
     * @return string[]
     */
    public static function paths($folder, $value)
    {
        $base = self::dir();
        $out = array();

        foreach (is_array($value) ? $value : array() as $file) {
            if (is_array($file) && !empty($file['file'])) {
                $path = $base . '/' . sanitize_file_name((string) $folder) . '/' . sanitize_file_name($file['file']);

                if ($base !== '' && is_readable($path)) {
                    $out[] = $path;
                }
            }
        }

        return $out;
    }

    /**
     * Après les e-mails : fichiers de l'entrée envoyés sur l'unité de stockage du formulaire (si ce n'est pas
     * le dossier du site), copie locale retirée. Un envoi raté laisse le fichier en local (rien ne se perd).
     *
     * @return array snapshot mis à jour
     */
    public static function pushToUnit(array $form, $entry_id, array $snapshot)
    {
        $unit = (string) ($form['settings']['storage'] ?? 'wp');
        $base = self::dir();

        if ($unit === '' || $unit === 'wp' || !$entry_id || $base === '' || !Storage::exists($unit)) {
            return $snapshot;
        }

        try {
            $disk = Storage::disk($unit);
        } catch (\Throwable $e) {
            Forms::log('storage_failed', sprintf(__('Pièces jointes gardées sur le site : unité « %1$s » indisponible (%2$s).', 'smbb-wpcodetool'), Storage::label($unit), $e->getMessage()), $form, array(), 'warning');

            return $snapshot;
        }

        foreach ($snapshot as $i => $field) {
            if (($field['type'] ?? '') !== 'file' || !is_array($field['value'] ?? null)) {
                continue;
            }

            foreach ($field['value'] as $j => $file) {
                if (!is_array($file) || empty($file['file']) || !empty($file['unit'])) {
                    continue;
                }

                $local = $base . '/' . (int) $entry_id . '/' . sanitize_file_name($file['file']);
                $key = 'forms/' . (int) $form['id'] . '/' . (int) $entry_id . '/' . sanitize_file_name($file['file']);

                if (!is_readable($local)) {
                    continue;
                }

                try {
                    $type = wp_check_filetype($file['file']);
                    $disk->putFile($key, $local, $type['type'] ? $type['type'] : 'application/octet-stream');
                    $snapshot[$i]['value'][$j]['unit'] = $unit;
                    $snapshot[$i]['value'][$j]['path'] = $key;
                    @unlink($local); // phpcs:ignore
                } catch (\Throwable $e) {
                    Forms::log('storage_failed', sprintf(__('« %1$s » gardé sur le site : envoi vers « %2$s » impossible (%3$s).', 'smbb-wpcodetool'), $file['name'] ?? $file['file'], Storage::label($unit), $e->getMessage()), $form, array(), 'warning');
                }
            }
        }

        @rmdir($base . '/' . (int) $entry_id); // phpcs:ignore -- seulement s'il est vide.

        return $snapshot;
    }

    /**
     * Fichiers d'une entrée : ceux rangés sur une unité de stockage, puis le dossier local.
     *
     * @param array|null $fields champs de l'entrée (pour retrouver les fichiers sur une unité)
     */
    public static function deleteEntryFiles($entry_id, $fields = null)
    {
        foreach (is_array($fields) ? $fields : array() as $field) {
            if (($field['type'] ?? '') !== 'file' || !is_array($field['value'] ?? null)) {
                continue;
            }

            foreach ($field['value'] as $file) {
                if (is_array($file) && !empty($file['unit']) && !empty($file['path'])) {
                    try {
                        Storage::disk((string) $file['unit'])->delete((string) $file['path']);
                    } catch (\Throwable $e) {
                        // Unité supprimée ou injoignable : on n'empêche pas la suppression de l'entrée.
                    }
                }
            }
        }

        self::deleteFolder((string) (int) $entry_id);
    }

    public static function deleteFolder($folder)
    {
        $base = self::dir();
        $folder = sanitize_file_name((string) $folder);

        if ($base === '' || $folder === '' || !is_dir($base . '/' . $folder)) {
            return;
        }

        foreach ((array) glob($base . '/' . $folder . '/*') as $file) {
            if (is_file($file)) {
                @unlink($file); // phpcs:ignore
            }
        }

        @rmdir($base . '/' . $folder); // phpcs:ignore
    }

    /**
     * Lien de téléchargement d'un fichier d'une entrée. Sans jeton : il sert aussi dans les e-mails
     * de l'équipe (un jeton WordPress expire et dépend du compte). La porte, c'est la connexion
     * avec le droit de lire les entrées.
     */
    public static function url($entry_id, $key, $index)
    {
        return admin_url('admin-post.php?action=smbb_form_file&entry=' . (int) $entry_id . '&key=' . rawurlencode((string) $key) . '&i=' . (int) $index);
    }

    /**
     * Lien ouvert sans être connecté (depuis un e-mail) : page de connexion, puis retour au fichier.
     */
    public static function downloadLoggedOut()
    {
        $back = add_query_arg(array(
            'action' => 'smbb_form_file',
            'entry' => isset($_GET['entry']) ? absint($_GET['entry']) : 0, // phpcs:ignore WordPress.Security.NonceVerification
            'key' => isset($_GET['key']) ? sanitize_key(wp_unslash($_GET['key'])) : '', // phpcs:ignore WordPress.Security.NonceVerification
            'i' => isset($_GET['i']) ? absint($_GET['i']) : 0, // phpcs:ignore WordPress.Security.NonceVerification
        ), admin_url('admin-post.php'));

        wp_safe_redirect(wp_login_url($back));
        exit;
    }

    /**
     * admin-post.php?action=smbb_form_file
     */
    public static function download()
    {
        // phpcs:disable WordPress.Security.NonceVerification -- lecture seule, réservée au droit ci-dessous.
        $entry = EntryRepository::find(isset($_GET['entry']) ? absint($_GET['entry']) : 0);
        $key = isset($_GET['key']) ? sanitize_key(wp_unslash($_GET['key'])) : '';
        $index = isset($_GET['i']) ? absint($_GET['i']) : 0;
        // phpcs:enable

        // Droit de lire les entrées, ou accord du plugin d'origine (ex. Passflow : l'intervenant de la journée).
        $allowed = current_user_can(Forms::capability())
            || ($entry && $entry['source'] !== '' && (bool) apply_filters('smbb_wpcodetool_form_file_allowed', false, $entry, $key));

        if (!$allowed) {
            wp_die(esc_html__('Vous n\'avez pas le droit de lire les entrées des formulaires.', 'smbb-wpcodetool'), '', array('response' => 403));
        }
        $value = $entry ? EntryRepository::value($entry, $key) : array();
        $file = is_array($value) && isset($value[$index]) && is_array($value[$index]) ? $value[$index] : null;
        $name = $file ? str_replace(array('"', "\r", "\n"), '', (string) ($file['name'] ?? 'fichier')) : '';
        $temp = '';

        if ($file && !empty($file['unit']) && !empty($file['path'])) {
            try {
                $disk = Storage::disk((string) $file['unit']);
                $signed = $disk->temporaryUrl((string) $file['path'], 300);

                if ($signed) {
                    wp_redirect($signed); // phpcs:ignore WordPress.Security.SafeRedirect -- adresse signée de l'unité (S3…).
                    exit;
                }

                $temp = $disk->download((string) $file['path']);
                $path = $temp;
            } catch (\Throwable $e) {
                wp_die(esc_html(sprintf(__('Fichier inaccessible sur l\'unité « %1$s » : %2$s', 'smbb-wpcodetool'), Storage::label((string) $file['unit']), $e->getMessage())));
            }
        } else {
            $base = self::dir();
            $path = $file && !empty($file['file']) && $base !== '' ? $base . '/' . (int) $entry['id'] . '/' . sanitize_file_name($file['file']) : '';
        }

        if ($path === '' || !is_readable($path)) {
            wp_die(esc_html__('Fichier introuvable (entrée supprimée ou purgée ?).', 'smbb-wpcodetool'));
        }

        $type = wp_check_filetype((string) ($file['file'] ?? wp_basename($path)));
        nocache_headers();
        header('Content-Type: ' . ($type['type'] ? $type['type'] : 'application/octet-stream'));
        header('Content-Disposition: attachment; filename="' . $name . '"');
        header('Content-Length: ' . (int) filesize($path));
        header('X-Content-Type-Options: nosniff');
        readfile($path); // phpcs:ignore

        if ($temp !== '') {
            @unlink($temp); // phpcs:ignore
        }

        exit;
    }
}
