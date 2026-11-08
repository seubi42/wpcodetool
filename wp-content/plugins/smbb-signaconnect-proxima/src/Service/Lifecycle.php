<?php

namespace Smbb\SignConnectProxima\Service;

use Smbb\SignConnectProxima\Repository\AuditLog;
use Smbb\SignConnectProxima\Repository\Documents;
use Smbb\SignConnectProxima\Repository\Lookup;
use Smbb\SignConnectProxima\Repository\Organizations;
use Smbb\SignConnectProxima\Support\Levels;

defined('ABSPATH') || exit;

/**
 * Tâche planifiée (toutes les heures) : relances et expirations internes, synchro Universign,
 * conservation, brouillons abandonnés, clôture automatique du mois de facturation.
 */
final class Lifecycle
{
    const HOOK = 'scp_hourly';

    public static function hooks()
    {
        add_action(self::HOOK, array(__CLASS__, 'run'));
        add_action('init', array(__CLASS__, 'schedule'));
    }

    public static function schedule()
    {
        if (!wp_next_scheduled(self::HOOK)) {
            wp_schedule_event(time() + 300, 'hourly', self::HOOK);
        }
    }

    public static function unschedule()
    {
        wp_clear_scheduled_hook(self::HOOK);
    }

    public static function run()
    {
        foreach (array('expire', 'remind', 'syncUniversign', 'purgeDrafts', 'applyRetention', 'closeBillingMonth') as $step) {
            try {
                self::$step();
            } catch (\Throwable $exception) {
                AuditLog::record('lifecycle_error', $exception->getMessage(), array('step' => $step), null, null, 'system');
            }
        }
    }

    private static function expire()
    {
        global $wpdb;

        $ids = $wpdb->get_col($wpdb->prepare(
            'SELECT id FROM ' . Lookup::table('scp_document') . " WHERE deleted_flag = 0 AND status IN ('sent','opened') AND signature_level = %s AND expires_at IS NOT NULL AND expires_at <= %s LIMIT 200",
            Levels::INTERNAL,
            current_time('mysql')
        ));

        foreach ($ids as $id) {
            if (!Documents::transition($id, array('sent', 'opened'), array('status' => 'expired', 'closed_at' => current_time('mysql')))) {
                continue;
            }

            $document = Documents::find($id);
            $organization = Organizations::find($document['organization_id']);
            AuditLog::record('document_expired', __('Lien expiré sans signature.', 'smbb-signaconnect-proxima'), array(), $document['organization_id'], $id, 'system');

            if ($organization) {
                try {
                    Mailer::expiredToSender($document, $organization);
                } catch (\Throwable $exception) {
                    AuditLog::record('notification_failed', $exception->getMessage(), array('to' => 'sender'), $document['organization_id'], $id, 'system');
                }
            }
        }
    }

    /**
     * Relance n°k quand on atteint le k-ième délai de l'organisation (ex. 2,5,10 jours après l'envoi).
     */
    private static function remind()
    {
        global $wpdb;

        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT id, organization_id, sent_at, reminder_count FROM ' . Lookup::table('scp_document') . " WHERE deleted_flag = 0 AND status IN ('sent','opened') AND signature_level = %s AND sent_at IS NOT NULL LIMIT 500",
            Levels::INTERNAL
        ), ARRAY_A);
        $now = current_time('timestamp');

        foreach (is_array($rows) ? $rows : array() as $row) {
            $organization = Organizations::find($row['organization_id']);

            if (!$organization || Organizations::isBlocked($organization)) {
                continue;
            }

            $days = Organizations::reminderDays($organization);
            $next = (int) $row['reminder_count'];

            if (!isset($days[$next]) || strtotime($row['sent_at']) + $days[$next] * DAY_IN_SECONDS > $now) {
                continue;
            }

            try {
                SendService::remind(0, (int) $row['id'], true);
            } catch (\Throwable $exception) {
                AuditLog::record('reminder_failed', $exception->getMessage(), array(), $row['organization_id'], $row['id'], 'system');
                // On avance quand même le compteur pour ne pas réessayer en boucle toutes les heures.
                Documents::update($row['id'], array('reminder_count' => $next + 1));
            }
        }
    }

    /**
     * Secours des webhooks Universign : relit les transactions en attente.
     */
    private static function syncUniversign()
    {
        UniversignFlow::syncPending(20);
    }

    /**
     * Brouillons jamais envoyés depuis 7 jours : PDF supprimé du S3, ligne masquée.
     */
    private static function purgeDrafts()
    {
        global $wpdb;

        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT id, organization_id, ' . self::unitColumn('') . 'source_storage_path FROM ' . Lookup::table('scp_document') . " WHERE deleted_flag = 0 AND status = 'draft' AND creation_date < %s LIMIT 200",
            date('Y-m-d H:i:s', current_time('timestamp') - 7 * DAY_IN_SECONDS)
        ), ARRAY_A);

        foreach (is_array($rows) ? $rows : array() as $row) {
            $organization = Organizations::find($row['organization_id']);

            try {
                if ($organization && $row['source_storage_path']) {
                    Storage::forDocument($row, $organization)->delete($row['source_storage_path']);
                }
            } catch (\Throwable $exception) {
                continue;
            }

            Documents::update($row['id'], array('deleted_flag' => 1, 'deleted_date' => current_time('mysql'), 'source_storage_path' => null));
        }
    }

    /**
     * Fin de conservation : suppression des PDF et anonymisation, la ligne reste pour la facturation.
     */
    private static function applyRetention()
    {
        global $wpdb;

        $rows = $wpdb->get_results(
            'SELECT d.id, d.organization_id, d.closed_at, ' . self::unitColumn('d.') . 'd.source_storage_path, d.signed_storage_path, d.evidence_storage_path, o.retention_months FROM ' . Lookup::table('scp_document') . ' d '
            . 'INNER JOIN ' . Lookup::table('scp_organization') . " o ON o.id = d.organization_id WHERE d.deleted_flag = 0 AND d.anonymized_at IS NULL AND d.storage_location = 's3' "
            . "AND d.status IN ('signed','refused','expired','cancelled') AND d.closed_at IS NOT NULL AND d.closed_at < DATE_SUB(NOW(), INTERVAL GREATEST(o.retention_months, 1) MONTH) LIMIT 100",
            ARRAY_A
        );

        foreach (is_array($rows) ? $rows : array() as $row) {
            $organization = Organizations::find($row['organization_id']);

            try {
                $storage = Storage::forDocument($row, $organization);
                $storage->delete($row['source_storage_path']);
                $storage->delete($row['signed_storage_path']);
                $storage->delete($row['evidence_storage_path']);
            } catch (\Throwable $exception) {
                AuditLog::record('retention_failed', $exception->getMessage(), array(), $row['organization_id'], $row['id'], 'system');
                continue;
            }

            Documents::update($row['id'], array(
                'recipient_name' => null,
                'recipient_first_name' => null,
                'recipient_last_name' => null,
                'recipient_email' => null,
                'recipient_phone' => null,
                'send_message' => null,
                'signer_name' => null,
                'signer_ip' => null,
                'signer_user_agent' => null,
                'refusal_message' => null,
                'filename' => 'document-' . $row['id'] . '.pdf',
                'source_storage_path' => null,
                'signed_storage_path' => null,
                'evidence_storage_path' => null,
                'public_token_secret' => null,
                'anonymized_at' => current_time('mysql'),
            ));
            $wpdb->delete(Lookup::table('scp_document_field'), array('document_id' => (int) $row['id']));
            $wpdb->update(Lookup::table('scp_audit_log'), array('context' => null, 'actor_ip' => null), array('document_id' => (int) $row['id']));
            AuditLog::record('document_anonymized', __('Fin de conservation : fichiers supprimés et données personnelles effacées.', 'smbb-signaconnect-proxima'), array(
                'retention_months' => (int) $row['retention_months'],
            ), $row['organization_id'], $row['id'], 'system');
        }
    }

    private static function closeBillingMonth()
    {
        Billing::autoClose();
    }

    /**
     * Colonne storage_unit (0.12.0) dans un SELECT, seulement une fois la table à jour.
     */
    private static function unitColumn($alias)
    {
        return \Smbb\SignConnectProxima\Support\Schema::atLeast('0.12.0') ? $alias . 'storage_unit, ' : '';
    }
}
