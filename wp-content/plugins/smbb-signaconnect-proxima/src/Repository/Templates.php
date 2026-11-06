<?php

namespace Smbb\SignConnectProxima\Repository;

defined('ABSPATH') || exit;

/**
 * Lecture d'un modèle et de ses réglages d'envoi (message par défaut, aperçu, email du document signé…).
 * SELECT * : les colonnes ajoutées en 0.10.0 peuvent manquer tant que la table n'est pas à jour,
 * d'où les valeurs par défaut de settings().
 */
final class Templates
{
    /** @var array<int,array<string,mixed>|null> */
    private static $cache = array();

    public static function find($template_id)
    {
        global $wpdb;

        $template_id = (int) $template_id;

        if ($template_id < 1) {
            return null;
        }

        if (!array_key_exists($template_id, self::$cache)) {
            $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . Lookup::table('scp_template') . ' WHERE id = %d', $template_id), ARRAY_A);
            self::$cache[$template_id] = $row ?: null;
        }

        return self::$cache[$template_id];
    }

    /**
     * Réglages d'envoi d'un modèle, avec leurs valeurs par défaut.
     *
     * @return array<string,mixed> default_message, skip_preview, signed_mail_*, custom_fields, naming_pattern
     */
    public static function settings($template)
    {
        $template = is_array($template) ? $template : array();

        return array(
            'default_message' => isset($template['default_message']) ? (string) $template['default_message'] : '',
            'skip_preview' => !empty($template['skip_preview']),
            'signed_mail_enabled' => !empty($template['signed_mail_enabled']),
            'signed_mail_subject' => isset($template['signed_mail_subject']) ? (string) $template['signed_mail_subject'] : '',
            'signed_mail_body' => isset($template['signed_mail_body']) ? (string) $template['signed_mail_body'] : '',
            'signed_mail_cc' => self::emails(isset($template['signed_mail_cc']) ? (string) $template['signed_mail_cc'] : ''),
            'custom_fields' => self::customFields(isset($template['custom_fields']) ? $template['custom_fields'] : ''),
            'naming_pattern' => isset($template['naming_pattern']) ? (string) $template['naming_pattern'] : '',
        );
    }

    const MAX_CUSTOM_FIELDS = 6;

    /**
     * Champs de saisie propres au modèle (N° de commande, N° de bon…), ajoutés au formulaire d'envoi.
     * Ce sont des données de classement de la transaction : rien n'est écrit sur le PDF.
     *
     * @param mixed $raw tableau ou JSON [{"key":"f1","label":"N° de commande","required":true}]
     * @return array<int,array{key:string,label:string,required:bool}>
     */
    public static function customFields($raw)
    {
        if (is_array($raw) && isset($raw['custom_fields'])) {
            $raw = $raw['custom_fields'];
        }

        $items = is_array($raw) ? $raw : json_decode((string) $raw, true);
        $fields = array();
        $keys = array();

        foreach (is_array($items) ? $items : array() as $item) {
            if (!is_array($item)) {
                continue;
            }

            $label = mb_substr(trim(sanitize_text_field(isset($item['label']) ? (string) $item['label'] : '')), 0, 60);
            $label = trim(str_replace(array('[', ']'), '', $label));
            $key = sanitize_key(isset($item['key']) ? (string) $item['key'] : '');

            if ($label === '') {
                continue;
            }

            if ($key === '' || isset($keys[$key])) {
                $key = 'f' . substr(md5($label . count($fields)), 0, 6);
            }

            $keys[$key] = true;
            $fields[] = array('key' => $key, 'label' => $label, 'required' => !empty($item['required']));

            if (count($fields) >= self::MAX_CUSTOM_FIELDS) {
                break;
            }
        }

        return $fields;
    }

    /**
     * Adresses valides d'une liste saisie à la main (virgules, points-virgules, espaces ou retours à la ligne).
     *
     * @return string[]
     */
    public static function emails($raw)
    {
        $list = array();

        foreach (preg_split('/[\s,;]+/', (string) $raw) as $email) {
            $email = sanitize_email($email);

            if ($email !== '' && is_email($email) && !in_array(strtolower($email), array_map('strtolower', $list), true)) {
                $list[] = $email;
            }
        }

        return array_slice($list, 0, 10);
    }
}
