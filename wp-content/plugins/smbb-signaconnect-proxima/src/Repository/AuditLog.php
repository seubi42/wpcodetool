<?php

namespace Smbb\SignConnectProxima\Repository;

defined('ABSPATH') || exit;

/**
 * Écriture dans le journal d'audit (table scp_audit_log).
 */
final class AuditLog
{
    /**
     * @param array<string,mixed> $context
     */
    public static function record($event_type, $message = '', array $context = array(), $organization_id = null, $document_id = null, $actor_type = 'admin', $actor_id = null)
    {
        global $wpdb;

        if ($actor_id === null && $actor_type === 'admin' && function_exists('get_current_user_id')) {
            $actor_id = get_current_user_id() ?: null;
        }

        $wpdb->insert(Lookup::table('scp_audit_log'), array(
            'organization_id' => $organization_id ? (int) $organization_id : null,
            'document_id' => $document_id ? (int) $document_id : null,
            'event_type' => substr(sanitize_key($event_type), 0, 80),
            'event_date' => current_time('mysql'),
            'creation_date' => current_time('mysql'),
            'actor_type' => sanitize_key($actor_type),
            'actor_id' => $actor_id ? (int) $actor_id : null,
            'actor_ip' => isset($_SERVER['REMOTE_ADDR']) ? substr(sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])), 0, 64) : null,
            'message' => (string) $message,
            'context' => $context ? wp_json_encode($context) : null,
        ));
    }
}
