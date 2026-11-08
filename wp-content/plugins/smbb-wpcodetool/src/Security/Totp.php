<?php

namespace Smbb\WpCodeTool\Security;

use Smbb\WpCodeTool\Support\Secrets;

defined('ABSPATH') || exit;

/**
 * Codes d'application d'authentification (TOTP, RFC 6238) : Google Authenticator, Microsoft
 * Authenticator, Authy, 1Password, Bitwarden…
 *
 * Aucun échange réseau : le site et le téléphone partagent un secret (montré une fois en QR code),
 * et chacun calcule le code à 6 chiffres de la tranche de 30 s en cours à partir de son horloge.
 * Le QR code est dessiné ici (TCPDF), jamais par un service en ligne qui verrait le secret.
 *
 * Métas du compte :
 * - smbb_2fa_totp         secret actif, chiffré (Support\Secrets) ;
 * - smbb_2fa_totp_pending secret en cours d'activation (pas encore confirmé par un code) ;
 * - smbb_2fa_totp_last    dernière tranche acceptée (un code ne sert qu'une fois) ;
 * - smbb_2fa_totp_since   date d'activation.
 */
final class Totp
{
    const META = 'smbb_2fa_totp';
    const PENDING_META = 'smbb_2fa_totp_pending';
    const LAST_META = 'smbb_2fa_totp_last';
    const SINCE_META = 'smbb_2fa_totp_since';
    const PERIOD = 30;
    const DIGITS = 6;
    const WINDOW = 1; // tranches tolérées avant / après (± 30 s d'écart d'horloge)

    // ------------------------------------------------------------------ Calcul (sans WordPress)

    /**
     * Secret aléatoire de 20 octets (160 bits, taille recommandée par la RFC 4226), en base32.
     */
    public static function newSecret()
    {
        return self::base32Encode(random_bytes(20));
    }

    /**
     * Code à 6 chiffres pour une tranche donnée (RFC 4226 : HMAC-SHA1 + troncature dynamique).
     */
    public static function code($secret, $counter)
    {
        $key = self::base32Decode($secret);

        if ($key === '') {
            return '';
        }

        // Compteur sur 8 octets, gros-boutiste.
        $counter = (int) $counter;
        $bin = pack('N2', ($counter >> 32) & 0xFFFFFFFF, $counter & 0xFFFFFFFF);
        $hash = hash_hmac('sha1', $bin, $key, true);
        $offset = ord($hash[19]) & 0x0F;
        $value = ((ord($hash[$offset]) & 0x7F) << 24)
            | (ord($hash[$offset + 1]) << 16)
            | (ord($hash[$offset + 2]) << 8)
            | ord($hash[$offset + 3]);

        return str_pad((string) ($value % (10 ** self::DIGITS)), self::DIGITS, '0', STR_PAD_LEFT);
    }

    public static function counter($time = null)
    {
        return (int) floor(($time === null ? time() : (int) $time) / self::PERIOD);
    }

    /**
     * Cherche le code dans la tranche courante et ses voisines. Renvoie la tranche trouvée, ou
     * false. $after : dernière tranche déjà utilisée (refusée, comme toutes les précédentes).
     *
     * @return int|false
     */
    public static function match($secret, $code, $time = null, $after = -1)
    {
        $code = preg_replace('/\D+/', '', (string) $code);

        if (strlen($code) !== self::DIGITS) {
            return false;
        }

        $now = self::counter($time);

        for ($i = -self::WINDOW; $i <= self::WINDOW; $i++) {
            $counter = $now + $i;

            if ($counter > (int) $after && hash_equals(self::code($secret, $counter), $code)) {
                return $counter;
            }
        }

        return false;
    }

    /**
     * Adresse lue par les applications (format Key Uri de Google, reconnu partout).
     */
    public static function uri($secret, $account, $issuer)
    {
        $issuer = trim(str_replace(':', ' ', (string) $issuer));
        $label = rawurlencode($issuer) . ':' . rawurlencode((string) $account);

        return 'otpauth://totp/' . $label . '?' . http_build_query(array(
            'secret' => $secret,
            'issuer' => $issuer,
            'algorithm' => 'SHA1',
            'digits' => self::DIGITS,
            'period' => self::PERIOD,
        ), '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * Secret groupé par 4 pour la saisie à la main (« JBSW Y3DP … »).
     */
    public static function pretty($secret)
    {
        return trim(chunk_split((string) $secret, 4, ' '));
    }

    public static function base32Encode($bytes)
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $bits = '';
        $out = '';

        foreach (str_split((string) $bytes) as $char) {
            $bits .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
        }

        foreach (str_split($bits, 5) as $chunk) {
            $out .= $alphabet[bindec(str_pad($chunk, 5, '0', STR_PAD_RIGHT))];
        }

        return $out;
    }

    public static function base32Decode($text)
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $text = strtoupper(preg_replace('/[\s=-]+/', '', (string) $text));
        $bits = '';
        $out = '';

        if ($text === '' || strspn($text, $alphabet) !== strlen($text)) {
            return '';
        }

        foreach (str_split($text) as $char) {
            $bits .= str_pad(decbin(strpos($alphabet, $char)), 5, '0', STR_PAD_LEFT);
        }

        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $out .= chr(bindec($byte));
            }
        }

        return $out;
    }

    // ------------------------------------------------------------------ Compte

    public static function enabled($user_id)
    {
        return self::secret($user_id) !== '';
    }

    /**
     * Secret actif du compte, en clair ('' si aucun ou illisible).
     */
    public static function secret($user_id)
    {
        return self::read((int) $user_id, self::META);
    }

    /**
     * Secret en cours d'activation : créé au premier affichage, gardé tant qu'il n'est pas confirmé
     * (recharger la page ne change pas le QR code déjà scanné).
     */
    public static function pendingSecret($user_id, $renew = false)
    {
        $secret = $renew ? '' : self::read((int) $user_id, self::PENDING_META);

        if ($secret === '') {
            $secret = self::newSecret();
            update_user_meta((int) $user_id, self::PENDING_META, Secrets::encrypt($secret));
        }

        return $secret;
    }

    /**
     * Confirme l'activation avec un premier code lu dans l'application.
     *
     * @return true|\WP_Error
     */
    public static function activate($user_id, $code)
    {
        $user_id = (int) $user_id;
        $secret = self::read($user_id, self::PENDING_META);

        if ($secret === '') {
            return new \WP_Error('smbb_totp_none', __('Aucune activation en cours : rechargez la page pour afficher le QR code.', 'smbb-wpcodetool'));
        }

        $counter = self::match($secret, $code);

        if ($counter === false) {
            return new \WP_Error('smbb_totp_wrong', __('Code incorrect. Vérifiez que l\'heure du téléphone est automatique, puis saisissez le code affiché en ce moment.', 'smbb-wpcodetool'));
        }

        update_user_meta($user_id, self::META, Secrets::encrypt($secret));
        update_user_meta($user_id, self::LAST_META, $counter);
        update_user_meta($user_id, self::SINCE_META, time());
        delete_user_meta($user_id, self::PENDING_META);

        Security::log('security.totp_enabled', 'notice', __('Application d\'authentification activée', 'smbb-wpcodetool'), array(
            'user_id' => $user_id,
            'object_type' => 'user',
            'object_id' => $user_id,
        ));

        return true;
    }

    /**
     * Code saisi à la connexion. Une tranche acceptée ne peut plus resservir (pas de rejeu).
     */
    public static function check($user_id, $code)
    {
        $user_id = (int) $user_id;
        $secret = self::secret($user_id);

        if ($secret === '') {
            return false;
        }

        $last = get_user_meta($user_id, self::LAST_META, true);
        $counter = self::match($secret, $code, null, $last === '' ? -1 : (int) $last);

        if ($counter === false) {
            return false;
        }

        update_user_meta($user_id, self::LAST_META, $counter);

        return true;
    }

    /**
     * Le code est juste, mais sa tranche a déjà servi (code rejoué, ou même code que juste avant).
     */
    public static function reused($user_id, $code)
    {
        $secret = self::secret((int) $user_id);

        return $secret !== '' && self::match($secret, $code) !== false;
    }

    /**
     * Retire l'application du compte (par le compte lui-même ou par un administrateur).
     */
    public static function disable($user_id, $by = 0)
    {
        $user_id = (int) $user_id;

        foreach (array(self::META, self::PENDING_META, self::LAST_META, self::SINCE_META) as $key) {
            delete_user_meta($user_id, $key);
        }

        Security::log('security.totp_disabled', 'warning', $by && (int) $by !== $user_id
            ? __('Application d\'authentification retirée par un administrateur', 'smbb-wpcodetool')
            : __('Application d\'authentification retirée', 'smbb-wpcodetool'), array(
            'user_id' => $by ? (int) $by : $user_id,
            'object_type' => 'user',
            'object_id' => $user_id,
        ));
    }

    public static function since($user_id)
    {
        return (int) get_user_meta((int) $user_id, self::SINCE_META, true);
    }

    /**
     * QR code de l'adresse otpauth://, en SVG dessiné sur le serveur ('' si TCPDF manque).
     */
    public static function qrSvg($uri, $size = 200)
    {
        if (!class_exists('\\TCPDF2DBarcode')) {
            $file = '';

            if (class_exists('\\TCPDF')) {
                $file = dirname((new \ReflectionClass('\\TCPDF'))->getFileName()) . '/tcpdf_barcodes_2d.php';
            } elseif (defined('SMBB_WPCODETOOL_PATH')) {
                $file = SMBB_WPCODETOOL_PATH . 'vendor/tecnickcom/tcpdf/tcpdf_barcodes_2d.php';
            }

            if ($file !== '' && is_readable($file)) {
                require_once $file;
            }
        }

        if (!class_exists('\\TCPDF2DBarcode')) {
            return '';
        }

        $barcode = new \TCPDF2DBarcode((string) $uri, 'QRCODE,M');
        $grid = $barcode->getBarcodeArray();

        if (empty($grid['num_cols'])) {
            return '';
        }

        $svg = (string) $barcode->getBarcodeSVGcode(4, 4, '#000');
        $svg = preg_replace('/^.*?(<svg)/s', '$1', $svg); // sans l'en-tête XML ni le DOCTYPE
        $box = ' viewBox="0 0 ' . ((int) $grid['num_cols'] * 4) . ' ' . ((int) $grid['num_rows'] * 4) . '"';
        $svg = preg_replace('/\s(width|height)="[^"]*"/', '', $svg, 2);

        return preg_replace('/<svg\b/', '<svg' . $box . ' width="' . (int) $size . '" height="' . (int) $size . '" role="img" aria-label="QR code"', $svg, 1);
    }

    private static function read($user_id, $key)
    {
        $stored = (string) get_user_meta($user_id, $key, true);

        if ($stored === '') {
            return '';
        }

        $plain = Secrets::decrypt($stored);

        return is_string($plain) ? $plain : '';
    }
}
