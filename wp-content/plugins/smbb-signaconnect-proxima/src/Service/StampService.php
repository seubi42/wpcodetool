<?php

namespace Smbb\SignConnectProxima\Service;

use Smbb\SignConnectProxima\Repository\AuditLog;
use Smbb\SignConnectProxima\Repository\Lookup;
use Smbb\SignConnectProxima\Support\Schema;

defined('ABSPATH') || exit;

/**
 * Tampons apposés dans les zones « Tampon » : celui de l'équipe du document s'il existe,
 * sinon celui de l'organisation. Les admins d'organisation les importent depuis l'app,
 * SMBB peut aussi les choisir dans l'admin (fiches Organisation et Équipe).
 */
final class StampService
{
    const MAX_BYTES = 3145728;

    /** @var array<int,array<string,mixed>|null> */
    private static $teams = array();

    /**
     * Pièce jointe du tampon à utiliser (0 si aucun) : l'équipe passe avant l'organisation.
     */
    public static function attachmentId(array $organization, $team_id = 0)
    {
        $team = self::team($team_id);

        if ($team && !empty($team['stamp_attachment_id']) && (int) $team['organization_id'] === (int) $organization['id'] && get_attached_file((int) $team['stamp_attachment_id'])) {
            return (int) $team['stamp_attachment_id'];
        }

        return !empty($organization['stamp_attachment_id']) ? (int) $organization['stamp_attachment_id'] : 0;
    }

    /**
     * Fichier du tampon pour l'écrire dans un PDF ('' si aucun).
     */
    public static function path(array $organization, $team_id = 0)
    {
        $id = self::attachmentId($organization, $team_id);
        $path = $id ? get_attached_file($id) : '';

        return is_string($path) && $path !== '' && is_readable($path) ? $path : '';
    }

    public static function url($attachment_id)
    {
        $url = $attachment_id ? wp_get_attachment_image_url((int) $attachment_id, 'medium') : '';

        return is_string($url) ? $url : '';
    }

    /**
     * @return array<string,mixed>|null
     */
    public static function team($team_id)
    {
        global $wpdb;

        $team_id = (int) $team_id;

        if ($team_id < 1) {
            return null;
        }

        if (!array_key_exists($team_id, self::$teams)) {
            // SELECT * : la colonne stamp_attachment_id peut manquer tant que la table n'est pas à jour.
            $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . Lookup::table('scp_team') . ' WHERE id = %d AND deleted_flag = 0', $team_id), ARRAY_A);
            self::$teams[$team_id] = $row ?: null;
        }

        return self::$teams[$team_id];
    }

    /**
     * Import d'un tampon (PNG conseillé, fond transparent) par l'admin de l'organisation.
     *
     * @param array<string,mixed> $file entrée de $_FILES
     */
    public static function upload($actor_id, $organization_id, $team_id, array $file)
    {
        $organization = MemberService::adminOrganization($actor_id, $organization_id);
        $team = self::checkTeam($organization, $team_id);

        if (empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
            throw new \InvalidArgumentException(__('Choisissez une image.', 'smbb-signaconnect-proxima'));
        }

        if (!empty($file['size']) && (int) $file['size'] > self::MAX_BYTES) {
            throw new \InvalidArgumentException(__('Image trop lourde : 3 Mo maximum.', 'smbb-signaconnect-proxima'));
        }

        $info = @getimagesize($file['tmp_name']);

        if (!$info || !in_array($info[2], array(IMAGETYPE_PNG, IMAGETYPE_JPEG), true)) {
            throw new \InvalidArgumentException(__('Image illisible : importez un PNG (idéalement sur fond transparent) ou un JPEG.', 'smbb-signaconnect-proxima'));
        }

        if ($info[0] < 40 || $info[1] < 30) {
            throw new \InvalidArgumentException(__('Image trop petite pour un tampon.', 'smbb-signaconnect-proxima'));
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $extension = $info[2] === IMAGETYPE_PNG ? 'png' : 'jpg';
        $mime = $info[2] === IMAGETYPE_PNG ? 'image/png' : 'image/jpeg';
        $upload = wp_upload_bits('scp-tampon-' . (int) $organization['id'] . ($team ? '-' . (int) $team['id'] : '') . '.' . $extension, null, (string) file_get_contents($file['tmp_name']));

        if (!empty($upload['error'])) {
            throw new \RuntimeException((string) $upload['error']);
        }

        $label = $team ? sprintf(__('Tampon de l\'équipe %s', 'smbb-signaconnect-proxima'), $team['name']) : sprintf(__('Tampon de %s', 'smbb-signaconnect-proxima'), $organization['name']);
        $attachment_id = wp_insert_attachment(array(
            'post_mime_type' => $mime,
            'post_title' => $label,
            'post_status' => 'inherit',
            'guid' => $upload['url'],
        ), $upload['file']);

        if (is_wp_error($attachment_id) || !$attachment_id) {
            throw new \RuntimeException(__("Le tampon n'a pas pu être enregistré.", 'smbb-signaconnect-proxima'));
        }

        wp_update_attachment_metadata($attachment_id, wp_generate_attachment_metadata($attachment_id, $upload['file']));
        update_post_meta($attachment_id, '_scp_stamp_upload', 1);

        self::store($organization, $team, (int) $attachment_id);
        AuditLog::record('stamp_saved', $label . ' : ' . __('importé.', 'smbb-signaconnect-proxima'), array('team_id' => $team ? (int) $team['id'] : 0), (int) $organization['id']);
    }

    public static function remove($actor_id, $organization_id, $team_id)
    {
        $organization = MemberService::adminOrganization($actor_id, $organization_id);
        $team = self::checkTeam($organization, $team_id);
        self::store($organization, $team, 0);
        AuditLog::record('stamp_removed', ($team ? sprintf(__('Tampon de l\'équipe %s', 'smbb-signaconnect-proxima'), $team['name']) : __("Tampon de l'organisation", 'smbb-signaconnect-proxima')) . ' : ' . __('retiré.', 'smbb-signaconnect-proxima'), array('team_id' => $team ? (int) $team['id'] : 0), (int) $organization['id']);
    }

    /**
     * @return array<string,mixed>|null null pour l'organisation elle-même
     */
    private static function checkTeam(array $organization, $team_id)
    {
        if (!(int) $team_id) {
            return null;
        }

        Schema::ensure();
        $team = self::team($team_id);

        if (!$team || (int) $team['organization_id'] !== (int) $organization['id']) {
            throw new \RuntimeException(__('Équipe introuvable.', 'smbb-signaconnect-proxima'));
        }

        if (!array_key_exists('stamp_attachment_id', $team)) {
            throw new \RuntimeException(__('La base n\'est pas encore à jour : ouvrez une page de l\'admin WordPress puis réessayez.', 'smbb-signaconnect-proxima'));
        }

        return $team;
    }

    private static function store(array $organization, $team, $attachment_id)
    {
        global $wpdb;

        $old = $team ? (int) $team['stamp_attachment_id'] : (int) $organization['stamp_attachment_id'];
        $table = Lookup::table($team ? 'scp_team' : 'scp_organization');
        $id = $team ? (int) $team['id'] : (int) $organization['id'];

        $wpdb->update($table, array(
            'stamp_attachment_id' => $attachment_id ?: null,
            'lastupdate_date' => current_time('mysql'),
            'lastupdate_by' => get_current_user_id(),
        ), array('id' => $id));

        // Seuls les fichiers importés depuis l'app sont supprimés (pas une image choisie dans la médiathèque).
        if ($old && $old !== $attachment_id && get_post_meta($old, '_scp_stamp_upload', true)) {
            wp_delete_attachment($old, true);
        }

        unset(self::$teams[$id]);
        Lookup::flush();
    }
}
