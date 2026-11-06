<?php

namespace Smbb\SignConnectProxima\Support;

defined('ABSPATH') || exit;

/**
 * Notifications dans l'app (cloche) : les 30 dernières, en méta utilisateur.
 * Mêmes événements que les emails, réglables séparément dans « Mon profil ».
 */
final class Inbox
{
    const META = 'scp_inbox';
    const PREFS = 'scp_notify_app';
    const KEEP = 30;

    /**
     * Événements notifiés dans l'app (tous par défaut).
     *
     * @return string[]
     */
    public static function preferences($user_id)
    {
        $stored = get_user_meta((int) $user_id, self::PREFS, true);

        if (!is_array($stored)) {
            return array_keys(UserPrefs::events());
        }

        return array_values(array_intersect(array_keys(UserPrefs::events()), $stored));
    }

    public static function savePreferences($user_id, array $events)
    {
        update_user_meta((int) $user_id, self::PREFS, array_values(array_intersect(array_keys(UserPrefs::events()), $events)));
    }

    /**
     * Notification à l'expéditeur d'un document.
     */
    public static function document(array $document, $event)
    {
        $user_id = isset($document['sender_id']) ? (int) $document['sender_id'] : 0;

        if ($user_id < 1 || !in_array($event, self::preferences($user_id), true)) {
            return;
        }

        $who = !empty($document['signer_name']) ? $document['signer_name'] : (!empty($document['recipient_name']) ? $document['recipient_name'] : (string) $document['recipient_email']);
        $texts = array(
            'signed' => __('%1$s a signé « %2$s ».', 'smbb-signaconnect-proxima'),
            'refused' => __('%1$s a refusé de signer « %2$s ».', 'smbb-signaconnect-proxima'),
            'expired' => __('Le lien de %1$s pour « %2$s » a expiré sans signature.', 'smbb-signaconnect-proxima'),
        );

        if (!isset($texts[$event])) {
            return;
        }

        self::push($user_id, array(
            'event' => $event,
            'text' => sprintf($texts[$event], $who, preg_replace('/\.pdf$/i', '', (string) $document['filename'])),
            'document_id' => (int) $document['id'],
        ));
    }

    public static function push($user_id, array $item)
    {
        $items = self::items($user_id);
        array_unshift($items, array_merge(array(
            'id' => wp_generate_password(8, false, false),
            'time' => current_time('mysql'),
            'read' => false,
        ), $item));
        update_user_meta((int) $user_id, self::META, array_slice($items, 0, self::KEEP));
    }

    /**
     * @return array<int,array<string,mixed>> du plus récent au plus ancien
     */
    public static function items($user_id)
    {
        $items = get_user_meta((int) $user_id, self::META, true);

        return is_array($items) ? array_values(array_filter($items, 'is_array')) : array();
    }

    public static function unread($user_id)
    {
        return count(array_filter(self::items($user_id), function ($item) {
            return empty($item['read']);
        }));
    }

    public static function markRead($user_id)
    {
        $items = self::items($user_id);

        foreach ($items as &$item) {
            $item['read'] = true;
        }

        unset($item);
        update_user_meta((int) $user_id, self::META, $items);
    }
}
