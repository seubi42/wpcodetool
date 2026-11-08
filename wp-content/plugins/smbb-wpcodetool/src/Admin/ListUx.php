<?php

namespace Smbb\WpCodeTool\Admin;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Listes de l'admin plus confortables (CodeTool › Apparence › « Listes plus confortables »).
 *
 * - Barre d'actions groupées flottante (assets/wp-lists.js) : dès qu'on coche des lignes d'une
 *   liste WordPress ou CodeTool, une barre en bas d'écran propose les actions en boutons ;
 *   elle pilote le select et le bouton « Appliquer » d'origine, donc les confirmations et
 *   l'édition groupée de WordPress marchent comme avant.
 * - Dates lisibles : colonne Date des contenus WordPress (filtre post_date_column_time) et
 *   dates SQL brutes des tableaux CodeTool (en JS), date complète au survol.
 */
class ListUx
{
    public static function hooks()
    {
        add_filter('post_date_column_time', array(__CLASS__, 'postDate'), 20, 2);
        add_action('admin_enqueue_scripts', array(__CLASS__, 'assets'), 40);
    }

    /** Colonne Date des listes de contenus : « Aujourd'hui, 14:05 », date complète au survol. */
    public static function postDate($t_time, $post)
    {
        if (!$post instanceof \WP_Post || $post->post_date === '0000-00-00 00:00:00') {
            return $t_time;
        }

        $timestamp = get_post_timestamp($post);

        if (!$timestamp) {
            return $t_time;
        }

        return self::timeTag($timestamp, wp_strip_all_tags((string) $t_time));
    }

    /**
     * Balise <time> avec la date relative et la date complète en infobulle.
     *
     * @param int    $timestamp horodatage Unix
     * @param string $full      libellé complet (sinon format de date + heure du site)
     */
    public static function timeTag($timestamp, $full = '')
    {
        if ($full === '') {
            $full = wp_date(get_option('date_format') . ' ' . get_option('time_format'), $timestamp);
        }

        return '<time class="smbb-reldate" datetime="' . esc_attr(wp_date('c', $timestamp)) . '" title="' . esc_attr($full) . '">' . esc_html(self::relative($timestamp)) . '</time>';
    }

    /** Date relative courte, dans le fuseau du site. */
    public static function relative($timestamp)
    {
        $tz = wp_timezone();
        $day = (new \DateTimeImmutable('@' . $timestamp))->setTimezone($tz)->setTime(0, 0);
        $today = (new \DateTimeImmutable('now', $tz))->setTime(0, 0);
        $diff = (int) round(($day->getTimestamp() - $today->getTimestamp()) / DAY_IN_SECONDS);
        $time = wp_date(get_option('time_format'), $timestamp);

        if ($diff === 0) {
            /* translators: %s: heure */
            return sprintf(__('Aujourd\'hui, %s', 'smbb-wpcodetool'), $time);
        }

        if ($diff === -1) {
            /* translators: %s: heure */
            return sprintf(__('Hier, %s', 'smbb-wpcodetool'), $time);
        }

        if ($diff === 1) {
            /* translators: %s: heure */
            return sprintf(__('Demain, %s', 'smbb-wpcodetool'), $time);
        }

        if ($diff < 0 && $diff >= -6) {
            /* translators: %d: nombre de jours */
            return sprintf(_n('Il y a %d jour', 'Il y a %d jours', -$diff, 'smbb-wpcodetool'), -$diff);
        }

        if ($diff > 0 && $diff <= 6) {
            return ucfirst(wp_date('l', $timestamp)) . ', ' . $time;
        }

        if (wp_date('Y', $timestamp) === wp_date('Y')) {
            return wp_date('j M', $timestamp);
        }

        return wp_date('j M Y', $timestamp);
    }

    public static function assets()
    {
        $css = 'wp-lists.css';
        $js = 'wp-lists.js';
        wp_enqueue_style('smbb-wpcodetool-lists', SMBB_WPCODETOOL_URL . 'assets/' . $css, array(), self::version($css));
        wp_enqueue_script('smbb-wpcodetool-lists', SMBB_WPCODETOOL_URL . 'assets/' . $js, array(), self::version($js), true);

        $now = new \DateTimeImmutable('now', wp_timezone());

        wp_localize_script('smbb-wpcodetool-lists', 'smbbLists', array(
            'today' => $now->format('Y-m-d'),
            'locale' => str_replace('_', '-', get_user_locale()),
            'i18n' => array(
                'selected' => __('%d sélectionné', 'smbb-wpcodetool'),
                'selectedPlural' => __('%d sélectionnés', 'smbb-wpcodetool'),
                'clear' => __('Tout désélectionner', 'smbb-wpcodetool'),
                'today' => __('Aujourd\'hui', 'smbb-wpcodetool'),
                'yesterday' => __('Hier', 'smbb-wpcodetool'),
                'tomorrow' => __('Demain', 'smbb-wpcodetool'),
                'ago' => __('Il y a %d jours', 'smbb-wpcodetool'),
            ),
        ));
    }

    private static function version($file)
    {
        $path = SMBB_WPCODETOOL_PATH . 'assets/' . $file;

        return is_file($path) ? (string) filemtime($path) : null;
    }
}
