<?php

namespace Smbb\Tomo\WooCommerce;

use Smbb\Tomo\Admin\View;
use Smbb\Tomo\Repository\Creneaux;
use Smbb\Tomo\Repository\Envois;
use Smbb\Tomo\Repository\Lookup;
use Smbb\Tomo\Support\Choices;
use Smbb\Tomo\Support\Roles;

defined('ABSPATH') || exit;

/**
 * Encadré « Tomo » sur l'écran d'une commande : ses réservations et les e-mails / SMS envoyés.
 * Tout est lu dans les tables Tomo à l'affichage : rien n'est recopié dans les notes de commande.
 */
final class OrderBox
{
    public static function hooks()
    {
        add_action('add_meta_boxes', array(__CLASS__, 'register'), 30);
    }

    public static function register()
    {
        if (!Roles::canManage()) {
            return;
        }

        $screen = function_exists('wc_get_page_screen_id') ? wc_get_page_screen_id('shop-order') : 'shop_order';

        foreach (array_unique(array($screen, 'shop_order', 'woocommerce_page_wc-orders')) as $id) {
            add_meta_box('tomo-order', __('Tomo : réservations et messages', 'smbb-tomo'), array(__CLASS__, 'render'), $id, 'normal', 'default');
        }
    }

    /**
     * @param \WP_Post|\WC_Order $object
     */
    public static function render($object)
    {
        global $wpdb;

        $d = 'smbb-tomo';
        $order_id = $object instanceof \WC_Order ? (int) $object->get_id() : (int) $object->ID;
        $inscriptions = $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM ' . Lookup::table('tomo_inscription') . ' WHERE deleted_flag = 0 AND order_id = %d ORDER BY id ASC',
            $order_id
        ), ARRAY_A);

        wp_enqueue_style('smbb-tomo-admin', SMBB_TOMO_URL . 'assets/admin.css', array(), SMBB_TOMO_VERSION);

        echo '<div class="tomo-admin tomo-order-box">';

        if (!$inscriptions) {
            echo '<p class="tomo-muted">' . esc_html__("Aucune réservation d'atelier dans cette commande.", $d) . '</p></div>';

            return;
        }

        echo '<table class="widefat striped tomo-table"><thead><tr><th>' . esc_html__('Participant', $d) . '</th><th>' . esc_html__('Date', $d) . '</th><th>' . esc_html__('Statut', $d) . '</th></tr></thead><tbody>';

        foreach ($inscriptions as $row) {
            $creneau = !empty($row['creneau_id']) ? Creneaux::find($row['creneau_id']) : null;

            echo '<tr><td><a href="' . esc_url(View::url('tomo_inscription', array('view' => 'details', 'id' => (int) $row['id']))) . '">' . esc_html(Lookup::personName($row)) . '</a></td>'
                . '<td>' . esc_html($creneau ? Lookup::creneauLabel($creneau) : __('Sans date', $d)) . '</td>'
                . '<td>' . Choices::inscriptionBadge($row) . '</td></tr>'; // phpcs:ignore -- badge échappé.
        }

        echo '</tbody></table>';
        echo '<h4 style="margin:16px 0 8px">' . esc_html__('Messages envoyés', $d) . '</h4>';
        echo View::messagesTable(Envois::forOrder($order_id)); // phpcs:ignore -- HTML échappé.
        echo '</div>';
    }
}
