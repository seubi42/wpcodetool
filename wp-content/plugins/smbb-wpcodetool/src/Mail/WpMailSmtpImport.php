<?php

namespace Smbb\WpCodeTool\Mail;

use Smbb\WpCodeTool\Admin\Pages\MailPage;
use Smbb\WpCodeTool\Mail\Transport\SmtpTransport;

defined('ABSPATH') || exit;

/**
 * Reprise des réglages de WP Mail SMTP (gratuit ou Pro) pour migrer sans rien ressaisir.
 *
 * Ce qu'on lit (rien n'est modifié chez WP Mail SMTP) :
 * - l'option wp_mail_smtp : mail[from_email, from_name, from_email_force, from_name_force,
 *   mailer, return_path] et smtp[host, port, encryption, autotls, auth, user, pass] ;
 * - ou, si WPMS_ON vaut true dans wp-config.php, les constantes WPMS_* qui priment sur la base ;
 * - le mot de passe SMTP est chiffré par WP Mail SMTP (libsodium secretbox, base64(nonce + chiffré))
 *   avec la clé de l'option wp_mail_smtp_mail_key (base64) ou de la constante WPMS_CRYPTO_KEY.
 *   On le déchiffre puis on le rechiffre avec la clé CodeTool. Comme WP Mail SMTP, une valeur
 *   qui ne se déchiffre pas est prise telle quelle (ancien mot de passe resté en clair).
 *
 * Proposé par une notice tant que l'import n'a été ni fait ni écarté ; l'activation de CodeTool
 * remet la proposition en route. Toujours disponible dans CodeTool › E-mails.
 */
final class WpMailSmtpImport
{
    const STATUS_OPTION = 'smbb_codetool_mail_import';

    public static function hooks()
    {
        add_action('admin_notices', array(__CLASS__, 'notice'));
        add_action('admin_post_smbb_codetool_mail_import', array(__CLASS__, 'handle'));
    }

    /**
     * Appelé à l'activation de CodeTool : une proposition écartée revient.
     */
    public static function onActivation()
    {
        if (get_option(self::STATUS_OPTION) === 'dismissed') {
            delete_option(self::STATUS_OPTION);
        }
    }

    /**
     * Réglages de WP Mail SMTP trouvés, ou null.
     *
     * @return array|null {source, mailer, supported, from_email, from_name, force_from, force_name,
     *                     return_path, smtp{host,port,encryption,autotls,auth,user}, pass, pass_state, plugin}
     */
    public static function detect()
    {
        $option = get_option('wp_mail_smtp');
        $constants = defined('WPMS_ON') && WPMS_ON === true;

        if (!$constants && (!is_array($option) || empty($option))) {
            return null;
        }

        $option = is_array($option) ? $option : array();
        $mail = isset($option['mail']) && is_array($option['mail']) ? $option['mail'] : array();
        $smtp = isset($option['smtp']) && is_array($option['smtp']) ? $option['smtp'] : array();

        $read = static function ($group, $key, $constant, $default = '') use ($mail, $smtp, $constants) {
            if ($constants && defined($constant)) {
                return constant($constant);
            }

            $source = $group === 'mail' ? $mail : $smtp;

            return array_key_exists($key, $source) ? $source[$key] : $default;
        };

        $mailer = (string) $read('mail', 'mailer', 'WPMS_MAILER', 'mail');
        $encryption = (string) $read('smtp', 'encryption', 'WPMS_SSL', 'none');
        $encryption = $encryption === '' ? 'none' : strtolower($encryption);

        $found = array(
            'source' => $constants ? 'constants' : 'database',
            'mailer' => $mailer,
            'supported' => in_array($mailer, array('smtp', 'pepipost'), true),
            'from_email' => (string) $read('mail', 'from_email', 'WPMS_MAIL_FROM'),
            'from_name' => (string) $read('mail', 'from_name', 'WPMS_MAIL_FROM_NAME'),
            'force_from' => (bool) $read('mail', 'from_email_force', 'WPMS_MAIL_FROM_FORCE', false),
            'force_name' => (bool) $read('mail', 'from_name_force', 'WPMS_MAIL_FROM_NAME_FORCE', false),
            'return_path' => (bool) $read('mail', 'return_path', 'WPMS_SET_RETURN_PATH', false),
            'smtp' => array(
                'host' => (string) $read('smtp', 'host', 'WPMS_SMTP_HOST'),
                'port' => (int) $read('smtp', 'port', 'WPMS_SMTP_PORT', 587),
                'encryption' => in_array($encryption, array('ssl', 'tls', 'none'), true) ? $encryption : 'tls',
                'autotls' => (bool) $read('smtp', 'autotls', 'WPMS_SMTP_AUTOTLS', true),
                'auth' => (bool) $read('smtp', 'auth', 'WPMS_SMTP_AUTH', true),
                'user' => (string) $read('smtp', 'user', 'WPMS_SMTP_USER'),
            ),
            'pass' => '',
            'pass_state' => 'empty',
            'plugin' => defined('WPMS_PLUGIN_FILE') ? plugin_basename(WPMS_PLUGIN_FILE) : '',
        );

        // Pepipost SMTP : mêmes champs, rangés sous « pepipost ».
        if ($mailer === 'pepipost' && isset($option['pepipost']) && is_array($option['pepipost'])) {
            $smtp = $option['pepipost'];
            $found['smtp']['host'] = isset($smtp['host']) ? (string) $smtp['host'] : '';
            $found['smtp']['port'] = isset($smtp['port']) ? (int) $smtp['port'] : 587;
            $found['smtp']['user'] = isset($smtp['user']) ? (string) $smtp['user'] : '';
        }

        if ($constants && defined('WPMS_SMTP_PASS')) {
            $found['pass'] = (string) WPMS_SMTP_PASS;
            $found['pass_state'] = $found['pass'] === '' ? 'empty' : 'constant';
        } elseif (isset($smtp['pass']) && (string) $smtp['pass'] !== '') {
            $decrypted = self::decrypt((string) $smtp['pass']);
            $found['pass'] = $decrypted['value'];
            $found['pass_state'] = $decrypted['state'];
        }

        if (!$found['supported'] && $found['from_email'] === '' && $found['smtp']['host'] === '') {
            return null;
        }

        return $found;
    }

    /**
     * Déchiffre un mot de passe de WP Mail SMTP.
     *
     * @return array{value:string,state:string} state : decrypted, plain (pas chiffré), uncertain (clé absente ou
     *                                          refusée alors que la valeur a l'air chiffrée : à vérifier).
     */
    public static function decrypt($stored)
    {
        $nonce_bytes = defined('SODIUM_CRYPTO_SECRETBOX_NONCEBYTES') ? SODIUM_CRYPTO_SECRETBOX_NONCEBYTES : 24;
        $mac_bytes = defined('SODIUM_CRYPTO_SECRETBOX_MACBYTES') ? SODIUM_CRYPTO_SECRETBOX_MACBYTES : 16;
        $raw = base64_decode($stored, true);
        $looks_encrypted = $raw !== false && strlen($raw) >= $nonce_bytes + $mac_bytes;

        if (!$looks_encrypted) {
            return array('value' => $stored, 'state' => 'plain');
        }

        $key = self::secretKey();

        if ($key === '' || strlen($key) !== 32 || !function_exists('sodium_crypto_secretbox_open')) {
            return array('value' => $stored, 'state' => 'uncertain');
        }

        try {
            $plain = sodium_crypto_secretbox_open(substr($raw, $nonce_bytes), substr($raw, 0, $nonce_bytes), $key);
        } catch (\Throwable $e) {
            $plain = false;
        }

        if ($plain === false) {
            return array('value' => $stored, 'state' => 'uncertain');
        }

        return array('value' => $plain, 'state' => 'decrypted');
    }

    /**
     * Clé de chiffrement de WP Mail SMTP (32 octets bruts) ou ''.
     */
    private static function secretKey()
    {
        if (defined('WPMS_CRYPTO_KEY')) {
            return (string) WPMS_CRYPTO_KEY;
        }

        $stored = get_option('wp_mail_smtp_mail_key');

        if (!is_string($stored) || $stored === '') {
            return '';
        }

        $key = base64_decode($stored, true);

        return $key === false ? '' : $key;
    }

    /**
     * Applique les réglages trouvés aux réglages CodeTool. Ne touche pas aux réglages du journal et des alertes.
     */
    public static function import(array $found)
    {
        $settings = Mailer::settings();

        if (is_email($found['from_email'])) {
            $settings['from_email'] = $found['from_email'];
        }

        if ($found['from_name'] !== '') {
            $settings['from_name'] = $found['from_name'];
        }

        $settings['force_from'] = $found['force_from'];
        $settings['force_name'] = $found['force_name'];
        $settings['return_path'] = $found['return_path'];

        if ($found['supported']) {
            $smtp = $found['smtp'];
            $smtp['pass'] = $found['pass'] !== '' ? SmtpTransport::protect($found['pass']) : '';
            $settings['transport'] = 'smtp';
            $settings['transports']['smtp'] = $smtp;
            $settings['enabled'] = true;
        }

        Mailer::save($settings);
        update_option(self::STATUS_OPTION, 'done', false);
    }

    public static function pending()
    {
        return get_option(self::STATUS_OPTION, '') === '' && self::detect() !== null;
    }

    public static function notice()
    {
        if (!current_user_can('manage_options') || !self::pending()) {
            return;
        }

        // Sur la page E-mails, la carte d'import est déjà affichée.
        if (isset($_GET['page']) && $_GET['page'] === MailPage::SLUG) { // phpcs:ignore WordPress.Security.NonceVerification
            return;
        }

        $found = self::detect();
        ?>
        <div class="notice notice-info smbb-mail-import-notice" data-smbb-bell>
            <p>
                <strong><?php esc_html_e('Réglages WP Mail SMTP trouvés.', 'smbb-wpcodetool'); ?></strong>
                <?php
                echo esc_html($found['supported']
                    ? sprintf(__('CodeTool peut reprendre le serveur %1$s, l\'identifiant, le mot de passe et l\'expéditeur %2$s pour envoyer les e-mails à sa place.', 'smbb-wpcodetool'), $found['smtp']['host'], $found['from_email'])
                    : sprintf(__('Ils utilisent « %s », que CodeTool ne sait pas encore faire : seul l\'expéditeur peut être repris.', 'smbb-wpcodetool'), $found['mailer']));
                ?>
            </p>
            <p>
                <a class="button button-primary" href="<?php echo esc_url(MailPage::url('settings', array('import' => 1))); ?>"><?php esc_html_e('Voir et importer', 'smbb-wpcodetool'); ?></a>
                <a class="button-link" href="<?php echo esc_url(self::actionUrl('dismiss')); ?>"><?php esc_html_e('Non merci', 'smbb-wpcodetool'); ?></a>
            </p>
        </div>
        <?php
    }

    public static function actionUrl($do)
    {
        return wp_nonce_url(admin_url('admin-post.php?action=smbb_codetool_mail_import&do=' . $do), 'smbb_codetool_mail_import');
    }

    /**
     * admin-post : do=import | import_deactivate | dismiss.
     */
    public static function handle()
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Action non autorisée.', 'smbb-wpcodetool'));
        }

        check_admin_referer('smbb_codetool_mail_import');
        $do = isset($_REQUEST['do']) ? sanitize_key(wp_unslash($_REQUEST['do'])) : '';

        if ($do === 'dismiss') {
            update_option(self::STATUS_OPTION, 'dismissed', false);
            wp_safe_redirect(wp_get_referer() ? wp_get_referer() : admin_url());
            exit;
        }

        $found = self::detect();

        if (!$found) {
            wp_safe_redirect(MailPage::url('settings', array('imported' => 'none')));
            exit;
        }

        self::import($found);
        $deactivated = 0;

        if ($do === 'import_deactivate' && $found['plugin'] !== '' && current_user_can('activate_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
            deactivate_plugins($found['plugin']);
            $deactivated = 1;
        }

        wp_safe_redirect(MailPage::url('settings', array(
            'imported' => $found['pass_state'] === 'uncertain' ? 'check_pass' : 'ok',
            'deactivated' => $deactivated,
        )));
        exit;
    }
}
