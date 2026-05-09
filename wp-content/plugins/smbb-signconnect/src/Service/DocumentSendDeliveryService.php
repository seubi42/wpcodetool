<?php

namespace Smbb\SignConnect\Service;

use Smbb\SignConnect\Repository\DocumentRepository;
use Smbb\SignConnect\Support\PublicSigningLink;
use Smbb\SignConnect\Support\SignConnectSettings;

defined('ABSPATH') || exit;

/**
 * Envoie le lien public de signature par email ou SMS.
 */
final class DocumentSendDeliveryService
{
    private $documents;

    public function __construct(DocumentRepository $documents = null)
    {
        $this->documents = $documents ?: new DocumentRepository();
    }

    public function deliver(array $document, $owner_id)
    {
        $document_id = isset($document['id']) ? (int) $document['id'] : 0;
        $token = isset($document['token']) ? (string) $document['token'] : '';

        if ($document_id < 1 || $token === '') {
            throw new \RuntimeException(__('The document does not have a signature token.', 'smbb-signconnect'));
        }

        $link = PublicSigningLink::url($document_id, $token);
        $channel = isset($document['send_channel']) && $document['send_channel'] === 'sms' ? 'sms' : 'email';
        $message = $this->messageWithLink(isset($document['send_message']) ? (string) $document['send_message'] : '', $link);

        if ($channel === 'sms') {
            $this->sendSms($document, $message);
        } else {
            $this->sendEmail($document, $message, $link);
        }

        $sent = $this->documents->markSent($document_id, $owner_id);

        if (!$sent) {
            throw new \RuntimeException(__('The document was sent, but its status could not be updated.', 'smbb-signconnect'));
        }

        return array(
            'document' => $sent,
            'link' => $link,
            'channel' => $channel,
        );
    }

    private function sendEmail(array $document, $message, $link)
    {
        $email = isset($document['recipient_email']) ? (string) $document['recipient_email'] : '';

        if ($email === '' || !is_email($email)) {
            throw new \RuntimeException(__('Invalid recipient email.', 'smbb-signconnect'));
        }

        $subject = sprintf(
            /* translators: %s: filename. */
            __('Signature requested: %s', 'smbb-signconnect'),
            isset($document['filename']) ? (string) $document['filename'] : __('Document', 'smbb-signconnect')
        );

        $body = $this->emailBody($document, $message, $link);
        $headers = array(
            'Content-Type: text/html; charset=UTF-8',
        );

        if (!wp_mail($email, $subject, $body, $headers)) {
            throw new \RuntimeException(__('The signature email could not be sent.', 'smbb-signconnect'));
        }
    }

    private function sendSms(array $document, $message)
    {
        if (!class_exists('\Smbb\WpCodeTool\Connector\SmbbTwilioSmsClient')) {
            throw new \RuntimeException(__('The SMBB WP CodeTool Twilio connector is unavailable.', 'smbb-signconnect'));
        }

        $phone = isset($document['recipient_phone']) ? (string) $document['recipient_phone'] : '';
        $client = \Smbb\WpCodeTool\Connector\SmbbTwilioSmsClient::fromSettings(SignConnectSettings::all());
        $client->send($phone, $message);
    }

    private function messageWithLink($message, $link)
    {
        $message = trim((string) $message);

        return $message !== '' ? $message . "\n\n" . $link : $link;
    }

    private function emailBody(array $document, $message, $link)
    {
        $filename = !empty($document['filename']) ? (string) $document['filename'] : __('Document', 'smbb-signconnect');
        $expires = !empty($document['link_expires_at']) ? mysql2date(get_option('date_format'), (string) $document['link_expires_at']) : '';
        $message = trim(str_replace($link, '', (string) $message));
        $brand = SignConnectSettings::brandColor();

        $html = '<!doctype html><html><body style="margin:0;padding:0;background:#f6f7f7;font-family:Arial,sans-serif;color:#1d2327;">';
        $html .= '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f6f7f7;padding:28px 12px;"><tr><td align="center">';
        $html .= '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:560px;background:#ffffff;border:1px solid #dcdcde;border-radius:8px;overflow:hidden;">';
        $html .= '<tr><td style="padding:22px 24px;border-bottom:1px solid #eef0f2;"><div style="font-size:13px;color:#646970;">SignConnect</div><h1 style="margin:6px 0 0;font-size:22px;line-height:1.25;">' . esc_html__('Signature requested', 'smbb-signconnect') . '</h1></td></tr>';
        $html .= '<tr><td style="padding:24px;">';
        $html .= '<p style="margin:0 0 14px;font-size:16px;line-height:1.5;">' . nl2br(esc_html($message !== '' ? $message : __('Please review and sign this document.', 'smbb-signconnect'))) . '</p>';
        $html .= '<p style="margin:0 0 18px;color:#646970;font-size:14px;">' . esc_html($filename) . '</p>';
        $html .= '<p style="margin:24px 0;"><a href="' . esc_url($link) . '" style="display:inline-block;background:' . esc_attr($brand) . ';color:#ffffff;text-decoration:none;border-radius:5px;padding:12px 18px;font-weight:600;">' . esc_html__('Open the document', 'smbb-signconnect') . '</a></p>';

        if ($expires !== '') {
            $html .= '<p style="margin:18px 0 0;color:#646970;font-size:13px;">' . sprintf(esc_html__('Link valid until %s.', 'smbb-signconnect'), esc_html($expires)) . '</p>';
        }

        $html .= '</td></tr>';
        $html .= '<tr><td style="padding:16px 24px;background:#fbfbfc;color:#8a96a3;font-size:12px;">' . esc_html__('If the button does not work, copy the following link into your browser:', 'smbb-signconnect') . '<br><span style="word-break:break-all;">' . esc_html($link) . '</span></td></tr>';
        $html .= '</table></td></tr></table></body></html>';

        return $html;
    }
}
