<?php

namespace Smbb\Passflow\WooCommerce;

use Smbb\Passflow\Repository\Slots;
use Smbb\Passflow\Repository\Lookup;
use Smbb\Passflow\Service\Documents;
use Smbb\Passflow\Service\Tickets;
use Smbb\Passflow\Support\Choices;

defined('ABSPATH') || exit;

/**
 * Onglet « Mes ateliers » dans Mon compte : les réservations du client, à venir et passées.
 */
final class Account
{
    const ENDPOINT = 'mes-ateliers';
    const SPACE_ITEM = 'passflow-portal-host';
    const AVAILABILITIES_ITEM = 'passflow-availabilities-host';

    public static function hooks()
    {
        add_action('init', array(__CLASS__, 'endpoint'));
        add_filter('woocommerce_get_query_vars', array(__CLASS__, 'queryVars'));
        add_filter('woocommerce_account_menu_items', array(__CLASS__, 'menu'));
        add_filter('woocommerce_get_endpoint_url', array(__CLASS__, 'spaceLink'), 10, 2);
        add_filter('woocommerce_endpoint_' . self::ENDPOINT . '_title', array(__CLASS__, 'title'));
        add_action('woocommerce_account_' . self::ENDPOINT . '_endpoint', array(__CLASS__, 'render'));
    }

    /**
     * Déclare l'adresse /mon-compte/mes-ateliers/ ; les règles d'URL sont régénérées une fois par version.
     */
    public static function endpoint()
    {
        add_rewrite_endpoint(self::ENDPOINT, EP_ROOT | EP_PAGES);

        if (get_option('smbb_passflow_rewrite_version') !== SMBB_PASSFLOW_VERSION) {
            flush_rewrite_rules(false);
            update_option('smbb_passflow_rewrite_version', SMBB_PASSFLOW_VERSION, false);
        }
    }

    public static function queryVars($vars)
    {
        $vars[self::ENDPOINT] = self::ENDPOINT;

        return $vars;
    }

    public static function menu($items)
    {
        $result = array();

        foreach ((array) $items as $key => $label) {
            $result[$key] = $label;

            if ($key === 'orders') {
                $result[self::ENDPOINT] = __('Mes ateliers', 'smbb-passflow');
            }
        }

        if (!isset($result[self::ENDPOINT])) {
            $result = array_merge(array(self::ENDPOINT => __('Mes ateliers', 'smbb-passflow')), $result);
        }

        // Entrée distincte pour les intervenants : leur agenda et l'écran d'appel sont dans l'espace intervenant.
        if (current_user_can(\Smbb\Passflow\Support\Roles::CAP_HOST)) {
            $with_space = array();

            foreach ($result as $key => $label) {
                $with_space[$key] = $label;

                if ($key === self::ENDPOINT) {
                    $with_space[self::SPACE_ITEM] = __('Espace intervenant', 'smbb-passflow');

                    // Raccourci vers ses disponibilités (compte rattaché à une fiche intervenant).
                    if (\Smbb\Passflow\Repository\Lookup::hostForUser(get_current_user_id())) {
                        $with_space[self::AVAILABILITIES_ITEM] = __('Mes disponibilités', 'smbb-passflow');
                    }
                }
            }

            $result = $with_space;
        }

        return $result;
    }

    /**
     * L'entrée « Espace intervenant » mène à l'administration, pas à une page de Mon compte.
     */
    public static function spaceLink($url, $endpoint)
    {
        if ($endpoint === self::AVAILABILITIES_ITEM) {
            return \Smbb\Passflow\Support\Roles::spaceUrl(array('dispos' => 1));
        }

        return $endpoint === self::SPACE_ITEM ? \Smbb\Passflow\Support\Roles::spaceUrl() : $url;
    }

    public static function title()
    {
        return __('Mes ateliers', 'smbb-passflow');
    }

    public static function render()
    {
        $d = 'smbb-passflow';
        $rows = self::bookings(get_current_user_id());
        $upcoming = array();
        $past = array();

        foreach ($rows as $row) {
            if ($row['creneau'] && Slots::isPast($row['creneau'])) {
                $past[] = $row;
            } elseif ($row['status'] !== 'cancelled' || ($row['creneau'] && !Slots::isPast($row['creneau']))) {
                $upcoming[] = $row;
            }
        }

        if (current_user_can(\Smbb\Passflow\Support\Roles::CAP_HOST)) {
            echo '<p class="passflow-account-help">' . esc_html__('Ici, vos réservations en tant que participant. Les ateliers que vous animez et l\'appel sont dans votre espace :', $d)
                . ' <a href="' . esc_url(\Smbb\Passflow\Support\Roles::spaceUrl()) . '">' . esc_html__('Espace intervenant', $d) . '</a></p>';
        }

        if (!$upcoming && !$past) {
            echo '<p>' . esc_html__("Vous n'avez pas encore réservé d'atelier.", $d) . '</p>';
            echo '<p><a class="button" href="' . esc_url(wc_get_page_permalink('shop')) . '">' . esc_html__('Voir les ateliers', $d) . '</a></p>';

            return;
        }

        echo '<h3>' . esc_html__('À venir', $d) . '</h3>';

        if ($upcoming) {
            self::table($upcoming);
        } else {
            echo '<p>' . esc_html__('Aucun atelier à venir.', $d) . '</p>';
        }

        echo '<p class="passflow-account-help">' . esc_html__('Besoin de changer de date ou d\'annuler ? Contactez-nous, nous trouverons une solution.', $d) . '</p>';

        if ($past) {
            echo '<details class="passflow-account-past"><summary>' . esc_html(sprintf(_n('%d atelier passé', '%d ateliers passés', count($past), $d), count($past))) . '</summary>';
            self::table($past);
            echo '</details>';
        }
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     */
    private static function table(array $rows)
    {
        $d = 'smbb-passflow';

        $docs = Documents::enabled();
        $answers = $docs ? Documents::answers(array_column($rows, 'id')) : array();

        echo '<table class="shop_table shop_table_responsive passflow-account-table"><thead><tr>'
            . '<th>' . esc_html__('Atelier', $d) . '</th>'
            . '<th>' . esc_html__('Date', $d) . '</th>'
            . '<th>' . esc_html__('Participant', $d) . '</th>'
            . '<th>' . esc_html__('Statut', $d) . '</th>'
            . '<th>' . esc_html__('Commande', $d) . '</th>'
            . '<th>' . esc_html__('Billet', $d) . '</th>'
            . ($docs ? '<th>' . esc_html__('Documents', $d) . '</th>' : '')
            . '</tr></thead><tbody>';
        $apple = Tickets::appleReady();
        $google = Tickets::googleReady();

        foreach ($rows as $row) {
            $order = !empty($row['order_id']) && function_exists('wc_get_order') ? wc_get_order((int) $row['order_id']) : null;
            $status = $row['status'] === 'pending' && empty($row['slot_id'])
                ? __('En attente d\'une nouvelle date', $d)
                : ($row['status'] === 'pending' ? __('En attente de paiement', $d) : Choices::label('bookingStatuses', $row['status']));

            echo '<tr>'
                . '<td data-title="' . esc_attr__('Atelier', $d) . '">' . esc_html(Lookup::activityName($row['activity_id'])) . '</td>'
                . '<td data-title="' . esc_attr__('Date', $d) . '">' . esc_html($row['creneau'] ? Lookup::slotWhen($row['creneau']) : '—') . '</td>'
                . '<td data-title="' . esc_attr__('Participant', $d) . '">' . esc_html(Lookup::personName($row)) . '</td>'
                . '<td data-title="' . esc_attr__('Statut', $d) . '"><span class="passflow-status passflow-status--' . esc_attr($row['status']) . '">' . esc_html($status) . '</span></td>'
                . '<td data-title="' . esc_attr__('Commande', $d) . '">' . ($order ? '<a href="' . esc_url($order->get_view_order_url()) . '">' . esc_html('n° ' . $order->get_order_number()) . '</a>' : '—') . '</td>'
                . '<td data-title="' . esc_attr__('Billet', $d) . '">' . self::ticketLinks($row, $apple, $google) . '</td>'
                . ($docs ? '<td data-title="' . esc_attr__('Documents', $d) . '">' . self::documentsCell($row, isset($answers[(int) $row['id']]) ? $answers[(int) $row['id']] : array()) . '</td>' : '')
                . '</tr>';
        }

        echo '</tbody></table>';
    }

    /**
     * « À remplir » (lien) tant qu'un document manque, sinon « Complets » avec le lien pour revoir les réponses.
     */
    private static function documentsCell(array $row, array $answers)
    {
        $d = 'smbb-passflow';

        if (!in_array($row['status'], array('pending', 'confirmed'), true) || ($row['creneau'] && Slots::isPast($row['creneau']))) {
            return $answers ? esc_html__('Complets', $d) : '—';
        }

        $missing = Documents::missing($answers);

        return $missing
            ? '<a class="button passflow-docs-link" href="' . esc_url(Documents::url($row)) . '">' . esc_html(sprintf(_n('%d document à remplir', '%d documents à remplir', count($missing), $d), count($missing))) . '</a>'
            : '<a href="' . esc_url(Documents::url($row)) . '">' . esc_html__('Complets', $d) . '</a>';
    }

    /**
     * PDF, Apple Wallet, Google Wallet : seulement pour une réservation à venir, placée sur une date.
     */
    private static function ticketLinks(array $row, $apple, $google)
    {
        $d = 'smbb-passflow';

        if ($row['status'] === 'pending' && !empty($row['slot_id'])) {
            return '<span class="passflow-order-waiting">' . esc_html__('Après validation du paiement', $d) . '</span>';
        }

        if (!Tickets::available($row) || ($row['creneau'] && Slots::isPast($row['creneau']))) {
            return '—';
        }

        unset($apple, $google);
        $links = array(
            '<a class="passflow-ticket-link" href="' . esc_url(Tickets::url($row, 'pdf')) . '">' . esc_html__('PDF', $d) . '</a>',
            '<a class="passflow-ticket-link" href="' . esc_url(Tickets::url($row, 'ics')) . '">' . esc_html__('Ajouter à mon agenda', $d) . '</a>',
        );

        // Donner sa place : nouveau billet à la personne, celui-ci désactivé.
        if (\Smbb\Passflow\Service\Transfers::blocked($row) === '') {
            $links[] = '<a class="passflow-ticket-link" href="' . esc_url(Tickets::url($row, 'page') . '#transfert') . '">' . esc_html__('Transférer', $d) . '</a>';
        }

        return implode(' · ', $links) . Tickets::walletButtons($row);
    }

    /**
     * Réservations du client : rattachées à son compte ou faites avec son adresse e-mail.
     *
     * @return array<int,array<string,mixed>>
     */
    private static function bookings($user_id)
    {
        global $wpdb;

        $user = get_userdata((int) $user_id);

        if (!$user) {
            return array();
        }

        $table = Lookup::table('passflow_booking');
        $slots = Lookup::table('passflow_slot');
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT i.* FROM {$table} i LEFT JOIN {$slots} c ON c.id = i.slot_id"
            . ' WHERE i.deleted_flag = 0 AND i.pass_id = 0 AND (i.customer_id = %d OR LOWER(i.email) = %s)'
            . ' ORDER BY c.start_date IS NULL DESC, c.start_date ASC, c.start_time ASC, i.id ASC LIMIT 200',
            (int) $user->ID,
            strtolower($user->user_email)
        ), ARRAY_A);

        foreach ($rows as &$row) {
            $row['creneau'] = !empty($row['slot_id']) ? Slots::find($row['slot_id']) : null;
        }

        unset($row);

        // Ne pas montrer les réservations libérées d'une commande jamais payée.
        return array_values(array_filter($rows, function ($row) {
            if ($row['status'] !== 'cancelled' || empty($row['order_id']) || !function_exists('wc_get_order')) {
                return true;
            }

            $order = wc_get_order((int) $row['order_id']);

            return $order && $order->is_paid();
        }));
    }
}
