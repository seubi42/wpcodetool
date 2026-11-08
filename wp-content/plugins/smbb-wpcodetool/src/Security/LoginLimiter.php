<?php

namespace Smbb\WpCodeTool\Security;

defined('ABSPATH') || exit;

/**
 * Limitation des tentatives de connexion : après N échecs en X minutes depuis une même adresse,
 * l'adresse est bloquée Y minutes ; après plusieurs blocages de suite, le blocage passe à Z heures.
 *
 * - échecs en cours (« en surveillance ») : option smbb_codetool_security_failures (fenêtre glissante) ;
 * - blocages : option smbb_codetool_security_lockouts (IP => until, count, user, at, reason),
 *   gardée 24 h après la fin du blocage pour l'escalade, 500 entrées au plus.
 *
 * Les adresses de la liste blanche ne sont jamais bloquées ; celles de la liste noire ne peuvent
 * jamais se connecter. Les codes de double authentification refusés comptent comme des échecs.
 */
final class LoginLimiter
{
    const LOCKOUTS = 'smbb_codetool_security_lockouts';
    const FAILURES = 'smbb_codetool_security_failures';
    const MAX_ENTRIES = 500;

    /** @var bool Blocage déjà signalé pendant cette requête (évite de compter deux fois). */
    private static $blocked_now = false;

    public static function hooks()
    {
        if (!self::enabled()) {
            return;
        }

        // Avant la vérification du mot de passe : une adresse bloquée ne fait même plus tester.
        add_filter('authenticate', array(__CLASS__, 'beforeAuthenticate'), 1, 3);
        // Après tout le monde : message unique et nombre d'essais restants.
        add_filter('authenticate', array(__CLASS__, 'afterAuthenticate'), 9999, 3);
        add_action('wp_login_failed', array(__CLASS__, 'failed'), 10, 2);
        add_action('lostpassword_post', array(__CLASS__, 'lostPassword'), 1);
    }

    public static function enabled()
    {
        return !empty(Security::part('limit')['enabled']);
    }

    // ------------------------------------------------------------------ Connexion

    public static function beforeAuthenticate($user, $username, $password)
    {
        $error = self::blockedError();

        if ($error === null) {
            return $user;
        }

        self::$blocked_now = true;

        // Plus de test du mot de passe tant que l'adresse est bloquée.
        remove_filter('authenticate', 'wp_authenticate_username_password', 20);
        remove_filter('authenticate', 'wp_authenticate_email_password', 20);

        return $error;
    }

    public static function afterAuthenticate($user, $username, $password)
    {
        if (self::$blocked_now) {
            return self::blockedError() ?: $user;
        }

        if (!is_wp_error($user) || !Security::interactiveLogin()) {
            return $user;
        }

        $codes = $user->get_error_codes();
        $credential_errors = array('invalid_username', 'invalid_email', 'incorrect_password');

        if (!array_intersect($codes, $credential_errors)) {
            return $user;
        }

        $limit = Security::part('limit');

        if (empty($limit['generic_errors'])) {
            return $user;
        }

        // Même message que le compte existe ou non : on ne laisse pas deviner les identifiants.
        // L'échec de cette tentative n'est pas encore compté (wp_login_failed vient après) : d'où le -1.
        $left = max(0, (int) $limit['attempts'] - self::failures(Security::ip()) - 1);
        $message = __('<strong>Erreur :</strong> identifiant ou mot de passe incorrect.', 'smbb-wpcodetool');

        if (!Security::whitelisted() && $left > 0 && $left <= 3) {
            $message .= ' ' . sprintf(_n('Encore %d essai avant blocage.', 'Encore %d essais avant blocage.', $left, 'smbb-wpcodetool'), $left);
        }

        $message .= ' <a href="' . esc_url(wp_lostpassword_url()) . '">' . esc_html__('Mot de passe oublié ?', 'smbb-wpcodetool') . '</a>';

        return new \WP_Error('incorrect_password', $message);
    }

    /**
     * Échec de connexion (wp_login_failed) : compte l'échec, bloque au-delà du seuil.
     */
    public static function failed($username, $error = null)
    {
        if (self::$blocked_now) {
            return;
        }

        // Champs laissés vides : pas une tentative.
        if ($error instanceof \WP_Error && array_intersect($error->get_error_codes(), array('empty_username', 'empty_password'))) {
            return;
        }

        self::recordFailure((string) $username, 'login');
    }

    /**
     * Ajoute un échec pour l'adresse courante (connexion, code de double authentification…).
     */
    public static function recordFailure($username, $reason = 'login')
    {
        $ip = Security::ip();

        if (Security::whitelisted($ip)) {
            return;
        }

        $limit = Security::part('limit');
        $all = self::watched();
        $entry = isset($all[$ip]) ? $all[$ip] : array('times' => array());
        $entry['times'][] = time();
        $entry['user'] = sanitize_user((string) $username);
        $entry['reason'] = (string) $reason;

        if (count($entry['times']) >= max(1, (int) $limit['attempts'])) {
            unset($all[$ip]);
            self::storeWatched($all);
            self::lock($ip, $username, $reason);

            return;
        }

        $all[$ip] = $entry;
        self::storeWatched($all);
    }

    /**
     * Adresses « en surveillance » : des échecs récents, pas encore assez pour être bloquées.
     * IP => times (horodatages dans la fenêtre), user (dernier identifiant), reason. Les plus récentes d'abord.
     *
     * @return array<string,array{times:int[],user:string,reason:string}>
     */
    public static function watched()
    {
        $all = get_option(self::FAILURES, array());
        $all = is_array($all) ? $all : array();
        $window = max(1, (int) Security::part('limit')['window']) * MINUTE_IN_SECONDS;
        $since = time() - $window;
        $clean = array();

        foreach ($all as $ip => $entry) {
            if (!is_array($entry) || empty($entry['times'])) {
                continue;
            }

            $times = array_values(array_filter(array_map('intval', (array) $entry['times']), function ($time) use ($since) {
                return $time > $since;
            }));

            if ($times) {
                $clean[$ip] = array(
                    'times' => $times,
                    'user' => isset($entry['user']) ? (string) $entry['user'] : '',
                    'reason' => isset($entry['reason']) ? (string) $entry['reason'] : 'login',
                );
            }
        }

        uasort($clean, function ($a, $b) {
            return max($b['times']) - max($a['times']);
        });

        return $clean;
    }

    private static function storeWatched(array $all)
    {
        update_option(self::FAILURES, array_slice($all, 0, self::MAX_ENTRIES, true), false);
    }

    /**
     * Nombre d'échecs récents de l'adresse.
     */
    public static function failures($ip)
    {
        $all = self::watched();

        return isset($all[$ip]) ? count($all[$ip]['times']) : 0;
    }

    /**
     * Remet à zéro les échecs d'une adresse (ou de toutes avec null).
     */
    public static function clearFailures($ip = null)
    {
        $all = self::watched();

        if ($ip === null) {
            $count = count($all);
            $all = array();
        } else {
            $count = isset($all[$ip]) ? 1 : 0;
            unset($all[$ip]);
        }

        if ($count > 0) {
            self::storeWatched($all);
        }

        return $count;
    }

    /**
     * Fiche publique de réputation de l'adresse (AbuseIPDB), pour étudier le cas.
     */
    public static function reputationUrl($ip)
    {
        return (string) apply_filters('smbb_wpcodetool_security_ip_check_url', 'https://www.abuseipdb.com/check/' . rawurlencode((string) $ip), $ip);
    }

    // ------------------------------------------------------------------ Mot de passe perdu

    /**
     * Mot de passe perdu depuis une adresse bloquée : refusé.
     *
     * @param \WP_Error $errors
     */
    public static function lostPassword($errors)
    {
        if (!Security::lostPasswordForm()) {
            return;
        }

        $error = self::blockedError();

        if ($error !== null && $errors instanceof \WP_Error) {
            $errors->add($error->get_error_code(), $error->get_error_message());
        }
    }

    // ------------------------------------------------------------------ Blocages

    /**
     * Erreur à renvoyer si l'adresse courante est bloquée, sinon null.
     *
     * @return \WP_Error|null
     */
    public static function blockedError()
    {
        $ip = Security::ip();

        if (Security::blacklisted($ip)) {
            return new \WP_Error('smbb_ip_blacklisted', __('<strong>Connexion refusée :</strong> cette adresse n\'est pas autorisée à se connecter.', 'smbb-wpcodetool'));
        }

        $lock = self::lockOf($ip);

        if ($lock === null || Security::whitelisted($ip)) {
            return null;
        }

        $minutes = max(1, (int) ceil(($lock['until'] - time()) / MINUTE_IN_SECONDS));
        $wait = $minutes >= 120
            ? sprintf(_n('%d heure', '%d heures', (int) ceil($minutes / 60), 'smbb-wpcodetool'), (int) ceil($minutes / 60))
            : sprintf(_n('%d minute', '%d minutes', $minutes, 'smbb-wpcodetool'), $minutes);

        return new \WP_Error(
            'smbb_ip_locked',
            sprintf(
                /* translators: %s: durée (« 25 minutes ») */
                __('<strong>Trop de tentatives :</strong> les connexions depuis votre adresse sont bloquées pendant encore %s.', 'smbb-wpcodetool'),
                $wait
            )
        );
    }

    /**
     * Blocage en cours de l'adresse, ou null.
     */
    public static function lockOf($ip)
    {
        $all = self::all();

        return isset($all[$ip]) && (int) $all[$ip]['until'] > time() ? $all[$ip] : null;
    }

    /**
     * Tous les blocages connus (en cours et récents), IP => données.
     *
     * @return array<string,array{until:int,count:int,user:string,at:int,reason:string}>
     */
    public static function all()
    {
        $all = get_option(self::LOCKOUTS, array());

        return is_array($all) ? $all : array();
    }

    /**
     * Blocages en cours, les plus récents d'abord.
     */
    public static function active()
    {
        $now = time();
        $active = array_filter(self::all(), function ($lock) use ($now) {
            return (int) $lock['until'] > $now;
        });
        uasort($active, function ($a, $b) {
            return (int) $b['at'] - (int) $a['at'];
        });

        return $active;
    }

    private static function lock($ip, $username, $reason)
    {
        $limit = Security::part('limit');
        $all = self::all();
        $previous = isset($all[$ip]) ? $all[$ip] : null;
        // Escalade : blocages successifs (le précédent a fini il y a moins de 24 h).
        $count = $previous && (int) $previous['until'] > time() - DAY_IN_SECONDS ? (int) $previous['count'] + 1 : 1;
        $long = (int) $limit['long_after'] > 0 && $count >= (int) $limit['long_after'];
        $duration = $long
            ? max(1, (int) $limit['long_hours']) * HOUR_IN_SECONDS
            : max(1, (int) $limit['lockout']) * MINUTE_IN_SECONDS;

        $all[$ip] = array(
            'until' => time() + $duration,
            'count' => $count,
            'user' => sanitize_user((string) $username),
            'at' => time(),
            'reason' => (string) $reason,
        );

        self::store($all);

        Security::log('login.locked', $long ? 'critical' : 'warning', $long
            ? sprintf(__('Adresse %1$s bloquée %2$d h (blocage n° %3$d)', 'smbb-wpcodetool'), $ip, (int) $limit['long_hours'], $count)
            : sprintf(__('Adresse %1$s bloquée %2$d min après trop d\'échecs', 'smbb-wpcodetool'), $ip, (int) $limit['lockout']), array(
            'object_type' => 'ip',
            'object_id' => $ip,
            'context' => array('username' => (string) $username, 'reason' => $reason, 'count' => $count, 'until' => $all[$ip]['until']),
        ));

        if ($long && !empty($limit['notify'])) {
            self::notify($ip, $all[$ip]);
        }
    }

    private static function store(array $all)
    {
        $now = time();

        // Ménage : on garde 24 h après la fin du blocage (escalade), puis on oublie.
        $all = array_filter($all, function ($lock) use ($now) {
            return is_array($lock) && (int) $lock['until'] > $now - DAY_IN_SECONDS;
        });

        if (count($all) > self::MAX_ENTRIES) {
            uasort($all, function ($a, $b) {
                return (int) $b['at'] - (int) $a['at'];
            });
            $all = array_slice($all, 0, self::MAX_ENTRIES, true);
        }

        update_option(self::LOCKOUTS, $all, false);
    }

    public static function unlock($ip)
    {
        $all = self::all();

        if (!isset($all[$ip])) {
            return false;
        }

        unset($all[$ip]);
        self::store($all);
        self::clearFailures($ip);

        return true;
    }

    public static function unlockAll()
    {
        $count = count(self::active());
        update_option(self::LOCKOUTS, array(), false);

        return $count;
    }

    private static function notify($ip, array $lock)
    {
        $to = get_option('admin_email');

        if (!is_email($to)) {
            return;
        }

        $site = wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES);
        $body = sprintf(
            /* translators: 1: site, 2: IP, 3: compte, 4: nombre, 5: date de fin, 6: lien */
            __("Bonjour,\n\nSur %1\$s, l'adresse %2\$s a été bloquée longtemps après des échecs de connexion répétés.\n\nDernier identifiant essayé : %3\$s\nBlocages de suite : %4\$d\nBloquée jusqu'au : %5\$s\n\nSi c'est vous ou un collègue, débloquez l'adresse ici : %6\$s\n", 'smbb-wpcodetool'),
            $site,
            $ip,
            $lock['user'] !== '' ? $lock['user'] : '—',
            (int) $lock['count'],
            wp_date(get_option('date_format') . ' H:i', (int) $lock['until']),
            admin_url('admin.php?page=smbb-wpcodetool-security-blocked')
        );

        wp_mail($to, sprintf(__('[%s] Adresse bloquée après des tentatives de connexion', 'smbb-wpcodetool'), $site), $body);
    }
}
