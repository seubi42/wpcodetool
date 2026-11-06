<?php

namespace Smbb\Tomo\Front;

use Smbb\Tomo\Repository\Creneaux;
use Smbb\Tomo\Repository\Lookup;
use Smbb\Tomo\Service\Capacity;
use Smbb\Tomo\WooCommerce\Front as Shop;
use Smbb\Tomo\Support\Settings;

defined('ABSPATH') || exit;

/**
 * Shortcodes du site :
 * - [tomo_ateliers columns="3"]                 catalogue des ateliers actifs ;
 * - [tomo_atelier id="2"]                       fiche d'un atelier et ses dates ;
 * - [tomo_creneaux atelier="2" limit="10"]      prochaines dates (d'un atelier ou de tous) ;
 * - [tomo_calendrier atelier="2"]               calendrier mensuel des dates en vente.
 *
 * La réservation se fait sur la fiche produit WooCommerce de l'atelier : les boutons « Réserver »
 * y mènent avec la date déjà choisie. Sans produit publié, les dates s'affichent sans bouton.
 */
final class Shortcodes
{
    public static function hooks()
    {
        add_shortcode('tomo_ateliers', array(__CLASS__, 'ateliers'));
        add_shortcode('tomo_atelier', array(__CLASS__, 'atelier'));
        add_shortcode('tomo_creneaux', array(__CLASS__, 'creneaux'));
        add_shortcode('tomo_calendrier', array(__CLASS__, 'calendrier'));
        add_action('wp_enqueue_scripts', array(__CLASS__, 'register'));
    }

    public static function register()
    {
        wp_register_style('smbb-tomo-front', SMBB_TOMO_URL . 'assets/front.css', array(), SMBB_TOMO_VERSION);
    }

    // ------------------------------------------------------------------
    // Shortcodes
    // ------------------------------------------------------------------

    public static function ateliers($atts)
    {
        $atts = shortcode_atts(array('columns' => 3, 'limit' => 0), $atts, 'tomo_ateliers');
        $ateliers = self::activeAteliers();

        if ((int) $atts['limit'] > 0) {
            $ateliers = array_slice($ateliers, 0, (int) $atts['limit']);
        }

        self::style();

        if (!$ateliers) {
            return '<p class="tomo-empty">' . esc_html__('Aucun atelier pour le moment.', 'smbb-tomo') . '</p>';
        }

        $html = '<div class="tomo-grid" style="--tomo-columns:' . esc_attr((string) max(1, min(4, (int) $atts['columns']))) . '">';

        foreach ($ateliers as $atelier) {
            $html .= self::card($atelier);
        }

        return $html . '</div>';
    }

    public static function atelier($atts)
    {
        $atts = shortcode_atts(array('id' => 0, 'limit' => 10), $atts, 'tomo_atelier');
        $atelier = self::findAtelier($atts['id']);

        self::style();

        if (!$atelier) {
            return '<p class="tomo-empty">' . esc_html__('Atelier introuvable.', 'smbb-tomo') . '</p>';
        }

        $d = 'smbb-tomo';
        $image_id = self::imageId($atelier);
        $image = $image_id ? wp_get_attachment_image($image_id, 'large', false, array('class' => 'tomo-atelier-image')) : '';
        $description = self::description($atelier, false);
        $facts = self::facts($atelier);

        $html = '<article class="tomo-atelier">'
            . ($image ? '<div class="tomo-atelier-media">' . $image . '</div>' : '')
            . '<div class="tomo-atelier-body">'
            . '<h2 class="tomo-atelier-title">' . esc_html($atelier['name']) . '</h2>'
            . ($facts ? '<p class="tomo-facts">' . $facts . '</p>' : '')
            . ($description !== '' ? '<div class="tomo-atelier-description">' . wp_kses_post(wpautop($description)) . '</div>' : '')
            . (!empty($atelier['address']) ? '<p class="tomo-address">' . nl2br(esc_html($atelier['address'])) . '</p>' : '')
            . '<h3 class="tomo-dates-title">' . esc_html__('Prochaines dates', $d) . '</h3>'
            . self::dateList($atelier, (int) $atts['limit'])
            . '</div></article>';

        return $html;
    }

    public static function creneaux($atts)
    {
        $atts = shortcode_atts(array('atelier' => 0, 'limit' => 10), $atts, 'tomo_creneaux');
        self::style();

        if ($atts['atelier']) {
            $atelier = self::findAtelier($atts['atelier']);

            return $atelier
                ? self::dateList($atelier, (int) $atts['limit'])
                : '<p class="tomo-empty">' . esc_html__('Atelier introuvable.', 'smbb-tomo') . '</p>';
        }

        return self::dateList(null, (int) $atts['limit']);
    }

    /**
     * Calendrier du mois (?tomo_mois=2026-12), filtrable par atelier (?tomo_atelier=2).
     */
    public static function calendrier($atts)
    {
        $atts = shortcode_atts(array('atelier' => 0), $atts, 'tomo_calendrier');
        $d = 'smbb-tomo';
        self::style();

        $month = isset($_GET['tomo_mois']) && preg_match('/^\d{4}-\d{2}$/', sanitize_text_field(wp_unslash($_GET['tomo_mois'])))
            ? sanitize_text_field(wp_unslash($_GET['tomo_mois']))
            : current_time('Y-m');
        $fixed = $atts['atelier'] ? self::findAtelier($atts['atelier']) : null;
        $atelier_id = $fixed ? (int) $fixed['id'] : (isset($_GET['tomo_atelier']) ? absint($_GET['tomo_atelier']) : 0);
        $first = strtotime($month . '-01 12:00:00');
        $last = (int) gmdate('t', $first);
        $by_day = array();

        foreach (self::saleable(Creneaux::between($month . '-01', $month . '-' . $last, array('atelier_id' => $atelier_id, 'statuses' => array('open', 'full')))) as $creneau) {
            $by_day[(int) substr($creneau['start_date'], 8, 2)][] = $creneau;
        }

        $prev = gmdate('Y-m', strtotime('-1 month', $first));
        $next = gmdate('Y-m', strtotime('+1 month', $first));
        $can_go_back = $month > current_time('Y-m');

        $html = '<div class="tomo-calendar">';
        $html .= '<div class="tomo-calendar-head">';
        $html .= $can_go_back ? '<a class="tomo-calendar-nav" href="' . esc_url(add_query_arg('tomo_mois', $prev)) . '" aria-label="' . esc_attr__('Mois précédent', $d) . '">‹</a>' : '<span class="tomo-calendar-nav is-off">‹</span>';
        $html .= '<strong class="tomo-calendar-month">' . esc_html(ucfirst(date_i18n('F Y', $first))) . '</strong>';
        $html .= '<a class="tomo-calendar-nav" href="' . esc_url(add_query_arg('tomo_mois', $next)) . '" aria-label="' . esc_attr__('Mois suivant', $d) . '">›</a>';
        $html .= '</div>';

        if (!$fixed) {
            $html .= '<form class="tomo-calendar-filter" method="get">';

            foreach ($_GET as $key => $value) {
                if (!in_array($key, array('tomo_atelier'), true) && is_scalar($value)) {
                    $html .= '<input type="hidden" name="' . esc_attr(sanitize_key($key)) . '" value="' . esc_attr(sanitize_text_field(wp_unslash($value))) . '">';
                }
            }

            $html .= '<label>' . esc_html__('Atelier', $d) . ' <select name="tomo_atelier" onchange="this.form.submit()">'
                . '<option value="0">' . esc_html__('Tous les ateliers', $d) . '</option>';

            foreach (self::activeAteliers() as $atelier) {
                $html .= '<option value="' . esc_attr((string) $atelier['id']) . '"' . selected($atelier_id, (int) $atelier['id'], false) . '>' . esc_html($atelier['name']) . '</option>';
            }

            $html .= '</select></label><noscript><button type="submit">' . esc_html__('Filtrer', $d) . '</button></noscript></form>';
        }

        // Grille du lundi au dimanche.
        $html .= '<div class="tomo-calendar-grid" role="list">';

        foreach (array(__('lun.', $d), __('mar.', $d), __('mer.', $d), __('jeu.', $d), __('ven.', $d), __('sam.', $d), __('dim.', $d)) as $label) {
            $html .= '<div class="tomo-calendar-dow" aria-hidden="true">' . esc_html($label) . '</div>';
        }

        $offset = (int) gmdate('N', $first) - 1;

        for ($i = 0; $i < $offset; $i++) {
            $html .= '<div class="tomo-calendar-day is-blank" aria-hidden="true"></div>';
        }

        for ($day = 1; $day <= $last; $day++) {
            $events = isset($by_day[$day]) ? $by_day[$day] : array();
            $html .= '<div class="tomo-calendar-day' . ($events ? ' has-events' : '') . '" role="listitem">'
                . '<span class="tomo-calendar-num"><span class="tomo-calendar-dow-inline">' . esc_html(date_i18n('l', strtotime($month . '-' . sprintf('%02d', $day) . ' 12:00:00'))) . ' </span>' . esc_html((string) $day) . '</span>';

            foreach ($events as $creneau) {
                $html .= self::calendarEvent($creneau);
            }

            $html .= '</div>';
        }

        $html .= '</div>';

        if (!$by_day) {
            $html .= '<p class="tomo-empty">' . esc_html__('Pas de date en vente ce mois-ci.', $d) . '</p>';
        }

        return $html . '</div>';
    }

    // ------------------------------------------------------------------
    // Morceaux
    // ------------------------------------------------------------------

    private static function card(array $atelier)
    {
        $d = 'smbb-tomo';
        $url = self::productUrl($atelier);
        $dates = self::upcoming($atelier, 1);
        $next = $dates ? $dates[0] : null;
        $image_id = self::imageId($atelier);
        $image = $image_id ? wp_get_attachment_image($image_id, 'medium_large', false, array('class' => 'tomo-card-image')) : '';
        $excerpt = wp_trim_words(wp_strip_all_tags(strip_shortcodes(self::description($atelier, true))), 24);
        $facts = self::facts($atelier);
        $title = esc_html($atelier['name']);

        $html = '<article class="tomo-card">';

        if ($image) {
            $html .= '<div class="tomo-card-media">' . ($url ? '<a href="' . esc_url($url) . '" tabindex="-1">' . $image . '</a>' : $image) . '</div>';
        }

        $html .= '<div class="tomo-card-body">'
            . '<h3 class="tomo-card-title">' . ($url ? '<a href="' . esc_url($url) . '">' . $title . '</a>' : $title) . '</h3>'
            . ($facts ? '<p class="tomo-facts">' . $facts . '</p>' : '')
            . ($excerpt !== '' ? '<p class="tomo-card-excerpt">' . esc_html($excerpt) . '</p>' : '')
            . '<p class="tomo-card-next">' . ($next
                ? esc_html__('Prochaine date :', $d) . ' ' . self::dayTag($next) . esc_html(Shop::when($next))
                : esc_html__('Nouvelles dates bientôt', $d)) . '</p>';

        if ($url) {
            $html .= '<p class="tomo-card-actions"><a class="button tomo-button" href="' . esc_url($url) . '">' . esc_html__('Voir les dates', $d) . '</a></p>';
        }

        return $html . '</div></article>';
    }

    /**
     * Étiquette de proximité devant une date : « Aujourd'hui », « Demain », « Après-demain », sinon « J+34 ».
     * Classes : tomo-day-tag--today, --tomorrow, --soon (J+2), --later, pour la mettre en valeur dans le thème.
     */
    public static function dayTag(array $creneau)
    {
        $d = 'smbb-tomo';
        $start = !empty($creneau['start_date']) ? (string) $creneau['start_date'] : '';

        if ($start === '') {
            return '';
        }

        $days = (int) round((strtotime($start . ' 12:00:00') - strtotime(current_time('Y-m-d') . ' 12:00:00')) / DAY_IN_SECONDS);

        if ($days < 0) {
            return '';
        }

        $labels = array(0 => array(__("Aujourd'hui", $d), 'today'), 1 => array(__('Demain', $d), 'tomorrow'), 2 => array(__('Après-demain', $d), 'soon'));
        list($label, $kind) = isset($labels[$days]) ? $labels[$days] : array(sprintf(__('J+%d', $d), $days), 'later');

        return '<span class="tomo-day-tag tomo-day-tag--' . esc_attr($kind) . '">' . esc_html($label) . '</span> ';
    }

    /**
     * Texte et image : ceux du produit WooCommerce lié s'il existe (une seule source), sinon ceux de l'atelier.
     */
    private static function description(array $atelier, $short)
    {
        if (\Smbb\Tomo\WooCommerce\Module::active()) {
            return \Smbb\Tomo\WooCommerce\Products::description($atelier, $short);
        }

        return (string) $atelier['description'];
    }

    private static function imageId(array $atelier)
    {
        if (\Smbb\Tomo\WooCommerce\Module::active()) {
            return \Smbb\Tomo\WooCommerce\Products::imageId($atelier);
        }

        return !empty($atelier['image_id']) ? (int) $atelier['image_id'] : 0;
    }

    /**
     * Liste des prochaines dates, avec places et bouton « Réserver ».
     */
    private static function dateList($atelier, $limit)
    {
        $d = 'smbb-tomo';
        $rows = self::upcoming($atelier, max(1, $limit));

        if (!$rows) {
            return '<p class="tomo-empty">' . esc_html__('Pas de date en vente pour le moment. Revenez bientôt !', $d) . '</p>';
        }

        $html = '<ul class="tomo-date-list">';

        foreach ($rows as $creneau) {
            $row_atelier = $atelier ?: Lookup::atelier($creneau['atelier_id']);
            $url = $row_atelier ? self::productUrl($row_atelier) : '';
            $free = (int) $creneau['for_sale'];

            $html .= '<li class="tomo-date-item' . ($free > 0 ? '' : ' is-full') . '">'
                . '<span class="tomo-date-info">'
                . ($atelier ? '' : '<span class="tomo-date-atelier">' . esc_html($row_atelier ? $row_atelier['name'] : '') . '</span>')
                . '<span class="tomo-date-when">' . self::dayTag($creneau) . esc_html(Shop::when($creneau)) . '</span>'
                . '<span class="tomo-date-meta">' . self::seats($free) . ' · ' . self::money(\Smbb\Tomo\Support\Prices::display(Shop::price($creneau, $row_atelier))) . '</span>'
                . '</span>';

            if ($url) {
                $html .= $free > 0
                    ? '<a class="button tomo-button" href="' . esc_url(add_query_arg('tomo_creneau', (int) $creneau['id'], $url)) . '">' . esc_html__('Réserver', $d) . '</a>'
                    : (Settings::waitlistEnabled()
                        ? '<a class="tomo-waitlist-link" href="' . esc_url($url) . '#tomo-waitlist">' . esc_html__("Liste d'attente", $d) . '</a>'
                        : '<span class="tomo-full-label">' . esc_html__('Complet', $d) . '</span>');
            }

            $html .= '</li>';
        }

        return $html . '</ul>';
    }

    private static function calendarEvent(array $creneau)
    {
        $atelier = Lookup::atelier($creneau['atelier_id']);
        $url = $atelier ? self::productUrl($atelier) : '';
        $free = (int) $creneau['for_sale'];
        $hours = Lookup::slotHours($creneau);
        $color = $atelier && !empty($atelier['color']) ? $atelier['color'] : '';
        $label = '<span class="tomo-event-time">' . esc_html($hours) . '</span> '
            . '<span class="tomo-event-name">' . esc_html($atelier ? $atelier['name'] : '') . '</span>'
            . '<span class="tomo-event-seats">' . self::seats($free) . '</span>';
        $style = $color ? ' style="--tomo-color:' . esc_attr($color) . '"' : '';
        $class = 'tomo-event' . ($free > 0 ? '' : ' is-full');

        if ($url && ($free > 0 || Settings::waitlistEnabled())) {
            $href = $free > 0 ? add_query_arg('tomo_creneau', (int) $creneau['id'], $url) : $url . '#tomo-waitlist';

            return '<a class="' . $class . '" href="' . esc_url($href) . '"' . $style . '>' . $label . '</a>';
        }

        return '<span class="' . $class . '"' . $style . '>' . $label . '</span>';
    }

    /**
     * Durée, lieu et prix « à partir de ».
     */
    private static function facts(array $atelier)
    {
        $d = 'smbb-tomo';
        $facts = array();

        if (!empty($atelier['duration_minutes'])) {
            $minutes = (int) $atelier['duration_minutes'];
            $facts[] = $minutes >= 60
                ? sprintf(__('%1$dh%2$s', $d), intdiv($minutes, 60), $minutes % 60 ? sprintf('%02d', $minutes % 60) : '')
                : sprintf(__('%d min', $d), $minutes);
        }

        if (!empty($atelier['location'])) {
            $facts[] = $atelier['location'];
        }

        $prices = array_map(function ($creneau) use ($atelier) {
            return \Smbb\Tomo\Support\Prices::display(Shop::price($creneau, $atelier));
        }, self::upcoming($atelier, 50));

        if ($prices) {
            $min = min($prices);
            $facts[] = count(array_unique($prices)) > 1
                ? sprintf(__('à partir de %s', $d), self::moneyText($min))
                : self::moneyText($min);
        } elseif ($atelier['default_price'] !== null && $atelier['default_price'] !== '') {
            $facts[] = self::moneyText((float) $atelier['default_price']);
        }

        return implode(' · ', array_map('esc_html', $facts));
    }

    private static function seats($free)
    {
        $d = 'smbb-tomo';

        if ($free < 1) {
            return '<span class="tomo-seats-full">' . esc_html__('Complet', $d) . '</span>';
        }

        return '<span class="tomo-seats-left' . ($free <= 2 ? ' is-low' : '') . '">'
            . esc_html(sprintf(_n('%d place restante', '%d places restantes', $free, $d), $free)) . '</span>';
    }

    private static function money($amount)
    {
        return function_exists('wc_price') ? wp_kses_post(wc_price($amount)) : esc_html(self::moneyText($amount));
    }

    private static function moneyText($amount)
    {
        if (function_exists('wc_price')) {
            return html_entity_decode(wp_strip_all_tags(wc_price($amount)), ENT_QUOTES, 'UTF-8');
        }

        return number_format_i18n((float) $amount, (float) $amount == (int) $amount ? 0 : 2) . ' €';
    }

    // ------------------------------------------------------------------
    // Données
    // ------------------------------------------------------------------

    /**
     * @return array<int,array<string,mixed>>
     */
    private static function activeAteliers()
    {
        return array_values(array_filter(Lookup::ateliers(), function ($atelier) {
            return $atelier['status'] === 'active';
        }));
    }

    /**
     * Atelier par id ou par nom exact.
     */
    private static function findAtelier($ref)
    {
        $ref = trim((string) $ref);

        foreach (self::activeAteliers() as $atelier) {
            if ((ctype_digit($ref) && (int) $atelier['id'] === (int) $ref) || strcasecmp($atelier['name'], $ref) === 0) {
                return $atelier;
            }
        }

        return null;
    }

    /**
     * Prochaines dates en vente (ou complètes) d'un atelier, ou de tous les ateliers actifs.
     *
     * @return array<int,array<string,mixed>>
     */
    private static function upcoming($atelier, $limit)
    {
        static $cache = array();

        $key = ($atelier ? (int) $atelier['id'] : 0) . ':' . (int) $limit;

        if (!isset($cache[$key])) {
            $rows = Creneaux::between(current_time('Y-m-d'), null, array(
                'atelier_id' => $atelier ? (int) $atelier['id'] : 0,
                'statuses' => array('open', 'full'),
                'with_passes' => true,
            ));
            $cache[$key] = array_slice(self::saleable($rows), 0, max(1, (int) $limit));
        }

        return $cache[$key];
    }

    /**
     * Garde les dates futures d'ateliers actifs et calcule les places vendables en ligne.
     */
    private static function saleable(array $rows)
    {
        $now = current_time('timestamp');
        $user = wp_get_current_user();
        $email = $user && $user->exists() ? strtolower((string) $user->user_email) : '';
        $result = array();

        foreach ($rows as $creneau) {
            $atelier = Lookup::atelier($creneau['atelier_id']);

            if (!$atelier || $atelier['status'] !== 'active' || Capacity::startsAt($creneau) <= $now) {
                continue;
            }

            $creneau['for_sale'] = Capacity::forSale($creneau, $email);
            $result[] = $creneau;
        }

        return $result;
    }

    /**
     * Fiche produit publiée de l'atelier, ou chaîne vide (pas de réservation en ligne).
     */
    private static function productUrl(array $atelier)
    {
        if (empty($atelier['product_id']) || !function_exists('wc_get_product')) {
            return '';
        }

        $post = get_post((int) $atelier['product_id']);

        return $post && $post->post_type === 'product' && $post->post_status === 'publish' ? (string) get_permalink($post) : '';
    }

    private static function style()
    {
        if (!wp_style_is('smbb-tomo-front', 'registered')) {
            self::register();
        }

        wp_enqueue_style('smbb-tomo-front');
    }
}
