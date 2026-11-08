<?php

namespace Smbb\WpCodeTool\Sms\Provider;

defined('ABSPATH') || exit;

/**
 * Facultatif pour un connecteur SMS : suivi de remise et coût de chaque envoi.
 *
 * Quand le connecteur l'implémente, Sms::send glisse dans ses réglages la clé « _status_callback »
 * (adresse du webhook de CodeTool) à transmettre au fournisseur, puis Sms\SmsTracking :
 * - reçoit les webhooks sur /wp-json/smbb-wpcodetool/v1/sms/status/<clé> et les passe à parseCallback() ;
 * - relit chaque message avec fetch() pour connaître son prix (et son statut si le webhook n'arrive pas).
 */
interface TrackingInterface
{
    /**
     * Lit un webhook de statut.
     *
     * @param string|string[] $url Adresse(s) possible(s) de l'appel (sert à vérifier la signature).
     * @param array  $params  Paramètres postés.
     * @param array  $headers En-têtes, clés en minuscules.
     * @return array{id:string,status:string,error_code:string}|\WP_Error WP_Error si la signature est fausse.
     */
    public function parseCallback(array $settings, $url, array $params, array $headers);

    /**
     * Relit un message chez le fournisseur.
     *
     * @return array{status:string,error_code:string,price:float|null,currency:string,segments:int}
     * @throws \RuntimeException
     */
    public function fetch(array $settings, $id);
}
