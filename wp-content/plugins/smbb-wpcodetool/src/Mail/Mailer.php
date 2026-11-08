<?php

namespace Smbb\WpCodeTool\Mail;

use Smbb\WpCodeTool\Mail\Transport\SmtpTransport;
use Smbb\WpCodeTool\Mail\Transport\TransportInterface;

defined('ABSPATH') || exit;

/**
 * Module « E-mails » de CodeTool : envoie les e-mails de WordPress par le connecteur choisi
 * (SMTP pour l'instant), garde un journal tournant, alerte quand l'envoi ne marche plus.
 *
 * Réglages : option smbb_codetool_mail (voir defaults()). Page : CodeTool › E-mails.
 * Les autres briques : MailLog (journal), MailHealth (alertes + widget), WpMailSmtpImport (migration).
 */
final class Mailer
{
    const OPTION = 'smbb_codetool_mail';

    /** @var array<string,TransportInterface>|null */
    private static $transports = null;

    /** @var array|null Réglages forcés le temps d'un envoi de test. */
    private static $override = null;

    /** @var string[]|null Transcription SMTP capturée pendant un test. */
    private static $debug = null;

    public static function hooks()
    {
        add_action('phpmailer_init', array(__CLASS__, 'configure'), 999);
        add_filter('wp_mail_from', array(__CLASS__, 'filterFrom'), 999);
        add_filter('wp_mail_from_name', array(__CLASS__, 'filterFromName'), 999);

        add_action('wp_ajax_smbb_codetool_mail_view', array(\Smbb\WpCodeTool\Admin\Pages\MailPage::class, 'ajaxView'));
        add_action('admin_init', array(\Smbb\WpCodeTool\Admin\Pages\MailPage::class, 'redirectOldSlug'), 1);
        add_action('admin_post_smbb_codetool_mail_attachment', array(\Smbb\WpCodeTool\Admin\Pages\MailPage::class, 'downloadAttachment'));

        MailLog::hooks();

        // Canal SMS de CodeTool › Communication (journal, alerte, widget).
        \Smbb\WpCodeTool\Sms\Sms::hooks();
        MailHealth::hooks();
        WpMailSmtpImport::hooks();
    }

    public static function defaults()
    {
        return array(
            'enabled' => false,
            'transport' => 'smtp',
            'from_email' => '',
            'from_name' => '',
            'force_from' => true,
            'force_name' => false,
            'return_path' => true,
            'log' => true,
            'log_body' => true,
            'log_days' => 15,
            'log_attachments' => true,
            'alerts' => true,
            'transports' => array(),
        );
    }

    public static function settings()
    {
        $stored = get_option(self::OPTION, array());
        $settings = array_merge(self::defaults(), is_array($stored) ? $stored : array());

        if (!is_array($settings['transports'])) {
            $settings['transports'] = array();
        }

        return $settings;
    }

    public static function save(array $settings)
    {
        $settings = array_merge(self::defaults(), $settings);
        update_option(self::OPTION, $settings, true);
    }

    /**
     * Connecteurs disponibles. Filtre smbb_wpcodetool_mail_transports pour en ajouter.
     *
     * @return array<string,TransportInterface>
     */
    public static function transports()
    {
        if (self::$transports === null) {
            $list = apply_filters('smbb_wpcodetool_mail_transports', array('smtp' => new SmtpTransport()));
            self::$transports = array();

            foreach ((array) $list as $key => $transport) {
                if ($transport instanceof TransportInterface) {
                    self::$transports[$transport->key()] = $transport;
                }
            }
        }

        return self::$transports;
    }

    /**
     * @return TransportInterface|null
     */
    public static function transport(array $settings = null)
    {
        $settings = $settings === null ? self::settings() : $settings;
        $all = self::transports();

        return isset($all[$settings['transport']]) ? $all[$settings['transport']] : null;
    }

    public static function transportSettings(array $settings = null)
    {
        $settings = $settings === null ? self::settings() : $settings;
        $transport = self::transport($settings);

        if (!$transport) {
            return array();
        }

        $own = isset($settings['transports'][$transport->key()]) && is_array($settings['transports'][$transport->key()])
            ? $settings['transports'][$transport->key()]
            : array();

        return array_merge($transport->defaults(), $own);
    }

    /**
     * WP Mail SMTP (gratuit ou Pro) est-il actif ? Dans ce cas CodeTool ne touche pas aux envois :
     * deux plugins qui configurent PHPMailer en même temps, c'est la panne assurée.
     */
    public static function conflict()
    {
        return defined('WPMS_PLUGIN_VER');
    }

    /**
     * CodeTool envoie-t-il réellement les e-mails ?
     */
    public static function active()
    {
        $settings = self::settings();

        return !empty($settings['enabled']) && !self::conflict() && self::transport($settings) !== null;
    }

    /**
     * Raison pour laquelle le module n'envoie pas ('' s'il envoie).
     */
    public static function inactiveReason()
    {
        $settings = self::settings();

        if (empty($settings['enabled'])) {
            return __('Module coupé : WordPress envoie avec sa méthode par défaut (fonction mail de PHP).', 'smbb-wpcodetool');
        }

        if (self::conflict()) {
            return __('WP Mail SMTP est encore actif : CodeTool le laisse envoyer tant qu\'il n\'est pas désactivé.', 'smbb-wpcodetool');
        }

        $transport = self::transport($settings);

        if (!$transport) {
            return __('Le connecteur choisi n\'existe plus.', 'smbb-wpcodetool');
        }

        $ready = $transport->ready(self::transportSettings($settings));

        return $ready === true ? '' : (string) $ready;
    }

    /**
     * phpmailer_init : branche le connecteur sur le PHPMailer de WordPress.
     *
     * @param \PHPMailer\PHPMailer\PHPMailer $phpmailer
     */
    public static function configure($phpmailer)
    {
        $settings = self::$override !== null ? self::$override : self::settings();

        if (self::$override === null && !self::active()) {
            return;
        }

        $transport = self::transport($settings);

        if (!$transport) {
            return;
        }

        $transport->configure($phpmailer, self::transportSettings($settings));

        if (!empty($settings['return_path'])) {
            $phpmailer->Sender = $phpmailer->From;
        }

        if (self::$debug !== null) {
            $phpmailer->SMTPDebug = 2; // SMTP::DEBUG_SERVER : commandes et réponses.
            $phpmailer->Debugoutput = static function ($line) {
                self::$debug[] = rtrim((string) $line);
            };
        } else {
            // Le PHPMailer de WordPress est réutilisé d'un envoi à l'autre dans la même requête.
            $phpmailer->SMTPDebug = 0;
        }
    }

    public static function filterFrom($email)
    {
        $settings = self::$override !== null ? self::$override : self::settings();

        if ((self::$override === null && !self::active()) || !is_email($settings['from_email'])) {
            return $email;
        }

        if (!empty($settings['force_from']) || self::isDefaultFrom($email)) {
            return $settings['from_email'];
        }

        return $email;
    }

    public static function filterFromName($name)
    {
        $settings = self::$override !== null ? self::$override : self::settings();

        if ((self::$override === null && !self::active()) || (string) $settings['from_name'] === '') {
            return $name;
        }

        if (!empty($settings['force_name']) || $name === 'WordPress') {
            return $settings['from_name'];
        }

        return $name;
    }

    /**
     * Adresse « wordpress@domaine » que WordPress met quand personne n'a choisi d'expéditeur.
     */
    private static function isDefaultFrom($email)
    {
        $host = wp_parse_url(network_home_url(), PHP_URL_HOST);
        $host = is_string($host) ? preg_replace('/^www\./', '', strtolower($host)) : '';

        return $email === '' || strtolower((string) $email) === 'wordpress@' . $host;
    }

    /**
     * Envoie un e-mail de test avec les réglages donnés (même si le module est coupé),
     * en capturant la transcription SMTP.
     *
     * @return array{ok:bool,error:string,transcript:string[],duration:float}
     */
    public static function sendTest($to, array $settings = null)
    {
        $settings = $settings === null ? self::settings() : $settings;
        $error = '';
        $catch = static function ($wp_error) use (&$error) {
            if (is_wp_error($wp_error)) {
                $error = $wp_error->get_error_message();
            }
        };

        $transport = self::transport($settings);
        $ready = $transport ? $transport->ready(self::transportSettings($settings)) : __('Aucun connecteur.', 'smbb-wpcodetool');

        if ($ready !== true) {
            return array('ok' => false, 'error' => (string) $ready, 'transcript' => array(), 'duration' => 0.0);
        }

        self::$override = $settings;
        self::$debug = array();
        add_action('wp_mail_failed', $catch, 1);
        add_filter('smbb_wpcodetool_mail_smtp_timeout', array(__CLASS__, 'testTimeout'));

        $start = microtime(true);
        $site = wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES);
        $subject = sprintf(__('[%s] Test d\'envoi CodeTool', 'smbb-wpcodetool'), $site);
        $ok = wp_mail($to, $subject, self::testBody($settings), array('Content-Type: text/html; charset=UTF-8'));
        $duration = microtime(true) - $start;

        remove_action('wp_mail_failed', $catch, 1);
        remove_filter('smbb_wpcodetool_mail_smtp_timeout', array(__CLASS__, 'testTimeout'));
        $transcript = self::maskTranscript(self::$debug);
        self::$override = null;
        self::$debug = null;

        if (!$ok && $error === '') {
            $error = __('L\'envoi a échoué sans message d\'erreur (un autre plugin a peut-être bloqué wp_mail).', 'smbb-wpcodetool');
        }

        return array('ok' => (bool) $ok, 'error' => $error, 'transcript' => $transcript, 'duration' => $duration);
    }

    public static function testTimeout()
    {
        return 15;
    }

    private static function testBody(array $settings)
    {
        $transport = self::transport($settings);
        $summary = $transport ? $transport->summary(self::transportSettings($settings)) : '';

        return '<div style="font-family:-apple-system,Segoe UI,Roboto,sans-serif;max-width:520px;margin:24px auto;padding:24px;border:1px solid #e3e3e8;border-radius:12px">'
            . '<p style="font-size:32px;margin:0 0 8px">✉️</p>'
            . '<h2 style="margin:0 0 12px;font-size:20px">' . esc_html__('Bravo, l\'envoi fonctionne !', 'smbb-wpcodetool') . '</h2>'
            . '<p style="margin:0 0 8px;color:#444">' . esc_html(sprintf(__('Cet e-mail de test a été envoyé par %1$s via %2$s.', 'smbb-wpcodetool'), home_url(), $summary)) . '</p>'
            . '<p style="margin:0;color:#888;font-size:13px">' . esc_html(wp_date(get_option('date_format') . ' H:i:s')) . '</p>'
            . '</div>';
    }

    /**
     * Masque l'identifiant et le mot de passe dans la transcription (AUTH LOGIN / PLAIN les envoient en base64).
     *
     * @param string[] $lines
     * @return string[]
     */
    public static function maskTranscript(array $lines)
    {
        $out = array();
        $masking = false;

        foreach ($lines as $line) {
            if (preg_match('/^(.*CLIENT -> SERVER:\s*AUTH\s+[A-Z0-9-]+)(.*)$/i', $line, $m)) {
                $out[] = $m[1] . (trim($m[2]) !== '' ? ' [masqué]' : '');
                $masking = true;
                continue;
            }

            if ($masking && preg_match('/^(.*CLIENT -> SERVER:)/', $line, $m)) {
                $out[] = $m[1] . ' [masqué]';
                continue;
            }

            if ($masking && preg_match('/SERVER -> CLIENT:\s*(\d{3})/', $line, $m) && $m[1] !== '334') {
                $masking = false;
            }

            $out[] = $line;
        }

        return $out;
    }
}
