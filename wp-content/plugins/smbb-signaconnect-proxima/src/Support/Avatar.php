<?php

namespace Smbb\SignConnectProxima\Support;

defined('ABSPATH') || exit;

/**
 * Photo de profil des utilisateurs : image carrée (256 px) rangée dans la médiathèque,
 * référencée par la méta utilisateur scp_avatar_id. Elle remplace aussi l'avatar WordPress
 * (Gravatar) partout où get_avatar() est utilisé, admin comprise.
 */
final class Avatar
{
    const META = 'scp_avatar_id';
    const SIZE = 256;
    const MAX_BYTES = 8388608;

    public static function hooks()
    {
        add_filter('pre_get_avatar_data', array(__CLASS__, 'avatarData'), 10, 2);
    }

    /**
     * @param array<string,mixed> $args
     * @param mixed $id_or_email
     */
    public static function avatarData($args, $id_or_email)
    {
        $user_id = 0;

        if (is_numeric($id_or_email)) {
            $user_id = (int) $id_or_email;
        } elseif ($id_or_email instanceof \WP_User) {
            $user_id = (int) $id_or_email->ID;
        } elseif ($id_or_email instanceof \WP_Post) {
            $user_id = (int) $id_or_email->post_author;
        } elseif ($id_or_email instanceof \WP_Comment) {
            $user_id = (int) $id_or_email->user_id;
        } elseif (is_string($id_or_email) && is_email($id_or_email)) {
            $user = get_user_by('email', $id_or_email);
            $user_id = $user ? (int) $user->ID : 0;
        }

        $url = $user_id ? self::url($user_id) : '';

        if ($url !== '') {
            $args['url'] = $url;
            $args['found_avatar'] = true;
        }

        return $args;
    }

    public static function url($user_id)
    {
        $id = (int) get_user_meta((int) $user_id, self::META, true);
        $url = $id ? wp_get_attachment_image_url($id, 'full') : '';

        return is_string($url) ? $url : '';
    }

    /**
     * Pastille ronde : la photo si elle existe, sinon les initiales sur le dégradé SignaConnect.
     */
    public static function html($user_id, $name = '', $class = '')
    {
        $url = self::url($user_id);

        if ($name === '') {
            $user = get_userdata((int) $user_id);
            $name = $user ? ($user->display_name !== '' ? $user->display_name : $user->user_login) : '';
        }

        $class = trim('scp-face ' . $class . ($url !== '' ? ' has-photo' : ''));

        return $url !== ''
            ? '<span class="' . esc_attr($class) . '" aria-hidden="true"><img src="' . esc_url($url) . '" alt="" loading="lazy"></span>'
            : '<span class="' . esc_attr($class) . '" aria-hidden="true">' . esc_html(self::initials($name)) . '</span>';
    }

    public static function initials($name)
    {
        $parts = preg_split('/[\s.\-_@]+/u', trim((string) $name), -1, PREG_SPLIT_NO_EMPTY);
        $initials = '';

        foreach (array_slice($parts ?: array('?'), 0, 2) as $part) {
            $initials .= mb_strtoupper(mb_substr($part, 0, 1));
        }

        return $initials;
    }

    /**
     * Enregistre une photo importée (PNG, JPEG ou WebP) : recadrée au centre, 256 × 256, en JPEG.
     *
     * @param array<string,mixed> $file entrée de $_FILES
     */
    public static function save($user_id, array $file)
    {
        $user_id = (int) $user_id;

        if (empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
            throw new \InvalidArgumentException(__('Photo non reçue, réessayez.', 'smbb-signaconnect-proxima'));
        }

        if (!empty($file['size']) && (int) $file['size'] > self::MAX_BYTES) {
            throw new \InvalidArgumentException(__('Photo trop lourde : 8 Mo maximum.', 'smbb-signaconnect-proxima'));
        }

        $info = @getimagesize($file['tmp_name']);
        $types = array(IMAGETYPE_JPEG, IMAGETYPE_PNG);

        if (defined('IMAGETYPE_WEBP')) {
            $types[] = IMAGETYPE_WEBP;
        }

        if (!$info || !in_array($info[2], $types, true)) {
            throw new \InvalidArgumentException(__('Photo illisible : importez une image JPEG, PNG ou WebP.', 'smbb-signaconnect-proxima'));
        }

        if ($info[0] < 32 || $info[1] < 32) {
            throw new \InvalidArgumentException(__('Photo trop petite.', 'smbb-signaconnect-proxima'));
        }

        require_once ABSPATH . 'wp-admin/includes/image.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';

        $editor = wp_get_image_editor($file['tmp_name']);

        if (is_wp_error($editor)) {
            throw new \RuntimeException(__("Le serveur ne sait pas retailler cette image (extension GD ou Imagick absente).", 'smbb-signaconnect-proxima'));
        }

        if (method_exists($editor, 'maybe_exif_rotate')) {
            $editor->maybe_exif_rotate();
        }

        $size = $editor->get_size();
        $side = min((int) $size['width'], (int) $size['height']);
        $editor->crop((int) floor(($size['width'] - $side) / 2), (int) floor(($size['height'] - $side) / 2), $side, $side, min(self::SIZE, $side), min(self::SIZE, $side));
        $editor->set_quality(88);

        $uploads = wp_upload_dir();

        if (!empty($uploads['error'])) {
            throw new \RuntimeException((string) $uploads['error']);
        }

        $name = wp_unique_filename($uploads['path'], 'scp-avatar-' . $user_id . '.jpg');
        $saved = $editor->save(trailingslashit($uploads['path']) . $name, 'image/jpeg');

        if (is_wp_error($saved)) {
            throw new \RuntimeException($saved->get_error_message());
        }

        $attachment_id = wp_insert_attachment(array(
            'post_mime_type' => 'image/jpeg',
            'post_title' => sprintf(__('Photo de profil (utilisateur %d)', 'smbb-signaconnect-proxima'), $user_id),
            'post_status' => 'inherit',
            'post_author' => $user_id,
            'guid' => trailingslashit($uploads['url']) . $saved['file'],
        ), $saved['path']);

        if (is_wp_error($attachment_id) || !$attachment_id) {
            throw new \RuntimeException(__("La photo n'a pas pu être enregistrée.", 'smbb-signaconnect-proxima'));
        }

        wp_update_attachment_metadata($attachment_id, wp_generate_attachment_metadata($attachment_id, $saved['path']));
        update_post_meta($attachment_id, '_scp_avatar_user', $user_id);

        self::delete($user_id);
        update_user_meta($user_id, self::META, (int) $attachment_id);
    }

    public static function delete($user_id)
    {
        $old = (int) get_user_meta((int) $user_id, self::META, true);

        // On ne supprime que les fichiers créés ici (jamais une image choisie ailleurs dans la médiathèque).
        if ($old && (int) get_post_meta($old, '_scp_avatar_user', true) === (int) $user_id) {
            wp_delete_attachment($old, true);
        }

        delete_user_meta((int) $user_id, self::META);
    }
}
