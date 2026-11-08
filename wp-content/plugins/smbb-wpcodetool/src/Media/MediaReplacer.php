<?php

namespace Smbb\WpCodeTool\Media;

defined('ABSPATH') || exit;

/**
 * Remplace le fichier d'un média en gardant son ID (donc toutes les pages, produits et
 * champs qui l'utilisent).
 *
 * Même format (jpg → jpg) : le nouveau fichier prend exactement la place de l'ancien,
 * même nom, même URL. Les miniatures sont recalculées.
 *
 * Format différent (jpg → png) :
 * - « convert » : l'image envoyée est convertie dans le format d'origine par l'éditeur
 *   d'images de WordPress (GD ou Imagick). L'URL ne change pas. Une image PNG/WebP/GIF
 *   convertie en JPG perd sa transparence.
 * - « rename »  : le fichier garde son format et change d'extension (photo.jpg → photo.png).
 *   L'ID ne bouge pas ; les liens dans les contenus sont mis à jour et les anciennes URL
 *   (fichier et miniatures) sont redirigées en 301 vers les nouvelles.
 *
 * Dans tous les cas : anciennes miniatures et fichiers de sauvegarde supprimés, métadonnées
 * régénérées, caches vidés (WordPress, extensions de cache connues, action pour les autres)
 * et les images affichées par WordPress reçoivent un ?v=… pour que les navigateurs et CDN
 * reprennent la nouvelle version.
 */
final class MediaReplacer
{
    const META_REPLACED = '_smbb_media_replaced';

    /**
     * @param int    $id      ID du média
     * @param array  $upload  entrée de $_FILES
     * @param string $mode    'convert' | 'rename' (ignoré si même format)
     * @param bool   $links   mettre à jour les liens des contenus (mode rename)
     *
     * @return array|\WP_Error infos du remplacement
     */
    public static function replace($id, array $upload, $mode = 'convert', $links = true)
    {
        $id = (int) $id;
        $post = get_post($id);

        if (!$post || $post->post_type !== 'attachment') {
            return new \WP_Error('smbb_media_missing', __('Ce média n\'existe plus.', 'smbb-wpcodetool'));
        }

        if (!current_user_can('upload_files') || !current_user_can('edit_post', $id)) {
            return new \WP_Error('smbb_media_cap', __('Vous n\'avez pas le droit de modifier ce média.', 'smbb-wpcodetool'));
        }

        $check = self::checkUpload($upload);

        if (is_wp_error($check)) {
            return $check;
        }

        $old_file = (string) get_attached_file($id, true);

        if ($old_file === '') {
            return new \WP_Error('smbb_media_nofile', __('Le fichier d\'origine de ce média est introuvable.', 'smbb-wpcodetool'));
        }

        $dir = dirname($old_file);

        if (!wp_is_writable($dir)) {
            return new \WP_Error('smbb_media_dir', sprintf(__('Le dossier %s n\'est pas accessible en écriture.', 'smbb-wpcodetool'), self::relative($dir)));
        }

        require_once ABSPATH . 'wp-admin/includes/image.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';

        $old_ext = strtolower(pathinfo($old_file, PATHINFO_EXTENSION));
        $old_mime = (string) $post->post_mime_type;
        $new_ext = $check['ext'];
        $new_mime = $check['type'];
        $same = self::sameFormat($old_ext, $new_ext);

        if (!$same && $mode === 'convert' && !self::canConvert($new_mime, $old_mime)) {
            $mode = 'rename';
        }

        // 1. Le fichier envoyé est d'abord déposé à côté (nom temporaire) : rien n'est effacé tant
        //    qu'il n'est pas en place.
        $temp = $dir . '/.smbb-replace-' . wp_generate_password(8, false) . '.' . $new_ext;

        if (!@move_uploaded_file($upload['tmp_name'], $temp) && !@rename($upload['tmp_name'], $temp)) { // phpcs:ignore
            return new \WP_Error('smbb_media_move', __('Impossible de déposer le nouveau fichier sur le serveur.', 'smbb-wpcodetool'));
        }

        if ($new_ext === 'svg') {
            $clean = SvgSanitizer::sanitizeFile($temp);

            if (is_wp_error($clean)) {
                @unlink($temp); // phpcs:ignore
                return $clean;
            }
        }

        $converted = false;

        if (!$same && $mode === 'convert') {
            $editor = wp_get_image_editor($temp);

            if (is_wp_error($editor)) {
                @unlink($temp); // phpcs:ignore
                return $editor;
            }

            $converted_path = $dir . '/.smbb-replace-' . wp_generate_password(8, false) . '.' . $old_ext;
            $saved = $editor->save($converted_path, $old_mime);
            @unlink($temp); // phpcs:ignore

            if (is_wp_error($saved)) {
                return $saved;
            }

            $temp = $saved['path'];
            $new_ext = $old_ext;
            $new_mime = $old_mime;
            $same = true;
            $converted = true;
        }

        // 2. Chemin final : le même, ou le même nom avec la nouvelle extension.
        $meta = wp_get_attachment_metadata($id);
        $meta = is_array($meta) ? $meta : array();
        $old_urls = self::fileUrls($id, $old_file, $meta);

        if ($same) {
            $target = $old_file;
        } else {
            $stem = preg_replace('/-(scaled|rotated)$/', '', pathinfo($old_file, PATHINFO_FILENAME));
            $target = $dir . '/' . wp_unique_filename($dir, $stem . '.' . $new_ext);
        }

        // 3. On met l'ancien original de côté (au cas où), puis on efface ses fichiers.
        $backup = $dir . '/.smbb-backup-' . wp_generate_password(8, false);

        if (file_exists($old_file) && !@copy($old_file, $backup)) { // phpcs:ignore
            $backup = '';
        }

        $backup_sizes = get_post_meta($id, '_wp_attachment_backup_sizes', true);
        wp_delete_attachment_files($id, $meta, is_array($backup_sizes) ? $backup_sizes : array(), $old_file);

        if (file_exists($old_file)) {
            @unlink($old_file); // phpcs:ignore
        }

        if (!@rename($temp, $target)) { // phpcs:ignore
            if ($backup !== '') {
                @rename($backup, $old_file); // phpcs:ignore
            }

            @unlink($temp); // phpcs:ignore

            return new \WP_Error('smbb_media_place', __('Impossible de mettre le nouveau fichier en place. L\'ancien a été remis.', 'smbb-wpcodetool'));
        }

        if ($backup !== '') {
            @unlink($backup); // phpcs:ignore
        }

        // Droits de fichier habituels de WordPress.
        $stat = @stat($dir); // phpcs:ignore
        @chmod($target, $stat ? ($stat['mode'] & 0000666) : 0644); // phpcs:ignore

        // 4. Base : fichier, type, date de modification ; métadonnées et miniatures recalculées.
        delete_post_meta($id, '_wp_attachment_backup_sizes');
        update_attached_file($id, $target);

        global $wpdb;
        $now = current_time('mysql');
        $row = array(
            'post_mime_type' => $new_mime,
            'post_modified' => $now,
            'post_modified_gmt' => get_gmt_from_date($now),
        );

        if (!$same) {
            $row['guid'] = self::uploadsUrl($target);
        }

        $wpdb->update($wpdb->posts, $row, array('ID' => $id));
        clean_post_cache($id);

        // Photo prise de travers (orientation EXIF) : on la redresse sur place, sous le même nom.
        // WordPress l'enregistrerait sinon en « -rotated », et l'URL changerait.
        if (strpos($new_mime, 'image/') === 0 && $new_ext !== 'svg') {
            $editor = wp_get_image_editor($target);

            if (!is_wp_error($editor) && method_exists($editor, 'maybe_exif_rotate')) {
                $rotated = $editor->maybe_exif_rotate();

                if ($rotated === true) {
                    $editor->save($target, $new_mime);
                }
            }
        }

        // Pas de « -scaled » ni de « -rotated » : la grande image garde le nom du fichier remplacé.
        add_filter('big_image_size_threshold', '__return_false', 999);
        add_filter('wp_image_maybe_exif_rotate', '__return_false', 999);
        $new_meta = wp_generate_attachment_metadata($id, $target);
        remove_filter('big_image_size_threshold', '__return_false', 999);
        remove_filter('wp_image_maybe_exif_rotate', '__return_false', 999);

        if (is_wp_error($new_meta) || !is_array($new_meta)) {
            $new_meta = array();
        }

        if ($new_ext === 'svg') {
            $new_meta = MediaTools::svgMetadata($new_meta, $id);
        }

        wp_update_attachment_metadata($id, $new_meta);

        $stamp = time();
        update_post_meta($id, self::META_REPLACED, $stamp);

        // 5. URL qui changent : extension (format changé) ou miniatures aux nouvelles proportions
        //    (photo-300x225.jpg devient photo-300x200.jpg). Liens des contenus mis à jour et
        //    anciennes URL redirigées. L'URL du fichier lui-même ne change qu'en mode « rename ».
        $updated = 0;
        $new_urls = self::fileUrls($id, $target, $new_meta);
        $map = self::urlMap($old_urls, $new_urls);

        if ($map) {
            MediaTools::addRedirects($id, $map);

            if ($links) {
                $updated = self::updateLinks($map);
            }
        }

        self::flushCaches($id, array_values(array_unique(array_merge(array_values($old_urls), array_values($new_urls)))));

        $info = array(
            'id' => $id,
            'old_file' => wp_basename($old_file),
            'new_file' => wp_basename($target),
            'old_mime' => $old_mime,
            'new_mime' => $new_mime,
            'converted' => $converted,
            'renamed' => !$same,
            'redirects' => count($map),
            'links_updated' => $updated,
            'sizes' => isset($new_meta['sizes']) ? count($new_meta['sizes']) : 0,
            'original_name' => sanitize_file_name((string) $upload['name']),
        );

        do_action('smbb_wpcodetool_media_replaced', $id, $info);

        do_action('smbb_wpcodetool_activity', 'content.media_replaced', array(
            'category' => 'content',
            'level' => 'notice',
            'object_type' => 'post',
            'object_id' => $id,
            'object_label' => get_the_title($id),
            'message' => $same
                ? sprintf(__('Fichier du média remplacé : %1$s (envoyé : %2$s)', 'smbb-wpcodetool'), $info['new_file'], $info['original_name'])
                : sprintf(__('Fichier du média remplacé : %1$s → %2$s', 'smbb-wpcodetool'), $info['old_file'], $info['new_file']),
            'context' => $info,
            'source' => 'smbb-wpcodetool',
        ));

        return $info;
    }

    /**
     * Vérifie l'envoi : erreur PHP, taille, type autorisé pour ce compte, vrai contenu du fichier.
     *
     * @return array{ext:string,type:string}|\WP_Error
     */
    public static function checkUpload(array $upload)
    {
        $error = isset($upload['error']) ? (int) $upload['error'] : UPLOAD_ERR_NO_FILE;

        if ($error === UPLOAD_ERR_NO_FILE || empty($upload['tmp_name'])) {
            return new \WP_Error('smbb_media_none', __('Choisissez le nouveau fichier.', 'smbb-wpcodetool'));
        }

        if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
            return new \WP_Error('smbb_media_size', sprintf(__('Le fichier dépasse la taille maximale autorisée (%s).', 'smbb-wpcodetool'), size_format(wp_max_upload_size())));
        }

        if ($error !== UPLOAD_ERR_OK || !is_uploaded_file($upload['tmp_name'])) {
            return new \WP_Error('smbb_media_upload', __('L\'envoi du fichier a échoué. Réessayez.', 'smbb-wpcodetool'));
        }

        if ((int) $upload['size'] > wp_max_upload_size()) {
            return new \WP_Error('smbb_media_size', sprintf(__('Le fichier dépasse la taille maximale autorisée (%s).', 'smbb-wpcodetool'), size_format(wp_max_upload_size())));
        }

        $name = sanitize_file_name((string) $upload['name']);
        $type = wp_check_filetype_and_ext($upload['tmp_name'], $name, get_allowed_mime_types());

        if (empty($type['ext']) || empty($type['type'])) {
            return new \WP_Error('smbb_media_type', __('Ce type de fichier n\'est pas autorisé dans la médiathèque (ou son contenu ne correspond pas à son extension).', 'smbb-wpcodetool'));
        }

        return array('ext' => strtolower($type['ext']), 'type' => $type['type']);
    }

    /** jpg et jpeg sont le même format. */
    public static function sameFormat($a, $b)
    {
        $norm = function ($ext) {
            $ext = strtolower((string) $ext);

            return $ext === 'jpeg' || $ext === 'jpe' ? 'jpg' : $ext;
        };

        return $norm($a) === $norm($b);
    }

    /** Conversion possible : deux images matricielles que l'éditeur sait lire et écrire. */
    public static function canConvert($from_mime, $to_mime)
    {
        $raster = array('image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif');

        if (!in_array($from_mime, $raster, true) || !in_array($to_mime, $raster, true)) {
            return false;
        }

        return wp_image_editor_supports(array('mime_type' => $from_mime))
            && wp_image_editor_supports(array('mime_type' => $to_mime, 'methods' => array('save')));
    }

    /**
     * URL du fichier et de chaque taille, par nom de taille ('full', 'original', 'thumbnail'…).
     *
     * @return array<string,string>
     */
    private static function fileUrls($id, $file, array $meta)
    {
        $base = trailingslashit(dirname(self::uploadsUrl($file)));
        $urls = array('full' => self::uploadsUrl($file));

        if (!empty($meta['original_image'])) {
            $urls['original'] = $base . $meta['original_image'];
        }

        if (!empty($meta['sizes']) && is_array($meta['sizes'])) {
            foreach ($meta['sizes'] as $size => $data) {
                if (!empty($data['file'])) {
                    $urls[$size] = $base . $data['file'];
                }
            }
        }

        return $urls;
    }

    /**
     * Ancienne URL => nouvelle : par nom de taille, sinon le fichier complet.
     *
     * @return array<string,string>
     */
    private static function urlMap(array $old, array $new)
    {
        $map = array();

        $still = array_flip(array_values($new));

        foreach ($old as $size => $url) {
            if (isset($still[$url])) {
                continue; // même URL, toujours servie
            }

            $map[$url] = isset($new[$size]) ? $new[$size] : $new['full'];
        }

        return $map;
    }

    /**
     * Remplace les anciennes URL dans le texte des contenus (articles, pages, produits…).
     * On cherche le chemin depuis la racine du site (« wp-content/uploads/2026/11/photo.jpg ») :
     * les liens absolus, relatifs, en http et en https sont couverts. Les champs des
     * constructeurs de pages (méta sérialisées) ne sont pas touchés : les redirections 301 s'en chargent.
     *
     * @return int contenus modifiés
     */
    private static function updateLinks(array $map)
    {
        global $wpdb;

        $ids = array();

        foreach ($map as $old => $new) {
            $from = self::sitePath($old);
            $to = self::sitePath($new);

            if ($from === '' || $from === $to) {
                continue;
            }

            $like = '%' . $wpdb->esc_like($from) . '%';
            $found = $wpdb->get_col($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE post_type NOT IN ('revision','attachment') AND (post_content LIKE %s OR post_excerpt LIKE %s)", $like, $like));

            if (!$found) {
                continue;
            }

            $wpdb->query($wpdb->prepare(
                "UPDATE {$wpdb->posts} SET post_content = REPLACE(post_content, %s, %s), post_excerpt = REPLACE(post_excerpt, %s, %s) WHERE post_type NOT IN ('revision','attachment') AND (post_content LIKE %s OR post_excerpt LIKE %s)",
                $from, $to, $from, $to, $like, $like
            ));

            foreach ($found as $post_id) {
                $ids[(int) $post_id] = true;
            }
        }

        foreach (array_keys($ids) as $post_id) {
            clean_post_cache($post_id);
        }

        return count($ids);
    }

    /**
     * Caches : objet WordPress, extensions de cache connues, puis action pour les autres.
     */
    private static function flushCaches($id, array $urls)
    {
        clean_attachment_cache($id);
        clean_post_cache($id);
        wp_cache_delete($id, 'post_meta');

        // Pages qui utilisent le média comme image mise en avant.
        $parents = get_posts(array(
            'post_type' => 'any',
            'post_status' => 'any',
            'fields' => 'ids',
            'posts_per_page' => 200,
            'no_found_rows' => true,
            'meta_key' => '_thumbnail_id', // phpcs:ignore
            'meta_value' => $id, // phpcs:ignore
        ));
        $parent = (int) wp_get_post_parent_id($id);

        if ($parent) {
            $parents[] = $parent;
        }

        $pages = array();

        foreach (array_unique(array_map('intval', $parents)) as $post_id) {
            clean_post_cache($post_id);
            $link = get_permalink($post_id);

            if ($link) {
                $pages[] = $link;
            }
        }

        $all = array_merge($urls, $pages);

        // WP Rocket.
        if (function_exists('rocket_clean_files')) {
            rocket_clean_files($all);
        }

        // LiteSpeed Cache.
        if (has_action('litespeed_purge_url')) {
            foreach ($all as $url) {
                do_action('litespeed_purge_url', $url);
            }
        }

        // W3 Total Cache.
        if (function_exists('w3tc_flush_url')) {
            foreach ($all as $url) {
                w3tc_flush_url($url);
            }
        }

        // WP Super Cache.
        if (function_exists('wpsc_delete_url_cache')) {
            foreach ($pages as $url) {
                wpsc_delete_url_cache($url);
            }
        }

        // WP Fastest Cache.
        if (function_exists('wpfc_clear_post_cache_by_id')) {
            foreach (array_unique(array_map('intval', $parents)) as $post_id) {
                wpfc_clear_post_cache_by_id($post_id);
            }
        }

        // SiteGround Optimizer, Cloudflare (extension officielle), Breeze, Nginx Helper, Kinsta…
        if (function_exists('sg_cachepress_purge_cache')) {
            sg_cachepress_purge_cache();
        }

        do_action('cloudflare_purge_by_url', $all);
        do_action('breeze_clear_all_cache');
        do_action('rt_nginx_helper_purge_all');

        /*
         * Pour les autres caches (CDN, Varnish…) : la liste des URL concernées.
         *
         *     add_action('smbb_wpcodetool_media_purge', function ($urls, $attachment_id) { … }, 10, 2);
         */
        do_action('smbb_wpcodetool_media_purge', $all, $id);
    }

    private static function uploadsUrl($file)
    {
        $uploads = wp_get_upload_dir();
        $rel = _wp_relative_upload_path($file);

        return trailingslashit($uploads['baseurl']) . ltrim($rel, '/');
    }

    /** « wp-content/uploads/2026/11/photo.jpg » depuis une URL du site. */
    public static function sitePath($url)
    {
        $path = (string) wp_parse_url($url, PHP_URL_PATH);

        return ltrim($path, '/');
    }

    private static function relative($dir)
    {
        return str_replace(wp_normalize_path(ABSPATH), '', wp_normalize_path($dir));
    }
}
