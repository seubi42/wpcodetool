<?php

namespace Smbb\Tomo\Service;

use Smbb\Tomo\Repository\Creneaux;
use Smbb\Tomo\Repository\Inscriptions;
use Smbb\Tomo\Repository\Lookup;
use Smbb\Tomo\Support\Choices;
use Smbb\Tomo\Support\Input;

defined('ABSPATH') || exit;

/**
 * Opérations métier sur les inscriptions et les créneaux.
 *
 * Chaque méthode renvoie array('success' => bool, 'message' => string) et laisse une trace
 * dans l'historique. Les actions WordPress déclenchées (smbb_tomo_*) serviront aux
 * notifications du lot 2.
 */
final class Booking
{
    /**
     * Annule une inscription, ou la reporte ($postpone = true) : elle repasse « à confirmer »
     * sans date et attend un nouveau créneau dans le stock.
     */
    public static function cancel($inscription_id, $postpone = false, $reason = '')
    {
        $inscription = Inscriptions::find($inscription_id);

        if (!$inscription) {
            return self::fail(__('Inscription introuvable.', 'smbb-tomo'));
        }

        if ($inscription['status'] === 'cancelled') {
            return self::fail(__('Cette inscription est déjà annulée.', 'smbb-tomo'));
        }

        $old_creneau_id = (int) $inscription['creneau_id'];
        $status = $postpone ? 'pending' : 'cancelled';
        // Un jour de pass annulé seul : remboursement de sa part, calculé avant le changement.
        $day_refund = !$postpone && !empty($inscription['pass_id']) && class_exists('\\Smbb\\Tomo\\WooCommerce\\Orders') && function_exists('wc_get_order')
            ? \Smbb\Tomo\WooCommerce\Orders::refundLine($inscription)
            : '';
        $data = array('status' => $status, 'checked_at' => null);

        if ($postpone) {
            $data['creneau_id'] = null;
        }

        self::updateInscription($inscription_id, $data);

        $when = $old_creneau_id ? Creneaux::find($old_creneau_id) : null;
        $message = $postpone
            ? sprintf(__('Reportée : retirée du créneau %s, en attente d\'une nouvelle date.', 'smbb-tomo'), $when ? Lookup::creneauWhen($when) : '—')
            : __('Inscription annulée.', 'smbb-tomo');

        if ($reason !== '') {
            $message .= ' ' . sprintf(__('Motif : %s', 'smbb-tomo'), $reason);
        }

        if ($day_refund !== '') {
            $message .= ' ' . $day_refund;
            $day = $old_creneau_id ? Creneaux::find($old_creneau_id) : null;

            if ($day) {
                \Smbb\Tomo\WooCommerce\Orders::noteDayCancelled($day, array($inscription), (string) $reason);
            }
        }

        History::record($inscription_id, $postpone ? 'postponed' : 'cancelled', $message, $old_creneau_id ?: null);

        if ($old_creneau_id) {
            Capacity::refresh($old_creneau_id);
        }

        do_action('smbb_tomo_inscription_cancelled', (int) $inscription_id, $status, $old_creneau_id, $reason);

        return self::ok($postpone ? __('Réservation reportée : elle attend une nouvelle date dans le stock.', 'smbb-tomo') : __('Réservation annulée.', 'smbb-tomo'));
    }

    /**
     * Déplace une inscription vers un autre créneau, ou donne une date à une réservation reportée.
     * $force permet de dépasser la jauge (décision de l'équipe). $confirm passe la réservation « confirmée ».
     */
    public static function move($inscription_id, $target_creneau_id, $force = false, $confirm = true)
    {
        $inscription = Inscriptions::find($inscription_id);
        $target = Creneaux::find($target_creneau_id);

        if (!$inscription || !$target) {
            return self::fail(__('Inscription ou créneau introuvable.', 'smbb-tomo'));
        }

        $from = (int) $inscription['creneau_id'];

        if ($from === (int) $target['id'] && in_array($inscription['status'], Choices::seatStatuses(), true)) {
            return self::fail(__("L'inscription est déjà sur ce créneau.", 'smbb-tomo'));
        }

        $error = Capacity::check($target['id'], 1, $inscription_id);

        if ($error !== '' && !($force && $target['status'] !== 'cancelled')) {
            return self::fail($error);
        }

        self::updateInscription($inscription_id, array(
            'creneau_id' => (int) $target['id'],
            'atelier_id' => (int) $target['atelier_id'],
            'status' => $confirm ? 'confirmed' : 'pending',
            'checked_at' => null,
        ));

        $from_row = $from ? Creneaux::find($from) : null;
        $message = $from_row
            ? sprintf(__('Déplacée de %1$s vers %2$s.', 'smbb-tomo'), Lookup::creneauLabel($from_row), Lookup::creneauLabel($target))
            : sprintf(__('Placée sur %s.', 'smbb-tomo'), Lookup::creneauLabel($target));

        if ($error !== '') {
            $message .= ' ' . __('(jauge dépassée volontairement)', 'smbb-tomo');
        }

        History::record($inscription_id, 'moved', $message, (int) $target['id'], $from ?: null);

        if ($from) {
            Capacity::refresh($from);
        }

        Capacity::refresh($target['id']);
        do_action('smbb_tomo_inscription_moved', (int) $inscription_id, $from, (int) $target['id'], (bool) $confirm);

        return self::ok($from_row ? __('Inscription déplacée.', 'smbb-tomo') : __('Inscription placée sur le créneau.', 'smbb-tomo'));
    }

    /**
     * Confirmation (pending → confirmed) et pointage du jour de l'atelier : present, absent, ou confirmed pour annuler le pointage.
     */
    public static function presence($inscription_id, $status)
    {
        $inscription = Inscriptions::find($inscription_id);

        if (!$inscription || !in_array($status, array('present', 'absent', 'confirmed'), true)) {
            return self::fail(__('Pointage impossible.', 'smbb-tomo'));
        }

        if (empty($inscription['creneau_id']) || !in_array($inscription['status'], Choices::seatStatuses(), true)) {
            return self::fail(__("Seule une inscription placée sur un créneau peut être confirmée ou pointée.", 'smbb-tomo'));
        }

        if ($inscription['status'] === $status) {
            return self::ok(sprintf(__('%1$s : %2$s.', 'smbb-tomo'), Lookup::personName($inscription), Choices::label('inscriptionStatuses', $status)));
        }

        self::updateInscription($inscription_id, array(
            'status' => $status,
            'checked_at' => $status === 'confirmed' ? null : current_time('mysql'),
        ));

        $is_confirmation = $inscription['status'] === 'pending' && $status === 'confirmed';
        History::record($inscription_id, $is_confirmation ? 'confirmed' : 'presence', Choices::label('inscriptionStatuses', $status), (int) $inscription['creneau_id']);

        if ($is_confirmation) {
            do_action('smbb_tomo_inscription_confirmed', (int) $inscription_id);
        }

        return self::ok(sprintf(__('%1$s : %2$s.', 'smbb-tomo'), Lookup::personName($inscription), Choices::label('inscriptionStatuses', $status)));
    }

    /**
     * Annule un créneau : ses réservations repassent « à confirmer » sans date, sa liste d'attente est close.
     */
    public static function cancelCreneau($creneau_id, $reason = '')
    {
        global $wpdb;

        $creneau = Creneaux::find($creneau_id);

        if (!$creneau) {
            return self::fail(__('Créneau introuvable.', 'smbb-tomo'));
        }

        if ($creneau['status'] === 'cancelled') {
            return self::fail(__('Ce créneau est déjà annulé.', 'smbb-tomo'));
        }

        $wpdb->update(Lookup::table('tomo_creneau'), array(
            'status' => 'cancelled',
            'cancel_reason' => (string) $reason,
            'lastupdate_date' => current_time('mysql'),
            'lastupdate_by' => get_current_user_id() ?: null,
        ), array('id' => (int) $creneau_id));

        $moved = 0;
        $ids = array();
        $label = Lookup::creneauLabel($creneau);

        $woo = class_exists('\\Smbb\\Tomo\\WooCommerce\\Orders') && function_exists('wc_get_order');
        $affected = Inscriptions::forCreneau($creneau_id);

        foreach ($affected as $inscription) {
            $ids[] = (int) $inscription['id'];
            $refund = $woo ? \Smbb\Tomo\WooCommerce\Orders::refundLine($inscription) : '';
            self::updateInscription($inscription['id'], array('status' => 'pending', 'creneau_id' => null, 'checked_at' => null));
            History::record($inscription['id'], 'creneau_cancelled', sprintf(__('Créneau %s annulé, réservation en attente d\'une nouvelle date.', 'smbb-tomo'), $label) . ($reason !== '' ? ' ' . sprintf(__('Motif : %s', 'smbb-tomo'), $reason) : '') . ($refund !== '' ? ' ' . $refund : ''), (int) $creneau_id);
            $moved++;
        }

        // Note dans chaque commande WooCommerce : journée annulée, remboursement à convenir.
        if ($woo && $affected) {
            \Smbb\Tomo\WooCommerce\Orders::noteDayCancelled($creneau, $affected, (string) $reason);
        }

        $wpdb->query($wpdb->prepare('UPDATE ' . Lookup::table('tomo_attente') . " SET status = 'cancelled' WHERE creneau_id = %d AND status IN ('waiting','notified')", (int) $creneau_id));
        Capacity::refresh($creneau_id); // un jour annulé rend complets les pass qui l'incluent.

        do_action('smbb_tomo_creneau_cancelled', (int) $creneau_id, $reason, $ids);

        return self::ok(sprintf(
            _n('Créneau annulé. %d réservation attend une nouvelle date.', 'Créneau annulé. %d réservations attendent une nouvelle date.', $moved, 'smbb-tomo'),
            $moved
        ));
    }

    /**
     * Inscrit une demande de la liste d'attente (une inscription par place demandée).
     */
    public static function bookFromWaitlist($attente_id, $force = false)
    {
        global $wpdb;

        $attente = Inscriptions::findWaitlist($attente_id);

        if (!$attente || !in_array($attente['status'], array('waiting', 'notified'), true)) {
            return self::fail(__("Cette demande n'est plus en attente.", 'smbb-tomo'));
        }

        $creneau = Creneaux::find($attente['creneau_id']);
        $places = max(1, (int) $attente['places']);
        $error = Capacity::check($attente['creneau_id'], $places);

        if (!$creneau || ($error !== '' && !($force && $creneau['status'] !== 'cancelled'))) {
            return self::fail($error !== '' ? $error : __('Créneau introuvable.', 'smbb-tomo'));
        }

        $first_id = 0;

        for ($i = 0; $i < $places; $i++) {
            $id = self::insertInscription(array(
                'creneau_id' => (int) $creneau['id'],
                'atelier_id' => (int) $creneau['atelier_id'],
                'customer_id' => $attente['customer_id'] ? (int) $attente['customer_id'] : null,
                'first_name' => $attente['first_name'],
                'last_name' => $attente['last_name'] . ($i > 0 ? ' (' . sprintf(__('place %d', 'smbb-tomo'), $i + 1) . ')' : ''),
                'email' => $attente['email'],
                'phone' => $attente['phone'],
                'source' => 'waitlist',
                'status' => 'confirmed',
            ));

            if (!$id) {
                return self::fail(__("L'inscription n'a pas pu être enregistrée.", 'smbb-tomo'));
            }

            $first_id = $first_id ?: $id;
            History::record($id, 'waitlist', sprintf(__("Inscrite depuis la liste d'attente sur %s.", 'smbb-tomo'), Lookup::creneauLabel($creneau)), (int) $creneau['id']);
        }

        $wpdb->update(Lookup::table('tomo_attente'), array(
            'status' => 'booked',
            'inscription_id' => $first_id,
            'lastupdate_date' => current_time('mysql'),
            'lastupdate_by' => get_current_user_id() ?: null,
        ), array('id' => (int) $attente_id));

        Capacity::refresh($creneau['id']);
        do_action('smbb_tomo_waitlist_booked', (int) $attente_id, $first_id);

        return self::ok(sprintf(_n('%d place réservée depuis la liste d\'attente.', '%d places réservées depuis la liste d\'attente.', $places, 'smbb-tomo'), $places));
    }

    /**
     * Copie un créneau sur d'autres dates (mêmes horaires, places, prix et intervenants).
     *
     * @param array<int,string> $dates dates Y-m-d
     */
    public static function duplicateCreneau($creneau_id, array $dates)
    {
        global $wpdb;

        $creneau = Creneaux::find($creneau_id);

        if (!$creneau) {
            return self::fail(__('Créneau introuvable.', 'smbb-tomo'));
        }

        if (Passes::isPass($creneau)) {
            return self::fail(__('Un pass ne se duplique pas : créez les nouvelles dates, puis un nouveau pass qui les regroupe.', 'smbb-tomo'));
        }

        $created = 0;

        foreach (array_unique($dates) as $date) {
            $date = trim((string) $date);

            if (!Input::isDate($date)) {
                continue;
            }

            $ok = $wpdb->insert(Lookup::table('tomo_creneau'), array(
                'atelier_id' => (int) $creneau['atelier_id'],
                'start_date' => $date,
                'start_time' => $creneau['start_time'],
                'end_time' => $creneau['end_time'],
                'all_day' => !empty($creneau['all_day']) ? 1 : 0,
                'capacity' => (int) $creneau['capacity'],
                'price' => $creneau['price'],
                'intervenant_ids' => is_array($creneau['intervenant_ids']) ? wp_json_encode($creneau['intervenant_ids']) : $creneau['intervenant_ids'],
                'status' => in_array($creneau['status'], array('open', 'full'), true) ? 'open' : 'draft',
                'internal_note' => $creneau['internal_note'],
                'creation_date' => current_time('mysql'),
                'creation_by' => get_current_user_id() ?: null,
                'lastupdate_date' => current_time('mysql'),
                'lastupdate_by' => get_current_user_id() ?: null,
            ));

            if ($ok) {
                $created++;
                $new_ids = Lookup::ids($creneau['intervenant_ids']);

                if ($new_ids) {
                    do_action('smbb_tomo_intervenants_assigned', (int) $wpdb->insert_id, $new_ids);
                }
            }
        }

        if (!$created) {
            return self::fail(__('Aucune date valide : rien n\'a été créé.', 'smbb-tomo'));
        }

        return self::ok(sprintf(_n('%d créneau créé.', '%d créneaux créés.', $created, 'smbb-tomo'), $created));
    }

    /**
     * Insère une inscription avec ses colonnes d'audit. Renvoie l'id ou 0.
     */
    public static function insertInscription(array $data)
    {
        global $wpdb;

        $now = current_time('mysql');
        $user = get_current_user_id() ?: null;
        $data = array_merge(array(
            'source' => 'manual',
            'status' => 'confirmed',
            'creation_date' => $now,
            'creation_by' => $user,
            'lastupdate_date' => $now,
            'lastupdate_by' => $user,
        ), $data);

        return $wpdb->insert(Lookup::table('tomo_inscription'), $data) ? (int) $wpdb->insert_id : 0;
    }

    private static function updateInscription($id, array $data)
    {
        global $wpdb;

        $data['lastupdate_date'] = current_time('mysql');
        $data['lastupdate_by'] = get_current_user_id() ?: null;
        $ok = $wpdb->update(Lookup::table('tomo_inscription'), $data, array('id' => (int) $id)) !== false;

        if ($ok) {
            Passes::cascade($id, $data); // les jours d'un pass suivent la réservation principale.
        }

        return $ok;
    }

    private static function ok($message)
    {
        return array('success' => true, 'message' => (string) $message);
    }

    private static function fail($message)
    {
        return array('success' => false, 'message' => (string) $message);
    }
}
