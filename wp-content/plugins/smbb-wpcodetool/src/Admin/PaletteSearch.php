<?php

namespace Smbb\WpCodeTool\Admin;

defined('ABSPATH') || exit;

/**
 * Recherche Ctrl K : résultats « métier » fournis par les extensions (participants, commandes…).
 *
 * Une extension déclare un fournisseur avec le filtre smbb_wpcodetool_palette_providers :
 *
 *   add_filter('smbb_wpcodetool_palette_providers', function ($providers) {
 *       $providers['passflow_people'] = array(
 *           'label' => 'Participants',          // titre du groupe dans la palette
 *           'icon' => 'groups',                 // Dashicon par défaut des résultats (sans « dashicons- »)
 *           'capability' => 'manage_passflow',  // facultatif : droit requis (sinon « read »)
 *           'priority' => 10,                   // ordre des groupes (petit = en haut)
 *           'min' => 2,                         // longueur minimale de la recherche
 *           'callback' => function ($query, $limit) {
 *               return array(array(
 *                   'label' => 'Marie Dupont',                // obligatoire
 *                   'url' => '…',                             // obligatoire : Entrée ouvre ce lien
 *                   'sub' => 'Atelier macarons — sam. 6 déc.',// 2e ligne, facultative
 *                   'meta' => 'Payée',                        // pastille à droite, facultative
 *                   'tone' => 'ok',                           // couleur de la pastille : ok, warn, off, info
 *                   'icon' => 'tickets-alt',                  // facultatif
 *                   'actions' => array(                       // actions rapides (Tab / → pour les parcourir)
 *                       array('label' => 'Billet', 'url' => '…', 'icon' => 'tickets-alt', 'target' => '_blank'),
 *                       array('label' => 'Pointer présent', 'url' => '…', 'icon' => 'yes', 'confirm' => '…'),
 *                   ),
 *               ));
 *           },
 *       );
 *       return $providers;
 *   });
 *
 * La palette (assets/wp-palette.js) interroge admin-ajax.php?action=smbb_codetool_palette à la frappe
 * et affiche un groupe par fournisseur, avant les pages et les contenus.
 * Sur les écrans CodeTool, la palette se charge dès qu'un fournisseur existe, même si
 * « Recherche Ctrl K partout » est coupé (elle reste alors limitée aux écrans de l'app).
 */
final class PaletteSearch
{
    const AJAX = 'smbb_codetool_palette';
    const MAX_ITEMS = 8;
    const MAX_ACTIONS = 6;

    /** @var array<string,array<string,mixed>>|null */
    private static $providers;

    public static function hooks()
    {
        add_action('wp_ajax_' . self::AJAX, array(__CLASS__, 'ajax'));
    }

    /**
     * Fournisseurs déclarés que l'utilisateur courant a le droit d'interroger, triés par priorité.
     *
     * @return array<string,array<string,mixed>>
     */
    public static function providers()
    {
        if (self::$providers !== null) {
            return self::$providers;
        }

        $out = array();
        $raw = apply_filters('smbb_wpcodetool_palette_providers', array());

        foreach ((array) $raw as $key => $provider) {
            if (!is_array($provider) || empty($provider['callback']) || !is_callable($provider['callback'])) {
                continue;
            }

            $capability = !empty($provider['capability']) ? (string) $provider['capability'] : 'read';

            if (!current_user_can($capability)) {
                continue;
            }

            $out[sanitize_key($key)] = array(
                'label' => isset($provider['label']) ? (string) $provider['label'] : (string) $key,
                'icon' => self::icon(isset($provider['icon']) ? $provider['icon'] : ''),
                'priority' => isset($provider['priority']) ? (int) $provider['priority'] : 10,
                'min' => isset($provider['min']) ? max(1, (int) $provider['min']) : 2,
                'callback' => $provider['callback'],
            );
        }

        uasort($out, function ($a, $b) {
            return $a['priority'] - $b['priority'];
        });

        return self::$providers = $out;
    }

    public static function hasProviders()
    {
        return (bool) self::providers();
    }

    /**
     * Données passées à la palette.
     *
     * @return array<string,mixed>
     */
    public static function clientData()
    {
        if (!self::hasProviders()) {
            return array();
        }

        return array(
            'url' => admin_url('admin-ajax.php'),
            'action' => self::AJAX,
            'nonce' => wp_create_nonce(self::AJAX),
        );
    }

    /**
     * admin-ajax : { groups: [ { key, label, icon, items: [ … ] } ] }.
     */
    public static function ajax()
    {
        check_ajax_referer(self::AJAX, 'nonce');

        $query = isset($_GET['q']) ? sanitize_text_field(wp_unslash($_GET['q'])) : '';
        $query = trim(preg_replace('/\s+/', ' ', $query));

        wp_send_json_success(array('groups' => self::search($query)));
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public static function search($query)
    {
        $groups = array();
        $length = function_exists('mb_strlen') ? mb_strlen($query) : strlen($query);

        if ($query === '') {
            return $groups;
        }

        foreach (self::providers() as $key => $provider) {
            if ($length < $provider['min']) {
                continue;
            }

            try {
                $rows = call_user_func($provider['callback'], $query, self::MAX_ITEMS);
            } catch (\Throwable $e) {
                $rows = array(); // un fournisseur en panne ne casse pas la palette
            }

            $items = array();

            foreach ((array) $rows as $row) {
                $item = self::item($row, $provider['icon']);

                if ($item) {
                    $items[] = $item;
                }

                if (count($items) >= self::MAX_ITEMS) {
                    break;
                }
            }

            if ($items) {
                $groups[] = array(
                    'key' => $key,
                    'label' => $provider['label'],
                    'icon' => $provider['icon'],
                    'items' => $items,
                );
            }
        }

        return $groups;
    }

    /**
     * Résultat nettoyé (textes, liens, icônes), ou null s'il manque le libellé ou le lien.
     *
     * @param mixed $row
     * @return array<string,mixed>|null
     */
    private static function item($row, $fallback_icon)
    {
        if (!is_array($row) || empty($row['label']) || empty($row['url'])) {
            return null;
        }

        $url = esc_url_raw((string) $row['url']);

        if ($url === '') {
            return null;
        }

        $tones = array('ok', 'warn', 'off', 'info', 'danger');
        $item = array(
            'label' => wp_strip_all_tags((string) $row['label']),
            'url' => $url,
            'icon' => !empty($row['icon']) ? self::icon($row['icon']) : $fallback_icon,
            'sub' => isset($row['sub']) ? wp_strip_all_tags((string) $row['sub']) : '',
            'meta' => isset($row['meta']) ? wp_strip_all_tags((string) $row['meta']) : '',
            'tone' => isset($row['tone']) && in_array($row['tone'], $tones, true) ? $row['tone'] : '',
            'actions' => array(),
        );

        foreach (isset($row['actions']) ? (array) $row['actions'] : array() as $action) {
            if (!is_array($action) || empty($action['label']) || empty($action['url'])) {
                continue;
            }

            $action_url = esc_url_raw((string) $action['url']);

            if ($action_url === '') {
                continue;
            }

            $item['actions'][] = array(
                'label' => wp_strip_all_tags((string) $action['label']),
                'url' => $action_url,
                'icon' => !empty($action['icon']) ? self::icon($action['icon']) : 'arrow-right-alt2',
                'target' => isset($action['target']) && $action['target'] === '_blank' ? '_blank' : '',
                'confirm' => isset($action['confirm']) ? wp_strip_all_tags((string) $action['confirm']) : '',
            );

            if (count($item['actions']) >= self::MAX_ACTIONS) {
                break;
            }
        }

        return $item;
    }

    private static function icon($icon)
    {
        $icon = preg_replace('/^dashicons-/', '', (string) $icon);

        return $icon !== '' ? sanitize_html_class($icon) : '';
    }
}
