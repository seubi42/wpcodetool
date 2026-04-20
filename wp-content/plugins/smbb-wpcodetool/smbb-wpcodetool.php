<?php
/**
 * Plugin Name: SMBB WP CodeTool
 * Description: Lightweight toolkit for declarative admin pages, custom table resources, option-backed settings pages, and REST API helpers.
 * Version: 0.1.26
 * Author: SMBB
 * Text Domain: smbb-wpcodetool
 * Requires at least: 6.0
 * Requires PHP: 7.4
 */

// Sécurité WordPress classique : si le fichier est appelé directement par le navigateur,
// on sort immédiatement. Le plugin doit toujours être chargé par WordPress.
defined('ABSPATH') || exit;

// Version interne du plugin. Elle servira plus tard pour les migrations, les caches compilés,
// ou les éventuelles évolutions de format des fichiers codetool/*.json.
define('SMBB_WPCODETOOL_VERSION', '0.1.26');

// Chemins de référence du plugin. On les centralise ici pour éviter de recalculer les chemins
// dans chaque classe du moteur.
define('SMBB_WPCODETOOL_FILE', __FILE__);
define('SMBB_WPCODETOOL_PATH', plugin_dir_path(__FILE__));
define('SMBB_WPCODETOOL_URL', plugin_dir_url(__FILE__));

// Autoloader minimal du plugin.
// L'objectif est volontairement simple pour le début du projet :
// Smbb\WpCodeTool\Api\ApiHelper  -> src/Api/ApiHelper.php
// Smbb\WpCodeTool\Admin\Table    -> src/Admin/Table.php
// Smbb\WpCodeTool\Store\...      -> src/Store/...
spl_autoload_register('smbb_wpcodetool_autoload');

/**
 * Charge automatiquement les classes du namespace Smbb\WpCodeTool.
 *
 * On ne met pas encore Composer pour garder le plugin très léger et facile à activer
 * dans un WordPress de test. Si le projet grossit, cette fonction pourra être remplacée
 * par un autoload Composer sans changer le code consommateur.
 *
 * @param string $class Nom complet de la classe demandée par PHP.
 */
function smbb_wpcodetool_autoload($class)
{
    $prefix = 'Smbb\\WpCodeTool\\';

    // On laisse immédiatement passer toutes les classes qui ne nous appartiennent pas.
    // C'est important pour ne pas interférer avec WordPress, TypeRocket ou d'autres plugins.
    if (strpos($class, $prefix) !== 0) {
        return;
    }

    // On transforme le namespace restant en chemin relatif dans src/.
    // Exemple : Admin\Table -> src/Admin/Table.php.
    $relative_class = substr($class, strlen($prefix));
    $file = SMBB_WPCODETOOL_PATH . 'src/' . str_replace('\\', DIRECTORY_SEPARATOR, $relative_class) . '.php';

    // On ne déclenche pas d'erreur si le fichier n'existe pas : PHP pourra continuer
    // avec les autres autoloaders éventuels.
    if (is_readable($file)) {
        require_once $file;
    }
}

// Point d'extension très simple : les futures briques du plugin pourront s'accrocher ici
// une fois que WordPress et les plugins sont chargés.
add_action('plugins_loaded', 'smbb_wpcodetool_loaded');

/**
 * Signale que le noyau CodeTool est chargé.
 *
 * Pour l'instant cette fonction ne démarre pas encore de scanner ou d'admin.
 * Elle existe pour poser une convention propre dès le début.
 */
function smbb_wpcodetool_loaded()
{
    $scanner = new \Smbb\WpCodeTool\Resource\ResourceScanner();

    // Premier branchement concret : si on est dans l'admin, on prépare le gestionnaire
    // qui scanne les ressources et enregistre les menus WordPress.
    if (is_admin()) {
        $admin = new \Smbb\WpCodeTool\Admin\AdminManager($scanner);
        $admin->hooks();
    }

    $api = new \Smbb\WpCodeTool\Api\ApiManager($scanner);
    $api->hooks();

    $docs = new \Smbb\WpCodeTool\Api\ApiDocsShortcode($scanner);
    $docs->hooks();

    do_action('smbb_wpcodetool_loaded');
}
