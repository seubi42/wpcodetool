<?php

namespace Smbb\Passflow\Service;

use Smbb\Passflow\Support\Roles;
use Smbb\Passflow\Support\Settings;

defined('ABSPATH') || exit;

/**
 * Habillage des e-mails aux couleurs du site (Réglages › Envoi).
 *
 * - Les e-mails de Passflow prennent toujours ce gabarit (logo, couleur, pied de page).
 * - Option « email_wp » : les e-mails texte de WordPress (mot de passe, nouveau compte…) reçoivent
 *   le même gabarit. Un e-mail déjà en HTML (WooCommerce, autres extensions) n'est pas touché.
 * - Option « email_wc » : WooCommerce reprend la couleur, le fond, le logo et le pied de page
 *   à la place de ses propres réglages d'e-mails.
 *
 * Les deux options se coupent d'un clic : tout redevient comme avant, rien n'est écrit ailleurs.
 */
final class EmailBrand
{
    const PREVIEW = 'passflow_email_preview';

    public static function hooks()
    {
        add_filter('wp_mail', array(__CLASS__, 'wrapWordPressMail'), 99);
        add_action('admin_post_' . self::PREVIEW, array(__CLASS__, 'preview'));

        // Au chargement de WooCommerce (plugins_loaded est déjà passé quand Passflow démarre).
        if ((int) Settings::get('email_wc') === 1) {
            foreach (array(
                'woocommerce_email_base_color' => 'wcBaseColor',
                'woocommerce_email_background_color' => 'wcBackground',
                'woocommerce_email_body_background_color' => 'wcBodyBackground',
                'woocommerce_email_header_image' => 'wcHeaderImage',
                'woocommerce_email_footer_text' => 'wcFooterText',
            ) as $option => $method) {
                add_filter('pre_option_' . $option, array(__CLASS__, $method), 20);
            }

            add_action('admin_notices', array(__CLASS__, 'wcNotice'));
        }
    }

    // ------------------------------------------------------------------
    // Réglages résolus
    // ------------------------------------------------------------------

    /**
     * Couleur de marque : celle des billets, ou une couleur propre aux e-mails.
     */
    public static function color()
    {
        $own = (string) Settings::get('email_color');

        if (Settings::get('email_color_mode') === 'fixed' && self::isHex($own)) {
            return strtolower($own);
        }

        $ticket = (string) Settings::get('ticket_color');

        return self::isHex($ticket) ? strtolower($ticket) : '#8c3c34';
    }

    public static function background()
    {
        $value = (string) Settings::get('email_background');

        return self::isHex($value) ? strtolower($value) : '#f6f3ef';
    }

    /**
     * Logo des e-mails : le sien, sinon celui des billets. '' sans logo.
     */
    public static function logoUrl()
    {
        foreach (array('email_logo_id', 'ticket_logo_id') as $key) {
            $id = (int) Settings::get($key);

            if ($id > 0) {
                $url = wp_get_attachment_image_url($id, 'medium');

                if ($url) {
                    return (string) $url;
                }
            }
        }

        return '';
    }

    /**
     * Texte du pied de page (brut). Par défaut : nom, e-mail et téléphone de contact.
     */
    public static function footerText()
    {
        $text = trim((string) Settings::get('email_footer'));

        if ($text !== '') {
            return $text;
        }

        return implode(' · ', array_filter(array(
            Settings::businessName(),
            trim((string) Settings::get('contact_email')),
            trim((string) Settings::get('contact_phone')),
        )));
    }

    /**
     * Couleur lisible sur la couleur de marque (bouton, bandeau).
     */
    public static function ink($hex)
    {
        $rgb = self::rgb($hex);
        $luminance = (0.299 * $rgb[0] + 0.587 * $rgb[1] + 0.114 * $rgb[2]) / 255;

        return $luminance > 0.62 ? '#2b2420' : '#ffffff';
    }

    // ------------------------------------------------------------------
    // Gabarit
    // ------------------------------------------------------------------

    /**
     * Texte brut → HTML : échappé, retours à la ligne, liens cliquables.
     */
    public static function textToHtml($text)
    {
        // Anciens e-mails WordPress : liens entre chevrons (<https://…>).
        $text = preg_replace('#<(https?://[^>\s]+)>#i', '$1', (string) $text);

        return make_clickable(nl2br(esc_html($text)));
    }

    /**
     * Corps d'un message Passflow → HTML. Comme textToHtml(), mais une ligne « Libellé : https://… »
     * devient un bouton portant le libellé : plus de longues adresses illisibles dans le message.
     * Les lignes de liens qui se suivent forment un groupe ; seul le premier bouton du message est plein.
     */
    public static function bodyToHtml($text)
    {
        $color = self::color();
        $ink = self::ink($color);
        $html = '';
        $plain = array();
        $buttons = array();
        $filled = false;

        $flush_text = function () use (&$plain, &$html) {
            if ($plain) {
                $html .= self::textToHtml(implode("\n", $plain));
                $plain = array();
            }
        };
        $flush_buttons = function () use (&$buttons, &$html, &$filled, $color, $ink) {
            if (!$buttons) {
                return;
            }

            $html .= '<div style="margin:12px 0 6px;">';

            foreach ($buttons as $button) {
                $style = !$filled
                    ? 'background:' . $color . ';color:' . $ink . ';border:1px solid ' . $color . ';'
                    : 'background:#ffffff;color:' . $color . ';border:1px solid ' . $color . ';';
                $filled = true;
                $html .= '<a href="' . esc_url($button[1]) . '" style="display:inline-block;' . esc_attr($style)
                    . 'text-decoration:none;font-weight:600;font-size:14px;line-height:20px;padding:10px 18px;border-radius:10px;margin:0 8px 8px 0;">'
                    . esc_html($button[0]) . '</a>';
            }

            $html .= '</div>';
            $buttons = array();
        };

        foreach (preg_split('/\r\n|\r|\n/', (string) $text) as $line) {
            if (preg_match('#^\s*(?:[•\-*]\s*)?([^:]{1,90}?)\s*:\s*<?(https?://[^\s>]+)>?\s*$#u', $line, $match)) {
                $flush_text();
                $buttons[] = array(trim($match[1]), $match[2]);
                continue;
            }

            $flush_buttons();

            // Ligne vide juste après des boutons : la marge du groupe suffit.
            if (trim($line) === '' && substr($html, -6) === '</div>' && !$plain) {
                continue;
            }

            $plain[] = $line;
        }

        $flush_buttons();
        $flush_text();

        return $html;
    }

    /**
     * Habille un contenu HTML (déjà sûr) avec le logo, la couleur et le pied de page.
     */
    public static function wrap($content_html, $title = '')
    {
        $color = self::color();
        $background = self::background();
        $logo = self::logoUrl();
        $name = Settings::businessName();
        $footer = self::footerText();
        $font = "-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif";

        // Liens à la couleur de marque (make_clickable ne pose pas de style).
        $content_html = preg_replace('#<a (?![^>]*style=)#i', '<a style="color:' . esc_attr($color) . ';text-decoration:underline;" ', (string) $content_html);

        $header = $logo !== ''
            ? '<img src="' . esc_url($logo) . '" alt="' . esc_attr($name) . '" style="display:block;margin:0 auto;max-width:200px;max-height:72px;width:auto;height:auto;border:0;">'
            : '<span style="font-size:20px;font-weight:600;letter-spacing:.01em;color:' . esc_attr($color) . ';">' . esc_html($name) . '</span>';

        $footer_html = $footer !== ''
            ? '<tr><td style="padding:18px 24px 0;text-align:center;font-size:12px;line-height:1.6;color:#7a716b;">' . self::textToHtml($footer) . '</td></tr>'
            : '';

        $html = '<!doctype html><html lang="' . esc_attr(substr((string) get_bloginfo('language'), 0, 2) ?: 'fr') . '"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1">'
            . ($title !== '' ? '<title>' . esc_html($title) . '</title>' : '')
            . '</head>'
            . '<body style="margin:0;padding:0;background:' . esc_attr($background) . ';">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:' . esc_attr($background) . ';font-family:' . esc_attr($font) . ';color:#1d2327;">'
            . '<tr><td align="center" style="padding:28px 12px;">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:580px;">'
            . '<tr><td style="padding:0 0 18px;text-align:center;">' . $header . '</td></tr>'
            . '<tr><td style="background:#ffffff;border-radius:14px;border-top:4px solid ' . esc_attr($color) . ';padding:28px 32px;font-size:15px;line-height:1.6;">'
            . $content_html
            . '</td></tr>'
            . $footer_html
            . '</table></td></tr></table></body></html>';

        /**
         * Dernier mot sur le gabarit des e-mails.
         *
         * @param string $html
         * @param string $content_html
         */
        return (string) apply_filters('smbb_passflow_email_html', $html, $content_html);
    }

    // ------------------------------------------------------------------
    // E-mails de WordPress
    // ------------------------------------------------------------------

    /**
     * @param array<string,mixed> $atts to, subject, message, headers, attachments
     * @return array<string,mixed>
     */
    public static function wrapWordPressMail($atts)
    {
        if (!is_array($atts) || (int) Settings::get('email_wp') !== 1) {
            return $atts;
        }

        $message = isset($atts['message']) ? (string) $atts['message'] : '';
        $headers = isset($atts['headers']) ? $atts['headers'] : array();
        $header_text = is_array($headers) ? implode("\n", array_map('strval', $headers)) : (string) $headers;

        // Déjà mis en forme (Content-Type posé, HTML, multipart) : on laisse tel quel.
        if ($message === ''
            || preg_match('/content-type\s*:/i', $header_text)
            || preg_match('/<(html|body|table|div|p|br)\b/i', $message)
            || apply_filters('wp_mail_content_type', 'text/plain') !== 'text/plain'
        ) {
            return $atts;
        }

        /**
         * Pour exclure un e-mail précis de l'habillage.
         *
         * @param bool                $wrap
         * @param array<string,mixed> $atts
         */
        if (!apply_filters('smbb_passflow_email_wrap_wordpress', true, $atts)) {
            return $atts;
        }

        $atts['message'] = self::wrap(self::textToHtml($message), isset($atts['subject']) ? (string) $atts['subject'] : '');

        if (is_array($headers)) {
            $headers[] = 'Content-Type: text/html; charset=UTF-8';
        } else {
            $headers = trim($headers) . ($headers !== '' ? "\r\n" : '') . 'Content-Type: text/html; charset=UTF-8';
        }

        $atts['headers'] = $headers;

        return $atts;
    }

    // ------------------------------------------------------------------
    // WooCommerce
    // ------------------------------------------------------------------

    /**
     * Sur WooCommerce › Réglages › E-mails, on laisse voir les valeurs propres à WooCommerce
     * (sinon elles sembleraient changées), sauf pour l'aperçu.
     */
    private static function onWcEmailScreen()
    {
        if (!is_admin() || wp_doing_ajax()) {
            return false;
        }

        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
        $tab = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : '';

        return $page === 'wc-settings' && $tab === 'email' && empty($_GET['preview_woocommerce_mail']);
    }

    /** @param mixed $value */
    public static function wcBaseColor($value)
    {
        return self::onWcEmailScreen() ? $value : self::color();
    }

    /** @param mixed $value */
    public static function wcBackground($value)
    {
        return self::onWcEmailScreen() ? $value : self::background();
    }

    /** @param mixed $value */
    public static function wcBodyBackground($value)
    {
        return self::onWcEmailScreen() ? $value : '#ffffff';
    }

    /** @param mixed $value */
    public static function wcHeaderImage($value)
    {
        if (self::onWcEmailScreen()) {
            return $value;
        }

        $logo = self::logoUrl();

        return $logo !== '' ? $logo : $value;
    }

    /** @param mixed $value */
    public static function wcFooterText($value)
    {
        if (self::onWcEmailScreen()) {
            return $value;
        }

        $footer = self::footerText();

        return $footer !== '' ? $footer : $value;
    }

    public static function wcNotice()
    {
        if (!self::onWcEmailScreen() || !empty($_GET['section'])) {
            return;
        }

        echo '<div class="notice notice-info"><p>'
            . esc_html__('Les couleurs, le logo et le pied de page des e-mails WooCommerce viennent de Passflow › Réglages › Envoi. Les champs « Modèle d\'e-mail » ci-dessous ne s\'appliquent pas tant que cette option y est cochée.', 'smbb-passflow')
            . ' <a href="' . esc_url(admin_url('admin.php?page=smbb-codetool-passflow_settings')) . '">' . esc_html__('Ouvrir les réglages Passflow', 'smbb-passflow') . '</a>'
            . '</p></div>';
    }

    // ------------------------------------------------------------------
    // Aperçu
    // ------------------------------------------------------------------

    public static function previewUrl()
    {
        return wp_nonce_url(add_query_arg('action', self::PREVIEW, admin_url('admin-post.php')), self::PREVIEW);
    }

    public static function preview()
    {
        if (!current_user_can(Roles::CAP_MANAGE)) {
            wp_die(esc_html__('Accès refusé.', 'smbb-passflow'), '', array('response' => 403));
        }

        check_admin_referer(self::PREVIEW);

        $d = 'smbb-passflow';
        $user = wp_get_current_user();
        $first = $user && $user->exists() && $user->first_name !== '' ? $user->first_name : __('Camille', $d);
        $text = sprintf(__("Bonjour %s,\n\nVoici à quoi ressemblent les e-mails envoyés par %s : confirmations, rappels, billets, et, selon vos réglages, ceux de WordPress et de WooCommerce.\n\nLes liens prennent la couleur de la marque : %s\n\nÀ bientôt !", $d), $first, Settings::businessName(), home_url('/'));

        nocache_headers();
        header('Content-Type: text/html; charset=UTF-8');
        echo self::wrap(self::textToHtml($text), __('Aperçu des e-mails', $d)); // phpcs:ignore WordPress.Security.EscapeOutput
        exit;
    }

    // ------------------------------------------------------------------

    private static function isHex($value)
    {
        return (bool) preg_match('/^#[0-9a-fA-F]{6}$/', (string) $value);
    }

    /**
     * @return array{0:int,1:int,2:int}
     */
    private static function rgb($hex)
    {
        $hex = ltrim((string) $hex, '#');

        if (strlen($hex) !== 6) {
            return array(140, 60, 52);
        }

        return array(hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2)));
    }
}
