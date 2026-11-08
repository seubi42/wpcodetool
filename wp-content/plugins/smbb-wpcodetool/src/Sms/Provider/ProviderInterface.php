<?php

namespace Smbb\WpCodeTool\Sms\Provider;

defined('ABSPATH') || exit;

/**
 * Connecteur d'envoi de SMS (Twilio aujourd'hui, OVH / Brevo / autre demain).
 *
 * Ses réglages sont rangés sous sa clé dans l'option smbb_codetool_sms['providers']. Pour en ajouter un :
 *
 *     add_filter('smbb_wpcodetool_sms_providers', function ($providers) {
 *         $providers['ovh'] = new MonConnecteurOvh();
 *         return $providers;
 *     });
 */
interface ProviderInterface
{
    /** Clé technique stable (twilio, ovh...). */
    public function key();

    /** Nom affiché. */
    public function label();

    public function defaults();

    /**
     * Nettoie les réglages postés (déjà wp_unslash). $previous sert à garder un secret non ressaisi.
     */
    public function sanitize(array $input, array $previous);

    /**
     * Réglages suffisants pour envoyer ? true, sinon le message d'erreur.
     *
     * @return true|string
     */
    public function ready(array $settings);

    /**
     * Envoie un SMS.
     *
     * @param string $to   Numéro (international +33..., ou national 06... converti en +33).
     * @param string $body Texte.
     * @return array{id:string,status:string,to:string,raw:array}
     * @throws \RuntimeException en cas d'échec (message lisible).
     */
    public function send(array $settings, $to, $body);

    /** Champs du connecteur. Noms : providers[<clé>][champ]. */
    public function renderFields(array $settings);

    /** Résumé court (« Twilio · service MG12… »). */
    public function summary(array $settings);
}
