<?php

namespace Smbb\Passflow\WooCommerce;

use Smbb\Passflow\Repository\Slots;
use Smbb\Passflow\Repository\Lookup;
use Smbb\Passflow\Service\Capacity;
use Smbb\Passflow\Service\SaleWindow;
use Smbb\Passflow\Support\Choices;
use Smbb\Passflow\Support\Input;
use Smbb\Passflow\Support\Settings;

defined('ABSPATH') || exit;

/**
 * Fiche produit d'un atelier et panier : choix de la date, places, informations des participants.
 */
final class Front
{
    const MAX_SEATS = 10;
    const WAITLIST_ACTION = 'passflow_waitlist_join';

    public static function hooks()
    {
        // Fiche produit.
        add_filter('woocommerce_is_purchasable', array(__CLASS__, 'purchasable'), 10, 2);
        add_filter('woocommerce_get_price_html', array(__CLASS__, 'priceHtml'), 10, 2);
        add_filter('woocommerce_quantity_input_args', array(__CLASS__, 'quantityArgs'), 10, 2);
        add_action('woocommerce_before_add_to_cart_button', array(__CLASS__, 'renderDates'));
        add_action('woocommerce_after_add_to_cart_quantity', array(__CLASS__, 'renderParticipants'));
        add_action('woocommerce_after_add_to_cart_form', array(__CLASS__, 'renderWaitlist'));
        add_action('woocommerce_single_product_summary', array(__CLASS__, 'renderClosed'), 31);
        add_action('wp_enqueue_scripts', array(__CLASS__, 'assets'));
        add_action('wp_head', array(__CLASS__, 'structuredData'), 30);

        // Listes de produits : le bouton mène à la fiche, où l'on choisit la date.
        add_filter('woocommerce_product_add_to_cart_url', array(__CLASS__, 'loopUrl'), 10, 2);
        add_filter('woocommerce_product_add_to_cart_text', array(__CLASS__, 'loopText'), 10, 2);
        add_filter('woocommerce_product_supports', array(__CLASS__, 'loopAjax'), 10, 3);

        // Panier.
        add_filter('woocommerce_add_to_cart_validation', array(__CLASS__, 'validateAdd'), 10, 3);
        add_filter('woocommerce_add_cart_item_data', array(__CLASS__, 'cartItemData'), 10, 2);
        add_filter('woocommerce_add_to_cart_quantity', array(__CLASS__, 'cartQuantity'), 10, 2);
        add_filter('woocommerce_get_item_data', array(__CLASS__, 'itemData'), 10, 2);
        add_action('woocommerce_before_calculate_totals', array(__CLASS__, 'cartPrices'), 20);
        add_filter('woocommerce_cart_item_quantity', array(__CLASS__, 'lockQuantity'), 10, 3);
        add_filter('woocommerce_store_api_product_quantity_editable', array(__CLASS__, 'quantityEditable'), 10, 3);
        add_action('woocommerce_check_cart_items', array(__CLASS__, 'checkCart'));
        add_action('woocommerce_after_checkout_validation', array(__CLASS__, 'checkCheckout'), 10, 2);

        // Commande.
        add_action('woocommerce_checkout_create_order_line_item', array(__CLASS__, 'orderLineItem'), 10, 4);
        add_filter('woocommerce_hidden_order_itemmeta', array(__CLASS__, 'hiddenMeta'));
        add_action('woocommerce_order_details_after_order_table', array(__CLASS__, 'orderReservations'), 5);

        // Liste d'attente (visiteurs connectés ou non).
        add_action('admin_post_' . self::WAITLIST_ACTION, array(__CLASS__, 'joinWaitlist'));
        add_action('admin_post_nopriv_' . self::WAITLIST_ACTION, array(__CLASS__, 'joinWaitlist'));
    }

    // ------------------------------------------------------------------
    // Données
    // ------------------------------------------------------------------

    /**
     * @param \WC_Product|int $product
     */
    private static function atelier($product)
    {
        $id = is_object($product) ? $product->get_id() : (int) $product;

        if (is_object($product) && $product->get_parent_id()) {
            $id = $product->get_parent_id();
        }

        $activity = Products::activityForProduct($id);

        return $activity && $activity['status'] === 'active' ? $activity : null;
    }

    /**
     * Dates à venir d'un atelier, avec les places vendables en ligne.
     *
     * @return array<int,array<string,mixed>>
     */
    private static function dates(array $activity)
    {
        static $cache = array();

        if (isset($cache[$activity['id']])) {
            return $cache[$activity['id']];
        }

        $email = self::currentEmail();
        $dates = array();

        foreach (Slots::upcoming(0, array('activity_id' => (int) $activity['id'], 'statuses' => array('open', 'full'), 'with_passes' => true)) as $slot) {
            if (Capacity::startsAt($slot) <= current_time('timestamp')) {
                continue;
            }

            // Mise en vente différée : la date s'affiche, sans réservation avant l'ouverture.
            $slot['presale'] = $slot['status'] === 'open' && SaleWindow::pending($slot);
            $slot['for_sale'] = Capacity::forSale($slot, $email);
            $slot['unit_price'] = \Smbb\Passflow\Support\Prices::display(self::price($slot, $activity));
            $dates[] = $slot;
        }

        return $cache[$activity['id']] = $dates;
    }

    public static function price(array $slot, $activity = null)
    {
        if ($slot['price'] !== null && $slot['price'] !== '') {
            return (float) $slot['price'];
        }

        $activity = $activity ?: Lookup::atelier($slot['activity_id']);

        return $activity && $activity['default_price'] !== null && $activity['default_price'] !== '' ? (float) $activity['default_price'] : 0.0;
    }

    private static function currentEmail()
    {
        $user = wp_get_current_user();

        return $user && $user->exists() ? strtolower((string) $user->user_email) : '';
    }

    /**
     * Ligne lisible d'une date : « samedi 6 décembre 2026, 19h30-21h00 ».
     */
    public static function when(array $slot)
    {
        if (\Smbb\Passflow\Service\Passes::isPass($slot)) {
            $parts = array();

            foreach (\Smbb\Passflow\Service\Passes::days($slot) as $day) {
                $hours = Lookup::slotHours($day);
                $parts[] = date_i18n('l j F', strtotime($day['start_date'] . ' 12:00:00')) . ($hours !== '' ? ' (' . $hours . ')' : '');
            }

            return sprintf(__('Pass %1$s : %2$s', 'smbb-passflow'), count($parts) === 2 ? __('2 jours', 'smbb-passflow') : sprintf(__('%d jours', 'smbb-passflow'), count($parts)), implode(' + ', $parts));
        }

        $date = date_i18n('l j F Y', strtotime($slot['start_date'] . ' 12:00:00'));
        $hours = Lookup::slotHours($slot);

        return $hours !== '' ? $date . ', ' . $hours : $date;
    }

    /**
     * Page de remerciement et détail de commande : les réservations de la commande, avec billet,
     * ajout à l'agenda et documents à remplir.
     *
     * @param \WC_Order $order
     */
    public static function orderReservations($order)
    {
        global $wpdb;

        if (!$order || !\Smbb\Passflow\Support\Schema::ready()) {
            return;
        }

        $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . Lookup::table('passflow_booking') . " WHERE deleted_flag = 0 AND order_id = %d AND pass_id = 0 AND status <> 'cancelled' ORDER BY id ASC", (int) $order->get_id()), ARRAY_A);

        if (!$rows) {
            return;
        }

        $d = 'smbb-passflow';
        $docs = \Smbb\Passflow\Service\Documents::enabled();
        $answers = $docs ? \Smbb\Passflow\Service\Documents::answers(array_column($rows, 'id')) : array();
        $items = '';

        foreach ($rows as $row) {
            $slot = !empty($row['slot_id']) ? Slots::find($row['slot_id']) : null;
            $activity = Lookup::atelier($row['activity_id']);
            $links = array();

            if ($slot && \Smbb\Passflow\Service\Tickets::available($row) && !Slots::isPast($slot)) {
                $links[] = '<a class="passflow-order-link is-pdf" href="' . esc_url(\Smbb\Passflow\Service\Tickets::url($row, 'pdf')) . '">' . esc_html__('Billet', $d) . '</a>';
                $links[] = '<a class="passflow-order-link is-ics" href="' . esc_url(\Smbb\Passflow\Service\Tickets::url($row, 'ics')) . '">' . esc_html__('Ajouter à mon agenda', $d) . '</a>';

                if (\Smbb\Passflow\Service\Transfers::blocked($row) === '') {
                    $links[] = '<a class="passflow-order-link is-transfer" href="' . esc_url(\Smbb\Passflow\Service\Tickets::url($row, 'page') . '#transfert') . '">' . esc_html__('Transférer', $d) . '</a>';
                }

                $wallets = \Smbb\Passflow\Service\Tickets::walletButtons($row);
            } else {
                $wallets = '';
            }

            if ($docs && \Smbb\Passflow\Service\Documents::missing(isset($answers[(int) $row['id']]) ? $answers[(int) $row['id']] : array())) {
                $links[] = '<a class="passflow-order-link is-docs" href="' . esc_url(\Smbb\Passflow\Service\Documents::url($row)) . '"><strong>' . esc_html__('Documents à remplir', $d) . '</strong></a>';
            }

            $waiting = $row['status'] === 'pending'
                ? '<br><span class="passflow-order-waiting">' . esc_html__('En attente de validation du paiement : votre place est réservée, le billet vous sera envoyé dès réception.', $d) . '</span>'
                : '';
            // Pastille de date façon calendrier (mois + jour), à gauche de la carte.
            $tile = '';
            $start = $slot && !empty($slot['start_date']) ? strtotime((string) $slot['start_date']) : false;

            if ($start) {
                $tile = '<span class="passflow-order-date" aria-hidden="true"><span class="passflow-order-month">' . esc_html(date_i18n('M', $start)) . '</span><span class="passflow-order-day">' . esc_html(date_i18n('j', $start)) . '</span></span>';
            }

            $items .= '<li class="passflow-order-item">' . $tile . '<div class="passflow-order-body"><span class="passflow-order-head"><strong class="passflow-order-who">' . esc_html(Lookup::personName($row)) . '</strong> · <span class="passflow-order-atelier">' . esc_html($activity ? $activity['name'] : '') . '</span></span>'
                . $waiting
                . '<br><span class="passflow-order-when">' . esc_html($slot ? self::when($slot) : __('Date à choisir avec nous', $d)) . '</span>'
                . ($links ? '<br><span class="passflow-order-links">' . implode(' · ', $links) . '</span>' : '')
                . $wallets
                . '</div></li>';
        }

        echo '<section class="passflow-order-reservations"><h2>' . esc_html(_n('Votre réservation', 'Vos réservations', count($rows), $d)) . '</h2><ul>' . $items . '</ul></section>'; // phpcs:ignore -- HTML échappé ci-dessus.
    }

    /**
     * Données structurées schema.org Event pour chaque date à venir de la fiche produit : Google peut
     * afficher les dates, le lieu, le prix et la disponibilité directement dans les résultats.
     */
    public static function structuredData()
    {
        if (!function_exists('is_product') || !is_product()) {
            return;
        }

        $product = wc_get_product(get_queried_object_id());
        $activity = $product ? self::atelier($product) : null;

        if (!$activity) {
            return;
        }

        $tz = wp_timezone();
        $events = array();
        $image_id = Products::imageId($activity);
        $image = $image_id ? (string) wp_get_attachment_image_url($image_id, 'large') : '';
        $shop = (string) \Smbb\Passflow\Support\Settings::get('sender_name') ?: get_bloginfo('name');
        $address = trim(preg_replace('/\s*\n\s*/', ', ', (string) $activity['address']));

        foreach (array_slice(self::dates($activity), 0, 12) as $slot) {
            try {
                $start = new \DateTimeImmutable($slot['start_date'] . ' ' . ($slot['start_time'] ?: '00:00:00'), $tz);
                $end = !empty($slot['end_time']) && $slot['end_time'] > $slot['start_time'] ? new \DateTimeImmutable($slot['start_date'] . ' ' . $slot['end_time'], $tz) : null;
            } catch (\Exception $e) {
                continue;
            }

            $event = array(
                '@context' => 'https://schema.org',
                '@type' => 'Event',
                'name' => (string) $activity['name'],
                'description' => wp_trim_words(wp_strip_all_tags(strip_shortcodes(Products::description($activity, true))), 60),
                'startDate' => Lookup::allDay($slot) ? $start->format('Y-m-d') : $start->format('c'),
                'eventStatus' => 'https://schema.org/EventScheduled',
                'eventAttendanceMode' => 'https://schema.org/OfflineEventAttendanceMode',
                'location' => array(
                    '@type' => 'Place',
                    'name' => (string) ($activity['location'] ?: $shop),
                    'address' => $address !== '' ? $address : (string) $activity['location'],
                ),
                'organizer' => array('@type' => 'Organization', 'name' => $shop, 'url' => home_url('/')),
                'offers' => array(
                    '@type' => 'Offer',
                    'url' => get_permalink($product->get_id()),
                    'price' => number_format(\Smbb\Passflow\Support\Prices::ttc(self::price($slot, $activity)), 2, '.', ''),
                    'priceCurrency' => get_woocommerce_currency(),
                    'availability' => (int) $slot['for_sale'] > 0 ? 'https://schema.org/InStock' : (!empty($slot['presale']) ? 'https://schema.org/PreSale' : 'https://schema.org/SoldOut'),
                    'validFrom' => get_post_time('c', false, $product->get_id()),
                ),
            );

            // Mise en vente différée : l'offre est valable à partir de l'ouverture.
            if (!empty($slot['presale']) && SaleWindow::publicOpensAt($slot)) {
                $event['offers']['validFrom'] = (new \DateTimeImmutable((string) $slot['sale_opens_at'], $tz))->format('c');
            }

            if ($end) {
                $event['endDate'] = $end->format('c');
            }

            if ($image) {
                $event['image'] = array($image);
            }

            $names = Lookup::hostNames($slot['host_ids']);

            if ($names !== '') {
                $event['performer'] = array('@type' => 'Person', 'name' => $names);
            }

            $events[] = $event;
        }

        if ($events) {
            echo '<script type="application/ld+json">' . wp_json_encode(count($events) === 1 ? $events[0] : $events, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . '</script>' . "\n";
        }
    }

    // ------------------------------------------------------------------
    // Fiche produit
    // ------------------------------------------------------------------

    public static function purchasable($purchasable, $product)
    {
        $activity = Products::activityForProduct(is_object($product) ? $product->get_id() : 0);

        if (!$activity) {
            return $purchasable;
        }

        if ($activity['status'] !== 'active') {
            return false;
        }

        foreach (self::dates($activity) as $slot) {
            if ($slot['for_sale'] > 0) {
                return true;
            }
        }

        return false;
    }

    public static function priceHtml($html, $product)
    {
        $activity = self::atelier($product);

        if (!$activity) {
            return $html;
        }

        $prices = array();

        foreach (self::dates($activity) as $slot) {
            $prices[] = $slot['unit_price'];
        }

        if (!$prices) {
            return $html;
        }

        $min = min($prices);
        $price = wc_price($min) . ' <small class="passflow-price-unit">' . esc_html__('par personne', 'smbb-passflow') . '</small>';

        return count(array_unique($prices)) > 1 ? sprintf(esc_html__('À partir de %s', 'smbb-passflow'), $price) : $price;
    }

    public static function quantityArgs($args, $product)
    {
        $activity = is_product() ? self::atelier($product) : null;

        if (!$activity) {
            return $args;
        }

        $max = 0;

        foreach (self::dates($activity) as $slot) {
            $max = max($max, (int) $slot['for_sale']);
        }

        $args['min_value'] = 1;
        $args['max_value'] = max(1, min(self::MAX_SEATS, $max));
        $args['input_value'] = isset($args['input_value']) ? max(1, (int) $args['input_value']) : 1;

        return $args;
    }

    public static function renderDates()
    {
        global $product;

        $activity = $product ? self::atelier($product) : null;

        if (!$activity) {
            return;
        }

        $d = 'smbb-passflow';
        // Date choisie : formulaire renvoyé, ou lien « Réserver » d'un shortcode (?passflow_slot=ID).
        $selected = isset($_POST['passflow_slot_id']) ? absint($_POST['passflow_slot_id']) : (isset($_GET['passflow_slot']) ? absint($_GET['passflow_slot']) : 0);
        $first_free = 0;

        echo '<fieldset class="passflow-dates" data-passflow-dates>';
        echo '<legend>' . esc_html__('Choisissez votre date', $d) . '</legend>';

        foreach (self::dates($activity) as $slot) {
            $free = (int) $slot['for_sale'];
            $id = (int) $slot['id'];

            if ($free > 0 && !$first_free) {
                $first_free = $id;
            }

            $checked = $selected ? $selected === $id : ($free > 0 && $first_free === $id);
            $label = $free > 0
                ? sprintf(_n('%d place restante', '%d places restantes', $free, $d), $free)
                : (!empty($slot['presale']) ? SaleWindow::shortLabel($slot) : __('Complet', $d));

            echo '<label class="passflow-date' . ($free > 0 ? '' : (!empty($slot['presale']) ? ' is-full is-soon' : ' is-full')) . '">'
                . '<input type="radio" name="passflow_slot_id" value="' . esc_attr((string) $id) . '" data-seats="' . esc_attr((string) min(self::MAX_SEATS, $free)) . '" data-price="' . esc_attr(wc_format_decimal($slot['unit_price'], wc_get_price_decimals())) . '"'
                . ($checked ? ' checked' : '') . ($free > 0 ? ' required' : ' disabled') . '>'
                . '<span class="passflow-date-when">' . esc_html(self::when($slot)) . '</span>'
                . '<span class="passflow-date-meta">' . esc_html($label) . ' · ' . wp_kses_post(wc_price($slot['unit_price'])) . '</span>'
                . '</label>';
        }

        echo '</fieldset>';
        echo '<p class="passflow-seats-label">' . esc_html__('Nombre de places', $d) . '</p>';
    }

    /**
     * Un bloc par participant : le JS en ajoute ou en retire selon le nombre de places.
     */
    /**
     * « Qui participe ? » : une carte par participant (pas de quantité à saisir). Carte 1 avec
     * « C'est moi » pour un client connecté, « Ajouter un participant », « Ajouter mes participants
     * favoris » (fenêtre de choix) et, sur chaque carte, « Mémoriser ce participant ».
     */
    public static function renderParticipants()
    {
        global $product;

        if (!$product || !self::atelier($product)) {
            return;
        }

        $d = 'smbb-passflow';
        $posted = isset($_POST['passflow_participants']) && is_array($_POST['passflow_participants']) ? array_values(wp_unslash($_POST['passflow_participants'])) : array();
        $user = wp_get_current_user();
        $logged = $user && $user->exists();
        $me = $logged ? array(
            'first_name' => (string) $user->first_name,
            'last_name' => (string) $user->last_name,
            'email' => (string) $user->user_email,
            'phone' => (string) get_user_meta($user->ID, 'billing_phone', true),
        ) : null;

        if ($me && $me['first_name'] === '') {
            $me['first_name'] = (string) get_user_meta($user->ID, 'billing_first_name', true);
            $me['last_name'] = (string) get_user_meta($user->ID, 'billing_last_name', true);
        }

        if (!$posted) {
            $posted = array(array());
        }

        $favorites = $logged ? Favorites::forScript($user->ID) : array();

        echo '<div class="passflow-participants" data-passflow-participants>';
        echo '<p class="passflow-participants-title">' . esc_html__('Qui participe ?', $d) . '</p>';
        echo '<div class="passflow-participant-list" data-passflow-list>';

        foreach ($posted as $i => $values) {
            echo self::participantBlock($i, is_array($values) ? $values : array(), (bool) $me); // phpcs:ignore -- échappé dans participantBlock.
        }

        echo '</div>';
        echo '<div class="passflow-participant-add">'
            . '<button type="button" class="passflow-add" data-passflow-add><span aria-hidden="true">+</span> ' . esc_html__('Ajouter un participant', $d) . '</button>'
            . '<button type="button" class="passflow-fav-open" data-passflow-fav-open' . ($favorites ? '' : ' hidden') . '><span aria-hidden="true">★</span> ' . esc_html__('Ajouter mes participants favoris', $d) . ' <span class="passflow-fav-count" data-passflow-fav-count>' . count($favorites) . '</span></button>'
            . '</div>';
        echo '<p class="passflow-seats-warning" data-passflow-seats-warning hidden></p>';
        echo '</div>';
        echo '<template data-passflow-participant-template>' . self::participantBlock('__i__', array(), (bool) $me) . '</template>'; // phpcs:ignore

        if ($logged) {
            echo '<div class="passflow-modal" data-passflow-fav-modal hidden role="dialog" aria-modal="true" aria-labelledby="passflow-fav-title">'
                . '<div class="passflow-modal-box">'
                . '<div class="passflow-modal-h"><h3 id="passflow-fav-title">' . esc_html__('Mes participants favoris', $d) . '</h3>'
                . '<button type="button" class="passflow-modal-x" data-passflow-fav-close aria-label="' . esc_attr__('Fermer', $d) . '">×</button></div>'
                . '<p class="passflow-modal-lead">' . esc_html__('Cochez les personnes à ajouter à cette réservation.', $d) . '</p>'
                . '<input type="search" class="passflow-fav-search" data-passflow-fav-search placeholder="' . esc_attr__('Rechercher un nom ou un e-mail', $d) . '" autocomplete="off">'
                . '<div class="passflow-fav-pick" data-passflow-fav-list></div>'
                . '<div class="passflow-modal-actions"><button type="button" class="passflow-link" data-passflow-fav-close>' . esc_html__('Annuler', $d) . '</button>'
                . '<button type="button" class="button alt" data-passflow-fav-add disabled>' . esc_html__('Ajouter', $d) . '</button></div>'
                . '</div></div>';
        }

        wp_add_inline_script('smbb-passflow-front', 'window.passflowParticipants=' . wp_json_encode(array(
            'me' => $me,
            'favorites' => $favorites,
            'max' => self::MAX_SEATS,
            'ajax' => $logged ? array('url' => admin_url('admin-ajax.php'), 'action' => Favorites::AJAX_DELETE, 'nonce' => Favorites::nonce()) : null,
            'texts' => array(
                'participant' => __('Participant %d', $d),
                'add' => __('Ajouter', $d),
                'addN' => __('Ajouter %d participant(s)', $d),
                'inOrder' => __('Déjà dans la réservation', $d),
                'empty' => __('Aucun favori enregistré.', $d),
                'noResult' => __('Aucun résultat.', $d),
                'remove' => __('Retirer %s de vos favoris ?', $d),
                'delete' => __('Supprimer', $d),
                'cancel' => __('Annuler', $d),
                'full' => __('Plus de place disponible sur cette date.', $d),
                'tooMany' => __('Il reste %d place(s) sur cette date : retirez un participant ou choisissez une autre date.', $d),
                'meMissing' => __('Complétez vos nom et prénom dans Mon compte pour les reprendre ici.', $d),
            ),
        )) . ';', 'before');
    }

    private static function participantBlock($index, array $values, $can_be_me = false)
    {
        $d = 'smbb-passflow';
        $prefix = 'passflow_participants[' . $index . ']';
        $number = is_numeric($index) ? (int) $index + 1 : '__n__';
        $self = !empty($values['self']);
        $save = !empty($values['save']);

        $html = '<div class="passflow-participant' . ($self ? ' is-self' : '') . '" data-passflow-participant>'
            . '<div class="passflow-participant-h">'
            . '<span class="passflow-participant-no" data-passflow-no>' . esc_html(is_numeric($number) ? sprintf('%02d', $number) : $number) . '</span>'
            . '<p class="passflow-participant-title" data-passflow-title>' . esc_html(sprintf(__('Participant %s', $d), $number)) . '</p>'
            . ($can_be_me ? '<button type="button" class="passflow-me' . ($self ? ' is-on' : '') . '" data-passflow-me aria-pressed="' . ($self ? 'true' : 'false') . '">' . esc_html__("C'est moi", $d) . '</button>' : '')
            . '<button type="button" class="passflow-remove" data-passflow-remove>' . esc_html__('Retirer', $d) . '</button>'
            . '</div>'
            . '<input type="hidden" name="' . esc_attr($prefix . '[self]') . '" value="' . ($self ? '1' : '0') . '" data-passflow-self>'
            . Favorites::fields($prefix, $values);

        if (self::askContact() === 'optional' && $number === 1) {
            $html .= '<p class="passflow-participant-help">' . esc_html__('Le billet et les rappels sont envoyés à chaque participant. Laissé vide, ils arrivent sur vos propres coordonnées.', $d) . '</p>';
        }

        if (Favorites::canRemember()) {
            $html .= '<label class="passflow-remember" data-passflow-remember><input type="checkbox" name="' . esc_attr($prefix . '[save]') . '" value="1"' . checked($save, true, false) . '> '
                . esc_html__('Mémoriser ce participant dans mes favoris pour mes prochaines commandes', $d)
                . (is_user_logged_in() ? '' : ' <small>' . esc_html__('(si vous créez un compte à la commande)', $d) . '</small>')
                . '</label>';
        }

        return $html . '</div>';
    }

    /**
     * Plus de date en vente : message à la place du bouton.
     */
    public static function renderClosed()
    {
        global $product;

        $activity = $product ? self::atelier($product) : null;

        if (!$activity || $product->is_purchasable()) {
            return;
        }

        // Dates annoncées mais pas encore ouvertes : on donne la prochaine ouverture.
        $soon = array_values(array_filter(self::dates($activity), function ($slot) {
            return !empty($slot['presale']);
        }));

        if ($soon) {
            usort($soon, function ($a, $b) {
                $at_a = SaleWindow::opensAt($a) ?: PHP_INT_MAX;
                $at_b = SaleWindow::opensAt($b) ?: PHP_INT_MAX;

                return $at_a <=> $at_b;
            });
            echo '<p class="passflow-closed passflow-soon">' . esc_html(SaleWindow::label($soon[0])) . '.</p>';
        } else {
            echo '<p class="passflow-closed">' . esc_html__('Toutes les dates sont complètes pour le moment.', 'smbb-passflow') . '</p>';
        }

        self::renderWaitlist();
    }

    /**
     * Formulaire de liste d'attente, pour les dates complètes.
     */
    public static function renderWaitlist()
    {
        global $product;
        static $done = false;

        $activity = $product ? self::atelier($product) : null;

        if (!$activity || $done || !Settings::waitlistEnabled()) {
            return;
        }

        // Pas de liste d'attente sur un pass (on s'inscrit sur les jours), ni sur une date en vente
        // différée pas encore ouverte (tout le monde part à égalité à l'ouverture).
        $full = array_filter(self::dates($activity), function ($slot) {
            return (int) $slot['for_sale'] < 1 && empty($slot['presale']) && !\Smbb\Passflow\Service\Passes::isPass($slot);
        });

        if (!$full) {
            return;
        }

        $done = true;
        $d = 'smbb-passflow';
        $user = wp_get_current_user();
        $logged = $user && $user->exists();

        echo '<details id="passflow-waitlist" class="passflow-waitlist"' . ($product->is_purchasable() ? '' : ' open') . '>';
        echo '<summary>' . esc_html__("Une date est complète ? Inscrivez-vous sur la liste d'attente", $d) . '</summary>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="passflow-waitlist-form">';
        echo '<input type="hidden" name="action" value="' . esc_attr(self::WAITLIST_ACTION) . '">';
        echo '<input type="hidden" name="product_id" value="' . esc_attr((string) $product->get_id()) . '">';
        wp_nonce_field(self::WAITLIST_ACTION, '_passflow_nonce');
        echo '<p>' . esc_html__("Si une place se libère, nous vous prévenons par e-mail et SMS, dans l'ordre d'arrivée.", $d) . '</p>';
        echo '<label class="passflow-full">' . esc_html__('Date', $d) . '<select name="slot_id" required>';

        foreach ($full as $slot) {
            echo '<option value="' . esc_attr((string) $slot['id']) . '">' . esc_html(self::when($slot)) . '</option>';
        }

        echo '</select></label>';
        echo '<div class="passflow-row">'
            . '<label>' . esc_html__('Prénom', $d) . '<input type="text" name="first_name" value="' . esc_attr($logged ? $user->first_name : '') . '" autocomplete="given-name"></label>'
            . '<label>' . esc_html__('Nom', $d) . ' <span class="required">*</span><input type="text" name="last_name" value="' . esc_attr($logged ? $user->last_name : '') . '" required autocomplete="family-name"></label>'
            . '</div><div class="passflow-row">'
            . '<label>' . esc_html__('E-mail', $d) . ' <span class="required">*</span><input type="email" name="email" value="' . esc_attr($logged ? $user->user_email : '') . '" required autocomplete="email"></label>'
            . '<label>' . esc_html__('Téléphone', $d) . '<input type="tel" name="phone" value="" autocomplete="tel"></label>'
            . '<label>' . esc_html__('Places', $d) . '<input type="number" name="places" min="1" max="' . esc_attr((string) self::MAX_SEATS) . '" value="1"></label>'
            . '</div>';
        echo '<p><button type="submit" class="button">' . esc_html__("M'inscrire sur la liste d'attente", $d) . '</button></p>';
        echo '</form></details>';
    }

    public static function loopUrl($url, $product)
    {
        return $product && self::atelier($product) ? $product->get_permalink() : $url;
    }

    public static function loopText($text, $product)
    {
        return $product && self::atelier($product) ? __('Choisir une date', 'smbb-passflow') : $text;
    }

    public static function loopAjax($supports, $feature, $product)
    {
        return $feature === 'ajax_add_to_cart' && $product && self::atelier($product) ? false : $supports;
    }

    public static function assets()
    {
        if ((function_exists('is_account_page') && is_account_page()) || (function_exists('is_order_received_page') && is_order_received_page())) {
            wp_enqueue_style('smbb-passflow-front', SMBB_PASSFLOW_URL . 'assets/front.css', array(), SMBB_PASSFLOW_VERSION);

            return;
        }

        if (!function_exists('is_product') || !is_product()) {
            return;
        }

        $product = wc_get_product(get_queried_object_id());

        if (!$product || !self::atelier($product)) {
            return;
        }

        wp_enqueue_style('smbb-passflow-front', SMBB_PASSFLOW_URL . 'assets/front.css', array(), SMBB_PASSFLOW_VERSION);
        wp_enqueue_script('smbb-passflow-front', SMBB_PASSFLOW_URL . 'assets/front.js', array(), SMBB_PASSFLOW_VERSION, true);
    }

    // ------------------------------------------------------------------
    // Ajout au panier
    // ------------------------------------------------------------------

    public static function validateAdd($passed, $product_id, $quantity)
    {
        $activity = self::atelier((int) $product_id);

        if (!$activity || !$passed) {
            return $passed;
        }

        $d = 'smbb-passflow';
        $slot_id = isset($_POST['passflow_slot_id']) ? absint($_POST['passflow_slot_id']) : 0;
        $slot = $slot_id ? Slots::find($slot_id) : null;

        if (!$slot || (int) $slot['activity_id'] !== (int) $activity['id']) {
            wc_add_notice(__('Choisissez une date.', $d), 'error');

            return false;
        }

        // Le nombre de places est celui des cartes participant remplies.
        $participants = self::postedParticipants();
        $quantity = max(1, count($participants));
        $error = Capacity::checkSale($slot_id, $quantity + self::seatsInCart($slot_id), self::currentEmail());

        if ($error !== '') {
            wc_add_notice($error, 'error');

            return false;
        }

        if (!$participants) {
            wc_add_notice(__('Indiquez le prénom et le nom du participant.', $d), 'error');

            return false;
        }

        $contact = self::askContact();

        foreach (array_slice($participants, 0, $quantity) as $participant) {
            if ($contact === 'off') {
                break;
            }

            $who = trim($participant['first_name'] . ' ' . $participant['last_name']);

            if ($participant['email_raw'] !== '' && $participant['email'] === '') {
                wc_add_notice(sprintf(__("L'adresse e-mail de %s n'est pas valide.", $d), $who), 'error');

                return false;
            }

            if ($contact === 'required' && ($participant['email'] === '' || $participant['phone'] === '')) {
                wc_add_notice(sprintf(__("Indiquez l'e-mail et le téléphone de %s.", $d), $who), 'error');

                return false;
            }
        }

        return true;
    }

    public static function cartItemData($data, $product_id)
    {
        if (!self::atelier((int) $product_id) || empty($_POST['passflow_slot_id'])) {
            return $data;
        }

        $data['passflow_slot_id'] = absint($_POST['passflow_slot_id']);
        $data['passflow_participants'] = array_map(function ($participant) {
            unset($participant['email_raw']);

            return $participant;
        }, array_slice(self::postedParticipants(), 0, self::MAX_SEATS));
        $data['passflow_key'] = md5(wp_json_encode($data['passflow_participants']) . microtime());

        return $data;
    }

    /**
     * Une place par carte participant : la quantité postée ne compte pas.
     */
    public static function cartQuantity($quantity, $product_id)
    {
        if (!self::atelier((int) $product_id) || empty($_POST['passflow_slot_id'])) {
            return $quantity;
        }

        $count = count(self::postedParticipants());

        return $count > 0 ? min(self::MAX_SEATS, $count) : $quantity;
    }

    /**
     * Participants postés, nettoyés ; ceux sans prénom ou nom sont écartés.
     *
     * @return array<int,array<string,string>>
     */
    /**
     * Coordonnées propres à chaque participant : off, optional (vide = celles de l'acheteur) ou required.
     */
    public static function askContact()
    {
        $value = (string) Settings::get('ask_contact');

        return in_array($value, array('off', 'optional', 'required'), true) ? $value : 'optional';
    }

    private static function postedParticipants()
    {
        $raw = isset($_POST['passflow_participants']) && is_array($_POST['passflow_participants']) ? wp_unslash($_POST['passflow_participants']) : array();
        $clean = array();

        foreach ($raw as $row) {
            if (!is_array($row)) {
                continue;
            }

            $participant = array(
                'first_name' => sanitize_text_field(isset($row['first_name']) ? $row['first_name'] : ''),
                'last_name' => sanitize_text_field(isset($row['last_name']) ? $row['last_name'] : ''),
                'allergies' => sanitize_text_field(isset($row['allergies']) ? $row['allergies'] : ''),
                'level' => isset($row['level']) && array_key_exists((string) $row['level'], Choices::levels()) ? (string) $row['level'] : '',
                'age' => isset($row['age']) && (int) $row['age'] > 0 && (int) $row['age'] <= 120 ? (string) (int) $row['age'] : '',
                'self' => !empty($row['self']) ? '1' : '',
                'save' => !empty($row['save']) && empty($row['self']) ? '1' : '',
            );

            if (self::askContact() !== 'off') {
                $email = sanitize_email(isset($row['email']) ? (string) $row['email'] : '');
                $participant['email'] = $email !== '' && is_email($email) ? strtolower($email) : '';
                $participant['phone'] = Input::phone(isset($row['phone']) ? (string) $row['phone'] : '');
                $participant['email_raw'] = trim(isset($row['email']) ? (string) $row['email'] : '');
            }

            if ($participant['first_name'] === '' || $participant['last_name'] === '') {
                continue;
            }

            $clean[] = $participant;
        }

        return $clean;
    }

    private static function seatsInCart($slot_id, $skip_key = '')
    {
        $seats = 0;

        if (!function_exists('WC') || !WC()->cart) {
            return 0;
        }

        foreach (WC()->cart->get_cart() as $key => $item) {
            if ($key !== $skip_key && !empty($item['passflow_slot_id']) && (int) $item['passflow_slot_id'] === (int) $slot_id) {
                $seats += (int) $item['quantity'];
            }
        }

        return $seats;
    }

    // ------------------------------------------------------------------
    // Panier
    // ------------------------------------------------------------------

    public static function itemData($item_data, $cart_item)
    {
        if (empty($cart_item['passflow_slot_id'])) {
            return $item_data;
        }

        $slot = Slots::find($cart_item['passflow_slot_id']);

        if ($slot) {
            $item_data[] = array('key' => __('Date', 'smbb-passflow'), 'value' => self::when($slot));
        }

        $names = array();

        foreach ((array) $cart_item['passflow_participants'] as $participant) {
            $names[] = trim($participant['first_name'] . ' ' . $participant['last_name']);
        }

        if ($names) {
            $item_data[] = array('key' => _n('Participant', 'Participants', count($names), 'smbb-passflow'), 'value' => implode(', ', $names));
        }

        return $item_data;
    }

    /**
     * @param \WC_Cart $cart
     */
    public static function cartPrices($cart)
    {
        if (is_admin() && !wp_doing_ajax()) {
            return;
        }

        foreach ($cart->get_cart() as $item) {
            if (empty($item['passflow_slot_id'])) {
                continue;
            }

            $slot = Slots::find($item['passflow_slot_id']);

            if ($slot) {
                $item['data']->set_price(\Smbb\Passflow\Support\Prices::forCart(self::price($slot)));

                if (\Smbb\Passflow\Support\Prices::taxesOn()) {
                    $item['data']->set_tax_status('taxable');
                    $item['data']->set_tax_class(\Smbb\Passflow\Support\Prices::taxClass());
                }
            }
        }
    }

    /**
     * Le nombre de places suit les participants saisis : pas de changement dans le panier.
     */
    public static function lockQuantity($html, $cart_item_key, $cart_item = array())
    {
        if (empty($cart_item['passflow_slot_id'])) {
            return $html;
        }

        return '<span class="passflow-qty">' . (int) $cart_item['quantity'] . '</span><input type="hidden" name="cart[' . esc_attr($cart_item_key) . '][qty]" value="' . esc_attr((string) (int) $cart_item['quantity']) . '">';
    }

    public static function quantityEditable($editable, $product = null, $cart_item = array())
    {
        return is_array($cart_item) && !empty($cart_item['passflow_slot_id']) ? false : $editable;
    }

    /**
     * Contrôle au panier et au paiement : date encore en vente, places suffisantes, un participant par place.
     */
    public static function checkCart($email = '')
    {
        if (!function_exists('WC') || !WC()->cart) {
            return true;
        }

        $email = $email !== '' ? $email : self::currentEmail();
        $per_date = array();
        $ok = true;

        foreach (WC()->cart->get_cart() as $item) {
            if (empty($item['passflow_slot_id'])) {
                continue;
            }

            $id = (int) $item['passflow_slot_id'];
            $per_date[$id] = (isset($per_date[$id]) ? $per_date[$id] : 0) + (int) $item['quantity'];

            if (count((array) $item['passflow_participants']) !== (int) $item['quantity']) {
                wc_add_notice(__('Le nombre de places ne correspond plus aux participants : retirez cette ligne et ajoutez-la de nouveau.', 'smbb-passflow'), 'error');
                $ok = false;
            }
        }

        foreach ($per_date as $slot_id => $seats) {
            $error = Capacity::checkSale($slot_id, $seats, $email);

            if ($error !== '') {
                $slot = Slots::find($slot_id);
                $label = $slot ? Lookup::slotLabel($slot) : '';
                wc_add_notice(trim($label . ' : ' . $error, ' :'), 'error');
                $ok = false;
            }
        }

        return $ok;
    }

    /**
     * @param array $data
     * @param \WP_Error $errors
     */
    public static function checkCheckout($data, $errors)
    {
        unset($errors);
        self::checkCart(isset($data['billing_email']) ? strtolower((string) $data['billing_email']) : '');
    }

    // ------------------------------------------------------------------
    // Commande
    // ------------------------------------------------------------------

    /**
     * @param \WC_Order_Item_Product $item
     */
    public static function orderLineItem($item, $cart_item_key, $values, $order)
    {
        unset($cart_item_key);

        if (empty($values['passflow_slot_id'])) {
            return;
        }

        $slot = Slots::find($values['passflow_slot_id']);
        $participants = isset($values['passflow_participants']) ? (array) $values['passflow_participants'] : array();
        $names = array();
        $remember = array();

        // « Mémoriser ce participant » : favoris du client (compte existant ou créé au paiement).
        foreach ($participants as $index => $participant) {
            if (!empty($participant['save']) && empty($participant['self'])) {
                $remember[] = $participant;
            }

            unset($participants[$index]['save'], $participants[$index]['self']);
        }

        $customer_id = $order && method_exists($order, 'get_customer_id') ? (int) $order->get_customer_id() : 0;

        if ($remember && $customer_id) {
            Favorites::remember($customer_id, $remember);
        }

        $participants = array_values($participants);

        foreach ($participants as $participant) {
            $names[] = trim($participant['first_name'] . ' ' . $participant['last_name']);
        }

        $item->add_meta_data(Orders::META_SLOT, (int) $values['passflow_slot_id'], true);
        $item->add_meta_data(Orders::META_PARTICIPANTS, $participants, true);

        if ($slot) {
            $item->add_meta_data(__('Date', 'smbb-passflow'), self::when($slot), true);
        }

        if ($names) {
            $item->add_meta_data(_n('Participant', 'Participants', count($names), 'smbb-passflow'), implode(', ', $names), true);
        }
    }

    public static function hiddenMeta($keys)
    {
        return array_merge((array) $keys, array(Orders::META_SLOT, Orders::META_PARTICIPANTS, Orders::META_BOOKINGS, Orders::META_RELEASED));
    }

    // ------------------------------------------------------------------
    // Liste d'attente
    // ------------------------------------------------------------------

    public static function joinWaitlist()
    {
        global $wpdb;

        $d = 'smbb-passflow';
        $back = wp_get_referer() ?: home_url('/');

        if (!isset($_POST['_passflow_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_passflow_nonce'])), self::WAITLIST_ACTION)) {
            wp_safe_redirect($back);
            exit;
        }

        if (!Settings::waitlistEnabled()) {
            wp_safe_redirect($back);
            exit;
        }

        $slot = isset($_POST['slot_id']) ? Slots::find(absint($_POST['slot_id'])) : null;
        $email = isset($_POST['email']) ? strtolower(sanitize_email(wp_unslash($_POST['email']))) : '';
        $last_name = isset($_POST['last_name']) ? sanitize_text_field(wp_unslash($_POST['last_name'])) : '';
        $first_name = isset($_POST['first_name']) ? sanitize_text_field(wp_unslash($_POST['first_name'])) : '';
        $phone = isset($_POST['phone']) ? Input::phone(sanitize_text_field(wp_unslash($_POST['phone']))) : '';
        $places = isset($_POST['places']) ? min(self::MAX_SEATS, max(1, absint($_POST['places']))) : 1;

        if (!$slot || \Smbb\Passflow\Service\Passes::isPass($slot) || !in_array($slot['status'], array('open', 'full'), true) || Slots::isPast($slot)) {
            self::notice(__("Cette date n'accepte plus de demandes.", $d), 'error');
        } elseif (SaleWindow::pending($slot)) {
            self::notice(SaleWindow::label($slot) . '.', 'error');
        } elseif ($last_name === '' || !is_email($email)) {
            self::notice(__('Indiquez au moins votre nom et une adresse e-mail valide.', $d), 'error');
        } else {
            $table = Lookup::table('passflow_waitlist');
            $exists = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} WHERE deleted_flag = 0 AND slot_id = %d AND LOWER(email) = %s AND status IN ('waiting','notified')",
                (int) $slot['id'],
                $email
            ));

            if ($exists) {
                self::notice(__("Vous êtes déjà sur la liste d'attente de cette date.", $d), 'notice');
            } else {
                $now = current_time('mysql');
                $wpdb->insert($table, array(
                    'slot_id' => (int) $slot['id'],
                    'customer_id' => get_current_user_id() ?: null,
                    'first_name' => $first_name,
                    'last_name' => $last_name,
                    'email' => $email,
                    'phone' => $phone,
                    'places' => $places,
                    'status' => 'waiting',
                    'notes' => __('Demande depuis la boutique.', $d),
                    'creation_date' => $now,
                    'lastupdate_date' => $now,
                ));
                self::notice(__("C'est noté : nous vous prévenons dès qu'une place se libère.", $d), 'success');
            }
        }

        wp_safe_redirect($back);
        exit;
    }

    private static function notice($message, $type)
    {
        if (function_exists('WC') && WC()->session === null && class_exists('WC_Session_Handler')) {
            WC()->session = new \WC_Session_Handler();
            WC()->session->init();
        }

        if (function_exists('wc_add_notice')) {
            wc_add_notice($message, $type);
        }
    }
}
