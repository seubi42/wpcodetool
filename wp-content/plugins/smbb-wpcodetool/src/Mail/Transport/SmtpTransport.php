<?php

namespace Smbb\WpCodeTool\Mail\Transport;

use Smbb\WpCodeTool\Support\Secrets;

defined('ABSPATH') || exit;

/**
 * Connecteur SMTP : n'importe quel serveur (hébergeur, OVH, Gmail en mot de passe d'application, Brevo SMTP...).
 *
 * Le mot de passe est chiffré en base avec Support\Secrets (clé SMBB_SECRET_KEY ou salts WordPress).
 * Il peut aussi être donné par la constante SMBB_MAIL_SMTP_PASS dans wp-config.php : il n'est alors
 * jamais enregistré en base.
 */
final class SmtpTransport implements TransportInterface
{
    const PASS_CONSTANT = 'SMBB_MAIL_SMTP_PASS';

    public function key()
    {
        return 'smtp';
    }

    public function label()
    {
        return __('SMTP', 'smbb-wpcodetool');
    }

    public function defaults()
    {
        return array(
            'host' => '',
            'port' => 587,
            'encryption' => 'tls',
            'autotls' => true,
            'auth' => true,
            'user' => '',
            'pass' => '',
        );
    }

    public static function encryptions()
    {
        return array(
            'tls' => __('STARTTLS (port 587, conseillé)', 'smbb-wpcodetool'),
            'ssl' => __('SSL/TLS direct (port 465)', 'smbb-wpcodetool'),
            'none' => __('Aucun (port 25, déconseillé)', 'smbb-wpcodetool'),
        );
    }

    public function sanitize(array $input, array $previous)
    {
        $previous = array_merge($this->defaults(), $previous);
        $encryption = isset($input['encryption']) ? sanitize_key($input['encryption']) : 'tls';
        $port = isset($input['port']) ? absint($input['port']) : 0;

        $settings = array(
            'host' => isset($input['host']) ? trim(sanitize_text_field($input['host'])) : '',
            'port' => $port > 0 && $port < 65536 ? $port : 587,
            'encryption' => isset(self::encryptions()[$encryption]) ? $encryption : 'tls',
            'autotls' => !empty($input['autotls']),
            'auth' => !empty($input['auth']),
            'user' => isset($input['user']) ? trim(sanitize_text_field($input['user'])) : '',
            'pass' => $previous['pass'],
        );

        // Mot de passe : champ vide = on garde celui enregistré ; case « effacer » = on le retire.
        // Pas de sanitize_text_field ici : il retirerait des caractères valides d'un mot de passe.
        if (!empty($input['pass_clear'])) {
            $settings['pass'] = '';
        } elseif (isset($input['pass']) && (string) $input['pass'] !== '') {
            $settings['pass'] = self::protect((string) $input['pass']);
        }

        return $settings;
    }

    /**
     * Chiffre un mot de passe en clair pour l'enregistrer.
     */
    public static function protect($plain)
    {
        $plain = str_replace(array("\r", "\n", "\0"), '', (string) $plain);

        return $plain === '' ? '' : Secrets::encrypt($plain);
    }

    /**
     * Mot de passe en clair pour l'envoi ('' si aucun, null si illisible : clé changée).
     */
    public static function password(array $settings)
    {
        if (defined(self::PASS_CONSTANT)) {
            return (string) constant(self::PASS_CONSTANT);
        }

        $stored = isset($settings['pass']) ? (string) $settings['pass'] : '';

        return $stored === '' ? '' : Secrets::decrypt($stored);
    }

    public function configure($phpmailer, array $settings)
    {
        $settings = array_merge($this->defaults(), $settings);

        $phpmailer->isSMTP();
        $phpmailer->Host = $settings['host'];
        $phpmailer->Port = (int) $settings['port'];
        $phpmailer->SMTPSecure = $settings['encryption'] === 'none' ? '' : $settings['encryption'];
        // STARTTLS automatique : utile seulement sans chiffrement choisi (sinon déjà chiffré).
        $phpmailer->SMTPAutoTLS = $settings['encryption'] === 'none' ? (bool) $settings['autotls'] : false;
        $phpmailer->SMTPAuth = (bool) $settings['auth'];
        $phpmailer->Timeout = (int) apply_filters('smbb_wpcodetool_mail_smtp_timeout', 30);

        if ($settings['auth']) {
            $phpmailer->Username = $settings['user'];
            $phpmailer->Password = (string) self::password($settings);
        }
    }

    public function ready(array $settings)
    {
        $settings = array_merge($this->defaults(), $settings);

        if ($settings['host'] === '') {
            return __('Le serveur SMTP n\'est pas renseigné.', 'smbb-wpcodetool');
        }

        if ($settings['auth'] && $settings['user'] === '') {
            return __('L\'identifiant SMTP n\'est pas renseigné.', 'smbb-wpcodetool');
        }

        if ($settings['auth'] && self::password($settings) === null) {
            return __('Le mot de passe SMTP enregistré ne peut plus être déchiffré (clé de chiffrement changée) : ressaisissez-le.', 'smbb-wpcodetool');
        }

        return true;
    }

    public function summary(array $settings)
    {
        $settings = array_merge($this->defaults(), $settings);

        if ($settings['host'] === '') {
            return __('SMTP non configuré', 'smbb-wpcodetool');
        }

        $labels = array('tls' => 'STARTTLS', 'ssl' => 'SSL/TLS', 'none' => __('sans chiffrement', 'smbb-wpcodetool'));

        return $settings['host'] . ':' . (int) $settings['port'] . ' · ' . $labels[$settings['encryption']];
    }

    public function renderFields(array $settings)
    {
        $settings = array_merge($this->defaults(), $settings);
        $name = 'transports[smtp]';
        $from_constant = defined(self::PASS_CONSTANT);
        $stored = (string) $settings['pass'] !== '';
        $state = $stored ? Secrets::state($settings['pass']) : 'empty';
        ?>
        <div class="smbb-mail-grid">
            <p class="smbb-mail-field is-wide">
                <label class="smbb-codetool-field-label" for="smbb-mail-smtp-host"><?php esc_html_e('Serveur SMTP', 'smbb-wpcodetool'); ?></label>
                <input type="text" class="regular-text" id="smbb-mail-smtp-host" name="<?php echo esc_attr($name); ?>[host]" value="<?php echo esc_attr($settings['host']); ?>" placeholder="smtp.exemple.fr" autocomplete="off" spellcheck="false">
            </p>
            <p class="smbb-mail-field">
                <label class="smbb-codetool-field-label" for="smbb-mail-smtp-encryption"><?php esc_html_e('Chiffrement', 'smbb-wpcodetool'); ?></label>
                <select id="smbb-mail-smtp-encryption" name="<?php echo esc_attr($name); ?>[encryption]" data-smbb-mail-encryption>
                    <?php foreach (self::encryptions() as $key => $label) : ?>
                        <option value="<?php echo esc_attr($key); ?>" <?php selected($settings['encryption'], $key); ?>><?php echo esc_html($label); ?></option>
                    <?php endforeach; ?>
                </select>
            </p>
            <p class="smbb-mail-field">
                <label class="smbb-codetool-field-label" for="smbb-mail-smtp-port"><?php esc_html_e('Port', 'smbb-wpcodetool'); ?></label>
                <input type="number" class="small-text" min="1" max="65535" id="smbb-mail-smtp-port" name="<?php echo esc_attr($name); ?>[port]" value="<?php echo esc_attr((string) $settings['port']); ?>" data-smbb-mail-port>
            </p>
        </div>

        <div data-smbb-mail-autotls<?php echo $settings['encryption'] === 'none' ? '' : ' hidden'; ?>>
            <?php \Smbb\WpCodeTool\Admin\Pages\AppearancePage::toggle($name . '[autotls]', $settings['autotls'], __('STARTTLS automatique', 'smbb-wpcodetool'), __('Chiffre quand même si le serveur le propose. À couper seulement si un vieux serveur refuse.', 'smbb-wpcodetool')); ?>
        </div>

        <?php \Smbb\WpCodeTool\Admin\Pages\AppearancePage::toggle($name . '[auth]', $settings['auth'], __('Authentification', 'smbb-wpcodetool'), __('Identifiant et mot de passe du compte e-mail. Presque toujours nécessaire.', 'smbb-wpcodetool')); ?>

        <div class="smbb-mail-grid" data-smbb-mail-auth<?php echo $settings['auth'] ? '' : ' hidden'; ?>>
            <p class="smbb-mail-field is-wide">
                <label class="smbb-codetool-field-label" for="smbb-mail-smtp-user"><?php esc_html_e('Identifiant', 'smbb-wpcodetool'); ?></label>
                <input type="text" class="regular-text" id="smbb-mail-smtp-user" name="<?php echo esc_attr($name); ?>[user]" value="<?php echo esc_attr($settings['user']); ?>" autocomplete="off" spellcheck="false">
            </p>
            <p class="smbb-mail-field is-wide">
                <label class="smbb-codetool-field-label" for="smbb-mail-smtp-pass"><?php esc_html_e('Mot de passe', 'smbb-wpcodetool'); ?></label>
                <?php if ($from_constant) : ?>
                    <span class="smbb-codetool-encryption-state is-good"><span class="dashicons dashicons-lock" aria-hidden="true"></span><?php echo esc_html(sprintf(__('Donné par la constante %s de wp-config.php.', 'smbb-wpcodetool'), self::PASS_CONSTANT)); ?></span>
                <?php else : ?>
                    <input type="password" class="regular-text" id="smbb-mail-smtp-pass" name="<?php echo esc_attr($name); ?>[pass]" value="" autocomplete="new-password" placeholder="<?php echo $stored ? esc_attr__('•••••••• enregistré, laisser vide pour le garder', 'smbb-wpcodetool') : ''; ?>">
                    <?php if ($stored) : ?>
                        <label class="smbb-mail-inline"><input type="checkbox" name="<?php echo esc_attr($name); ?>[pass_clear]" value="1"> <?php esc_html_e('Effacer le mot de passe enregistré', 'smbb-wpcodetool'); ?></label>
                    <?php endif; ?>
                    <?php if ($state === 'plain') : ?>
                        <span class="smbb-codetool-encryption-state is-warn"><span class="dashicons dashicons-warning" aria-hidden="true"></span><?php esc_html_e('Enregistré en clair : le chiffrement n\'est pas disponible (voir CodeTool › Chiffrement).', 'smbb-wpcodetool'); ?></span>
                    <?php elseif ($state === 'unknown') : ?>
                        <span class="smbb-codetool-encryption-state is-warn"><span class="dashicons dashicons-warning" aria-hidden="true"></span><?php esc_html_e('Chiffré avec une clé disparue : ressaisissez le mot de passe.', 'smbb-wpcodetool'); ?></span>
                    <?php elseif ($stored) : ?>
                        <span class="smbb-codetool-encryption-state is-good"><span class="dashicons dashicons-lock" aria-hidden="true"></span><?php esc_html_e('Chiffré en base, jamais réaffiché.', 'smbb-wpcodetool'); ?></span>
                    <?php endif; ?>
                <?php endif; ?>
            </p>
        </div>
        <?php
    }
}
