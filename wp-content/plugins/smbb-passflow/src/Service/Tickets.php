<?php

namespace Smbb\Passflow\Service;

use Smbb\Passflow\Repository\Slots;
use Smbb\Passflow\Repository\Bookings;
use Smbb\Passflow\Repository\Lookup;
use Smbb\Passflow\Support\Settings;

defined('ABSPATH') || exit;

/**
 * Billets des participants : PDF, QR code de pointage, Apple Wallet et Google Wallet.
 *
 * Chaque billet est signé (HMAC avec les clés WordPress) : le lien de téléchargement et le QR code
 * ne peuvent pas être devinés à partir d'un numéro de réservation.
 */
final class Tickets
{
    const DOWNLOAD = 'passflow_ticket';
    const CHECKIN = 'passflow_checkin';

    public static function hooks()
    {
        add_action('template_redirect', array(__CLASS__, 'download'));
        add_action('admin_post_' . self::CHECKIN, array(__CLASS__, 'checkin'));
        add_action('admin_post_nopriv_' . self::CHECKIN, array(__CLASS__, 'login'));
    }

    // ------------------------------------------------------------------
    // Liens
    // ------------------------------------------------------------------

    /**
     * Jeton du billet : ne dépend que de l'id, pour qu'une correction de l'e-mail ne rende pas
     * le PDF, le lien et le QR code déjà envoyés inutilisables.
     */
    public static function token(array $booking)
    {
        // Après un transfert (version > 0), le jeton change : l'ancien lien, le PDF et le QR code ne marchent plus.
        $rev = Transfers::rev($booking);

        return substr(hash_hmac('sha256', 'ticket|' . (int) $booking['id'] . ($rev ? '|r' . $rev : ''), wp_salt('auth')), 0, 24);
    }

    /**
     * Numéro de série Apple Wallet : nouveau à chaque transfert (l'ancien billet est barré à part).
     */
    public static function serial(array $booking)
    {
        $rev = Transfers::rev($booking);

        return 'passflow-' . (int) $booking['id'] . ($rev ? '-r' . $rev : '');
    }

    /**
     * Identifiant du billet chez Google Wallet : nouveau à chaque transfert.
     */
    public static function googleObjectId(array $booking)
    {
        $issuer = preg_replace('/[^0-9]/', '', (string) Settings::get('google_issuer_id'));
        $rev = Transfers::rev($booking);

        return $issuer . '.passflow-ticket-' . (int) $booking['id'] . ($rev ? '-r' . $rev : '');
    }

    /**
     * Ancien jeton (jusqu'à 0.13.0, calculé avec l'e-mail) : accepté pour les billets déjà envoyés,
     * tant que l'e-mail n'a pas changé.
     */
    private static function legacyToken(array $booking)
    {
        return substr(hash_hmac('sha256', 'ticket|' . (int) $booking['id'] . '|' . strtolower((string) $booking['email']), wp_salt('auth')), 0, 24);
    }

    public static function valid(array $booking, $token)
    {
        return is_string($token) && $token !== ''
            && (hash_equals(self::token($booking), $token) || (Transfers::rev($booking) === 0 && hash_equals(self::legacyToken($booking), $token)));
    }

    /**
     * Lien du billet ($format : page, pdf, apple, google, ics).
     * « page » : page personnelle du billet (PDF, Apple Wallet, Google Wallet, agenda), sans connexion.
     */
    public static function url(array $booking, $format = 'pdf')
    {
        $booking = Passes::root($booking); // pass : un seul billet pour tous les jours.

        return add_query_arg(array(
            self::DOWNLOAD => (int) $booking['id'],
            'k' => self::token($booking),
            'format' => $format,
        ), home_url('/'));
    }

    /**
     * Lien du QR code : ouvre l'écran de pointage pour l'équipe (connexion requise).
     */
    public static function checkinUrl(array $booking)
    {
        $booking = Passes::root($booking); // pass : le même QR chaque jour.

        return add_query_arg(array(
            'action' => self::CHECKIN,
            'id' => (int) $booking['id'],
            'k' => self::token($booking),
        ), admin_url('admin-post.php'));
    }

    /**
     * Un billet a du sens pour une réservation payée (ou déjà pointée) placée sur une date.
     * « À confirmer » (paiement par chèque ou virement pas encore reçu) : pas de billet.
     */
    public static function available(array $booking)
    {
        return !empty($booking['slot_id']) && in_array($booking['status'], array('confirmed', 'present'), true);
    }

    /**
     * Boutons Apple Wallet / Google Wallet (ceux qui sont configurés). Toujours affichés :
     * sur un appareil qui ne peut pas ouvrir le billet, un clic affiche une explication au lieu du téléchargement.
     */
    public static function walletButtons(array $booking)
    {
        $d = 'smbb-passflow';
        $icon = '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path fill="currentColor" d="M4 6.5A2.5 2.5 0 0 1 6.5 4h11A2.5 2.5 0 0 1 20 6.5V8H4zm0 3.5h16v7.5a2.5 2.5 0 0 1-2.5 2.5h-11A2.5 2.5 0 0 1 4 17.5zm11 3a1.5 1.5 0 1 0 0 3h3v-3z"/></svg>';
        $wallets = array(
            'apple' => array(self::appleReady(), __('Ajouter à Apple Wallet', $d), ''),
            'google' => array(self::googleReady(), __('Ajouter à Google Wallet', $d), ' target="_blank" rel="noopener"'),
        );
        $html = '';

        foreach ($wallets as $key => $wallet) {
            if (!$wallet[0]) {
                continue;
            }

            // Badge officiel (assets/wallet/{apple|google}-fr.svg) s'il est présent, affiché tel quel ; sinon bouton maison.
            $badge = self::walletBadge($key);
            $html .= '<a class="passflow-wallet passflow-wallet--' . $key . ($badge ? ' passflow-wallet--badge' : '') . '" data-passflow-wallet="' . $key . '" href="' . esc_url(self::url($booking, $key)) . '"' . $wallet[2] . '>'
                . ($badge ? '<img src="' . esc_url($badge) . '" alt="' . esc_attr($wallet[1]) . '">' : $icon . '<span>' . esc_html($wallet[1]) . '</span>')
                . '</a>';
        }

        if ($html === '') {
            return '';
        }

        return '<span class="passflow-wallets">' . $html . '</span>' . self::walletScript();
    }

    /**
     * URL du badge officiel déposé dans assets/wallet/, ou ''.
     */
    private static function walletBadge($key)
    {
        $file = 'assets/wallet/' . $key . '-fr.svg';

        return defined('SMBB_PASSFLOW_PATH') && is_readable(SMBB_PASSFLOW_PATH . $file) ? SMBB_PASSFLOW_URL . $file : '';
    }

    /**
     * Script partagé (une fois par page) : met en avant le bon Wallet et explique sur un appareil incompatible.
     */
    private static function walletScript()
    {
        static $done = false;

        if ($done) {
            return '';
        }

        $done = true;
        $texts = array(
            'apple' => __('Apple Wallet s\'ouvre sur iPhone, iPad ou Mac (Safari). Ouvrez cette page depuis votre appareil compatible.', 'smbb-passflow'),
            'google' => __('Google Wallet n\'existe pas sur iPhone. Ouvrez cette page depuis votre appareil Android, ou choisissez Apple Wallet.', 'smbb-passflow'),
        );

        return '<script>(function(){var t=' . wp_json_encode($texts) . ',ua=navigator.userAgent||"",'
            . 'ios=/iPhone|iPad|iPod/.test(ua)||(/Macintosh/.test(ua)&&navigator.maxTouchPoints>1),'
            . 'mac=/Macintosh/.test(ua)&&/Safari/.test(ua)&&!/Chrome|Chromium|Edg|Firefox/.test(ua),android=/Android/.test(ua),'
            . 'ok={apple:ios||mac,google:!ios};'
            . 'document.querySelectorAll("[data-passflow-wallet]").forEach(function(a){var k=a.getAttribute("data-passflow-wallet");'
            . 'if((k==="apple"&&(ios||mac))||(k==="google"&&android)){a.classList.add("is-suggested");}'
            . 'if(!ok[k]){a.classList.add("is-unavailable");a.addEventListener("click",function(e){e.preventDefault();alert(t[k]);});}});})();</script>';
    }

    public static function appleReady()
    {
        return Settings::get('apple_pass_type_id') !== '' && Settings::get('apple_team_id') !== ''
            && Settings::get('apple_certificate') !== '' && Settings::secret('apple_private_key') !== ''
            && Settings::get('apple_wwdr') !== '' && class_exists('\\ZipArchive') && function_exists('openssl_pkcs7_sign');
    }

    /**
     * Fin de validité (timestamp) des certificats Apple : celui du Pass Type ID (un an environ) et le WWDR.
     * Après cette date, les nouveaux billets ne s'ajoutent plus ; ceux déjà dans les téléphones restent.
     *
     * @return array<string,int> certificate|wwdr => timestamp
     */
    public static function appleExpiry()
    {
        $out = array();

        foreach (array('certificate' => 'apple_certificate', 'wwdr' => 'apple_wwdr') as $key => $setting) {
            $pem = (string) Settings::get($setting);
            $info = $pem !== '' && function_exists('openssl_x509_parse') ? openssl_x509_parse($pem) : false;

            if (is_array($info) && !empty($info['validTo_time_t'])) {
                $out[$key] = (int) $info['validTo_time_t'];
            }
        }

        return $out;
    }

    /**
     * Alerte quand un certificat Apple expire dans moins de 30 jours (ou a expiré).
     */
    public static function appleExpiryWarning()
    {
        $d = 'smbb-passflow';

        foreach (self::appleExpiry() as $key => $until) {
            if ($until - time() < 30 * DAY_IN_SECONDS) {
                $what = $key === 'wwdr' ? __('Le certificat Apple WWDR', $d) : __('Le certificat Apple Wallet', $d);

                return $until < time()
                    ? sprintf(__('%1$s a expiré le %2$s : les billets Apple Wallet ne peuvent plus être ajoutés. Renouvelez-le (Réglages › Billets).', $d), $what, date_i18n('j F Y', $until))
                    : sprintf(__('%1$s expire le %2$s : renouvelez-le avant (Réglages › Billets).', $d), $what, date_i18n('j F Y', $until));
            }
        }

        return '';
    }

    public static function googleReady()
    {
        $account = json_decode(Settings::secret('google_service_account'), true);

        return Settings::get('google_issuer_id') !== '' && is_array($account) && !empty($account['client_email']) && !empty($account['private_key']);
    }

    // ------------------------------------------------------------------
    // Téléchargement (?passflow_ticket=ID&k=…&format=pdf|apple|google)
    // ------------------------------------------------------------------

    public static function download()
    {
        if (empty($_GET[self::DOWNLOAD])) {
            return;
        }

        $booking = Bookings::find(absint($_GET[self::DOWNLOAD]));
        $token = isset($_GET['k']) ? sanitize_text_field(wp_unslash($_GET['k'])) : '';

        $format = isset($_GET['format']) ? sanitize_key(wp_unslash($_GET['format'])) : 'pdf';
        // L'agenda reste disponible tant que la réservation a une date (même pointée absente ou présente).
        $usable = $booking && ($format === 'ics' ? !empty($booking['slot_id']) && $booking['status'] !== 'cancelled' : self::available($booking));

        // Ancien lien d'un billet transféré depuis : page qui l'explique.
        if ($booking && !self::valid($booking, $token)) {
            $previous = Transfers::fromToken(Passes::root($booking), $token);

            if ($previous) {
                Transfers::transferredPage($previous);
            }
        }

        if (!$booking || !self::valid($booking, $token) || !$usable) {
            wp_die(esc_html__("Ce billet n'est pas disponible : la réservation a peut-être été annulée ou déplacée sans date.", 'smbb-passflow'), '', array('response' => 404));
        }

        if ($format === 'page') {
            self::sendPage($booking);
        }

        if ($format === 'apple') {
            self::sendApple($booking);
        }

        if ($format === 'ics') {
            self::sendIcs($booking);
        }

        if ($format === 'google') {
            $url = self::googleSaveUrl($booking);

            if ($url === '') {
                $error = self::googleError();
                wp_die(esc_html($error !== '' && current_user_can('manage_options') ? $error : ($error !== '' ? __("Le billet n'a pas pu être ajouté à Google Wallet pour le moment. Réessayez plus tard ou utilisez le billet PDF.", 'smbb-passflow') : __('Google Wallet n\'est pas encore configuré.', 'smbb-passflow'))));
            }

            wp_redirect($url); // phpcs:ignore -- domaine Google, pas un domaine du site.
            exit;
        }

        self::sendPdf($booking);
    }

    // ------------------------------------------------------------------
    // Agenda (.ics) : ajout au calendrier du téléphone, de Google Agenda ou d'Outlook
    // ------------------------------------------------------------------

    /**
     * Fichier iCalendar de la réservation, avec un rappel la veille et l'adresse.
     */
    public static function ics(array $booking)
    {
        $booking = Passes::root($booking);
        $slot = !empty($booking['slot_id']) ? Slots::find($booking['slot_id']) : null;

        if (!$slot) {
            return '';
        }

        // Pass : un événement par jour inclus encore actif.
        $sessions = array($slot);

        if (Passes::isPass($slot)) {
            $sessions = array();

            foreach (Passes::children($booking['id']) as $child) {
                $day = Slots::find($child['slot_id']);

                if ($day) {
                    $sessions[] = $day;
                }
            }

            if (!$sessions) {
                return '';
            }
        }

        $data = self::data($booking);
        $tz = wp_timezone();
        $utc = new \DateTimeZone('UTC');
        $escape = function ($text) {
            return str_replace(array('\\', ';', ',', "\r\n", "\n"), array('\\\\', '\\;', '\\,', '\\n', '\\n'), (string) $text);
        };
        $where = trim($data['location'] . ($data['address'] !== '' ? ', ' . $data['address'] : ''), ', ');
        $description = sprintf(__('Réservation %1$s au nom de %2$s.', 'smbb-passflow'), $data['reference'], $data['name'])
            . "\n" . sprintf(__('Votre billet : %s', 'smbb-passflow'), self::url($booking, 'pdf'));
        $host = wp_parse_url(home_url('/'), PHP_URL_HOST) ?: 'passflow';

        $lines = array(
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//SMBB//smbb-passflow//FR',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
        );

        foreach ($sessions as $session) {
            try {
                $start = new \DateTimeImmutable($session['start_date'] . ' ' . ($session['start_time'] ?: '09:00:00'), $tz);
                $end = !empty($session['end_time']) && $session['end_time'] > $session['start_time']
                    ? new \DateTimeImmutable($session['start_date'] . ' ' . $session['end_time'], $tz)
                    : $start->modify('+2 hours');
            } catch (\Exception $e) {
                continue;
            }

            $title = Lookup::atelier($session['activity_id']);
            $title = $title ? (string) $title['name'] : $data['atelier'];
            $lines = array_merge($lines, array(
                'BEGIN:VEVENT',
                'UID:passflow-' . (int) $booking['id'] . '-' . (int) $session['id'] . '@' . $host,
                'DTSTAMP:' . gmdate('Ymd\THis\Z'),
                Lookup::allDay($session) ? 'DTSTART;VALUE=DATE:' . $start->format('Ymd') : 'DTSTART:' . $start->setTimezone($utc)->format('Ymd\THis\Z'),
                Lookup::allDay($session) ? 'DTEND;VALUE=DATE:' . $start->modify('+1 day')->format('Ymd') : 'DTEND:' . $end->setTimezone($utc)->format('Ymd\THis\Z'),
                'SUMMARY:' . $escape($title . ' · ' . $data['shop']),
                'DESCRIPTION:' . $escape($description),
                'LOCATION:' . $escape($where),
                'URL:' . self::url($booking, 'pdf'),
                'STATUS:CONFIRMED',
                'BEGIN:VALARM',
                'TRIGGER:-PT24H',
                'ACTION:DISPLAY',
                'DESCRIPTION:' . $escape($title),
                'END:VALARM',
                'END:VEVENT',
            ));
        }

        $lines[] = 'END:VCALENDAR';

        // Lignes de 75 octets au plus (RFC 5545), suite précédée d'un espace.
        $folded = array();

        foreach ($lines as $line) {
            while (strlen($line) > 75) {
                $cut = 75;

                while ($cut > 1 && (ord($line[$cut]) & 0xC0) === 0x80) {
                    $cut--; // ne pas couper un caractère UTF-8 en deux.
                }

                $folded[] = substr($line, 0, $cut);
                $line = ' ' . substr($line, $cut);
            }

            $folded[] = $line;
        }

        return implode("\r\n", $folded) . "\r\n";
    }

    private static function sendIcs(array $booking)
    {
        $ics = self::ics($booking);

        if ($ics === '') {
            wp_die(esc_html__("Cette réservation n'a pas encore de date.", 'smbb-passflow'), '', array('response' => 404));
        }

        nocache_headers();
        header('Content-Type: text/calendar; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . sanitize_file_name(sprintf('passflow-%05d.ics', (int) $booking['id'])) . '"');
        echo $ics; // phpcs:ignore -- texte iCalendar construit et échappé ci-dessus.
        exit;
    }

    // ------------------------------------------------------------------
    // PDF
    // ------------------------------------------------------------------

    /**
     * @return array<string,string> textes communs au PDF et aux wallets
     */
    public static function data(array $booking)
    {
        $slot = Slots::find($booking['slot_id']);
        $activity = $slot ? Lookup::atelier($slot['activity_id']) : null;
        $start = $slot ? strtotime($slot['start_date'] . ' ' . ($slot['start_time'] ?: '00:00:00')) : 0;

        return array(
            'shop' => (string) Settings::get('sender_name') ?: get_bloginfo('name'),
            'name' => Lookup::personName($booking),
            'atelier' => $activity ? (string) $activity['name'] : '',
            'date' => $slot ? (Passes::isPass($slot) ? ucfirst(Passes::daysLabel($slot)) : ucfirst(date_i18n('l j F Y', strtotime($slot['start_date'] . ' 12:00:00')))) : '',
            'hours' => $slot ? (Passes::isPass($slot) ? sprintf(__('Pass %d jours', 'smbb-passflow'), count(Passes::dayIds($slot))) : Lookup::slotHours($slot)) : '',
            'start' => $start ? gmdate('Y-m-d\TH:i:s', $start) : '',
            'location' => $activity ? (string) $activity['location'] : '',
            'address' => $activity ? trim(preg_replace('/\s*\n\s*/', ', ', (string) $activity['address'])) : '',
            'reference' => (Settings::companyCode() !== '' ? Settings::companyCode() . '-' : '') . sprintf('T-%05d', (int) $booking['id']),
            'color' => self::color($activity),
            'logo' => (int) Settings::get('ticket_logo_id'),
            'checkin' => self::checkinUrl($booking),
            'end' => $slot && !Passes::isPass($slot) && !Lookup::allDay($slot) && !empty($slot['end_time']) ? gmdate('Y-m-d\TH:i:s', strtotime($slot['start_date'] . ' ' . $slot['end_time'])) : '',
            'staff' => $slot ? self::staffNames($slot) : '',
            'duration' => $slot ? self::duration($slot, $activity) : '',
            'practical' => $activity && !empty($activity['practical_info']) ? trim((string) $activity['practical_info']) : '',
            'image' => self::imageId($activity),
            'geo' => null, // calculé à la demande (Wallet) : self::geo()
            'activity_row' => $activity,
        );
    }

    // ------------------------------------------------------------------
    // Contenu enrichi des billets Wallet
    // ------------------------------------------------------------------

    /**
     * Intervenants du créneau (pour un pass : ceux de ses jours).
     */
    private static function staffNames(array $slot)
    {
        $ids = Lookup::ids($slot['host_ids']);

        if (!$ids && Passes::isPass($slot)) {
            foreach (Passes::days($slot) as $day) {
                $ids = array_merge($ids, Lookup::ids($day['host_ids']));
            }
        }

        return Lookup::hostNames(array_values(array_unique($ids)));
    }

    /**
     * Durée lisible : « 3 h », « 1 h 30 », « 45 min » (rien pour un pass ou une journée entière).
     *
     * @param array<string,mixed>|null $activity
     */
    private static function duration(array $slot, $activity)
    {
        if (Passes::isPass($slot) || Lookup::allDay($slot)) {
            return '';
        }

        $minutes = 0;

        if (!empty($slot['start_time']) && !empty($slot['end_time'])) {
            $minutes = (int) round((strtotime('2000-01-01 ' . $slot['end_time']) - strtotime('2000-01-01 ' . $slot['start_time'])) / 60);
        }

        if ($minutes <= 0 && $activity && !empty($activity['duration_minutes'])) {
            $minutes = (int) $activity['duration_minutes'];
        }

        if ($minutes <= 0) {
            return '';
        }

        if ($minutes < 60) {
            return sprintf(__('%d min', 'smbb-passflow'), $minutes);
        }

        return $minutes % 60 ? sprintf(__('%1$d h %2$02d', 'smbb-passflow'), intdiv($minutes, 60), $minutes % 60) : sprintf(__('%d h', 'smbb-passflow'), $minutes / 60);
    }

    /**
     * Photo de l'atelier : celle du produit WooCommerce lié, sinon celle de l'atelier.
     *
     * @param array<string,mixed>|null $activity
     */
    private static function imageId($activity)
    {
        if (!$activity) {
            return 0;
        }

        if (class_exists('\\Smbb\\Passflow\\WooCommerce\\Products')) {
            $product = \Smbb\Passflow\WooCommerce\Products::linked($activity);

            if ($product && $product->get_image_id()) {
                return (int) $product->get_image_id();
            }
        }

        return !empty($activity['image_id']) ? (int) $activity['image_id'] : 0;
    }

    /**
     * Coordonnées du lieu : saisies sur l'atelier, sinon trouvées depuis l'adresse (OpenStreetMap, gardées 30 jours).
     *
     * @param array<string,mixed>|null $activity
     * @return array{0:float,1:float}|null
     */
    public static function geo($activity)
    {
        if (!$activity) {
            return null;
        }

        $lat = isset($activity['latitude']) ? str_replace(',', '.', trim((string) $activity['latitude'])) : '';
        $lng = isset($activity['longitude']) ? str_replace(',', '.', trim((string) $activity['longitude'])) : '';

        if (is_numeric($lat) && is_numeric($lng)) {
            return array((float) $lat, (float) $lng);
        }

        $address = trim(preg_replace('/\s+/', ' ', (string) $activity['address']));

        if ($address === '') {
            return null;
        }

        $key = 'smbb_passflow_geo_' . md5($address);
        $cached = get_transient($key);

        if (is_array($cached)) {
            return $cached ? array((float) $cached[0], (float) $cached[1]) : null;
        }

        $response = wp_remote_get('https://nominatim.openstreetmap.org/search?format=json&limit=1&q=' . rawurlencode($address), array(
            'timeout' => 5,
            'headers' => array('User-Agent' => 'smbb-passflow/' . SMBB_PASSFLOW_VERSION . ' (' . home_url('/') . ')', 'Accept-Language' => 'fr'),
        ));
        $found = is_wp_error($response) ? null : json_decode((string) wp_remote_retrieve_body($response), true);

        if (is_array($found) && isset($found[0]['lat'], $found[0]['lon'])) {
            $point = array((float) $found[0]['lat'], (float) $found[0]['lon']);
            set_transient($key, $point, 30 * DAY_IN_SECONDS);

            return $point;
        }

        set_transient($key, array(), is_wp_error($response) ? HOUR_IN_SECONDS : DAY_IN_SECONDS);

        return null;
    }

    /**
     * Liens utiles au dos du billet.
     *
     * @param array<string,mixed> $t données de self::data()
     * @return array<int,array{id:string,label:string,text:string,url:string}>
     */
    private static function links(array $booking, array $t, $wallet)
    {
        $d = 'smbb-passflow';
        $links = array();

        if (function_exists('wc_get_account_endpoint_url') && class_exists('\\Smbb\\Passflow\\WooCommerce\\Account')) {
            $links[] = array('id' => 'gerer', 'label' => __('Ma réservation', $d), 'text' => __('Gérer ou déplacer ma réservation', $d), 'url' => (string) wc_get_account_endpoint_url(\Smbb\Passflow\WooCommerce\Account::ENDPOINT));
        }

        $place = $t['address'] !== '' ? trim($t['location'] . ' ' . $t['address']) : ($t['geo'] ? $t['geo'][0] . ',' . $t['geo'][1] : '');

        if ($place !== '') {
            $links[] = array('id' => 'itineraire', 'label' => __('Itinéraire', $d), 'text' => __('Ouvrir dans Plans', $d), 'url' => $wallet === 'apple'
                ? 'https://maps.apple.com/?q=' . rawurlencode($t['location'] !== '' ? $t['location'] : $t['address']) . ($t['geo'] ? '&ll=' . $t['geo'][0] . ',' . $t['geo'][1] : '&address=' . rawurlencode($t['address']))
                : 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode($place));
        }

        if ($booking['status'] === 'confirmed' && $t['start'] !== '') {
            $links[] = array('id' => 'agenda', 'label' => __('Agenda', $d), 'text' => __('Ajouter à mon agenda', $d), 'url' => self::url($booking, 'ics'));
        }

        $phone = trim((string) Settings::get('contact_phone'));

        if ($phone !== '') {
            $links[] = array('id' => 'telephone', 'label' => __('Téléphone', $d), 'text' => $phone, 'url' => 'tel:' . preg_replace('/[^0-9+]/', '', $phone));
        }

        $email = trim((string) Settings::get('contact_email')) ?: trim((string) Settings::get('sender_email'));

        if ($email !== '' && is_email($email)) {
            $links[] = array('id' => 'email', 'label' => __('E-mail', $d), 'text' => $email, 'url' => 'mailto:' . $email);
        }

        return $links;
    }

    /**
     * Jours d'un pass avec leur état : « Lun 3 mars, 9h00-17h00 : Présent ».
     *
     * @return string[]
     */
    public static function passDays(array $booking)
    {
        $slot = empty($booking['slot_id']) ? null : Slots::find($booking['slot_id']);

        if (!$slot || !Passes::isPass($slot)) {
            return array();
        }

        $d = 'smbb-passflow';
        $labels = array('present' => __('Présent', $d), 'absent' => __('Absent', $d), 'cancelled' => __('Annulé', $d));
        $lines = array();

        foreach (Passes::children((int) $booking['id'], true) as $child) {
            $day = Slots::find($child['slot_id']);

            if (!$day) {
                continue;
            }

            $hours = Lookup::slotHours($day);
            $lines[] = ucfirst(date_i18n('D j M', strtotime($day['start_date'] . ' 12:00:00'))) . ($hours !== '' ? ', ' . $hours : '')
                . ' : ' . (isset($labels[$child['status']]) ? $labels[$child['status']] : __('À venir', $d));
        }

        return $lines;
    }

    /**
     * Message de la veille (« C'est demain à 14h00, à bientôt ! ») pendant la fenêtre du rappel participant,
     * sinon ''. Pour un pass : le prochain jour.
     */
    public static function eveText(array $booking)
    {
        $d = 'smbb-passflow';
        $slot = empty($booking['slot_id']) ? null : Slots::find($booking['slot_id']);

        if (!$slot || in_array($booking['status'], array('present', 'absent', 'cancelled'), true)) {
            return '';
        }

        $days = array($slot);

        if (Passes::isPass($slot)) {
            $days = array();

            foreach (Passes::children((int) $booking['id']) as $child) {
                if ($child['status'] === 'confirmed' && ($day = Slots::find($child['slot_id']))) {
                    $days[] = $day;
                }
            }
        }

        $now = current_time('timestamp');
        $window = max(1, Settings::int('reminder_participant_hours')) * HOUR_IN_SECONDS;

        foreach ($days as $day) {
            $start = strtotime($day['start_date'] . ' ' . (Lookup::allDay($day) || empty($day['start_time']) ? '00:00:00' : $day['start_time']));

            if ($start <= $now - 12 * HOUR_IN_SECONDS || $start > $now + $window) {
                continue;
            }

            $hour = Lookup::slotHours($day, false);
            $today = $day['start_date'] === date('Y-m-d', $now);
            $tomorrow = $day['start_date'] === date('Y-m-d', $now + DAY_IN_SECONDS);

            if ($today) {
                return $hour !== '' ? sprintf(__("C'est aujourd'hui à %s, à tout à l'heure !", $d), $hour) : __("C'est aujourd'hui, à tout à l'heure !", $d);
            }

            if ($tomorrow) {
                return $hour !== '' ? sprintf(__("C'est demain à %s, à bientôt !", $d), $hour) : __("C'est demain, à bientôt !", $d);
            }

            $date = date_i18n('l j F', strtotime($day['start_date'] . ' 12:00:00'));

            return $hour !== '' ? sprintf(__("C'est %1\$s à %2\$s, à bientôt !", $d), $date, $hour) : sprintf(__("C'est %s, à bientôt !", $d), $date);
        }

        return '';
    }

    public static function sendPdf(array $booking)
    {
        $content = self::pdfContent($booking);

        if ($content === '') {
            wp_die(esc_html__('PDF indisponible : la bibliothèque TCPDF (fournie par SMBB WP CodeTool) est absente.', 'smbb-passflow'));
        }

        nocache_headers();
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . self::pdfName($booking) . '"');
        header('Content-Length: ' . strlen($content));
        echo $content; // phpcs:ignore -- fichier PDF binaire.
        exit;
    }

    /**
     * Nom du PDF : prénom et nom du participant, pour que l'acheteur s'y retrouve en les distribuant.
     */
    public static function pdfName(array $booking)
    {
        $booking = Passes::root($booking);
        $t = self::data($booking);
        $name = sanitize_title(remove_accents($t['name']));

        return sanitize_title(__('billet', 'smbb-passflow')) . '-' . ($name !== '' ? $name . '-' : '') . strtolower($t['reference']) . '.pdf';
    }

    /**
     * Écrit le PDF dans un dossier temporaire (pièce jointe d'un e-mail). À supprimer après l'envoi (deleteFile).
     *
     * @return string chemin du fichier, ou '' si le PDF n'a pas pu être produit
     */
    public static function pdfFile(array $booking)
    {
        $booking = Passes::root($booking);
        $content = self::pdfContent($booking);

        if ($content === '') {
            return '';
        }

        $dir = trailingslashit(get_temp_dir()) . 'passflow-' . wp_generate_password(16, false, false);

        if (!wp_mkdir_p($dir)) {
            return '';
        }

        $path = $dir . '/' . self::pdfName($booking);

        return file_put_contents($path, $content) !== false ? $path : '';
    }

    /**
     * Supprime un fichier créé par pdfFile() et son dossier.
     */
    public static function deleteFile($path)
    {
        if ($path !== '' && is_file($path) && strpos(basename(dirname($path)), 'passflow-') === 0) {
            @unlink($path); // phpcs:ignore
            @rmdir(dirname($path)); // phpcs:ignore
        }
    }

    /**
     * Contenu binaire du billet PDF, ou '' sans TCPDF.
     */
    public static function pdfContent(array $booking)
    {
        if (!class_exists('\\TCPDF')) {
            return '';
        }

        $d = 'smbb-passflow';
        $t = self::data($booking);
        $rgb = self::rgb($t['color']);

        $pdf = new \TCPDF('P', 'mm', 'A6', true, 'UTF-8', false);
        $pdf->SetCreator($t['shop']);
        $pdf->SetTitle(sprintf(__('Billet %s', $d), $t['reference']));
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(9, 9, 9);
        $pdf->SetAutoPageBreak(false);
        $pdf->AddPage();

        // Bandeau.
        $text = self::textColor($t['color']);
        $pdf->SetFillColor($rgb[0], $rgb[1], $rgb[2]);
        $pdf->Rect(0, 0, 105, 26, 'F');
        $pdf->SetTextColor($text[0], $text[1], $text[2]);
        $logo = $t['logo'] ? self::logoFile($t['logo'], 600, 200) : '';
        $x = 9;

        // Logo à gauche du bandeau (le nom de l'expéditeur prend sa place s'il n'y en a pas).
        if ($logo !== '') {
            $pdf->Image($logo, 9, 5, 30, 16, 'PNG', '', '', false, 300, '', false, false, 0, 'LM');
            @unlink($logo); // phpcs:ignore
            $x = 42;
        } else {
            $pdf->SetFont('dejavusans', '', 9);
            $pdf->SetXY(9, 6);
            $pdf->Cell(87, 5, $t['shop'], 0, 1);
        }

        $pdf->SetFont('dejavusans', 'B', $logo !== '' ? 13 : 15);
        $pdf->SetXY($x, $logo !== '' ? 9 : 12);
        $pdf->MultiCell(96 - $x, 7, $t['atelier'], 0, 'L', false, 1, '', '', true, 0, false, true, 14, 'M');

        $pdf->SetTextColor(50, 40, 35);
        $pdf->SetFont('dejavusans', '', 8);
        $pdf->SetXY(9, 32);
        $pdf->Cell(87, 4, mb_strtoupper(__('Participant', $d)), 0, 1);
        $pdf->SetFont('dejavusans', 'B', 14);
        $pdf->SetX(9);
        $pdf->MultiCell(87, 7, $t['name'], 0, 'L');

        $pdf->SetFont('dejavusans', '', 8);
        $pdf->SetXY(9, $pdf->GetY() + 3);
        $pdf->Cell(87, 4, mb_strtoupper(__('Date', $d)), 0, 1);
        $pdf->SetFont('dejavusans', 'B', 11);
        $pdf->SetX(9);
        $pdf->MultiCell(87, 5.5, $t['date'] . ($t['hours'] !== '' ? "\n" . $t['hours'] : ''), 0, 'L');

        if ($t['location'] !== '' || $t['address'] !== '') {
            $pdf->SetFont('dejavusans', '', 8);
            $pdf->SetXY(9, $pdf->GetY() + 3);
            $pdf->Cell(87, 4, mb_strtoupper(__('Lieu', $d)), 0, 1);
            $pdf->SetFont('dejavusans', '', 10);
            $pdf->SetX(9);
            $pdf->MultiCell(87, 5, trim($t['location'] . "\n" . $t['address']), 0, 'L');
        }

        // QR code de pointage.
        $pdf->write2DBarcode($t['checkin'], 'QRCODE,M', 34.5, 92, 36, 36, array(
            'border' => false,
            'padding' => 0,
            'fgcolor' => array(50, 40, 35),
            'bgcolor' => false,
        ), 'N');
        $pdf->SetFont('dejavusansmono', 'B', 10);
        $pdf->SetXY(9, 130);
        $pdf->Cell(87, 5, $t['reference'], 0, 1, 'C');
        $pdf->SetFont('dejavusans', '', 7);
        $pdf->SetX(9);
        $pdf->Cell(87, 4, __("À présenter à l'accueil, sur téléphone ou imprimé.", $d), 0, 1, 'C');

        return (string) $pdf->Output('', 'S');
    }

    // ------------------------------------------------------------------
    // Page personnelle du billet (lien des e-mails et SMS)
    // ------------------------------------------------------------------

    /**
     * Page simple, sans le thème du site : le billet, son QR code, le PDF, les Wallets et l'agenda.
     * Accessible avec le lien signé, sans connexion : le participant n'a pas forcément de compte.
     */
    private static function sendPage(array $booking)
    {
        $d = 'smbb-passflow';
        $booking = Passes::root($booking);
        $t = self::data($booking);
        $color = $t['color'];
        $on_color = 'rgb(' . implode(',', array_map('intval', self::textColor($color))) . ')';
        $logo = $t['logo'] ? wp_get_attachment_image_url($t['logo'], 'medium') : '';
        $qr = self::qrSvg($t['checkin']);
        $wallets = self::walletButtons($booking);
        $rows = array(
            __('Participant', $d) => $t['name'],
            __('Date', $d) => trim($t['date'] . ($t['hours'] !== '' ? ' · ' . $t['hours'] : '')),
            __('Lieu', $d) => trim($t['location'] . ($t['location'] !== '' && $t['address'] !== '' ? ', ' : '') . $t['address']),
        );

        nocache_headers();
        header('Content-Type: text/html; charset=UTF-8');
        header('X-Robots-Tag: noindex, nofollow');

        echo '<!doctype html><html lang="' . esc_attr(substr((string) get_bloginfo('language'), 0, 2) ?: 'fr') . '"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow">'
            . '<title>' . esc_html(sprintf(__('Billet %s', $d), $t['reference'])) . '</title>'
            . '<style>'
            . ':root{--c:' . esc_attr($color) . ';--on:' . esc_attr($on_color) . ';}'
            . '*{box-sizing:border-box}body{margin:0;background:#f4f1ee;color:#2b211c;font:16px/1.5 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;}'
            . '.pf-t{max-width:420px;margin:24px auto;padding:0 16px}'
            . '.pf-card{background:#fff;border-radius:18px;overflow:hidden;box-shadow:0 10px 30px rgba(40,25,15,.12)}'
            . '.pf-head{background:var(--c);color:var(--on);padding:18px 22px}.pf-head img{display:block;max-height:44px;max-width:160px;margin-bottom:10px}'
            . '.pf-shop{font-size:13px;opacity:.85}.pf-title{margin:2px 0 0;font-size:22px;line-height:1.25}'
            . '.pf-body{padding:18px 22px}dl{margin:0}.pf-row{margin:0 0 12px}.pf-row dt{font-size:11px;letter-spacing:.06em;text-transform:uppercase;color:#8a7d75}.pf-row dd{margin:0;font-weight:600}'
            . '.pf-qr{width:200px;height:200px;margin:8px auto 4px}.pf-qr svg{display:block;width:100%;height:100%}'
            . '.pf-ref{text-align:center;font:600 15px ui-monospace,Menlo,Consolas,monospace}.pf-hint{text-align:center;font-size:12px;color:#8a7d75;margin:2px 0 0}'
            . '.pf-actions{display:flex;flex-direction:column;gap:10px;margin-top:18px}'
            . '.pf-btn{display:block;text-align:center;padding:12px 16px;border-radius:12px;text-decoration:none;font-weight:600;background:var(--c);color:var(--on)}'
            . '.pf-btn--ghost{background:#fff;color:#2b211c;border:1px solid #ddd3cc}'
            . '.passflow-wallets{display:flex;flex-direction:column;align-items:center;gap:10px}'
            . '.passflow-wallet{display:inline-flex;align-items:center;justify-content:center;gap:8px;min-width:220px;padding:10px 16px;border-radius:12px;background:#000;color:#fff;font-weight:600;text-decoration:none}'
            . '.passflow-wallet svg{width:20px;height:20px}.passflow-wallet--badge{background:none;padding:0;min-width:0}.passflow-wallet--badge img{display:block;height:48px;width:auto}'
            . '.passflow-wallet.is-unavailable{opacity:.45}'
            . '.pf-transfer{margin-top:20px;border-top:1px solid #eee5df;padding-top:14px}.pf-transfer summary{cursor:pointer;font-weight:600;color:#5a4d45;text-align:center;list-style:none}'
            . '.pf-transfer summary::-webkit-details-marker{display:none}.pf-transfer summary::after{content:" \\25BE"}.pf-transfer[open] summary::after{content:" \\25B4"}'
            . '.pf-transfer form{display:flex;flex-direction:column;gap:10px;margin-top:10px}.pf-small{font-size:13px;color:#8a7d75;margin:10px 0 0}'
            . '.pf-two{display:flex;flex-direction:column;gap:10px}.pf-field{display:flex;flex-direction:column;font-size:13px;color:#5a4d45;gap:4px;min-width:0;width:100%}'
            . '.pf-field input{display:block;box-sizing:border-box;width:100%;max-width:100%;min-width:0;font:inherit;font-size:16px;padding:10px 12px;border:1px solid #ddd3cc;border-radius:10px}'
            . '.pf-card,.pf-transfer,.pf-transfer form{max-width:100%;min-width:0;overflow-wrap:anywhere}'
            . '.pf-check{font-size:13px;color:#5a4d45;display:flex;gap:8px;align-items:flex-start}.pf-check input{margin-top:3px}'
            . '.pf-transfer .pf-btn{border:0;font:inherit;font-weight:600;cursor:pointer;width:100%}'
            . '.pf-phone{margin:18px 0 0;padding:14px;border-radius:12px;background:#f7f3ef}.pf-phone h2{font-size:16px;margin:0}.pf-phone form{display:flex;flex-direction:column;gap:10px;margin-top:10px}'
            . '.pf-phone .pf-btn{border:0;font:inherit;font-weight:600;cursor:pointer;width:100%}.pf-phone .pf-btn--ghost{border:1px solid #ddd3cc}'
            . '.pf-link{background:none;border:0;padding:0;font:inherit;font-size:14px;color:#5a4d45;text-decoration:underline;cursor:pointer}.pf-inline{align-items:center}'
            . '.pf-okline{background:#e8f4ec;color:#1f5f35;border-radius:10px;padding:10px 12px;font-size:14px;margin:10px 0 0}'
            . '.pf-error{background:#fbeaea;color:#8a1f1f;border-radius:10px;padding:10px 12px;font-size:14px;margin:10px 0 0}'
            . '</style></head><body><main class="pf-t"><div class="pf-card">'
            . '<div class="pf-head">' . ($logo ? '<img src="' . esc_url($logo) . '" alt="">' : '<div class="pf-shop">' . esc_html($t['shop']) . '</div>')
            . '<h1 class="pf-title">' . esc_html($t['atelier']) . '</h1></div><div class="pf-body"><dl>';

        foreach ($rows as $label => $value) {
            if ($value !== '') {
                echo '<div class="pf-row"><dt>' . esc_html($label) . '</dt><dd>' . esc_html($value) . '</dd></div>';
            }
        }

        echo '</dl>'
            . ($qr !== '' ? '<div class="pf-qr">' . $qr . '</div>' : '') // SVG produit par TCPDF.
            . '<div class="pf-ref">' . esc_html($t['reference']) . '</div>'
            . '<p class="pf-hint">' . esc_html__("À présenter à l'accueil, sur téléphone ou imprimé.", $d) . '</p>'
            . Transfers::phoneForm($booking)
            . '<div class="pf-actions">'
            . $wallets
            . '<a class="pf-btn" href="' . esc_url(self::url($booking, 'pdf')) . '">' . esc_html__('Télécharger le billet (PDF)', $d) . '</a>'
            . '<a class="pf-btn pf-btn--ghost" href="' . esc_url(self::url($booking, 'ics')) . '">' . esc_html__('Ajouter à mon agenda', $d) . '</a>'
            . '</div>'
            . Transfers::form($booking, Transfers::error($booking))
            . '</div></div></main></body></html>';
        exit;
    }

    /**
     * QR code de pointage en SVG (classe 2D de TCPDF), ou ''.
     */
    private static function qrSvg($data)
    {
        if (!class_exists('\\TCPDF2DBarcode') && class_exists('\\TCPDF')) {
            $file = dirname((new \ReflectionClass('\\TCPDF'))->getFileName()) . '/tcpdf_barcodes_2d.php';

            if (is_readable($file)) {
                require_once $file;
            }
        }

        if (!class_exists('\\TCPDF2DBarcode')) {
            return '';
        }

        $barcode = new \TCPDF2DBarcode((string) $data, 'QRCODE,M');
        $grid = $barcode->getBarcodeArray();

        if (empty($grid['num_cols'])) {
            return '';
        }

        $svg = (string) $barcode->getBarcodeSVGcode(4, 4, '#322823');
        $svg = preg_replace('/^.*?(<svg)/s', '$1', $svg); // sans l'en-tête XML ni le DOCTYPE
        $box = ' viewBox="0 0 ' . ((int) $grid['num_cols'] * 4) . ' ' . ((int) $grid['num_rows'] * 4) . '"';

        return preg_replace('/<svg\b/', '<svg' . $box, $svg, 1);
    }

    /**
     * Boutons Apple Wallet / Google Wallet pour un e-mail : liens simples, mis en forme en ligne
     * (les messageries ignorent le SVG et les feuilles de style). '' si aucun Wallet n'est configuré.
     */
    public static function emailButtons(array $booking)
    {
        $d = 'smbb-passflow';
        $booking = Passes::root($booking);
        $buttons = array();

        if (self::appleReady()) {
            $buttons[] = array(self::url($booking, 'apple'), __('Ajouter à Apple Wallet', $d));
        }

        if (self::googleReady()) {
            $buttons[] = array(self::url($booking, 'google'), __('Ajouter à Google Wallet', $d));
        }

        if (!$buttons) {
            return '';
        }

        $cells = '';

        foreach ($buttons as $key => $button) {
            $badge = self::emailBadge($key === 0 && self::appleReady() ? 'apple' : 'google');

            // Badge officiel en PNG, intégré au message (cid:) : s'affiche sans aller chercher l'image sur le site.
            if ($badge) {
                $cells .= '<td style="padding:4px 8px 4px 0;">'
                    . '<a href="' . esc_url($button[0]) . '" style="display:inline-block;text-decoration:none;border:0;">'
                    . '<img src="cid:' . esc_attr($badge['cid']) . '" alt="' . esc_attr($button[1]) . '" width="' . (int) $badge['width'] . '" height="44" style="display:block;border:0;height:44px;width:' . (int) $badge['width'] . 'px;">'
                    . '</a></td>';
                continue;
            }

            $cells .= '<td style="padding:4px 8px 4px 0;">'
                . '<a href="' . esc_url($button[0]) . '" style="display:inline-block;background:#000000;color:#ffffff;text-decoration:none;font-weight:600;font-size:14px;line-height:20px;padding:10px 16px;border-radius:10px;white-space:nowrap;">'
                . esc_html($button[1]) . '</a></td>';
        }

        return '<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:6px 0 10px;"><tr>' . $cells . '</tr></table>';
    }

    /**
     * Badge PNG des e-mails (assets/wallet/{apple|google}-fr.png, version PNG des SVG officiels), ou null.
     *
     * @return array{cid:string,path:string,width:int}|null
     */
    public static function emailBadge($key)
    {
        $path = defined('SMBB_PASSFLOW_PATH') ? SMBB_PASSFLOW_PATH . 'assets/wallet/' . $key . '-fr.png' : '';
        $size = $path !== '' && is_readable($path) ? @getimagesize($path) : false; // phpcs:ignore

        if (!$size || empty($size[1])) {
            return null;
        }

        return array(
            'cid' => 'passflow-wallet-' . $key,
            'path' => $path,
            'width' => (int) round($size[0] * 44 / $size[1]),
        );
    }

    // ------------------------------------------------------------------
    // Apple Wallet (.pkpass)
    // ------------------------------------------------------------------

    /**
     * Construit le .pkpass : pass.json + images + manifest.json + signature PKCS#7 détachée.
     *
     * @return string contenu binaire du fichier, ou '' si la configuration est incomplète
     */
    public static function applePass(array $booking)
    {
        if (!self::appleReady()) {
            return '';
        }

        $d = 'smbb-passflow';
        $t = self::data($booking);
        $t['geo'] = self::geo($t['activity_row']);
        $rgb = self::rgb($t['color']);
        $pass = array(
            'formatVersion' => 1,
            'passTypeIdentifier' => (string) Settings::get('apple_pass_type_id'),
            'teamIdentifier' => (string) Settings::get('apple_team_id'),
            'serialNumber' => self::serial($booking),
            'organizationName' => $t['shop'],
            'description' => sprintf(__('Billet %s', $d), $t['atelier']),
            'logoText' => $t['shop'],
            'foregroundColor' => vsprintf('rgb(%d, %d, %d)', self::textColor($t['color'])),
            'labelColor' => vsprintf('rgb(%d, %d, %d)', self::textColor($t['color'])),
            'backgroundColor' => sprintf('rgb(%d, %d, %d)', $rgb[0], $rgb[1], $rgb[2]),
            'barcodes' => array(array(
                'format' => 'PKBarcodeFormatQR',
                'message' => $t['checkin'],
                'messageEncoding' => 'iso-8859-1',
                'altText' => $t['reference'],
            )),
            'eventTicket' => array(
                'primaryFields' => array(array('key' => 'atelier', 'label' => __('Atelier', $d), 'value' => $t['atelier'])),
                'secondaryFields' => array_values(array_filter(array(
                    array('key' => 'name', 'label' => __('Participant', $d), 'value' => $t['name']),
                    $t['staff'] !== '' ? array('key' => 'staff', 'label' => __('Avec', $d), 'value' => $t['staff']) : null,
                ))),
                'auxiliaryFields' => array(
                    array('key' => 'date', 'label' => __('Date', $d), 'value' => $t['date']),
                    array('key' => 'hours', 'label' => __('Horaire', $d), 'value' => $t['hours']),
                ),
                'backFields' => array(),
            ),
        );

        // Dos du billet : infos pratiques, jours du pass, lieu, liens utiles, conditions.
        $back = array();
        $back[] = $t['practical'] !== '' ? array('key' => 'practical', 'label' => __('Infos pratiques', $d), 'value' => $t['practical']) : null;
        $back[] = $t['duration'] !== '' ? array('key' => 'duration', 'label' => __('Durée', $d), 'value' => $t['duration']) : null;
        $days = self::passDays($booking);
        $back[] = $days ? array('key' => 'days', 'label' => __('Jours du pass', $d), 'value' => implode("\n", $days), 'changeMessage' => __('Pass : %@', $d)) : null;
        $back[] = $t['location'] !== '' || $t['address'] !== '' ? array('key' => 'place', 'label' => __('Lieu', $d), 'value' => trim($t['location'] . "\n" . $t['address'])) : null;

        foreach (self::links($booking, $t, 'apple') as $link) {
            $back[] = array('key' => 'link_' . $link['id'], 'label' => $link['label'], 'value' => $link['text'], 'attributedValue' => '<a href="' . esc_url_raw($link['url'], array('http', 'https', 'tel', 'mailto')) . '">' . esc_html($link['text']) . '</a>');
        }

        $policy = trim((string) Settings::get('ticket_policy'));
        $back[] = $policy !== '' ? array('key' => 'policy', 'label' => __("Conditions d'annulation", $d), 'value' => $policy) : null;
        $back[] = array('key' => 'ref', 'label' => __('Référence', $d), 'value' => $t['reference']);
        $pass['eventTicket']['backFields'] = array_values(array_filter($back));

        // Écran verrouillé : le billet remonte le jour J et à l'approche du lieu.
        if ($t['start'] !== '') {
            $pass['relevantDate'] = self::iso($t['start']);
        }

        // Le billet passe en « expiré » (grisé) à 3h le lendemain du dernier jour de l'événement, pas avant.
        $until = self::validUntil($booking);

        if ($until !== '') {
            $pass['expirationDate'] = self::iso($until);
        }

        if ($t['geo']) {
            $pass['locations'] = array(array(
                'latitude' => $t['geo'][0],
                'longitude' => $t['geo'][1],
                'relevantText' => sprintf(__('%s : présentez ce billet à l\'accueil', $d), $t['atelier']),
            ));
            $pass['maxDistance'] = 500;
        }

        // Suivi de l'état : l'iPhone s'inscrit auprès de Passflow et reçoit les mises à jour (présence, annulation…).
        if (self::syncOn('apple')) {
            $pass['webServiceURL'] = rest_url(WalletSync::ROUTE);
            $pass['authenticationToken'] = WalletSync::appleToken($pass['serialNumber']);
            $status = self::walletStatus($booking);

            array_unshift($pass['eventTicket']['auxiliaryFields'], array('key' => 'statut', 'label' => __('Statut', $d), 'value' => $status['label'], 'changeMessage' => __('Votre billet : %@', $d)));
            if ($status['void']) {
                $pass['voided'] = true;
            } else {
                // Message de la veille : sa valeur change pendant la fenêtre du rappel, l'iPhone affiche une notification.
                $eve = self::eveText($booking);
                array_unshift($pass['eventTicket']['backFields'], array('key' => 'message', 'label' => __('Message', $d), 'value' => $eve !== '' ? $eve : __('Nous avons hâte de vous accueillir !', $d), 'changeMessage' => '%@'));
            }

            // Changement de date ou d'horaire : notification sur l'iPhone.
            foreach ($pass['eventTicket']['auxiliaryFields'] as $i => $field) {
                if (in_array($field['key'], array('date', 'hours'), true)) {
                    $pass['eventTicket']['auxiliaryFields'][$i]['changeMessage'] = __('Nouvelle date ou horaire : %@', $d);
                }
            }
        }

        $files = array('pass.json' => wp_json_encode($pass, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        foreach (array('icon.png', 'icon@2x.png', 'icon@3x.png') as $image) {
            $path = SMBB_PASSFLOW_PATH . 'assets/wallet/' . $image;

            if (is_readable($path)) {
                $files[$image] = (string) file_get_contents($path);
            }
        }

        // Logo : 160 × 50 points maximum (images ×1, ×2, ×3).
        if ($t['logo']) {
            foreach (array('logo.png' => 1, 'logo@2x.png' => 2, 'logo@3x.png' => 3) as $image => $scale) {
                $file = self::logoFile($t['logo'], 160 * $scale, 50 * $scale);

                if ($file !== '') {
                    $files[$image] = (string) file_get_contents($file);
                    @unlink($file); // phpcs:ignore
                }
            }
        }

        // Photo de l'atelier en bandeau (375 × 98 points pour un billet d'événement).
        if ($t['image']) {
            foreach (array('strip.png' => 1, 'strip@2x.png' => 2, 'strip@3x.png' => 3) as $image => $scale) {
                $file = self::logoFile($t['image'], 375 * $scale, 98 * $scale, true);

                if ($file !== '') {
                    $files[$image] = (string) file_get_contents($file);
                    @unlink($file); // phpcs:ignore
                }
            }
        }

        $manifest = array();

        foreach ($files as $name => $content) {
            $manifest[$name] = sha1($content);
        }

        $files['manifest.json'] = wp_json_encode($manifest, JSON_UNESCAPED_SLASHES);
        $signature = self::signManifest($files['manifest.json']);

        if ($signature === '') {
            return '';
        }

        $files['signature'] = $signature;

        return self::zip($files);
    }

    private static function sendApple(array $booking)
    {
        $content = self::applePass($booking);

        if ($content === '') {
            wp_die(esc_html__("Apple Wallet n'est pas encore configuré.", 'smbb-passflow'));
        }

        nocache_headers();
        header('Content-Type: application/vnd.apple.pkpass');
        header('Content-Disposition: attachment; filename="billet-' . (int) $booking['id'] . '.pkpass"');
        header('Content-Length: ' . strlen($content));
        echo $content; // phpcs:ignore -- fichier binaire.
        exit;
    }

    /**
     * Signature PKCS#7 détachée (DER) du manifest, avec le certificat Pass Type ID et le certificat Apple WWDR.
     */
    public static function signManifest($manifest)
    {
        $in = self::tempFile('passflow-manifest');
        $out = self::tempFile('passflow-signature');
        $extra = self::tempFile('passflow-wwdr');

        file_put_contents($in, $manifest);
        file_put_contents($extra, (string) Settings::get('apple_wwdr'));

        $key = openssl_pkey_get_private(Settings::secret('apple_private_key'), Settings::secret('apple_key_password'));
        $ok = $key && openssl_pkcs7_sign($in, $out, (string) Settings::get('apple_certificate'), $key, array(), PKCS7_BINARY | PKCS7_DETACHED, $extra);
        $smime = $ok ? (string) file_get_contents($out) : '';

        foreach (array($in, $out, $extra) as $file) {
            @unlink($file); // phpcs:ignore
        }

        if ($smime === '') {
            return '';
        }

        // Sortie S/MIME : on garde la partie base64 de la signature et on la passe en DER.
        if (!preg_match('/Content-Disposition: attachment; filename="smime.p7s"\s+(.+?)\s+------/s', $smime, $match)) {
            return '';
        }

        return (string) base64_decode(preg_replace('/\s+/', '', $match[1]));
    }

    /**
     * Fichier temporaire. wp_tempnam() n'existe que dans l'administration (wp-admin/includes/file.php) :
     * le billet est téléchargé depuis le site, on passe donc par tempnam().
     */
    private static function tempFile($prefix)
    {
        $file = tempnam(get_temp_dir(), $prefix);

        return $file !== false ? $file : get_temp_dir() . $prefix . wp_generate_password(8, false);
    }

    private static function zip(array $files)
    {
        $path = self::tempFile('passflow-pass');
        $zip = new \ZipArchive();

        if ($zip->open($path, \ZipArchive::OVERWRITE) !== true) {
            return '';
        }

        foreach ($files as $name => $content) {
            $zip->addFromString($name, $content);
        }

        $zip->close();
        $content = (string) file_get_contents($path);
        @unlink($path); // phpcs:ignore

        return $content;
    }

    // ------------------------------------------------------------------
    // Google Wallet (lien « Ajouter à Google Wallet »)
    // ------------------------------------------------------------------

    /**
     * Lien https://pay.google.com/gp/v/save/{jwt} : la classe (l'atelier) et l'objet (le billet)
     * voyagent dans le JWT signé avec le compte de service ; rien n'est appelé côté serveur.
     */
    /**
     * Classe (la date) et objet (le billet) Google Wallet, avec l'état du billet (présent, absent, annulé).
     *
     * @return array{0:array<string,mixed>,1:array<string,mixed>}
     */
    public static function googleResources(array $booking)
    {
        $d = 'smbb-passflow';
        $t = self::data($booking);
        $t['geo'] = self::geo($t['activity_row']);
        $issuer = preg_replace('/[^0-9]/', '', (string) Settings::get('google_issuer_id'));
        $slot_id = (int) $booking['slot_id'];
        $class_id = $issuer . '.passflow-slot-' . $slot_id;
        $fr = function ($value) {
            return array('defaultValue' => array('language' => 'fr', 'value' => (string) $value));
        };

        $class = array(
            'id' => $class_id,
            'issuerName' => $t['shop'],
            'eventName' => $fr(trim((string) $t['atelier']) !== '' ? $t['atelier'] : __('Billet', $d)),
            'reviewStatus' => 'UNDER_REVIEW',
            'hexBackgroundColor' => $t['color'],
        );

        // Logo : Google va le chercher à cette adresse (elle doit être publique, en https).
        // Celui des réglages Billets, sinon le logo du thème, sinon l'icône du site.
        $logo_url = self::googleLogoUrl($t['logo']);

        if ($logo_url !== '') {
            $class['logo'] = array('sourceUri' => array('uri' => $logo_url));
        }

        if ($t['location'] !== '' || $t['address'] !== '') {
            // Google refuse un LocalizedString vide : nom et adresse se servent de repli l'un l'autre.
            $venue_name = $t['location'] !== '' ? $t['location'] : $t['address'];
            $venue_address = $t['address'] !== '' ? $t['address'] : $t['location'];
            $class['venue'] = array('name' => $fr($venue_name), 'address' => $fr($venue_address));
        }

        if ($t['start'] !== '') {
            $class['dateTime'] = array_filter(array('start' => self::iso($t['start']), 'end' => $t['end'] !== '' ? self::iso($t['end']) : ''));
        }

        $hero = $t['image'] ? (string) wp_get_attachment_image_url($t['image'], 'large') : '';

        if (strpos($hero, 'https://') === 0) {
            $class['heroImage'] = array('sourceUri' => array('uri' => $hero));
        }

        $policy = trim((string) Settings::get('ticket_policy'));

        if ($policy !== '') {
            $class['finePrint'] = $fr($policy);
        }

        $object = array(
            'id' => self::googleObjectId($booking),
            'classId' => $class_id,
            'state' => 'ACTIVE',
            'ticketHolderName' => $t['name'],
            'ticketNumber' => $t['reference'],
            'barcode' => array('type' => 'QR_CODE', 'value' => $t['checkin'], 'alternateText' => $t['reference']),
            'hexBackgroundColor' => $t['color'],
            'textModulesData' => array(array('id' => 'date', 'header' => __('Date', $d), 'body' => trim($t['date'] . ' ' . $t['hours']))),
        );

        // Fin de validité : Google passe le billet en expiré après le dernier jour de l'événement.
        $until = self::validUntil($booking);

        if ($until !== '') {
            $object['validTimeInterval'] = array('end' => array('date' => self::iso($until)));
        }

        // État suivi : absent (expiré), annulé ou sans date (désactivé). Présent reste actif.
        $status = self::walletStatus($booking);

        if ($status['google'] !== 'ACTIVE' && self::syncOn('google')) {
            $object['state'] = $status['google'];
        }

        if (self::syncOn('google')) {
            array_unshift($object['textModulesData'], array('id' => 'statut', 'header' => __('Statut', $d), 'body' => $status['label']));
        }

        // Infos de l'atelier, jours du pass et liens utiles.
        $modules = array(
            'avec' => array(__('Avec', $d), $t['staff']),
            'duree' => array(__('Durée', $d), $t['duration']),
            'pratique' => array(__('Infos pratiques', $d), $t['practical']),
            'jours' => array(__('Jours du pass', $d), implode("\n", self::passDays($booking))),
        );

        foreach ($modules as $id => $module) {
            if ($module[1] !== '') {
                $object['textModulesData'][] = array('id' => $id, 'header' => $module[0], 'body' => $module[1]);
            }
        }

        $uris = array();

        foreach (self::links($booking, $t, 'google') as $link) {
            $uris[] = array('id' => $link['id'], 'uri' => $link['url'], 'description' => $link['label'] . ' : ' . $link['text']);
        }

        if ($uris) {
            $object['linksModuleData'] = array('uris' => $uris);
        }

        return array($class, $object);
    }


    public static function googleSaveUrl(array $booking)
    {
        if (!self::googleReady()) {
            return '';
        }

        $account = json_decode(Settings::secret('google_service_account'), true);
        list($class, $object) = self::googleResources($booking);

        // La classe et le billet sont d'abord créés (ou mis à jour) par l'API Google : le lien ne porte
        // ensuite que l'identifiant du billet (« skinny JWT »), court, et Google renvoie de vraies erreurs.
        $saved = self::googleUpsert('eventTicketClass', $class);

        if ($saved === true) {
            $saved = self::googleUpsert('eventTicketObject', $object);
        }

        if ($saved !== true) {
            self::$googleError = $saved;

            return '';
        }

        $claims = array(
            'iss' => $account['client_email'],
            'aud' => 'google',
            'typ' => 'savetowallet',
            'iat' => time(),
            'origins' => array(home_url()),
            'payload' => array(
                'eventTicketObjects' => array(array('id' => $object['id'])),
            ),
        );

        $jwt = self::jwt($claims, (string) $account['private_key']);

        return $jwt !== '' ? 'https://pay.google.com/gp/v/save/' . $jwt : '';
    }

    /** @var string Dernière erreur Google Wallet (affichée au lieu du lien). */
    private static $googleError = '';

    public static function googleError()
    {
        return self::$googleError;
    }

    /**
     * Suivi de l'état dans les Wallet (Réglages › Billets) : google|apple.
     */
    public static function syncOn($wallet)
    {
        return (int) Settings::get('wallet_sync_' . $wallet) === 1;
    }

    /**
     * État du billet affiché dans les Wallet.
     *
     * @return array{label:string,google:string,void:bool}
     */
    public static function walletStatus(array $booking)
    {
        $d = 'smbb-passflow';
        $status = (string) $booking['status'];

        // Ancien billet d'une place transférée à quelqu'un d'autre : barré.
        if (!empty($booking['_transferred'])) {
            return array('label' => __('Billet transféré', $d), 'google' => 'INACTIVE', 'void' => true);
        }

        if ($status === 'present') {
            // Présent : affiché sur le billet, mais le QR reste actif (entrées/sorties multiples).
            // Le billet expire seul après la date de l'événement (expirationDate / validTimeInterval).
            return array('label' => __('Présent', $d), 'google' => 'ACTIVE', 'void' => false);
        }

        if ($status === 'absent') {
            return array('label' => __('Absent', $d), 'google' => 'EXPIRED', 'void' => true);
        }

        if ($status === 'cancelled') {
            return array('label' => __('Réservation annulée', $d), 'google' => 'INACTIVE', 'void' => true);
        }

        if (empty($booking['slot_id'])) {
            return array('label' => __('En attente d\'une nouvelle date', $d), 'google' => 'INACTIVE', 'void' => true);
        }

        // Pass : jours déjà faits sur le total.
        $slot = Slots::find($booking['slot_id']);

        if ($slot && Passes::isPass($slot)) {
            $children = Passes::children((int) $booking['id']);
            $done = count(array_filter($children, function ($child) {
                return $child['status'] === 'present';
            }));

            if ($done > 0) {
                return array('label' => sprintf(__('Présent %1$d jour(s) sur %2$d', $d), $done, count($children)), 'google' => 'ACTIVE', 'void' => false);
            }
        }

        return array('label' => __('Non utilisé', $d), 'google' => 'ACTIVE', 'void' => false);
    }

    /**
     * Message Google Wallet avec notification sur le téléphone (rappel de la veille).
     *
     * @return true|string
     */
    public static function googleNotify(array $booking, $text)
    {
        if (!self::googleReady() || !self::syncOn('google') || $text === '') {
            return true;
        }

        $token = self::googleToken();

        if (is_wp_error($token)) {
            return $token->get_error_message();
        }

        $issuer = preg_replace('/[^0-9]/', '', (string) Settings::get('google_issuer_id'));
        $url = 'https://walletobjects.googleapis.com/walletobjects/v1/eventTicketObject/' . rawurlencode(self::googleObjectId($booking)) . '/addMessage';
        $response = wp_remote_post($url, array(
            'timeout' => 10,
            'headers' => array('Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json; charset=utf-8'),
            'body' => wp_json_encode(array('message' => array(
                'id' => 'veille-' . (int) $booking['id'] . '-' . gmdate('Ymd'),
                'header' => (string) Settings::get('sender_name') ?: get_bloginfo('name'),
                'body' => $text,
                'messageType' => 'TEXT_AND_NOTIFY',
            )), JSON_UNESCAPED_UNICODE),
        ));
        $code = is_wp_error($response) ? 0 : (int) wp_remote_retrieve_response_code($response);

        return $code === 404 || $code >= 200 && $code < 300 ? true : self::googleMessage($response); // 404 : jamais ajouté à un Wallet Google.
    }

    /**
     * Met à jour chez Google un billet déjà ajouté à un Wallet (rien si le client ne l'a jamais ajouté).
     *
     * @return true|string
     */
    public static function googleSync(array $booking)
    {
        if (!self::googleReady() || !self::syncOn('google') || empty($booking['slot_id']) && $booking['status'] !== 'cancelled') {
            return true;
        }

        $issuer = preg_replace('/[^0-9]/', '', (string) Settings::get('google_issuer_id'));
        $token = self::googleToken();

        if (is_wp_error($token)) {
            return $token->get_error_message();
        }

        $get = wp_remote_get('https://walletobjects.googleapis.com/walletobjects/v1/eventTicketObject/' . rawurlencode(self::googleObjectId($booking)), array('timeout' => 10, 'headers' => array('Authorization' => 'Bearer ' . $token)));

        if (is_wp_error($get) || (int) wp_remote_retrieve_response_code($get) !== 200) {
            return true; // jamais ajouté à un Wallet Google : rien à mettre à jour.
        }

        if (empty($booking['slot_id'])) {
            // Plus de date : on garde la classe d'origine, seul l'état change.
            $current = json_decode((string) wp_remote_retrieve_body($get), true);
            $object = array('state' => 'INACTIVE', 'textModulesData' => array(array('id' => 'statut', 'header' => __('Statut', 'smbb-passflow'), 'body' => self::walletStatus($booking)['label'])));
            $response = wp_remote_request('https://walletobjects.googleapis.com/walletobjects/v1/eventTicketObject/' . rawurlencode((string) $current['id']), array(
                'method' => 'PATCH', 'timeout' => 10,
                'headers' => array('Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json; charset=utf-8'),
                'body' => wp_json_encode($object, JSON_UNESCAPED_UNICODE),
            ));

            return !is_wp_error($response) && (int) wp_remote_retrieve_response_code($response) < 300 ? true : 'PATCH';
        }

        list($class, $object) = self::googleResources($booking);
        $saved = self::googleUpsert('eventTicketClass', $class);

        return $saved === true ? self::googleUpsert('eventTicketObject', $object) : $saved;
    }

    private static function googleLogoUrl($logo_id)
    {
        $candidates = array();

        if ($logo_id) {
            $candidates[] = (string) wp_get_attachment_image_url((int) $logo_id, 'medium');
        }

        if (get_theme_mod('custom_logo')) {
            $candidates[] = (string) wp_get_attachment_image_url((int) get_theme_mod('custom_logo'), 'medium');
        }

        $candidates[] = (string) get_site_icon_url(512);

        foreach ($candidates as $url) {
            if ($url !== '' && strpos($url, 'https://') === 0) {
                return $url;
            }
        }

        return '';
    }

    /**
     * Jeton OAuth du compte de service (gardé 50 minutes).
     *
     * @return string|\WP_Error
     */
    private static function googleToken()
    {
        $cached = get_transient('smbb_passflow_google_token');

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $account = json_decode(Settings::secret('google_service_account'), true);
        $now = time();
        $jwt = self::jwt(array(
            'iss' => (string) $account['client_email'],
            'scope' => 'https://www.googleapis.com/auth/wallet_object.issuer',
            'aud' => 'https://oauth2.googleapis.com/token',
            'iat' => $now,
            'exp' => $now + 3600,
        ), (string) $account['private_key']);

        if ($jwt === '') {
            return new \WP_Error('passflow_google', __('La clé privée du compte de service Google est illisible.', 'smbb-passflow'));
        }

        $response = wp_remote_post('https://oauth2.googleapis.com/token', array(
            'timeout' => 15,
            'body' => array('grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => $jwt),
        ));

        if (is_wp_error($response)) {
            return $response;
        }

        $body = json_decode((string) wp_remote_retrieve_body($response), true);

        if (empty($body['access_token'])) {
            return new \WP_Error('passflow_google', sprintf(__('Google refuse la clé du compte de service : %s', 'smbb-passflow'), is_array($body) ? trim((isset($body['error']) ? $body['error'] : '') . ' ' . (isset($body['error_description']) ? $body['error_description'] : '')) : 'HTTP ' . wp_remote_retrieve_response_code($response)));
        }

        set_transient('smbb_passflow_google_token', (string) $body['access_token'], 50 * MINUTE_IN_SECONDS);

        return (string) $body['access_token'];
    }

    /**
     * Crée ou met à jour une ressource Google Wallet (classe ou billet).
     *
     * @return true|string true, ou le message d'erreur de Google
     */
    private static function googleUpsert($type, array $resource)
    {
        $token = self::googleToken();

        if (is_wp_error($token)) {
            return $token->get_error_message();
        }

        $base = 'https://walletobjects.googleapis.com/walletobjects/v1/' . $type;
        $args = array('timeout' => 15, 'headers' => array('Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json; charset=utf-8'));
        $get = wp_remote_get($base . '/' . rawurlencode($resource['id']), $args);

        if (is_wp_error($get)) {
            return $get->get_error_message();
        }

        $code = (int) wp_remote_retrieve_response_code($get);
        $exists = $code === 200;

        if (!$exists && $code !== 404) {
            return self::googleMessage($get);
        }

        $response = wp_remote_request($exists ? $base . '/' . rawurlencode($resource['id']) : $base, array_merge($args, array(
            'method' => $exists ? 'PUT' : 'POST',
            'body' => wp_json_encode($resource, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        )));

        if (is_wp_error($response)) {
            return $response->get_error_message();
        }

        $code = (int) wp_remote_retrieve_response_code($response);

        return $code >= 200 && $code < 300 ? true : self::googleMessage($response);
    }

    private static function googleMessage($response)
    {
        $code = (int) wp_remote_retrieve_response_code($response);
        $body = json_decode((string) wp_remote_retrieve_body($response), true);
        $text = is_array($body) && isset($body['error']['message']) ? (string) $body['error']['message'] : '';

        if ($code === 401 || $code === 403) {
            $text = __("le compte de service n'a pas accès à ce compte émetteur. Invitez son e-mail dans la console Google Pay & Wallet › Utilisateurs (rôle Développeur), vérifiez l'Issuer ID et que l'API Google Wallet est activée sur le projet Google Cloud.", 'smbb-passflow') . ($text !== '' ? ' (' . $text . ')' : '');
        }

        return sprintf(__('Google Wallet a refusé le billet (HTTP %1$d) : %2$s', 'smbb-passflow'), $code, $text !== '' ? $text : (string) wp_remote_retrieve_body($response));
    }

    /**
     * Google Wallet : la clé JSON du compte de service n'a pas de date de fin lisible (elle peut pourtant être
     * supprimée, désactivée ou expirer selon la politique du compte Google). On la vérifie donc pour de vrai :
     * demande d'un jeton OAuth à Google avec cette clé.
     *
     * @return array{ok:bool|null,message:string} ok null = vérification impossible (réseau), sans conclure
     */
    public static function googleKeyCheck()
    {
        $d = 'smbb-passflow';
        $account = json_decode(Settings::secret('google_service_account'), true);

        if (!self::googleReady()) {
            return array('ok' => null, 'message' => '');
        }

        $now = time();
        $jwt = self::jwt(array(
            'iss' => (string) $account['client_email'],
            'scope' => 'https://www.googleapis.com/auth/wallet_object.issuer',
            'aud' => 'https://oauth2.googleapis.com/token',
            'iat' => $now,
            'exp' => $now + 300,
        ), (string) $account['private_key']);

        if ($jwt === '') {
            return array('ok' => false, 'message' => __('La clé privée du compte de service Google est illisible.', $d));
        }

        $response = wp_remote_post('https://oauth2.googleapis.com/token', array(
            'timeout' => 15,
            'body' => array('grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => $jwt),
        ));

        if (is_wp_error($response)) {
            return array('ok' => null, 'message' => $response->get_error_message());
        }

        $body = json_decode((string) wp_remote_retrieve_body($response), true);

        if (is_array($body) && !empty($body['access_token'])) {
            return array('ok' => true, 'message' => '');
        }

        $code = (int) wp_remote_retrieve_response_code($response);

        if ($code >= 500 || $code === 0) {
            return array('ok' => null, 'message' => sprintf('HTTP %d', $code));
        }

        $detail = is_array($body) ? trim((isset($body['error']) ? $body['error'] : '') . ' ' . (isset($body['error_description']) ? $body['error_description'] : '')) : '';

        return array('ok' => false, 'message' => sprintf(__('Google refuse la clé du compte de service (%s) : elle a peut-être été supprimée, désactivée ou a expiré.', $d), $detail !== '' ? $detail : 'HTTP ' . $code));
    }

    private static function jwt(array $claims, $private_key)
    {
        $encode = function ($data) {
            return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
        };
        $input = $encode(wp_json_encode(array('alg' => 'RS256', 'typ' => 'JWT'))) . '.' . $encode(wp_json_encode($claims, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $signature = '';

        if (!openssl_sign($input, $signature, $private_key, OPENSSL_ALGO_SHA256)) {
            return '';
        }

        return $input . '.' . $encode($signature);
    }

    // ------------------------------------------------------------------
    // Pointage par QR code
    // ------------------------------------------------------------------

    /**
     * QR code scanné sans être connecté : on passe par la connexion, puis on revient au billet.
     */
    public static function login()
    {
        $back = add_query_arg(array(
            'action' => self::CHECKIN,
            'id' => isset($_GET['id']) ? absint($_GET['id']) : 0,
            'k' => isset($_GET['k']) ? sanitize_text_field(wp_unslash($_GET['k'])) : '',
        ), admin_url('admin-post.php'));

        wp_safe_redirect(wp_login_url($back));
        exit;
    }

    /**
     * Écran mobile ouvert par le QR code : la réservation et un bouton « Présent ».
     * Réservé aux gestionnaires et à l'intervenant du créneau.
     */
    public static function checkin()
    {
        $d = 'smbb-passflow';
        $id = isset($_REQUEST['id']) ? absint($_REQUEST['id']) : 0;
        $token = isset($_REQUEST['k']) ? sanitize_text_field(wp_unslash($_REQUEST['k'])) : '';
        $booking = Bookings::find($id);

        $previous = $booking && !self::valid($booking, $token) ? Transfers::fromToken($booking, $token) : null;

        if ($previous) {
            self::checkinPage(__('Billet transféré', $d), '<p class="name">' . esc_html(Lookup::personName($previous)) . '</p>'
                . '<p class="notice warn">' . esc_html(sprintf(__('Ce billet a été transféré le %1$s à %2$s : il n\'est plus valable. Demandez le nouveau billet.', $d), mysql2date('d/m/Y à H:i', (string) $previous['_transferred']['at']), Lookup::personName($booking))) . '</p>', 'error');
        }

        if (!$booking || !self::valid($booking, $token)) {
            self::checkinPage(__('Billet inconnu', $d), '<p>' . esc_html__('Ce QR code ne correspond à aucune réservation.', $d) . '</p>', 'error');
        }

        // Pass plusieurs jours : le même QR pointe le jour du scan.
        $pass_note = '';
        $pass = !empty($booking['slot_id']) ? Slots::find($booking['slot_id']) : null;

        if (Passes::isPass($pass)) {
            $child = Passes::childOn($booking['id'], current_time('Y-m-d'));
            $pass_note = '<p class="notice info">' . esc_html(sprintf(__('Pass : %s', $d), Passes::daysLabel($pass))) . '</p>';

            if (!$child) {
                $days = array();

                foreach (Passes::children($booking['id'], true) as $c) {
                    $days[] = date_i18n('D j M', strtotime($c['start_date'] . ' 12:00:00')) . ' : ' . \Smbb\Passflow\Support\Choices::label('bookingStatuses', $c['status']);
                }

                self::checkinPage(__('Pas de jour du pass aujourd\'hui', $d), '<p class="name">' . esc_html(Lookup::personName($booking)) . '</p>' . $pass_note
                    . '<p class="notice warn">' . esc_html__("Ce pass ne comprend pas de place aujourd'hui, ou ce jour a été annulé.", $d) . '</p>'
                    . ($days ? '<ul><li>' . implode('</li><li>', array_map('esc_html', $days)) . '</li></ul>' : ''), 'warn');
            }

            $booking = $child;
            $id = (int) $child['id'];
        }

        if (!\Smbb\Passflow\Support\Roles::canManage() && !\Smbb\Passflow\Admin\Actions::canSeeSlot($booking['slot_id'])) {
            self::checkinPage(__('Accès réservé', $d), '<p>' . esc_html__("Seuls l'équipe et l'intervenant de cet atelier peuvent pointer ce billet.", $d) . '</p>', 'error');
        }

        $message = '';
        $scanned_id = isset($_REQUEST['id']) ? absint($_REQUEST['id']) : $id;

        if (isset($_POST['presence']) && check_admin_referer(self::CHECKIN . '_' . $id)) {
            $status = sanitize_key(wp_unslash($_POST['presence'])) === 'absent' ? 'absent' : 'present';
            $result = Booking::presence($id, $status);
            $message = '<p class="notice ' . ($result['success'] ? 'ok' : 'error') . '">' . esc_html($result['message']) . '</p>';
            $booking = Bookings::find($id);
        }

        $t = self::data($booking);
        $slot = Slots::find($booking['slot_id']);
        $today = $slot && $slot['start_date'] === current_time('Y-m-d');
        $labels = \Smbb\Passflow\Support\Choices::bookingStatuses();
        $status = $booking['status'];
        $state = $status === 'present' ? 'ok' : ($status === 'cancelled' ? 'error' : ($today ? 'info' : 'warn'));

        $body = $message . $pass_note
            . '<p class="status ' . esc_attr($state) . '">' . esc_html(isset($labels[$status]) ? $labels[$status] : $status) . '</p>'
            . '<p class="name">' . esc_html($t['name']) . '</p>'
            . '<p>' . esc_html($t['atelier']) . '<br>' . esc_html(trim($t['date'] . ' · ' . $t['hours'], ' ·')) . '</p>'
            . (!$today && $status !== 'cancelled' ? '<p class="notice warn">' . esc_html__("Attention : ce billet n'est pas pour aujourd'hui.", $d) . '</p>' : '')
            . (!empty($booking['allergies']) ? '<p class="notice warn">' . esc_html(sprintf(__('Allergies : %s', $d), $booking['allergies'])) . '</p>' : '');

        if ($status !== 'cancelled' && !empty($booking['slot_id'])) {
            $body .= '<form method="post">'
                . wp_nonce_field(self::CHECKIN . '_' . $id, '_wpnonce', true, false)
                . '<input type="hidden" name="action" value="' . esc_attr(self::CHECKIN) . '">'
                . '<input type="hidden" name="id" value="' . esc_attr((string) $scanned_id) . '">'
                . '<input type="hidden" name="k" value="' . esc_attr($token) . '">'
                . ($status !== 'present' ? '<button name="presence" value="present" class="primary">' . esc_html__('Présent', $d) . '</button>' : '')
                . ($status !== 'absent' ? '<button name="presence" value="absent">' . esc_html__('Absent', $d) . '</button>' : '')
                . '</form>';
        }

        $body .= '<p class="links"><a href="' . esc_url(\Smbb\Passflow\Admin\View::url('passflow_portal', array('creneau' => (int) $booking['slot_id']))) . '">' . esc_html__("Appel de l'atelier", $d) . '</a></p>';

        self::checkinPage(__('Pointage', $d), $body, $state);
    }

    private static function checkinPage($title, $body, $state)
    {
        nocache_headers();
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex">'
            . '<title>' . esc_html($title) . '</title><style>'
            . 'body{font:16px/1.45 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;margin:0;background:#f6f3ee;color:#2b2420}'
            . 'main{max-width:420px;margin:0 auto;padding:24px 18px}h1{font-size:15px;text-transform:uppercase;letter-spacing:.06em;opacity:.6;margin:0 0 16px}'
            . '.name{font-size:26px;font-weight:700;margin:4px 0 8px}.status{display:inline-block;padding:6px 14px;border-radius:999px;font-weight:600;margin:0}'
            . '.status.ok{background:#dff1e4;color:#1e6b37}.status.info{background:#e3eefb;color:#1d4f91}.status.warn{background:#fff1d6;color:#7a5500}.status.error{background:#fbe4e4;color:#9b1c1c}'
            . '.notice{padding:10px 12px;border-radius:8px}.notice.ok{background:#dff1e4}.notice.error{background:#fbe4e4}.notice.warn{background:#fff1d6}'
            . 'form{display:flex;gap:10px;margin:20px 0}button{flex:1;font-size:18px;padding:16px;border-radius:12px;border:1px solid #ccc;background:#fff}'
            . 'button.primary{background:#1e6b37;border-color:#1e6b37;color:#fff;font-weight:700}.links a{color:#2b2420}'
            . '</style></head><body><main><h1>' . esc_html($title) . '</h1>' . $body . '</main></body></html>'; // phpcs:ignore -- échappé ci-dessus.
        unset($state);
        exit;
    }

    /**
     * Fin de validité du billet (heure locale) : 3h00 le lendemain du dernier jour de l'événement
     * (dernier jour du pass pour un pass), '' si pas de date.
     */
    public static function validUntil(array $booking)
    {
        $slot = empty($booking['slot_id']) ? null : Slots::find($booking['slot_id']);

        if (!$slot || empty($slot['start_date'])) {
            return '';
        }

        $last = (string) $slot['start_date'];

        if (Passes::isPass($slot)) {
            foreach (Passes::children((int) $booking['id'], true) as $child) {
                $day = Slots::find($child['slot_id']);

                if ($day && !empty($day['start_date']) && strcmp((string) $day['start_date'], $last) > 0) {
                    $last = (string) $day['start_date'];
                }
            }
        }

        // 3h du matin le lendemain : laisse la marge pour un événement qui finit tard.
        return gmdate('Y-m-d', strtotime(substr($last, 0, 10) . ' 12:00:00 +1 day')) . 'T03:00:00';
    }

    /**
     * Heure locale du site (2026-12-06T19:30:00) → ISO 8601 avec fuseau (2026-12-06T19:30:00+01:00).
     */
    private static function iso($local)
    {
        try {
            return (new \DateTimeImmutable($local, wp_timezone()))->format('c');
        } catch (\Exception $e) {
            return '';
        }
    }

    /**
     * @return array{0:int,1:int,2:int}
     */
    /**
     * Couleur du billet : celle de l'atelier, ou une couleur unique choisie dans les réglages.
     *
     * @param array<string,mixed>|null $activity
     */
    private static function color($activity)
    {
        $fixed = (string) Settings::get('ticket_color');

        if (Settings::get('ticket_color_mode') === 'fixed' && preg_match('/^#[0-9a-fA-F]{6}$/', $fixed)) {
            return $fixed;
        }

        return $activity && !empty($activity['color']) ? (string) $activity['color'] : ($fixed !== '' ? $fixed : '#8c3c34');
    }

    /**
     * Texte blanc sur fond foncé, texte foncé sur fond clair.
     *
     * @return array{0:int,1:int,2:int}
     */
    public static function textColor($hex)
    {
        $rgb = self::rgb($hex);
        $luminance = (0.299 * $rgb[0] + 0.587 * $rgb[1] + 0.114 * $rgb[2]) / 255;

        return $luminance > 0.62 ? array(43, 36, 32) : array(255, 255, 255);
    }

    /**
     * Logo redimensionné (sans déformation, sans agrandissement) dans un PNG temporaire, ou '' sans logo exploitable.
     */
    private static function logoFile($attachment_id, $max_width, $max_height, $crop = false)
    {
        $path = get_attached_file((int) $attachment_id);

        if (!$path || !is_readable($path) || !function_exists('wp_get_image_editor')) {
            return '';
        }

        $editor = wp_get_image_editor($path);

        if (is_wp_error($editor)) {
            return '';
        }

        $size = $editor->get_size();

        if ($crop) {
            $editor->resize($max_width, $max_height, true); // bandeau : recadré au centre
        } elseif (!empty($size['width']) && !empty($size['height']) && ($size['width'] > $max_width || $size['height'] > $max_height)) {
            $editor->resize($max_width, $max_height, false);
        }

        $target = self::tempFile('passflow-logo') . '.png';
        $saved = $editor->save($target, 'image/png');

        return !is_wp_error($saved) && !empty($saved['path']) && is_readable($saved['path']) ? (string) $saved['path'] : '';
    }

    private static function rgb($hex)
    {
        $hex = ltrim((string) $hex, '#');

        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }

        if (!preg_match('/^[0-9a-fA-F]{6}/', $hex)) {
            return array(140, 60, 52);
        }

        return array(hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2)));
    }
}
