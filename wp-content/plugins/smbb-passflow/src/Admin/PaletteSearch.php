<?php

namespace Smbb\Passflow\Admin;

use Smbb\Passflow\Repository\Lookup;
use Smbb\Passflow\Repository\Slots;
use Smbb\Passflow\Service\Documents;
use Smbb\Passflow\Service\Passes;
use Smbb\Passflow\Service\Tickets;
use Smbb\Passflow\Support\Choices;
use Smbb\Passflow\Support\Roles;

defined('ABSPATH') || exit;

/**
 * Résultats Passflow dans la recherche Ctrl K de CodeTool (Smbb\WpCodeTool\Admin\PaletteSearch) :
 * on tape « Dupont », « 06 12 », « marie@ » ou « #1234 » et on tombe sur l'inscription,
 * avec ses actions rapides (pointer, billet, commande, documents à signer, fiche du créneau…).
 *
 * - Participants : nom, prénom, e-mail, téléphone (chiffres seuls, avec ou sans 0 / +33).
 * - Commandes : numéro de commande WooCommerce (« 1234 », « #1234 », « n° 1234 »).
 * - Créneaux : nom de l'atelier → ses prochaines dates (appel, caisse, inscrits, communication).
 * Réservé à la gestion de Passflow (les intervenants n'ont que leur espace).
 */
final class PaletteSearch
{
    const D = 'smbb-passflow';

    public static function hooks()
    {
        add_filter('smbb_wpcodetool_palette_providers', array(__CLASS__, 'providers'));
        add_filter('smbb_wpcodetool_palette_placeholder', array(__CLASS__, 'placeholder'));
    }

    public static function providers($providers)
    {
        $providers['passflow_orders'] = array(
            'label' => __('Commandes', self::D),
            'icon' => 'cart',
            'capability' => Roles::CAP_MANAGE,
            'priority' => 5,
            'min' => 1,
            'callback' => array(__CLASS__, 'orders'),
        );
        $providers['passflow_people'] = array(
            'label' => __('Participants', self::D),
            'icon' => 'admin-users',
            'capability' => Roles::CAP_MANAGE,
            'priority' => 10,
            'min' => 2,
            'callback' => array(__CLASS__, 'people'),
        );
        $providers['passflow_slots'] = array(
            'label' => __('Prochains créneaux', self::D),
            'icon' => 'calendar-alt',
            'capability' => Roles::CAP_MANAGE,
            'priority' => 20,
            'min' => 3,
            'callback' => array(__CLASS__, 'slots'),
        );

        return $providers;
    }

    public static function placeholder($text)
    {
        return Roles::canManage() ? __('Chercher un participant, une commande, un atelier, une page…', self::D) : $text;
    }

    /* ---------------------------------------------------------------- Participants */

    /**
     * @return array<int,array<string,mixed>>
     */
    public static function people($query, $limit)
    {
        global $wpdb;

        $words = array_filter(explode(' ', (string) $query), 'strlen');
        $where = array();
        $args = array();

        foreach ($words as $word) {
            $like = '%' . $wpdb->esc_like($word) . '%';
            $clause = '(b.first_name LIKE %s OR b.last_name LIKE %s OR b.email LIKE %s';
            array_push($args, $like, $like, $like);

            // Téléphone : on compare les chiffres seuls, sans le 0 ni l'indicatif (« 06 12 34 » = « +33 6 12 34 »).
            $digits = preg_replace('/\D+/', '', $word);

            if (strlen($digits) >= 3 && strlen($digits) === strlen(preg_replace('/[\s.+()-]/', '', $word))) {
                $tail = ltrim(preg_replace('/^(00)?33/', '', $digits), '0');
                $clause .= " OR REPLACE(REPLACE(REPLACE(REPLACE(b.phone, ' ', ''), '.', ''), '-', ''), '+', '') LIKE %s";
                $args[] = '%' . $wpdb->esc_like($tail !== '' ? $tail : $digits) . '%';
            }

            $where[] = $clause . ')';
        }

        if (!$where) {
            return array();
        }

        $today = current_time('Y-m-d');
        $sql = 'SELECT b.* FROM ' . Lookup::table('passflow_booking') . ' b'
            . ' LEFT JOIN ' . Lookup::table('passflow_slot') . ' s ON s.id = b.slot_id AND s.deleted_flag = 0'
            . ' WHERE b.deleted_flag = 0 AND b.pass_id = 0 AND ' . implode(' AND ', $where)
            // Les réservations actives d'abord, les prochaines dates en tête, puis les plus récentes.
            . " ORDER BY (b.status = 'cancelled') ASC, (s.start_date >= %s) DESC,"
            . ' CASE WHEN s.start_date >= %s THEN s.start_date END ASC, s.start_date DESC, b.id DESC'
            . ' LIMIT %d';
        array_push($args, $today, $today, (int) $limit);

        $rows = $wpdb->get_results($wpdb->prepare($sql, $args), ARRAY_A); // phpcs:ignore -- requête préparée.

        if (!is_array($rows) || !$rows) {
            return array();
        }

        $slots = self::slotsById(array_column($rows, 'slot_id'));
        $answers = Documents::enabled() ? Documents::answers(array_map('intval', array_column($rows, 'id'))) : array();
        $out = array();

        foreach ($rows as $booking) {
            $slot = isset($slots[(int) $booking['slot_id']]) ? $slots[(int) $booking['slot_id']] : null;
            $out[] = self::personItem($booking, $slot, isset($answers[(int) $booking['id']]) ? $answers[(int) $booking['id']] : array());
        }

        return $out;
    }

    /**
     * @param array<string,mixed>      $booking
     * @param array<string,mixed>|null $slot
     * @param array<string,mixed>      $answers réponses aux documents
     * @return array<string,mixed>
     */
    private static function personItem(array $booking, $slot, array $answers)
    {
        $id = (int) $booking['id'];
        $name = Lookup::personName($booking);
        $where = $slot ? Lookup::slotLabel($slot) : trim(Lookup::activityName($booking['activity_id']) . ' — ' . __('Date à définir', self::D), ' —');
        $contact = (string) $booking['email'] !== '' ? (string) $booking['email'] : (string) $booking['phone'];
        $status = self::statusChip((string) $booking['status']);
        $actions = array();

        // 1. Le geste du jour : marquer payée, pointer présent (ou annuler le pointage).
        if ($slot && $slot['status'] !== 'cancelled' && !Passes::isPass($slot) && in_array($booking['status'], Choices::seatStatuses(), true)) {
            if ($booking['status'] === 'pending') {
                $actions[] = array('label' => __('Marquer payée', self::D), 'icon' => 'yes-alt', 'url' => View::actionUrl(Actions::PRESENCE, array('id' => $id, 'status' => 'confirmed')));
            } elseif (!empty($slot['start_date']) && $slot['start_date'] <= current_time('Y-m-d')) {
                $actions[] = $booking['status'] === 'present'
                    ? array('label' => __('Annuler le pointage', self::D), 'icon' => 'undo', 'url' => View::actionUrl(Actions::PRESENCE, array('id' => $id, 'status' => 'confirmed')))
                    : array('label' => __('Pointer présent', self::D), 'icon' => 'groups', 'url' => View::actionUrl(Actions::PRESENCE, array('id' => $id, 'status' => 'present')));
            }
        }

        // 2. Le billet (page personnelle : PDF, Wallet, agenda).
        if (Tickets::available($booking)) {
            $actions[] = array('label' => __('Billet', self::D), 'icon' => 'tickets-alt', 'url' => Tickets::url($booking, 'page'), 'target' => '_blank');
        }

        // 3. La commande WooCommerce.
        if (!empty($booking['order_id'])) {
            $order = function_exists('wc_get_order') ? wc_get_order((int) $booking['order_id']) : null;
            $actions[] = array(
                'label' => sprintf(__('Commande n° %s', self::D), $order ? $order->get_order_number() : (int) $booking['order_id']),
                'icon' => 'cart',
                'url' => $order ? $order->get_edit_order_url() : admin_url('post.php?post=' . (int) $booking['order_id'] . '&action=edit'),
            );
        }

        // 4. Documents à signer : on les ouvre sur place, sur l'appareil de l'équipe.
        $docs = Documents::enabled() ? count(Documents::all()) : 0;
        $done_docs = $docs ? $docs - count(Documents::missing($answers)) : 0;

        if ($docs && $done_docs < $docs && in_array($booking['status'], array('pending', 'confirmed', 'present'), true)) {
            $actions[] = array(
                'label' => sprintf(__('Faire signer (%1$d/%2$d)', self::D), $done_docs, $docs),
                'icon' => 'edit-page',
                'url' => Documents::url($booking, true),
                'target' => '_blank',
            );
        }

        // 5. La fiche du créneau, puis le contact.
        if ($slot) {
            $actions[] = array('label' => __('Fiche du créneau', self::D), 'icon' => 'calendar-alt', 'url' => View::url('passflow_slot', array('view' => 'details', 'id' => (int) $slot['id'])));
        }

        if ((string) $booking['phone'] !== '' && wp_is_mobile()) {
            $actions[] = array('label' => __('Appeler', self::D), 'icon' => 'phone', 'url' => 'tel:' . preg_replace('/[^\d+]/', '', $booking['phone']));
        }

        if ((string) $booking['email'] !== '' && is_email($booking['email'])) {
            $actions[] = array('label' => __('E-mail', self::D), 'icon' => 'email-alt', 'url' => 'mailto:' . $booking['email']);
        }

        $sub = array_filter(array($where, $contact));

        if ($docs && $done_docs >= $docs) {
            $sub[] = __('documents ✓', self::D);
        }

        return array(
            'label' => $name !== '' ? $name : ($contact !== '' ? $contact : sprintf(__('Inscription n° %d', self::D), $id)),
            'sub' => implode(' · ', $sub),
            'meta' => $status[0],
            'tone' => $status[1],
            'icon' => $booking['status'] === 'present' ? 'groups' : 'admin-users',
            'url' => View::url('passflow_booking', array('view' => 'details', 'id' => $id)),
            'actions' => $actions,
        );
    }

    /**
     * Pastille courte : [libellé, ton].
     *
     * @return array{0:string,1:string}
     */
    private static function statusChip($status)
    {
        $chips = array(
            'pending' => array(__('À confirmer', self::D), 'warn'),
            'confirmed' => array(__('Payée', self::D), 'ok'),
            'present' => array(__('Présent', self::D), 'info'),
            'absent' => array(__('Absent', self::D), 'danger'),
            'cancelled' => array(__('Annulée', self::D), 'off'),
        );

        return isset($chips[$status]) ? $chips[$status] : array($status, '');
    }

    /* ---------------------------------------------------------------- Commandes */

    /**
     * @return array<int,array<string,mixed>>
     */
    public static function orders($query, $limit)
    {
        global $wpdb;

        if (!preg_match('/^(?:#|n°\s*|no\s*|commande\s*)?(\d{1,10})$/iu', (string) $query, $m) || !function_exists('wc_get_order')) {
            return array();
        }

        $order = (int) $m[1] > 0 ? wc_get_order((int) $m[1]) : null;

        if (!$order || !is_a($order, 'WC_Order') || is_a($order, 'WC_Order_Refund')) {
            return array();
        }

        $bookings = $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM ' . Lookup::table('passflow_booking') . ' WHERE deleted_flag = 0 AND pass_id = 0 AND order_id = %d ORDER BY id ASC LIMIT 12',
            (int) $order->get_id()
        ), ARRAY_A);
        $bookings = is_array($bookings) ? $bookings : array();
        $actions = array();

        // Actions rapides : la fiche de chaque participant de la commande.
        foreach ($bookings as $booking) {
            $actions[] = array(
                'label' => Lookup::personName($booking) !== '' ? Lookup::personName($booking) : sprintf(__('Inscription n° %d', self::D), (int) $booking['id']),
                'icon' => 'admin-users',
                'url' => View::url('passflow_booking', array('view' => 'details', 'id' => (int) $booking['id'])),
            );
        }

        $status = $order->get_status();
        $tones = array('completed' => 'ok', 'processing' => 'ok', 'on-hold' => 'warn', 'pending' => 'warn', 'cancelled' => 'off', 'refunded' => 'off', 'failed' => 'danger');
        $created = $order->get_date_created();
        $sub = array_filter(array(
            trim($order->get_formatted_billing_full_name()),
            html_entity_decode(wp_strip_all_tags(wc_price($order->get_total(), array('currency' => $order->get_currency()))), ENT_QUOTES, 'UTF-8'),
            $created ? date_i18n('j M Y', $created->getTimestamp()) : '',
            $bookings ? sprintf(_n('%d inscription', '%d inscriptions', count($bookings), self::D), count($bookings)) : '',
        ));

        return array(array(
            'label' => sprintf(__('Commande n° %s', self::D), $order->get_order_number()),
            'sub' => implode(' · ', $sub),
            'meta' => wc_get_order_status_name($status),
            'tone' => isset($tones[$status]) ? $tones[$status] : '',
            'icon' => 'cart',
            'url' => $order->get_edit_order_url(),
            'actions' => $actions,
        ));
    }

    /* ---------------------------------------------------------------- Créneaux */

    /**
     * @return array<int,array<string,mixed>>
     */
    public static function slots($query, $limit)
    {
        global $wpdb;

        $needle = self::fold($query);
        $activity_ids = array();

        foreach (Lookup::ateliers() as $id => $activity) {
            if (strpos(self::fold($activity['name']), $needle) !== false) {
                $activity_ids[] = (int) $id;
            }
        }

        if (!$activity_ids) {
            return array();
        }

        $today = current_time('Y-m-d');
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM ' . Lookup::table('passflow_slot') . ' WHERE deleted_flag = 0 AND activity_id IN (' . implode(',', $activity_ids) . ')'
            . " AND status <> 'cancelled' AND start_date >= %s ORDER BY start_date ASC, start_time ASC LIMIT %d",
            $today,
            min(5, (int) $limit)
        ), ARRAY_A);

        if (!is_array($rows) || !$rows) {
            return array();
        }

        $out = array();

        foreach (Slots::withStats($rows) as $slot) {
            $id = (int) $slot['id'];
            $booked = isset($slot['booked']) ? (int) $slot['booked'] : 0;
            $remaining = isset($slot['remaining']) ? (int) $slot['remaining'] : 0;
            $is_today = $slot['start_date'] === $today;
            $actions = array();

            if ($is_today) {
                $actions[] = array('label' => __("Faire l'appel", self::D), 'icon' => 'groups', 'url' => View::url('passflow_portal', array('creneau' => $id)));
                $actions[] = array('label' => __('Caisse', self::D), 'icon' => 'money-alt', 'url' => Cashdesk::url($id));
            }

            $actions[] = array('label' => __('Inscrits', self::D), 'icon' => 'list-view', 'url' => View::filtered('passflow_booking', 'slot_id', $id));
            $actions[] = array('label' => __('Communication', self::D), 'icon' => 'megaphone', 'url' => View::url('passflow_slot', array('view' => 'communication', 'id' => $id)));

            if ($is_today) {
                $actions[] = array('label' => __('Bilan', self::D), 'icon' => 'chart-bar', 'url' => View::url('passflow_slot', array('view' => 'report', 'id' => $id)));
            }

            $hosts = Lookup::hostNames($slot['host_ids']);

            $out[] = array(
                'label' => Lookup::slotLabel($slot),
                'sub' => implode(' · ', array_filter(array(
                    sprintf(__('%1$d inscrits sur %2$d places', self::D), $booked, (int) $slot['capacity']),
                    $hosts,
                ))),
                'meta' => $is_today ? __("Aujourd'hui", self::D) : ($remaining < 1 ? __('Complet', self::D) : sprintf(_n('%d place libre', '%d places libres', $remaining, self::D), $remaining)),
                'tone' => $is_today ? 'info' : ($remaining < 1 ? 'danger' : ($remaining <= 2 ? 'warn' : 'ok')),
                'icon' => 'calendar-alt',
                'url' => View::url('passflow_slot', array('view' => 'details', 'id' => $id)),
                'actions' => $actions,
            );
        }

        return $out;
    }

    /* ---------------------------------------------------------------- Outils */

    /**
     * @param array<int,mixed> $ids
     * @return array<int,array<string,mixed>>
     */
    private static function slotsById(array $ids)
    {
        global $wpdb;

        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));

        if (!$ids) {
            return array();
        }

        $rows = $wpdb->get_results('SELECT * FROM ' . Lookup::table('passflow_slot') . ' WHERE deleted_flag = 0 AND id IN (' . implode(',', $ids) . ')', ARRAY_A);
        $out = array();

        foreach (is_array($rows) ? $rows : array() as $row) {
            $out[(int) $row['id']] = $row;
        }

        return $out;
    }

    private static function fold($text)
    {
        return strtolower(remove_accents((string) $text));
    }
}
