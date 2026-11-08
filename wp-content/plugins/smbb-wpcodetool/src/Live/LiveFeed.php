<?php

namespace Smbb\WpCodeTool\Live;

defined('ABSPATH') || exit;

/**
 * « En direct » : une bulle discrète, avec un petit son, dans l'administration à chaque nouvelle vente.
 *
 * Fonctionnement :
 * - les événements sont gardés dans une courte file (option smbb_codetool_live_feed, non chargée
 *   d'office) : numéro d'ordre, titre, texte, montant, lien, capacités qui peuvent le voir ;
 * - chaque onglet de l'admin ouvert et visible demande toutes les 20 s ce qui est nouveau
 *   (admin-ajax smbb_codetool_live, une seule lecture d'option). Onglet caché : rien n'est demandé,
 *   le retard (15 min au plus) arrive au retour. Entre onglets, le dernier numéro vu est partagé :
 *   une vente ne sonne qu'une fois ;
 * - la personne qui a fait la vente depuis l'admin (caisse) ne reçoit pas sa propre bulle.
 *
 * Sources :
 * - WooCommerce, fourni ici : toute commande qui passe « payée » (en cours / terminée), une seule fois.
 *   Les plugins l'enrichissent par le filtre `smbb_wpcodetool_live_order_event` ($event, $order) :
 *   titre, texte, lien, capacités… (false = pas de bulle pour cette commande) ;
 * - n'importe quel plugin : LiveFeed::push(array(...)) pour ses propres événements.
 *
 * Réglages : CodeTool › WooCommerce › « Ventes en direct » (pour le site) ; widget « Mes préférences
 * d'affichage » et bouton de la bulle (pour soi : avec son / sans son / coupé, méta smbb_codetool_live).
 *
 * Filtres : `smbb_wpcodetool_live_enabled` (false = rien du tout), `smbb_wpcodetool_live_capabilities`
 * (capacités qui reçoivent des bulles, pour savoir s'il faut charger le script),
 * `smbb_wpcodetool_live_event` (chaque événement avant d'être gardé, false = ignoré),
 * `smbb_wpcodetool_live_interval` (secondes entre deux demandes, 10 à 120).
 * Action : `smbb_wpcodetool_live_pushed` ($event).
 * Côté page : un élément [data-smbb-live-quiet] met les bulles en pause (écran d'appel, plein écran…).
 */
final class LiveFeed
{
    const OPTION = 'smbb_codetool_live_feed';
    const SETTINGS = 'smbb_codetool_live';
    const USER_META = 'smbb_codetool_live';
    const NONCE = 'smbb_codetool_live';
    const ORDER_META = '_smbb_live_sent';

    /** Événements gardés au plus. */
    const KEEP = 40;
    /** Âge maximum d'un événement gardé, en secondes. */
    const TTL = 7200;
    /** Retard rattrapé au retour sur un onglet ou au chargement d'une page, en secondes. */
    const CATCH_UP = 900;

    public static function hooks()
    {
        add_action('woocommerce_order_status_changed', array(__CLASS__, 'orderStatus'), 50, 4);
        add_action('admin_enqueue_scripts', array(__CLASS__, 'enqueue'), 30);
        add_action('wp_ajax_smbb_codetool_live', array(__CLASS__, 'poll'));
        add_action('wp_ajax_smbb_codetool_live_pref', array(__CLASS__, 'savePref'));
    }

    /* ---------------------------------------------------------------- Réglages */

    /**
     * @return array{orders:int,sound:int}
     */
    public static function settings()
    {
        $saved = get_option(self::SETTINGS, array());

        return array_merge(array(
            'orders' => 1, // bulle à chaque commande WooCommerce payée
            'sound' => 1,  // son par défaut (chacun peut le couper pour lui)
        ), is_array($saved) ? array_map('intval', $saved) : array());
    }

    public static function saveSettings(array $values)
    {
        $settings = array(
            'orders' => !empty($values['orders']) ? 1 : 0,
            'sound' => !empty($values['sound']) ? 1 : 0,
        );
        update_option(self::SETTINGS, $settings, false);

        return $settings;
    }

    /** @return array<string,string> */
    public static function modes()
    {
        return array(
            'sound' => __('Avec son', 'smbb-wpcodetool'),
            'mute' => __('Sans son', 'smbb-wpcodetool'),
            'off' => __('Coupé', 'smbb-wpcodetool'),
        );
    }

    /** Choix de l'utilisateur : sound, mute ou off (sans choix : le réglage du site). */
    public static function mode($user_id = 0)
    {
        $user_id = $user_id ?: get_current_user_id();
        $mode = (string) get_user_meta($user_id, self::USER_META, true);

        if (isset(self::modes()[$mode])) {
            return $mode;
        }

        return self::settings()['sound'] ? 'sound' : 'mute';
    }

    public static function setMode($mode, $user_id = 0)
    {
        $user_id = $user_id ?: get_current_user_id();

        if (!isset(self::modes()[$mode])) {
            return false;
        }

        update_user_meta($user_id, self::USER_META, $mode);

        return true;
    }

    public static function enabled()
    {
        return (bool) apply_filters('smbb_wpcodetool_live_enabled', true);
    }

    /**
     * Capacités qui reçoivent des bulles (au moins une suffit pour charger le script).
     *
     * @return array<int,string>
     */
    public static function capabilities()
    {
        $caps = array();

        if (class_exists('WooCommerce') && self::settings()['orders']) {
            $caps[] = 'edit_shop_orders';
        }

        return array_values(array_unique(array_filter((array) apply_filters('smbb_wpcodetool_live_capabilities', $caps))));
    }

    /** L'utilisateur courant est-il concerné (au moins une source le vise) ? */
    public static function relevant()
    {
        if (!self::enabled() || !is_user_logged_in()) {
            return false;
        }

        foreach (self::capabilities() as $cap) {
            if (current_user_can($cap)) {
                return true;
            }
        }

        return false;
    }

    /* ---------------------------------------------------------------- File d'événements */

    /**
     * @return array{seq:int,events:array<int,array<string,mixed>>}
     */
    private static function feed()
    {
        $feed = get_option(self::OPTION, array());

        return array(
            'seq' => isset($feed['seq']) ? (int) $feed['seq'] : 0,
            'events' => isset($feed['events']) && is_array($feed['events']) ? $feed['events'] : array(),
        );
    }

    /** Dernier numéro d'ordre (point de départ d'une page qui s'ouvre). */
    public static function seq()
    {
        return self::feed()['seq'];
    }

    /**
     * Ajoute un événement et renvoie son numéro (0 s'il est ignoré).
     *
     * Champs : title (obligatoire), text, amount (texte déjà formaté), badge (« En ligne », « Caisse »…),
     * url, icon (cart | cashdesk | ticket | link | bell), tone (sale | info), caps (capacités, une suffit ;
     * par défaut edit_shop_orders), users (comptes qui la voient aussi, sans la capacité), user (auteur, qui ne reçoit pas la bulle ; par défaut l'utilisateur
     * connecté s'il agit depuis l'admin), source, ref.
     *
     * @param array<string,mixed> $event
     */
    public static function push(array $event)
    {
        if (!self::enabled()) {
            return 0;
        }

        $event = apply_filters('smbb_wpcodetool_live_event', $event);

        if (!is_array($event) || empty($event['title'])) {
            return 0;
        }

        $now = time();
        $feed = self::feed();
        $seq = $feed['seq'] + 1;
        $icons = array('cart', 'cashdesk', 'ticket', 'link', 'bell');
        $caps = isset($event['caps']) ? array_values(array_filter(array_map('strval', (array) $event['caps']))) : array();

        $clean = array(
            'seq' => $seq,
            'time' => $now,
            'source' => isset($event['source']) ? sanitize_key($event['source']) : 'custom',
            'ref' => isset($event['ref']) ? (string) $event['ref'] : '',
            'title' => wp_strip_all_tags((string) $event['title']),
            'text' => isset($event['text']) ? wp_strip_all_tags((string) $event['text']) : '',
            'amount' => isset($event['amount']) ? wp_strip_all_tags((string) $event['amount']) : '',
            'badge' => isset($event['badge']) ? wp_strip_all_tags((string) $event['badge']) : '',
            'url' => isset($event['url']) ? esc_url_raw((string) $event['url']) : '',
            'icon' => isset($event['icon']) && in_array($event['icon'], $icons, true) ? $event['icon'] : 'cart',
            'tone' => isset($event['tone']) && $event['tone'] === 'info' ? 'info' : 'sale',
            'caps' => $caps ?: array('edit_shop_orders'),
            'users' => isset($event['users']) ? array_values(array_filter(array_map('intval', (array) $event['users']))) : array(),
            'user' => isset($event['user']) ? (int) $event['user'] : (is_admin() && !wp_doing_cron() ? get_current_user_id() : 0),
        );

        $events = array_values(array_filter($feed['events'], static function ($item) use ($now) {
            return isset($item['time']) && (int) $item['time'] > $now - self::TTL;
        }));
        $events[] = $clean;

        if (count($events) > self::KEEP) {
            $events = array_slice($events, -self::KEEP);
        }

        update_option(self::OPTION, array('seq' => $seq, 'events' => $events), false);
        do_action('smbb_wpcodetool_live_pushed', $clean);

        return $seq;
    }

    /* ---------------------------------------------------------------- Source WooCommerce */

    /**
     * Commande qui devient payée : une bulle, une seule fois par commande.
     */
    public static function orderStatus($order_id, $from, $to, $order = null)
    {
        if (!self::enabled() || !self::settings()['orders'] || !function_exists('wc_get_order')) {
            return;
        }

        $paid = function_exists('wc_get_is_paid_statuses') ? wc_get_is_paid_statuses() : array('processing', 'completed');

        if (!in_array($to, $paid, true) || in_array($from, $paid, true)) {
            return;
        }

        $order = $order instanceof \WC_Order ? $order : wc_get_order($order_id);

        if (!$order || $order->get_type() !== 'shop_order' || $order->get_meta(self::ORDER_META)) {
            return;
        }

        $order->update_meta_data(self::ORDER_META, time());
        $order->save_meta_data();

        $event = apply_filters('smbb_wpcodetool_live_order_event', self::orderEvent($order), $order);

        if (is_array($event)) {
            self::push($event);
        }
    }

    /**
     * Bulle par défaut d'une commande : « Nouvelle commande · 45,00 € », client · articles.
     *
     * @param \WC_Order $order
     * @return array<string,mixed>
     */
    public static function orderEvent($order)
    {
        $lines = array();
        $count = 0;

        foreach ($order->get_items('line_item') as $item) {
            $count++;

            if (count($lines) < 2) {
                $qty = (int) $item->get_quantity();
                $lines[] = $item->get_name() . ($qty > 1 ? ' ×' . $qty : '');
            }
        }

        $summary = implode(', ', $lines);

        if ($count > 2) {
            /* translators: %d: nombre d'articles en plus */
            $summary .= ' ' . sprintf(_n('+ %d autre', '+ %d autres', $count - 2, 'smbb-wpcodetool'), $count - 2);
        }

        return array(
            'source' => 'woocommerce',
            'ref' => 'order:' . $order->get_id(),
            'title' => sprintf(__('Nouvelle commande n° %s', 'smbb-wpcodetool'), $order->get_order_number()),
            'text' => implode(' · ', array_filter(array(self::customerName($order), $summary))),
            'amount' => self::money($order->get_total(), $order->get_currency()),
            'badge' => $order->get_payment_method_title() ?: __('En ligne', 'smbb-wpcodetool'),
            'url' => $order->get_edit_order_url(),
            'icon' => 'cart',
            'caps' => array('edit_shop_orders'),
        );
    }

    /** @param \WC_Order $order */
    public static function customerName($order)
    {
        $name = trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name());

        if ($name === '' && $order->get_customer_id()) {
            $user = get_userdata($order->get_customer_id());
            $name = $user ? $user->display_name : '';
        }

        return $name !== '' ? $name : (string) $order->get_billing_email();
    }

    public static function money($amount, $currency = '')
    {
        if (!function_exists('wc_price')) {
            return number_format_i18n((float) $amount, 2);
        }

        return html_entity_decode(wp_strip_all_tags(wc_price($amount, $currency ? array('currency' => $currency) : array())), ENT_QUOTES, 'UTF-8');
    }

    /* ---------------------------------------------------------------- Navigateur */

    public static function enqueue()
    {
        if ((defined('IFRAME_REQUEST') && IFRAME_REQUEST) || !self::relevant() || self::mode() === 'off') {
            return;
        }

        $version = static function ($file) {
            $path = SMBB_WPCODETOOL_PATH . 'assets/' . $file;

            return SMBB_WPCODETOOL_VERSION . '.' . (file_exists($path) ? filemtime($path) : 0);
        };

        wp_enqueue_style('smbb-codetool-live', SMBB_WPCODETOOL_URL . 'assets/live.css', array(), $version('live.css'));
        wp_enqueue_script('smbb-codetool-live', SMBB_WPCODETOOL_URL . 'assets/live.js', array(), $version('live.js'), true);
        wp_add_inline_script('smbb-codetool-live', 'window.smbbLive = ' . wp_json_encode(self::config()) . ';', 'before');
    }

    /** @return array<string,mixed> */
    public static function config()
    {
        $interval = (int) apply_filters('smbb_wpcodetool_live_interval', 20);

        return array(
            'ajax' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce(self::NONCE),
            'seq' => self::seq(),
            'mode' => self::mode(),
            'interval' => max(10, min(120, $interval)) * 1000,
            'user' => get_current_user_id(),
            'i18n' => array(
                'close' => __('Fermer', 'smbb-wpcodetool'),
                'mute' => __('Couper le son', 'smbb-wpcodetool'),
                'unmute' => __('Remettre le son', 'smbb-wpcodetool'),
                'now' => __('à l\'instant', 'smbb-wpcodetool'),
                /* translators: %d: minutes */
                'ago' => __('il y a %d min', 'smbb-wpcodetool'),
                /* translators: %d: nombre de ventes */
                'more' => __('+ %d autres ventes', 'smbb-wpcodetool'),
                'region' => __('Ventes en direct', 'smbb-wpcodetool'),
                'demoTitle' => __('Nouvelle commande n° 1042', 'smbb-wpcodetool'),
                'demoText' => __('Marie Dupont · Atelier découverte ×2', 'smbb-wpcodetool'),
                'demoAmount' => self::money(90),
                'demoBadge' => __('Exemple', 'smbb-wpcodetool'),
            ),
        );
    }

    /** Nouveaux événements pour l'utilisateur courant (AJAX). */
    public static function poll()
    {
        check_ajax_referer(self::NONCE);

        $after = isset($_POST['after']) ? absint(wp_unslash($_POST['after'])) : 0;
        $user_id = get_current_user_id();
        $feed = self::feed();
        $now = time();
        $out = array();

        if (self::enabled() && $after < $feed['seq']) {
            foreach ($feed['events'] as $event) {
                if ((int) $event['seq'] <= $after || (int) $event['time'] < $now - self::CATCH_UP) {
                    continue;
                }

                if ($user_id && (int) $event['user'] === $user_id) {
                    continue;
                }

                $allowed = !empty($event['users']) && in_array($user_id, (array) $event['users'], true);

                foreach ($allowed ? array() : (array) $event['caps'] as $cap) {
                    if (current_user_can($cap)) {
                        $allowed = true;
                        break;
                    }
                }

                if (!$allowed) {
                    continue;
                }

                unset($event['caps'], $event['user'], $event['users']);
                $out[] = $event;
            }
        }

        wp_send_json_success(array(
            'seq' => $feed['seq'],
            'now' => $now,
            'mode' => self::mode($user_id),
            'events' => $out,
        ));
    }

    /** Bouton de la bulle : avec son / sans son / coupé, pour soi (AJAX). */
    public static function savePref()
    {
        check_ajax_referer(self::NONCE);

        $mode = isset($_POST['mode']) ? sanitize_key(wp_unslash($_POST['mode'])) : '';

        if (!self::setMode($mode)) {
            wp_send_json_error(null, 400);
        }

        wp_send_json_success(array('mode' => $mode));
    }
}
