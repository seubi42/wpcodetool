<?php

namespace Smbb\Tomo\Support;

use Smbb\WpCodeTool\Resource\ResourceDefinition;
use Smbb\WpCodeTool\Schema\SchemaSynchronizer;

defined('ABSPATH') || exit;

/**
 * Crée et met à jour les tables Tomo avec le synchroniseur de CodeTool, à la première requête
 * (site, admin, cron, retour de paiement) après une installation ou une montée de version.
 * Rien n'est jamais supprimé.
 */
final class Schema
{
    const OPTION = 'smbb_tomo_schema';
    const MODELS = array('tomo-atelier', 'tomo-intervenant', 'tomo-creneau', 'tomo-inscription', 'tomo-historique', 'tomo-attente', 'tomo-envoi', 'tomo-document', 'tomo-dispo', 'tomo-catalogue', 'tomo-wallet', 'tomo-facture', 'tomo-compteur', 'tomo-campagne', 'tomo-campagne-dest');

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

        if (get_option(self::OPTION) === SMBB_TOMO_VERSION) {
            return $done = true;
        }

        if (!class_exists(SchemaSynchronizer::class) || !class_exists(ResourceDefinition::class)) {
            return $done = false;
        }

        global $wpdb;

        // Une seule requête fait la mise à jour ; les autres continuent sans attendre.
        $lock = $wpdb->prefix . 'tomo_schema';

        if ((int) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)', $lock)) !== 1) {
            return $done = self::ready();
        }

        // Une autre requête a pu finir pendant qu'on attendait le verrou.
        wp_cache_delete(self::OPTION, 'options');

        if (get_option(self::OPTION) === SMBB_TOMO_VERSION) {
            $wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock));

            return $done = true;
        }

        $synchronizer = new SchemaSynchronizer();
        $ok = true;

        foreach (self::MODELS as $model) {
            $file = SMBB_TOMO_PATH . 'codetool/models/' . $model . '.json';
            $data = json_decode((string) file_get_contents($file), true);

            if (!is_array($data)) {
                $ok = false;
                continue;
            }

            $result = $synchronizer->apply(new ResourceDefinition($data, rtrim(SMBB_TOMO_PATH, '/\\'), $file));
            $ok = $ok && !empty($result['success']);
        }

        if ($ok) {
            self::migrate();
            update_option(self::OPTION, SMBB_TOMO_VERSION, true); // chargée avec les options : pas de requête à chaque page
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

        $wpdb->query('UPDATE ' . $wpdb->prefix . "tomo_inscription SET status = 'pending', creneau_id = NULL WHERE status = 'to_replace'");
        $wpdb->query('UPDATE ' . $wpdb->prefix . "tomo_creneau SET status = 'open' WHERE status = 'done'");

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
        if (!get_option('smbb_tomo_catalogue_migrated')) {
            $ateliers = $wpdb->get_results('SELECT id, name, extra_product_ids FROM ' . $wpdb->prefix . 'tomo_atelier', ARRAY_A);

            foreach ((array) $ateliers as $position => $atelier) {
                $ids = array_values(array_unique(array_filter(array_map('intval', \Smbb\Tomo\Repository\Lookup::ids($atelier['extra_product_ids'])))));

                if (!$ids) {
                    continue;
                }

                $wpdb->insert($wpdb->prefix . 'tomo_catalogue', array(
                    'name' => sprintf(__('%s — sur place', 'smbb-tomo'), $atelier['name']),
                    'position' => ($position + 1) * 10,
                    'active' => 1,
                    'atelier_ids' => wp_json_encode(array((int) $atelier['id'])),
                    'intervenant_ids' => wp_json_encode(array()),
                    'product_ids' => wp_json_encode($ids),
                    'creation_date' => current_time('mysql'),
                ));

                if ($wpdb->insert_id) {
                    $wpdb->update($wpdb->prefix . 'tomo_atelier', array('extra_product_ids' => null), array('id' => (int) $atelier['id']));
                }
            }

            update_option('smbb_tomo_catalogue_migrated', 1, false);
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

        $table = $wpdb->prefix . 'tomo_creneau';

        return $ready = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table;
    }
}
