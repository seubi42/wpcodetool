<?php

namespace Smbb\WpCodeTool\Sms;

defined('ABSPATH') || exit;

/**
 * Vérification d'un numéro de téléphone par code SMS, commune à tous les plugins (sans compte WordPress).
 *
 *     // 1. envoyer le code
 *     $sent = PhoneVerification::start('passflow-ticket-12-r1', '0612345678', array('label' => 'votre billet', 'plugin' => 'passflow'));
 *     // 2. vérifier le code saisi : renvoie le numéro confirmé (format international) ou une WP_Error
 *     $phone = PhoneVerification::check('passflow-ticket-12-r1', $_POST['code']);
 *
 * La clé identifie ce qu'on vérifie (un billet, un formulaire…). Code à 6 chiffres, valable 10 minutes,
 * 5 essais, un nouvel envoi au plus toutes les 60 secondes et 5 envois par heure. Le code n'est
 * jamais stocké en clair (transient, HMAC avec les clés WordPress).
 */
final class PhoneVerification
{
    const TTL = 600;
    const ATTEMPTS = 5;
    const RESEND = 60;
    const MAX_SENDS = 5;

    /**
     * Envoie un code au numéro.
     *
     * @param array<string,mixed> $context label (« votre billet »), plugin, source : texte du SMS et journal SMS
     * @return true|\WP_Error
     */
    public static function start($key, $phone, array $context = array())
    {
        $d = 'smbb-wpcodetool';
        $phone = trim((string) $phone);

        if (preg_replace('/\D+/', '', $phone) === '' || strlen(preg_replace('/\D+/', '', $phone)) < 9) {
            return new \WP_Error('smbb_phone_invalid', __('Ce numéro de téléphone ne semble pas valide.', $d));
        }

        $state = self::state($key);
        $now = time();

        if ($state && $state['phone'] === $phone && $now - (int) $state['last_send'] < self::RESEND) {
            return new \WP_Error('smbb_phone_wait', sprintf(__('Un code vient d\'être envoyé. Attendez %d secondes avant d\'en demander un autre.', $d), self::RESEND - ($now - (int) $state['last_send'])));
        }

        $sends = $state && $now - (int) $state['first_send'] < HOUR_IN_SECONDS ? (int) $state['sends'] : 0;

        if ($sends >= self::MAX_SENDS) {
            return new \WP_Error('smbb_phone_limit', __('Trop de codes demandés. Réessayez dans une heure.', $d));
        }

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $site = wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES);
        $site = function_exists('mb_strimwidth') ? mb_strimwidth($site, 0, 30, '…') : substr($site, 0, 30);
        $label = isset($context['label']) && $context['label'] !== '' ? (string) $context['label'] : __('votre numéro', $d);
        $body = sprintf(__('%1$s : code %2$s pour confirmer ce numéro (%3$s). Valable %4$d min. Ne le donnez à personne.', $d), $site, $code, $label, (int) (self::TTL / MINUTE_IN_SECONDS));
        $result = Sms::send($phone, $body, array(
            'source' => isset($context['source']) ? (string) $context['source'] : 'phone-verification',
            'plugin' => isset($context['plugin']) ? (string) $context['plugin'] : 'codetool',
        ));

        if (is_wp_error($result)) {
            return $result;
        }

        set_transient(self::name($key), array(
            'phone' => $phone,
            'code' => self::hash($key, $code),
            'expires' => $now + self::TTL,
            'attempts' => 0,
            'sends' => $sends + 1,
            'first_send' => $sends ? (int) $state['first_send'] : $now,
            'last_send' => $now,
        ), HOUR_IN_SECONDS);

        return true;
    }

    /**
     * Vérifie le code saisi. Renvoie le numéro confirmé, ou une WP_Error.
     *
     * @return string|\WP_Error
     */
    public static function check($key, $code)
    {
        $d = 'smbb-wpcodetool';
        $state = self::state($key);
        $code = preg_replace('/\D+/', '', (string) $code);

        if (!$state || empty($state['code'])) {
            return new \WP_Error('smbb_phone_none', __('Demandez d\'abord un code.', $d));
        }

        if (time() > (int) $state['expires']) {
            return new \WP_Error('smbb_phone_expired', __('Ce code a expiré : demandez-en un nouveau.', $d));
        }

        if ((int) $state['attempts'] >= self::ATTEMPTS) {
            return new \WP_Error('smbb_phone_attempts', __('Trop d\'essais : demandez un nouveau code.', $d));
        }

        if (!hash_equals((string) $state['code'], self::hash($key, $code))) {
            $state['attempts'] = (int) $state['attempts'] + 1;
            set_transient(self::name($key), $state, HOUR_IN_SECONDS);

            return new \WP_Error('smbb_phone_wrong', sprintf(__('Code incorrect. Encore %d essai(s).', $d), self::ATTEMPTS - (int) $state['attempts']));
        }

        // Code utilisé : il ne resservira pas. Le compteur d'envois reste (limite par heure).
        $state['code'] = '';
        set_transient(self::name($key), $state, HOUR_IN_SECONDS);

        return (string) $state['phone'];
    }

    /**
     * Numéro auquel un code est en attente pour cette clé, ou ''.
     */
    public static function pending($key)
    {
        $state = self::state($key);

        return $state && !empty($state['code']) && time() <= (int) $state['expires'] ? (string) $state['phone'] : '';
    }

    /**
     * @return array<string,mixed>|null
     */
    private static function state($key)
    {
        $state = get_transient(self::name($key));

        return is_array($state) ? $state : null;
    }

    private static function name($key)
    {
        return 'smbb_pv_' . md5((string) $key);
    }

    private static function hash($key, $code)
    {
        return hash_hmac('sha256', (string) $key . '|' . (string) $code, wp_salt('auth'));
    }
}
