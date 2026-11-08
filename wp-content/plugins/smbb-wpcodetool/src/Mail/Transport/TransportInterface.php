<?php

namespace Smbb\WpCodeTool\Mail\Transport;

defined('ABSPATH') || exit;

/**
 * Connecteur d'envoi des e-mails (SMTP aujourd'hui, API d'un prestataire demain).
 *
 * Un connecteur reçoit ses propres réglages (le tableau rangé sous sa clé dans
 * l'option smbb_codetool_mail['transports']) et configure le PHPMailer de WordPress
 * juste avant l'envoi (action phpmailer_init). Pour en ajouter un :
 *
 *     add_filter('smbb_wpcodetool_mail_transports', function ($transports) {
 *         $transports['brevo'] = new MonConnecteurBrevo();
 *         return $transports;
 *     });
 *
 * Un connecteur d'API qui n'utilise pas PHPMailer pourra plus tard court-circuiter
 * wp_mail() via le filtre pre_wp_mail : l'interface reste la même côté réglages.
 */
interface TransportInterface
{
    /** Clé technique stable (smtp, brevo...). */
    public function key();

    /** Nom affiché. */
    public function label();

    /** Réglages par défaut du connecteur. */
    public function defaults();

    /**
     * Nettoie les réglages postés par le formulaire.
     *
     * @param array $input    Valeurs postées (déjà wp_unslash).
     * @param array $previous Réglages enregistrés avant (pour garder un mot de passe non ressaisi).
     * @return array
     */
    public function sanitize(array $input, array $previous);

    /**
     * Configure PHPMailer pour l'envoi.
     *
     * @param \PHPMailer\PHPMailer\PHPMailer $phpmailer
     */
    public function configure($phpmailer, array $settings);

    /**
     * Réglages suffisants pour envoyer ? true, sinon le message d'erreur.
     *
     * @return true|string
     */
    public function ready(array $settings);

    /** Affiche les champs du connecteur dans CodeTool › E-mails. Noms : transports[<clé>][champ]. */
    public function renderFields(array $settings);

    /** Résumé court (« smtp.exemple.fr:587 · STARTTLS »). */
    public function summary(array $settings);
}
