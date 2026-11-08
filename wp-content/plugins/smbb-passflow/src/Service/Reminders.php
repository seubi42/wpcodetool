<?php

namespace Smbb\Passflow\Service;

use Smbb\Passflow\Repository\Slots;
use Smbb\Passflow\Repository\Bookings;
use Smbb\Passflow\Repository\Lookup;
use Smbb\Passflow\Support\Settings;

defined('ABSPATH') || exit;

/**
 * Rappels avant les ateliers et échéances de la liste d'attente, par une tâche toutes les 15 minutes.
 * Chaque rappel porte une clé (personne + créneau + date) : il ne part jamais deux fois,
 * et repart si le créneau change de date.
 */
final class Reminders
{
    const HOOK = 'smbb_passflow_notifications';

    public static function hooks()
    {
        add_action('smbb_wpcodetool_register_cron_tasks', array(__CLASS__, 'registerCron'));
    }

    /**
     * @param \Smbb\WpCodeTool\Cron\CronRegistry $registry
     */
    public static function registerCron($registry)
    {
        $registry->register(self::HOOK, array(
            'label' => __('Passflow : rappels et liste d\'attente', 'smbb-passflow'),
            'description' => __('Envoie les rappels avant les ateliers (intervenants et participants), ouvre les réservations des créneaux en vente différée et passe la main à la personne suivante quand un délai de liste d\'attente est dépassé.', 'smbb-passflow'),
            'schedule' => 'smbb_every_15_minutes',
            'callback' => array(__CLASS__, 'run'),
        ));
    }

    /**
     * @return array<string,int> nombre de messages programmés par type
     */
    public static function run()
    {
        Accounts::syncAll();
        CertificateAlerts::run();

        $counts = array(
            'participants' => 0,
            'intervenants' => 0,
            'ouvertures' => SaleWindow::openDue(),
            'attente' => Waitlist::expire(),
            'abandons' => \Smbb\Passflow\WooCommerce\Module::active() ? \Smbb\Passflow\WooCommerce\Orders::releaseAbandoned() : 0,
        );

        $hours = max(1, Settings::int('reminder_participant_hours'));
        $days = max(1, Settings::int('reminder_host_days'));

        // La veille : participants (et intervenants par SMS).
        foreach (self::startingWithin($hours * HOUR_IN_SECONDS) as $slot) {
            $counts['participants'] += self::remindParticipants($slot);

            foreach (Lookup::ids($slot['host_ids']) as $host_id) {
                $counts['intervenants'] += Notifier::toHost('reminder_host_eve', $host_id, $slot, array(), self::key('eve', $host_id, $slot));
            }
        }

        // Le jour même (réglage facultatif) : le matin à heure fixe, ou X heures avant.
        foreach (self::dueToday() as $slot) {
            $counts['participants'] += self::remindParticipants($slot, 'reminder_participant_day', 'j0');
        }

        // Une semaine avant : intervenants avec la liste des inscrits.
        foreach (self::startingWithin($days * DAY_IN_SECONDS) as $slot) {
            foreach (Lookup::ids($slot['host_ids']) as $host_id) {
                $counts['intervenants'] += Notifier::toHost('reminder_host', $host_id, $slot, array(), self::key('week', $host_id, $slot));
            }
        }

        Notifier::flush();

        return $counts;
    }

    /**
     * Rappel manuel depuis la fiche d'un créneau (sans contrôle de doublon).
     *
     * @param string $target participants | intervenants
     * @return int nombre de messages envoyés
     */
    public static function sendNow(array $slot, $target)
    {
        $count = 0;

        if ($target === 'participants') {
            foreach (Bookings::forSlot($slot['id']) as $booking) {
                if ($booking['status'] === 'confirmed') {
                    $count += Notifier::toParticipant('reminder_participant', $booking, $slot, array(), '', self::channels('reminder_participant'));
                }
            }
        } else {
            foreach (Lookup::ids($slot['host_ids']) as $host_id) {
                $count += Notifier::toHost('reminder_host', $host_id, $slot, array(), '', self::channels('reminder_host'));
            }
        }

        Notifier::flush();

        return $count;
    }

    private static function remindParticipants(array $slot, $template = 'reminder_participant', $type = 'j1')
    {
        $count = 0;

        foreach (Bookings::forSlot($slot['id']) as $booking) {
            if ($booking['status'] === 'confirmed') {
                $count += Notifier::toParticipant($template, $booking, $slot, array(), self::key($type, $booking['id'], $slot));

                if ($type === 'j1') {
                    WalletSync::eve($booking, $slot); // le billet Wallet affiche « C'est demain… »
                }
            }
        }

        return $count;
    }

    /**
     * Créneaux dont le rappel du jour même est dû maintenant, selon le réglage reminder_day_mode :
     * - morning : à partir de l'heure choisie, le jour de l'atelier, s'il n'a pas encore commencé ;
     * - hours : dans les X heures qui précèdent le début.
     * La clé anti-doublon (j0) garantit un seul envoi, même si la tâche passe plusieurs fois.
     *
     * @return array<int,array<string,mixed>>
     */
    private static function dueToday()
    {
        $mode = (string) Settings::get('reminder_day_mode');

        if ($mode === 'hours') {
            return self::startingWithin(min(23, max(1, Settings::int('reminder_day_hours'))) * HOUR_IN_SECONDS);
        }

        if ($mode !== 'morning') {
            return array();
        }

        $now = current_time('timestamp');
        $today = date('Y-m-d', $now);
        $time = preg_match('/^(\d{1,2}):(\d{2})/', (string) Settings::get('reminder_day_time'), $m) ? sprintf('%02d:%02d:00', $m[1], $m[2]) : '08:00:00';
        $send_at = strtotime($today . ' ' . $time);

        if ($send_at === false || $now < $send_at) {
            return array();
        }

        $slots = array();

        // Ce qui commence d'ici minuit ; on garde ceux du jour qui commencent après l'heure du rappel.
        foreach (self::startingWithin(strtotime($today . ' 23:59:59') - $now + 1) as $slot) {
            $start = strtotime($slot['start_date'] . ' ' . ($slot['start_time'] ? $slot['start_time'] : '00:00:00'));

            if ($slot['start_date'] === $today && $start > $send_at) {
                $slots[] = $slot;
            }
        }

        return $slots;
    }

    /**
     * Créneaux en vente ou complets qui commencent entre maintenant et maintenant + $seconds.
     *
     * @return array<int,array<string,mixed>>
     */
    private static function startingWithin($seconds)
    {
        global $wpdb;

        $now = current_time('timestamp');
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM ' . Lookup::table('passflow_slot')
            . " WHERE deleted_flag = 0 AND status IN ('open','full') AND kind <> 'pass'" // les jours d'un pass ont leurs propres rappels.
            . " AND TIMESTAMP(start_date, COALESCE(start_time, '00:00:00')) > %s"
            . " AND TIMESTAMP(start_date, COALESCE(start_time, '00:00:00')) <= %s"
            . ' ORDER BY start_date ASC, start_time ASC',
            date('Y-m-d H:i:s', $now),
            date('Y-m-d H:i:s', $now + (int) $seconds)
        ), ARRAY_A);

        return $rows ? Slots::withStats($rows) : array();
    }

    private static function key($type, $id, array $slot)
    {
        return 'reminder:' . $type . ':' . (int) $id . ':' . (int) $slot['id'] . ':' . $slot['start_date'] . 'T' . substr((string) $slot['start_time'], 0, 5);
    }

    /**
     * Canaux actifs d'un rappel ; à défaut, l'e-mail (pour qu'un renvoi manuel parte toujours).
     *
     * @return array<int,string>
     */
    private static function channels($key)
    {
        $channels = array();

        foreach (array('email', 'sms') as $channel) {
            if (Settings::channelOn($key, $channel)) {
                $channels[] = $channel;
            }
        }

        return $channels ?: array('email');
    }
}
