<?php

namespace Smbb\Tomo\Service;

use Smbb\Tomo\Support\Roles;
use Smbb\Tomo\Support\Settings;

defined('ABSPATH') || exit;

/**
 * Certificats Apple Wallet : dates lues directement dans les certificats collés dans les réglages.
 * Google Wallet : la clé du compte de service n'a pas de date lisible ; elle est essayée une fois par jour
 * auprès de Google, et un refus déclenche les mêmes alertes.
 * À partir de 30 jours avant l'échéance (et après) : bandeau dans l'administration pour les gestionnaires,
 * et un e-mail par jour à l'administrateur, tant que le certificat n'est pas renouvelé.
 */
final class CertificateAlerts
{
    const OPTION_DAY = 'smbb_tomo_cert_alert_day';
    const OPTION_GOOGLE = 'smbb_tomo_google_key_check';

    public static function hooks()
    {
        add_action('admin_notices', array(__CLASS__, 'notice'));
    }

    public static function notice()
    {
        if (!Roles::canManage()) {
            return;
        }

        $warning = self::warning();

        if ($warning === '') {
            return;
        }

        echo '<div class="notice notice-' . (self::expired() || self::googleFailed() ? 'error' : 'warning') . '"><p><strong>Tomo</strong> · ' . esc_html($warning)
            . ' <a href="' . esc_url(admin_url('admin.php?page=smbb-codetool-tomo_settings')) . '">' . esc_html__('Ouvrir les réglages', 'smbb-tomo') . '</a></p></div>';
    }

    /**
     * Appelé par la tâche planifiée (toutes les 15 minutes) : un seul e-mail par jour.
     *
     * @return bool vrai si un e-mail est parti
     */
    public static function run()
    {
        $today = current_time('Y-m-d');
        self::checkGoogle($today);
        $warning = self::warning();

        if ($warning === '' || get_option(self::OPTION_DAY) === $today) {
            return false;
        }

        update_option(self::OPTION_DAY, $today, false);

        $to = array_values(array_unique(array_filter(array(
            (string) get_option('admin_email'),
            (string) Settings::get('contact_email'),
        ), 'is_email')));

        if (!$to) {
            return false;
        }

        $d = 'smbb-tomo';
        $subject = self::googleFailed() && Tickets::appleExpiryWarning() === ''
            ? __('[Tomo] Clé Google Wallet refusée', $d)
            : (self::expired() ? __('[Tomo] Certificat Apple Wallet expiré', $d) : __('[Tomo] Certificat Apple Wallet à renouveler', $d));
        $body = $warning . "\n\n"
            . (self::googleFailed() ? __("Google Wallet : dans Google Cloud › IAM › Comptes de service, créez une nouvelle clé JSON pour le compte de service et collez-la dans Réglages › Billets.", $d) . "\n\n" : '')
            . (Tickets::appleExpiryWarning() === '' ? '' : __("Apple Wallet : pour le renouveler : developer.apple.com › Certificates, Identifiers & Profiles › Certificates › « + » › Pass Type ID Certificate, même identifiant, même fichier .csr. Convertissez le .cer reçu en .pem (openssl x509 -inform DER -in pass.cer -out pass.pem) et collez-le dans Réglages › Billets.", $d) . "\n\n")
            . admin_url('admin.php?page=smbb-codetool-tomo_settings') . "\n\n"
            . __('Ce rappel part chaque jour jusqu\'au renouvellement.', $d);

        return (bool) wp_mail($to, $subject, $body);
    }

    /**
     * Texte d'alerte : Apple (échéance) puis Google (clé refusée), ou chaîne vide.
     */
    public static function warning()
    {
        $parts = array_filter(array(Tickets::appleExpiryWarning(), self::googleWarning()));

        return implode(' ', $parts);
    }

    public static function googleWarning()
    {
        $last = get_option(self::OPTION_GOOGLE);

        if (!is_array($last) || $last['ok'] !== false || !Tickets::googleReady()) {
            return '';
        }

        return (string) $last['message'];
    }

    /**
     * Dernière vérification de la clé Google : array(at, ok, message) ou null.
     */
    public static function googleLast()
    {
        $last = get_option(self::OPTION_GOOGLE);

        return is_array($last) ? $last : null;
    }

    /**
     * Essaie la clé Google une fois par jour (ou tout de suite si la clé a changé).
     */
    public static function checkGoogle($today = '', $force = false)
    {
        if (!Tickets::googleReady()) {
            return;
        }

        $today = $today !== '' ? $today : current_time('Y-m-d');
        $fingerprint = md5((string) Settings::get('google_service_account'));
        $last = get_option(self::OPTION_GOOGLE);

        if (!$force && is_array($last) && $last['day'] === $today && $last['key'] === $fingerprint) {
            return;
        }

        $result = Tickets::googleKeyCheck();

        // Réseau indisponible : on garde le dernier verdict et on réessaiera au prochain passage.
        if ($result['ok'] === null) {
            return;
        }

        update_option(self::OPTION_GOOGLE, array(
            'day' => $today,
            'key' => $fingerprint,
            'at' => current_time('mysql'),
            'ok' => (bool) $result['ok'],
            'message' => $result['message'],
        ), false);
    }

    private static function googleFailed()
    {
        return self::googleWarning() !== '';
    }

    private static function expired()
    {
        foreach (Tickets::appleExpiry() as $until) {
            if ($until < time()) {
                return true;
            }
        }

        return false;
    }
}
