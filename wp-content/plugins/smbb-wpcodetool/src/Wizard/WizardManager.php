<?php

namespace Smbb\WpCodeTool\Wizard;

use Smbb\WpCodeTool\Admin\Form;
use Smbb\WpCodeTool\Admin\Pages\AppearancePage;
use Smbb\WpCodeTool\Admin\Skin;
use Smbb\WpCodeTool\Resource\ResourceDefinition;
use Smbb\WpCodeTool\Resource\ResourceMutationService;
use Smbb\WpCodeTool\Resource\ResourceRuntime;
use Smbb\WpCodeTool\Resource\ResourceScanner;

defined('ABSPATH') || exit;

/**
 * Assistants de démarrage des plugins CodeTool (codetool/wizards/*.json).
 *
 * Une page d'admin cachée (admin.php?page=smbb-wpcodetool-wizard&wizard=<nom>) affiche les étapes
 * l'une après l'autre. Chaque étape enregistre tout de suite : on peut quitter, reprendre, revenir.
 *
 * Types d'étapes :
 * - appearance : version courte de CodeTool › Apparence (allure, palette, « tout moderniser ») ;
 * - plugins    : « plugins »: [{ "slug", "name", "file"?, "skipOnboarding"? }] installés et activés ;
 * - form       : « resource » (ressource option) + « view » : la view remplit $fields avec le
 *                $form habituel ; l'enregistrement fusionne dans l'option et passe par ses hooks ;
 * - profiles   : « dir » : cartes lues dans <plugin>/<dir>/<clé>/profile.json, appliquées par
 *                le hook applyProfile() ;
 * - view       : « view » libre, enregistrée par le hook saveStep() ;
 * - finish     : dernière page (« view » facultative), bouton Terminer.
 *
 * Hooks facultatifs de la classe déclarée (même principe que les modèles) :
 * stepVisible($key, $ctx) bool · stepDone($key, $ctx) bool|null · stepContext($key, $ctx) array ·
 * saveStep($key, array $data, $ctx) true|string|WP_Error|array{message?,redirect?,stay?} ·
 * applyProfile($key, array $profile, $ctx) true|string|WP_Error · finish($ctx) string|null (URL).
 * Filtre smbb_wpcodetool_wizard_steps ($steps, $wizard) sur les étapes visibles.
 */
final class WizardManager
{
    const SLUG = 'smbb-wpcodetool-wizard';
    const NONCE = 'smbb_codetool_wizard';
    const REDIRECT = 'smbb_codetool_wizard_redirect';

    /** Interrupteurs cochés par « Tout moderniser ». */
    const MODERN_PACK = array('modern', 'icons', 'wp_chrome', 'wp_screens', 'list_ux', 'wp_media', 'wp_notices', 'wp_profile', 'wc_account', 'dashboard_clean', 'adminbar_clean', 'palette', 'fx_toasts', 'mode_toggle');

    /** @var ResourceScanner */
    private $scanner;

    /** @var array<string,object|null> */
    private static $hooks = array();

    /** @var array{errors:array<string,string>,message:string,item:array|null} */
    private $failure = array('errors' => array(), 'message' => '', 'item' => null);

    public function __construct(ResourceScanner $scanner)
    {
        $this->scanner = $scanner;
    }

    public function hooks()
    {
        add_action('admin_menu', array($this, 'registerPage'));
        add_action('admin_init', array($this, 'maybeRedirect'), 1);
        add_action('admin_init', array($this, 'handle'));
        add_action('activated_plugin', array($this, 'onActivated'), 10, 2);
        add_action('admin_notices', array($this, 'banner'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue'));
        add_filter('admin_body_class', array($this, 'bodyClass'));

        // Un assistant remplace celui de WooCommerce : jamais de redirection vers le sien.
        add_action('plugins_loaded', function () {
            foreach (WizardScanner::all() as $wizard) {
                foreach ($wizard->steps() as $step) {
                    if ($step['type'] !== 'plugins' || empty($step['plugins'])) {
                        continue;
                    }

                    foreach ((array) $step['plugins'] as $plugin) {
                        if (is_array($plugin) && !empty($plugin['skipOnboarding']) && isset($plugin['slug']) && $plugin['slug'] === 'woocommerce') {
                            PluginInstaller::woocommerceGuards();

                            return;
                        }
                    }
                }
            }
        }, 30);
    }

    // ------------------------------------------------------------------
    // API publique
    // ------------------------------------------------------------------

    /**
     * Lien vers l'assistant (une étape précise si $step).
     */
    public static function url($name, $step = '', array $args = array())
    {
        $args = array_merge(array('page' => self::SLUG, 'wizard' => sanitize_key((string) $name)), $step !== '' ? array('step' => sanitize_key((string) $step)) : array(), $args);

        return add_query_arg($args, admin_url('admin.php'));
    }

    /**
     * Avancement : array{done:int,total:int,finished:bool,next:string}.
     */
    public static function progress($name)
    {
        $wizard = WizardScanner::find($name);

        if (!$wizard) {
            return array('done' => 0, 'total' => 0, 'finished' => false, 'next' => '');
        }

        $state = new WizardState($wizard->name());
        $steps = self::visibleSteps($wizard, $state);
        $done = 0;
        $next = '';

        foreach ($steps as $step) {
            if (self::stepDone($wizard, $step, $state) || $state->isSkipped($step['key'])) {
                $done++;
            } elseif ($next === '' && $step['type'] !== 'finish') {
                $next = $step['key'];
            }
        }

        return array('done' => $done, 'total' => count($steps), 'finished' => $state->finished(), 'next' => $next);
    }

    /**
     * Instance de la classe de hooks d'un assistant (ou null).
     */
    public static function hooksFor(WizardDefinition $wizard)
    {
        $name = $wizard->name();

        if (array_key_exists($name, self::$hooks)) {
            return self::$hooks[$name];
        }

        $file = $wizard->hookFile();
        $class = $wizard->hookClass();

        if ($file && is_readable($file)) {
            require_once $file;
        }

        return self::$hooks[$name] = ($class !== '' && class_exists($class)) ? new $class() : null;
    }

    /**
     * Profils proposés par une étape « profiles » : clé => {key, dir, profile, extras, image}.
     *
     * @return array<string,array<string,mixed>>
     */
    public static function profiles(WizardDefinition $wizard, array $step)
    {
        $base = $wizard->pluginPath(isset($step['dir']) ? $step['dir'] : 'profiles');
        $out = array();

        if (!$base || !is_dir($base)) {
            return $out;
        }

        foreach ((array) glob($base . DIRECTORY_SEPARATOR . '*' . DIRECTORY_SEPARATOR . 'profile.json') as $file) {
            $dir = dirname($file);
            $key = sanitize_key(basename($dir));
            $data = json_decode((string) file_get_contents($file), true);

            if ($key === '' || !is_array($data)) {
                continue;
            }

            $extras = is_readable($dir . DIRECTORY_SEPARATOR . 'extras.json') ? json_decode((string) file_get_contents($dir . DIRECTORY_SEPARATOR . 'extras.json'), true) : array();
            $header = isset($data['profile']) && is_array($data['profile']) ? $data['profile'] : array();
            $image = '';

            foreach (array('card.jpg', 'card.png', 'card.webp') as $candidate) {
                if (is_readable($dir . DIRECTORY_SEPARATOR . $candidate)) {
                    $image = plugins_url(basename($base) . '/' . $key . '/' . $candidate, $wizard->pluginDir() . DIRECTORY_SEPARATOR . 'x.php');
                    break;
                }
            }

            $out[$key] = array(
                'key' => $key,
                'dir' => $dir,
                'title' => isset($header['title']) ? (string) $header['title'] : $key,
                'description' => isset($header['description']) ? (string) $header['description'] : '',
                'icon' => isset($header['icon']) ? (string) $header['icon'] : 'dashicons-admin-generic',
                'order' => isset($header['order']) ? (int) $header['order'] : 50,
                'image' => $image,
                'profile' => $data,
                'extras' => is_array($extras) ? $extras : array(),
            );
        }

        uasort($out, function ($a, $b) {
            return $a['order'] === $b['order'] ? strcmp($a['title'], $b['title']) : $a['order'] - $b['order'];
        });

        return $out;
    }

    // ------------------------------------------------------------------
    // Branchements WordPress
    // ------------------------------------------------------------------

    public function registerPage()
    {
        // Sous options.php : page accessible, jamais listée dans un menu.
        add_submenu_page(
            'options.php',
            __('Assistant de démarrage', 'smbb-wpcodetool'),
            __('Assistant de démarrage', 'smbb-wpcodetool'),
            'read',
            self::SLUG,
            array($this, 'render')
        );
    }

    /**
     * Activation d'un plugin qui déclare un assistant : on l'ouvrira à la prochaine page d'admin.
     */
    public function onActivated($plugin, $network = false)
    {
        if ($network || (defined('WP_CLI') && WP_CLI)) {
            return;
        }

        $dir = WP_PLUGIN_DIR . DIRECTORY_SEPARATOR . dirname((string) $plugin);

        foreach (WizardScanner::fromPluginDir($dir) as $wizard) {
            $state = new WizardState($wizard->name());

            if ($wizard->redirectOnActivation() && !$state->finished()) {
                set_transient(self::REDIRECT, $wizard->name(), 60);

                return;
            }
        }
    }

    public function maybeRedirect()
    {
        $name = get_transient(self::REDIRECT);

        if (!$name || wp_doing_ajax() || (defined('DOING_CRON') && DOING_CRON) || isset($_GET['activate-multi'])) { // phpcs:ignore
            return;
        }

        delete_transient(self::REDIRECT);
        $wizard = WizardScanner::find($name);

        if ($wizard && current_user_can($wizard->capability())) {
            wp_safe_redirect(self::url($wizard->name()));
            exit;
        }
    }

    public function enqueue()
    {
        if (!$this->onWizardPage() && !$this->bannerWizard()) {
            return;
        }

        wp_enqueue_style('smbb-wpcodetool-wizard', SMBB_WPCODETOOL_URL . 'assets/wizard.css', array(), (string) @filemtime(SMBB_WPCODETOOL_PATH . 'assets/wizard.css'));
    }

    public function bodyClass($classes)
    {
        return $this->onWizardPage() ? $classes . ' smbb-wizard-page' : $classes;
    }

    private function onWizardPage()
    {
        return isset($_GET['page']) && $_GET['page'] === self::SLUG; // phpcs:ignore
    }

    /**
     * Assistant à rappeler par un bandeau sur l'écran courant (accueil ou pages du plugin), ou null.
     */
    private function bannerWizard()
    {
        if ($this->onWizardPage()) {
            return null;
        }

        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : ''; // phpcs:ignore

        foreach (WizardScanner::all() as $wizard) {
            $here = ($screen && $screen->id === 'dashboard') || ($wizard->menuParent() !== '' && $page !== '' && strpos($page, $wizard->menuParent()) === 0);

            if (!$here || !current_user_can($wizard->capability())) {
                continue;
            }

            $state = new WizardState($wizard->name());

            if (!$state->finished() && !$state->hidden()) {
                return $wizard;
            }
        }

        return null;
    }

    public function banner()
    {
        $wizard = $this->bannerWizard();

        if (!$wizard) {
            return;
        }

        $progress = self::progress($wizard->name());
        $hide = wp_nonce_url(self::url($wizard->name(), '', array('wizard_action' => 'hide')), self::NONCE . '_hide');
        $label = (new WizardState($wizard->name()))->started() ? __('Reprendre la configuration', 'smbb-wpcodetool') : __('Lancer la configuration', 'smbb-wpcodetool');

        echo '<div class="notice smbb-wizard-banner"><p>'
            . '<span class="dashicons dashicons-flag" aria-hidden="true"></span> '
            . '<strong>' . esc_html($wizard->title()) . '</strong> '
            . '<span class="smbb-wizard-banner-count">' . esc_html(sprintf(__('%1$d étape(s) sur %2$d', 'smbb-wpcodetool'), $progress['done'], $progress['total'])) . '</span> '
            . '<a class="button button-primary" href="' . esc_url(self::url($wizard->name(), $progress['next'])) . '">' . esc_html($label) . '</a> '
            . '<a class="smbb-wizard-banner-hide" href="' . esc_url($hide) . '">' . esc_html__('Plus tard', 'smbb-wpcodetool') . '</a>'
            . '</p></div>';
    }

    // ------------------------------------------------------------------
    // Étapes
    // ------------------------------------------------------------------

    /**
     * @return array<int,array<string,mixed>>
     */
    public static function visibleSteps(WizardDefinition $wizard, WizardState $state)
    {
        $hooks = self::hooksFor($wizard);
        $out = array();

        foreach ($wizard->steps() as $step) {
            if ($hooks && method_exists($hooks, 'stepVisible') && !$hooks->stepVisible($step['key'], self::context($wizard, $state, $step))) {
                continue;
            }

            $out[] = $step;
        }

        $out = apply_filters('smbb_wpcodetool_wizard_steps', $out, $wizard);

        return is_array($out) ? array_values($out) : array();
    }

    public static function stepDone(WizardDefinition $wizard, array $step, WizardState $state)
    {
        if ($step['type'] === 'plugins') {
            foreach (self::stepPlugins($step) as $plugin) {
                if (PluginInstaller::status($plugin['slug'], $plugin['file']) !== 'active') {
                    return false;
                }
            }

            return true;
        }

        if ($step['type'] === 'finish') {
            return $state->finished();
        }

        $hooks = self::hooksFor($wizard);

        if ($hooks && method_exists($hooks, 'stepDone')) {
            $done = $hooks->stepDone($step['key'], self::context($wizard, $state, $step));

            if ($done !== null) {
                return (bool) $done || $state->isDone($step['key']);
            }
        }

        return $state->isDone($step['key']);
    }

    /**
     * @return array<int,array{slug:string,name:string,file:string,skipOnboarding:bool}>
     */
    private static function stepPlugins(array $step)
    {
        $out = array();

        foreach (isset($step['plugins']) ? (array) $step['plugins'] : array() as $plugin) {
            if (!is_array($plugin) || empty($plugin['slug'])) {
                continue;
            }

            $out[] = array(
                'slug' => sanitize_key((string) $plugin['slug']),
                'name' => isset($plugin['name']) ? (string) $plugin['name'] : (string) $plugin['slug'],
                'file' => isset($plugin['file']) ? (string) $plugin['file'] : '',
                'description' => isset($plugin['description']) ? (string) $plugin['description'] : '',
                'skipOnboarding' => !empty($plugin['skipOnboarding']),
            );
        }

        return $out;
    }

    private static function context(WizardDefinition $wizard, WizardState $state, array $step)
    {
        return array('wizard' => $wizard, 'state' => $state, 'step' => $step);
    }

    private function resource($name)
    {
        foreach ($this->scanner->scan() as $resource) {
            if ($resource->name() === $name) {
                return $resource;
            }
        }

        return null;
    }

    /**
     * Étape suivante visible après $key ('' = aucune).
     */
    private static function nextKey(array $steps, $key)
    {
        $found = false;

        foreach ($steps as $step) {
            if ($found) {
                return $step['key'];
            }

            $found = $step['key'] === $key;
        }

        return '';
    }

    private static function previousKey(array $steps, $key)
    {
        $previous = '';

        foreach ($steps as $step) {
            if ($step['key'] === $key) {
                return $previous;
            }

            $previous = $step['key'];
        }

        return '';
    }

    /**
     * Étape affichée : celle demandée, sinon la première ni faite ni passée.
     */
    private static function currentStep(WizardDefinition $wizard, WizardState $state, array $steps)
    {
        $asked = isset($_GET['step']) ? sanitize_key(wp_unslash($_GET['step'])) : ''; // phpcs:ignore

        foreach ($steps as $step) {
            if ($step['key'] === $asked) {
                return $step;
            }
        }

        foreach ($steps as $step) {
            if (!self::stepDone($wizard, $step, $state) && !$state->isSkipped($step['key'])) {
                return $step;
            }
        }

        return $steps ? $steps[count($steps) - 1] : null;
    }

    // ------------------------------------------------------------------
    // Actions (avant tout affichage)
    // ------------------------------------------------------------------

    public function handle()
    {
        if (!$this->onWizardPage()) {
            return;
        }

        $wizard = WizardScanner::find(isset($_GET['wizard']) ? wp_unslash($_GET['wizard']) : ''); // phpcs:ignore

        if (!$wizard) {
            return;
        }

        if (!current_user_can($wizard->capability())) {
            wp_die(esc_html__('Vous n\'avez pas le droit d\'ouvrir cet assistant.', 'smbb-wpcodetool'));
        }

        $state = new WizardState($wizard->name());
        $steps = self::visibleSteps($wizard, $state);

        // Liens en GET : masquer le bandeau, reprendre depuis le début.
        $get_action = isset($_GET['wizard_action']) ? sanitize_key(wp_unslash($_GET['wizard_action'])) : ''; // phpcs:ignore

        if ($get_action === 'hide' && check_admin_referer(self::NONCE . '_hide')) {
            $state->hideFor(7);
            $state->save();
            wp_safe_redirect(wp_get_referer() ?: admin_url());
            exit;
        }

        if ($get_action === 'reopen' && check_admin_referer(self::NONCE . '_reopen')) {
            $state->reopen();
            $state->save();
            wp_safe_redirect(self::url($wizard->name(), $steps ? $steps[0]['key'] : ''));
            exit;
        }

        if (!isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['_smbb_wizard_step'])) {
            return;
        }

        check_admin_referer(self::NONCE . '_' . $wizard->name());

        $key = sanitize_key(wp_unslash($_POST['_smbb_wizard_step']));
        $step = null;

        foreach ($steps as $candidate) {
            if ($candidate['key'] === $key) {
                $step = $candidate;
            }
        }

        if (!$step) {
            return;
        }

        $action = isset($_POST['_smbb_wizard_action']) ? sanitize_key(wp_unslash($_POST['_smbb_wizard_action'])) : 'next';
        $next = self::nextKey($steps, $key);

        if ($action === 'skip') {
            $state->markSkipped($key);
            $state->setCurrent($next);
            $state->save();
            $this->go($wizard, $next);
        }

        $result = $this->save($wizard, $state, $step, $action);

        if ($result === true || (is_array($result) && empty($result['error']))) {
            $redirect = is_array($result) && !empty($result['redirect']) ? (string) $result['redirect'] : '';
            $stay = is_array($result) && !empty($result['stay']);

            if (!$stay && $step['type'] !== 'plugins') {
                $state->markDone($key);
            }

            $state->setCurrent($stay ? $key : $next);
            $state->save();

            if (is_array($result) && !empty($result['message'])) {
                set_transient('smbb_wizard_msg_' . get_current_user_id(), (string) $result['message'], 60);
            }

            if ($redirect !== '') {
                wp_safe_redirect($redirect);
                exit;
            }

            $this->go($wizard, $stay ? $key : $next);
        }

        $this->failure['message'] = is_array($result) ? (string) $result['error'] : (string) $result;
    }

    private function go(WizardDefinition $wizard, $key)
    {
        wp_safe_redirect(self::url($wizard->name(), $key));
        exit;
    }

    /**
     * Données postées sans les champs techniques (nonce, _smbb_*, _wp*).
     */
    private static function posted()
    {
        $data = array();

        foreach (wp_unslash($_POST) as $key => $value) { // phpcs:ignore
            $key = (string) $key;

            if ($key === 'submit' || strpos($key, '_wp') === 0 || strpos($key, '_smbb_') === 0) {
                continue;
            }

            $data[$key] = $value;
        }

        return $data;
    }

    /**
     * Champs marqués obligatoires restés vides : nom => message.
     */
    private static function requiredErrors(array $data)
    {
        $errors = array();

        foreach (isset($_POST['_smbb_required']) ? (array) wp_unslash($_POST['_smbb_required']) : array() as $name) { // phpcs:ignore
            $name = (string) $name;
            $value = isset($data[$name]) ? $data[$name] : '';

            if ((is_string($value) && trim($value) === '') || (is_array($value) && !$value)) {
                $errors[$name] = __('Ce champ est obligatoire.', 'smbb-wpcodetool');
            }
        }

        return $errors;
    }

    /**
     * Enregistre une étape. Retourne true, array{message?,redirect?,stay?} ou un message d'erreur.
     */
    private function save(WizardDefinition $wizard, WizardState $state, array $step, $action)
    {
        $hooks = self::hooksFor($wizard);
        $ctx = self::context($wizard, $state, $step);
        $data = self::posted();

        switch ($step['type']) {
            case 'plugins':
                if ($action === 'install') {
                    foreach (self::stepPlugins($step) as $plugin) {
                        $result = PluginInstaller::installAndActivate($plugin['slug'], $plugin['file']);

                        if ($result !== true) {
                            return sprintf(__('%1$s : %2$s', 'smbb-wpcodetool'), $plugin['name'], $result);
                        }

                        if ($plugin['skipOnboarding']) {
                            PluginInstaller::skipOnboarding($plugin['slug']);
                        }
                    }

                    // Le plugin n'est pas encore initialisé dans cette requête : le hook saveStep()
                    // (réglages de départ du plugin installé) passe au clic suivant, « Continuer ».
                    return array('stay' => true, 'message' => __('Extension installée et activée. Continuez pour la régler.', 'smbb-wpcodetool'));
                }

                if (!self::stepDone($wizard, $step, $state)) {
                    return __('Installez et activez d\'abord les extensions demandées.', 'smbb-wpcodetool');
                }

                $state->markDone($step['key']);

                return self::afterHook($hooks, $step['key'], $data, $ctx, true);

            case 'appearance':
                return $this->saveAppearance($data) === true ? self::afterHook($hooks, $step['key'], $data, $ctx, true) : __('Apparence non enregistrée.', 'smbb-wpcodetool');

            case 'form':
                $resource = $this->resource(isset($step['resource']) ? (string) $step['resource'] : '');

                if (!$resource || $resource->storageType() !== 'option') {
                    return __('Étape mal déclarée : « resource » doit être une ressource de type option.', 'smbb-wpcodetool');
                }

                $errors = self::requiredErrors($data);
                $service = new ResourceMutationService(new ResourceRuntime());
                $result = $errors ? array('success' => false, 'reason' => 'validation', 'errors' => $errors, 'data' => $data) : $service->saveOption($resource, $data, array('merge_current' => true, 'action' => 'wizard'));

                if (empty($result['success'])) {
                    $this->failure['errors'] = isset($result['errors']) && is_array($result['errors']) ? array_map(function ($e) {
                        return is_array($e) ? implode(' ', $e) : (string) $e;
                    }, $result['errors']) : array();
                    $this->failure['item'] = isset($result['data']) && is_array($result['data']) ? $result['data'] : $data;

                    return !empty($result['message']) ? (string) $result['message'] : __('Le formulaire contient des erreurs.', 'smbb-wpcodetool');
                }

                return self::afterHook($hooks, $step['key'], $data, $ctx, true);

            case 'profiles':
                $key = isset($_POST['_smbb_wizard_profile']) ? sanitize_key(wp_unslash($_POST['_smbb_wizard_profile'])) : '';
                $profiles = self::profiles($wizard, $step);

                if ($key === '' || !isset($profiles[$key])) {
                    return __('Choisissez un profil.', 'smbb-wpcodetool');
                }

                if (!$hooks || !method_exists($hooks, 'applyProfile')) {
                    return __('Ce plugin ne sait pas appliquer de profil (hook applyProfile absent).', 'smbb-wpcodetool');
                }

                $applied = $hooks->applyProfile($key, $profiles[$key], $ctx);

                if ($applied !== true && !is_array($applied)) {
                    return is_wp_error($applied) ? $applied->get_error_message() : (string) $applied;
                }

                $state->setData($step['key'], $key);

                return self::afterHook($hooks, $step['key'], $data, $ctx, is_array($applied) ? $applied : true);

            case 'finish':
                $before = self::afterHook($hooks, $step['key'], $data, $ctx, true);

                if ($before !== true && !is_array($before)) {
                    return $before;
                }

                $state->finish();
                $url = $hooks && method_exists($hooks, 'finish') ? $hooks->finish($ctx) : null;
                $state->markDone($step['key']);

                return array('redirect' => is_string($url) && $url !== '' ? $url : admin_url());

            case 'view':
            default:
                $errors = self::requiredErrors($data);

                if ($errors) {
                    $this->failure['errors'] = $errors;
                    $this->failure['item'] = $data;

                    return __('Le formulaire contient des erreurs.', 'smbb-wpcodetool');
                }

                return self::afterHook($hooks, $step['key'], $data, $ctx, true);
        }
    }

    /**
     * Appelle saveStep() s'il existe ; $default sinon.
     */
    private static function afterHook($hooks, $key, array $data, array $ctx, $default)
    {
        if (!$hooks || !method_exists($hooks, 'saveStep')) {
            return $default;
        }

        $result = $hooks->saveStep($key, $data, $ctx);

        if ($result === null || $result === true) {
            return $default;
        }

        if (is_wp_error($result)) {
            return $result->get_error_message();
        }

        if (is_array($result)) {
            return is_array($default) ? array_merge($default, $result) : $result;
        }

        return (string) $result;
    }

    private function saveAppearance(array $data)
    {
        if (!current_user_can('manage_options')) {
            return false;
        }

        $current = Skin::settings();
        $themes = Skin::themes();
        $theme = isset($data['theme']) ? sanitize_key((string) $data['theme']) : $current['theme'];
        $extra = array(
            'preset' => isset($data['preset']) ? sanitize_key((string) $data['preset']) : $current['preset'],
            'mode' => isset($data['mode']) ? sanitize_key((string) $data['mode']) : $current['mode'],
            'modern' => !empty($data['modern']) ? 1 : 0,
        );

        if (!empty($data['modern_pack'])) {
            foreach (self::MODERN_PACK as $switch) {
                $extra[$switch] = 1;
            }

            if ($extra['preset'] === 'cristal') {
                $extra['fx_glass'] = 1;
            }
        }

        if ($theme === Skin::CUSTOM || !isset($themes[$theme])) {
            $theme = $current['theme'];
        }

        Skin::saveSettings($theme, $current['accent'], $extra);

        return true;
    }

    // ------------------------------------------------------------------
    // Affichage
    // ------------------------------------------------------------------

    public function render()
    {
        $wizard = WizardScanner::find(isset($_GET['wizard']) ? wp_unslash($_GET['wizard']) : ''); // phpcs:ignore

        if (!$wizard) {
            echo '<div class="wrap"><p>' . esc_html__('Assistant introuvable (plugin désactivé ?).', 'smbb-wpcodetool') . '</p></div>';

            return;
        }

        $state = new WizardState($wizard->name());
        $steps = self::visibleSteps($wizard, $state);
        $step = self::currentStep($wizard, $state, $steps);

        if (!$step) {
            return;
        }

        $hooks = self::hooksFor($wizard);
        $ctx = self::context($wizard, $state, $step);
        $message = get_transient('smbb_wizard_msg_' . get_current_user_id());

        if ($message) {
            delete_transient('smbb_wizard_msg_' . get_current_user_id());
        }

        $exit = $wizard->menuParent() !== '' ? admin_url('admin.php?page=' . $wizard->menuParent()) : admin_url();
        $index = 0;

        foreach ($steps as $i => $candidate) {
            if ($candidate['key'] === $step['key']) {
                $index = $i;
            }
        }

        echo '<div class="wrap smbb-codetool smbb-wizard">';
        echo '<header class="smbb-wizard-head"><div><h1>' . esc_html($wizard->title()) . '</h1>';

        if ($wizard->description() !== '') {
            echo '<p class="description">' . esc_html($wizard->description()) . '</p>';
        }

        echo '</div><a class="smbb-wizard-exit" href="' . esc_url($exit) . '"><span class="dashicons dashicons-no-alt" aria-hidden="true"></span> ' . esc_html__('Quitter l\'assistant', 'smbb-wpcodetool') . '</a></header>';

        echo '<div class="smbb-wizard-layout"><ol class="smbb-wizard-steps">';

        foreach ($steps as $i => $candidate) {
            $done = self::stepDone($wizard, $candidate, $state);
            $class = 'smbb-wizard-step' . ($candidate['key'] === $step['key'] ? ' is-current' : '') . ($done ? ' is-done' : ($state->isSkipped($candidate['key']) ? ' is-skipped' : ''));
            echo '<li class="' . esc_attr($class) . '"><a href="' . esc_url(self::url($wizard->name(), $candidate['key'])) . '">'
                . '<span class="smbb-wizard-step-num">' . ($done ? '<span class="dashicons dashicons-yes" aria-hidden="true"></span>' : esc_html((string) $i)) . '</span>'
                . '<span class="smbb-wizard-step-label">' . esc_html($candidate['title'])
                . ($candidate['optional'] ? ' <em>' . esc_html__('facultatif', 'smbb-wpcodetool') . '</em>' : '') . '</span></a></li>';
        }

        echo '</ol><section class="smbb-wizard-card">';
        echo '<header class="smbb-wizard-card-head">'
            . ($step['icon'] !== '' ? '<span class="smbb-codetool-section-icon" aria-hidden="true"><span class="dashicons ' . esc_attr($step['icon']) . '"></span></span>' : '')
            . '<div><p class="smbb-wizard-kicker">' . esc_html(sprintf(__('Étape %1$d sur %2$d', 'smbb-wpcodetool'), $index, count($steps) - 1)) . '</p>'
            . '<h2>' . esc_html($step['title']) . '</h2>'
            . ($step['description'] !== '' ? '<p class="description">' . esc_html($step['description']) . '</p>' : '')
            . '</div></header>';

        if ($message) {
            echo '<div class="smbb-codetool-notice is-success"><p class="smbb-codetool-notice-message">' . esc_html($message) . '</p></div>';
        }

        if ($this->failure['message'] !== '') {
            echo '<div class="smbb-codetool-notice is-error"><p class="smbb-codetool-notice-message">' . esc_html($this->failure['message']) . '</p>';

            if ($this->failure['errors']) {
                echo '<ul>';

                foreach ($this->failure['errors'] as $error) {
                    echo '<li>' . esc_html($error) . '</li>';
                }

                echo '</ul>';
            }

            echo '</div>';
        }

        $body = $this->stepBody($wizard, $state, $step, $hooks, $ctx);
        $previous = self::previousKey($steps, $step['key']);
        $is_last = $step['type'] === 'finish';
        $blocked = $step['type'] === 'plugins' && !self::stepDone($wizard, $step, $state);

        echo '<form method="post" class="smbb-wizard-form" enctype="multipart/form-data" action="' . esc_url(self::url($wizard->name(), $step['key'])) . '">';
        wp_nonce_field(self::NONCE . '_' . $wizard->name());
        echo '<input type="hidden" name="_smbb_wizard_step" value="' . esc_attr($step['key']) . '">';
        echo '<div class="smbb-wizard-body">' . $body . '</div>'; // phpcs:ignore -- HTML des éléments de formulaire, échappé par eux.
        echo '<footer class="smbb-wizard-foot">';
        echo $previous !== '' ? '<a class="button" href="' . esc_url(self::url($wizard->name(), $previous)) . '">← ' . esc_html__('Retour', 'smbb-wpcodetool') . '</a>' : '<span></span>';
        echo '<span class="smbb-wizard-foot-right">';

        if ($step['optional'] && !$is_last) {
            echo '<button type="submit" class="button-link" name="_smbb_wizard_action" value="skip" formnovalidate>' . esc_html__('Passer cette étape', 'smbb-wpcodetool') . '</button> ';
        }

        if ($blocked) {
            echo '<button type="submit" class="button button-primary button-hero" name="_smbb_wizard_action" value="install">' . esc_html__('Installer et activer', 'smbb-wpcodetool') . '</button>';
        } else {
            echo '<button type="submit" class="button button-primary button-hero" name="_smbb_wizard_action" value="next">' . esc_html($is_last ? __('Terminer', 'smbb-wpcodetool') : (isset($step['button']) ? (string) $step['button'] : __('Enregistrer et continuer', 'smbb-wpcodetool'))) . ' →</button>';
        }

        echo '</span></footer></form></section></div></div>';
    }

    /**
     * HTML du corps de l'étape (sans <form> : l'assistant l'ouvre lui-même).
     */
    private function stepBody(WizardDefinition $wizard, WizardState $state, array $step, $hooks, array $ctx)
    {
        switch ($step['type']) {
            case 'plugins':
                return $this->pluginsBody($step) . $this->extraView($wizard, $state, $step, $hooks, $ctx);
            case 'appearance':
                return $this->appearanceBody() . $this->extraView($wizard, $state, $step, $hooks, $ctx);
            case 'profiles':
                return $this->profilesBody($wizard, $state, $step);
        }

        // form, view, finish : une view PHP du plugin.
        $view = isset($step['view']) ? $wizard->codetoolPath($step['view']) : null;

        if (!$view || !is_readable($view)) {
            return $step['type'] === 'finish' ? '<p>' . esc_html__('Tout est prêt.', 'smbb-wpcodetool') . '</p>' : '<p>' . esc_html__('Vue introuvable pour cette étape.', 'smbb-wpcodetool') . '</p>';
        }

        $item = array();
        $resource = null;

        if ($step['type'] === 'form') {
            $resource = $this->resource(isset($step['resource']) ? (string) $step['resource'] : '');

            if ($resource && $resource->storageType() === 'option') {
                $saved = get_option($resource->optionName(), array());
                $item = array_merge($resource->optionDefaults(), is_array($saved) ? $saved : array());
            }
        }

        if (is_array($this->failure['item'])) {
            $item = array_merge($item, $this->failure['item']);
        }

        $form = new Form(array(
            'action_url' => self::url($wizard->name(), $step['key']),
            'item' => $item,
            'field_errors' => $this->failure['errors'],
            'nonce_action' => self::NONCE . '_' . $wizard->name(),
            'resource_name' => $resource ? $resource->name() : '',
            'resource' => $resource,
            'requested_id' => null,
            'resources' => $this->scanner->scan(),
            'store' => null,
        ));

        $vars = $hooks && method_exists($hooks, 'stepContext') ? (array) $hooks->stepContext($step['key'], $ctx) : array();

        return self::includeView($view, array_merge($vars, array(
            'form' => $form,
            'item' => $item,
            'wizard' => $wizard,
            'state' => $state,
            'step' => $step,
        )));
    }

    /**
     * Inclut une view : elle peut écrire du HTML et/ou remplir $fields (éléments du $form).
     */
    private static function includeView($__view, array $__vars)
    {
        extract($__vars, EXTR_SKIP); // phpcs:ignore
        $fields = array();
        ob_start();
        include $__view;
        $html = (string) ob_get_clean();

        foreach ((array) $fields as $field) {
            $html .= (string) $field;
        }

        return $html;
    }

    private function pluginsBody(array $step)
    {
        $html = '<ul class="smbb-wizard-plugins">';

        foreach (self::stepPlugins($step) as $plugin) {
            $status = PluginInstaller::status($plugin['slug'], $plugin['file']);
            $labels = array(
                'active' => __('Installée et active', 'smbb-wpcodetool'),
                'installed' => __('Installée, pas encore active', 'smbb-wpcodetool'),
                'missing' => __('À installer depuis wordpress.org', 'smbb-wpcodetool'),
            );
            $html .= '<li class="is-' . esc_attr($status) . '"><span class="dashicons ' . ($status === 'active' ? 'dashicons-yes-alt' : 'dashicons-download') . '" aria-hidden="true"></span>'
                . '<span><strong>' . esc_html($plugin['name']) . '</strong> · ' . esc_html($labels[$status])
                . ($plugin['description'] !== '' ? '<br><span class="description">' . esc_html($plugin['description']) . '</span>' : '')
                . ($plugin['skipOnboarding'] && $status !== 'active' ? '<br><span class="description">' . esc_html__('Son propre assistant de démarrage sera passé : celui-ci le remplace.', 'smbb-wpcodetool') . '</span>' : '')
                . '</span></li>';
        }

        return $html . '</ul>';
    }

    private function appearanceBody()
    {
        $settings = Skin::settings();
        $themes = Skin::themes();
        $site = $settings['theme'] !== '' ? $settings['theme'] : Skin::siteTheme();
        unset($themes[Skin::CUSTOM]);

        ob_start();
        AppearancePage::toggle('modern', $settings['modern'], __('Style moderne', 'smbb-wpcodetool'), __('Couleurs du thème, cartes, barre d\'app en haut. Décoché : l\'administration WordPress habituelle.', 'smbb-wpcodetool'));
        AppearancePage::toggle('modern_pack', 1, __('Tout moderniser', 'smbb-wpcodetool'), __('Aussi les écrans de WordPress et de WooCommerce, la médiathèque, Mon compte, l\'accueil allégé, la recherche Ctrl K et les confirmations en bulles. Le détail reste dans CodeTool › Apparence.', 'smbb-wpcodetool'));

        echo '<p class="smbb-codetool-field-label">' . esc_html__('Allure', 'smbb-wpcodetool') . '</p><div class="smbb-choice-grid" role="radiogroup">';
        $notes = array(
            'galet' => __('Cartes douces et ombrées, coins arrondis, dégradés.', 'smbb-wpcodetool'),
            'cristal' => __('Minimaliste : grille fine, liserés, verre dépoli, typo Geist.', 'smbb-wpcodetool'),
            'porsche' => __('Aplats sans ombre, titres fins, boutons à l\'encre.', 'smbb-wpcodetool'),
        );

        foreach (Skin::presets() as $key => $label) {
            AppearancePage::choice('preset', $key, $settings['preset'], $label, isset($notes[$key]) ? $notes[$key] : '', 'is-' . $key);
        }

        echo '</div><p class="smbb-codetool-field-label">' . esc_html__('Palette de couleurs', 'smbb-wpcodetool') . '</p><div class="smbb-theme-grid" role="radiogroup">';

        foreach ($themes as $key => $theme) {
            AppearancePage::card($key, $theme['label'], array('accent' => $theme['accent'], 'accent2' => isset($theme['accent_2']) ? $theme['accent_2'] : $theme['accent'], 'soft' => $theme['accent_soft'], 'bg' => $theme['bg']), $site === $key);
        }

        echo '</div><p class="smbb-codetool-field-label">' . esc_html__('Mode d\'affichage', 'smbb-wpcodetool') . '</p><div class="smbb-codetool-density" role="radiogroup">';

        foreach (Skin::modes() as $key => $label) {
            echo '<label><input type="radio" name="mode" value="' . esc_attr($key) . '" ' . checked($settings['mode'], $key, false) . '> ' . esc_html($label) . '</label>';
        }

        echo '</div>';

        return (string) ob_get_clean();
    }

    private function profilesBody(WizardDefinition $wizard, WizardState $state, array $step)
    {
        $current = (string) $state->data($step['key'], '');
        $html = '<div class="smbb-wizard-profiles" role="radiogroup">';

        foreach (self::profiles($wizard, $step) as $key => $profile) {
            $html .= '<label class="smbb-wizard-profile' . ($current === $key ? ' is-selected' : '') . '">'
                . '<input type="radio" name="_smbb_wizard_profile" value="' . esc_attr($key) . '" ' . checked($current, $key, false) . ' required>'
                . ($profile['image'] !== '' ? '<img src="' . esc_url($profile['image']) . '" alt="" loading="lazy">' : '<span class="smbb-wizard-profile-icon dashicons ' . esc_attr($profile['icon']) . '" aria-hidden="true"></span>')
                . '<span class="smbb-wizard-profile-title">' . esc_html($profile['title']) . '</span>'
                . '<span class="smbb-wizard-profile-text">' . esc_html($profile['description']) . '</span>'
                . '<span class="smbb-theme-check dashicons dashicons-yes" aria-hidden="true"></span>'
                . '</label>';
        }

        $html .= '</div>';

        // Suite libre sous les cartes (aperçu, réglages fins) : view facultative.
        return $html . $this->extraView($wizard, $state, $step, self::hooksFor($wizard), self::context($wizard, $state, $step));
    }

    /**
     * View facultative sous une étape intégrée (plugins, appearance, profiles).
     */
    private function extraView(WizardDefinition $wizard, WizardState $state, array $step, $hooks, array $ctx)
    {
        $view = isset($step['view']) ? $wizard->codetoolPath($step['view']) : null;

        if (!$view || !is_readable($view)) {
            return '';
        }

        $vars = $hooks && method_exists($hooks, 'stepContext') ? (array) $hooks->stepContext($step['key'], $ctx) : array();

        return self::includeView($view, array_merge($vars, array('wizard' => $wizard, 'state' => $state, 'step' => $step)));
    }

    /**
     * Lien « Relancer l'assistant » (nonce compris), à mettre dans les réglages d'un plugin.
     */
    public static function reopenUrl($name)
    {
        return wp_nonce_url(self::url($name, '', array('wizard_action' => 'reopen')), self::NONCE . '_reopen');
    }
}
