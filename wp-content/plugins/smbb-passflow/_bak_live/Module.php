<?php

namespace Smbb\Passflow\WooCommerce;

defined('ABSPATH') || exit;

/**
 * Lot 4 : vente des places avec WooCommerce. Chargé seulement si WooCommerce est actif.
 */
final class Module
{
    public static function active()
    {
        return class_exists('WooCommerce') && function_exists('wc_get_product');
    }

    public static function hooks()
    {
        if (!self::active()) {
            return;
        }

        Products::hooks();
        VoucherBalance::hooks();
        PayPage::hooks();

        // Recherche de produits CodeTool (Catalogue sur place) : ouverte aux gestionnaires Passflow.
        add_filter('smbb_wpcodetool_product_lookup_allowed', function ($allowed) {
            return $allowed || \Smbb\Passflow\Support\Roles::canManage();
        });
        Front::hooks();
        Orders::hooks();
        Account::hooks();
        Favorites::hooks();
        Walkins::hooks();
        GiftCards::hooks();
        OrderBox::hooks();
        Invoices::hooks();
        InvoiceScreen::hooks();
    }

    /**
     * Compatibilité avec le stockage des commandes HPOS (à déclarer avant l'init de WooCommerce).
     */
    public static function declareCompatibility()
    {
        if (class_exists('\\Automattic\\WooCommerce\\Utilities\\FeaturesUtil')) {
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', SMBB_PASSFLOW_FILE, true);
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('cart_checkout_blocks', SMBB_PASSFLOW_FILE, true);
        }
    }
}
