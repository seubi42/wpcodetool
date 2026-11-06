<?php

namespace Smbb\SignConnectProxima\Admin;

use Smbb\SignConnectProxima\Repository\AuditLog;
use Smbb\SignConnectProxima\Repository\Documents;
use Smbb\SignConnectProxima\Service\UniversignFlow;

defined('ABSPATH') || exit;

/**
 * Bouton « Actualiser depuis Universign » de la fiche document (utile tant que le webhook n'est pas déclaré).
 */
final class DocumentActions
{
    const ACTION = 'scp_universign_sync';

    public static function hooks()
    {
        add_action('admin_post_' . self::ACTION, array(__CLASS__, 'handle'));
    }

    public static function syncUrl($document_id)
    {
        return wp_nonce_url(
            add_query_arg(array('action' => self::ACTION, 'document_id' => absint($document_id)), admin_url('admin-post.php')),
            self::ACTION . '_' . absint($document_id)
        );
    }

    public static function handle()
    {
        $document_id = isset($_GET['document_id']) ? absint($_GET['document_id']) : 0;

        if (!current_user_can('manage_options') || !check_admin_referer(self::ACTION . '_' . $document_id)) {
            wp_die(esc_html__('Action non autorisée.', 'smbb-signaconnect-proxima'), '', array('response' => 403));
        }

        $document = Documents::find($document_id);
        $result = array('success' => false, 'message' => __('Document introuvable.', 'smbb-signaconnect-proxima'));

        if ($document) {
            try {
                $status = UniversignFlow::sync($document, 'admin');
                $result = array('success' => true, 'message' => sprintf(__('Universign relu : statut « %s ».', 'smbb-signaconnect-proxima'), $status));
            } catch (\Throwable $exception) {
                $result['message'] = $exception->getMessage();
                AuditLog::record('universign_sync_failed', $exception->getMessage(), array('origin' => 'admin'), $document['organization_id'], $document_id, 'user', get_current_user_id());
            }
        }

        set_transient('scp_sync_' . get_current_user_id() . '_' . $document_id, $result, 300);
        wp_safe_redirect(wp_get_referer() ?: admin_url());
        exit;
    }

    public static function notice($document_id)
    {
        $key = 'scp_sync_' . get_current_user_id() . '_' . absint($document_id);
        $result = get_transient($key);

        if (!is_array($result)) {
            return '';
        }

        delete_transient($key);

        return '<div class="notice notice-' . ($result['success'] ? 'success' : 'error') . ' inline"><p>' . esc_html($result['message']) . '</p></div>';
    }
}
