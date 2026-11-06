<?php

namespace Smbb\SignConnectProxima\CodeTool;

use Smbb\SignConnectProxima\Repository\AuditLog;

defined('ABSPATH') || exit;

/**
 * Les documents naissent dans l'app (lot 2) : l'admin ne fait que les consulter.
 */
final class DocumentHooks
{
    public function validate(array $data, array $context = array())
    {
        return array(
            'filename' => __("Les documents se créent et se modifient depuis l'app SignaConnect, pas depuis l'admin.", 'smbb-signaconnect-proxima'),
        );
    }

    public function beforeDelete(array $row, array $context = array())
    {
        if (!empty($row['source_storage_path']) || !empty($row['signed_storage_path']) || !empty($row['universign_transaction_id'])) {
            return __('Ce document a des fichiers stockés : utilisez la purge (lot 2) pour supprimer aussi les fichiers.', 'smbb-signaconnect-proxima');
        }

        AuditLog::record('document_deleted', __('Document supprimé depuis l\'admin.', 'smbb-signaconnect-proxima'), array('filename' => isset($row['filename']) ? $row['filename'] : ''), isset($row['organization_id']) ? (int) $row['organization_id'] : null, isset($row['id']) ? (int) $row['id'] : null);

        return true;
    }
}
