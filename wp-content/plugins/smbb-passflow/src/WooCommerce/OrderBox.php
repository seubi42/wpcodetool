<?php

namespace Smbb\Passflow\WooCommerce;

use Smbb\Passflow\Admin\View;
use Smbb\Passflow\Repository\Slots;
use Smbb\Passflow\Repository\Deliveries;
use Smbb\Passflow\Repository\Lookup;
use Smbb\Passflow\Service\Tickets;
use Smbb\Passflow\Support\Choices;
use Smbb\Passflow\Support\Roles;

defined('ABSPATH') || exit;

/**
 * Encadré « Passflow » sur l'écran d'une commande : ses réservations et les e-mails / SMS envoyés.
 * Tout est lu dans les tables Passflow à l'affichage : rien n'est recopié dans les notes de commande.
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
            add_meta_box('passflow-order', __('Passflow : réservations et messages', 'smbb-passflow'), array(__CLASS__, 'render'), $id, 'normal', 'default');
        }
    }

    /**
     * @param \WP_Post|\WC_Order $object
     */
    public static function render($object)
    {
        global $wpdb;

        $d = 'smbb-passflow';
        $order_id = $object instanceof \WC_Order ? (int) $object->get_id() : (int) $object->ID;
        $bookings = $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM ' . Lookup::table('passflow_booking') . ' WHERE deleted_flag = 0 AND order_id = %d ORDER BY id ASC',
            $order_id
        ), ARRAY_A);

        wp_enqueue_style('smbb-passflow-admin', SMBB_PASSFLOW_URL . 'assets/admin.css', array(), SMBB_PASSFLOW_VERSION);

        echo '<div class="passflow-admin passflow-order-box">';

        if (!$bookings) {
            echo '<p class="passflow-muted">' . esc_html__("Aucune réservation d'atelier dans cette commande.", $d) . '</p></div>';

            return;
        }

        echo '<table class="widefat striped passflow-table"><thead><tr><th>' . esc_html__('Participant', $d) . '</th><th>' . esc_html__('Date', $d) . '</th><th>' . esc_html__('Statut', $d) . '</th><th>' . esc_html__('Billet', $d) . '</th></tr></thead><tbody>';

        foreach ($bookings as $row) {
            $slot = !empty($row['slot_id']) ? Slots::find($row['slot_id']) : null;

            echo '<tr><td><a href="' . esc_url(View::url('passflow_booking', array('view' => 'details', 'id' => (int) $row['id']))) . '">' . esc_html(Lookup::personName($row)) . '</a></td>'
                . '<td>' . esc_html($slot ? Lookup::slotLabel($slot) : __('Sans date', $d)) . '</td>'
                . '<td>' . Choices::bookingBadge($row) . '</td>'
                . '<td>' . self::ticketButtons($row) . '</td></tr>'; // phpcs:ignore -- badge et boutons échappés.
        }

        echo '</tbody></table>';
        echo '<p class="passflow-muted" style="margin:8px 0 0">' . esc_html__("Les liens du billet s'ouvrent sans connexion : vous pouvez les copier pour les transmettre (clic droit › Copier l'adresse du lien).", $d) . '</p>';
        echo '<h4 style="margin:16px 0 8px">' . esc_html__('Messages envoyés', $d) . '</h4>';
        echo View::messagesTable(Deliveries::forOrder($order_id)); // phpcs:ignore -- HTML échappé.
        echo '</div>';
    }

    /**
     * Boutons du billet d'une réservation : page, PDF, Apple Wallet, Google Wallet (ceux qui sont configurés).
     * Un jour de pass n'a pas de billet propre : il renvoie au billet du pass (même lien).
     *
     * @param array<string,mixed> $row
     */
    private static function ticketButtons(array $row)
    {
        $d = 'smbb-passflow';

        if (!Tickets::available($row)) {
            return '<span class="passflow-muted">—</span>';
        }

        $links = array(
            'page' => array(__('Voir', $d), __('Page du billet : QR code, PDF, Wallets, agenda', $d), ' target="_blank" rel="noopener"'),
            'pdf' => array(__('PDF', $d), __('Télécharger le billet PDF', $d), ''),
        );

        if (Tickets::appleReady()) {
            $links['apple'] = array(__('Apple Wallet', $d), __('Télécharger le .pkpass (à ouvrir sur iPhone ou Mac)', $d), '');
        }

        if (Tickets::googleReady()) {
            $links['google'] = array(__('Google Wallet', $d), __('Ouvrir le lien Google Wallet', $d), ' target="_blank" rel="noopener"');
        }

        $html = '';

        foreach ($links as $format => $link) {
            $badge = in_array($format, array('apple', 'google'), true) ? 'assets/wallet/' . $format . '-fr.svg' : '';

            // Wallets : badge officiel (SVG) quand il est présent, sinon un bouton texte.
            if ($badge !== '' && is_readable(SMBB_PASSFLOW_PATH . $badge)) {
                $html .= '<a href="' . esc_url(Tickets::url($row, $format)) . '" title="' . esc_attr($link[1]) . '"' . $link[2] . ' style="display:inline-block;margin:0 4px 4px 0;vertical-align:middle;line-height:0">'
                    . '<img src="' . esc_url(SMBB_PASSFLOW_URL . $badge) . '" alt="' . esc_attr($link[0]) . '" style="height:30px;width:auto;display:block"></a>';
                continue;
            }

            $html .= '<a class="button button-small" style="margin:0 4px 4px 0;vertical-align:middle" href="' . esc_url(Tickets::url($row, $format)) . '" title="' . esc_attr($link[1]) . '"' . $link[2] . '>' . esc_html($link[0]) . '</a>';
        }

        return $html;
    }
}
