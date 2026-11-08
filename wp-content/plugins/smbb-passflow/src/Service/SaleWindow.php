<?php

namespace Smbb\Passflow\Service;

use Smbb\Passflow\Repository\Slots;
use Smbb\Passflow\Repository\Lookup;
use Smbb\Passflow\Support\Settings;

defined('ABSPATH') || exit;

/**
 * Mise en vente différée d'un créneau.
 *
 * Un créneau « en vente » dont la mise en vente est différée s'affiche en ligne mais ne se réserve
 * pas encore. Avec une date d'ouverture, il s'ouvre tout seul à cette date ; sans date, il attend
 * qu'on décoche l'option. Pas de liste d'attente avant l'ouverture (décision de Seb) : elle
 * viderait la vente au moment de l'ouverture. Tout le monde part à égalité à l'heure dite.
 *
 * Colonnes du créneau : sale_delayed (0/1), sale_opens_at (date et heure, facultative),
 * sale_show_date (0/1 : date d'ouverture affichée aux clients ou non).
 */
final class SaleWindow
{
    /**
     * Le créneau est-il affiché sans être encore réservable en ligne ?
     * Une date d'ouverture passée bascule le créneau sur-le-champ (sans attendre la tâche planifiée).
     */
    public static function pending(array $slot)
    {
        if (empty($slot['sale_delayed']) || (string) $slot['sale_delayed'] === '0') {
            return false;
        }

        $at = self::opensAt($slot);

        if ($at === 0 || $at > current_time('timestamp')) {
            return true;
        }

        self::open($slot);

        return false;
    }

    /**
     * Horodatage local de l'ouverture, 0 sans date.
     */
    public static function opensAt(array $slot)
    {
        if (empty($slot['sale_opens_at']) || $slot['sale_opens_at'] === '0000-00-00 00:00:00') {
            return 0;
        }

        return (int) strtotime((string) $slot['sale_opens_at']);
    }

    /**
     * Date d'ouverture telle que les clients la voient : 0 si elle est masquée (« Bientôt en vente »).
     */
    public static function publicOpensAt(array $slot)
    {
        if (isset($slot['sale_show_date']) && (string) $slot['sale_show_date'] === '0') {
            return 0;
        }

        return self::opensAt($slot);
    }

    /**
     * « Ouverture des réservations le samedi 12 décembre à 10h00 » / « Réservations bientôt ouvertes ».
     * $public : texte pour les clients (date masquée si l'option est décochée) ; faux pour l'admin.
     */
    public static function label(array $slot, $public = true)
    {
        $at = $public ? self::publicOpensAt($slot) : self::opensAt($slot);

        if (!$at) {
            return __('Réservations bientôt ouvertes', 'smbb-passflow');
        }

        return sprintf(__('Ouverture des réservations le %s', 'smbb-passflow'), self::when($at, 'l j F'));
    }

    /**
     * Version courte : « Ouvre le sam. 12 déc. à 10h00 » / « Bientôt en vente ».
     */
    public static function shortLabel(array $slot, $public = true)
    {
        $at = $public ? self::publicOpensAt($slot) : self::opensAt($slot);

        if (!$at) {
            return __('Bientôt en vente', 'smbb-passflow');
        }

        return sprintf(__('Ouvre le %s', 'smbb-passflow'), self::when($at, 'D j M'));
    }

    private static function when($timestamp, $format)
    {
        return sprintf(
            /* translators: 1: jour, 2: heure */
            __('%1$s à %2$s', 'smbb-passflow'),
            date_i18n($format, $timestamp),
            Lookup::hours(date('H:i:s', $timestamp))
        );
    }

    /**
     * Bascule un créneau dont la date d'ouverture est passée. La mise à jour conditionnelle sert de
     * verrou : une seule requête fait la bascule et prévient la liste d'attente.
     */
    public static function open(array $slot)
    {
        global $wpdb;
        static $done = array();

        $id = (int) $slot['id'];

        if ($id < 1 || isset($done[$id])) {
            return;
        }

        $done[$id] = true;
        $changed = $wpdb->query($wpdb->prepare(
            'UPDATE ' . Lookup::table('passflow_slot') . ' SET sale_delayed = 0, lastupdate_date = %s WHERE id = %d AND sale_delayed = 1',
            current_time('mysql'),
            $id
        ));

        if ($changed) {
            self::opened($id);
        }
    }

    /**
     * Les réservations viennent d'ouvrir (date atteinte ou option décochée à la main).
     */
    public static function opened($slot_id)
    {
        $slot = Slots::find($slot_id);

        if (!$slot || !in_array($slot['status'], array('open', 'full'), true)) {
            return;
        }

        do_action('smbb_passflow_sale_opened', (int) $slot_id);
    }

    /**
     * Tâche planifiée : ouvre les créneaux dont la date est atteinte, même sans visite sur le site.
     *
     * @return int créneaux ouverts
     */
    public static function openDue()
    {
        global $wpdb;

        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM ' . Lookup::table('passflow_slot') . " WHERE deleted_flag = 0 AND sale_delayed = 1 AND sale_opens_at IS NOT NULL AND sale_opens_at <= %s AND status IN ('open','full')",
            current_time('mysql')
        ), ARRAY_A);

        foreach ((array) $rows as $row) {
            self::open($row);
        }

        return count((array) $rows);
    }
}
