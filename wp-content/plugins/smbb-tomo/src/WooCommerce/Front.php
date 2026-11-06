<?php

namespace Smbb\Tomo\WooCommerce;

use Smbb\Tomo\Repository\Creneaux;
use Smbb\Tomo\Repository\Lookup;
use Smbb\Tomo\Service\Capacity;
use Smbb\Tomo\Support\Choices;
use Smbb\Tomo\Support\Input;
use Smbb\Tomo\Support\Settings;

defined('ABSPATH') || exit;

/**
 * Fiche produit d'un atelier et panier : choix de la date, places, informations des participants.
 */
final class Front
{
    const MAX_SEATS = 10;
    const WAITLIST_ACTION = 'tomo_waitlist_join';

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

        $atelier = Products::atelierForProduct($id);

        return $atelier && $atelier['status'] === 'active' ? $atelier : null;
    }

    /**
     * Dates à venir d'un atelier, avec les places vendables en ligne.
     *
     * @return array<int,array<string,mixed>>
     */
    private static function dates(array $atelier)
    {
        static $cache = array();

        if (isset($cache[$atelier['id']])) {
            return $cache[$atelier['id']];
        }

        $email = self::currentEmail();
        $dates = array();

        foreach (Creneaux::upcoming(0, array('atelier_id' => (int) $atelier['id'], 'statuses' => array('open', 'full'), 'with_passes' => true)) as $creneau) {
            if (Capacity::startsAt($creneau) <= current_time('timestamp')) {
                continue;
            }

            $creneau['for_sale'] = Capacity::forSale($creneau, $email);
            $creneau['unit_price'] = \Smbb\Tomo\Support\Prices::display(self::price($creneau, $atelier));
            $dates[] = $creneau;
        }

        return $cache[$atelier['id']] = $dates;
    }

    public static function price(array $creneau, $atelier = null)
    {
        if ($creneau['price'] !== null && $creneau['price'] !== '') {
            return (float) $creneau['price'];
        }

        $atelier = $atelier ?: Lookup::atelier($creneau['atelier_id']);

        return $atelier && $atelier['default_price'] !== null && $atelier['default_price'] !== '' ? (float) $atelier['default_price'] : 0.0;
    }

    private static function currentEmail()
    {
        $user = wp_get_current_user();

        return $user && $user->exists() ? strtolower((string) $user->user_email) : '';
    }

    /**
     * Ligne lisible d'une date : « samedi 6 décembre 2026, 19h30-21h00 ».
     */
    public static function when(array $creneau)
    {
        if (\Smbb\Tomo\Service\Passes::isPass($creneau)) {
            $parts = array();

            foreach (\Smbb\Tomo\Service\Passes::days($creneau) as $day) {
                $hours = Lookup::slotHours($day);
                $parts[] = date_i18n('l j F', strtotime($day['start_date'] . ' 12:00:00')) . ($hours !== '' ? ' (' . $hours . ')' : '');
            }

            return sprintf(__('Pass %1$s : %2$s', 'smbb-tomo'), count($parts) === 2 ? __('2 jours', 'smbb-tomo') : sprintf(__('%d jours', 'smbb-tomo'), count($parts)), implode(' + ', $parts));
        }

        $date = date_i18n('l j F Y', strtotime($creneau['start_date'] . ' 12:00:00'));
        $hours = Lookup::slotHours($creneau);

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

        if (!$order || !\Smbb\Tomo\Support\Schema::ready()) {
            return;
        }

        $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . Lookup::table('tomo_inscription') . " WHERE deleted_flag = 0 AND order_id = %d AND pass_id = 0 AND status <> 'cancelled' ORDER BY id ASC", (int) $order->get_id()), ARRAY_A);

        if (!$rows) {
            return;
        }

        $d = 'smbb-tomo';
        $docs = \Smbb\Tomo\Service\Documents::enabled();
        $answers = $docs ? \Smbb\Tomo\Service\Documents::answers(array_column($rows, 'id')) : array();
        $items = '';

        foreach ($rows as $row) {
            $creneau = !empty($row['creneau_id']) ? Creneaux::find($row['creneau_id']) : null;
            $atelier = Lookup::atelier($row['atelier_id']);
            $links = array();

            if ($creneau && \Smbb\Tomo\Service\Tickets::available($row) && !Creneaux::isPast($creneau)) {
                $links[] = '<a href="' . esc_url(\Smbb\Tomo\Service\Tickets::url($row, 'pdf')) . '">' . esc_html__('Billet', $d) . '</a>';
                $links[] = '<a href="' . esc_url(\Smbb\Tomo\Service\Tickets::url($row, 'ics')) . '">' . esc_html__('Ajouter à mon agenda', $d) . '</a>';
                $wallets = \Smbb\Tomo\Service\Tickets::walletButtons($row);
            } else {
                $wallets = '';
            }

            if ($docs && \Smbb\Tomo\Service\Documents::missing(isset($answers[(int) $row['id']]) ? $answers[(int) $row['id']] : array())) {
                $links[] = '<a href="' . esc_url(\Smbb\Tomo\Service\Documents::url($row)) . '"><strong>' . esc_html__('Documents à remplir', $d) . '</strong></a>';
            }

            $waiting = $row['status'] === 'pending'
                ? '<br><span class="tomo-order-waiting">' . esc_html__('En attente de validation du paiement : votre place est réservée, le billet vous sera envoyé dès réception.', $d) . '</span>'
                : '';
            $items .= '<li><strong>' . esc_html(Lookup::personName($row)) . '</strong> · ' . esc_html($atelier ? $atelier['name'] : '')
                . $waiting
                . '<br><span class="tomo-order-when">' . esc_html($creneau ? self::when($creneau) : __('Date à choisir avec nous', $d)) . '</span>'
                . ($links ? '<br><span class="tomo-order-links">' . implode(' · ', $links) . '</span>' : '')
                . $wallets
                . '</li>';
        }

        echo '<section class="tomo-order-reservations"><h2>' . esc_html(_n('Votre réservation', 'Vos réservations', count($rows), $d)) . '</h2><ul>' . $items . '</ul></section>'; // phpcs:ignore -- HTML échappé ci-dessus.
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
        $atelier = $product ? self::atelier($product) : null;

        if (!$atelier) {
            return;
        }

        $tz = wp_timezone();
        $events = array();
        $image_id = Products::imageId($atelier);
        $image = $image_id ? (string) wp_get_attachment_image_url($image_id, 'large') : '';
        $shop = (string) \Smbb\Tomo\Support\Settings::get('sender_name') ?: get_bloginfo('name');
        $address = trim(preg_replace('/\s*\n\s*/', ', ', (string) $atelier['address']));

        foreach (array_slice(self::dates($atelier), 0, 12) as $creneau) {
            try {
                $start = new \DateTimeImmutable($creneau['start_date'] . ' ' . ($creneau['start_time'] ?: '00:00:00'), $tz);
                $end = !empty($creneau['end_time']) && $creneau['end_time'] > $creneau['start_time'] ? new \DateTimeImmutable($creneau['start_date'] . ' ' . $creneau['end_time'], $tz) : null;
            } catch (\Exception $e) {
                continue;
            }

            $event = array(
                '@context' => 'https://schema.org',
                '@type' => 'Event',
                'name' => (string) $atelier['name'],
                'description' => wp_trim_words(wp_strip_all_tags(strip_shortcodes(Products::description($atelier, true))), 60),
                'startDate' => Lookup::allDay($creneau) ? $start->format('Y-m-d') : $start->format('c'),
                'eventStatus' => 'https://schema.org/EventScheduled',
                'eventAttendanceMode' => 'https://schema.org/OfflineEventAttendanceMode',
                'location' => array(
                    '@type' => 'Place',
                    'name' => (string) ($atelier['location'] ?: $shop),
                    'address' => $address !== '' ? $address : (string) $atelier['location'],
                ),
                'organizer' => array('@type' => 'Organization', 'name' => $shop, 'url' => home_url('/')),
                'offers' => array(
                    '@type' => 'Offer',
                    'url' => get_permalink($product->get_id()),
                    'price' => number_format(\Smbb\Tomo\Support\Prices::ttc(self::price($creneau, $atelier)), 2, '.', ''),
                    'priceCurrency' => get_woocommerce_currency(),
                    'availability' => (int) $creneau['for_sale'] > 0 ? 'https://schema.org/InStock' : 'https://schema.org/SoldOut',
                    'validFrom' => get_post_time('c', false, $product->get_id()),
                ),
            );

            if ($end) {
                $event['endDate'] = $end->format('c');
            }

            if ($image) {
                $event['image'] = array($image);
            }

            $names = Lookup::intervenantNames($creneau['intervenant_ids']);

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
        $atelier = Products::atelierForProduct(is_object($product) ? $product->get_id() : 0);

        if (!$atelier) {
            return $purchasable;
        }

        if ($atelier['status'] !== 'active') {
            return false;
        }

        foreach (self::dates($atelier) as $creneau) {
            if ($creneau['for_sale'] > 0) {
                return true;
            }
        }

        return false;
    }

    public static function priceHtml($html, $product)
    {
        $atelier = self::atelier($product);

        if (!$atelier) {
            return $html;
        }

        $prices = array();

        foreach (self::dates($atelier) as $creneau) {
            $prices[] = $creneau['unit_price'];
        }

        if (!$prices) {
            return $html;
        }

        $min = min($prices);
        $price = wc_price($min) . ' <small class="tomo-price-unit">' . esc_html__('par personne', 'smbb-tomo') . '</small>';

        return count(array_unique($prices)) > 1 ? sprintf(esc_html__('À partir de %s', 'smbb-tomo'), $price) : $price;
    }

    public static function quantityArgs($args, $product)
    {
        $atelier = is_product() ? self::atelier($product) : null;

        if (!$atelier) {
            return $args;
        }

        $max = 0;

        foreach (self::dates($atelier) as $creneau) {
            $max = max($max, (int) $creneau['for_sale']);
        }

        $args['min_value'] = 1;
        $args['max_value'] = max(1, min(self::MAX_SEATS, $max));
        $args['input_value'] = isset($args['input_value']) ? max(1, (int) $args['input_value']) : 1;

        return $args;
    }

    public static function renderDates()
    {
        global $product;

        $atelier = $product ? self::atelier($product) : null;

        if (!$atelier) {
            return;
        }

        $d = 'smbb-tomo';
        // Date choisie : formulaire renvoyé, ou lien « Réserver » d'un shortcode (?tomo_creneau=ID).
        $selected = isset($_POST['tomo_creneau_id']) ? absint($_POST['tomo_creneau_id']) : (isset($_GET['tomo_creneau']) ? absint($_GET['tomo_creneau']) : 0);
        $first_free = 0;

        echo '<fieldset class="tomo-dates" data-tomo-dates>';
        echo '<legend>' . esc_html__('Choisissez votre date', $d) . '</legend>';

        foreach (self::dates($atelier) as $creneau) {
            $free = (int) $creneau['for_sale'];
            $id = (int) $creneau['id'];

            if ($free > 0 && !$first_free) {
                $first_free = $id;
            }

            $checked = $selected ? $selected === $id : ($free > 0 && $first_free === $id);
            $label = $free > 0
                ? sprintf(_n('%d place restante', '%d places restantes', $free, $d), $free)
                : __('Complet', $d);

            echo '<label class="tomo-date' . ($free > 0 ? '' : ' is-full') . '">'
                . '<input type="radio" name="tomo_creneau_id" value="' . esc_attr((string) $id) . '" data-seats="' . esc_attr((string) min(self::MAX_SEATS, $free)) . '" data-price="' . esc_attr(wc_format_decimal($creneau['unit_price'], wc_get_price_decimals())) . '"'
                . ($checked ? ' checked' : '') . ($free > 0 ? ' required' : ' disabled') . '>'
                . '<span class="tomo-date-when">' . esc_html(self::when($creneau)) . '</span>'
                . '<span class="tomo-date-meta">' . esc_html($label) . ' · ' . wp_kses_post(wc_price($creneau['unit_price'])) . '</span>'
                . '</label>';
        }

        echo '</fieldset>';
        echo '<p class="tomo-seats-label">' . esc_html__('Nombre de places', $d) . '</p>';
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

        $d = 'smbb-tomo';
        $posted = isset($_POST['tomo_participants']) && is_array($_POST['tomo_participants']) ? array_values(wp_unslash($_POST['tomo_participants'])) : array();
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

        echo '<div class="tomo-participants" data-tomo-participants>';
        echo '<p class="tomo-participants-title">' . esc_html__('Qui participe ?', $d) . '</p>';
        echo '<div class="tomo-participant-list" data-tomo-list>';

        foreach ($posted as $i => $values) {
            echo self::participantBlock($i, is_array($values) ? $values : array(), (bool) $me); // phpcs:ignore -- échappé dans participantBlock.
        }

        echo '</div>';
        echo '<div class="tomo-participant-add">'
            . '<button type="button" class="tomo-add" data-tomo-add><span aria-hidden="true">+</span> ' . esc_html__('Ajouter un participant', $d) . '</button>'
            . '<button type="button" class="tomo-fav-open" data-tomo-fav-open' . ($favorites ? '' : ' hidden') . '><span aria-hidden="true">★</span> ' . esc_html__('Ajouter mes participants favoris', $d) . ' <span class="tomo-fav-count" data-tomo-fav-count>' . count($favorites) . '</span></button>'
            . '</div>';
        echo '<p class="tomo-seats-warning" data-tomo-seats-warning hidden></p>';
        echo '</div>';
        echo '<template data-tomo-participant-template>' . self::participantBlock('__i__', array(), (bool) $me) . '</template>'; // phpcs:ignore

        if ($logged) {
            echo '<div class="tomo-modal" data-tomo-fav-modal hidden role="dialog" aria-modal="true" aria-labelledby="tomo-fav-title">'
                . '<div class="tomo-modal-box">'
                . '<div class="tomo-modal-h"><h3 id="tomo-fav-title">' . esc_html__('Mes participants favoris', $d) . '</h3>'
                . '<button type="button" class="tomo-modal-x" data-tomo-fav-close aria-label="' . esc_attr__('Fermer', $d) . '">×</button></div>'
                . '<p class="tomo-modal-lead">' . esc_html__('Cochez les personnes à ajouter à cette réservation.', $d) . '</p>'
                . '<input type="search" class="tomo-fav-search" data-tomo-fav-search placeholder="' . esc_attr__('Rechercher un nom ou un e-mail', $d) . '" autocomplete="off">'
                . '<div class="tomo-fav-pick" data-tomo-fav-list></div>'
                . '<div class="tomo-modal-actions"><button type="button" class="tomo-link" data-tomo-fav-close>' . esc_html__('Annuler', $d) . '</button>'
                . '<button type="button" class="button alt" data-tomo-fav-add disabled>' . esc_html__('Ajouter', $d) . '</button></div>'
                . '</div></div>';
        }

        wp_add_inline_script('smbb-tomo-front', 'window.tomoParticipants=' . wp_json_encode(array(
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
        $d = 'smbb-tomo';
        $prefix = 'tomo_participants[' . $index . ']';
        $number = is_numeric($index) ? (int) $index + 1 : '__n__';
        $self = !empty($values['self']);
        $save = !empty($values['save']);

        $html = '<div class="tomo-participant' . ($self ? ' is-self' : '') . '" data-tomo-participant>'
            . '<div class="tomo-participant-h">'
            . '<span class="tomo-participant-no" data-tomo-no>' . esc_html(is_numeric($number) ? sprintf('%02d', $number) : $number) . '</span>'
            . '<p class="tomo-participant-title" data-tomo-title>' . esc_html(sprintf(__('Participant %s', $d), $number)) . '</p>'
            . ($can_be_me ? '<button type="button" class="tomo-me' . ($self ? ' is-on' : '') . '" data-tomo-me aria-pressed="' . ($self ? 'true' : 'false') . '">' . esc_html__("C'est moi", $d) . '</button>' : '')
            . '<button type="button" class="tomo-remove" data-tomo-remove>' . esc_html__('Retirer', $d) . '</button>'
            . '</div>'
            . '<input type="hidden" name="' . esc_attr($prefix . '[self]') . '" value="' . ($self ? '1' : '0') . '" data-tomo-self>'
            . Favorites::fields($prefix, $values);

        if (self::askContact() === 'optional' && $number === 1) {
            $html .= '<p class="tomo-participant-help">' . esc_html__('Le billet et les rappels sont envoyés à chaque participant. Laissé vide, ils arrivent sur vos propres coordonnées.', $d) . '</p>';
        }

        if (Favorites::canRemember()) {
            $html .= '<label class="tomo-remember" data-tomo-remember><input type="checkbox" name="' . esc_attr($prefix . '[save]') . '" value="1"' . checked($save, true, false) . '> '
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

        $atelier = $product ? self::atelier($product) : null;

        if (!$atelier || $product->is_purchasable()) {
            return;
        }

        echo '<p class="tomo-closed">' . esc_html__('Toutes les dates sont complètes pour le moment.', 'smbb-tomo') . '</p>';
        self::renderWaitlist();
    }

    /**
     * Formulaire de liste d'attente, pour les dates complètes.
     */
    public static function renderWaitlist()
    {
        global $product;
        static $done = false;

        $atelier = $product ? self::atelier($product) : null;

        if (!$atelier || $done || !Settings::waitlistEnabled()) {
            return;
        }

        // Pas de liste d'attente sur un pass : on s'inscrit sur les jours.
        $full = array_filter(self::dates($atelier), function ($creneau) {
            return (int) $creneau['for_sale'] < 1 && !\Smbb\Tomo\Service\Passes::isPass($creneau);
        });

        if (!$full) {
            return;
        }

        $done = true;
        $d = 'smbb-tomo';
        $user = wp_get_current_user();
        $logged = $user && $user->exists();

        echo '<details id="tomo-waitlist" class="tomo-waitlist"' . ($product->is_purchasable() ? '' : ' open') . '>';
        echo '<summary>' . esc_html__("Une date est complète ? Inscrivez-vous sur la liste d'attente", $d) . '</summary>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="tomo-waitlist-form">';
        echo '<input type="hidden" name="action" value="' . esc_attr(self::WAITLIST_ACTION) . '">';
        echo '<input type="hidden" name="product_id" value="' . esc_attr((string) $product->get_id()) . '">';
        wp_nonce_field(self::WAITLIST_ACTION, '_tomo_nonce');
        echo '<p>' . esc_html__("Si une place se libère, nous vous prévenons par e-mail et SMS, dans l'ordre d'arrivée.", $d) . '</p>';
        echo '<label class="tomo-full">' . esc_html__('Date', $d) . '<select name="creneau_id" required>';

        foreach ($full as $creneau) {
            echo '<option value="' . esc_attr((string) $creneau['id']) . '">' . esc_html(self::when($creneau)) . '</option>';
        }

        echo '</select></label>';
        echo '<div class="tomo-row">'
            . '<label>' . esc_html__('Prénom', $d) . '<input type="text" name="first_name" value="' . esc_attr($logged ? $user->first_name : '') . '" autocomplete="given-name"></label>'
            . '<label>' . esc_html__('Nom', $d) . ' <span class="required">*</span><input type="text" name="last_name" value="' . esc_attr($logged ? $user->last_name : '') . '" required autocomplete="family-name"></label>'
            . '</div><div class="tomo-row">'
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
        return $product && self::atelier($product) ? __('Choisir une date', 'smbb-tomo') : $text;
    }

    public static function loopAjax($supports, $feature, $product)
    {
        return $feature === 'ajax_add_to_cart' && $product && self::atelier($product) ? false : $supports;
    }

    public static function assets()
    {
        if ((function_exists('is_account_page') && is_account_page()) || (function_exists('is_order_received_page') && is_order_received_page())) {
            wp_enqueue_style('smbb-tomo-front', SMBB_TOMO_URL . 'assets/front.css', array(), SMBB_TOMO_VERSION);

            return;
        }

        if (!function_exists('is_product') || !is_product()) {
            return;
        }

        $product = wc_get_product(get_queried_object_id());

        if (!$product || !self::atelier($product)) {
            return;
        }

        wp_enqueue_style('smbb-tomo-front', SMBB_TOMO_URL . 'assets/front.css', array(), SMBB_TOMO_VERSION);
        wp_enqueue_script('smbb-tomo-front', SMBB_TOMO_URL . 'assets/front.js', array(), SMBB_TOMO_VERSION, true);
    }

    // ------------------------------------------------------------------
    // Ajout au panier
    // ------------------------------------------------------------------

    public static function validateAdd($passed, $product_id, $quantity)
    {
        $atelier = self::atelier((int) $product_id);

        if (!$atelier || !$passed) {
            return $passed;
        }

        $d = 'smbb-tomo';
        $creneau_id = isset($_POST['tomo_creneau_id']) ? absint($_POST['tomo_creneau_id']) : 0;
        $creneau = $creneau_id ? Creneaux::find($creneau_id) : null;

        if (!$creneau || (int) $creneau['atelier_id'] !== (int) $atelier['id']) {
            wc_add_notice(__('Choisissez une date.', $d), 'error');

            return false;
        }

        // Le nombre de places est celui des cartes participant remplies.
        $participants = self::postedParticipants();
        $quantity = max(1, count($participants));
        $error = Capacity::checkSale($creneau_id, $quantity + self::seatsInCart($creneau_id), self::currentEmail());

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
        if (!self::atelier((int) $product_id) || empty($_POST['tomo_creneau_id'])) {
            return $data;
        }

        $data['tomo_creneau_id'] = absint($_POST['tomo_creneau_id']);
        $data['tomo_participants'] = array_map(function ($participant) {
            unset($participant['email_raw']);

            return $participant;
        }, array_slice(self::postedParticipants(), 0, self::MAX_SEATS));
        $data['tomo_key'] = md5(wp_json_encode($data['tomo_participants']) . microtime());

        return $data;
    }

    /**
     * Une place par carte participant : la quantité postée ne compte pas.
     */
    public static function cartQuantity($quantity, $product_id)
    {
        if (!self::atelier((int) $product_id) || empty($_POST['tomo_creneau_id'])) {
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
        $raw = isset($_POST['tomo_participants']) && is_array($_POST['tomo_participants']) ? wp_unslash($_POST['tomo_participants']) : array();
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

    private static function seatsInCart($creneau_id, $skip_key = '')
    {
        $seats = 0;

        if (!function_exists('WC') || !WC()->cart) {
            return 0;
        }

        foreach (WC()->cart->get_cart() as $key => $item) {
            if ($key !== $skip_key && !empty($item['tomo_creneau_id']) && (int) $item['tomo_creneau_id'] === (int) $creneau_id) {
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
        if (empty($cart_item['tomo_creneau_id'])) {
            return $item_data;
        }

        $creneau = Creneaux::find($cart_item['tomo_creneau_id']);

        if ($creneau) {
            $item_data[] = array('key' => __('Date', 'smbb-tomo'), 'value' => self::when($creneau));
        }

        $names = array();

        foreach ((array) $cart_item['tomo_participants'] as $participant) {
            $names[] = trim($participant['first_name'] . ' ' . $participant['last_name']);
        }

        if ($names) {
            $item_data[] = array('key' => _n('Participant', 'Participants', count($names), 'smbb-tomo'), 'value' => implode(', ', $names));
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
            if (empty($item['tomo_creneau_id'])) {
                continue;
            }

            $creneau = Creneaux::find($item['tomo_creneau_id']);

            if ($creneau) {
                $item['data']->set_price(\Smbb\Tomo\Support\Prices::forCart(self::price($creneau)));

                if (\Smbb\Tomo\Support\Prices::taxesOn()) {
                    $item['data']->set_tax_status('taxable');
                    $item['data']->set_tax_class(\Smbb\Tomo\Support\Prices::taxClass());
                }
            }
        }
    }

    /**
     * Le nombre de places suit les participants saisis : pas de changement dans le panier.
     */
    public static function lockQuantity($html, $cart_item_key, $cart_item = array())
    {
        if (empty($cart_item['tomo_creneau_id'])) {
            return $html;
        }

        return '<span class="tomo-qty">' . (int) $cart_item['quantity'] . '</span><input type="hidden" name="cart[' . esc_attr($cart_item_key) . '][qty]" value="' . esc_attr((string) (int) $cart_item['quantity']) . '">';
    }

    public static function quantityEditable($editable, $product = null, $cart_item = array())
    {
        return is_array($cart_item) && !empty($cart_item['tomo_creneau_id']) ? false : $editable;
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
            if (empty($item['tomo_creneau_id'])) {
                continue;
            }

            $id = (int) $item['tomo_creneau_id'];
            $per_date[$id] = (isset($per_date[$id]) ? $per_date[$id] : 0) + (int) $item['quantity'];

            if (count((array) $item['tomo_participants']) !== (int) $item['quantity']) {
                wc_add_notice(__('Le nombre de places ne correspond plus aux participants : retirez cette ligne et ajoutez-la de nouveau.', 'smbb-tomo'), 'error');
                $ok = false;
            }
        }

        foreach ($per_date as $creneau_id => $seats) {
            $error = Capacity::checkSale($creneau_id, $seats, $email);

            if ($error !== '') {
                $creneau = Creneaux::find($creneau_id);
                $label = $creneau ? Lookup::creneauLabel($creneau) : '';
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

        if (empty($values['tomo_creneau_id'])) {
            return;
        }

        $creneau = Creneaux::find($values['tomo_creneau_id']);
        $participants = isset($values['tomo_participants']) ? (array) $values['tomo_participants'] : array();
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

        $item->add_meta_data(Orders::META_CRENEAU, (int) $values['tomo_creneau_id'], true);
        $item->add_meta_data(Orders::META_PARTICIPANTS, $participants, true);

        if ($creneau) {
            $item->add_meta_data(__('Date', 'smbb-tomo'), self::when($creneau), true);
        }

        if ($names) {
            $item->add_meta_data(_n('Participant', 'Participants', count($names), 'smbb-tomo'), implode(', ', $names), true);
        }
    }

    public static function hiddenMeta($keys)
    {
        return array_merge((array) $keys, array(Orders::META_CRENEAU, Orders::META_PARTICIPANTS, Orders::META_INSCRIPTIONS, Orders::META_RELEASED));
    }

    // ------------------------------------------------------------------
    // Liste d'attente
    // ------------------------------------------------------------------

    public static function joinWaitlist()
    {
        global $wpdb;

        $d = 'smbb-tomo';
        $back = wp_get_referer() ?: home_url('/');

        if (!isset($_POST['_tomo_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_tomo_nonce'])), self::WAITLIST_ACTION)) {
            wp_safe_redirect($back);
            exit;
        }

        if (!Settings::waitlistEnabled()) {
            wp_safe_redirect($back);
            exit;
        }

        $creneau = isset($_POST['creneau_id']) ? Creneaux::find(absint($_POST['creneau_id'])) : null;
        $email = isset($_POST['email']) ? strtolower(sanitize_email(wp_unslash($_POST['email']))) : '';
        $last_name = isset($_POST['last_name']) ? sanitize_text_field(wp_unslash($_POST['last_name'])) : '';
        $first_name = isset($_POST['first_name']) ? sanitize_text_field(wp_unslash($_POST['first_name'])) : '';
        $phone = isset($_POST['phone']) ? Input::phone(sanitize_text_field(wp_unslash($_POST['phone']))) : '';
        $places = isset($_POST['places']) ? min(self::MAX_SEATS, max(1, absint($_POST['places']))) : 1;

        if (!$creneau || \Smbb\Tomo\Service\Passes::isPass($creneau) || !in_array($creneau['status'], array('open', 'full'), true) || Creneaux::isPast($creneau)) {
            self::notice(__("Cette date n'accepte plus de demandes.", $d), 'error');
        } elseif ($last_name === '' || !is_email($email)) {
            self::notice(__('Indiquez au moins votre nom et une adresse e-mail valide.', $d), 'error');
        } else {
            $table = Lookup::table('tomo_attente');
            $exists = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} WHERE deleted_flag = 0 AND creneau_id = %d AND LOWER(email) = %s AND status IN ('waiting','notified')",
                (int) $creneau['id'],
                $email
            ));

            if ($exists) {
                self::notice(__("Vous êtes déjà sur la liste d'attente de cette date.", $d), 'notice');
            } else {
                $now = current_time('mysql');
                $wpdb->insert($table, array(
                    'creneau_id' => (int) $creneau['id'],
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
