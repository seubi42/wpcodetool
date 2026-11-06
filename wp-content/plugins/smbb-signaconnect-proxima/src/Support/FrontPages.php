<?php

namespace Smbb\SignConnectProxima\Support;

defined('ABSPATH') || exit;

/**
 * Pages front de SignaConnect : chaque page choisie dans les Réglages doit contenir son shortcode.
 * Le contenu réel des shortcodes arrive avec les lots 2 (signature) et 5 (app).
 */
final class FrontPages
{
    const OPTION = 'smbb_scp_settings';
    const ACTION = 'scp_fix_front_page';

    /**
     * @return array<string,array{setting:string,shortcode:string,legacy:string,title:string,label:string}>
     */
    public static function pages()
    {
        return array(
            'app' => array(
                'setting' => 'app_page_id',
                'shortcode' => 'signaconnect_app',
                'legacy' => 'signconnect_app',
                'title' => __('SignaConnect', 'smbb-signaconnect-proxima'),
                'label' => __("Page de l'app", 'smbb-signaconnect-proxima'),
            ),
            'signing' => array(
                'setting' => 'signing_page_id',
                'shortcode' => 'signaconnect_signature',
                'legacy' => 'signconnect_signature',
                'title' => __('Signature', 'smbb-signaconnect-proxima'),
                'label' => __('Page de signature publique', 'smbb-signaconnect-proxima'),
            ),
        );
    }

    public static function hooks()
    {
        add_action('init', array(__CLASS__, 'registerShortcodes'));
        add_action('admin_post_' . self::ACTION, array(__CLASS__, 'handleFix'));
    }

    /**
     * Shortcodes déclarés dès maintenant pour qu'ils ne s'affichent pas en texte brut.
     */
    public static function registerShortcodes()
    {
        foreach (self::pages() as $page) {
            foreach (array($page['shortcode'], $page['legacy']) as $tag) {
                if (!shortcode_exists($tag)) {
                    add_shortcode($tag, array(__CLASS__, 'placeholder'));
                }
            }
        }
    }

    public static function placeholder()
    {
        return '<div class="signaconnect-placeholder"><p>' . esc_html__('SignaConnect sera bientôt disponible sur cette page.', 'smbb-signaconnect-proxima') . '</p></div>';
    }

    /**
     * État d'une page : missing (aucune page choisie), not_found, ok, no_shortcode.
     *
     * @return array{status:string,page_id:int,page:\WP_Post|null}
     */
    public static function status($key)
    {
        $pages = self::pages();
        $settings = get_option(self::OPTION, array());
        $settings = is_array($settings) ? $settings : array();
        $page_id = isset($settings[$pages[$key]['setting']]) ? (int) $settings[$pages[$key]['setting']] : 0;

        if ($page_id < 1) {
            return array('status' => 'missing', 'page_id' => 0, 'page' => null);
        }

        $page = get_post($page_id);

        if (!$page || $page->post_type !== 'page' || $page->post_status === 'trash') {
            return array('status' => 'not_found', 'page_id' => $page_id, 'page' => null);
        }

        // L'ancien shortcode (SignConnect, avant 0.9.1) reste accepté.
        $content = (string) $page->post_content;
        $status = has_shortcode($content, $pages[$key]['shortcode']) || has_shortcode($content, $pages[$key]['legacy']) ? 'ok' : 'no_shortcode';

        return array('status' => $status, 'page_id' => $page_id, 'page' => $page);
    }

    /**
     * Messages pour le tableau de bord (pages absentes ou sans shortcode).
     *
     * @return string[]
     */
    public static function problems()
    {
        $problems = array();

        foreach (self::pages() as $key => $page) {
            $status = self::status($key);

            if ($status['status'] === 'ok') {
                continue;
            }

            $problems[] = $status['status'] === 'no_shortcode'
                ? sprintf(__('%1$s : le shortcode [%2$s] est absent.', 'smbb-signaconnect-proxima'), $page['label'], $page['shortcode'])
                : sprintf(__('%s : aucune page valide choisie.', 'smbb-signaconnect-proxima'), $page['label']);
        }

        return $problems;
    }

    public static function fixUrl($key, $mode)
    {
        return wp_nonce_url(
            add_query_arg(array('action' => self::ACTION, 'page_key' => $key, 'mode' => $mode), admin_url('admin-post.php')),
            self::ACTION . '_' . $key
        );
    }

    /**
     * HTML de contrôle affiché sous les listes de pages dans les Réglages.
     */
    public static function checksHtml()
    {
        $html = '';

        foreach (self::pages() as $key => $page) {
            $status = self::status($key);
            $code = '<code>[' . esc_html($page['shortcode']) . ']</code>';
            $line = '<strong>' . esc_html($page['label']) . '</strong> — ';

            switch ($status['status']) {
                case 'ok':
                    $line .= '<span class="scp-badge scp-badge--active">' . esc_html__('OK', 'smbb-signaconnect-proxima') . '</span> '
                        . sprintf(esc_html__('%1$s contient %2$s.', 'smbb-signaconnect-proxima'), self::pageLink($status['page']), $code);
                    $class = 'scp-check scp-check--ok';
                    break;

                case 'no_shortcode':
                    $line .= sprintf(esc_html__('%1$s ne contient pas %2$s.', 'smbb-signaconnect-proxima'), self::pageLink($status['page']), $code)
                        . ' <a class="button button-primary button-small" href="' . esc_url(self::fixUrl($key, 'add')) . '">' . esc_html__('Ajouter le shortcode', 'smbb-signaconnect-proxima') . '</a>';
                    $class = 'scp-alert';
                    break;

                default:
                    $line .= esc_html__('Aucune page valide choisie.', 'smbb-signaconnect-proxima')
                        . ' <a class="button button-small" href="' . esc_url(self::fixUrl($key, 'create')) . '">' . esc_html__('Créer la page', 'smbb-signaconnect-proxima') . '</a>';
                    $class = 'scp-alert';
            }

            $html .= '<div class="' . esc_attr($class) . '">' . $line . '</div>';
        }

        if (isset($_GET['scp_page_fixed'])) {
            $html = '<div class="notice notice-success inline"><p>' . esc_html__('Page mise à jour.', 'smbb-signaconnect-proxima') . '</p></div>' . $html;
        }

        return $html;
    }

    private static function pageLink($page)
    {
        $url = get_edit_post_link($page->ID);
        $title = esc_html($page->post_title !== '' ? $page->post_title : '#' . $page->ID);

        return $url ? '<a href="' . esc_url($url) . '">' . $title . '</a>' : $title;
    }

    /**
     * Ajoute le shortcode à la page choisie, ou crée la page et l'enregistre dans les Réglages.
     */
    public static function handleFix()
    {
        $key = isset($_GET['page_key']) ? sanitize_key(wp_unslash($_GET['page_key'])) : '';
        $mode = isset($_GET['mode']) ? sanitize_key(wp_unslash($_GET['mode'])) : '';
        $pages = self::pages();

        if (!isset($pages[$key]) || !current_user_can('manage_options')) {
            wp_die(esc_html__('Action non autorisée.', 'smbb-signaconnect-proxima'), '', array('response' => 403, 'back_link' => true));
        }

        check_admin_referer(self::ACTION . '_' . $key);

        $page = $pages[$key];
        $block = '<!-- wp:shortcode -->[' . $page['shortcode'] . ']<!-- /wp:shortcode -->';
        $status = self::status($key);

        if ($mode === 'add' && $status['status'] === 'no_shortcode') {
            $content = trim((string) $status['page']->post_content);
            wp_update_post(array(
                'ID' => $status['page_id'],
                'post_content' => $content === '' ? $block : $content . "\n\n" . $block,
            ));
        } elseif ($mode === 'create' && in_array($status['status'], array('missing', 'not_found'), true)) {
            $page_id = wp_insert_post(array(
                'post_type' => 'page',
                'post_status' => 'publish',
                'post_title' => $page['title'],
                'post_content' => $block,
            ));

            if (!is_wp_error($page_id) && $page_id) {
                $settings = get_option(self::OPTION, array());
                $settings = is_array($settings) ? $settings : array();
                $settings[$page['setting']] = (int) $page_id;
                update_option(self::OPTION, $settings, false);
            }
        }

        $back = wp_get_referer() ?: admin_url('admin.php?page=smbb-codetool-scp');
        wp_safe_redirect(add_query_arg('scp_page_fixed', '1', remove_query_arg('scp_page_fixed', $back)));
        exit;
    }
}
