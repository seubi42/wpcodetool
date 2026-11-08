<?php

namespace Smbb\Passflow\Admin;

use Smbb\Passflow\Repository\Slots;
use Smbb\Passflow\Repository\Bookings;
use Smbb\Passflow\Repository\Lookup;
use Smbb\Passflow\Repository\Stats;
use Smbb\Passflow\Support\Roles;
use Smbb\Passflow\Support\Schema;
use Smbb\Passflow\Support\Settings;

defined('ABSPATH') || exit;

/**
 * Widgets Passflow pour l'accueil de WordPress (Tableau de bord › Accueil) :
 * - Prochains ateliers : les 6 prochaines dates en vente, remplissage et bouton d'appel le jour même ;
 * - Remplissage : les 10 prochains créneaux à venir (quelle que soit leur date), en anneau et en barres ;
 * - Ventes : encaissé du mois, tendance, courbe sur 8 semaines, places à venir et bons cadeaux ;
 * - Demandes de dispo : réponses attendues des intervenants et dernières demandes envoyées.
 *
 * Réservés aux gestionnaires (manage_passflow). Chacun peut les masquer ou les déplacer comme
 * les widgets de WordPress (Options de l'écran). Les couleurs suivent le thème CodeTool
 * (variables --smbb-t-*, dégradé --smbb-t-grad pour les palettes qui en ont un), même quand
 * la peau n'est pas appliquée au reste de l'admin ; en apparence classique, l'accent Passflow.
 */
final class DashboardWidgets
{
    const D = 'smbb-passflow';

    /** @var array<string,mixed>|null données communes, calculées une fois par affichage */
    private static $data = null;

    /** @var bool vrai quand les widgets sont rendus dans la page Passflow › Tableau de bord */
    private static $embedded = false;

    public static function hooks()
    {
        add_action('wp_dashboard_setup', array(__CLASS__, 'register'));
        add_action('admin_enqueue_scripts', array(__CLASS__, 'assets'));
    }

    private static function allowed()
    {
        return Roles::canManage() && Schema::ready();
    }

    public static function register()
    {
        if (!self::allowed()) {
            return;
        }

        $d = self::D;
        $widgets = array(
            'smbb_passflow_w_next' => array(__('Prochains ateliers', $d), 'next'),
            'smbb_passflow_w_fill' => array(__('Remplissage', $d), 'fill'),
            'smbb_passflow_w_sales' => array(__('Ventes', $d), 'sales'),
            'smbb_passflow_w_availability' => array(__('Demandes de dispo', $d), 'availability'),
        );

        /**
         * Retirer ou réordonner les widgets : add_filter('smbb_passflow_dashboard_widgets', fn ($w) => ...).
         */
        $widgets = apply_filters('smbb_passflow_dashboard_widgets', $widgets);

        foreach ($widgets as $id => $widget) {
            if (!is_array($widget) || !method_exists(__CLASS__, 'render' . ucfirst($widget[1]))) {
                continue;
            }

            wp_add_dashboard_widget(
                $id,
                esc_html__('Passflow', $d) . ' · ' . esc_html($widget[0]),
                array(__CLASS__, 'render' . ucfirst($widget[1]))
            );
        }
    }

    public static function assets($hook_suffix)
    {
        if ($hook_suffix !== 'index.php' || !self::allowed()) {
            return;
        }

        wp_enqueue_style('smbb-passflow-wp-dashboard', SMBB_PASSFLOW_URL . 'assets/wp-dashboard.css', array(), SMBB_PASSFLOW_VERSION . '.' . (int) @filemtime(SMBB_PASSFLOW_PATH . 'assets/wp-dashboard.css'));
        wp_add_inline_style('smbb-passflow-wp-dashboard', self::themeCss());
    }

    // -----------------------------------------------------------------------
    // Page Passflow › Tableau de bord : les mêmes widgets, posés dans des cartes CodeTool
    // -----------------------------------------------------------------------

    /**
     * Widgets repris dans le tableau de bord Passflow, dans l'ordre d'affichage (grille 2 colonnes).
     * Filtre `smbb_passflow_page_widgets` pour en retirer ou en réordonner.
     *
     * @return array<int,array{key:string,title:string,icon:string}>
     */
    public static function pageWidgets()
    {
        $d = self::D;
        $widgets = array(
            array('key' => 'next', 'title' => __('Prochains ateliers', $d), 'icon' => 'dashicons-calendar-alt'),
            array('key' => 'sales', 'title' => __('Ventes', $d), 'icon' => 'dashicons-chart-area'),
            array('key' => 'fill', 'title' => __('Remplissage', $d), 'icon' => 'dashicons-chart-bar'),
            array('key' => 'availability', 'title' => __('Demandes de dispo', $d), 'icon' => 'dashicons-megaphone'),
        );

        return (array) apply_filters('smbb_passflow_page_widgets', $widgets);
    }

    /**
     * Styles des widgets (même fichier et mêmes variables de thème que sur l'accueil WordPress).
     */
    public static function pageAssets()
    {
        wp_enqueue_style('smbb-passflow-wp-dashboard', SMBB_PASSFLOW_URL . 'assets/wp-dashboard.css', array(), SMBB_PASSFLOW_VERSION . '.' . (int) @filemtime(SMBB_PASSFLOW_PATH . 'assets/wp-dashboard.css'));
        wp_add_inline_style('smbb-passflow-wp-dashboard', self::themeCss());
    }

    /**
     * HTML d'un widget, pour la page Passflow (chaîne vide si le widget n'existe pas ou n'est pas permis).
     */
    public static function capture($key)
    {
        $method = 'render' . ucfirst((string) $key);

        if (!self::allowed() || !method_exists(__CLASS__, $method)) {
            return '';
        }

        self::$embedded = true;
        ob_start();
        call_user_func(array(__CLASS__, $method));
        self::$embedded = false;

        return (string) ob_get_clean();
    }

    /**
     * Variables du thème posées sur les widgets : d'abord l'accent Passflow (apparence classique),
     * puis, si la peau CodeTool est active pour cet utilisateur, toutes ses variables par-dessus.
     */
    private static function themeCss()
    {
        $css = '.smbb-tw{--smbb-t-accent:' . \Smbb\Passflow\Support\Theme::accent() . ';}';
        $skin = '\\Smbb\\WpCodeTool\\Admin\\Skin';

        if (class_exists($skin) && method_exists($skin, 'active') && $skin::active() && method_exists($skin, 'css')) {
            // Mêmes règles que la peau (clair, puis sombre sous html.smbb-dark), reciblées sur les widgets.
            $css .= strtr((string) $skin::css(), array(
                'html.smbb-dark body.smbb-skin,html.smbb-dark body.smbb-login' => 'html.smbb-dark .smbb-tw',
                'body.smbb-skin,body.smbb-login' => '.smbb-tw',
            ));
        }

        return $css;
    }

    // -----------------------------------------------------------------------
    // Données
    // -----------------------------------------------------------------------

    /** Nombre de barres du widget Remplissage : les N prochains créneaux, complétés de barres hachurées. */
    const FILL_COUNT = 10;

    /**
     * @return array<string,mixed>
     */
    private static function data()
    {
        if (self::$data !== null) {
            return self::$data;
        }

        $today = current_time('Y-m-d');
        $upcoming = Slots::upcoming(0, array('statuses' => array('open', 'full')));
        $fill_slots = array_slice($upcoming, 0, self::FILL_COUNT);
        $capacity = 0;
        $booked = 0;
        $waiting = 0;

        foreach ($fill_slots as $slot) {
            $booked += (int) $slot['booked'];
            $capacity += (int) $slot['booked'] + max(0, (int) $slot['remaining']);
            $waiting += (int) $slot['waiting'];
        }

        self::$data = array(
            'today' => $today,
            'upcoming' => $upcoming,
            'fill_slots' => $fill_slots,
            'capacity' => $capacity,
            'booked' => $booked,
            'waiting' => $waiting,
            'fill' => $capacity > 0 ? (int) round(100 * $booked / $capacity) : null,
        );

        return self::$data;
    }

    // -----------------------------------------------------------------------
    // Prochains ateliers
    // -----------------------------------------------------------------------

    public static function renderNext()
    {
        $d = self::D;
        $data = self::data();
        $today = $data['today'];
        $week_end = date('Y-m-d', strtotime($today . ' +6 days'));
        $week = 0;

        foreach ($data['upcoming'] as $slot) {
            if ($slot['start_date'] <= $week_end) {
                $week++;
            }
        }

        $to_confirm = Bookings::countPendingDated();
        $rows = '';

        foreach (array_slice($data['upcoming'], 0, 6) as $slot) {
            $time = strtotime($slot['start_date'] . ' 12:00:00');
            $capacity = (int) $slot['booked'] + max(0, (int) $slot['remaining']);
            $pct = $capacity > 0 ? (int) round(100 * (int) $slot['booked'] / $capacity) : 0;
            $is_today = $slot['start_date'] === $today;
            $hours = Lookup::slotHours($slot, true, __('Journée', $d));
            $people = Lookup::hostNames($slot['host_ids']);
            $fiche = View::url('passflow_slot', array('view' => 'details', 'id' => (int) $slot['id']));

            if ((int) $slot['remaining'] <= 0) {
                $state = '<span class="smbb-pfw-chip is-full">' . esc_html__('Complet', $d) . '</span>';
            } elseif ($pct < 50 && $slot['start_date'] <= $week_end) {
                $state = '<span class="smbb-pfw-chip is-warn">' . esc_html(sprintf(_n('%d place libre', '%d places libres', (int) $slot['remaining'], $d), (int) $slot['remaining'])) . '</span>';
            } else {
                $state = '';
            }

            $rows .= '<li class="smbb-pfw-slot' . ($is_today ? ' is-today' : '') . '">'
                . '<a class="smbb-pfw-date" href="' . esc_url($fiche) . '"><small>' . esc_html($is_today ? __("Auj.", $d) : date_i18n('D', $time)) . '</small><b>' . esc_html(date_i18n('j', $time)) . '</b><small>' . esc_html(date_i18n('M', $time)) . '</small></a>'
                . '<div class="smbb-pfw-slot-main">'
                . '<a class="smbb-pfw-slot-name" href="' . esc_url($fiche) . '">' . esc_html(Lookup::activityName($slot['activity_id'])) . '</a>' . $state
                . '<div class="smbb-pfw-muted">' . esc_html(implode(' · ', array_filter(array($hours, $people !== '' ? $people : __('Sans intervenant', $d))))) . '</div>'
                . '<div class="smbb-pfw-meter"><span class="smbb-pfw-bar"><i style="width:' . $pct . '%"></i></span><span>' . esc_html($slot['booked'] . ' / ' . $capacity) . '</span></div>'
                . '</div>'
                . ($is_today ? '<a class="button button-small smbb-pfw-cta" href="' . esc_url(View::url('passflow_portal', array('creneau' => (int) $slot['id']))) . '">' . esc_html__("Faire l'appel", $d) . '</a>' : '')
                . '</li>';
        }

        echo '<div class="smbb-tw smbb-pfw-next">';

        echo '<div class="smbb-pfw-summary"><span><b>' . esc_html(number_format_i18n($week)) . '</b> ' . esc_html(_n('date cette semaine', 'dates cette semaine', $week, $d)) . '</span>';

        if ($to_confirm > 0) {
            echo '<a class="smbb-pfw-pill is-warn" href="' . esc_url(View::filtered('passflow_booking', 'status', 'pending')) . '">' . esc_html(sprintf(_n('%d réservation à confirmer', '%d réservations à confirmer', $to_confirm, $d), $to_confirm)) . '</a>';
        }

        echo '</div>';

        echo $rows !== ''
            ? '<ul class="smbb-pfw-slots">' . $rows . '</ul>' // phpcs:ignore WordPress.Security.EscapeOutput -- échappé ci-dessus.
            : '<p class="smbb-pfw-empty">' . esc_html__('Aucune date en vente pour le moment.', $d) . '</p>';

        self::footer(array(
            array(__('Calendrier', $d), View::url('passflow_calendar')),
            array(__('Tableau de bord Passflow', $d), View::url('passflow')),
        ), array(__('Nouvelle date', $d), View::url('passflow_slot', array('view' => 'form'))));

        echo '</div>';
    }

    // -----------------------------------------------------------------------
    // Remplissage
    // -----------------------------------------------------------------------

    public static function renderFill()
    {
        $d = self::D;
        $data = self::data();
        $fill = $data['fill'];
        $free = max(0, $data['capacity'] - $data['booked']);

        // Anneau : r = 15.915 -> circonférence 100, la longueur du trait vaut le pourcentage.
        $ring = '<svg class="smbb-pfw-ring" viewBox="0 0 36 36" aria-hidden="true">'
            . '<defs><linearGradient id="smbb-pfw-ring-grad" x1="0" y1="0" x2="1" y2="1"><stop offset="0" class="a"/><stop offset="1" class="b"/></linearGradient></defs>'
            . '<circle class="track" cx="18" cy="18" r="15.915"/>'
            . '<circle class="value" cx="18" cy="18" r="15.915" stroke-dasharray="' . (int) ($fill === null ? 0 : min(100, $fill)) . ' 100"/>'
            . '</svg>';

        echo '<div class="smbb-tw smbb-pfw-fill">';
        echo '<div class="smbb-pfw-subtitle is-top">' . esc_html(sprintf(__('Les %d prochains créneaux', $d), self::FILL_COUNT)) . '</div>';
        echo '<div class="smbb-pfw-fill-head">'
            . '<div class="smbb-pfw-ring-wrap">' . $ring . '<strong>' . esc_html($fill === null ? '–' : $fill . ' %') . '</strong></div>' // phpcs:ignore WordPress.Security.EscapeOutput
            . '<div class="smbb-pfw-facts">'
            . '<div><span>' . esc_html__('Places occupées', $d) . '</span><b>' . esc_html(number_format_i18n($data['booked']) . ' / ' . number_format_i18n($data['capacity'])) . '</b></div>'
            . '<div><span>' . esc_html__('Encore libres', $d) . '</span><b>' . esc_html(number_format_i18n($free)) . '</b></div>'
            . (Settings::waitlistEnabled() ? '<div><span>' . esc_html__("En liste d'attente", $d) . '</span><b>' . esc_html(number_format_i18n($data['waiting'])) . '</b></div>' : '')
            . '</div></div>';

        $bars = '';

        foreach ($data['fill_slots'] as $slot) {
            $time = strtotime($slot['start_date'] . ' 12:00:00');
            $capacity = (int) $slot['booked'] + max(0, (int) $slot['remaining']);
            $pct = $capacity > 0 ? (int) round(100 * (int) $slot['booked'] / $capacity) : 0;
            $label = date_i18n('D j M', $time) . ' · ' . Lookup::activityName($slot['activity_id'])
                . ' · ' . sprintf(__('%1$d / %2$d places (%3$d %%)', $d), (int) $slot['booked'], $capacity, $pct);
            $class = $pct >= 80 ? ' is-good' : ($pct >= 50 ? ' is-mid' : ' is-low');
            $class .= $slot['start_date'] === $data['today'] ? ' is-today' : '';
            $class .= (int) date('N', $time) >= 6 ? ' is-weekend' : '';

            $bars .= '<a class="smbb-pfw-col' . $class . '" href="' . esc_url(View::url('passflow_slot', array('view' => 'details', 'id' => (int) $slot['id']))) . '" title="' . esc_attr($label) . '">'
                . '<span class="smbb-pfw-col-bar"><i style="height:' . max(6, $pct) . '%"></i></span>'
                . '<small>' . esc_html($slot['start_date'] === $data['today'] ? __('Auj.', $d) : mb_substr(date_i18n('D', $time), 0, 3)) . '</small>'
                . '<b>' . esc_html(date_i18n('j/n', $time)) . '</b>'
                . '<span class="screen-reader-text">' . esc_html($label) . '</span></a>';
        }

        // Moins de créneaux à venir que de barres : on complète avec des barres hachurées.
        for ($i = count($data['fill_slots']); $i < self::FILL_COUNT; $i++) {
            $bars .= '<span class="smbb-pfw-col is-none" aria-hidden="true"><span class="smbb-pfw-col-bar"></span><small>&nbsp;</small><b>&nbsp;</b></span>';
        }

        echo '<div class="smbb-pfw-cols">' . $bars . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput

        if (!$data['fill_slots']) {
            echo '<p class="smbb-pfw-empty">' . esc_html__('Aucune date en vente pour le moment.', $d) . '</p>';
        }

        self::footer(array(
            array(__('Ouvrir le calendrier', $d), View::url('passflow_calendar')),
            array(__('Statistiques', $d), View::url('passflow_stats')),
        ));

        echo '</div>';
    }

    // -----------------------------------------------------------------------
    // Ventes
    // -----------------------------------------------------------------------

    public static function renderSales()
    {
        global $wpdb;

        $d = self::D;
        $today = current_time('Y-m-d');
        $month_start = substr($today, 0, 8) . '01';
        $prev_start = date('Y-m-01', strtotime($month_start . ' -1 month'));
        $prev_same_day = date('Y-m-d', min(strtotime($prev_start . ' +' . ((int) substr($today, 8, 2) - 1) . ' days'), strtotime(date('Y-m-t', strtotime($prev_start)))));
        $sales = Stats::sales($month_start, $today);
        $prev = Stats::sales($prev_start, $prev_same_day);
        $stock = Stats::stock();

        // 8 dernières semaines (hors pass, comme Statistiques et le tableau de bord Passflow).
        $weeks = array_fill(0, 8, 0.0);
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT creation_date, price_paid FROM ' . Lookup::table('passflow_booking') . " WHERE deleted_flag = 0 AND pass_id = 0 AND status <> 'cancelled' AND creation_date >= %s",
            date('Y-m-d', strtotime($today . ' -55 days')) . ' 00:00:00'
        ), ARRAY_A);

        foreach (is_array($rows) ? $rows : array() as $row) {
            $index = 7 - (int) floor((strtotime($today) - strtotime(substr($row['creation_date'], 0, 10))) / (7 * DAY_IN_SECONDS));

            if ($index >= 0 && $index <= 7) {
                $weeks[$index] += (float) $row['price_paid'];
            }
        }

        if ($prev['revenue'] > 0) {
            $delta = (int) round(100 * ($sales['revenue'] - $prev['revenue']) / $prev['revenue']);
            $trend = '<span class="smbb-pfw-trend ' . ($delta >= 0 ? 'is-up' : 'is-down') . '">' . esc_html(($delta >= 0 ? '↑ +' : '↓ −') . abs($delta) . ' %') . '</span> '
                . esc_html(sprintf(__('vs %s à la même date', $d), date_i18n('F', strtotime($prev_start))));
        } else {
            $trend = esc_html(sprintf(__('Rien sur %s à la même date', $d), date_i18n('F', strtotime($prev_start))));
        }

        echo '<div class="smbb-tw smbb-pfw-sales">';
        echo '<a class="smbb-pfw-hero" href="' . esc_url(View::url('passflow_stats')) . '">'
            . '<span class="smbb-pfw-hero-label">' . esc_html(sprintf(__('Ventes de %s', $d), date_i18n('F', strtotime($today)))) . '</span>'
            . '<strong>' . View::money($sales['revenue']) . '</strong>' // money() échappe.
            . '<span class="smbb-pfw-hero-trend">' . $trend . '</span>' // phpcs:ignore WordPress.Security.EscapeOutput
            . self::sparkline($weeks) // phpcs:ignore WordPress.Security.EscapeOutput
            . '<span class="smbb-pfw-hero-foot">' . esc_html__('8 dernières semaines', $d) . '</span>'
            . '</a>';

        $tiles = array(
            array(__('Places vendues ce mois', $d), number_format_i18n((int) $sales['sold']), '', View::url('passflow_stats')),
            array(__('Places à venir', $d), number_format_i18n((int) $stock['upcoming'] + (int) $stock['undated']), sprintf(__('%s de PCA', $d), wp_strip_all_tags(View::money($stock['amount']))), View::url('passflow_stock')),
        );

        if (\Smbb\Passflow\WooCommerce\Module::active() && class_exists('\\Smbb\\Passflow\\WooCommerce\\GiftCards')) {
            $gift = \Smbb\Passflow\WooCommerce\GiftCards::counts();
            $tiles[] = array(__('Bons cadeaux en circulation', $d), number_format_i18n((int) $gift['unused']), sprintf(__('%d utilisés', $d), (int) $gift['used']), View::url('passflow_vouchers'));
        }

        echo '<div class="smbb-pfw-tiles">';

        foreach ($tiles as $tile) {
            echo '<a class="smbb-pfw-tile" href="' . esc_url($tile[3]) . '"><span>' . esc_html($tile[0]) . '</span><b>' . esc_html($tile[1]) . '</b>'
                . ($tile[2] !== '' ? '<small>' . esc_html($tile[2]) . '</small>' : '') . '</a>';
        }

        echo '</div>';

        self::footer(array(
            array(__('Statistiques', $d), View::url('passflow_stats')),
            array(__('Factures', $d), View::url('passflow_invoices')),
        ));

        echo '</div>';
    }

    /**
     * Courbe des ventes, tracée en couleur du texte courant (blanc sur le dégradé).
     *
     * @param array<int,float> $values
     */
    private static function sparkline(array $values)
    {
        $max = max($values) > 0 ? max($values) : 1;
        $count = count($values);
        $points = array();

        foreach (array_values($values) as $i => $value) {
            $points[] = round(200 * $i / max(1, $count - 1), 1) . ' ' . round(36 - 30 * $value / $max, 1);
        }

        $line = 'M' . implode(' L', $points);
        $last = explode(' ', end($points));

        return '<svg class="smbb-pfw-spark" viewBox="-3 0 206 40" preserveAspectRatio="none" aria-hidden="true">'
            . '<path class="area" d="' . esc_attr($line . ' L200 40 L0 40Z') . '"/><path class="line" d="' . esc_attr($line) . '"/>'
            . '<circle cx="' . esc_attr($last[0]) . '" cy="' . esc_attr($last[1]) . '" r="3"/></svg>';
    }

    // -----------------------------------------------------------------------
    // Demandes de dispo
    // -----------------------------------------------------------------------

    public static function renderAvailability()
    {
        global $wpdb;

        $d = self::D;
        $today = current_time('Y-m-d');
        $since = date('Y-m-d H:i:s', strtotime(current_time('mysql')) - 60 * DAY_IN_SECONDS);
        $campaigns = array();

        foreach (\Smbb\Passflow\Service\Campaigns::all(20) as $campaign) {
            if ((string) $campaign['sent_at'] !== '' && $campaign['sent_at'] >= $since) {
                $campaigns[] = $campaign;
            }
        }

        // Réponses reçues par demande (lignes de suivi), sans créer de ligne au passage.
        $answered = array();
        $ids = array_column($campaigns, 'id');

        if ($ids) {
            $results = $wpdb->get_results(
                'SELECT campaign_id, COUNT(*) AS n FROM ' . Lookup::table('passflow_campaign_recipient')
                . ' WHERE deleted_flag = 0 AND replied_at IS NOT NULL AND campaign_id IN (' . implode(',', array_map('intval', $ids)) . ') GROUP BY campaign_id',
                ARRAY_A
            );

            foreach (is_array($results) ? $results : array() as $row) {
                $answered[(int) $row['campaign_id']] = (int) $row['n'];
            }
        }

        $waiting = 0;
        $rows = '';

        foreach ($campaigns as $i => $campaign) {
            $total = count($campaign['host_ids']);
            $done = min($total, isset($answered[$campaign['id']]) ? $answered[$campaign['id']] : 0);
            $waiting += $total - $done;

            if ($i >= 4) {
                continue;
            }

            $dates = count($campaign['slot_ids']);
            $pct = $total > 0 ? (int) round(100 * $done / $total) : 0;
            $rows .= '<li><div class="smbb-pfw-req-top"><b>' . esc_html(sprintf(_n('%d date', '%d dates', $dates, $d), $dates)) . '</b>'
                . '<span class="smbb-pfw-muted">' . esc_html(sprintf(__('envoyée le %s', $d), date_i18n('j M', strtotime($campaign['sent_at'])))) . '</span>'
                . '<span class="smbb-pfw-req-count' . ($done >= $total ? ' is-done' : '') . '">' . esc_html(sprintf(__('%1$d / %2$d réponses', $d), $done, $total)) . '</span></div>'
                . '<span class="smbb-pfw-bar"><i style="width:' . $pct . '%"></i></span></li>';
        }

        // Dates en vente à 30 jours sans intervenant : ce qu'une demande de dispo doit couvrir.
        $in_30 = date('Y-m-d', strtotime($today . ' +30 days'));
        $uncovered = 0;

        foreach (self::data()['upcoming'] as $slot) {
            if ($slot['start_date'] <= $in_30 && !Lookup::ids($slot['host_ids'])) {
                $uncovered++;
            }
        }

        echo '<div class="smbb-tw smbb-pfw-availability">';
        echo '<div class="smbb-pfw-duo">'
            . '<div class="smbb-pfw-big' . ($waiting > 0 ? ' is-accent' : '') . '"><b>' . esc_html(number_format_i18n($waiting)) . '</b><span>' . esc_html(_n('réponse attendue', 'réponses attendues', $waiting, $d)) . '</span></div>'
            . '<div class="smbb-pfw-big' . ($uncovered > 0 ? ' is-warn' : '') . '"><b>' . esc_html(number_format_i18n($uncovered)) . '</b><span>' . esc_html(_n('date sans intervenant à 30 jours', 'dates sans intervenant à 30 jours', $uncovered, $d)) . '</span></div>'
            . '</div>';

        echo $rows !== ''
            ? '<div class="smbb-pfw-subtitle">' . esc_html__('Dernières demandes', $d) . '</div><ul class="smbb-pfw-reqs">' . $rows . '</ul>' // phpcs:ignore WordPress.Security.EscapeOutput
            : '<p class="smbb-pfw-empty">' . esc_html__('Aucune demande envoyée ces 60 derniers jours.', $d) . '</p>';

        self::footer(array(
            array(__('Toutes les demandes', $d), View::url('passflow_campaigns')),
            array(__('Calendrier', $d), View::url('passflow_calendar')),
        ), array(__('Nouvelle demande', $d), View::url('passflow_campaigns')));

        echo '</div>';
    }

    // -----------------------------------------------------------------------

    /**
     * @param array<int,array{0:string,1:string}> $links
     * @param array{0:string,1:string}|null       $primary
     */
    private static function footer(array $links, $primary = null)
    {
        echo '<div class="smbb-pfw-foot"><span>';

        foreach ($links as $link) {
            // Dans le tableau de bord Passflow, pas de lien vers… le tableau de bord Passflow.
            if (self::$embedded && $link[1] === View::url('passflow')) {
                continue;
            }

            echo '<a href="' . esc_url($link[1]) . '">' . esc_html($link[0]) . '</a>';
        }

        echo '</span>';

        if ($primary) {
            echo '<a class="smbb-pfw-btn" href="' . esc_url($primary[1]) . '">+ ' . esc_html($primary[0]) . '</a>';
        }

        echo '</div>';
    }
}
