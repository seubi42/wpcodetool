<?php

namespace Smbb\Tomo\Support;

defined('ABSPATH') || exit;

/**
 * Prix saisis dans Tomo (atelier, créneau) : HT ou TTC selon les réglages, avec un taux de TVA
 * pris dans les classes de taxe WooCommerce (taux de la boutique). Le panier reçoit le prix dans la
 * convention de WooCommerce (« prix saisis TTC » ou non), l'affichage suit « afficher les prix TTC / HT ».
 */
final class Prices
{
    /**
     * « ttc » ou « ht ».
     */
    public static function mode()
    {
        return Settings::get('price_entry') === 'ht' ? 'ht' : 'ttc';
    }

    /**
     * Classe de taxe WooCommerce ('' = standard).
     */
    public static function taxClass()
    {
        $class = (string) Settings::get('price_tax_class');

        return $class === 'standard' ? '' : sanitize_title($class);
    }

    public static function taxesOn()
    {
        return function_exists('wc_tax_enabled') && wc_tax_enabled() && class_exists('\\WC_Tax');
    }

    /**
     * Taux de la boutique pour la classe choisie.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function rates()
    {
        return self::taxesOn() ? \WC_Tax::get_base_tax_rates(self::taxClass()) : array();
    }

    /**
     * Taux total en %, pour l'affichage (« TVA 20 % »).
     */
    public static function rate()
    {
        $total = 0.0;

        foreach (self::rates() as $rate) {
            $total += isset($rate['rate']) ? (float) $rate['rate'] : 0.0;
        }

        return $total;
    }

    public static function ttc($price)
    {
        $price = (float) $price;

        if (self::mode() === 'ttc' || !self::rates()) {
            return $price;
        }

        return $price + array_sum(\WC_Tax::calc_tax($price, self::rates(), false));
    }

    public static function ht($price)
    {
        $price = (float) $price;

        if (self::mode() === 'ht' || !self::rates()) {
            return $price;
        }

        return $price - array_sum(\WC_Tax::calc_tax($price, self::rates(), true));
    }

    /**
     * Prix à donner au panier, dans la convention de saisie de WooCommerce.
     */
    public static function forCart($price)
    {
        if (!self::taxesOn()) {
            return (float) $price;
        }

        return function_exists('wc_prices_include_tax') && wc_prices_include_tax() ? self::ttc($price) : self::ht($price);
    }

    /**
     * Prix montré aux clients, selon « Afficher les prix dans la boutique » de WooCommerce.
     */
    public static function display($price)
    {
        if (!self::taxesOn()) {
            return (float) $price;
        }

        return get_option('woocommerce_tax_display_shop') === 'excl' ? self::ht($price) : self::ttc($price);
    }

    /**
     * Libellé des champs prix : « € TTC » ou « € HT ».
     */
    public static function label()
    {
        $symbol = function_exists('get_woocommerce_currency_symbol') ? html_entity_decode(get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8') : '€';

        return $symbol . ' ' . (self::mode() === 'ht' ? __('HT', 'smbb-tomo') : __('TTC', 'smbb-tomo'));
    }

    /**
     * Aide sous les champs prix : taux appliqué ou rappel que les taxes sont coupées.
     */
    public static function help()
    {
        $d = 'smbb-tomo';

        if (!self::taxesOn()) {
            return __('Taxes désactivées dans WooCommerce : le prix est vendu tel quel.', $d);
        }

        $rate = rtrim(rtrim(number_format_i18n(self::rate(), 2), '0'), ',.');

        return sprintf(__('Saisi %1$s, TVA %2$s %% (réglable dans Réglages › Général › Prix).', $d), self::mode() === 'ht' ? __('HT', $d) : __('TTC', $d), $rate);
    }

    /**
     * Classes de taxe WooCommerce pour le select des réglages (libellé => valeur).
     *
     * @return array<string,string>
     */
    public static function classOptions()
    {
        $out = array(__('Standard', 'smbb-tomo') => 'standard');

        if (class_exists('\\WC_Tax')) {
            foreach (\WC_Tax::get_tax_classes() as $name) {
                $out[$name] = sanitize_title($name);
            }
        }

        return $out;
    }
}
