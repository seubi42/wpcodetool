<?php

namespace Smbb\Tomo\Service;

use Smbb\Tomo\Repository\Creneaux;
use Smbb\Tomo\Repository\Inscriptions;
use Smbb\Tomo\Repository\Lookup;
use Smbb\Tomo\Support\Settings;

defined('ABSPATH') || exit;

/**
 * Rappels avant les ateliers et échéances de la liste d'attente, par une tâche toutes les 15 minutes.
 * Chaque rappel porte une clé (personne + créneau + date) : il ne part jamais deux fois,
 * et repart si le créneau change de date.
 */
final class Reminders
{
    const HOOK = 'smbb_tomo_notifications';

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
            'label' => __('Tomo : rappels et liste d\'attente', 'smbb-tomo'),
            'description' => __('Envoie les rappels avant les ateliers (intervenants et participants) et passe la main à la personne suivante quand un délai de liste d\'attente est dépassé.', 'smbb-tomo'),
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
            'attente' => Waitlist::expire(),
            'abandons' => \Smbb\Tomo\WooCommerce\Module::active() ? \Smbb\Tomo\WooCommerce\Orders::releaseAbandoned() : 0,
        );

        $hours = max(1, Settings::int('reminder_participant_hours'));
        $days = max(1, Settings::int('reminder_intervenant_days'));

        // La veille : participants (et intervenants par SMS).
        foreach (self::startingWithin($hours * HOUR_IN_SECONDS) as $creneau) {
            $counts['participants'] += self::remindParticipants($creneau);

            foreach (Lookup::ids($creneau['intervenant_ids']) as $intervenant_id) {
                $counts['intervenants'] += Notifier::toIntervenant('reminder_intervenant_eve', $intervenant_id, $creneau, array(), self::key('eve', $intervenant_id, $creneau));
            }
        }

        // Une semaine avant : intervenants avec la liste des inscrits.
        foreach (self::startingWithin($days * DAY_IN_SECONDS) as $creneau) {
            foreach (Lookup::ids($creneau['intervenant_ids']) as $intervenant_id) {
                $counts['intervenants'] += Notifier::toIntervenant('reminder_intervenant', $intervenant_id, $creneau, array(), self::key('week', $intervenant_id, $creneau));
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
    public static function sendNow(array $creneau, $target)
    {
        $count = 0;

        if ($target === 'participants') {
            foreach (Inscriptions::forCreneau($creneau['id']) as $inscription) {
                if ($inscription['status'] === 'confirmed') {
                    $count += Notifier::toParticipant('reminder_participant', $inscription, $creneau, array(), '', self::channels('reminder_participant'));
                }
            }
        } else {
            foreach (Lookup::ids($creneau['intervenant_ids']) as $intervenant_id) {
                $count += Notifier::toIntervenant('reminder_intervenant', $intervenant_id, $creneau, array(), '', self::channels('reminder_intervenant'));
            }
        }

        Notifier::flush();

        return $count;
    }

    private static function remindParticipants(array $creneau)
    {
        $count = 0;

        foreach (Inscriptions::forCreneau($creneau['id']) as $inscription) {
            if ($inscription['status'] === 'confirmed') {
                $count += Notifier::toParticipant('reminder_participant', $inscription, $creneau, array(), self::key('j1', $inscription['id'], $creneau));
                WalletSync::eve($inscription, $creneau); // le billet Wallet affiche « C'est demain… »
            }
        }

        return $count;
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
            'SELECT * FROM ' . Lookup::table('tomo_creneau')
            . " WHERE deleted_flag = 0 AND status IN ('open','full') AND kind <> 'pass'" // les jours d'un pass ont leurs propres rappels.
            . " AND TIMESTAMP(start_date, COALESCE(start_time, '00:00:00')) > %s"
            . " AND TIMESTAMP(start_date, COALESCE(start_time, '00:00:00')) <= %s"
            . ' ORDER BY start_date ASC, start_time ASC',
            date('Y-m-d H:i:s', $now),
            date('Y-m-d H:i:s', $now + (int) $seconds)
        ), ARRAY_A);

        return $rows ? Creneaux::withStats($rows) : array();
    }

    private static function key($type, $id, array $creneau)
    {
        return 'reminder:' . $type . ':' . (int) $id . ':' . (int) $creneau['id'] . ':' . $creneau['start_date'] . 'T' . substr((string) $creneau['start_time'], 0, 5);
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
