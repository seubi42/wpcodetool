<?php

namespace Smbb\SignConnectProxima\Front;

use Smbb\SignConnectProxima\Repository\AuditLog;
use Smbb\SignConnectProxima\Repository\Documents;
use Smbb\SignConnectProxima\Repository\Lookup;
use Smbb\SignConnectProxima\Repository\Organizations;
use Smbb\SignConnectProxima\Service\UniversignFlow;

defined('ABSPATH') || exit;

/**
 * Webhook Universign : POST /wp-json/signconnect/v1/universign/webhook/{webhook_key}
 *
 * La clé secrète de l'URL désigne l'organisation (ou son espace Universign, partagé ou non). Le contenu reçu n'est jamais pris pour
 * argent comptant : il sert seulement à savoir quelle transaction relire, et l'état fait foi
 * tel que renvoyé par l'API Universign avec la clé de l'organisation.
 */
final class Webhook
{
    const NAMESPACE_V1 = 'signconnect/v1';

    public static function hooks()
    {
        add_action('rest_api_init', array(__CLASS__, 'routes'));
    }

    public static function routes()
    {
        register_rest_route(self::NAMESPACE_V1, '/universign/webhook/(?P<key>[A-Za-z0-9]{20,64})', array(
            'methods' => 'POST',
            'callback' => array(__CLASS__, 'receive'),
            'permission_callback' => '__return_true',
        ));
    }

    public static function receive(\WP_REST_Request $request)
    {
        global $wpdb;

        $organization_id = (int) $wpdb->get_var($wpdb->prepare(
            'SELECT id FROM ' . Lookup::table('scp_organization') . ' WHERE webhook_key = %s AND deleted_flag = 0',
            (string) $request['key']
        ));

        if ($organization_id < 1) {
            return new \WP_REST_Response(array('ok' => false), 404);
        }

        $wpdb->update(Lookup::table('scp_organization'), array('webhook_last_received_at' => current_time('mysql')), array('id' => $organization_id));
        $body = (string) $request->get_body();
        $event = json_decode($body, true);
        $type = is_array($event) && isset($event['type']) ? sanitize_text_field((string) $event['type']) : '';
        $transaction_id = self::transactionId($event, $body);

        if ($transaction_id === '') {
            return new \WP_REST_Response(array('ok' => true, 'ignored' => 'no transaction'), 200);
        }

        // Plusieurs organisations peuvent partager un espace Universign (même clé ou même ID
        // d'espace) : une seule URL de webhook déclarée suffit alors pour toutes.
        // Les clés sont chiffrées en base (valeurs différentes à chaque chiffrement) : la
        // comparaison des clés se fait donc ici, après déchiffrement.
        $orgs = Lookup::table('scp_organization');
        $same_key = array($organization_id);
        $receiver = Organizations::find($organization_id);
        $receiver_key = $receiver ? trim((string) $receiver['universign_api_key']) : '';

        if ($receiver_key !== '') {
            foreach ((array) $wpdb->get_col("SELECT id FROM {$orgs} WHERE deleted_flag = 0 AND universign_api_key IS NOT NULL AND universign_api_key <> ''") as $other_id) {
                $other = Organizations::find((int) $other_id);

                if ($other && trim((string) $other['universign_api_key']) === $receiver_key) {
                    $same_key[] = (int) $other_id;
                }
            }
        }

        $document_id = (int) $wpdb->get_var($wpdb->prepare(
            'SELECT d.id FROM ' . Lookup::table('scp_document') . ' d INNER JOIN ' . $orgs . ' o ON o.id = d.organization_id '
            . 'INNER JOIN ' . $orgs . ' r ON r.id = %d '
            . 'WHERE d.universign_transaction_id = %s AND d.deleted_flag = 0 AND ('
            . 'o.id IN (' . implode(',', array_map('intval', array_unique($same_key))) . ') '
            . "OR (r.universign_workspace_id <> '' AND o.universign_workspace_id = r.universign_workspace_id AND o.universign_environment = r.universign_environment)"
            . ') ORDER BY (o.id = r.id) DESC LIMIT 1',
            $organization_id,
            $transaction_id
        ));

        if ($document_id < 1) {
            return new \WP_REST_Response(array('ok' => true, 'ignored' => 'unknown transaction'), 200);
        }

        try {
            $document = Documents::find($document_id);
            $organization_id = (int) $document['organization_id'];
            $status = UniversignFlow::sync($document, 'webhook');
            AuditLog::record('universign_webhook', sprintf(__('Webhook Universign reçu : %s.', 'smbb-signaconnect-proxima'), $type ?: '?'), array(
                'event' => $type,
                'event_id' => is_array($event) && isset($event['id']) ? (string) $event['id'] : '',
                'status' => $status,
            ), $organization_id, $document_id, 'system');
        } catch (\Throwable $exception) {
            AuditLog::record('universign_webhook_failed', $exception->getMessage(), array('event' => $type), $organization_id, $document_id, 'system');

            // 500 : Universign réessaiera plus tard (1 min, 5 min, 30 min…).
            return new \WP_REST_Response(array('ok' => false), 500);
        }

        return new \WP_REST_Response(array('ok' => true), 200);
    }

    /**
     * Identifiant tx_… : objet transaction du payload, sinon première mention dans le corps.
     */
    private static function transactionId($event, $body)
    {
        if (is_array($event) && isset($event['payload']['object']) && is_array($event['payload']['object'])) {
            $object = $event['payload']['object'];

            foreach (array('id', 'transaction_id', 'transaction') as $key) {
                if (isset($object[$key]) && is_string($object[$key]) && strpos($object[$key], 'tx_') === 0) {
                    return $object[$key];
                }
            }
        }

        return preg_match('/"(tx_[A-Za-z0-9]+)"/', (string) $body, $match) ? $match[1] : '';
    }
}
