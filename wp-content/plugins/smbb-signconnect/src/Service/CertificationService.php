<?php

namespace Smbb\SignConnect\Service;

use Smbb\SignConnect\Support\SignConnectSettings;

defined('ABSPATH') || exit;

/**
 * Gère la couche "certification" accessible avec la stack actuelle.
 *
 * Important : ce service prépare un certificat autosigné et des preuves
 * d'intégrité. La signature cryptographique PDF visible par Adobe demande une
 * pile PDF compatible signature, par exemple TCPDF, que FPDF/FPDI seul ne
 * fournit pas.
 */
final class CertificationService
{
    public function generateSelfSignedCertificate(array $data = array())
    {
        if (!function_exists('openssl_pkey_new') || !function_exists('openssl_csr_new') || !function_exists('openssl_csr_sign')) {
            throw new \RuntimeException(__('The OpenSSL PHP extension is required to generate a certificate.', 'smbb-signconnect'));
        }

        $directory = $this->certificateDirectory();
        $common_name = isset($data['common_name']) && trim((string) $data['common_name']) !== ''
            ? sanitize_text_field((string) $data['common_name'])
            : (string) wp_parse_url(home_url('/'), PHP_URL_HOST);
        $organization = isset($data['organization']) && trim((string) $data['organization']) !== ''
            ? sanitize_text_field((string) $data['organization'])
            : get_bloginfo('name');
        $email = isset($data['email']) && is_email((string) $data['email'])
            ? sanitize_email((string) $data['email'])
            : get_option('admin_email');
        $country = isset($data['country']) && preg_match('/^[A-Z]{2}$/', (string) $data['country'])
            ? (string) $data['country']
            : 'FR';
        $days = isset($data['valid_days']) ? max(1, min(3650, absint($data['valid_days']))) : 365;

        $private_key = openssl_pkey_new(array(
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
            'private_key_bits' => 2048,
        ));

        if (!$private_key) {
            throw new \RuntimeException(__('The private key could not be generated.', 'smbb-signconnect'));
        }

        $dn = array(
            'countryName' => $country,
            'organizationName' => $organization,
            'commonName' => $common_name,
            'emailAddress' => $email,
        );
        $csr = openssl_csr_new($dn, $private_key, array('digest_alg' => 'sha256'));

        if (!$csr) {
            throw new \RuntimeException(__('The certificate request could not be generated.', 'smbb-signconnect'));
        }

        $certificate = openssl_csr_sign($csr, null, $private_key, $days, array('digest_alg' => 'sha256'));

        if (!$certificate) {
            throw new \RuntimeException(__('The self-signed certificate could not be generated.', 'smbb-signconnect'));
        }

        $certificate_pem = '';
        $private_key_pem = '';
        openssl_x509_export($certificate, $certificate_pem);
        openssl_pkey_export($private_key, $private_key_pem);

        $certificate_path = $directory . 'signconnect-certificate.pem';
        $private_key_path = $directory . 'signconnect-private.key';

        if (file_put_contents($certificate_path, $certificate_pem) === false || file_put_contents($private_key_path, $private_key_pem) === false) {
            throw new \RuntimeException(__('The certificate files could not be written.', 'smbb-signconnect'));
        }

        @chmod($private_key_path, 0600);
        @chmod($certificate_path, 0640);

        $fingerprint = strtoupper(hash_file('sha256', $certificate_path));
        $settings = SignConnectSettings::all();
        $settings['certification_certificate_mode'] = 'self_signed';
        $settings['certification_certificate_path'] = $certificate_path;
        $settings['certification_private_key_path'] = $private_key_path;
        $settings['certification_private_key_passphrase'] = '';
        $settings['certification_certificate_fingerprint'] = $fingerprint;
        $settings['certification_certificate_subject'] = $common_name;
        $settings['certification_certificate_organization'] = $organization;
        $settings['certification_certificate_email'] = $email;
        $settings['certification_certificate_created_at'] = current_time('mysql');
        $settings['certification_certificate_expires_at'] = gmdate('Y-m-d H:i:s', time() + ($days * DAY_IN_SECONDS));
        $settings['certification_self_signed_certificate_path'] = $certificate_path;
        $settings['certification_self_signed_private_key_path'] = $private_key_path;
        $settings['certification_self_signed_certificate_fingerprint'] = $fingerprint;
        $settings['certification_self_signed_certificate_subject'] = $common_name;
        $settings['certification_self_signed_certificate_organization'] = $organization;
        $settings['certification_self_signed_certificate_email'] = $email;
        $settings['certification_self_signed_certificate_created_at'] = $settings['certification_certificate_created_at'];
        $settings['certification_self_signed_certificate_expires_at'] = $settings['certification_certificate_expires_at'];
        update_option(SignConnectSettings::OPTION_NAME, $settings, false);

        return array(
            'fingerprint' => $fingerprint,
            'certificate_path' => $certificate_path,
            'private_key_path' => $private_key_path,
        );
    }

    public function importExternalCertificate(array $certificate_file, array $private_key_file, $private_key_passphrase = '')
    {
        if (!function_exists('openssl_x509_read') || !function_exists('openssl_pkey_get_private') || !function_exists('openssl_x509_check_private_key')) {
            throw new \RuntimeException(__('The OpenSSL PHP extension is required to import a certificate.', 'smbb-signconnect'));
        }

        $certificate_pem = $this->uploadedFileContents($certificate_file, __('Certificate file', 'smbb-signconnect'));
        $private_key_pem = $this->uploadedFileContents($private_key_file, __('Private key', 'smbb-signconnect'));
        $private_key_passphrase = (string) $private_key_passphrase;

        $certificate = openssl_x509_read($certificate_pem);

        if (!$certificate) {
            throw new \RuntimeException(__('The uploaded certificate is not a valid PEM certificate.', 'smbb-signconnect'));
        }

        $private_key = openssl_pkey_get_private($private_key_pem, $private_key_passphrase);

        if (!$private_key) {
            throw new \RuntimeException(__('The uploaded private key is invalid or its passphrase is wrong.', 'smbb-signconnect'));
        }

        if (!openssl_x509_check_private_key($certificate, $private_key)) {
            throw new \RuntimeException(__('The certificate and private key do not match.', 'smbb-signconnect'));
        }

        $directory = $this->certificateDirectory();
        $certificate_path = $directory . 'signconnect-imported-certificate.pem';
        $private_key_path = $directory . 'signconnect-imported-private.key';

        if (file_put_contents($certificate_path, $certificate_pem) === false || file_put_contents($private_key_path, $private_key_pem) === false) {
            throw new \RuntimeException(__('The imported certificate files could not be written.', 'smbb-signconnect'));
        }

        @chmod($private_key_path, 0600);
        @chmod($certificate_path, 0640);

        $fingerprint = strtoupper(hash_file('sha256', $certificate_path));
        $parsed = openssl_x509_parse($certificate);
        $subject = is_array($parsed) && !empty($parsed['subject']) && is_array($parsed['subject']) ? $parsed['subject'] : array();
        $settings = SignConnectSettings::all();
        $settings['certification_certificate_mode'] = 'imported';
        $settings['certification_certificate_path'] = $certificate_path;
        $settings['certification_private_key_path'] = $private_key_path;
        $settings['certification_private_key_passphrase'] = $private_key_passphrase;
        $settings['certification_certificate_fingerprint'] = $fingerprint;
        $settings['certification_certificate_subject'] = isset($subject['CN']) ? (string) $subject['CN'] : '';
        $settings['certification_certificate_organization'] = isset($subject['O']) ? (string) $subject['O'] : '';
        $settings['certification_certificate_email'] = isset($subject['emailAddress']) ? (string) $subject['emailAddress'] : '';
        $settings['certification_certificate_created_at'] = current_time('mysql');
        $settings['certification_certificate_expires_at'] = is_array($parsed) && !empty($parsed['validTo_time_t'])
            ? gmdate('Y-m-d H:i:s', (int) $parsed['validTo_time_t'])
            : '';
        $settings['certification_imported_certificate_path'] = $certificate_path;
        $settings['certification_imported_private_key_path'] = $private_key_path;
        $settings['certification_imported_private_key_passphrase'] = $private_key_passphrase;
        $settings['certification_imported_certificate_fingerprint'] = $fingerprint;
        $settings['certification_imported_certificate_subject'] = $settings['certification_certificate_subject'];
        $settings['certification_imported_certificate_organization'] = $settings['certification_certificate_organization'];
        $settings['certification_imported_certificate_email'] = $settings['certification_certificate_email'];
        $settings['certification_imported_certificate_created_at'] = $settings['certification_certificate_created_at'];
        $settings['certification_imported_certificate_expires_at'] = $settings['certification_certificate_expires_at'];
        update_option(SignConnectSettings::OPTION_NAME, $settings, false);

        return array(
            'fingerprint' => $fingerprint,
            'certificate_path' => $certificate_path,
            'private_key_path' => $private_key_path,
        );
    }

    public function status()
    {
        $settings = SignConnectSettings::all();
        $certificate_path = SignConnectSettings::certificationCertificatePath();
        $private_key_path = SignConnectSettings::certificationPrivateKeyPath();

        return array(
            'has_certificate' => $certificate_path !== '' && is_readable($certificate_path) && $private_key_path !== '' && is_readable($private_key_path),
            'mode' => SignConnectSettings::certificationCertificateMode(),
            'certificate_path' => $certificate_path,
            'private_key_path' => $private_key_path,
            'certificate_readable' => $certificate_path !== '' && is_readable($certificate_path),
            'private_key_readable' => $private_key_path !== '' && is_readable($private_key_path),
            'fingerprint' => SignConnectSettings::certificationCertificateFingerprint(),
            'subject' => $this->modeSetting($settings, 'certificate_subject'),
            'created_at' => $this->modeSetting($settings, 'certificate_created_at'),
            'expires_at' => $this->modeSetting($settings, 'certificate_expires_at'),
            'tcpdf_available' => $this->tcpdfAvailable(),
        );
    }

    private function modeSetting(array $settings, $suffix)
    {
        $prefix = SignConnectSettings::certificationCertificateMode() === 'imported'
            ? 'certification_imported_'
            : 'certification_self_signed_';
        $key = $prefix . $suffix;

        if (!empty($settings[$key])) {
            return (string) $settings[$key];
        }

        $legacy_key = 'certification_' . $suffix;

        return isset($settings[$legacy_key]) ? (string) $settings[$legacy_key] : '';
    }

    private function uploadedFileContents(array $file, $label)
    {
        if (empty($file) || !isset($file['error'])) {
            throw new \RuntimeException(sprintf(
                /* translators: %s: uploaded file label. */
                __('Missing upload: %s.', 'smbb-signconnect'),
                $label
            ));
        }

        if ((int) $file['error'] !== UPLOAD_ERR_OK) {
            throw new \RuntimeException(sprintf(
                /* translators: 1: uploaded file label, 2: PHP upload error code. */
                __('Upload failed for %1$s. Error code: %2$d.', 'smbb-signconnect'),
                $label,
                (int) $file['error']
            ));
        }

        $temporary_path = isset($file['tmp_name']) ? (string) $file['tmp_name'] : '';

        if ($temporary_path === '' || !is_uploaded_file($temporary_path)) {
            throw new \RuntimeException(sprintf(
                /* translators: %s: uploaded file label. */
                __('Invalid uploaded file: %s.', 'smbb-signconnect'),
                $label
            ));
        }

        $contents = file_get_contents($temporary_path);

        if (!is_string($contents) || trim($contents) === '') {
            throw new \RuntimeException(sprintf(
                /* translators: %s: uploaded file label. */
                __('Uploaded file is empty: %s.', 'smbb-signconnect'),
                $label
            ));
        }

        return $contents;
    }

    private function tcpdfAvailable()
    {
        /*
         * Ne pas appeler class_exists(\setasign\Fpdi\Tcpdf\Fpdi::class) tant
         * que TCPDF n'est pas charge : l'autoloader FPDI inclut alors une classe
         * qui extends \TCPDF et provoque un fatal error si TCPDF est absent.
         */
        if (!class_exists('\TCPDF')) {
            return false;
        }

        return class_exists('\setasign\Fpdi\Tcpdf\Fpdi');
    }

    private function certificateDirectory()
    {
        $upload = wp_upload_dir(null, false);
        $base = !empty($upload['basedir']) ? trailingslashit((string) $upload['basedir']) : trailingslashit(WP_CONTENT_DIR . '/uploads');
        $directory = $base . 'signconnect-certificates/';

        if (!wp_mkdir_p($directory)) {
            throw new \RuntimeException(__('The certificate directory could not be created.', 'smbb-signconnect'));
        }

        if (!file_exists($directory . 'index.php')) {
            file_put_contents($directory . 'index.php', "<?php\n// Silence is golden.\n");
        }

        if (!file_exists($directory . '.htaccess')) {
            file_put_contents($directory . '.htaccess', "Require all denied\nDeny from all\n");
        }

        return trailingslashit($directory);
    }
}
