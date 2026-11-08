<?php

namespace Smbb\SignConnectProxima\Support;

defined('ABSPATH') || exit;

/**
 * Listes de valeurs fermées (statuts, rôles…) et leurs libellés.
 */
final class Choices
{
    public static function organizationStatuses()
    {
        return array(
            'active' => __('Active', 'smbb-signaconnect-proxima'),
            'suspended' => __('Suspendue (plus de nouvel envoi)', 'smbb-signaconnect-proxima'),
            'blocked' => __('Bloquée (envois et documents en cours bloqués)', 'smbb-signaconnect-proxima'),
        );
    }

    public static function teamStatuses()
    {
        return array(
            'active' => __('Active', 'smbb-signaconnect-proxima'),
            'archived' => __('Archivée', 'smbb-signaconnect-proxima'),
        );
    }

    public static function planStatuses()
    {
        return array(
            'active' => __('Actif', 'smbb-signaconnect-proxima'),
            'archived' => __('Archivé', 'smbb-signaconnect-proxima'),
        );
    }

    public static function roles()
    {
        return array(
            'user' => __('Utilisateur', 'smbb-signaconnect-proxima'),
            'team_supervisor' => __("Superviseur d'équipe", 'smbb-signaconnect-proxima'),
            'org_supervisor' => __("Superviseur d'organisation", 'smbb-signaconnect-proxima'),
            'org_admin' => __("Admin d'organisation", 'smbb-signaconnect-proxima'),
        );
    }

    public static function universignModes()
    {
        return array(
            'smbb_workspace' => __('Espace de travail du compte SMBB', 'smbb-signaconnect-proxima'),
            'own_account' => __('Compte Universign propre au client', 'smbb-signaconnect-proxima'),
        );
    }

    public static function universignEnvironments()
    {
        return array(
            'preprod' => __('Préproduction (api.alpha.universign.com)', 'smbb-signaconnect-proxima'),
            'prod' => __('Production (api.universign.com)', 'smbb-signaconnect-proxima'),
        );
    }

    public static function authMeans()
    {
        return array(
            'sms' => __('Code par SMS', 'smbb-signaconnect-proxima'),
            'email' => __('Code par email', 'smbb-signaconnect-proxima'),
        );
    }

    public static function channels()
    {
        return array(
            'email' => __('Email', 'smbb-signaconnect-proxima'),
            'sms' => __('SMS (Twilio)', 'smbb-signaconnect-proxima'),
        );
    }

    public static function documentStatuses()
    {
        return array(
            'draft' => __('Brouillon', 'smbb-signaconnect-proxima'),
            'sent' => __('Envoyé', 'smbb-signaconnect-proxima'),
            'opened' => __('Ouvert', 'smbb-signaconnect-proxima'),
            'signed' => __('Signé', 'smbb-signaconnect-proxima'),
            'refused' => __('Refusé', 'smbb-signaconnect-proxima'),
            'expired' => __('Expiré', 'smbb-signaconnect-proxima'),
            'cancelled' => __('Annulé', 'smbb-signaconnect-proxima'),
            'purged' => __('Purgé', 'smbb-signaconnect-proxima'),
        );
    }

    public static function detectionSources()
    {
        return array(
            'template' => __('Modèle reconnu', 'smbb-signaconnect-proxima'),
            'ai' => __('Détection IA', 'smbb-signaconnect-proxima'),
            'manual' => __('Placement manuel', 'smbb-signaconnect-proxima'),
        );
    }

    public static function storageLocations()
    {
        return array(
            'none' => __('Aucun', 'smbb-signaconnect-proxima'),
            's3' => __('Stockage de l\'organisation', 'smbb-signaconnect-proxima'),
            'universign' => __('Universign', 'smbb-signaconnect-proxima'),
        );
    }

    /**
     * Libellé d'une valeur dans une des listes ci-dessus.
     */
    public static function label($list, $value)
    {
        $values = method_exists(__CLASS__, $list) ? call_user_func(array(__CLASS__, $list)) : array();

        return isset($values[$value]) ? $values[$value] : (string) $value;
    }

    /**
     * Inverse une liste valeur => libellé vers le format attendu par Form::setOptions().
     */
    public static function options($list)
    {
        $values = method_exists(__CLASS__, $list) ? call_user_func(array(__CLASS__, $list)) : array();

        return array_flip($values);
    }

    /**
     * Pastille de statut HTML (déjà échappée).
     */
    public static function badge($list, $value)
    {
        return '<span class="scp-badge scp-badge--' . esc_attr(sanitize_html_class((string) $value)) . '">'
            . esc_html(self::label($list, $value))
            . '</span>';
    }
}
