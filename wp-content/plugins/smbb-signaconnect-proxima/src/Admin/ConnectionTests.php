<?php

namespace Smbb\SignConnectProxima\Admin;

use Smbb\SignConnectProxima\Repository\AuditLog;
use Smbb\SignConnectProxima\Repository\Organizations;
use Smbb\SignConnectProxima\Service\OpenAi;
use Smbb\SignConnectProxima\Service\Sms;
use Smbb\SignConnectProxima\Service\Storage;
use Smbb\SignConnectProxima\Service\Universign;

defined('ABSPATH') || exit;

/**
 * Boutons « Tester » de la fiche organisation. Le test porte sur les réglages enregistrés.
 */
final class ConnectionTests
{
    const ACTION = 'scp_test_connection';

    /** Dernier résultat de chaque test, par organisation : array(org_id => array(type => {success, message, at})). */
    const OPTION = 'scp_connection_tests';

    public static function hooks()
    {
        add_action('admin_post_' . self::ACTION, array(__CLASS__, 'handle'));
    }

    public static function url($organization_id, $type)
    {
        return wp_nonce_url(
            add_query_arg(array('action' => self::ACTION, 'organization_id' => absint($organization_id), 'type' => $type), admin_url('admin-post.php')),
            self::ACTION . '_' . absint($organization_id) . '_' . $type
        );
    }

    public static function button($organization_id, $type, $label)
    {
        if (absint($organization_id) < 1) {
            return '<p class="description">' . esc_html__("Le test sera disponible une fois l'organisation enregistrée.", 'smbb-signaconnect-proxima') . '</p>';
        }

        return self::resultHtml($organization_id, $type)
            . '<p><a class="button" href="' . esc_url(self::url($organization_id, $type)) . '">' . esc_html($label) . '</a> '
            . '<span class="description">' . esc_html__('Teste les réglages enregistrés : enregistrez avant de tester.', 'smbb-signaconnect-proxima') . '</span></p>';
    }

    public static function handle()
    {
        $organization_id = isset($_GET['organization_id']) ? absint($_GET['organization_id']) : 0;
        $type = isset($_GET['type']) ? sanitize_key(wp_unslash($_GET['type'])) : '';

        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Action non autorisée.', 'smbb-signaconnect-proxima'), '', array('response' => 403));
        }

        check_admin_referer(self::ACTION . '_' . $organization_id . '_' . $type);

        // Tout est intercepté (y compris les erreurs PHP) : le résultat et l'erreur complète vont au journal.
        try {
            $organization = Organizations::find($organization_id);

            if (!$organization) {
                throw new \RuntimeException(__('Organisation introuvable.', 'smbb-signaconnect-proxima'));
            }

            if (!in_array($type, array('s3', 'openai', 'twilio', 'universign'), true)) {
                throw new \RuntimeException(__('Test inconnu.', 'smbb-signaconnect-proxima'));
            }

            $result = array('success' => true, 'message' => self::run($organization, $type));
            $context = array('success' => true);
        } catch (\Throwable $exception) {
            $result = array('success' => false, 'message' => sprintf(__('Échec du test : %s', 'smbb-signaconnect-proxima'), self::shortError($exception)));
            $context = array(
                'success' => false,
                'error_class' => get_class($exception),
                'error' => mb_substr($exception->getMessage(), 0, 4000),
                'file' => basename($exception->getFile()) . ':' . $exception->getLine(),
            );
        }

        try {
            AuditLog::record($type . '_test', $result['message'], $context, $organization_id ?: null);
        } catch (\Throwable $exception) {
            // Le journal lui-même est indisponible (tables non synchronisées) : on affiche quand même le résultat.
        }

        // Gardé jusqu'au test suivant (pas un message à usage unique) : il reste lisible après un rechargement.
        $all = get_option(self::OPTION, array());
        $all = is_array($all) ? $all : array();
        $all[$organization_id][$type] = $result + array('at' => time());
        update_option(self::OPTION, $all, false);

        $back = wp_get_referer() ?: admin_url('admin.php?page=smbb-codetool-scp_organization&view=form&id=' . $organization_id);
        $back = add_query_arg('scp_tested', $type, remove_query_arg('scp_tested', strtok($back, '#')));
        wp_safe_redirect($back . '#' . self::anchor($type));
        exit;
    }

    private static function run(array $organization, $type)
    {
        switch ($type) {
            case 's3':
                return Storage::forOrganization($organization)->selfTest();

            case 'universign':
                return (new Universign($organization))->check();

            case 'openai':
                return sprintf(__('Clé OpenAI OK : le modèle %s est accessible.', 'smbb-signaconnect-proxima'), OpenAi::checkModel($organization));

            case 'twilio':
                $account = Sms::checkAccount($organization);
                $phone = isset($_GET['phone']) ? sanitize_text_field(wp_unslash($_GET['phone'])) : '';
                $message = sprintf(__('Identifiants Twilio OK (compte « %s »).', 'smbb-signaconnect-proxima'), isset($account['friendly_name']) ? $account['friendly_name'] : '');

                if ($phone !== '') {
                    Sms::send($organization, $phone, sprintf(__('Test SignaConnect pour %s : la configuration SMS fonctionne.', 'smbb-signaconnect-proxima'), $organization['name']));
                    $message .= ' ' . sprintf(__('SMS de test envoyé au %s.', 'smbb-signaconnect-proxima'), Sms::normalizePhone($phone));
                }

                return $message;
        }

        return '';
    }

    /**
     * Bouton Twilio : champ numéro optionnel pour recevoir un vrai SMS de test.
     */
    public static function twilioButton($organization_id)
    {
        if (absint($organization_id) < 1) {
            return self::button(0, 'twilio', '');
        }

        $input_id = 'scp-twilio-test-phone';

        return self::resultHtml($organization_id, 'twilio')
            . '<p><input type="tel" id="' . $input_id . '" class="regular-text" placeholder="' . esc_attr__('Numéro pour un SMS de test (optionnel)', 'smbb-signaconnect-proxima') . '" form="scp-no-form"> '
            . '<a class="button" href="' . esc_url(self::url($organization_id, 'twilio')) . '" onclick="var p=document.getElementById(\'' . $input_id . '\').value;if(p){this.href+=\'&phone=\'+encodeURIComponent(p);}">'
            . esc_html__('Tester Twilio', 'smbb-signaconnect-proxima') . '</a></p>'
            . '<p class="description">' . esc_html__('Sans numéro : vérifie seulement les identifiants. Teste les réglages enregistrés : enregistrez avant de tester.', 'smbb-signaconnect-proxima') . '</p>';
    }

    /**
     * Dernier résultat du test, affiché en permanence au-dessus du bouton (data-smbb-keep : ni bulle
     * ni cloche). Juste après un test, la page revient sur cet onglet et le bloc clignote.
     */
    private static function resultHtml($organization_id, $type)
    {
        $all = get_option(self::OPTION, array());
        $result = isset($all[absint($organization_id)][$type]) ? $all[absint($organization_id)][$type] : null;

        if (!is_array($result) || !isset($result['message'])) {
            return '';
        }

        $just = isset($_GET['scp_tested']) && sanitize_key(wp_unslash($_GET['scp_tested'])) === $type; // phpcs:ignore WordPress.Security.NonceVerification
        $at = !empty($result['at']) ? (int) $result['at'] : 0;
        $when = $at ? sprintf(__('Dernier test il y a %s', 'smbb-signaconnect-proxima'), human_time_diff($at, time())) : __('Dernier test', 'smbb-signaconnect-proxima');

        $html = '<div id="' . esc_attr(self::anchor($type)) . '" data-smbb-keep class="notice smbb-keep-notice scp-test-result ' . (!empty($result['success']) ? 'notice-success' : 'notice-error') . ' inline' . ($just ? ' is-fresh' : '') . '"'
            . ($at ? ' title="' . esc_attr(wp_date(get_option('date_format') . ' H:i', $at)) . '"' : '') . '>'
            . '<p><strong>' . esc_html($when) . ' :</strong> ' . esc_html($result['message']) . '</p></div>';

        static $css = false;

        if ($just && !$css) {
            $css = true;
            $html .= '<style>@keyframes scp-test-flash{0%{box-shadow:0 0 0 4px rgba(34,113,177,.35)}100%{box-shadow:0 0 0 0 rgba(34,113,177,0)}}.scp-test-result.is-fresh{animation:scp-test-flash 1.8s ease}</style>';
        }

        return $html;
    }

    private static function anchor($type)
    {
        return 'scp-test-' . sanitize_key($type);
    }

    private static function shortError(\Throwable $exception)
    {
        $message = $exception->getMessage();

        // Les erreurs du SDK AWS sont très longues : on garde le code et le message S3.
        if (method_exists($exception, 'getAwsErrorCode') && $exception->getAwsErrorCode()) {
            $aws_message = method_exists($exception, 'getAwsErrorMessage') ? (string) $exception->getAwsErrorMessage() : '';
            $message = $exception->getAwsErrorCode() . ($aws_message !== '' ? ' — ' . $aws_message : '');
        }

        return wp_strip_all_tags(mb_substr($message, 0, 300));
    }
}
