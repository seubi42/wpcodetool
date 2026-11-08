<?php

namespace Smbb\WpCodeTool\Forms;

use Smbb\WpCodeTool\Sms\Sms;

defined('ABSPATH') || exit;

/**
 * Notifications d'un envoi : e-mails (wp_mail, donc le Mailer CodeTool : SMTP, journal 15 jours,
 * renvoi) et SMS (Sms CodeTool) si réglé sur le formulaire.
 */
final class Notifier
{
    public static function send(array $form, array $context)
    {
        foreach ($form['settings']['notifications'] as $notification) {
            if ($notification['enabled'] !== '1') {
                continue;
            }

            self::sendMail($form, $notification, $context);
        }

        $settings = $form['settings'];

        if ($settings['sms'] === '1' && trim($settings['sms_to']) !== '' && class_exists(Sms::class) && Sms::ready() === true) {
            $text = sprintf(__('%1$s : nouvelle entrée sur « %2$s ».', 'smbb-wpcodetool'), wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES), $form['title']);
            $summary = EntryRepository::summary(array('fields' => $context['fields']), 2);

            if ($summary !== '') {
                $text .= ' ' . $summary;
            }

            foreach (array_filter(array_map('trim', explode(',', SmartTags::replace($settings['sms_to'], $context)))) as $to) {
                Sms::send($to, mb_substr($text, 0, 300), array('source' => 'forms', 'form_id' => $form['id'], 'entry_id' => $context['entry_id']));
            }
        }
    }

    /**
     * @return bool
     */
    public static function sendMail(array $form, array $notification, array $context)
    {
        $to = array_filter(array_map('trim', explode(',', SmartTags::replace($notification['to'], $context))), 'is_email');

        if (!$to) {
            return false;
        }

        // Liens de téléchargement des fichiers dans {all_fields} : pour l'équipe seulement (ils demandent
        // un compte), pas dans un e-mail adressé au visiteur.
        $visitor = SmartTags::visitorEmail($context['fields'] ?? array());
        $context['file_links'] = !empty($context['entry_id']) && strpos($notification['to'], '{visitor_email}') === false
            && ($visitor === '' || !in_array(strtolower($visitor), array_map('strtolower', $to), true));

        $subject = wp_strip_all_tags(SmartTags::replace($notification['subject'], $context));
        $message = SmartTags::replace($notification['message'] !== '' ? $notification['message'] : '{all_fields}', $context, true);
        $headers = array('Content-Type: text/html; charset=UTF-8');
        $reply_to = trim(SmartTags::replace($notification['reply_to'], $context));

        if ($reply_to !== '' && is_email($reply_to)) {
            $headers[] = 'Reply-To: ' . $reply_to;
        }

        $from_name = trim(SmartTags::replace($notification['from_name'], $context));
        $from_filter = null;

        if ($from_name !== '') {
            $from_filter = static function () use ($from_name) {
                return $from_name;
            };
            add_filter('wp_mail_from_name', $from_filter, 1000);
        }

        $attachments = array();

        if ($notification['attach_files'] === '1' && !empty($context['folder'])) {
            foreach ($context['fields'] as $field) {
                if (($field['type'] ?? '') === 'file') {
                    $attachments = array_merge($attachments, Uploads::paths($context['folder'], $field['value']));
                }
            }

            // Trop lourd pour un e-mail (refus des messageries) : on garde seulement les liens.
            $total = 0;

            foreach ($attachments as $path) {
                $total += (int) @filesize($path); // phpcs:ignore
            }

            if ($total > self::attachMax()) {
                $attachments = array();
            }
        }

        // Adresses (lien vers l'entrée…) cliquables dans l'e-mail.
        $sent = wp_mail($to, $subject, self::layout($form, make_clickable($message)), $headers, $attachments);

        if ($from_filter) {
            remove_filter('wp_mail_from_name', $from_filter, 1000);
        }

        return (bool) $sent;
    }

    /**
     * Poids total maximal des pièces jointes d'un e-mail (10 Mo ; filtre smbb_wpcodetool_forms_attach_max).
     */
    public static function attachMax()
    {
        return max(0, (int) apply_filters('smbb_wpcodetool_forms_attach_max', 10 * MB_IN_BYTES));
    }

    /**
     * Mise en page sobre de l'e-mail (le message peut contenir {all_fields} en tableau).
     */
    public static function layout(array $form, $message)
    {
        $body = wpautop($message);
        $html = '<!doctype html><html><head><meta charset="utf-8"></head><body style="margin:0;padding:24px;background:#f5f5f7;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Helvetica,Arial,sans-serif;color:#1d1d1f;">'
            . '<div style="max-width:640px;margin:0 auto;background:#ffffff;border-radius:12px;padding:24px 28px;">'
            . '<p style="margin:0 0 16px;font-size:12px;letter-spacing:.06em;text-transform:uppercase;color:#6e6e73;">' . esc_html($form['title']) . '</p>'
            . $body
            . '</div></body></html>';

        return (string) apply_filters('smbb_wpcodetool_form_mail_html', $html, $form, $message);
    }
}
