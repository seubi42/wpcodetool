<?php

namespace Smbb\SignConnectProxima\Service;

use Smbb\SignConnectProxima\Repository\AuditLog;
use Smbb\SignConnectProxima\Repository\Documents;
use Smbb\SignConnectProxima\Repository\Organizations;
use Smbb\SignConnectProxima\Repository\Templates;
use Smbb\SignConnectProxima\Support\Token;
use Smbb\SignConnectProxima\Support\Inbox;
use Smbb\SignConnectProxima\Support\Placeholders;
use Smbb\SignConnectProxima\Support\UserPrefs;

defined('ABSPATH') || exit;

/**
 * Emails et SMS de la signature interne. Les emails partent par wp_mail()
 * (un plugin SMTP peut prendre le relais), aux couleurs de l'organisation.
 */
final class Mailer
{
    /**
     * Demande de signature (ou relance) par les canaux choisis à l'envoi.
     *
     * @return string[] canaux effectivement utilisés
     */
    public static function signatureRequest(array $document, array $organization, $is_reminder = false)
    {
        $url = Token::signingUrl($document);
        $sender = self::senderName($document);
        $channels = $document['delivery_channels'] ?: array('email');
        $used = array();
        $expires = !empty($document['expires_at']) ? mysql2date('d/m/Y', $document['expires_at']) : '';

        if (in_array('email', $channels, true) && is_email($document['recipient_email'])) {
            $title = $is_reminder
                ? sprintf(__('Rappel : %s attend votre signature', 'smbb-signaconnect-proxima'), $document['filename'])
                : sprintf(__('%1$s vous invite à signer %2$s', 'smbb-signaconnect-proxima'), $sender, $document['filename']);
            $body = '<p>' . esc_html(sprintf(__('Bonjour %s,', 'smbb-signaconnect-proxima'), Documents::recipientName($document))) . '</p>';
            $body .= '<p>' . esc_html(sprintf(
                $is_reminder ? __('%1$s (%2$s) attend toujours votre signature sur le document « %3$s ».', 'smbb-signaconnect-proxima') : __('%1$s (%2$s) vous invite à signer le document « %3$s ».', 'smbb-signaconnect-proxima'),
                $sender,
                $organization['name'],
                $document['filename']
            )) . '</p>';

            if (!empty($document['send_message'])) {
                $body .= '<blockquote style="margin:16px 0;padding:12px 16px;background:#f6f7f9;border-left:3px solid ' . esc_attr(Organizations::brandColor($organization)) . ';border-radius:4px;">' . nl2br(esc_html($document['send_message'])) . '</blockquote>';
            }

            if ($expires !== '') {
                $body .= '<p style="color:#6b7280;font-size:13px;">' . esc_html(sprintf(__('Ce lien est personnel et valable jusqu\'au %s.', 'smbb-signaconnect-proxima'), $expires)) . '</p>';
            }

            self::email($organization, $document['recipient_email'], $title, $title, $body, array(__('Lire et signer', 'smbb-signaconnect-proxima'), $url));
            $used[] = 'email';
        }

        if (in_array('sms', $channels, true) && !empty($document['recipient_phone']) && Organizations::hasTwilio($organization)) {
            Sms::send($organization, $document['recipient_phone'], sprintf(
                $is_reminder ? __('Rappel %1$s : document « %2$s » à signer : %3$s', 'smbb-signaconnect-proxima') : __('%1$s vous invite à signer « %2$s » : %3$s', 'smbb-signaconnect-proxima'),
                $is_reminder ? $organization['name'] : $sender,
                self::shorten($document['filename'], 40),
                $url
            ));
            $used[] = 'sms';
        }

        if (!$used) {
            throw new \RuntimeException(__("Aucun canal n'a pu être utilisé pour prévenir le signataire.", 'smbb-signaconnect-proxima'));
        }

        return $used;
    }

    public static function signedToSigner(array $document, array $organization, $signed_pdf)
    {
        if (!is_email($document['recipient_email'])) {
            return;
        }

        $title = sprintf(__('Votre exemplaire signé : %s', 'smbb-signaconnect-proxima'), $document['filename']);
        $body = '<p>' . esc_html__('Merci, votre signature a bien été enregistrée.', 'smbb-signaconnect-proxima') . '</p>'
            . '<p>' . esc_html(sprintf(__('Vous trouverez en pièce jointe le document signé, avec son certificat de signature (%s).', 'smbb-signaconnect-proxima'), $organization['name'])) . '</p>';

        self::email($organization, $document['recipient_email'], $title, __('Document signé', 'smbb-signaconnect-proxima'), $body, null, array(self::attachment($signed_pdf, $document)));
    }

    /**
     * PDF signé à l'expéditeur. Si le modèle du document a son propre email du document signé,
     * c'est lui qui part (sujet et message du modèle, copies éventuelles), même si l'expéditeur
     * a coupé la notification « signé » dans son profil : c'est un réglage voulu par l'organisation.
     */
    public static function signedToSender(array $document, array $organization, $signed_pdf)
    {
        Inbox::document($document, 'signed');
        $email = self::senderEmail($document);
        $template = !empty($document['template_id']) ? Templates::find((int) $document['template_id']) : null;
        $settings = Templates::settings($template);

        if ($template && $settings['signed_mail_enabled']) {
            self::templateSignedMail($document, $organization, $template, $settings, $email, $signed_pdf);
            return;
        }

        if ($email === '' || !UserPrefs::wants((int) $document['sender_id'], 'signed')) {
            return;
        }

        $title = sprintf(__('Signé : %s', 'smbb-signaconnect-proxima'), $document['filename']);
        $body = '<p>' . esc_html(sprintf(__('%1$s a signé « %2$s ».', 'smbb-signaconnect-proxima'), $document['signer_name'] ?: $document['recipient_email'], $document['filename'])) . '</p>'
            . '<p>' . esc_html__('Le PDF signé et son certificat sont joints à cet email.', 'smbb-signaconnect-proxima') . '</p>';

        self::email($organization, $email, $title, __('Document signé', 'smbb-signaconnect-proxima'), $body, array(__('Voir mes transactions', 'smbb-signaconnect-proxima'), Token::appUrl(array('scp_view' => 'documents'))), array(self::attachment($signed_pdf, $document)));
    }

    /**
     * Email du document signé paramétré dans le modèle : expéditeur + copies.
     */
    private static function templateSignedMail(array $document, array $organization, array $template, array $settings, $sender_email, $signed_pdf)
    {
        $values = Placeholders::values($document, $organization, $template);
        $subject = trim(Placeholders::render($settings['signed_mail_subject'], $values));
        $subject = $subject !== '' ? $subject : sprintf(__('Signé : %s', 'smbb-signaconnect-proxima'), $document['filename']);
        $text = trim(Placeholders::render($settings['signed_mail_body'], $values));
        $body = $text !== ''
            ? '<p>' . nl2br(esc_html($text)) . '</p>'
            : '<p>' . esc_html(sprintf(__('%1$s a signé « %2$s ».', 'smbb-signaconnect-proxima'), $values['signataire'], $document['filename'])) . '</p>';
        $body .= '<p style="color:#6b7280;font-size:13px;">' . esc_html__('Le PDF signé est joint à cet email.', 'smbb-signaconnect-proxima') . '</p>';
        $recipients = $sender_email !== '' ? array($sender_email) : array();

        foreach ($settings['signed_mail_cc'] as $cc) {
            if (!in_array(strtolower($cc), array_map('strtolower', $recipients), true)) {
                $recipients[] = $cc;
            }
        }

        $errors = array();

        foreach ($recipients as $index => $to) {
            // Bouton « Voir mes transactions » pour l'expéditeur seulement : les copies n'ont pas forcément de compte.
            $button = $index === 0 && $sender_email !== '' ? array(__('Voir mes transactions', 'smbb-signaconnect-proxima'), Token::appUrl(array('scp_view' => 'documents'))) : null;

            try {
                self::email($organization, $to, $subject, __('Document signé', 'smbb-signaconnect-proxima'), $body, $button, array(self::attachment($signed_pdf, $document)));
            } catch (\Throwable $exception) {
                $errors[] = $exception->getMessage();
            }
        }

        AuditLog::record('signed_mail_sent', __('Email du document signé (réglage du modèle) envoyé.', 'smbb-signaconnect-proxima'), array(
            'template' => (string) $template['name'],
            'to' => $recipients,
            'errors' => $errors,
        ), (int) $organization['id'], (int) $document['id'], 'system');

        if ($errors) {
            throw new \RuntimeException(implode(' ', $errors));
        }
    }

    public static function refusedToSender(array $document, array $organization)
    {
        Inbox::document($document, 'refused');
        $email = self::senderEmail($document);

        if ($email === '' || !UserPrefs::wants((int) $document['sender_id'], 'refused')) {
            return;
        }

        $title = sprintf(__('Refusé : %s', 'smbb-signaconnect-proxima'), $document['filename']);
        $body = '<p>' . esc_html(sprintf(__('%1$s a refusé de signer « %2$s ».', 'smbb-signaconnect-proxima'), $document['signer_name'] ?: $document['recipient_email'], $document['filename'])) . '</p>';

        if (!empty($document['refusal_message'])) {
            $body .= '<blockquote style="margin:16px 0;padding:12px 16px;background:#fff5f5;border-left:3px solid #cc1818;border-radius:4px;">' . nl2br(esc_html($document['refusal_message'])) . '</blockquote>';
        }

        self::email($organization, $email, $title, __('Signature refusée', 'smbb-signaconnect-proxima'), $body, array(__('Voir mes transactions', 'smbb-signaconnect-proxima'), Token::appUrl(array('scp_view' => 'documents'))));
    }

    public static function expiredToSender(array $document, array $organization)
    {
        Inbox::document($document, 'expired');
        $email = self::senderEmail($document);

        if ($email === '' || !UserPrefs::wants((int) $document['sender_id'], 'expired')) {
            return;
        }

        $title = sprintf(__('Expiré sans signature : %s', 'smbb-signaconnect-proxima'), $document['filename']);
        $body = '<p>' . esc_html(sprintf(__('Le lien envoyé à %1$s pour « %2$s » a expiré sans signature.', 'smbb-signaconnect-proxima'), $document['recipient_email'] ?: $document['recipient_phone'], $document['filename'])) . '</p>';

        self::email($organization, $email, $title, __('Lien expiré', 'smbb-signaconnect-proxima'), $body, array(__('Voir mes transactions', 'smbb-signaconnect-proxima'), Token::appUrl(array('scp_view' => 'documents'))));
    }

    /**
     * Gabarit HTML commun.
     *
     * @param array{0:string,1:string}|null $button libellé, URL
     * @param string[] $attachments
     */
    private static function email(array $organization, $to, $subject, $heading, $body_html, $button = null, array $attachments = array())
    {
        $color = Organizations::brandColor($organization);
        $logo = !empty($organization['logo_attachment_id']) ? wp_get_attachment_image_url((int) $organization['logo_attachment_id'], 'medium') : '';
        $html = '<!doctype html><html><body style="margin:0;padding:0;background:#f3f4f6;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Helvetica,Arial,sans-serif;color:#111827;">';
        $html .= '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="padding:32px 12px;"><tr><td align="center">';
        $html .= '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:560px;background:#ffffff;border-radius:14px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,.06);">';
        $html .= '<tr><td style="height:4px;background:' . esc_attr($color) . ';"></td></tr>';
        $html .= '<tr><td style="padding:28px 32px 8px;">';
        $html .= $logo ? '<img src="' . esc_url($logo) . '" alt="' . esc_attr($organization['name']) . '" style="max-height:40px;max-width:180px;display:block;margin-bottom:20px;">' : '<div style="font-size:13px;font-weight:600;color:#6b7280;margin-bottom:16px;">' . esc_html($organization['name']) . '</div>';
        $html .= '<h1 style="margin:0;font-size:22px;line-height:1.3;font-weight:600;">' . esc_html($heading) . '</h1></td></tr>';
        $html .= '<tr><td style="padding:8px 32px 24px;font-size:15px;line-height:1.6;">' . $body_html;

        if ($button) {
            $html .= '<p style="margin:28px 0 8px;"><a href="' . esc_url($button[1]) . '" style="display:inline-block;background:' . esc_attr($color) . ';color:#ffffff;text-decoration:none;font-weight:600;padding:12px 22px;border-radius:10px;">' . esc_html($button[0]) . '</a></p>';
            $html .= '<p style="font-size:12px;color:#9ca3af;word-break:break-all;">' . esc_html($button[1]) . '</p>';
        }

        $html .= '</td></tr><tr><td style="padding:16px 32px 24px;border-top:1px solid #f0f0f0;font-size:12px;color:#9ca3af;">' . esc_html(sprintf(__('Envoyé avec SignaConnect pour %s.', 'smbb-signaconnect-proxima'), $organization['name'])) . '</td></tr>';
        $html .= '</table></td></tr></table></body></html>';

        $sent = wp_mail($to, $subject, $html, array('Content-Type: text/html; charset=UTF-8'), array_filter($attachments));

        foreach ($attachments as $path) {
            if ($path && strpos(basename(dirname($path)), 'scp-mail-') === 0) {
                @unlink($path);
                @rmdir(dirname($path));
            }
        }

        if (!$sent) {
            throw new \RuntimeException(sprintf(__("L'email à %s n'a pas pu partir (vérifiez la configuration d'envoi des emails).", 'smbb-signaconnect-proxima'), $to));
        }
    }

    /**
     * Copie temporaire nommée proprement (le nom du fichier joint est celui vu par le destinataire).
     */
    private static function attachment($path, array $document)
    {
        if (!$path || !is_readable($path)) {
            return '';
        }

        $name = !empty($document['source_filename'])
            ? SendService::cleanName(Documents::downloadName($document, 'signed')) . '.pdf'
            : sanitize_file_name(Documents::downloadName($document, 'signed'));
        $dir = trailingslashit(get_temp_dir()) . 'scp-mail-' . wp_generate_password(8, false, false);
        wp_mkdir_p($dir);
        $target = $dir . '/' . $name;

        return copy($path, $target) ? $target : '';
    }

    private static function senderName(array $document)
    {
        $user = !empty($document['sender_id']) ? get_userdata((int) $document['sender_id']) : false;

        return $user ? (string) $user->display_name : __("L'expéditeur", 'smbb-signaconnect-proxima');
    }

    private static function senderEmail(array $document)
    {
        $user = !empty($document['sender_id']) ? get_userdata((int) $document['sender_id']) : false;

        return $user && is_email($user->user_email) ? (string) $user->user_email : '';
    }

    private static function shorten($text, $length)
    {
        $text = (string) $text;

        return mb_strlen($text) > $length ? mb_substr($text, 0, $length - 1) . '…' : $text;
    }
}
