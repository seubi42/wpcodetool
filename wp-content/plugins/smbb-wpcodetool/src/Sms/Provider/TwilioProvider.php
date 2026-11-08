<?php

namespace Smbb\WpCodeTool\Sms\Provider;

use Smbb\WpCodeTool\Connector\SmbbTwilioSmsClient;
use Smbb\WpCodeTool\Support\Secrets;

defined('ABSPATH') || exit;

/**
 * Connecteur Twilio : Account SID + Auth Token (chiffré) + Messaging Service SID (porte le nom d'expéditeur).
 * L'envoi passe par Connector\SmbbTwilioSmsClient. Le jeton peut aussi venir de la constante
 * SMBB_TWILIO_TOKEN de wp-config.php (jamais enregistré en base dans ce cas).
 *
 * Suivi : chaque envoi donne à Twilio l'adresse du webhook de CodeTool (StatusCallback), dont la
 * signature X-Twilio-Signature est vérifiée avec l'Auth Token ; le prix est relu par l'API Messages.
 */
final class TwilioProvider implements ProviderInterface, TrackingInterface
{
    const TOKEN_CONSTANT = 'SMBB_TWILIO_TOKEN';

    public function key()
    {
        return 'twilio';
    }

    public function label()
    {
        return 'Twilio';
    }

    public function defaults()
    {
        return array(
            'account_sid' => '',
            'token' => '',
            'service_sid' => '',
        );
    }

    public function sanitize(array $input, array $previous)
    {
        $previous = array_merge($this->defaults(), $previous);
        $clean = static function ($value) {
            return preg_replace('/[^A-Za-z0-9]/', '', (string) $value);
        };

        $settings = array(
            'account_sid' => isset($input['account_sid']) ? $clean($input['account_sid']) : '',
            'service_sid' => isset($input['service_sid']) ? $clean($input['service_sid']) : '',
            'token' => $previous['token'],
        );

        if (!empty($input['token_clear'])) {
            $settings['token'] = '';
        } elseif (isset($input['token']) && trim((string) $input['token']) !== '') {
            $settings['token'] = self::protect($input['token']);
        }

        return $settings;
    }

    public static function protect($plain)
    {
        $plain = trim(str_replace(array("\r", "\n", "\0"), '', (string) $plain));

        return $plain === '' ? '' : Secrets::encrypt($plain);
    }

    /**
     * Jeton en clair ('' si aucun, null si illisible : clé de chiffrement changée).
     */
    public static function token(array $settings)
    {
        if (defined(self::TOKEN_CONSTANT)) {
            return (string) constant(self::TOKEN_CONSTANT);
        }

        $stored = isset($settings['token']) ? (string) $settings['token'] : '';

        return $stored === '' ? '' : Secrets::decrypt($stored);
    }

    public function ready(array $settings)
    {
        $settings = array_merge($this->defaults(), $settings);

        if ($settings['account_sid'] === '') {
            return __('L\'Account SID Twilio n\'est pas renseigné.', 'smbb-wpcodetool');
        }

        if ($settings['service_sid'] === '') {
            return __('Le Messaging Service SID Twilio n\'est pas renseigné.', 'smbb-wpcodetool');
        }

        $token = self::token($settings);

        if ($token === null) {
            return __('L\'Auth Token Twilio enregistré ne peut plus être déchiffré (clé de chiffrement changée) : ressaisissez-le.', 'smbb-wpcodetool');
        }

        if ($token === '') {
            return __('L\'Auth Token Twilio n\'est pas renseigné.', 'smbb-wpcodetool');
        }

        return true;
    }

    public function send(array $settings, $to, $body)
    {
        $settings = array_merge($this->defaults(), $settings);
        $client = new SmbbTwilioSmsClient($settings['service_sid'], $settings['account_sid'], (string) self::token($settings));
        $extra = !empty($settings['_status_callback']) ? array('StatusCallback' => (string) $settings['_status_callback']) : array();
        $result = $client->send($to, $body, $extra);

        return array(
            'id' => isset($result['sid']) ? (string) $result['sid'] : '',
            'status' => isset($result['status']) ? (string) $result['status'] : '',
            'to' => isset($result['to']) ? (string) $result['to'] : (string) $to,
            'raw' => isset($result['raw']) && is_array($result['raw']) ? $result['raw'] : array(),
        );
    }

    public function parseCallback(array $settings, $url, array $params, array $headers)
    {
        $settings = array_merge($this->defaults(), $settings);
        $signature = isset($headers['x-twilio-signature']) ? (string) $headers['x-twilio-signature'] : '';
        $token = (string) self::token($settings);
        $urls = is_array($url) ? $url : array($url);
        $valid = false;

        foreach (array_unique(array_filter($urls)) as $candidate) {
            if (SmbbTwilioSmsClient::validSignature($token, $candidate, $params, $signature)) {
                $valid = true;
                break;
            }
        }

        if (!$valid) {
            return new \WP_Error('smbb_sms_bad_signature', 'Signature Twilio invalide.', array('status' => 403));
        }

        if (isset($params['AccountSid']) && $settings['account_sid'] !== '' && $params['AccountSid'] !== $settings['account_sid']) {
            return new \WP_Error('smbb_sms_other_account', 'Compte Twilio différent.', array('status' => 403));
        }

        $id = isset($params['MessageSid']) ? $params['MessageSid'] : (isset($params['SmsSid']) ? $params['SmsSid'] : '');
        $status = isset($params['MessageStatus']) ? $params['MessageStatus'] : (isset($params['SmsStatus']) ? $params['SmsStatus'] : '');

        return array(
            'id' => preg_replace('/[^A-Za-z0-9]/', '', (string) $id),
            'status' => sanitize_key((string) $status),
            'error_code' => isset($params['ErrorCode']) ? preg_replace('/[^0-9]/', '', (string) $params['ErrorCode']) : '',
        );
    }

    public function fetch(array $settings, $id)
    {
        $settings = array_merge($this->defaults(), $settings);
        $client = new SmbbTwilioSmsClient($settings['service_sid'], $settings['account_sid'], (string) self::token($settings));
        $raw = $client->fetch($id);
        $price = isset($raw['price']) && $raw['price'] !== null && $raw['price'] !== '' ? abs((float) $raw['price']) : null;

        return array(
            'status' => isset($raw['status']) ? sanitize_key((string) $raw['status']) : '',
            'error_code' => isset($raw['error_code']) && $raw['error_code'] !== null ? preg_replace('/[^0-9]/', '', (string) $raw['error_code']) : '',
            'price' => $price,
            'currency' => isset($raw['price_unit']) ? strtoupper(substr(preg_replace('/[^A-Za-z]/', '', (string) $raw['price_unit']), 0, 3)) : '',
            'segments' => isset($raw['num_segments']) ? (int) $raw['num_segments'] : 0,
        );
    }

    public function summary(array $settings)
    {
        $settings = array_merge($this->defaults(), $settings);

        if ($settings['service_sid'] === '') {
            return __('Twilio non configuré', 'smbb-wpcodetool');
        }

        return sprintf(__('Twilio · service %s…', 'smbb-wpcodetool'), substr($settings['service_sid'], 0, 8));
    }

    public function renderFields(array $settings)
    {
        $settings = array_merge($this->defaults(), $settings);
        $name = 'providers[twilio]';
        $stored = (string) $settings['token'] !== '';
        $state = $stored ? Secrets::state($settings['token']) : 'empty';
        ?>
        <div class="smbb-mail-grid">
            <p class="smbb-mail-field is-wide">
                <label class="smbb-codetool-field-label" for="smbb-sms-twilio-sid"><?php esc_html_e('Account SID', 'smbb-wpcodetool'); ?></label>
                <input type="text" class="regular-text code" id="smbb-sms-twilio-sid" name="<?php echo esc_attr($name); ?>[account_sid]" value="<?php echo esc_attr($settings['account_sid']); ?>" placeholder="AC…" autocomplete="off" spellcheck="false">
            </p>
            <p class="smbb-mail-field is-wide">
                <label class="smbb-codetool-field-label" for="smbb-sms-twilio-service"><?php esc_html_e('Messaging Service SID', 'smbb-wpcodetool'); ?></label>
                <input type="text" class="regular-text code" id="smbb-sms-twilio-service" name="<?php echo esc_attr($name); ?>[service_sid]" value="<?php echo esc_attr($settings['service_sid']); ?>" placeholder="MG…" autocomplete="off" spellcheck="false">
            </p>
            <p class="smbb-mail-field is-wide">
                <label class="smbb-codetool-field-label" for="smbb-sms-twilio-token"><?php esc_html_e('Auth Token', 'smbb-wpcodetool'); ?></label>
                <?php if (defined(self::TOKEN_CONSTANT)) : ?>
                    <span class="smbb-codetool-encryption-state is-good"><span class="dashicons dashicons-lock" aria-hidden="true"></span><?php echo esc_html(sprintf(__('Donné par la constante %s de wp-config.php.', 'smbb-wpcodetool'), self::TOKEN_CONSTANT)); ?></span>
                <?php else : ?>
                    <input type="password" class="regular-text" id="smbb-sms-twilio-token" name="<?php echo esc_attr($name); ?>[token]" value="" autocomplete="new-password" placeholder="<?php echo $stored ? esc_attr__('•••••••• enregistré, laisser vide pour le garder', 'smbb-wpcodetool') : ''; ?>">
                    <?php if ($stored) : ?>
                        <label class="smbb-mail-inline"><input type="checkbox" name="<?php echo esc_attr($name); ?>[token_clear]" value="1"> <?php esc_html_e('Effacer le jeton enregistré', 'smbb-wpcodetool'); ?></label>
                    <?php endif; ?>
                    <?php if ($state === 'plain') : ?>
                        <span class="smbb-codetool-encryption-state is-warn"><span class="dashicons dashicons-warning" aria-hidden="true"></span><?php esc_html_e('Enregistré en clair : le chiffrement n\'est pas disponible (voir CodeTool › Chiffrement).', 'smbb-wpcodetool'); ?></span>
                    <?php elseif ($state === 'unknown') : ?>
                        <span class="smbb-codetool-encryption-state is-warn"><span class="dashicons dashicons-warning" aria-hidden="true"></span><?php esc_html_e('Chiffré avec une clé disparue : ressaisissez le jeton.', 'smbb-wpcodetool'); ?></span>
                    <?php elseif ($stored) : ?>
                        <span class="smbb-codetool-encryption-state is-good"><span class="dashicons dashicons-lock" aria-hidden="true"></span><?php esc_html_e('Chiffré en base, jamais réaffiché.', 'smbb-wpcodetool'); ?></span>
                    <?php endif; ?>
                <?php endif; ?>
            </p>
        </div>
        <p class="description"><?php esc_html_e('Dans la console Twilio : Account SID et Auth Token sur l\'accueil du compte, Messaging Service SID dans Messaging › Services (le service porte le numéro ou le nom d\'expéditeur).', 'smbb-wpcodetool'); ?></p>
        <?php
    }
}
