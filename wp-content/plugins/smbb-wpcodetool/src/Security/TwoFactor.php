<?php

namespace Smbb\WpCodeTool\Security;

use Smbb\WpCodeTool\Sms\Sms;
use Smbb\WpCodeTool\Support\UserLookup;

defined('ABSPATH') || exit;

/**
 * Double authentification par code à usage unique, envoyé par e-mail (module E-mails de CodeTool)
 * ou par SMS (Sms::send()), ou lu dans une application d'authentification (TOTP, voir Totp), pour
 * les rôles choisis dans CodeTool › Sécurité › Double authentification.
 *
 * Application : chaque compte l'active depuis son profil (QR code + premier code). Un compte qui l'a
 * activée l'utilise toujours, quel que soit le canal de son rôle. Un rôle réglé sur « Application »
 * dont le compte ne l'a pas encore activée reçoit le code par e-mail, avec un rappel dans l'admin.
 *
 * Déroulé : mot de passe bon → pas de cookie de session, un code part et le navigateur va sur
 * wp-login.php?action=smbb_2fa. Code bon → session ouverte, et le couple compte + adresse IP
 * est reconnu pendant 24 h (réglable) : pas de nouveau code depuis cette adresse.
 *
 * Secours : codes de secours à usage unique (générés par chaque compte dans la page de réglages),
 * et la constante SMBB_SECURITY_OFF qui coupe tout le module.
 *
 * Filtre smbb_wpcodetool_2fa_required ($required, WP_User) pour forcer ou dispenser un compte.
 */
final class TwoFactor
{
    const ACTION = 'smbb_2fa';
    const TRUST_META = 'smbb_2fa_trust';
    const BACKUP_META = 'smbb_2fa_backup';
    const MAX_ATTEMPTS = 5;
    const MAX_SENDS = 5;
    const RESEND_DELAY = 30;
    const BACKUP_COUNT = 8;
    const STRICTNESS = array('email' => 1, 'sms' => 2, 'app' => 3);

    public static function hooks()
    {
        // Activation de l'application depuis le profil : possible même module coupé (on prépare).
        add_action('show_user_profile', array(__CLASS__, 'profileSection'), 5);
        add_action('edit_user_profile', array(__CLASS__, 'profileSection'), 5);
        add_action('user_profile_update_errors', array(__CLASS__, 'profileSave'), 10, 3);

        if (empty(Security::part('twofa')['enabled'])) {
            return;
        }

        add_action('admin_notices', array(__CLASS__, 'appReminder'));

        // Après le mot de passe (20), Turnstile (30) ; avant la mise en forme des erreurs (9999).
        add_filter('authenticate', array(__CLASS__, 'intercept'), 100, 3);
        add_action('login_form_' . self::ACTION, array(__CLASS__, 'screen'));
        add_filter('wp_login_errors', array(__CLASS__, 'loginErrors'));
    }

    // ------------------------------------------------------------------ Qui est concerné

    /**
     * Règle du compte, d'après ses rôles : canal le plus strict (app, puis sms, puis email) et durée de confiance
     * la plus courte parmi ses rôles protégés. null si aucun de ses rôles n'est protégé.
     *
     * @return array{channel:string,trust:int}|null
     */
    public static function ruleFor(\WP_User $user)
    {
        $roles = (array) Security::part('twofa')['roles'];
        $rule = null;

        foreach ((array) $user->roles as $role) {
            if (!isset($roles[$role]) || $roles[$role]['channel'] === 'off') {
                continue;
            }

            if ($rule === null) {
                $rule = $roles[$role];
                continue;
            }

            $mine = isset(self::STRICTNESS[$roles[$role]['channel']]) ? self::STRICTNESS[$roles[$role]['channel']] : 0;
            $kept = isset(self::STRICTNESS[$rule['channel']]) ? self::STRICTNESS[$rule['channel']] : 0;

            if ($mine > $kept) {
                $rule['channel'] = $roles[$role]['channel'];
            }

            $rule['trust'] = min((int) $rule['trust'], (int) $roles[$role]['trust']);
        }

        return apply_filters('smbb_wpcodetool_2fa_rule', $rule, $user);
    }

    public static function required(\WP_User $user)
    {
        $required = !empty(Security::part('twofa')['enabled']) && self::ruleFor($user) !== null;

        return (bool) apply_filters('smbb_wpcodetool_2fa_required', $required, $user);
    }

    /**
     * Heures de confiance compte + IP pour ce compte (0 = code à chaque connexion).
     */
    public static function trustHours(\WP_User $user)
    {
        $rule = self::ruleFor($user);

        return $rule === null ? 24 : max(0, (int) $rule['trust']);
    }

    /**
     * Canal utilisé pour ce compte : l'application si le compte l'a activée ; sinon sms si c'est la
     * règle et que c'est possible (connecteur prêt + numéro connu), sinon email (on ne bloque jamais
     * faute de numéro ou d'application).
     */
    public static function channelFor(\WP_User $user, $wanted = '')
    {
        if ($wanted === '') {
            if (Totp::enabled($user->ID)) {
                return 'app';
            }

            $rule = self::ruleFor($user);
            $wanted = $rule === null ? 'email' : $rule['channel'];
        }

        if ($wanted === 'app' && Totp::enabled($user->ID)) {
            return 'app';
        }

        if ($wanted === 'sms' && self::smsPossible($user)) {
            return 'sms';
        }

        return 'email';
    }

    public static function smsPossible(\WP_User $user)
    {
        return class_exists(Sms::class) && Sms::ready() === true && self::validPhone(self::phone($user));
    }

    /**
     * Numéro utilisable pour un SMS : au moins 9 chiffres.
     */
    public static function validPhone($phone)
    {
        return strlen(preg_replace('/\D+/', '', (string) $phone)) >= 9;
    }

    /**
     * Comptes d'un rôle sans numéro utilisable (ils recevront le code par e-mail).
     *
     * @return array{total:int,missing:\WP_User[]}
     */
    public static function roleWithoutPhone($role)
    {
        $users = get_users(array('role' => $role, 'number' => 1000, 'orderby' => 'display_name'));
        $missing = array();

        foreach ($users as $user) {
            if (!self::validPhone(self::phone($user))) {
                $missing[] = $user;
            }
        }

        return array('total' => count($users), 'missing' => $missing);
    }

    /**
     * Comptes d'un rôle qui n'ont pas encore activé l'application (ils reçoivent le code par e-mail).
     *
     * @return array{total:int,missing:\WP_User[]}
     */
    public static function roleWithoutApp($role)
    {
        $users = get_users(array('role' => $role, 'number' => 1000, 'orderby' => 'display_name'));
        $missing = array();

        foreach ($users as $user) {
            if (!Totp::enabled($user->ID)) {
                $missing[] = $user;
            }
        }

        return array('total' => count($users), 'missing' => $missing);
    }

    /**
     * Repli possible quand le compte utilise l'application : seulement si son rôle n'exige pas
     * l'application (elle a été choisie par la personne). '' = pas de repli (codes de secours).
     */
    public static function fallbackFor(\WP_User $user)
    {
        $rule = self::ruleFor($user);

        if ($rule === null || $rule['channel'] === 'app') {
            return '';
        }

        return self::channelFor($user, $rule['channel']);
    }

    public static function phone(\WP_User $user)
    {
        $phone = class_exists(UserLookup::class) ? UserLookup::phone($user->ID) : (string) get_user_meta($user->ID, 'billing_phone', true);

        return (string) apply_filters('smbb_wpcodetool_2fa_phone', $phone, $user);
    }

    // ------------------------------------------------------------------ Connexion

    public static function intercept($user, $username, $password)
    {
        if (!$user instanceof \WP_User) {
            return $user;
        }

        $context = Security::interactiveLogin();

        if (!$context) {
            // XML-RPC ne sait pas demander de code : refusé pour les comptes protégés.
            if (defined('XMLRPC_REQUEST') && !empty(Security::part('twofa')['block_xmlrpc']) && self::required($user)) {
                return new \WP_Error('smbb_2fa_xmlrpc', __('Ce compte est protégé par la double authentification : connexion XML-RPC refusée.', 'smbb-wpcodetool'));
            }

            return $user;
        }

        if (!self::required($user) || self::trusted($user->ID, Security::ip())) {
            return $user;
        }

        // phpcs:disable WordPress.Security.NonceVerification
        if ($context === 'woocommerce') {
            $redirect = isset($_POST['redirect']) ? esc_url_raw(wp_unslash($_POST['redirect'])) : '';
            $redirect = $redirect !== '' ? $redirect : (function_exists('wc_get_page_permalink') ? wc_get_page_permalink('myaccount') : home_url('/'));
        } else {
            $redirect = isset($_REQUEST['redirect_to']) ? esc_url_raw(wp_unslash($_REQUEST['redirect_to'])) : '';
        }

        $pending = array(
            'user' => (int) $user->ID,
            'ip' => Security::ip(),
            'remember' => !empty($_POST['rememberme']),
            'redirect' => $redirect,
            'context' => $context,
            'interim' => !empty($_REQUEST['interim-login']),
            'attempts' => 0,
            'sends' => 0,
            'last_send' => 0,
            'channel' => self::channelFor($user),
            'code' => '',
            'expires' => 0,
        );
        // phpcs:enable

        $token = wp_generate_password(40, false, false);
        $args = array('action' => self::ACTION, 't' => $token);

        if ($pending['channel'] === 'app') {
            // Rien à envoyer : le code est dans l'application du téléphone.
            self::store($token, $pending);
            wp_safe_redirect(add_query_arg($args, wp_login_url()));
            exit;
        }

        $sent = self::sendCode($token, $pending, $user);

        if (is_wp_error($sent)) {
            // Ni SMS ni e-mail ne sont partis. Mode souple (choix de Seb, par défaut) : on laisse entrer
            // sans code plutôt que de fermer la porte, et on le note en critique au journal.
            if (!empty(Security::part('twofa')['fail_open'])) {
                self::forget($token);
                Security::log('login.2fa_skipped', 'critical', __('Connexion sans code : ni le SMS ni l\'e-mail n\'ont pu partir', 'smbb-wpcodetool'), array(
                    'user_id' => $user->ID,
                    'object_type' => 'user',
                    'object_id' => $user->ID,
                    'context' => array('error' => $sent->get_error_message()),
                ));

                return $user;
            }

            $args['sendfail'] = 1;
        }

        wp_safe_redirect(add_query_arg($args, wp_login_url()));
        exit;
    }

    /**
     * Génère un code, l'envoie et enregistre la demande en attente.
     *
     * @return true|\WP_Error
     */
    private static function sendCode($token, array &$pending, \WP_User $user)
    {
        $ttl = max(2, (int) Security::part('twofa')['code_ttl']) * MINUTE_IN_SECONDS;
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        $pending['code'] = self::hash($code);
        $pending['expires'] = time() + $ttl;
        $pending['sends']++;
        $pending['last_send'] = time();

        $result = self::deliver($user, $pending['channel'], $code, (int) ($ttl / MINUTE_IN_SECONDS));

        // Le SMS n'est pas parti : on bascule sur l'e-mail tout de suite.
        if (is_wp_error($result) && $pending['channel'] === 'sms') {
            $pending['channel'] = 'email';
            $result = self::deliver($user, 'email', $code, (int) ($ttl / MINUTE_IN_SECONDS));
        }

        self::store($token, $pending);

        if (is_wp_error($result)) {
            Security::log('login.2fa_send_failed', 'critical', __('Code de double authentification non envoyé', 'smbb-wpcodetool'), array(
                'user_id' => $user->ID,
                'object_type' => 'user',
                'object_id' => $user->ID,
                'context' => array('channel' => $pending['channel'], 'error' => $result->get_error_message()),
            ));

            return $result;
        }

        Security::log('login.2fa_sent', 'info', $pending['channel'] === 'sms'
            ? __('Code de double authentification envoyé par SMS', 'smbb-wpcodetool')
            : __('Code de double authentification envoyé par e-mail', 'smbb-wpcodetool'), array(
            'user_id' => $user->ID,
            'object_type' => 'user',
            'object_id' => $user->ID,
            'context' => array('channel' => $pending['channel']),
        ));

        return true;
    }

    /**
     * Envoie un code par le canal donné.
     *
     * @return true|\WP_Error
     */
    public static function deliver(\WP_User $user, $channel, $code, $minutes)
    {
        $site = wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES);

        if ($channel === 'sms') {
            $short = function_exists('mb_strimwidth') ? mb_strimwidth($site, 0, 30, '…') : substr($site, 0, 30);
            $body = sprintf(__('%1$s est votre code de connexion à %2$s. Valable %3$d min. Ne le donnez à personne.', 'smbb-wpcodetool'), $code, $short, $minutes);
            $result = Sms::send(self::phone($user), $body, array('source' => 'codetool-2fa', 'plugin' => 'codetool', 'user_id' => $user->ID));

            return is_wp_error($result) ? $result : true;
        }

        if (!is_email($user->user_email)) {
            return new \WP_Error('smbb_2fa_no_email', __('Ce compte n\'a pas d\'adresse e-mail valide.', 'smbb-wpcodetool'));
        }

        $subject = sprintf(__('[%1$s] Votre code de connexion : %2$s', 'smbb-wpcodetool'), $site, $code);
        $body = sprintf(
            /* translators: 1: nom, 2: site, 3: code, 4: minutes, 5: IP, 6: date */
            __("Bonjour %1\$s,\n\nVoici votre code pour vous connecter à %2\$s :\n\n    %3\$s\n\nIl est valable %4\$d minutes et ne sert qu'une fois.\nDemande faite depuis l'adresse %5\$s, le %6\$s.\n\nSi ce n'est pas vous : quelqu'un connaît votre mot de passe. Changez-le dès que possible.\n", 'smbb-wpcodetool'),
            $user->display_name !== '' ? $user->display_name : $user->user_login,
            $site,
            $code,
            $minutes,
            Security::ip(),
            wp_date(get_option('date_format') . ' H:i')
        );

        $error = null;
        $catch = function ($wp_error) use (&$error) {
            $error = $wp_error;
        };
        add_action('wp_mail_failed', $catch);
        $ok = wp_mail($user->user_email, $subject, $body);
        remove_action('wp_mail_failed', $catch);

        if (!$ok) {
            return $error instanceof \WP_Error ? $error : new \WP_Error('smbb_2fa_mail', __('L\'e-mail n\'a pas pu partir.', 'smbb-wpcodetool'));
        }

        return true;
    }

    // ------------------------------------------------------------------ Écran du code

    /**
     * wp-login.php?action=smbb_2fa : saisie du code, renvoi, changement de canal.
     */
    public static function screen()
    {
        // phpcs:disable WordPress.Security.NonceVerification -- le jeton (40 caractères aléatoires, lié à l'IP) fait office de nonce.
        $token = isset($_REQUEST['t']) ? preg_replace('/[^A-Za-z0-9]/', '', (string) wp_unslash($_REQUEST['t'])) : '';
        $pending = $token !== '' ? self::load($token) : null;

        if ($pending === null || $pending['ip'] !== Security::ip() || !($user = get_userdata($pending['user']))) {
            self::backToLogin('expired');
        }

        $errors = new \WP_Error();
        $notice = '';

        if (isset($_GET['sendfail'])) {
            $errors->add('smbb_2fa_send', __('Le code n\'a pas pu être envoyé. Réessayez avec « Renvoyer un code », utilisez un code de secours, ou prévenez l\'administrateur du site.', 'smbb-wpcodetool'));
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $do = isset($_POST['smbb_2fa_do']) ? sanitize_key(wp_unslash($_POST['smbb_2fa_do'])) : 'verify';
            $blocked = LoginLimiter::enabled() ? LoginLimiter::blockedError() : null;

            if ($blocked !== null) {
                self::forget($token);
                $errors = $blocked;
            } elseif ($do === 'resend' || $do === 'email' || $do === 'sms') {
                $notice = self::resend($token, $pending, $user, $do, $errors);
            } else {
                $code = isset($_POST['smbb_code']) ? (string) wp_unslash($_POST['smbb_code']) : '';
                self::verify($token, $pending, $user, $code, $errors);
            }
        }
        // phpcs:enable

        self::render($token, $pending, $user, $errors, $notice);
        exit;
    }

    /**
     * @return string Message de confirmation ('' si erreur).
     */
    private static function resend($token, array $pending, \WP_User $user, $do, \WP_Error $errors)
    {
        if ($pending['channel'] === 'app') {
            // Repli vers SMS / e-mail seulement si le rôle n'exige pas l'application.
            $fallback = self::fallbackFor($user);

            if ($fallback === '' || ($do !== 'email' && $do !== 'sms')) {
                $errors->add('smbb_2fa_app_only', __('Ce compte doit utiliser son application d\'authentification (ou un code de secours).', 'smbb-wpcodetool'));

                return '';
            }

            $pending['channel'] = $do === 'sms' && self::smsPossible($user) ? 'sms' : 'email';
            $pending['last_send'] = 0;
        }

        $wait = self::RESEND_DELAY - (time() - (int) $pending['last_send']);

        if ($pending['sends'] >= self::MAX_SENDS) {
            $errors->add('smbb_2fa_sends', __('Trop de codes envoyés. Reconnectez-vous pour recommencer.', 'smbb-wpcodetool'));

            return '';
        }

        if ($wait > 0) {
            $errors->add('smbb_2fa_wait', sprintf(_n('Patientez %d seconde avant de demander un nouveau code.', 'Patientez %d secondes avant de demander un nouveau code.', $wait, 'smbb-wpcodetool'), $wait));

            return '';
        }

        if ($do === 'sms' && self::smsPossible($user)) {
            $pending['channel'] = 'sms';
        } elseif ($do === 'email') {
            $pending['channel'] = 'email';
        }

        $sent = self::sendCode($token, $pending, $user);

        if (is_wp_error($sent)) {
            $errors->add('smbb_2fa_send', __('Le code n\'a pas pu être envoyé.', 'smbb-wpcodetool') . ' ' . esc_html($sent->get_error_message()));

            return '';
        }

        return __('Nouveau code envoyé. Le précédent ne marche plus.', 'smbb-wpcodetool');
    }

    private static function verify($token, array $pending, \WP_User $user, $code, \WP_Error $errors)
    {
        $clean = strtolower(preg_replace('/[^A-Za-z0-9]/', '', $code));

        if ($clean === '') {
            $errors->add('smbb_2fa_empty', __('Saisissez le code reçu.', 'smbb-wpcodetool'));

            return;
        }

        $ok = false;
        $backup = false;

        if ($pending['channel'] === 'app' && ctype_digit($clean) && strlen($clean) === 6) {
            $ok = Totp::check($user->ID, $clean);
        } elseif (ctype_digit($clean) && strlen($clean) === 6) {
            $ok = time() <= (int) $pending['expires'] && hash_equals((string) $pending['code'], self::hash($clean));

            if (!$ok && time() > (int) $pending['expires'] && hash_equals((string) $pending['code'], self::hash($clean))) {
                $errors->add('smbb_2fa_expired', __('Ce code a expiré. Demandez-en un nouveau.', 'smbb-wpcodetool'));

                return;
            }
        } else {
            $ok = $backup = self::useBackupCode($user->ID, $clean);
        }

        if ($ok) {
            self::success($token, $pending, $user, $backup);
        }

        if ($pending['channel'] === 'app' && Totp::reused($user->ID, $clean)) {
            // Pas un essai raté : le code vient déjà de servir, le suivant arrive dans 30 s au plus.
            $errors->add('smbb_2fa_reused', __('Ce code vient déjà de servir. Attendez que l\'application en affiche un nouveau (30 secondes au plus).', 'smbb-wpcodetool'));

            return;
        }

        $pending['attempts']++;
        LoginLimiter::enabled() && LoginLimiter::recordFailure($user->user_login, '2fa');

        Security::log('login.2fa_failed', 'warning', __('Code de double authentification refusé', 'smbb-wpcodetool'), array(
            'user_id' => $user->ID,
            'object_type' => 'user',
            'object_id' => $user->ID,
            'context' => array('attempt' => $pending['attempts']),
        ));

        if ($pending['attempts'] >= self::MAX_ATTEMPTS) {
            self::forget($token);
            self::backToLogin('attempts');
        }

        self::store($token, $pending);
        $left = self::MAX_ATTEMPTS - $pending['attempts'];
        $errors->add('smbb_2fa_wrong', sprintf(_n('Code incorrect. Encore %d essai.', 'Code incorrect. Encore %d essais.', $left, 'smbb-wpcodetool'), $left));
    }

    private static function success($token, array $pending, \WP_User $user, $backup)
    {
        global $interim_login;

        self::forget($token);
        self::trust($user->ID, $pending['ip']);
        LoginLimiter::clearFailures($pending['ip']);

        if ($backup) {
            Security::log('login.2fa_backup_used', 'warning', sprintf(__('Code de secours utilisé (il en reste %d)', 'smbb-wpcodetool'), self::backupCount($user->ID)), array(
                'user_id' => $user->ID,
                'object_type' => 'user',
                'object_id' => $user->ID,
            ));
        }

        wp_set_auth_cookie($user->ID, (bool) $pending['remember'], is_ssl());
        wp_set_current_user($user->ID);
        do_action('wp_login', $user->user_login, $user);

        if (!empty($pending['interim'])) {
            // Reconnexion dans la fenêtre « session expirée » de l'admin : même fin que wp-login.php.
            $interim_login = 'success';
            login_header('', '<p class="message">' . esc_html__('Vous êtes reconnecté. Vous pouvez fermer cette fenêtre.', 'smbb-wpcodetool') . '</p>');
            echo '</div>';
            do_action('login_footer');
            echo '</body></html>';
            exit;
        }

        $requested = (string) $pending['redirect'];

        if ($pending['context'] === 'woocommerce') {
            $redirect = apply_filters('woocommerce_login_redirect', $requested, $user);
        } else {
            $redirect = $requested !== '' ? $requested : admin_url();

            if ($requested === '' || $requested === 'wp-admin/' || $requested === admin_url()) {
                if (is_multisite() && !get_active_blog_for_user($user->ID) && !is_super_admin($user->ID)) {
                    $redirect = user_admin_url();
                } elseif (is_multisite() && !$user->has_cap('read')) {
                    $redirect = get_dashboard_url($user->ID);
                } elseif (!$user->has_cap('edit_posts')) {
                    $redirect = $user->has_cap('read') ? admin_url('profile.php') : home_url();
                }
            }

            $redirect = apply_filters('login_redirect', $redirect, $requested, $user);
        }

        wp_safe_redirect($redirect !== '' ? $redirect : admin_url());
        exit;
    }

    private static function render($token, array $pending, \WP_User $user, \WP_Error $errors, $notice)
    {
        if ($pending['channel'] === 'app') {
            self::renderApp($token, $user, $errors, $notice);

            return;
        }

        $minutes = max(2, (int) Security::part('twofa')['code_ttl']);
        $where = $pending['channel'] === 'sms'
            ? sprintf(__('par SMS au %s', 'smbb-wpcodetool'), Security::maskPhone(self::phone($user)))
            : sprintf(__('par e-mail à %s', 'smbb-wpcodetool'), Security::maskEmail($user->user_email));
        $message = '<p class="message">' . sprintf(
            /* translators: 1: « par e-mail à s•••@g•••.fr », 2: minutes */
            esc_html__('Un code à 6 chiffres vient d\'être envoyé %1$s. Il est valable %2$d minutes.', 'smbb-wpcodetool'),
            esc_html($where),
            $minutes
        ) . '</p>';

        if ($notice !== '') {
            $message .= '<p class="message">' . esc_html($notice) . '</p>';
        }

        $other = $pending['channel'] === 'sms' ? 'email' : (self::smsPossible($user) ? 'sms' : '');

        login_header(__('Vérification en deux étapes', 'smbb-wpcodetool'), $message, $errors);
        ?>
        <form name="smbb2faform" id="loginform" class="smbb-2fa-form" action="<?php echo esc_url(add_query_arg('action', self::ACTION, wp_login_url())); ?>" method="post" autocomplete="off">
            <input type="hidden" name="t" value="<?php echo esc_attr($token); ?>">
            <p>
                <label for="smbb_code"><?php esc_html_e('Code de connexion', 'smbb-wpcodetool'); ?></label>
                <input type="text" name="smbb_code" id="smbb_code" class="input" value="" size="20" autocomplete="one-time-code" inputmode="numeric" spellcheck="false" style="font-size:24px;letter-spacing:.3em;text-align:center;">
            </p>
            <p class="description" style="margin:-8px 0 16px;color:#646970;font-size:12px;"><?php esc_html_e('Plus d\'accès à votre messagerie ou à votre téléphone ? Saisissez un de vos codes de secours (xxxx-xxxx).', 'smbb-wpcodetool'); ?></p>
            <?php $hours = self::trustHours($user); ?>
            <?php if ($hours > 0) : ?>
                <p class="description" style="margin:0 0 16px;color:#646970;font-size:12px;"><?php echo esc_html($hours >= 48
                    ? sprintf(__('Depuis cette adresse, on ne vous redemandera pas de code pendant %d jours.', 'smbb-wpcodetool'), (int) round($hours / 24))
                    : sprintf(_n('Depuis cette adresse, on ne vous redemandera pas de code pendant %d heure.', 'Depuis cette adresse, on ne vous redemandera pas de code pendant %d heures.', $hours, 'smbb-wpcodetool'), $hours)); ?></p>
            <?php endif; ?>
            <p class="submit">
                <button type="submit" name="smbb_2fa_do" value="verify" class="button button-primary button-large"><?php esc_html_e('Valider', 'smbb-wpcodetool'); ?></button>
            </p>
            <p class="smbb-2fa-alt">
                <span><?php esc_html_e('Pas reçu ?', 'smbb-wpcodetool'); ?></span>
                <button type="submit" name="smbb_2fa_do" value="resend" class="smbb-2fa-link" formnovalidate><?php esc_html_e('Renvoyer', 'smbb-wpcodetool'); ?></button>
                <?php if ($other !== '') : ?>
                    <span class="smbb-2fa-dot" aria-hidden="true">·</span>
                    <button type="submit" name="smbb_2fa_do" value="<?php echo esc_attr($other); ?>" class="smbb-2fa-link" formnovalidate><?php echo esc_html($other === 'sms' ? __('Par SMS', 'smbb-wpcodetool') : __('Par e-mail', 'smbb-wpcodetool')); ?></button>
                <?php endif; ?>
            </p>
            <?php self::altStyle(); ?>
        </form>
        <p id="nav"><a href="<?php echo esc_url(wp_login_url()); ?>"><?php esc_html_e('← Se connecter avec un autre compte', 'smbb-wpcodetool'); ?></a></p>
        <?php
        login_footer('smbb_code');
    }

    /**
     * Écran du code pour un compte qui utilise l'application.
     */
    private static function renderApp($token, \WP_User $user, \WP_Error $errors, $notice)
    {
        $message = '<p class="message">' . esc_html__('Ouvrez votre application d\'authentification (Google Authenticator, Microsoft Authenticator…) et saisissez le code à 6 chiffres affiché pour ce site.', 'smbb-wpcodetool') . '</p>';

        if ($notice !== '') {
            $message .= '<p class="message">' . esc_html($notice) . '</p>';
        }

        $fallback = self::fallbackFor($user);

        login_header(__('Vérification en deux étapes', 'smbb-wpcodetool'), $message, $errors);
        ?>
        <form name="smbb2faform" id="loginform" class="smbb-2fa-form" action="<?php echo esc_url(add_query_arg('action', self::ACTION, wp_login_url())); ?>" method="post" autocomplete="off">
            <input type="hidden" name="t" value="<?php echo esc_attr($token); ?>">
            <p>
                <label for="smbb_code"><?php esc_html_e('Code de l\'application', 'smbb-wpcodetool'); ?></label>
                <input type="text" name="smbb_code" id="smbb_code" class="input" value="" size="20" autocomplete="one-time-code" inputmode="numeric" spellcheck="false" style="font-size:24px;letter-spacing:.3em;text-align:center;">
            </p>
            <p class="description" style="margin:-8px 0 16px;color:#646970;font-size:12px;"><?php esc_html_e('Le code change toutes les 30 secondes. Téléphone perdu ? Saisissez un de vos codes de secours (xxxx-xxxx).', 'smbb-wpcodetool'); ?></p>
            <?php $hours = self::trustHours($user); ?>
            <?php if ($hours > 0) : ?>
                <p class="description" style="margin:0 0 16px;color:#646970;font-size:12px;"><?php echo esc_html($hours >= 48
                    ? sprintf(__('Depuis cette adresse, on ne vous redemandera pas de code pendant %d jours.', 'smbb-wpcodetool'), (int) round($hours / 24))
                    : sprintf(_n('Depuis cette adresse, on ne vous redemandera pas de code pendant %d heure.', 'Depuis cette adresse, on ne vous redemandera pas de code pendant %d heures.', $hours, 'smbb-wpcodetool'), $hours)); ?></p>
            <?php endif; ?>
            <p class="submit">
                <button type="submit" name="smbb_2fa_do" value="verify" class="button button-primary button-large"><?php esc_html_e('Valider', 'smbb-wpcodetool'); ?></button>
            </p>
            <?php if ($fallback !== '') : ?>
                <p class="smbb-2fa-alt">
                    <span><?php esc_html_e('Pas votre téléphone ?', 'smbb-wpcodetool'); ?></span>
                    <button type="submit" name="smbb_2fa_do" value="<?php echo esc_attr($fallback); ?>" class="smbb-2fa-link" formnovalidate><?php echo esc_html($fallback === 'sms' ? __('Recevoir un code par SMS', 'smbb-wpcodetool') : __('Recevoir un code par e-mail', 'smbb-wpcodetool')); ?></button>
                </p>
                <?php self::altStyle(); ?>
            <?php endif; ?>
        </form>
        <p id="nav"><a href="<?php echo esc_url(wp_login_url()); ?>"><?php esc_html_e('← Se connecter avec un autre compte', 'smbb-wpcodetool'); ?></a></p>
        <?php
        login_footer('smbb_code');
    }

    /**
     * Actions secondaires de l'écran du code (renvoyer, changer de canal) : une ligne de liens
     * discrète sous « Valider », aux couleurs du skin CodeTool s'il est actif.
     */
    private static function altStyle()
    {
        ?>
        <style>
            .smbb-2fa-form .submit{margin-bottom:0}
            .smbb-2fa-alt{clear:both;margin:0;padding-top:18px;display:flex;flex-wrap:wrap;justify-content:center;align-items:baseline;gap:4px 8px;font-size:13px;line-height:1.5;color:var(--smbb-t-muted,#646970);text-align:center}
            .smbb-2fa-form .smbb-2fa-link{appearance:none;background:none;border:0;border-radius:4px;box-shadow:none;margin:0;padding:0 2px;min-height:0;font:inherit;font-weight:600;color:var(--smbb-t-accent,#2271b1);cursor:pointer;text-decoration:none}
            .smbb-2fa-form .smbb-2fa-link:hover{text-decoration:underline;text-underline-offset:3px}
            .smbb-2fa-form .smbb-2fa-link:focus-visible{outline:2px solid var(--smbb-t-accent,#2271b1);outline-offset:2px}
            .smbb-2fa-dot{opacity:.6}
        </style>
        <?php
    }

    private static function backToLogin($reason)
    {
        wp_safe_redirect(add_query_arg('smbb_2fa_error', $reason, wp_login_url()));
        exit;
    }

    /**
     * Messages sur la page de connexion après un retour depuis l'écran du code.
     */
    public static function loginErrors($errors)
    {
        $reason = isset($_GET['smbb_2fa_error']) ? sanitize_key(wp_unslash($_GET['smbb_2fa_error'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification

        if ($reason === '' || !$errors instanceof \WP_Error) {
            return $errors;
        }

        $errors->add('smbb_2fa_' . $reason, $reason === 'attempts'
            ? __('Trop de codes incorrects : reconnectez-vous pour recevoir un nouveau code.', 'smbb-wpcodetool')
            : __('La vérification a expiré. Reconnectez-vous pour recevoir un nouveau code.', 'smbb-wpcodetool'));

        return $errors;
    }

    // ------------------------------------------------------------------ Demande en attente

    private static function key($token)
    {
        return 'smbb_2fa_' . substr(hash('sha256', $token), 0, 40);
    }

    private static function store($token, array $pending)
    {
        // Gardée un peu plus longtemps que le code : « code expiré » plutôt que « reconnectez-vous ».
        set_transient(self::key($token), $pending, max(60, (int) $pending['expires'] - time()) + 15 * MINUTE_IN_SECONDS);
    }

    private static function load($token)
    {
        $pending = get_transient(self::key($token));

        return is_array($pending) && isset($pending['user'], $pending['ip'], $pending['code']) ? $pending : null;
    }

    private static function forget($token)
    {
        delete_transient(self::key($token));
    }

    private static function hash($code)
    {
        return hash_hmac('sha256', strtolower((string) $code), wp_salt('auth'));
    }

    // ------------------------------------------------------------------ Confiance compte + IP

    public static function trusted($user_id, $ip)
    {
        $user = get_userdata((int) $user_id);

        if (!$user || self::trustHours($user) <= 0) {
            return false;
        }

        $trust = self::trustList($user_id);

        return isset($trust[$ip]) && (int) $trust[$ip] > time();
    }

    /**
     * Adresses reconnues pour le compte : IP => fin de la confiance.
     *
     * @return array<string,int>
     */
    public static function trustList($user_id)
    {
        $trust = get_user_meta((int) $user_id, self::TRUST_META, true);
        $trust = is_array($trust) ? $trust : array();
        $now = time();

        return array_filter($trust, function ($until) use ($now) {
            return (int) $until > $now;
        });
    }

    private static function trust($user_id, $ip)
    {
        $user = get_userdata((int) $user_id);
        $hours = $user ? self::trustHours($user) : 0;

        if ($hours <= 0) {
            return;
        }

        $trust = self::trustList($user_id);
        $trust[$ip] = time() + $hours * HOUR_IN_SECONDS;

        // 20 adresses au plus par compte.
        arsort($trust);
        update_user_meta((int) $user_id, self::TRUST_META, array_slice($trust, 0, 20, true));
    }

    public static function revokeTrust($user_id, $ip = null)
    {
        if ($ip === null) {
            delete_user_meta((int) $user_id, self::TRUST_META);

            return;
        }

        $trust = self::trustList($user_id);
        unset($trust[$ip]);
        update_user_meta((int) $user_id, self::TRUST_META, $trust);
    }

    /**
     * Oublie toutes les adresses reconnues, pour tous les comptes.
     */
    public static function revokeAllTrust()
    {
        delete_metadata('user', 0, self::TRUST_META, '', true);
    }

    // ------------------------------------------------------------------ Codes de secours

    /**
     * Nouveaux codes de secours pour le compte (les anciens ne marchent plus). Renvoyés en clair,
     * une seule fois : seule leur empreinte est gardée.
     *
     * @return string[]
     */
    public static function generateBackupCodes($user_id)
    {
        $alphabet = 'abcdefghjkmnpqrstuvwxyz23456789';
        $codes = array();
        $hashes = array();

        for ($i = 0; $i < self::BACKUP_COUNT; $i++) {
            $code = '';

            for ($j = 0; $j < 8; $j++) {
                $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }

            $codes[] = substr($code, 0, 4) . '-' . substr($code, 4);
            $hashes[] = self::hash($code);
        }

        update_user_meta((int) $user_id, self::BACKUP_META, $hashes);

        Security::log('security.backup_codes_generated', 'notice', __('Nouveaux codes de secours générés', 'smbb-wpcodetool'), array(
            'user_id' => (int) $user_id,
            'object_type' => 'user',
            'object_id' => (int) $user_id,
        ));

        return $codes;
    }

    public static function backupCount($user_id)
    {
        $hashes = get_user_meta((int) $user_id, self::BACKUP_META, true);

        return is_array($hashes) ? count($hashes) : 0;
    }

    private static function useBackupCode($user_id, $clean)
    {
        if (strlen($clean) !== 8) {
            return false;
        }

        $hashes = get_user_meta((int) $user_id, self::BACKUP_META, true);

        if (!is_array($hashes)) {
            return false;
        }

        $hash = self::hash($clean);

        foreach ($hashes as $index => $stored) {
            if (hash_equals((string) $stored, $hash)) {
                unset($hashes[$index]);
                update_user_meta((int) $user_id, self::BACKUP_META, array_values($hashes));

                return true;
            }
        }

        return false;
    }

    // ------------------------------------------------------------------ Application d'authentification (profil)

    /**
     * Section « Application d'authentification » du profil (le sien ou celui d'un autre compte).
     */
    public static function profileSection($user)
    {
        if (!$user instanceof \WP_User) {
            return;
        }

        $own = (int) $user->ID === get_current_user_id();
        $enabled = Totp::enabled($user->ID);
        $rule = self::ruleFor($user);
        $module = !empty(Security::part('twofa')['enabled']);

        // Rien à montrer sur le profil d'un autre compte qui ne l'a pas activée.
        if (!$own && !$enabled) {
            return;
        }

        $required = $module && $rule !== null && $rule['channel'] === 'app';
        ?>
        <h2 id="smbb-totp"><?php esc_html_e('Application d\'authentification', 'smbb-wpcodetool'); ?></h2>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><?php esc_html_e('État', 'smbb-wpcodetool'); ?></th>
                <td>
                    <?php if ($enabled) : ?>
                        <p><span class="dashicons dashicons-yes-alt" style="color:#008a20;" aria-hidden="true"></span> <?php
                        $since = Totp::since($user->ID);
                        echo esc_html($since
                            ? sprintf(__('Activée depuis le %s. Le code de connexion se lit dans l\'application.', 'smbb-wpcodetool'), wp_date(get_option('date_format'), $since))
                            : __('Activée. Le code de connexion se lit dans l\'application.', 'smbb-wpcodetool'));
                        ?></p>
                        <?php if (!$module || $rule === null) : ?>
                            <p class="description"><?php esc_html_e('La double authentification n\'est pas demandée à ce compte pour le moment : l\'application servira dès qu\'elle le sera.', 'smbb-wpcodetool'); ?></p>
                        <?php endif; ?>
                        <p style="margin-top:10px;">
                            <label><input type="checkbox" name="smbb_totp_remove" value="1"> <?php echo esc_html($own
                                ? __('Retirer l\'application de mon compte', 'smbb-wpcodetool')
                                : __('Retirer l\'application de ce compte (téléphone perdu ou changé)', 'smbb-wpcodetool')); ?></label>
                        </p>
                        <?php if ($own) : ?>
                            <p class="description"><?php echo esc_html($required
                                ? __('Votre rôle exige l\'application : sans elle, le code vous sera envoyé par e-mail jusqu\'à ce que vous la configuriez à nouveau.', 'smbb-wpcodetool')
                                : __('Sans elle, vous recevrez le code par SMS ou par e-mail selon votre rôle.', 'smbb-wpcodetool')); ?></p>
                        <?php else : ?>
                            <p class="description"><?php esc_html_e('La personne recevra le code par e-mail et pourra configurer à nouveau son application depuis son profil.', 'smbb-wpcodetool'); ?></p>
                        <?php endif; ?>
                    <?php else : ?>
                        <?php
                        $secret = Totp::pendingSecret($user->ID);
                        $site = wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES);
                        $uri = Totp::uri($secret, $user->user_email !== '' ? $user->user_email : $user->user_login, $site !== '' ? $site : wp_parse_url(home_url(), PHP_URL_HOST));
                        $qr = Totp::qrSvg($uri, 180);
                        ?>
                        <?php if ($required) : ?>
                            <div class="notice notice-warning inline" style="margin:0 0 12px;"><p><?php esc_html_e('Votre rôle demande une application d\'authentification. En attendant, votre code arrive par e-mail.', 'smbb-wpcodetool'); ?></p></div>
                        <?php endif; ?>
                        <ol style="margin:0 0 0 18px;max-width:640px;">
                            <li style="margin-bottom:10px;"><?php esc_html_e('Installez une application sur votre téléphone : Google Authenticator, Microsoft Authenticator, Authy, ou votre gestionnaire de mots de passe (1Password, Bitwarden…).', 'smbb-wpcodetool'); ?></li>
                            <li style="margin-bottom:10px;">
                                <?php esc_html_e('Dans l\'application, ajoutez un compte et scannez ce QR code :', 'smbb-wpcodetool'); ?>
                                <div style="display:flex;flex-wrap:wrap;gap:20px;align-items:center;margin:10px 0;">
                                    <?php if ($qr !== '') : ?>
                                        <div style="background:#fff;padding:10px;border-radius:8px;border:1px solid #dcdcde;line-height:0;"><?php echo $qr; // phpcs:ignore -- SVG produit par TCPDF. ?></div>
                                    <?php endif; ?>
                                    <div style="font-size:12px;color:#646970;max-width:300px;">
                                        <?php esc_html_e('Pas de caméra ? Choisissez « saisir une clé » et tapez :', 'smbb-wpcodetool'); ?><br>
                                        <code style="display:inline-block;margin-top:6px;font-size:15px;letter-spacing:.06em;user-select:all;"><?php echo esc_html(Totp::pretty($secret)); ?></code><br>
                                        <span><?php esc_html_e('Type : basé sur l\'heure, 6 chiffres, 30 secondes.', 'smbb-wpcodetool'); ?></span>
                                    </div>
                                </div>
                            </li>
                            <li>
                                <label for="smbb_totp_code"><?php esc_html_e('Saisissez le code à 6 chiffres affiché par l\'application :', 'smbb-wpcodetool'); ?></label><br>
                                <input type="text" name="smbb_totp_code" id="smbb_totp_code" value="" inputmode="numeric" autocomplete="one-time-code" maxlength="7" style="width:9em;font-size:18px;letter-spacing:.2em;text-align:center;margin-top:6px;">
                                <button type="submit" class="button button-primary" name="smbb_totp_action" value="activate" style="margin-top:6px;"><?php esc_html_e('Activer l\'application', 'smbb-wpcodetool'); ?></button>
                                <button type="submit" class="button-link" name="smbb_totp_action" value="renew" style="margin:6px 0 0 8px;"><?php esc_html_e('Nouveau QR code', 'smbb-wpcodetool'); ?></button>
                            </li>
                        </ol>
                        <p class="description" style="margin-top:12px;"><?php esc_html_e('Le QR code contient une clé secrète : ne le montrez à personne. Pensez aussi à garder vos codes de secours en cas de perte du téléphone.', 'smbb-wpcodetool'); ?></p>
                    <?php endif; ?>
                </td>
            </tr>
        </table>
        <?php
    }

    /**
     * Enregistrement du profil : activation (code de confirmation), nouveau QR, retrait.
     * Branché sur user_profile_update_errors pour afficher une erreur au lieu d'enregistrer en silence.
     */
    public static function profileSave($errors, $update, $user)
    {
        // phpcs:disable WordPress.Security.NonceVerification -- formulaire du profil, nonce vérifié par WordPress (update-user_ID).
        if (!$update || !$errors instanceof \WP_Error || empty($user->ID) || !current_user_can('edit_user', (int) $user->ID)) {
            return;
        }

        $id = (int) $user->ID;
        $own = $id === get_current_user_id();
        $action = isset($_POST['smbb_totp_action']) ? sanitize_key(wp_unslash($_POST['smbb_totp_action'])) : '';
        $code = isset($_POST['smbb_totp_code']) ? trim((string) wp_unslash($_POST['smbb_totp_code'])) : '';

        if (!empty($_POST['smbb_totp_remove']) && Totp::enabled($id)) {
            Totp::disable($id, get_current_user_id());

            return;
        }

        if (!$own || Totp::enabled($id)) {
            return;
        }

        if ($action === 'renew') {
            Totp::pendingSecret($id, true);

            return;
        }

        // Un code saisi suffit (touche Entrée ou bouton « Mettre à jour le profil »).
        if ($action !== 'activate' && $code === '') {
            return;
        }

        $result = Totp::activate($id, $code);

        if (is_wp_error($result)) {
            $errors->add('smbb_totp', $result->get_error_message());
        }
        // phpcs:enable
    }

    /**
     * Rappel dans l'admin : le rôle demande l'application et le compte ne l'a pas activée.
     */
    public static function appReminder()
    {
        $user = wp_get_current_user();

        if (!$user->exists() || Totp::enabled($user->ID)) {
            return;
        }

        $rule = self::ruleFor($user);
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;

        if ($rule === null || $rule['channel'] !== 'app' || ($screen && $screen->id === 'profile')) {
            return;
        }

        echo '<div class="notice notice-warning"><p>'
            . esc_html__('Votre compte doit utiliser une application d\'authentification (Google ou Microsoft Authenticator…) pour se connecter. Il suffit de scanner un QR code.', 'smbb-wpcodetool')
            . ' <a class="button button-small" href="' . esc_url(get_edit_profile_url($user->ID) . '#smbb-totp') . '">' . esc_html__('Configurer maintenant', 'smbb-wpcodetool') . '</a></p></div>';
    }

    // ------------------------------------------------------------------ Test depuis l'admin

    /**
     * Envoie un code d'essai au compte courant (page de réglages). Le code ne sert à rien.
     *
     * @return true|\WP_Error
     */
    public static function sendTest(\WP_User $user, $channel)
    {
        if ($channel === 'sms' && !self::smsPossible($user)) {
            return new \WP_Error('smbb_2fa_sms', __('SMS impossible : connecteur SMS pas prêt ou aucun numéro sur votre compte (champ « Téléphone » du profil).', 'smbb-wpcodetool'));
        }

        return self::deliver($user, $channel === 'sms' ? 'sms' : 'email', str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT), max(2, (int) Security::part('twofa')['code_ttl']));
    }
}
