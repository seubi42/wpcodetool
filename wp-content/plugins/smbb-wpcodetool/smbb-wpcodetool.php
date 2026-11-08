<?php
/**
 * Plugin Name: SMBB WP CodeTool
 * Description: Lightweight toolkit for declarative admin pages, custom table resources, option-backed settings pages, and REST API helpers.
 * Version: 0.4.5
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
define('SMBB_WPCODETOOL_VERSION', '0.4.5');

// Chemins de référence du plugin. On les centralise ici pour éviter de recalculer les chemins
// dans chaque classe du moteur.
define('SMBB_WPCODETOOL_FILE', __FILE__);
define('SMBB_WPCODETOOL_PATH', plugin_dir_path(__FILE__));
define('SMBB_WPCODETOOL_URL', plugin_dir_url(__FILE__));

if (!function_exists('codetool_route')) {
    /**
     * Declare une route publique CodeTool dans codetool/routes/public.php.
     */
    function codetool_route()
    {
        return \Smbb\WpCodeTool\Route\PublicRouteContext::route();
    }
}

if (!function_exists('smbb_codetool_route')) {
    /**
     * Alias explicite de codetool_route().
     */
    function smbb_codetool_route()
    {
        return codetool_route();
    }
}

// Thème GeneratePress (version gratuite) : « Built with GeneratePress » est remplacé
// par le nom du site. Sans effet avec un autre thème.
add_action('wp', function () {
    if (has_action('generate_credits', 'generate_add_footer_info') === false) {
        return;
    }

    remove_action('generate_credits', 'generate_add_footer_info');
    add_action('generate_credits', function () {
        echo '<span class="copyright">&copy; ' . esc_html(wp_date('Y')) . ' ' . esc_html(get_bloginfo('name')) . '</span>';
    });
});

// Autoloader minimal du plugin.
// L'objectif est volontairement simple pour le début du projet :
// Smbb\WpCodeTool\Api\ApiHelper  -> src/Api/ApiHelper.php
// Smbb\WpCodeTool\Admin\Table    -> src/Admin/Table.php
// Smbb\WpCodeTool\Store\...      -> src/Store/...
$smbb_wpcodetool_composer_autoload = SMBB_WPCODETOOL_PATH . 'vendor/autoload.php';

if (is_readable($smbb_wpcodetool_composer_autoload)) {
    require_once $smbb_wpcodetool_composer_autoload;
} else {
    spl_autoload_register('smbb_wpcodetool_autoload');
}

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

// À l'activation : si des réglages WP Mail SMTP sont en base, l'import est (re)proposé.
register_activation_hook(__FILE__, function () {
    \Smbb\WpCodeTool\Mail\WpMailSmtpImport::onActivation();
});

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

        // Assistants de démarrage déclarés par les plugins (codetool/wizards/*.json).
        $wizards = new \Smbb\WpCodeTool\Wizard\WizardManager($scanner);
        $wizards->hooks();
    }

    // Page de connexion aux couleurs du thème (réglage CodeTool › Apparence).
    \Smbb\WpCodeTool\Admin\WpSkin::loginHooks();

    // Page « Mon compte » de WooCommerce aux couleurs du thème (réglage CodeTool › Apparence).
    \Smbb\WpCodeTool\Admin\WcAccount::hooks();

    // Carte bancaire FICTIVE pour les tests (réglage CodeTool › WooCommerce). Rien n'est accroché si c'est coupé.
    \Smbb\WpCodeTool\Woo\FakeCard::hooks();

    // Envoi des e-mails par SMTP, test, journal 15 jours, alertes et import WP Mail SMTP (CodeTool › E-mails).
    \Smbb\WpCodeTool\Mail\Mailer::hooks();

    // File d'envoi commune : SMS et e-mails groupés diffusés par lots (CodeTool › Communication › File d'envoi).
    // Les plugins y déposent avec Outbox::queue() et se déclarent par le filtre smbb_wpcodetool_outbox_sources.
    \Smbb\WpCodeTool\Outbox\Outbox::hooks();

    // En direct : bulle discrète + petit son dans l'admin à chaque nouvelle vente (commandes WooCommerce payées,
    // enrichies par les plugins via smbb_wpcodetool_live_order_event ; autres événements : Live\LiveFeed::push()).
    \Smbb\WpCodeTool\Live\LiveFeed::hooks();

    // Sécurité de la connexion : Turnstile, limitation des tentatives, double authentification (CodeTool › Sécurité).
    // Coupée d'un coup par define('SMBB_SECURITY_OFF', true); dans wp-config.php (accès de secours).
    \Smbb\WpCodeTool\Security\Security::hooks();

    // Fiche utilisateur : champ « Téléphone » (méta billing_phone) quand WooCommerce n'est pas actif.
    \Smbb\WpCodeTool\Support\UserPhone::hooks();

    // Journal d'activité : qui a fait quoi et quand (CodeTool › Sécurité › Journal d'activité).
    // Les plugins y écrivent avec do_action('smbb_wpcodetool_activity', 'evenement', array(...)).
    \Smbb\WpCodeTool\Activity\ActivityLog::hooks();

    // Unités de stockage (CodeTool › Réglages › Stockage) : WordPress par défaut, S3… Plugins : Storage::disk($id).
    \Smbb\WpCodeTool\Storage\Storage::boot();

    // Formulaires : builder, bloc « Formulaire », entrées, e-mails/SMS, reprise WPForms (menu Formulaires).
    // Coupé par add_filter('smbb_wpcodetool_forms_enabled', '__return_false');
    \Smbb\WpCodeTool\Forms\Forms::hooks();

    // Contenu WP : blog, commentaires, pages de médias, pages auteur, XML-RPC, émojis (réglage CodeTool › Contenu WP).
    // Sur init 0, juste après l'enregistrement des types de contenu de WordPress.
    add_action('init', array(\Smbb\WpCodeTool\Admin\WpContent::class, 'hooks'), 0);

    // Médiathèque : « Remplacer le fichier » (même ID, même URL) et envoi de SVG nettoyés (réglage CodeTool › Contenu WP).
    add_action('init', array(\Smbb\WpCodeTool\Media\MediaTools::class, 'hooks'), 0);

    // Médiathèque : catégories colorées (arborescence, pastilles, filtre, classement par glisser-déposer).
    add_action('init', array(\Smbb\WpCodeTool\Media\MediaCategories::class, 'hooks'), 0);

    // Marque blanche et ménage (favicon, barre du haut, accueil, colonnes, Ctrl K).
    // Sur init : les réglages utilisent des traductions.
    add_action('init', array(\Smbb\WpCodeTool\Admin\WpExtras::class, 'hooks'), 0);

    $api = new \Smbb\WpCodeTool\Api\ApiManager($scanner);
    $api->hooks();

    $docs = new \Smbb\WpCodeTool\Api\ApiDocsShortcode($scanner);
    $docs->hooks();

    $routes = new \Smbb\WpCodeTool\Route\PublicRouteDispatcher();
    $routes->hooks();

    $cron = new \Smbb\WpCodeTool\Cron\CronManager();
    $cron->hooks();

    do_action('smbb_wpcodetool_loaded');
}
