<?php

namespace Smbb\WpCodeTool\Security;

defined('ABSPATH') || exit;

/**
 * Cloudflare Turnstile (le captcha sans puzzle) sur la connexion, le mot de passe perdu et
 * l'inscription, côté wp-login.php et côté « Mon compte » de WooCommerce.
 *
 * Vérification serveur : https://challenges.cloudflare.com/turnstile/v0/siteverify.
 * Garde-fou : si Cloudflare répond que la configuration est fausse (clé secrète invalide…) ou ne
 * répond pas, on laisse passer et on le note (CodeTool › Sécurité › Turnstile) plutôt que de
 * bloquer tout le monde, admin compris.
 *
 * Clés de test Cloudflare : site 1x00000000000000000000AA / secrète 1x0000000000000000000000000000000AA
 * (toujours valide), site 2x00000000000000000000AB (toujours refusé).
 */
final class Turnstile
{
    const SCRIPT = 'https://challenges.cloudflare.com/turnstile/v0/api.js';
    const VERIFY = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';
    const FIELD = 'cf-turnstile-response';

    /** @var array<string,true|\WP_Error> Résultat par jeton (un jeton ne se vérifie qu'une fois). */
    private static $verified = array();

    public static function hooks()
    {
        if (!self::active()) {
            return;
        }

        $s = Security::part('turnstile');

        add_action('login_enqueue_scripts', array(__CLASS__, 'enqueue'));
        add_filter('login_body_class', array(__CLASS__, 'bodyClass'));

        if (!empty($s['login'])) {
            add_action('login_form', array(__CLASS__, 'widget'));
            add_filter('authenticate', array(__CLASS__, 'checkLogin'), 30, 3);
        }

        if (!empty($s['lostpassword'])) {
            add_action('lostpassword_form', array(__CLASS__, 'widget'));
            add_action('lostpassword_post', array(__CLASS__, 'checkLostPassword'), 5);
        }

        if (!empty($s['register'])) {
            add_action('register_form', array(__CLASS__, 'widget'));
            add_filter('registration_errors', array(__CLASS__, 'checkRegistration'), 10, 3);
        }

        if (!empty($s['woocommerce'])) {
            add_action('wp_enqueue_scripts', array(__CLASS__, 'enqueueFront'));

            if (!empty($s['login'])) {
                add_action('woocommerce_login_form', array(__CLASS__, 'widget'));
            }

            if (!empty($s['lostpassword'])) {
                add_action('woocommerce_lostpassword_form', array(__CLASS__, 'widget'));
            }

            if (!empty($s['register'])) {
                add_action('woocommerce_register_form', array(__CLASS__, 'widget'));
                add_filter('woocommerce_registration_errors', array(__CLASS__, 'checkWooRegistration'), 10, 3);
            }
        }
    }

    /**
     * Activé et configuré (clé du site + clé secrète lisible).
     */
    public static function active()
    {
        $s = Security::part('turnstile');
        $secret = Security::turnstileSecret();

        return !empty($s['enabled']) && trim((string) $s['site_key']) !== '' && is_string($secret) && $secret !== '';
    }

    public static function enqueue()
    {
        wp_enqueue_script('smbb-turnstile', self::SCRIPT, array(), null, array('strategy' => 'defer', 'in_footer' => false));
    }

    public static function enqueueFront()
    {
        if (function_exists('is_account_page') && is_account_page() && !is_user_logged_in()) {
            self::enqueue();
        }
    }

    public static function bodyClass($classes)
    {
        $classes[] = 'smbb-has-turnstile';

        return $classes;
    }

    public static function widget()
    {
        $s = Security::part('turnstile');
        $theme = in_array($s['theme'], array('auto', 'light', 'dark'), true) ? $s['theme'] : 'auto';
        ?>
        <div class="smbb-turnstile" style="margin:0 0 16px;">
            <div class="cf-turnstile" data-sitekey="<?php echo esc_attr(trim((string) $s['site_key'])); ?>" data-theme="<?php echo esc_attr($theme); ?>" data-size="flexible" data-language="<?php echo esc_attr(substr(determine_locale(), 0, 2)); ?>"></div>
        </div>
        <style>.login.smbb-has-turnstile #login{width:348px}.smbb-turnstile .cf-turnstile{min-height:65px}</style>
        <?php
    }

    // ------------------------------------------------------------------ Contrôles

    public static function checkLogin($user, $username, $password)
    {
        // Formulaires seulement : XML-RPC, API REST et mots de passe d'application ne sont pas concernés.
        $context = Security::interactiveLogin();

        if (!$context || ($context === 'woocommerce' && empty(Security::part('turnstile')['woocommerce']))) {
            return $user;
        }

        // Déjà en erreur (adresse bloquée…) : rien à ajouter.
        if (is_wp_error($user) && in_array($user->get_error_code(), array('smbb_ip_locked', 'smbb_ip_blacklisted'), true)) {
            return $user;
        }

        $check = self::verify('login', (string) $username);

        return $check === true ? $user : $check;
    }

    /**
     * @param \WP_Error $errors
     */
    public static function checkLostPassword($errors)
    {
        // Seulement le formulaire public (pas « Envoyer le lien de réinitialisation » de l'admin).
        if (!Security::lostPasswordForm()) {
            return;
        }

        $check = self::verify('lostpassword');

        if ($check !== true && $errors instanceof \WP_Error) {
            $errors->add($check->get_error_code(), $check->get_error_message());
        }
    }

    public static function checkRegistration($errors, $login, $email)
    {
        $check = self::verify('register', (string) $login);

        if ($check !== true && $errors instanceof \WP_Error) {
            $errors->add($check->get_error_code(), $check->get_error_message());
        }

        return $errors;
    }

    public static function checkWooRegistration($errors, $login, $email)
    {
        // Formulaire d'inscription de « Mon compte » seulement : pas la création de compte au paiement.
        if (!isset($_POST['woocommerce-register-nonce'])) { // phpcs:ignore WordPress.Security.NonceVerification
            return $errors;
        }

        return self::checkRegistration($errors, $login, $email);
    }

    /**
     * Vérifie le jeton envoyé avec le formulaire.
     *
     * @return true|\WP_Error
     */
    public static function verify($form, $username = '')
    {
        $token = isset($_POST[self::FIELD]) ? sanitize_text_field(wp_unslash($_POST[self::FIELD])) : ''; // phpcs:ignore WordPress.Security.NonceVerification

        if ($token === '') {
            return self::refuse($form, $username, 'missing-input-response');
        }

        if (isset(self::$verified[$token])) {
            return self::$verified[$token];
        }

        $response = wp_remote_post(self::VERIFY, array(
            'timeout' => 8,
            'body' => array(
                'secret' => (string) Security::turnstileSecret(),
                'response' => $token,
                'remoteip' => Security::ip(),
                'idempotency_key' => wp_generate_uuid4(),
            ),
        ));

        if (is_wp_error($response) || (int) wp_remote_retrieve_response_code($response) >= 500) {
            // Cloudflare injoignable : on ne ferme pas la porte à tout le monde.
            self::remember(is_wp_error($response) ? $response->get_error_message() : 'HTTP ' . wp_remote_retrieve_response_code($response));

            return self::$verified[$token] = true;
        }

        $data = json_decode((string) wp_remote_retrieve_body($response), true);
        $data = is_array($data) ? $data : array();

        if (!empty($data['success'])) {
            return self::$verified[$token] = true;
        }

        $codes = isset($data['error-codes']) ? array_map('strval', (array) $data['error-codes']) : array('unknown');

        // Erreur de configuration (clé secrète fausse…) : on laisse passer et on le signale à l'admin.
        if (array_intersect($codes, array('missing-input-secret', 'invalid-input-secret', 'internal-error'))) {
            self::remember(implode(', ', $codes));

            return self::$verified[$token] = true;
        }

        return self::$verified[$token] = self::refuse($form, $username, implode(', ', $codes));
    }

    private static function refuse($form, $username, $codes)
    {
        $labels = array(
            'login' => __('connexion', 'smbb-wpcodetool'),
            'lostpassword' => __('mot de passe perdu', 'smbb-wpcodetool'),
            'register' => __('inscription', 'smbb-wpcodetool'),
        );

        Security::log('login.turnstile_failed', 'notice', sprintf(__('Vérification anti-robot refusée (%s)', 'smbb-wpcodetool'), isset($labels[$form]) ? $labels[$form] : $form), array(
            'context' => array('form' => $form, 'username' => (string) $username, 'codes' => (string) $codes),
        ));

        return new \WP_Error('smbb_turnstile', $codes === 'missing-input-response'
            ? __('<strong>Vérification anti-robot :</strong> attendez que la case soit validée (coche verte) avant d\'envoyer le formulaire.', 'smbb-wpcodetool')
            : __('<strong>Vérification anti-robot échouée :</strong> rechargez la page et réessayez.', 'smbb-wpcodetool'));
    }

    /**
     * Note la dernière erreur de configuration (au plus une écriture par heure).
     */
    private static function remember($error)
    {
        $s = Security::part('turnstile');

        if ((string) $s['last_error'] === (string) $error && (int) $s['last_error_at'] > time() - HOUR_IN_SECONDS) {
            return;
        }

        $s['last_error'] = (string) $error;
        $s['last_error_at'] = time();
        Security::savePart('turnstile', $s);
    }

    public static function clearError()
    {
        $s = Security::part('turnstile');
        $s['last_error'] = '';
        $s['last_error_at'] = 0;
        Security::savePart('turnstile', $s);
    }
}
