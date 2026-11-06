<?php

namespace Smbb\SignConnectProxima\Support;

use Smbb\WpCodeTool\Resource\ResourceDefinition;
use Smbb\WpCodeTool\Schema\SchemaSynchronizer;

defined('ABSPATH') || exit;

/**
 * Crée tout seul les petites tables ajoutées après coup (libellés, archivage des transactions)
 * et les colonnes ajoutées aux tables existantes (tampon d'équipe 0.9.0, ville de l'organisation 0.9.4, ville de l'équipe 0.9.6, réglages d'envoi des modèles et équipe « modèles uniquement » 0.10.0, champs perso et nom d'origine des documents 0.11.0),
 * avec le synchroniseur de CodeTool : l'admin CodeTool les voit ensuite « à jour ».
 * Une option retient la version appliquée, pour ne rien refaire à chaque page.
 */
final class Schema
{
    const OPTION = 'smbb_scp_schema';
    const VERSION = '0.11.0';
    const MODELS = array('scp-tag', 'scp-document-tag', 'scp-document-user', 'scp-team', 'scp-organization', 'scp-template', 'scp-document');

    public static function hooks()
    {
        add_action('admin_init', array(__CLASS__, 'ensure'));
    }

    /**
     * Les colonnes ajoutées par cette version (ou une plus ancienne) existent-elles déjà ?
     */
    public static function atLeast($version)
    {
        $applied = (string) get_option(self::OPTION);

        return $applied !== '' && version_compare($applied, $version, '>=');
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

        if (get_option(self::OPTION) === self::VERSION) {
            return $done = true;
        }

        if (!class_exists(SchemaSynchronizer::class) || !class_exists(ResourceDefinition::class)) {
            return $done = false;
        }

        $synchronizer = new SchemaSynchronizer();
        $ok = true;

        foreach (self::MODELS as $model) {
            $file = SMBB_SCP_PATH . 'codetool/models/' . $model . '.json';
            $data = json_decode((string) file_get_contents($file), true);

            if (!is_array($data)) {
                $ok = false;
                continue;
            }

            $result = $synchronizer->apply(new ResourceDefinition($data, rtrim(SMBB_SCP_PATH, '/\\'), $file));
            $ok = $ok && !empty($result['success']);
        }

        if ($ok) {
            update_option(self::OPTION, self::VERSION, false);
        }

        return $done = $ok;
    }
}
