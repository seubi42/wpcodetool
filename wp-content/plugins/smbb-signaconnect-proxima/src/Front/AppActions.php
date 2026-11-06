<?php

namespace Smbb\SignConnectProxima\Front;

use Smbb\SignConnectProxima\Repository\AuditLog;
use Smbb\SignConnectProxima\Service\MemberService;
use Smbb\SignConnectProxima\Service\StampService;
use Smbb\SignConnectProxima\Service\TemplateService;
use Smbb\SignConnectProxima\Support\Avatar;
use Smbb\SignConnectProxima\Support\Inbox;
use Smbb\SignConnectProxima\Support\Token;
use Smbb\SignConnectProxima\Support\UserPrefs;

defined('ABSPATH') || exit;

/**
 * Formulaires de l'app (profil, utilisateurs de l'organisation) : admin-post.php, puis retour sur l'app avec un message.
 */
final class AppActions
{
    const PROFILE = 'scp_app_profile';
    const INVITE = 'scp_app_member_invite';
    const MEMBER = 'scp_app_member_update';
    const TEMPLATE = 'scp_app_template_save';
    const STAMP = 'scp_app_stamp_save';

    public static function hooks()
    {
        add_action('admin_post_' . self::PROFILE, array(__CLASS__, 'profile'));
        add_action('admin_post_' . self::INVITE, array(__CLASS__, 'invite'));
        add_action('admin_post_' . self::MEMBER, array(__CLASS__, 'member'));
        add_action('admin_post_' . self::TEMPLATE, array(__CLASS__, 'template'));
        add_action('admin_post_' . self::STAMP, array(__CLASS__, 'stamp'));
    }

    public static function fields($action, $view)
    {
        return '<input type="hidden" name="action" value="' . esc_attr($action) . '">'
            . '<input type="hidden" name="scp_view" value="' . esc_attr($view) . '">'
            . wp_nonce_field($action, '_scp_nonce', true, false);
    }

    public static function formAction()
    {
        return esc_url(admin_url('admin-post.php'));
    }

    public static function profile()
    {
        $user_id = self::check(self::PROFILE);

        self::run(function () use ($user_id) {
            $input = wp_unslash($_POST);
            UserPrefs::saveMobile($user_id, isset($input['mobile']) ? $input['mobile'] : '');
            UserPrefs::saveNotifications($user_id, isset($input['notify']) && is_array($input['notify']) ? array_map('sanitize_key', $input['notify']) : array());
            if (!empty($input['client_revoke']) && is_array($input['client_revoke'])) {
                ClientApi::revoke($user_id, $input['client_revoke']);
            }

            Inbox::savePreferences($user_id, isset($input['notify_app']) && is_array($input['notify_app']) ? array_map('sanitize_key', $input['notify_app']) : array());

            if (!empty($input['signature_delete'])) {
                UserPrefs::deleteSignature($user_id);
            } else {
                $data_url = isset($input['signature_data']) ? trim((string) $input['signature_data']) : '';
                $file = isset($_FILES['signature_file']) && is_array($_FILES['signature_file']) && !empty($_FILES['signature_file']['tmp_name']) ? $_FILES['signature_file'] : array();

                if ($data_url !== '' || $file) {
                    UserPrefs::saveSignature($user_id, $data_url, $file);
                    AuditLog::record('signature_saved', __('Signature personnelle enregistrée.', 'smbb-signaconnect-proxima'), array(), null, null, 'user', $user_id);
                }
            }

            // En dernier : une photo refusée n'empêche pas d'enregistrer le reste.
            if (!empty($input['avatar_delete'])) {
                Avatar::delete($user_id);
            } elseif (isset($_FILES['avatar_file']) && is_array($_FILES['avatar_file']) && !empty($_FILES['avatar_file']['tmp_name'])) {
                Avatar::save($user_id, $_FILES['avatar_file']);
            }

            return __('Profil enregistré.', 'smbb-signaconnect-proxima');
        });
    }

    public static function invite()
    {
        $user_id = self::check(self::INVITE);

        self::run(function () use ($user_id) {
            $input = wp_unslash($_POST);
            $created = MemberService::invite($user_id, isset($input['organization_id']) ? absint($input['organization_id']) : 0, $input);

            return $created
                ? __('Invitation envoyée : la personne reçoit un email pour choisir son mot de passe.', 'smbb-signaconnect-proxima')
                : __('Cette personne avait déjà un compte : elle est maintenant rattachée à votre organisation.', 'smbb-signaconnect-proxima');
        });
    }

    public static function member()
    {
        $user_id = self::check(self::MEMBER);

        self::run(function () use ($user_id) {
            $input = wp_unslash($_POST);
            $changed = MemberService::update($user_id, isset($input['membership_id']) ? absint($input['membership_id']) : 0, $input);

            return $changed ? __('Utilisateur mis à jour.', 'smbb-signaconnect-proxima') : __('Aucun changement.', 'smbb-signaconnect-proxima');
        });
    }

    /**
     * Tampon de l'organisation ou d'une équipe (team_id = 0 : organisation), importé ou retiré par l'admin d'org.
     */
    public static function stamp()
    {
        $user_id = self::check(self::STAMP);

        self::run(function () use ($user_id) {
            $input = wp_unslash($_POST);
            $organization_id = isset($input['organization_id']) ? absint($input['organization_id']) : 0;
            $team_id = isset($input['team_id']) ? absint($input['team_id']) : 0;

            if (!empty($input['delete'])) {
                StampService::remove($user_id, $organization_id, $team_id);

                return __('Tampon retiré.', 'smbb-signaconnect-proxima');
            }

            $file = isset($_FILES['stamp_file']) && is_array($_FILES['stamp_file']) ? $_FILES['stamp_file'] : array();
            StampService::upload($user_id, $organization_id, $team_id, $file);

            return __('Tampon enregistré.', 'smbb-signaconnect-proxima');
        });
    }

    /**
     * Modèle : enregistrement (puis retour sur sa fiche) ou suppression (retour à la liste).
     */
    public static function template()
    {
        $user_id = self::check(self::TEMPLATE);
        $input = wp_unslash($_POST);
        $args = array('scp_view' => 'templates', 'org' => isset($input['organization_id']) ? absint($input['organization_id']) : 0);

        try {
            if (!empty($input['delete'])) {
                $template = TemplateService::delete($user_id, isset($input['template_id']) ? absint($input['template_id']) : 0);
                $notice = array('success' => true, 'message' => sprintf(__('Modèle « %s » supprimé.', 'smbb-signaconnect-proxima'), $template['name']));
            } else {
                $is_new = empty($input['template_id']);
                $args['template'] = TemplateService::save($user_id, $input);
                $notice = array('success' => true, 'message' => $is_new ? __('Modèle créé.', 'smbb-signaconnect-proxima') : __('Modèle enregistré.', 'smbb-signaconnect-proxima'));
            }
        } catch (\Throwable $exception) {
            $notice = array('success' => false, 'message' => $exception->getMessage());
            $args['template'] = !empty($input['template_id']) ? absint($input['template_id']) : 'new';
        }

        set_transient('scp_app_notice_' . get_current_user_id(), $notice, 300);
        $back = remove_query_arg(array('template', 'org', 'scp_view'), wp_get_referer() ?: Token::appUrl());
        wp_safe_redirect(add_query_arg($args, $back));
        exit;
    }

    /**
     * Message à afficher en haut de l'app après une action.
     */
    public static function notice()
    {
        $key = 'scp_app_notice_' . get_current_user_id();
        $notice = get_transient($key);

        if (!is_array($notice)) {
            return '';
        }

        delete_transient($key);

        return '<div class="scp-alert ' . ($notice['success'] ? 'scp-alert--success' : 'scp-alert--error') . '" role="status">' . nl2br(esc_html($notice['message'])) . '</div>';
    }

    private static function check($action)
    {
        if (!is_user_logged_in() || !isset($_POST['_scp_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_scp_nonce'])), $action)) {
            wp_die(esc_html__('Session expirée : rechargez la page.', 'smbb-signaconnect-proxima'), '', array('response' => 403, 'back_link' => true));
        }

        return get_current_user_id();
    }

    private static function run(callable $callback)
    {
        try {
            $notice = array('success' => true, 'message' => $callback());
        } catch (\Throwable $exception) {
            $notice = array('success' => false, 'message' => $exception->getMessage());
        }

        set_transient('scp_app_notice_' . get_current_user_id(), $notice, 300);
        $view = isset($_POST['scp_view']) ? sanitize_key(wp_unslash($_POST['scp_view'])) : '';
        $back = wp_get_referer() ?: Token::appUrl();
        wp_safe_redirect($view !== '' ? add_query_arg('scp_view', $view, $back) : $back);
        exit;
    }
}
