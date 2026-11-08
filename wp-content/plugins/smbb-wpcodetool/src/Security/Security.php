<?php

namespace Smbb\WpCodeTool\Security;

use Smbb\WpCodeTool\Support\Secrets;

defined('ABSPATH') || exit;

/**
 * Sécurité de la connexion (CodeTool › Sécurité) : Cloudflare Turnstile, limitation des tentatives
 * avec blocage d'IP, double authentification par code (e-mail ou SMS).
 *
 * Option : smbb_codetool_security (turnstile, limit, twofa). La clé secrète Turnstile est chiffrée
 * par Support\Secrets et listée dans CodeTool › Chiffrement.
 *
 * Accès de secours : define('SMBB_SECURITY_OFF', true); dans wp-config.php coupe tout le module
 * (Turnstile, blocages, double authentification) le temps de reprendre la main.
 */
final class Security
{
    const OPTION = 'smbb_codetool_security';
    const OFF_CONSTANT = 'SMBB_SECURITY_OFF';
    const TURNSTILE_SECRET_CONSTANT = 'SMBB_TURNSTILE_SECRET';

    /** @var array|null */
    private static $settings = null;

    public static function hooks()
    {
        // Widget d'accueil et compteurs : toujours là, même module coupé (il le signale).
        SecurityWidget::hooks();

        if (self::off()) {
            return;
        }

        LoginLimiter::hooks();
        Turnstile::hooks();
        TwoFactor::hooks();
    }

    /**
     * Module coupé par la constante de secours.
     */
    public static function off()
    {
        return defined(self::OFF_CONSTANT) && constant(self::OFF_CONSTANT);
    }

    public static function defaults()
    {
        return array(
            'turnstile' => array(
                'enabled' => 0,
                'site_key' => '',
                'secret' => '',
                'login' => 1,
                'lostpassword' => 1,
                'register' => 1,
                'woocommerce' => 1,
                'theme' => 'auto',
                // Dernière erreur de configuration renvoyée par Cloudflare (clé invalide…).
                'last_error' => '',
                'last_error_at' => 0,
            ),
            'limit' => array(
                'enabled' => 1,
                'attempts' => 5,
                'window' => 15,
                'lockout' => 30,
                'long_after' => 3,
                'long_hours' => 24,
                'ip_source' => 'remote_addr',
                'whitelist' => '',
                'blacklist' => '',
                'generic_errors' => 1,
                'notify' => 0,
            ),
            'twofa' => array(
                'enabled' => 0,
                // Par rôle : channel off | sms | email | app, trust = heures de confiance compte + IP (0 = code à chaque connexion).
                'roles' => array(
                    'administrator' => array('channel' => 'email', 'trust' => 24),
                ),
                'code_ttl' => 10,
                'block_xmlrpc' => 1,
                // Aucun code n'a pu partir (SMS puis e-mail) : 1 = on laisse entrer sans code, 0 = on bloque.
                'fail_open' => 1,
            ),
        );
    }

    public static function settings()
    {
        if (self::$settings !== null) {
            return self::$settings;
        }

        $stored = get_option(self::OPTION, array());
        $stored = is_array($stored) ? $stored : array();
        $settings = self::defaults();

        foreach ($settings as $part => $values) {
            if (isset($stored[$part]) && is_array($stored[$part])) {
                $settings[$part] = array_merge($values, $stored[$part]);
            }
        }

        if (!is_array($settings['twofa']['roles'])) {
            $settings['twofa']['roles'] = array();
        }

        // Réglages par rôle nettoyés : seulement des rôles valides, canal et durée connus.
        $roles = array();

        foreach ($settings['twofa']['roles'] as $role => $rule) {
            if (!is_string($role) || !is_array($rule)) {
                continue;
            }

            $channel = isset($rule['channel']) ? (string) $rule['channel'] : 'off';
            $trust = isset($rule['trust']) ? (int) $rule['trust'] : 24;
            $roles[$role] = array(
                'channel' => isset(self::channels()[$channel]) ? $channel : 'off',
                'trust' => isset(self::trustChoices()[$trust]) ? $trust : 24,
            );
        }

        $settings['twofa']['roles'] = $roles;

        self::$settings = $settings;

        return $settings;
    }

    public static function part($part)
    {
        $settings = self::settings();

        return isset($settings[$part]) ? $settings[$part] : array();
    }

    /**
     * Remplace une partie des réglages (turnstile, limit ou twofa).
     */
    public static function savePart($part, array $values)
    {
        $settings = self::settings();
        $settings[$part] = array_merge(self::defaults()[$part], $values);
        update_option(self::OPTION, $settings, true);
        self::$settings = null;
    }

    /**
     * Canaux de la double authentification, réglés par rôle.
     */
    public static function channels()
    {
        return array(
            'off' => __('Non', 'smbb-wpcodetool'),
            'sms' => __('SMS', 'smbb-wpcodetool'),
            'email' => __('E-mail', 'smbb-wpcodetool'),
            'app' => __('Application (Authenticator)', 'smbb-wpcodetool'),
        );
    }

    /**
     * Durées de confiance compte + IP (en heures), réglées par rôle.
     */
    public static function trustChoices()
    {
        return array(
            0 => __('Off : code à chaque connexion', 'smbb-wpcodetool'),
            24 => __('24 heures', 'smbb-wpcodetool'),
            168 => __('7 jours', 'smbb-wpcodetool'),
        );
    }

    /**
     * Clé secrète Turnstile en clair ('' si aucune, null si illisible : clé de chiffrement changée).
     */
    public static function turnstileSecret()
    {
        if (defined(self::TURNSTILE_SECRET_CONSTANT)) {
            return (string) constant(self::TURNSTILE_SECRET_CONSTANT);
        }

        $stored = (string) self::part('turnstile')['secret'];

        return $stored === '' ? '' : Secrets::decrypt($stored);
    }

    public static function protect($plain)
    {
        $plain = trim(str_replace(array("\r", "\n", "\0"), '', (string) $plain));

        return $plain === '' ? '' : Secrets::encrypt($plain);
    }

    // ------------------------------------------------------------------ Adresse IP

    /**
     * Sources possibles de l'adresse du visiteur.
     */
    public static function ipSources()
    {
        return array(
            'remote_addr' => __('Adresse directe (REMOTE_ADDR) : le bon choix sans proxy', 'smbb-wpcodetool'),
            'cloudflare' => __('Site derrière Cloudflare (en-tête CF-Connecting-IP)', 'smbb-wpcodetool'),
            'forwarded' => __('Derrière un proxy ou un répartiteur (1re adresse de X-Forwarded-For)', 'smbb-wpcodetool'),
        );
    }

    /**
     * Adresse IP du visiteur, selon la source choisie. Un en-tête n'est lu que si on l'a demandé :
     * sans proxy devant le site, n'importe qui pourrait l'inventer pour échapper au blocage.
     */
    public static function ip()
    {
        $source = (string) self::part('limit')['ip_source'];
        $ip = '';

        if ($source === 'cloudflare' && !empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
            $ip = trim((string) wp_unslash($_SERVER['HTTP_CF_CONNECTING_IP']));
        } elseif ($source === 'forwarded' && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $parts = explode(',', (string) wp_unslash($_SERVER['HTTP_X_FORWARDED_FOR']));
            $ip = trim($parts[0]);
        }

        if ($ip === '' || !filter_var($ip, FILTER_VALIDATE_IP)) {
            $ip = isset($_SERVER['REMOTE_ADDR']) ? trim((string) wp_unslash($_SERVER['REMOTE_ADDR'])) : '';
        }

        $ip = filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '0.0.0.0';

        return (string) apply_filters('smbb_wpcodetool_security_ip', $ip);
    }

    /**
     * Liste d'adresses (une par ligne, # pour commenter, plages CIDR acceptées) nettoyée.
     *
     * @return string[]
     */
    public static function ipList($text)
    {
        $list = array();

        foreach (preg_split('/[\r\n,;]+/', (string) $text) as $line) {
            $line = trim(preg_replace('/#.*$/', '', $line));

            if ($line !== '' && self::validRange($line)) {
                $list[] = $line;
            }
        }

        return array_values(array_unique($list));
    }

    public static function validRange($range)
    {
        $parts = explode('/', (string) $range, 2);

        if (!filter_var($parts[0], FILTER_VALIDATE_IP)) {
            return false;
        }

        if (!isset($parts[1])) {
            return true;
        }

        $max = strpos($parts[0], ':') !== false ? 128 : 32;

        return ctype_digit($parts[1]) && (int) $parts[1] <= $max;
    }

    /**
     * L'adresse fait-elle partie de la liste (adresses seules ou plages CIDR, IPv4 et IPv6) ?
     */
    public static function ipInList($ip, array $list)
    {
        $packed = @inet_pton($ip);

        if ($packed === false) {
            return false;
        }

        foreach ($list as $range) {
            $parts = explode('/', $range, 2);
            $net = @inet_pton($parts[0]);

            if ($net === false || strlen($net) !== strlen($packed)) {
                continue;
            }

            $bits = isset($parts[1]) ? (int) $parts[1] : strlen($packed) * 8;
            $bytes = intdiv($bits, 8);
            $rest = $bits % 8;

            if ($bytes > 0 && substr($packed, 0, $bytes) !== substr($net, 0, $bytes)) {
                continue;
            }

            if ($rest > 0) {
                $mask = (0xFF << (8 - $rest)) & 0xFF;

                if ((ord($packed[$bytes]) & $mask) !== (ord($net[$bytes]) & $mask)) {
                    continue;
                }
            }

            return true;
        }

        return false;
    }

    public static function whitelisted($ip = null)
    {
        $ip = $ip === null ? self::ip() : $ip;

        return self::ipInList($ip, self::ipList(self::part('limit')['whitelist']));
    }

    public static function blacklisted($ip = null)
    {
        $ip = $ip === null ? self::ip() : $ip;

        return !self::whitelisted($ip) && self::ipInList($ip, self::ipList(self::part('limit')['blacklist']));
    }

    // ------------------------------------------------------------------ Contexte

    /**
     * Connexion faite depuis un formulaire (wp-login.php ou « Mon compte » WooCommerce),
     * et pas par XML-RPC, l'API REST ou un mot de passe d'application.
     */
    public static function interactiveLogin()
    {
        if (defined('XMLRPC_REQUEST') || (defined('REST_REQUEST') && REST_REQUEST) || wp_doing_ajax()) {
            return false;
        }

        // phpcs:disable WordPress.Security.NonceVerification
        if (isset($_POST['log'], $_POST['pwd'])) {
            return 'wp';
        }

        if (isset($_POST['woocommerce-login-nonce'], $_POST['username'], $_POST['password'])) {
            return 'woocommerce';
        }
        // phpcs:enable

        return false;
    }

    /**
     * Demande « mot de passe perdu » envoyée par un visiteur (wp-login.php ou WooCommerce),
     * et non par un admin depuis la liste des comptes.
     */
    public static function lostPasswordForm()
    {
        if (is_admin() && !wp_doing_ajax()) {
            return false;
        }

        return isset($_POST['user_login']) && !is_user_logged_in(); // phpcs:ignore WordPress.Security.NonceVerification
    }

    /**
     * Écrit dans le journal d'activité de CodeTool (sans effet s'il n'est pas installé).
     */
    public static function log($event, $level, $message, array $extra = array())
    {
        $category = strpos($event, 'security.') === 0 ? 'security' : 'login';
        $data = array_merge(array(
            'category' => $category,
            'level' => $level,
            'message' => $message,
        ), $extra);

        if (!isset($data['context']) || !is_array($data['context'])) {
            $data['context'] = array();
        }

        $data['context']['ip'] = self::ip();

        do_action('smbb_wpcodetool_activity', $event, $data);
    }

    /**
     * Masque un e-mail : s•••@d•••.fr
     */
    public static function maskEmail($email)
    {
        $email = (string) $email;
        $at = strpos($email, '@');

        if ($at === false) {
            return $email;
        }

        $name = substr($email, 0, $at);
        $domain = substr($email, $at + 1);
        $dot = strrpos($domain, '.');
        $host = $dot === false ? $domain : substr($domain, 0, $dot);
        $tld = $dot === false ? '' : substr($domain, $dot);

        return substr($name, 0, 1) . '•••@' . substr($host, 0, 1) . '•••' . $tld;
    }

    /**
     * Masque un numéro : •• •• •• 12 34
     */
    public static function maskPhone($phone)
    {
        $digits = preg_replace('/\D+/', '', (string) $phone);

        return strlen($digits) < 4 ? '••••' : '•• •• •• ' . substr($digits, -4, 2) . ' ' . substr($digits, -2);
    }
}
