<?php

namespace Smbb\WpCodeTool\Media;

use Smbb\WpCodeTool\Admin\Pages\MediaReplacePage;

defined('ABSPATH') || exit;

/**
 * Outils de la médiathèque (réglages dans CodeTool › Contenu WP › Médiathèque).
 *
 * - replace : action « Remplacer le fichier » (liste, fenêtre des médias, écran d'édition)
 *             qui garde l'ID, l'URL et le nom du fichier. Voir MediaReplacer.
 * - svg     : envoi de SVG autorisé pour les rôles choisis, chaque fichier nettoyé (SvgSanitizer).
 *
 * Toujours actifs, même si l'action est coupée ensuite : redirection 301 des anciennes URL
 * d'un média remplacé, et ?v=… sur ses images pour casser le cache des navigateurs.
 */
final class MediaTools
{
    const OPTION = 'smbb_codetool_media';
    const REDIRECTS = 'smbb_codetool_media_redirects';
    const MAX_REDIRECTS = 3000;
    const ACTION = 'smbb_codetool_media_replace';

    /** @var array|null */
    private static $settings = null;

    public static function defaults()
    {
        return array(
            'replace' => 1,
            'svg' => 0,
            'svg_roles' => array('administrator'),
        );
    }

    public static function settings()
    {
        if (self::$settings !== null) {
            return self::$settings;
        }

        $saved = get_option(self::OPTION, array());
        self::$settings = self::clean(is_array($saved) ? $saved : array());

        return self::$settings;
    }

    public static function save(array $values)
    {
        self::$settings = null;
        update_option(self::OPTION, self::clean($values), true);
    }

    public static function on($key)
    {
        return !empty(self::settings()[$key]);
    }

    private static function clean(array $values)
    {
        $d = self::defaults();
        $roles = isset($values['svg_roles']) && is_array($values['svg_roles']) ? $values['svg_roles'] : $d['svg_roles'];

        return array(
            'replace' => isset($values['replace']) ? (int) (bool) $values['replace'] : $d['replace'],
            'svg' => isset($values['svg']) ? (int) (bool) $values['svg'] : $d['svg'],
            'svg_roles' => array_values(array_unique(array_filter(array_map('sanitize_key', $roles)))),
        );
    }

    /** Rôles qui peuvent envoyer des fichiers (ceux qu'on propose pour les SVG). */
    public static function uploadRoles()
    {
        $out = array();

        foreach (wp_roles()->roles as $key => $role) {
            if (!empty($role['capabilities']['upload_files'])) {
                $out[$key] = translate_user_role($role['name']);
            }
        }

        return $out;
    }

    /* ---------------------------------------------------------------- Branchement */

    /** Sur init 0 (depuis smbb-wpcodetool.php). */
    public static function hooks()
    {
        // Toujours : anciennes URL redirigées et images remplacées « rafraîchies ».
        add_action('template_redirect', array(__CLASS__, 'redirectOldUrl'), 0);
        add_filter('wp_get_attachment_image_src', array(__CLASS__, 'bustImageSrc'), 99, 2);
        add_filter('wp_calculate_image_srcset', array(__CLASS__, 'bustSrcset'), 99, 5);
        add_filter('wp_content_img_tag', array(__CLASS__, 'bustContentImg'), 99, 3);
        add_filter('wp_prepare_attachment_for_js', array(__CLASS__, 'prepareForJs'), 99, 2);

        if (self::on('replace')) {
            add_action('admin_post_' . self::ACTION, array(__CLASS__, 'handlePost'));
            add_filter('media_row_actions', array(__CLASS__, 'rowAction'), 10, 2);
            add_action('attachment_submitbox_misc_actions', array(__CLASS__, 'submitboxButton'), 20);
            add_action('wp_enqueue_media', array(__CLASS__, 'enqueue'));
            add_action('admin_head', array(__CLASS__, 'hideMenuEntry'));
            add_filter('parent_file', array(__CLASS__, 'parentFile'), 99);
            add_filter('submenu_file', array(__CLASS__, 'submenuFile'), 99);
            add_filter('admin_title', array(__CLASS__, 'adminTitle'), 10, 2);
        }

        if (self::on('svg')) {
            add_filter('upload_mimes', array(__CLASS__, 'svgMimes'), 99);
            add_filter('wp_check_filetype_and_ext', array(__CLASS__, 'svgFiletype'), 99, 5);
            add_filter('wp_handle_upload_prefilter', array(__CLASS__, 'svgPrefilter'));
            add_filter('wp_handle_sideload_prefilter', array(__CLASS__, 'svgPrefilter'));
            add_action('admin_head', array(__CLASS__, 'svgAdminCss'));
        }

        // Largeur et hauteur des SVG (affichage, attributs width/height). Inoffensif sans SVG.
        add_filter('wp_generate_attachment_metadata', array(__CLASS__, 'svgMetadata'), 10, 2);
    }

    /* ---------------------------------------------------------------- Points d'entrée « Remplacer » */

    public static function canReplace($id)
    {
        return current_user_can('upload_files') && current_user_can('edit_post', (int) $id);
    }

    public static function pageUrl($id)
    {
        return admin_url('upload.php?page=' . MediaReplacePage::SLUG . '&attachment_id=' . (int) $id);
    }

    /** Liste des médias (mode liste). */
    public static function rowAction($actions, $post)
    {
        if ($post instanceof \WP_Post && self::canReplace($post->ID)) {
            $link = '<a href="' . esc_url(self::pageUrl($post->ID)) . '">' . esc_html__('Remplacer le fichier', 'smbb-wpcodetool') . '</a>';
            $out = array();

            // Juste après « Modifier ».
            foreach ($actions as $key => $html) {
                $out[$key] = $html;

                if ($key === 'edit') {
                    $out['smbb_replace'] = $link;
                }
            }

            if (!isset($out['smbb_replace'])) {
                $out['smbb_replace'] = $link;
            }

            return $out;
        }

        return $actions;
    }

    /** Écran « Modifier le média » : bloc Enregistrer. */
    public static function submitboxButton($post)
    {
        if (!$post instanceof \WP_Post || !self::canReplace($post->ID)) {
            return;
        }

        $replaced = (int) get_post_meta($post->ID, MediaReplacer::META_REPLACED, true);
        ?>
        <style>
            .smbb-media-replace-misc .smbb-media-replace-button{display:inline-flex;align-items:center;gap:6px}
            .smbb-media-replace-misc .smbb-media-replace-button .dashicons{display:inline-flex;align-items:center;justify-content:center;flex:none;width:18px;height:18px;margin:0;padding:0;font-size:18px;line-height:1;vertical-align:0}
            .smbb-media-replace-misc .smbb-media-replace-button .dashicons::before{line-height:1}
        </style>
        <div class="misc-pub-section smbb-media-replace-misc">
            <a class="button smbb-media-replace-button" href="<?php echo esc_url(self::pageUrl($post->ID)); ?>">
                <span class="dashicons dashicons-update" aria-hidden="true"></span>
                <?php esc_html_e('Remplacer le fichier', 'smbb-wpcodetool'); ?>
            </a>
            <?php if ($replaced) : ?>
                <p class="description"><?php echo esc_html(sprintf(__('Remplacé le %s.', 'smbb-wpcodetool'), wp_date(get_option('date_format') . ' ' . get_option('time_format'), $replaced))); ?></p>
            <?php endif; ?>
        </div>
        <?php
    }

    /** Fenêtre des médias (médiathèque en grille et fenêtre ouverte depuis l'éditeur). */
    public static function enqueue()
    {
        if (!is_admin() || !current_user_can('upload_files')) {
            return;
        }

        $file = 'media-replace.js';
        $path = SMBB_WPCODETOOL_PATH . 'assets/' . $file;
        $ver = SMBB_WPCODETOOL_VERSION . (file_exists($path) ? '.' . filemtime($path) : '');

        wp_enqueue_script('smbb-wpcodetool-media-replace', SMBB_WPCODETOOL_URL . 'assets/' . $file, array(), $ver, true);
        wp_localize_script('smbb-wpcodetool-media-replace', 'smbbMediaReplace', array(
            'url' => admin_url('upload.php?page=' . MediaReplacePage::SLUG . '&attachment_id='),
            'label' => __('Remplacer le fichier', 'smbb-wpcodetool'),
            'short' => __('Remplacer', 'smbb-wpcodetool'),
        ));
    }

    /**
     * La page existe (enregistrée par AdminManager sous Médias) mais n'apparaît pas dans le menu.
     * Retirée sur admin_head, juste avant le dessin du menu : les contrôles d'accès de WordPress
     * l'ont déjà trouvée.
     */
    public static function hideMenuEntry()
    {
        remove_submenu_page('upload.php', MediaReplacePage::SLUG);
    }

    private static function onReplacePage()
    {
        return is_admin() && isset($_GET['page']) && $_GET['page'] === MediaReplacePage::SLUG; // phpcs:ignore
    }

    public static function parentFile($parent)
    {
        return self::onReplacePage() ? 'upload.php' : $parent;
    }

    public static function submenuFile($file)
    {
        return self::onReplacePage() ? 'upload.php' : $file;
    }

    public static function adminTitle($title, $original)
    {
        if (self::onReplacePage()) {
            return __('Remplacer le fichier', 'smbb-wpcodetool') . ' ' . $title;
        }

        return $title;
    }

    /* ---------------------------------------------------------------- Envoi du formulaire */

    public static function handlePost()
    {
        $id = isset($_POST['attachment_id']) ? (int) $_POST['attachment_id'] : 0;

        if (!$id || !isset($_POST['_smbb_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_smbb_nonce'])), self::ACTION . '_' . $id)) {
            wp_die(esc_html__('Le lien a expiré. Rechargez la page et recommencez.', 'smbb-wpcodetool'));
        }

        if (!self::canReplace($id)) {
            wp_die(esc_html__('Vous n\'avez pas le droit de modifier ce média.', 'smbb-wpcodetool'));
        }

        $mode = isset($_POST['format_mode']) && $_POST['format_mode'] === 'rename' ? 'rename' : 'convert';
        $links = !empty($_POST['update_links']);
        $upload = isset($_FILES['smbb_file']) && is_array($_FILES['smbb_file']) ? $_FILES['smbb_file'] : array(); // phpcs:ignore

        $result = MediaReplacer::replace($id, $upload, $mode, $links);

        set_transient('smbb_media_replace_' . get_current_user_id(), is_wp_error($result)
            ? array('ok' => false, 'id' => $id, 'message' => $result->get_error_message())
            : array('ok' => true, 'id' => $id, 'info' => $result), 300);

        $url = self::pageUrl($id) . '&done=1';

        if (!empty($_POST['return']) && $_POST['return'] === 'grid') {
            $url .= '&return=grid';
        }

        wp_safe_redirect($url);
        exit;
    }

    /** Résultat du dernier envoi (lu une fois). */
    public static function pullResult($id)
    {
        $key = 'smbb_media_replace_' . get_current_user_id();
        $data = get_transient($key);

        if (!is_array($data) || (int) $data['id'] !== (int) $id) {
            return null;
        }

        delete_transient($key);

        return $data;
    }

    /* ---------------------------------------------------------------- Anciennes URL */

    /**
     * @param array<string,string> $map ancienne URL => nouvelle
     */
    public static function addRedirects($id, array $map)
    {
        $all = get_option(self::REDIRECTS, array());
        $all = is_array($all) ? $all : array();

        foreach ($map as $old => $new) {
            $from = self::uploadsRelative($old);
            $to = self::uploadsRelative($new);

            if ($from === '' || $to === '' || $from === $to) {
                continue;
            }

            unset($all[$to]); // le nouveau fichier existe : plus de redirection depuis ce chemin
            $all[$from] = array('id' => (int) $id, 'to' => $to);
        }

        // Une chaîne a → b puis b → c : a pointe directement vers c.
        foreach ($all as $from => $row) {
            $guard = 0;

            while (isset($all[$row['to']]) && $guard++ < 5) {
                $row['to'] = $all[$row['to']]['to'];
            }

            $all[$from] = $row;
        }

        if (count($all) > self::MAX_REDIRECTS) {
            $all = array_slice($all, -self::MAX_REDIRECTS, null, true);
        }

        update_option(self::REDIRECTS, $all, false);
    }

    /**
     * Une ancienne URL de fichier arrive sur WordPress (le serveur n'a pas trouvé le fichier) :
     * 301 vers le nouveau fichier. Dépend du serveur : si Nginx répond lui-même 404 aux images
     * absentes, la requête n'atteint jamais WordPress (les liens des contenus sont de toute façon à jour).
     */
    public static function redirectOldUrl()
    {
        if (!is_404()) {
            return;
        }

        $all = get_option(self::REDIRECTS, array());

        if (!is_array($all) || !$all) {
            return;
        }

        $request = isset($_SERVER['REQUEST_URI']) ? (string) wp_unslash($_SERVER['REQUEST_URI']) : ''; // phpcs:ignore
        $path = rawurldecode((string) wp_parse_url($request, PHP_URL_PATH));
        $base = '/' . trim((string) wp_parse_url(wp_get_upload_dir()['baseurl'], PHP_URL_PATH), '/') . '/';

        if ($path === '' || strpos($path, $base) !== 0) {
            return;
        }

        $rel = substr($path, strlen($base));

        if (!isset($all[$rel])) {
            return;
        }

        wp_safe_redirect(trailingslashit(wp_get_upload_dir()['baseurl']) . $all[$rel]['to'], 301, 'CodeTool');
        exit;
    }

    private static function uploadsRelative($url)
    {
        $base = trailingslashit(wp_get_upload_dir()['baseurl']);
        $url = (string) $url;

        if (strpos($url, $base) === 0) {
            return substr($url, strlen($base));
        }

        // Même site en http / https.
        $path = (string) wp_parse_url($url, PHP_URL_PATH);
        $base_path = trailingslashit((string) wp_parse_url($base, PHP_URL_PATH));

        return strpos($path, $base_path) === 0 ? substr($path, strlen($base_path)) : '';
    }

    /* ---------------------------------------------------------------- Cache des navigateurs */

    private static function version($id)
    {
        static $cache = array();
        $id = (int) $id;

        if (!isset($cache[$id])) {
            $cache[$id] = $id ? (int) get_post_meta($id, MediaReplacer::META_REPLACED, true) : 0;
        }

        return $cache[$id];
    }

    private static function addVersion($url, $v)
    {
        if (!$v || !is_string($url) || $url === '' || strpos($url, 'v=' . $v) !== false) {
            return $url;
        }

        return add_query_arg('v', $v, $url);
    }

    public static function bustImageSrc($image, $id)
    {
        $v = self::version($id);

        if ($v && is_array($image) && !empty($image[0])) {
            $image[0] = self::addVersion($image[0], $v);
        }

        return $image;
    }

    public static function bustSrcset($sources, $size_array, $src, $meta, $id)
    {
        $v = self::version($id);

        if ($v && is_array($sources)) {
            foreach ($sources as $w => $source) {
                if (!empty($source['url'])) {
                    $sources[$w]['url'] = self::addVersion($source['url'], $v);
                }
            }
        }

        return $sources;
    }

    /** Images insérées dans les contenus (classe wp-image-ID) : ?v=… sur src et srcset. */
    public static function bustContentImg($html, $context, $id)
    {
        $v = self::version($id);

        if (!$v) {
            return $html;
        }

        $file = (string) get_post_meta((int) $id, '_wp_attached_file', true);

        if ($file === '') {
            return $html;
        }

        $stem = preg_quote(pathinfo($file, PATHINFO_FILENAME), '#');
        $pattern = '#(' . $stem . '(?:-\d+x\d+)?\.[a-z0-9]{2,5})(?![?\w])#i';

        return preg_replace_callback('#\s(src|srcset)=(["\'])(.*?)\2#is', function ($m) use ($pattern, $v) {
            $value = preg_replace($pattern, '$1?v=' . $v, $m[3]);

            return ' ' . $m[1] . '=' . $m[2] . $value . $m[2];
        }, (string) $html);
    }

    /**
     * Fenêtre des médias : vignettes rafraîchies (l'URL à copier, elle, reste propre) et SVG
     * affichés comme des images.
     */
    public static function prepareForJs($response, $attachment)
    {
        if (!$attachment instanceof \WP_Post || !is_array($response)) {
            return $response;
        }

        $v = self::version($attachment->ID);

        if ($v && !empty($response['sizes']) && is_array($response['sizes'])) {
            foreach ($response['sizes'] as $size => $data) {
                if (!empty($data['url'])) {
                    $response['sizes'][$size]['url'] = self::addVersion($data['url'], $v);
                }
            }
        }

        if ($attachment->post_mime_type === 'image/svg+xml') {
            $url = $v ? self::addVersion($response['url'], $v) : $response['url'];
            $w = !empty($response['width']) ? (int) $response['width'] : 512;
            $h = !empty($response['height']) ? (int) $response['height'] : 512;
            $full = array('url' => $url, 'width' => $w, 'height' => $h, 'orientation' => $w >= $h ? 'landscape' : 'portrait');
            $response['image'] = array('src' => $url, 'width' => $w, 'height' => $h);
            $response['thumb'] = array('src' => $url, 'width' => $w, 'height' => $h);

            if (empty($response['sizes'])) {
                $response['sizes'] = array('full' => $full, 'medium' => $full, 'thumbnail' => $full);
            }
        }

        if (self::on('replace') && self::canReplace($attachment->ID)) {
            $response['smbbReplaceUrl'] = self::pageUrl($attachment->ID);
        }

        return $response;
    }

    /* ---------------------------------------------------------------- SVG */

    public static function svgAllowedForUser($user_id = 0)
    {
        $user = $user_id ? get_userdata((int) $user_id) : wp_get_current_user();

        if (!$user || !$user->exists() || !user_can($user, 'upload_files')) {
            return false;
        }

        return (bool) array_intersect((array) $user->roles, self::settings()['svg_roles']);
    }

    public static function svgMimes($mimes)
    {
        if (self::svgAllowedForUser()) {
            $mimes['svg'] = 'image/svg+xml';
        }

        return $mimes;
    }

    /**
     * PHP reconnaît mal certains SVG (text/plain, text/html, application/xml…) : on confirme
     * l'extension quand le contenu commence bien par une balise <svg>.
     */
    public static function svgFiletype($data, $file, $filename, $mimes, $real_mime = null)
    {
        if (strtolower(pathinfo((string) $filename, PATHINFO_EXTENSION)) !== 'svg' || !self::svgAllowedForUser()) {
            return $data;
        }

        $head = @file_get_contents($file, false, null, 0, 4096); // phpcs:ignore

        if (is_string($head) && preg_match('/<svg[\s>]/i', $head)) {
            $data['ext'] = 'svg';
            $data['type'] = 'image/svg+xml';
            $data['proper_filename'] = false;
        }

        return $data;
    }

    /** Avant l'enregistrement : refus hors des rôles choisis, sinon nettoyage du fichier. */
    public static function svgPrefilter($file)
    {
        $name = isset($file['name']) ? (string) $file['name'] : '';

        if (strtolower(pathinfo($name, PATHINFO_EXTENSION)) !== 'svg' && (!isset($file['type']) || $file['type'] !== 'image/svg+xml')) {
            return $file;
        }

        if (!self::svgAllowedForUser()) {
            $file['error'] = __('Votre rôle ne permet pas d\'envoyer des fichiers SVG.', 'smbb-wpcodetool');

            return $file;
        }

        $clean = SvgSanitizer::sanitizeFile($file['tmp_name']);

        if (is_wp_error($clean)) {
            $file['error'] = $clean->get_error_message();
        } else {
            $file['size'] = (int) @filesize($file['tmp_name']); // phpcs:ignore
        }

        return $file;
    }

    public static function svgMetadata($meta, $id)
    {
        if (get_post_mime_type((int) $id) !== 'image/svg+xml') {
            return $meta;
        }

        $meta = is_array($meta) ? $meta : array();
        $file = (string) get_attached_file((int) $id);
        list($w, $h) = SvgSanitizer::dimensions($file);

        if ($w && $h) {
            $meta['width'] = $w;
            $meta['height'] = $h;
        }

        $meta['file'] = _wp_relative_upload_path($file);
        $meta['filesize'] = (int) @filesize($file); // phpcs:ignore

        return $meta;
    }

    public static function svgAdminCss()
    {
        echo '<style id="smbb-svg-media">'
            . '.attachment-preview .thumbnail img[src*=".svg"],.media-modal img.details-image[src*=".svg"]{width:100%;height:auto;max-height:100%;object-fit:contain}'
            . '.wp-list-table.media .media-icon img[src*=".svg"]{width:60px;height:60px;object-fit:contain}'
            . '</style>';
    }
}
