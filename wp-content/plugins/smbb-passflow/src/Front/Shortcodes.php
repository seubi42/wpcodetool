<?php

namespace Smbb\Passflow\Front;

use Smbb\Passflow\Repository\Slots;
use Smbb\Passflow\Repository\Lookup;
use Smbb\Passflow\Service\Capacity;
use Smbb\Passflow\WooCommerce\Front as Shop;
use Smbb\Passflow\Support\Settings;

defined('ABSPATH') || exit;

/**
 * Shortcodes du site :
 * - [passflow_activities columns="3"]                 catalogue des ateliers actifs ;
 * - [passflow_activity id="2"]                       fiche d'un atelier et ses dates ;
 * - [passflow_slots atelier="2" limit="10"]      prochaines dates (d'un atelier ou de tous) ;
 * - [passflow_calendar atelier="2"]               calendrier mensuel des dates en vente.
 *
 * La réservation se fait sur la fiche produit WooCommerce de l'atelier : les boutons « Réserver »
 * y mènent avec la date déjà choisie. Sans produit publié, les dates s'affichent sans bouton.
 */
final class Shortcodes
{
    public static function hooks()
    {
        add_shortcode('passflow_activities', array(__CLASS__, 'ateliers'));
        add_shortcode('passflow_activity', array(__CLASS__, 'atelier'));
        add_shortcode('passflow_slots', array(__CLASS__, 'creneaux'));
        add_shortcode('passflow_calendar', array(__CLASS__, 'calendrier'));
        add_action('wp_enqueue_scripts', array(__CLASS__, 'register'));
    }

    public static function register()
    {
        wp_register_style('smbb-passflow-front', SMBB_PASSFLOW_URL . 'assets/front.css', array(), SMBB_PASSFLOW_VERSION);
    }

    // ------------------------------------------------------------------
    // Shortcodes
    // ------------------------------------------------------------------

    public static function ateliers($atts)
    {
        $atts = shortcode_atts(array('columns' => 3, 'limit' => 0), $atts, 'passflow_activities');
        $activities = self::activeActivities();

        if ((int) $atts['limit'] > 0) {
            $activities = array_slice($activities, 0, (int) $atts['limit']);
        }

        self::style();

        if (!$activities) {
            return '<p class="passflow-empty">' . esc_html__('Aucun atelier pour le moment.', 'smbb-passflow') . '</p>';
        }

        $html = '<div class="passflow-grid" style="--passflow-columns:' . esc_attr((string) max(1, min(4, (int) $atts['columns']))) . '">';

        foreach ($activities as $activity) {
            $html .= self::card($activity);
        }

        return $html . '</div>';
    }

    public static function atelier($atts)
    {
        $atts = shortcode_atts(array('id' => 0, 'limit' => 10), $atts, 'passflow_activity');
        $activity = self::findActivity($atts['id']);

        self::style();

        if (!$activity) {
            return '<p class="passflow-empty">' . esc_html__('Atelier introuvable.', 'smbb-passflow') . '</p>';
        }

        $d = 'smbb-passflow';
        $image_id = self::imageId($activity);
        $image = $image_id ? wp_get_attachment_image($image_id, 'large', false, array('class' => 'passflow-activity-image')) : '';
        $description = self::description($activity, false);
        $facts = self::facts($activity);

        $html = '<article class="passflow-activity">'
            . ($image ? '<div class="passflow-activity-media">' . $image . '</div>' : '')
            . '<div class="passflow-activity-body">'
            . '<h2 class="passflow-activity-title">' . esc_html($activity['name']) . '</h2>'
            . ($facts ? '<p class="passflow-facts">' . $facts . '</p>' : '')
            . ($description !== '' ? '<div class="passflow-activity-description">' . wp_kses_post(wpautop($description)) . '</div>' : '')
            . (!empty($activity['address']) ? '<p class="passflow-address">' . nl2br(esc_html($activity['address'])) . '</p>' : '')
            . '<h3 class="passflow-dates-title">' . esc_html__('Prochaines dates', $d) . '</h3>'
            . self::dateList($activity, (int) $atts['limit'])
            . '</div></article>';

        return $html;
    }

    public static function creneaux($atts)
    {
        $atts = shortcode_atts(array('atelier' => 0, 'limit' => 10), $atts, 'passflow_slots');
        self::style();

        if ($atts['atelier']) {
            $activity = self::findActivity($atts['atelier']);

            return $activity
                ? self::dateList($activity, (int) $atts['limit'])
                : '<p class="passflow-empty">' . esc_html__('Atelier introuvable.', 'smbb-passflow') . '</p>';
        }

        return self::dateList(null, (int) $atts['limit']);
    }

    /**
     * Calendrier du mois (?passflow_month=2026-12), filtrable par atelier (?passflow_activity=2).
     */
    public static function calendrier($atts)
    {
        $atts = shortcode_atts(array('atelier' => 0), $atts, 'passflow_calendar');
        $d = 'smbb-passflow';
        self::style();

        $month = isset($_GET['passflow_month']) && preg_match('/^\d{4}-\d{2}$/', sanitize_text_field(wp_unslash($_GET['passflow_month'])))
            ? sanitize_text_field(wp_unslash($_GET['passflow_month']))
            : current_time('Y-m');
        $fixed = $atts['atelier'] ? self::findActivity($atts['atelier']) : null;
        $activity_id = $fixed ? (int) $fixed['id'] : (isset($_GET['passflow_activity']) ? absint($_GET['passflow_activity']) : 0);
        $first = strtotime($month . '-01 12:00:00');
        $last = (int) gmdate('t', $first);
        $by_day = array();

        foreach (self::saleable(Slots::between($month . '-01', $month . '-' . $last, array('activity_id' => $activity_id, 'statuses' => array('open', 'full')))) as $slot) {
            $by_day[(int) substr($slot['start_date'], 8, 2)][] = $slot;
        }

        $prev = gmdate('Y-m', strtotime('-1 month', $first));
        $next = gmdate('Y-m', strtotime('+1 month', $first));
        $can_go_back = $month > current_time('Y-m');

        $html = '<div class="passflow-calendar">';
        $html .= '<div class="passflow-calendar-head">';
        $html .= $can_go_back ? '<a class="passflow-calendar-nav" href="' . esc_url(add_query_arg('passflow_month', $prev)) . '" aria-label="' . esc_attr__('Mois précédent', $d) . '">‹</a>' : '<span class="passflow-calendar-nav is-off">‹</span>';
        $html .= '<strong class="passflow-calendar-month">' . esc_html(ucfirst(date_i18n('F Y', $first))) . '</strong>';
        $html .= '<a class="passflow-calendar-nav" href="' . esc_url(add_query_arg('passflow_month', $next)) . '" aria-label="' . esc_attr__('Mois suivant', $d) . '">›</a>';
        $html .= '</div>';

        if (!$fixed) {
            $html .= '<form class="passflow-calendar-filter" method="get">';

            foreach ($_GET as $key => $value) {
                if (!in_array($key, array('passflow_activity'), true) && is_scalar($value)) {
                    $html .= '<input type="hidden" name="' . esc_attr(sanitize_key($key)) . '" value="' . esc_attr(sanitize_text_field(wp_unslash($value))) . '">';
                }
            }

            $html .= '<label>' . esc_html__('Atelier', $d) . ' <select name="passflow_activity" onchange="this.form.submit()">'
                . '<option value="0">' . esc_html__('Tous les ateliers', $d) . '</option>';

            foreach (self::activeActivities() as $activity) {
                $html .= '<option value="' . esc_attr((string) $activity['id']) . '"' . selected($activity_id, (int) $activity['id'], false) . '>' . esc_html($activity['name']) . '</option>';
            }

            $html .= '</select></label><noscript><button type="submit">' . esc_html__('Filtrer', $d) . '</button></noscript></form>';
        }

        // Grille du lundi au dimanche.
        $html .= '<div class="passflow-calendar-grid" role="list">';

        foreach (array(__('lun.', $d), __('mar.', $d), __('mer.', $d), __('jeu.', $d), __('ven.', $d), __('sam.', $d), __('dim.', $d)) as $label) {
            $html .= '<div class="passflow-calendar-dow" aria-hidden="true">' . esc_html($label) . '</div>';
        }

        $offset = (int) gmdate('N', $first) - 1;

        for ($i = 0; $i < $offset; $i++) {
            $html .= '<div class="passflow-calendar-day is-blank" aria-hidden="true"></div>';
        }

        for ($day = 1; $day <= $last; $day++) {
            $events = isset($by_day[$day]) ? $by_day[$day] : array();
            $html .= '<div class="passflow-calendar-day' . ($events ? ' has-events' : '') . '" role="listitem">'
                . '<span class="passflow-calendar-num"><span class="passflow-calendar-dow-inline">' . esc_html(date_i18n('l', strtotime($month . '-' . sprintf('%02d', $day) . ' 12:00:00'))) . ' </span>' . esc_html((string) $day) . '</span>';

            foreach ($events as $slot) {
                $html .= self::calendarEvent($slot);
            }

            $html .= '</div>';
        }

        $html .= '</div>';

        if (!$by_day) {
            $html .= '<p class="passflow-empty">' . esc_html__('Pas de date en vente ce mois-ci.', $d) . '</p>';
        }

        return $html . '</div>';
    }

    // ------------------------------------------------------------------
    // Morceaux
    // ------------------------------------------------------------------

    private static function card(array $activity)
    {
        $d = 'smbb-passflow';
        $url = self::productUrl($activity);
        $dates = self::upcoming($activity, 1);
        $next = $dates ? $dates[0] : null;
        $image_id = self::imageId($activity);
        $image = $image_id ? wp_get_attachment_image($image_id, 'medium_large', false, array('class' => 'passflow-card-image')) : '';
        $excerpt = wp_trim_words(wp_strip_all_tags(strip_shortcodes(self::description($activity, true))), 24);
        $facts = self::facts($activity);
        $title = esc_html($activity['name']);

        $html = '<article class="passflow-card">';

        if ($image) {
            $html .= '<div class="passflow-card-media">' . ($url ? '<a href="' . esc_url($url) . '" tabindex="-1">' . $image . '</a>' : $image) . '</div>';
        }

        $html .= '<div class="passflow-card-body">'
            . '<h3 class="passflow-card-title">' . ($url ? '<a href="' . esc_url($url) . '">' . $title . '</a>' : $title) . '</h3>'
            . ($facts ? '<p class="passflow-facts">' . $facts . '</p>' : '')
            . ($excerpt !== '' ? '<p class="passflow-card-excerpt">' . esc_html($excerpt) . '</p>' : '')
            . '<p class="passflow-card-next">' . ($next
                ? esc_html__('Prochaine date :', $d) . ' ' . self::dayTag($next) . esc_html(Shop::when($next))
                : esc_html__('Nouvelles dates bientôt', $d)) . '</p>';

        if ($url) {
            $html .= '<p class="passflow-card-actions"><a class="button passflow-button" href="' . esc_url($url) . '">' . esc_html__('Voir les dates', $d) . '</a></p>';
        }

        return $html . '</div></article>';
    }

    /**
     * Étiquette de proximité devant une date : « Aujourd'hui », « Demain », « Après-demain », sinon « J+34 ».
     * Classes : passflow-day-tag--today, --tomorrow, --soon (J+2), --later, pour la mettre en valeur dans le thème.
     */
    public static function dayTag(array $slot)
    {
        $d = 'smbb-passflow';
        $start = !empty($slot['start_date']) ? (string) $slot['start_date'] : '';

        if ($start === '') {
            return '';
        }

        $days = (int) round((strtotime($start . ' 12:00:00') - strtotime(current_time('Y-m-d') . ' 12:00:00')) / DAY_IN_SECONDS);

        if ($days < 0) {
            return '';
        }

        $labels = array(0 => array(__("Aujourd'hui", $d), 'today'), 1 => array(__('Demain', $d), 'tomorrow'), 2 => array(__('Après-demain', $d), 'soon'));
        list($label, $kind) = isset($labels[$days]) ? $labels[$days] : array(sprintf(__('J+%d', $d), $days), 'later');

        return '<span class="passflow-day-tag passflow-day-tag--' . esc_attr($kind) . '">' . esc_html($label) . '</span> ';
    }

    /**
     * Texte et image : ceux du produit WooCommerce lié s'il existe (une seule source), sinon ceux de l'atelier.
     */
    private static function description(array $activity, $short)
    {
        if (\Smbb\Passflow\WooCommerce\Module::active()) {
            return \Smbb\Passflow\WooCommerce\Products::description($activity, $short);
        }

        return (string) $activity['description'];
    }

    private static function imageId(array $activity)
    {
        if (\Smbb\Passflow\WooCommerce\Module::active()) {
            return \Smbb\Passflow\WooCommerce\Products::imageId($activity);
        }

        return !empty($activity['image_id']) ? (int) $activity['image_id'] : 0;
    }

    /**
     * Liste des prochaines dates, avec places et bouton « Réserver ».
     */
    private static function dateList($activity, $limit)
    {
        $d = 'smbb-passflow';
        $rows = self::upcoming($activity, max(1, $limit));

        if (!$rows) {
            return '<p class="passflow-empty">' . esc_html__('Pas de date en vente pour le moment. Revenez bientôt !', $d) . '</p>';
        }

        $html = '<ul class="passflow-date-list">';

        foreach ($rows as $slot) {
            $row_activity = $activity ?: Lookup::atelier($slot['activity_id']);
            $url = $row_activity ? self::productUrl($row_activity) : '';
            $free = (int) $slot['for_sale'];
            $soon = !empty($slot['presale']);

            $html .= '<li class="passflow-date-item' . ($free > 0 ? '' : ($soon ? ' is-full is-soon' : ' is-full')) . '">'
                . '<span class="passflow-date-info">'
                . ($activity ? '' : '<span class="passflow-date-activity">' . esc_html($row_activity ? $row_activity['name'] : '') . '</span>')
                . '<span class="passflow-date-when">' . self::dayTag($slot) . esc_html(Shop::when($slot)) . '</span>'
                . '<span class="passflow-date-meta">' . ($soon ? esc_html(\Smbb\Passflow\Service\SaleWindow::shortLabel($slot)) : self::seats($free)) . ' · ' . self::money(\Smbb\Passflow\Support\Prices::display(Shop::price($slot, $row_activity))) . '</span>'
                . '</span>';

            if ($url) {
                $html .= $free > 0
                    ? '<a class="button passflow-button" href="' . esc_url(add_query_arg('passflow_slot', (int) $slot['id'], $url)) . '">' . esc_html__('Réserver', $d) . '</a>'
                    : ((!$soon && Settings::waitlistEnabled() && !\Smbb\Passflow\Service\Passes::isPass($slot))
                        ? '<a class="passflow-waitlist-link" href="' . esc_url($url) . '#passflow-waitlist">' . esc_html__("Liste d'attente", $d) . '</a>'
                        : '<span class="passflow-full-label">' . esc_html($soon ? __('Bientôt', $d) : __('Complet', $d)) . '</span>');
            }

            $html .= '</li>';
        }

        return $html . '</ul>';
    }

    private static function calendarEvent(array $slot)
    {
        $activity = Lookup::atelier($slot['activity_id']);
        $url = $activity ? self::productUrl($activity) : '';
        $free = (int) $slot['for_sale'];
        $soon = !empty($slot['presale']);
        $hours = Lookup::slotHours($slot);
        $color = $activity && !empty($activity['color']) ? $activity['color'] : '';
        $label = '<span class="passflow-event-time">' . esc_html($hours) . '</span> '
            . '<span class="passflow-event-name">' . esc_html($activity ? $activity['name'] : '') . '</span>'
            . '<span class="passflow-event-seats">' . ($soon ? esc_html(\Smbb\Passflow\Service\SaleWindow::shortLabel($slot)) : self::seats($free)) . '</span>';
        $style = $color ? ' style="--passflow-color:' . esc_attr($color) . '"' : '';
        $class = 'passflow-event' . ($free > 0 ? '' : ($soon ? ' is-full is-soon' : ' is-full'));
        $waitlist = !$soon && Settings::waitlistEnabled();

        if ($url && ($free > 0 || $waitlist)) {
            $href = $free > 0 ? add_query_arg('passflow_slot', (int) $slot['id'], $url) : $url . '#passflow-waitlist';

            return '<a class="' . $class . '" href="' . esc_url($href) . '"' . $style . '>' . $label . '</a>';
        }

        return '<span class="' . $class . '"' . $style . '>' . $label . '</span>';
    }

    /**
     * Durée, lieu et prix « à partir de ».
     */
    private static function facts(array $activity)
    {
        $d = 'smbb-passflow';
        $facts = array();

        if (!empty($activity['duration_minutes'])) {
            $minutes = (int) $activity['duration_minutes'];
            $facts[] = $minutes >= 60
                ? sprintf(__('%1$dh%2$s', $d), intdiv($minutes, 60), $minutes % 60 ? sprintf('%02d', $minutes % 60) : '')
                : sprintf(__('%d min', $d), $minutes);
        }

        if (!empty($activity['location'])) {
            $facts[] = $activity['location'];
        }

        $prices = array_map(function ($slot) use ($activity) {
            return \Smbb\Passflow\Support\Prices::display(Shop::price($slot, $activity));
        }, self::upcoming($activity, 50));

        if ($prices) {
            $min = min($prices);
            $facts[] = count(array_unique($prices)) > 1
                ? sprintf(__('à partir de %s', $d), self::moneyText($min))
                : self::moneyText($min);
        } elseif ($activity['default_price'] !== null && $activity['default_price'] !== '') {
            $facts[] = self::moneyText((float) $activity['default_price']);
        }

        return implode(' · ', array_map('esc_html', $facts));
    }

    private static function seats($free)
    {
        $d = 'smbb-passflow';

        if ($free < 1) {
            return '<span class="passflow-seats-full">' . esc_html__('Complet', $d) . '</span>';
        }

        return '<span class="passflow-seats-left' . ($free <= 2 ? ' is-low' : '') . '">'
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
    private static function activeActivities()
    {
        return array_values(array_filter(Lookup::ateliers(), function ($activity) {
            return $activity['status'] === 'active';
        }));
    }

    /**
     * Atelier par id ou par nom exact.
     */
    private static function findActivity($ref)
    {
        $ref = trim((string) $ref);

        foreach (self::activeActivities() as $activity) {
            if ((ctype_digit($ref) && (int) $activity['id'] === (int) $ref) || strcasecmp($activity['name'], $ref) === 0) {
                return $activity;
            }
        }

        return null;
    }

    /**
     * Prochaines dates en vente (ou complètes) d'un atelier, ou de tous les ateliers actifs.
     *
     * @return array<int,array<string,mixed>>
     */
    private static function upcoming($activity, $limit)
    {
        static $cache = array();

        $key = ($activity ? (int) $activity['id'] : 0) . ':' . (int) $limit;

        if (!isset($cache[$key])) {
            $rows = Slots::between(current_time('Y-m-d'), null, array(
                'activity_id' => $activity ? (int) $activity['id'] : 0,
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

        foreach ($rows as $slot) {
            $activity = Lookup::atelier($slot['activity_id']);

            if (!$activity || $activity['status'] !== 'active' || Capacity::startsAt($slot) <= $now) {
                continue;
            }

            $slot['presale'] = $slot['status'] === 'open' && \Smbb\Passflow\Service\SaleWindow::pending($slot);
            $slot['for_sale'] = Capacity::forSale($slot, $email);
            $result[] = $slot;
        }

        return $result;
    }

    /**
     * Fiche produit publiée de l'atelier, ou chaîne vide (pas de réservation en ligne).
     */
    private static function productUrl(array $activity)
    {
        if (empty($activity['product_id']) || !function_exists('wc_get_product')) {
            return '';
        }

        $post = get_post((int) $activity['product_id']);

        return $post && $post->post_type === 'product' && $post->post_status === 'publish' ? (string) get_permalink($post) : '';
    }

    private static function style()
    {
        if (!wp_style_is('smbb-passflow-front', 'registered')) {
            self::register();
        }

        wp_enqueue_style('smbb-passflow-front');
    }
}
