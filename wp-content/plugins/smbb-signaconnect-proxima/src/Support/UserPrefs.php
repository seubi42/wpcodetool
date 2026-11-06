<?php

namespace Smbb\SignConnectProxima\Support;

defined('ABSPATH') || exit;

/**
 * Préférences personnelles (méta utilisateur WordPress) : notifications par email,
 * signature personnelle (PNG) et mobile.
 */
final class UserPrefs
{
    const NOTIFY = 'scp_notify';
    const SIGNATURE = 'scp_signature';
    const MOBILE = 'scp_mobile';

    /** Taille maximale d'une signature enregistrée (PNG décodé). */
    const SIGNATURE_MAX_BYTES = 400000;

    public static function events()
    {
        return array(
            'signed' => __('Un document est signé', 'smbb-signaconnect-proxima'),
            'refused' => __('Un signataire refuse', 'smbb-signaconnect-proxima'),
            'expired' => __('Un envoi expire sans signature', 'smbb-signaconnect-proxima'),
        );
    }

    /**
     * Événements notifiés par email (tous par défaut).
     *
     * @return string[]
     */
    public static function notifications($user_id)
    {
        $stored = get_user_meta((int) $user_id, self::NOTIFY, true);

        if (!is_array($stored)) {
            return array_keys(self::events());
        }

        return array_values(array_intersect(array_keys(self::events()), $stored));
    }

    public static function wants($user_id, $event)
    {
        return in_array($event, self::notifications($user_id), true);
    }

    public static function saveNotifications($user_id, array $events)
    {
        update_user_meta((int) $user_id, self::NOTIFY, array_values(array_intersect(array_keys(self::events()), $events)));
    }

    public static function hasSignature($user_id)
    {
        return self::signatureBinary($user_id) !== '';
    }

    /**
     * Signature en data URL, pour l'aperçu.
     */
    public static function signatureDataUrl($user_id)
    {
        $binary = self::signatureBinary($user_id);

        return $binary !== '' ? 'data:image/png;base64,' . base64_encode($binary) : '';
    }

    /**
     * Copie temporaire de la signature pour l'écrire dans un PDF ('' si aucune). L'appelant la supprime.
     */
    public static function signatureFile($user_id)
    {
        $binary = self::signatureBinary($user_id);

        if ($binary === '') {
            return '';
        }

        $path = wp_tempnam('scp-sender-signature.png');
        file_put_contents($path, $binary);

        return $path;
    }

    /**
     * Enregistre une signature reçue en data URL PNG (dessinée) ou un fichier image importé.
     * Les images importées sont converties en PNG.
     */
    public static function saveSignature($user_id, $data_url = '', array $file = array())
    {
        $binary = '';

        if ($data_url !== '' && preg_match('#^data:image/png;base64,([A-Za-z0-9+/=]+)$#', $data_url, $match)) {
            $binary = (string) base64_decode($match[1], true);
        } elseif (!empty($file['tmp_name']) && is_uploaded_file($file['tmp_name'])) {
            $binary = self::toPng($file['tmp_name']);
        }

        if ($binary === '' || strncmp($binary, "\x89PNG", 4) !== 0) {
            throw new \InvalidArgumentException(__('Signature illisible : dessinez-la ou importez une image PNG ou JPEG.', 'smbb-signaconnect-proxima'));
        }

        if (strlen($binary) > self::SIGNATURE_MAX_BYTES) {
            throw new \InvalidArgumentException(__('Image trop lourde : 400 Ko maximum.', 'smbb-signaconnect-proxima'));
        }

        $size = @getimagesizefromstring($binary);

        if (!$size || $size[0] < 20 || $size[1] < 10) {
            throw new \InvalidArgumentException(__('Image trop petite pour une signature.', 'smbb-signaconnect-proxima'));
        }

        update_user_meta((int) $user_id, self::SIGNATURE, base64_encode($binary));
    }

    public static function deleteSignature($user_id)
    {
        delete_user_meta((int) $user_id, self::SIGNATURE);
    }

    public static function mobile($user_id)
    {
        return (string) get_user_meta((int) $user_id, self::MOBILE, true);
    }

    public static function saveMobile($user_id, $mobile)
    {
        $mobile = sanitize_text_field((string) $mobile);
        $normalized = \Smbb\SignConnectProxima\Service\Sms::normalizePhone($mobile);
        update_user_meta((int) $user_id, self::MOBILE, mb_substr($normalized !== '' ? $normalized : $mobile, 0, 40));
    }

    private static function signatureBinary($user_id)
    {
        $stored = (string) get_user_meta((int) $user_id, self::SIGNATURE, true);

        return $stored !== '' ? (string) base64_decode($stored, true) : '';
    }

    /**
     * PNG ou JPEG importé → PNG (via GD si besoin).
     */
    private static function toPng($path)
    {
        $info = @getimagesize($path);

        if (!$info) {
            return '';
        }

        if ($info[2] === IMAGETYPE_PNG) {
            return (string) file_get_contents($path);
        }

        if ($info[2] !== IMAGETYPE_JPEG || !function_exists('imagecreatefromjpeg')) {
            return '';
        }

        $image = @imagecreatefromjpeg($path);

        if (!$image) {
            return '';
        }

        // Une signature importée en JPEG a un fond blanc : on le rend transparent.
        $width = imagesx($image);
        $height = imagesy($image);
        $scale = min(1, 900 / max(1, $width));
        $png = imagecreatetruecolor((int) round($width * $scale), (int) round($height * $scale));
        imagealphablending($png, false);
        imagesavealpha($png, true);
        imagefill($png, 0, 0, imagecolorallocatealpha($png, 255, 255, 255, 127));
        imagecopyresampled($png, $image, 0, 0, 0, 0, imagesx($png), imagesy($png), $width, $height);

        for ($x = 0; $x < imagesx($png); $x++) {
            for ($y = 0; $y < imagesy($png); $y++) {
                $rgb = imagecolorat($png, $x, $y);

                if ((($rgb >> 16) & 0xFF) > 235 && (($rgb >> 8) & 0xFF) > 235 && ($rgb & 0xFF) > 235) {
                    imagesetpixel($png, $x, $y, imagecolorallocatealpha($png, 255, 255, 255, 127));
                }
            }
        }

        ob_start();
        imagepng($png, null, 9);
        $binary = (string) ob_get_clean();
        imagedestroy($image);
        imagedestroy($png);

        return $binary;
    }
}
