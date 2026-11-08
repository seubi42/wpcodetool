<?php

namespace Smbb\Passflow\Support;

use Smbb\WpCodeTool\Resource\ResourceDefinition;
use Smbb\WpCodeTool\Schema\SchemaSynchronizer;

defined('ABSPATH') || exit;

/**
 * Crée et met à jour les tables Passflow avec le synchroniseur de CodeTool, à la première requête
 * (site, admin, cron, retour de paiement) après une installation ou une montée de version.
 * Rien n'est jamais supprimé.
 */
final class Schema
{
    const OPTION = 'smbb_passflow_schema';
    const MODELS = array('passflow-activity', 'passflow-host', 'passflow-slot', 'passflow-booking', 'passflow-history', 'passflow-waitlist', 'passflow-delivery', 'passflow-document', 'passflow-availability', 'passflow-catalog', 'passflow-wallet', 'passflow-invoice', 'passflow-counter', 'passflow-campaign', 'passflow-campaign-recipient', 'passflow-broadcast', 'passflow-broadcast-queue');

    public static function hooks()
    {
        // Sur init et pas seulement admin_init : le site public, le cron et les retours de paiement
        // ne doivent pas tourner sur l'ancien schéma en attendant qu'on ouvre l'admin.
        // Quand la version n'a pas changé, ça ne coûte qu'une lecture d'option (déjà en cache).
        add_action('init', array(__CLASS__, 'ensure'), 1);
    }

    /**
     * @return bool vrai si les tables sont prêtes
     */
    public static function ensure()
    {
        static $done = null;

        if ($done !== null) {
            return $done;
        }

        if (get_option(self::OPTION) === SMBB_PASSFLOW_VERSION) {
            return $done = true;
        }

        if (!class_exists(SchemaSynchronizer::class) || !class_exists(ResourceDefinition::class)) {
            return $done = false;
        }

        global $wpdb;

        // Une seule requête fait la mise à jour ; les autres continuent sans attendre.
        $lock = $wpdb->prefix . 'passflow_schema';

        if ((int) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)', $lock)) !== 1) {
            return $done = self::ready();
        }

        // Une autre requête a pu finir pendant qu'on attendait le verrou.
        wp_cache_delete(self::OPTION, 'options');

        if (get_option(self::OPTION) === SMBB_PASSFLOW_VERSION) {
            $wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock));

            return $done = true;
        }

        $synchronizer = new SchemaSynchronizer();
        $ok = true;

        foreach (self::MODELS as $model) {
            $file = SMBB_PASSFLOW_PATH . 'codetool/models/' . $model . '.json';
            $data = json_decode((string) file_get_contents($file), true);

            if (!is_array($data)) {
                $ok = false;
                continue;
            }

            $result = $synchronizer->apply(new ResourceDefinition($data, rtrim(SMBB_PASSFLOW_PATH, '/\\'), $file));
            $ok = $ok && !empty($result['success']);
        }

        if ($ok) {
            self::migrate();
            update_option(self::OPTION, SMBB_PASSFLOW_VERSION, true); // chargée avec les options : pas de requête à chaque page
        }

        $wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock));

        return $done = $ok;
    }

    /**
     * Données des versions précédentes :
     * - l'ancien statut « à replacer » devient « à confirmer » sans date ;
     * - le statut de créneau « terminé » disparaît (la date passée suffit) : il redevient « en vente ».
     */
    private static function migrate()
    {
        global $wpdb;

        $wpdb->query('UPDATE ' . $wpdb->prefix . "passflow_booking SET status = 'pending', slot_id = NULL WHERE status = 'to_replace'");
        $wpdb->query('UPDATE ' . $wpdb->prefix . "passflow_slot SET status = 'open' WHERE status = 'done'");

        // Documents : l'interrupteur « En pause » devient « Actif » (sens inversé).
        $settings = get_option(Settings::OPTION, array());

        if (is_array($settings) && !empty($settings['documents']) && is_array($settings['documents'])) {
            $changed = false;

            foreach ($settings['documents'] as $index => $row) {
                if (is_array($row) && !array_key_exists('active', $row)) {
                    $settings['documents'][$index]['active'] = empty($row['paused']) ? 1 : 0;
                    unset($settings['documents'][$index]['paused']);
                    $changed = true;
                }
            }

            if ($changed) {
                update_option(Settings::OPTION, $settings);
            }
        }

        // Les documents quittent les Réglages pour leur propre menu : on recopie une seule fois.
        if (get_option(Settings::DOCUMENTS_OPTION, null) === null && is_array($settings)) {
            $copy = array('documents' => isset($settings['documents']) && is_array($settings['documents']) ? $settings['documents'] : array());

            if (isset($settings['documents_onsite_note'])) {
                $copy['documents_onsite_note'] = $settings['documents_onsite_note'];
            }

            add_option(Settings::DOCUMENTS_OPTION, $copy, '', false);
        }

        // 0.11.4 : les produits « Vente sur place » de chaque atelier deviennent un catalogue sur place
        // réservé à cet atelier (une seule fois), et quittent la fiche de l'atelier.
        if (!get_option('smbb_passflow_catalog_migrated')) {
            $activities = $wpdb->get_results('SELECT id, name, extra_product_ids FROM ' . $wpdb->prefix . 'passflow_activity', ARRAY_A);

            foreach ((array) $activities as $position => $activity) {
                $ids = array_values(array_unique(array_filter(array_map('intval', \Smbb\Passflow\Repository\Lookup::ids($activity['extra_product_ids'])))));

                if (!$ids) {
                    continue;
                }

                $wpdb->insert($wpdb->prefix . 'passflow_catalog', array(
                    'name' => sprintf(__('%s — sur place', 'smbb-passflow'), $activity['name']),
                    'position' => ($position + 1) * 10,
                    'active' => 1,
                    'activity_ids' => wp_json_encode(array((int) $activity['id'])),
                    'host_ids' => wp_json_encode(array()),
                    'product_ids' => wp_json_encode($ids),
                    'creation_date' => current_time('mysql'),
                ));

                if ($wpdb->insert_id) {
                    $wpdb->update($wpdb->prefix . 'passflow_activity', array('extra_product_ids' => null), array('id' => (int) $activity['id']));
                }
            }

            update_option('smbb_passflow_catalog_migrated', 1, false);
        }

        // 0.15.4 : « Réservation payée » devient « Votre ticket personnel » (PDF joint, boutons Wallet, SMS
        // avec le lien du ticket, actif par défaut). Les textes jamais modifiés reprennent les nouveaux.
        if (!get_option('smbb_passflow_tickets_mail_migrated')) {
            $settings = get_option(Settings::OPTION, array());

            if (is_array($settings) && $settings) {
                $old = array(
                    'subject' => 'Votre place pour {atelier} est confirmée',
                    'body' => "Bonjour {prenom},\n\nVotre place est confirmée :\n\n{atelier}\n{date}, {heure}\n{lieu}\n{adresse}\n\nVotre billet : {lien_billet}\nDans votre agenda : {lien_agenda}\nDocuments à remplir avant l'atelier : {lien_documents}\n\nSi vous avez une allergie ou une intolérance, dites-le-nous avant l'atelier.\n\nÀ très bientôt,\n{signature}",
                    'text' => '{expediteur} : votre place pour {atelier} le {date_courte} à {heure} est confirmée. À bientôt !',
                );

                self::resetOldTexts($settings, $old);
                $settings['notify_confirmed_sms'] = 1;
                update_option(Settings::OPTION, $settings);
            }

            update_option('smbb_passflow_tickets_mail_migrated', 1, false);
        }

        // 0.15.5 : les textes enregistrés passent par le Vocabulaire (« billet » → « badge »…) : on compare
        // aussi avec leur version réécrite, que la migration précédente ne reconnaissait pas.
        if (!get_option('smbb_passflow_tickets_mail_migrated_2')) {
            $settings = get_option(Settings::OPTION, array());

            if (is_array($settings) && $settings) {
                self::resetOldTexts($settings, array(
                    'subject' => 'Votre place pour {atelier} est confirmée',
                    'body' => "Bonjour {prenom},\n\nVotre place est confirmée :\n\n{atelier}\n{date}, {heure}\n{lieu}\n{adresse}\n\nVotre billet : {lien_billet}\nDans votre agenda : {lien_agenda}\nDocuments à remplir avant l'atelier : {lien_documents}\n\nSi vous avez une allergie ou une intolérance, dites-le-nous avant l'atelier.\n\nÀ très bientôt,\n{signature}",
                    'text' => '{expediteur} : votre place pour {atelier} le {date_courte} à {heure} est confirmée. À bientôt !',
                ));
                update_option(Settings::OPTION, $settings);
            }

            update_option('smbb_passflow_tickets_mail_migrated_2', 1, false);
        }
    }

    /**
     * Retire les textes « Réservation payée » restés à l'ancien texte d'origine (tel quel ou réécrit
     * par le Vocabulaire) : le nouveau texte par défaut s'applique.
     *
     * @param array<string,mixed>  $settings
     * @param array<string,string> $old
     */
    private static function resetOldTexts(array &$settings, array $old)
    {
        foreach ($old as $part => $text) {
            $name = 'tpl_confirmed_' . $part;

            if (!isset($settings[$name])) {
                continue;
            }

            $stored = trim(str_replace("\r\n", "\n", (string) $settings[$name]));
            $known = array($text);

            if (class_exists(Vocabulary::class)) {
                $known[] = trim(Vocabulary::apply($text));
            }

            if (in_array($stored, $known, true)) {
                unset($settings[$name]);
            }
        }
    }

    /**
     * Les écrans vérifient que les tables existent avant de les interroger.
     */
    public static function ready()
    {
        static $ready = false;

        if ($ready) {
            return true; // une table ne disparaît pas en cours de requête : inutile de redemander
        }

        global $wpdb;

        $table = $wpdb->prefix . 'passflow_slot';

        return $ready = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table;
    }
}
