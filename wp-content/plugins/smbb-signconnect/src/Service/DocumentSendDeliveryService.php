<?php

namespace Smbb\SignConnect\Service;

use Smbb\SignConnect\Repository\DocumentRepository;
use Smbb\SignConnect\Repository\DocumentAuditRepository;
use Smbb\SignConnect\Support\PublicSigningLink;
use Smbb\SignConnect\Support\SignConnectSettings;

defined('ABSPATH') || exit;

/**
 * Envoie le lien public de signature par email ou SMS.
 */
final class DocumentSendDeliveryService
{
    private $documents;
    private $audit;

    public function __construct(DocumentRepository $documents = null, DocumentAuditRepository $audit = null)
    {
        $this->documents = $documents ?: new DocumentRepository();
        $this->audit = $audit ?: new DocumentAuditRepository();
    }

    public function deliver(array $document, $owner_id)
    {
        $document_id = isset($document['id']) ? (int) $document['id'] : 0;
        $token = isset($document['_public_token']) ? (string) $document['_public_token'] : '';

        if ($document_id < 1) {
            throw new \RuntimeException(__('The document does not exist.', 'smbb-signconnect'));
        }

        if ($token === '') {
            $token = $this->documents->rotatePublicToken($document_id, $owner_id);
        }

        if ($token === '') {
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

        $this->audit->record($document_id, $channel === 'sms' ? 'sent_sms' : 'sent_email', array(
            'recipient' => $channel === 'sms'
                ? (isset($document['recipient_phone']) ? (string) $document['recipient_phone'] : '')
                : (isset($document['recipient_email']) ? (string) $document['recipient_email'] : ''),
            'expires_at' => isset($document['link_expires_at']) ? (string) $document['link_expires_at'] : '',
        ), 'owner', $owner_id, __('Signature link sent.', 'smbb-signconnect'));

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
        $return_expected = isset($document['return_expected']) && (string) $document['return_expected'] === 'approval_refusal'
            ? __('Approval or refusal', 'smbb-signconnect')
            : __('Signature', 'smbb-signconnect');

        $html = '<!doctype html><html><body style="margin:0;padding:0;background:#f3f5f7;font-family:Arial,Helvetica,sans-serif;color:#1d2327;">';
        $html .= '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f3f5f7;padding:32px 12px;"><tr><td align="center">';
        $html .= '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:620px;background:#ffffff;border:1px solid #dfe3e8;border-radius:10px;overflow:hidden;box-shadow:0 10px 28px rgba(15,23,42,0.08);">';
        $html .= '<tr><td style="padding:28px 30px 24px;border-top:5px solid ' . esc_attr($brand) . ';border-bottom:1px solid #eef0f2;">';
        $html .= '<div style="font-size:12px;letter-spacing:.08em;text-transform:uppercase;color:#6b7280;font-weight:700;">SignConnect</div>';
        $html .= '<h1 style="margin:8px 0 0;font-size:26px;line-height:1.2;color:#111827;">' . esc_html__('Signature requested', 'smbb-signconnect') . '</h1>';
        $html .= '<p style="margin:10px 0 0;color:#6b7280;font-size:14px;">' . esc_html__('A document is waiting for your response.', 'smbb-signconnect') . '</p>';
        $html .= '</td></tr>';
        $html .= '<tr><td style="padding:28px 30px;">';
        $html .= '<p style="margin:0 0 20px;font-size:16px;line-height:1.55;">' . nl2br(esc_html($message !== '' ? $message : __('Please review and sign this document.', 'smbb-signconnect'))) . '</p>';
        $html .= '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:0 0 22px;border:1px solid #e5e7eb;border-radius:8px;background:#fbfcfd;">';
        $html .= '<tr><td style="padding:14px 16px;color:#6b7280;font-size:13px;">' . esc_html__('Document', 'smbb-signconnect') . '</td><td style="padding:14px 16px;text-align:right;font-size:14px;font-weight:700;">' . esc_html($filename) . '</td></tr>';
        $html .= '<tr><td style="padding:0 16px 14px;color:#6b7280;font-size:13px;">' . esc_html__('Expected response', 'smbb-signconnect') . '</td><td style="padding:0 16px 14px;text-align:right;font-size:14px;">' . esc_html($return_expected) . '</td></tr>';
        if ($expires !== '') {
            $html .= '<tr><td style="padding:0 16px 14px;color:#6b7280;font-size:13px;">' . esc_html__('Expiration', 'smbb-signconnect') . '</td><td style="padding:0 16px 14px;text-align:right;font-size:14px;">' . esc_html($expires) . '</td></tr>';
        }
        $html .= '</table>';
        $html .= '<p style="margin:26px 0 8px;text-align:center;"><a href="' . esc_url($link) . '" style="display:inline-block;background:' . esc_attr($brand) . ';color:#ffffff;text-decoration:none;border-radius:6px;padding:13px 22px;font-weight:700;font-size:15px;">' . esc_html__('Open the document', 'smbb-signconnect') . '</a></p>';

        if ($expires !== '') {
            $html .= '<p style="margin:12px 0 0;text-align:center;color:#6b7280;font-size:13px;">' . sprintf(esc_html__('Link valid until %s.', 'smbb-signconnect'), esc_html($expires)) . '</p>';
        }

        $html .= '</td></tr>';
        $html .= '<tr><td style="padding:18px 30px;background:#f9fafb;color:#8a96a3;font-size:12px;line-height:1.5;">' . esc_html__('If the button does not work, copy the following link into your browser:', 'smbb-signconnect') . '<br><span style="word-break:break-all;">' . esc_html($link) . '</span></td></tr>';
        $html .= '</table></td></tr></table></body></html>';

        return $html;
    }
}
