<?php

namespace Smbb\Passflow\Service;

use Smbb\Passflow\Admin\ConfigTransfer;
use Smbb\Passflow\Repository\Lookup;
use Smbb\Passflow\Support\Settings;

defined('ABSPATH') || exit;

/**
 * Ce que fait l'assistant de démarrage (codetool/wizards/passflow.json) en dehors des formulaires :
 * réglages WooCommerce de base, TVA alignée sur Passflow, profils d'activité, première offre.
 *
 * Mémoire de l'assistant côté Passflow : option smbb_passflow_onboarding
 * (profil choisi, extras du profil, id de la première offre).
 */
final class Onboarding
{
    const OPTION = 'smbb_passflow_onboarding';
    const WIZARD = 'passflow';

    /** Taux proposés : valeur postée => [taux %, nom de la classe WooCommerce ('' = standard)]. */
    const RATES = array(
        '20' => array(20.0, ''),
        '10' => array(10.0, 'TVA 10 %'),
        '5.5' => array(5.5, 'TVA 5,5 %'),
        '2.1' => array(2.1, 'TVA 2,1 %'),
    );

    /** Options WooCommerce qu'un profil (extras.json › woocommerce) a le droit de poser. */
    const WC_ALLOWED = array(
        'woocommerce_tax_display_shop', 'woocommerce_tax_display_cart', 'woocommerce_price_display_suffix',
        'woocommerce_enable_reviews', 'woocommerce_enable_coupons', 'woocommerce_calc_discounts_sequentially',
        'woocommerce_hold_stock_minutes', 'woocommerce_manage_stock',
    );

    public static function memory($key = null, $default = null)
    {
        $value = get_option(self::OPTION, array());
        $value = is_array($value) ? $value : array();

        if ($key === null) {
            return $value;
        }

        return array_key_exists($key, $value) ? $value[$key] : $default;
    }

    public static function remember($key, $data)
    {
        $value = self::memory();
        $value[$key] = $data;
        update_option(self::OPTION, $value, false);
    }

    /**
     * Enregistre des réglages Passflow comme l'écran Réglages : fusion dans l'option, hooks
     * (nettoyage, validation, chiffrement) compris.
     *
     * @return true|string
     */
    public static function saveSettings(array $data)
    {
        $resource = self::resource('passflow-settings');

        if (!$resource) {
            $current = get_option(Settings::OPTION, array());
            update_option(Settings::OPTION, array_merge(is_array($current) ? $current : array(), $data), false);
            Settings::flush();

            return true;
        }

        $service = new \Smbb\WpCodeTool\Resource\ResourceMutationService(new \Smbb\WpCodeTool\Resource\ResourceRuntime());
        $result = $service->saveOption($resource, $data, array('merge_current' => true, 'action' => 'wizard'));
        Settings::flush();

        if (empty($result['success'])) {
            $errors = isset($result['errors']) && is_array($result['errors']) ? array_map(function ($error) {
                return is_array($error) ? implode(' ', $error) : (string) $error;
            }, $result['errors']) : array();

            return $errors ? implode(' ', $errors) : (isset($result['message']) ? (string) $result['message'] : __('Réglages non enregistrés.', 'smbb-passflow'));
        }

        return true;
    }

    /**
     * Ligne enregistrée par CodeTool, hooks du modèle compris (produit, jauge, rôle…).
     *
     * @return int id, 0 en cas d'échec
     */
    public static function saveRow($model, array $data, $id = null)
    {
        $resource = self::resource($model);

        if (!$resource) {
            return 0;
        }

        $service = new \Smbb\WpCodeTool\Resource\ResourceMutationService(new \Smbb\WpCodeTool\Resource\ResourceRuntime());
        $result = $service->saveTable($resource, $data, $id !== null ? array('id' => $id) : array());
        Lookup::flush();

        return !empty($result['success']) ? (int) $result['id'] : 0;
    }

    /**
     * @return \Smbb\WpCodeTool\Resource\ResourceDefinition|null
     */
    public static function resource($model)
    {
        static $cache = array();

        if (isset($cache[$model])) {
            return $cache[$model];
        }

        if (!class_exists('\\Smbb\\WpCodeTool\\Resource\\ResourceDefinition')) {
            return null;
        }

        $file = SMBB_PASSFLOW_PATH . 'codetool/models/' . $model . '.json';
        $data = is_readable($file) ? json_decode((string) file_get_contents($file), true) : null;

        return $cache[$model] = is_array($data) ? new \Smbb\WpCodeTool\Resource\ResourceDefinition($data, rtrim(SMBB_PASSFLOW_PATH, '/\\'), $file) : null;
    }

    // ------------------------------------------------------------------
    // Étape 1 : WooCommerce
    // ------------------------------------------------------------------

    /**
     * Réglages de base d'une boutique d'ateliers en France, posés une fois WooCommerce actif.
     * Ne remplace que les valeurs d'origine de WooCommerce (pays US, devise USD…).
     */
    public static function wooDefaults($fake_card = '')
    {
        if (!\Smbb\Passflow\WooCommerce\Module::active()) {
            return;
        }

        $country = (string) get_option('woocommerce_default_country', '');

        if ($country === '' || strpos($country, 'US') === 0) {
            update_option('woocommerce_default_country', 'FR');
        }

        if (in_array((string) get_option('woocommerce_currency', ''), array('', 'USD'), true)) {
            update_option('woocommerce_currency', 'EUR');
            update_option('woocommerce_currency_pos', 'right_space');
            update_option('woocommerce_price_decimal_sep', ',');
            update_option('woocommerce_price_thousand_sep', ' ');
        }

        // Billets, factures et « Mes ateliers » vivent dans Mon compte : un compte à chaque commande.
        update_option('woocommerce_enable_guest_checkout', 'no');
        update_option('woocommerce_enable_checkout_login_reminder', 'yes');
        update_option('woocommerce_enable_signup_and_login_from_checkout', 'yes');
        update_option('woocommerce_enable_myaccount_registration', 'yes');
        update_option('woocommerce_registration_generate_password', 'yes');

        if (class_exists('\\WC_Install') && (wc_get_page_id('checkout') < 1 || wc_get_page_id('myaccount') < 1 || wc_get_page_id('cart') < 1)) {
            \WC_Install::create_pages();
        }

        self::fakeCard($fake_card);
    }

    /**
     * Carte bancaire fictive de CodeTool (7 jours) : 'admins' (administrateurs seulement),
     * 'all' (tous les clients, pour une démo publique) ou 'none' (coupée). '' = choix par défaut.
     */
    public static function fakeCard($choice)
    {
        if (!class_exists('\\Smbb\\WpCodeTool\\Woo\\FakeCard')) {
            return;
        }

        $choice = in_array($choice, array('admins', 'all', 'none'), true) ? $choice : self::fakeCardChoice();

        if ($choice === 'none') {
            if (\Smbb\WpCodeTool\Woo\FakeCard::enabled()) {
                \Smbb\WpCodeTool\Woo\FakeCard::save(array_merge(\Smbb\WpCodeTool\Woo\FakeCard::settings(), array('fake_card' => 0)));
            }

            return;
        }

        $current = \Smbb\WpCodeTool\Woo\FakeCard::settings();
        $keep = \Smbb\WpCodeTool\Woo\FakeCard::enabled();

        \Smbb\WpCodeTool\Woo\FakeCard::save(array(
            'fake_card' => 1,
            'fake_card_for' => $keep && !empty($current['fake_card_for']) ? $current['fake_card_for'] : '7d',
            'admins_only' => $choice === 'admins' ? 1 : 0,
            'scenario' => $keep && !empty($current['scenario']) ? $current['scenario'] : 'frictionless',
        ));
    }

    /**
     * Choix proposé à l'étape Boutique : l'état actuel de la carte fictive, sinon « admins »
     * tant qu'aucun vrai moyen de paiement n'est activé.
     */
    public static function fakeCardChoice()
    {
        if (!class_exists('\\Smbb\\WpCodeTool\\Woo\\FakeCard')) {
            return 'none';
        }

        if (\Smbb\WpCodeTool\Woo\FakeCard::enabled()) {
            $settings = \Smbb\WpCodeTool\Woo\FakeCard::settings();

            return empty($settings['admins_only']) ? 'all' : 'admins';
        }

        return self::hasGateway() ? 'none' : 'admins';
    }

    public static function hasGateway()
    {
        if (!function_exists('WC') || !WC()->payment_gateways()) {
            return false;
        }

        foreach (WC()->payment_gateways()->payment_gateways() as $gateway) {
            if ($gateway->enabled === 'yes') {
                return true;
            }
        }

        return false;
    }

    /**
     * État de la boutique pour l'étape 1 : libellé => fait ?
     *
     * @return array<string,bool>
     */
    public static function wooChecklist()
    {
        $d = 'smbb-passflow';

        if (!\Smbb\Passflow\WooCommerce\Module::active()) {
            return array();
        }

        return array(
            __('Pays de la boutique : France', $d) => strpos((string) get_option('woocommerce_default_country'), 'FR') === 0,
            __('Devise : euro', $d) => get_option('woocommerce_currency') === 'EUR',
            __('Pages Panier, Commande et Mon compte', $d) => wc_get_page_id('checkout') > 0 && wc_get_page_id('myaccount') > 0 && wc_get_page_id('cart') > 0,
            __('Compte client créé à la commande', $d) => get_option('woocommerce_enable_guest_checkout') === 'no',
            __('Un moyen de paiement actif (carte fictive pour la démo)', $d) => self::hasGateway() || (class_exists('\\Smbb\\WpCodeTool\\Woo\\FakeCard') && \Smbb\WpCodeTool\Woo\FakeCard::enabled()),
        );
    }

    // ------------------------------------------------------------------
    // Étape 2 : TVA
    // ------------------------------------------------------------------

    /**
     * Taux choisi dans l'assistant ('' si la classe enregistrée n'est pas l'une des nôtres).
     */
    public static function currentRate()
    {
        $class = (string) Settings::get('price_tax_class');

        foreach (self::RATES as $key => $rate) {
            if (($rate[1] === '' && $class === 'standard') || ($rate[1] !== '' && sanitize_title($rate[1]) === $class)) {
                return (string) $key;
            }
        }

        return '';
    }

    /**
     * Aligne WooCommerce sur Passflow : taxes activées ou non, prix saisis HT ou TTC, affichage,
     * et taux français dans la classe choisie (créés s'il n'y en a aucun). $rate : clé de RATES ou ''.
     */
    public static function syncTaxes($rate = '')
    {
        if (!\Smbb\Passflow\WooCommerce\Module::active()) {
            return;
        }

        if (Settings::get('invoice_vat_mode') === 'franchise') {
            update_option('woocommerce_calc_taxes', 'no');

            return;
        }

        $ttc = Settings::get('price_entry') !== 'ht';
        update_option('woocommerce_calc_taxes', 'yes');
        update_option('woocommerce_prices_include_tax', $ttc ? 'yes' : 'no');
        update_option('woocommerce_tax_display_shop', $ttc ? 'incl' : 'excl');
        update_option('woocommerce_tax_display_cart', $ttc ? 'incl' : 'excl');
        update_option('woocommerce_tax_based_on', 'base');
        update_option('woocommerce_tax_round_at_subtotal', 'no');

        if (!class_exists('\\WC_Tax')) {
            return;
        }

        // Taux standard français : toujours présent, c'est celui de la plupart des prestations.
        self::ensureRate('', 20.0);

        if ($rate !== '' && isset(self::RATES[$rate])) {
            list($percent, $class_name) = self::RATES[$rate];
            $slug = '';

            if ($class_name !== '') {
                $slug = sanitize_title($class_name);

                if (!in_array($slug, \WC_Tax::get_tax_class_slugs(), true)) {
                    $created = \WC_Tax::create_tax_class($class_name, $slug);
                    $slug = is_array($created) && isset($created['slug']) ? $created['slug'] : $slug;
                }
            }

            self::ensureRate($slug, $percent);
            self::saveSettings(array('price_tax_class' => $slug === '' ? 'standard' : $slug));
        }
    }

    private static function ensureRate($class, $percent)
    {
        foreach (\WC_Tax::get_rates_for_tax_class($class) as $existing) {
            if (isset($existing->tax_rate_country) && in_array($existing->tax_rate_country, array('FR', ''), true)) {
                return;
            }
        }

        \WC_Tax::_insert_tax_rate(array(
            'tax_rate_country' => 'FR',
            'tax_rate_state' => '',
            'tax_rate' => number_format((float) $percent, 4, '.', ''),
            'tax_rate_name' => 'TVA',
            'tax_rate_priority' => 1,
            'tax_rate_compound' => 0,
            'tax_rate_shipping' => 1,
            'tax_rate_order' => 0,
            'tax_rate_class' => $class,
        ));
    }

    /**
     * Adresse de la boutique WooCommerce reprise de l'adresse des factures, si elle est vide.
     */
    public static function syncStoreAddress()
    {
        if (!\Smbb\Passflow\WooCommerce\Module::active() || trim((string) get_option('woocommerce_store_address')) !== '') {
            return;
        }

        $lines = array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', (string) Settings::get('invoice_address')))));

        if (!$lines) {
            return;
        }

        $last = array_pop($lines);

        if (preg_match('/^(\d{5})\s+(.+)$/u', $last, $m)) {
            update_option('woocommerce_store_postcode', $m[1]);
            update_option('woocommerce_store_city', $m[2]);
        } else {
            $lines[] = $last;
        }

        if ($lines) {
            update_option('woocommerce_store_address', array_shift($lines));
            update_option('woocommerce_store_address_2', implode(', ', $lines));
        }
    }

    // ------------------------------------------------------------------
    // Étape 3 : profils
    // ------------------------------------------------------------------

    /**
     * Applique un profil (profiles/<clé>/profile.json au format de l'export des réglages,
     * + extras.json) : réglages, documents ajoutés sans toucher aux existants, apparence CodeTool,
     * options WooCommerce autorisées. Les textes de messages ne sont jamais touchés.
     *
     * @param array{key:string,profile:array,extras:array} $profile
     *
     * @return true|string
     */
    public static function applyProfile(array $profile)
    {
        $data = $profile['profile'];

        // Un profil ne règle jamais les textes des messages ni les clés secrètes.
        if (isset($data['settings']) && is_array($data['settings'])) {
            foreach (array_keys($data['settings']) as $key) {
                if (strpos((string) $key, 'tpl_') === 0 || in_array($key, ConfigTransfer::SECRETS, true)) {
                    unset($data['settings'][$key]);
                }
            }
        }

        $parsed = ConfigTransfer::parse(wp_json_encode($data));

        if (is_wp_error($parsed)) {
            return $parsed->get_error_message();
        }

        ConfigTransfer::apply($parsed, false);

        if (isset($parsed['documents']['documents']) && is_array($parsed['documents']['documents'])) {
            self::mergeDocuments($parsed['documents']['documents']);
        }

        $extras = isset($profile['extras']) && is_array($profile['extras']) ? $profile['extras'] : array();

        if (!empty($extras['codetool_appearance']) && is_array($extras['codetool_appearance']) && class_exists('\\Smbb\\WpCodeTool\\Admin\\Skin')) {
            $look = $extras['codetool_appearance'];
            $current = \Smbb\WpCodeTool\Admin\Skin::settings();
            $themes = \Smbb\WpCodeTool\Admin\Skin::themes();
            $theme = isset($look['theme']) && isset($themes[$look['theme']]) ? $look['theme'] : $current['theme'];
            unset($look['theme']);
            \Smbb\WpCodeTool\Admin\Skin::saveSettings($theme, $current['accent'], $look);
        }

        if (!empty($extras['woocommerce']) && is_array($extras['woocommerce']) && \Smbb\Passflow\WooCommerce\Module::active()) {
            foreach ($extras['woocommerce'] as $option => $value) {
                if (in_array($option, self::WC_ALLOWED, true) && is_scalar($value)) {
                    update_option($option, (string) $value);
                }
            }
        }

        self::remember('profile', $profile['key']);
        self::remember('extras', $extras);

        return true;
    }

    /**
     * Documents du profil ajoutés à la suite (même identifiant = déjà là : on n'y touche pas).
     */
    private static function mergeDocuments(array $rows)
    {
        $current = get_option(Settings::DOCUMENTS_OPTION, array());
        $current = is_array($current) ? $current : array();
        $list = isset($current['documents']) && is_array($current['documents']) ? array_values($current['documents']) : array();
        $keys = array();

        foreach ($list as $row) {
            if (is_array($row)) {
                $keys[] = !empty($row['key']) ? (string) $row['key'] : sanitize_title(isset($row['title']) ? $row['title'] : '');
            }
        }

        foreach ($rows as $row) {
            if (!is_array($row) || empty($row['title'])) {
                continue;
            }

            $key = !empty($row['key']) ? (string) $row['key'] : sanitize_title($row['title']);

            if (in_array($key, $keys, true)) {
                continue;
            }

            $row['key'] = $key;
            $list[] = $row;
            $keys[] = $key;
        }

        $current['documents'] = $list;
        update_option(Settings::DOCUMENTS_OPTION, $current, false);
        Settings::flush();
    }

    // ------------------------------------------------------------------
    // Étape 4 : première offre
    // ------------------------------------------------------------------

    /**
     * Valeurs proposées pour la première offre : celles du profil (extras.json › offer).
     *
     * @return array<string,mixed>
     */
    public static function offerDefaults()
    {
        $extras = self::memory('extras', array());
        $offer = is_array($extras) && isset($extras['offer']) && is_array($extras['offer']) ? $extras['offer'] : array();

        return array_merge(array(
            'name' => '',
            'location' => '',
            'duration_minutes' => 120,
            'default_price' => '',
            'default_capacity' => 10,
            'color' => (string) Settings::get('ticket_color'),
            'description' => '',
        ), $offer);
    }

    /**
     * Première offre déjà créée par l'assistant (ligne d'atelier), ou null.
     */
    public static function offer()
    {
        $id = (int) self::memory('offer_id', 0);

        return $id ? Lookup::atelier($id) : null;
    }

    /**
     * Crée (ou met à jour) la première offre, puis son produit WooCommerce publié.
     *
     * @return true|string
     */
    public static function saveOffer(array $input)
    {
        $d = 'smbb-passflow';
        $name = trim(sanitize_text_field(isset($input['offer_name']) ? $input['offer_name'] : ''));

        if ($name === '') {
            return __('Donnez un nom à votre offre.', $d);
        }

        $color = sanitize_hex_color(isset($input['offer_color']) ? (string) $input['offer_color'] : '');
        $row = array(
            'name' => $name,
            'location' => sanitize_text_field(isset($input['offer_location']) ? $input['offer_location'] : ''),
            'duration_minutes' => max(0, (int) (isset($input['offer_duration']) ? $input['offer_duration'] : 0)),
            'default_price' => round((float) str_replace(',', '.', (string) (isset($input['offer_price']) ? $input['offer_price'] : 0)), 2),
            'default_capacity' => max(1, (int) (isset($input['offer_capacity']) ? $input['offer_capacity'] : 1)),
            'color' => $color ?: '#8c3c34',
            'description' => sanitize_textarea_field(isset($input['offer_description']) ? $input['offer_description'] : ''),
            'image_id' => absint(isset($input['offer_image_id']) ? $input['offer_image_id'] : 0) ?: null,
            'status' => 'active',
        );

        $existing = self::offer();
        $id = self::saveRow('passflow-activity', $row, $existing ? (int) $existing['id'] : null);

        if (!$id) {
            return __("L'offre n'a pas pu être enregistrée.", $d);
        }

        self::remember('offer_id', $id);
        self::ensureProduct($id);

        return true;
    }

    /**
     * Produit WooCommerce publié pour une fiche (créé s'il manque).
     */
    public static function ensureProduct($activity_id)
    {
        if (!\Smbb\Passflow\WooCommerce\Module::active()) {
            return 0;
        }

        $activity = Lookup::atelier($activity_id);

        if (!$activity) {
            return 0;
        }

        if (empty($activity['product_id']) || !get_post((int) $activity['product_id'])) {
            $created = \Smbb\Passflow\WooCommerce\Products::create($activity);

            if (empty($created['success'])) {
                return 0;
            }

            Lookup::flush();
            $activity = Lookup::atelier($activity_id);
        }

        $product_id = (int) $activity['product_id'];

        if ($product_id && get_post_status($product_id) !== 'publish') {
            wp_update_post(array('ID' => $product_id, 'post_status' => 'publish'));
        }

        \Smbb\Passflow\WooCommerce\Products::syncActivity($activity);

        return $product_id;
    }
}
