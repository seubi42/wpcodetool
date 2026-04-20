<?php

namespace Smbb\WpCodeTool\Admin;

use Smbb\WpCodeTool\Api\ApiClientStore;
use Smbb\WpCodeTool\Api\ApiVisibilitySettings;
use Smbb\WpCodeTool\Admin\Pages\ApiClientsPage;
use Smbb\WpCodeTool\Admin\Pages\ApiPage;
use Smbb\WpCodeTool\Admin\Pages\OverviewPage;
use Smbb\WpCodeTool\Admin\Pages\ParentPage;
use Smbb\WpCodeTool\Admin\Pages\ResourcePage;
use Smbb\WpCodeTool\Admin\Pages\ResourcesIndexPage;
use Smbb\WpCodeTool\Resource\ResourceDefinition;
use Smbb\WpCodeTool\Resource\ResourceMutationService;
use Smbb\WpCodeTool\Resource\ResourceRuntime;
use Smbb\WpCodeTool\Resource\ResourceScanner;
use Smbb\WpCodeTool\Schema\SchemaSynchronizer;
use Smbb\WpCodeTool\Store\OptionStore;
use Smbb\WpCodeTool\Store\TableStore;
use Smbb\WpCodeTool\Support\ValidationErrors;

// Toute cette classe est branchée sur l'admin WordPress.
defined('ABSPATH') || exit;

/**
 * Branche les ressources CodeTool dans le menu admin WordPress.
 *
 * C'est le premier essai "vertical" du projet :
 * - scanner les JSON ;
 * - enregistrer des menus ;
 * - rendre les views list/form/details/settings.
 *
 * Le CRUD table avance par briques : schema, store SQL, puis actions admin.
 * Cette classe orchestre maintenant les pages et les mutations admin standard.
 */
final class AdminManager
{
    // Scanner des ressources déclarées par les plugins actifs.
    private $scanner;

    // Ressources valides du dernier scan.
    private $resources = array();

    // Erreurs de scan du dernier passage.
    private $errors = array();

    // Service de preview/apply des schemas SQL custom table.
    private $schema;

    // Runtime partage pour hooks, metadata et search clause.
    private $runtime;

    // Pipeline de mutation partage entre l'admin et l'API.
    private $mutations;

    // Helper d'acces a la requete admin courante.
    private $request;

    // Etat mutable du rendu/admin courant.
    private $state;

    // Regles de visibilite OpenAPI/Swagger par namespace.
    private $api_visibility;

    // Clients API geres dans l'admin CodeTool.
    private $api_clients;

    public function __construct(ResourceScanner $scanner)
    {
        $this->scanner = $scanner;
        $this->schema = new SchemaSynchronizer();
        $this->runtime = new ResourceRuntime();
        $this->mutations = new ResourceMutationService($this->runtime);
        $this->request = new AdminRequest();
        $this->state = new AdminPageState();
        $this->api_visibility = new ApiVisibilitySettings();
        $this->api_clients = new ApiClientStore();
    }

    /**
     * Enregistre les hooks WordPress nécessaires à l'admin.
     */
    public function hooks()
    {
        add_action('admin_init', array($this, 'handleAdminActions'));
        add_action('admin_menu', array($this, 'registerMenus'));
        add_action('admin_bar_menu', array($this, 'registerAdminBarItems'), 90);
        add_action('admin_enqueue_scripts', array($this, 'enqueueAssets'));
        add_action('wp_ajax_smbb_codetool_lookup', array($this, 'ajaxLookup'));
    }

    /**
     * Traite les actions admin avant le rendu de la page.
     *
     * C'est indispensable pour les create/update/delete : en cas de succes on redirige,
     * et une redirection doit partir avant que l'admin WordPress envoie son HTML.
     */
    public function handleAdminActions()
    {
        $page = $this->request->page();

        $this->state->reset();

        if ($page === 'smbb-wpcodetool-api') {
            if (!current_user_can('manage_options')) {
                wp_die(esc_html__('You are not allowed to manage CodeTool API settings.', 'smbb-wpcodetool'));
            }

            $this->handleApiVisibilityActions();
            return;
        }

        if ($page === 'smbb-wpcodetool-api-tokens') {
            if (!current_user_can('manage_options')) {
                wp_die(esc_html__('You are not allowed to manage CodeTool API clients.', 'smbb-wpcodetool'));
            }

            $this->handleApiTokenActions();
            return;
        }

        if (strpos($page, 'smbb-codetool-') !== 0) {
            return;
        }

        $this->ensureResources();
        $resource = $this->resourceByAdminSlug($page);

        if (!$resource) {
            return;
        }

        if (!current_user_can($resource->capability())) {
            wp_die(esc_html__('You are not allowed to modify this CodeTool resource.', 'smbb-wpcodetool'));
        }
        $this->handleResourceMutation($resource);
    }

    /**
     * Charge les assets admin CodeTool uniquement sur nos pages.
     *
     * Bonne pratique importante : on ne pollue pas tout l'admin WordPress avec notre CSS.
     * Les styles restent scopes sous .smbb-codetool et le fichier n'est enqueue que si
     * l'utilisateur est sur une page du toolkit, une ressource, ou un parent thématique.
     */
    public function enqueueAssets($hook_suffix)
    {
        if (!$this->isCodeToolAdminPage()) {
            return;
        }

        /*
         * Briques natives WordPress utilisees par nos controles :
         * - Media Library pour image()/media() ;
         * - wpColorPicker pour color() ;
         * - wp_editor() pour editor()/wysiwyg().
         */
        wp_enqueue_media();
        wp_enqueue_style('wp-color-picker');

        if (function_exists('wp_enqueue_editor')) {
            wp_enqueue_editor();
        }

        wp_enqueue_style(
            'smbb-wpcodetool-admin',
            SMBB_WPCODETOOL_URL . 'assets/admin.css',
            array(),
            SMBB_WPCODETOOL_VERSION
        );

        wp_enqueue_script(
            'smbb-wpcodetool-admin',
            SMBB_WPCODETOOL_URL . 'assets/admin.js',
            array('jquery', 'wp-color-picker'),
            SMBB_WPCODETOOL_VERSION,
            true
        );

        /*
         * Le JS reste volontairement generique : il ne connait que des actions
         * ("add", "remove", "collapse"). Les textes affiches a l'utilisateur
         * restent donc declares cote PHP pour profiter de la traduction WordPress.
         */
        wp_localize_script(
            'smbb-wpcodetool-admin',
            'SmbbCodeToolAdmin',
            array(
                'confirmRemove' => __('Remove this item?', 'smbb-wpcodetool'),
                'confirmClear' => __('Remove all items?', 'smbb-wpcodetool'),
                'collapse' => __('Collapse', 'smbb-wpcodetool'),
                'expand' => __('Expand', 'smbb-wpcodetool'),
                'selectMedia' => __('Select media', 'smbb-wpcodetool'),
                'chooseMedia' => __('Choose media', 'smbb-wpcodetool'),
                'noMedia' => __('No media selected.', 'smbb-wpcodetool'),
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'lookupNonce' => wp_create_nonce('smbb_codetool_lookup'),
                'searching' => __('Searching...', 'smbb-wpcodetool'),
                'noResults' => __('No result found.', 'smbb-wpcodetool'),
                'selectionEmpty' => __('No selection yet.', 'smbb-wpcodetool'),
                'selectedNone' => __('No row selected', 'smbb-wpcodetool'),
                'selectedSingle' => __('1 row selected', 'smbb-wpcodetool'),
                'selectedPlural' => __('%d rows selected', 'smbb-wpcodetool'),
            )
        );
    }

    /**
     * Enregistre le menu de debug CodeTool et une page par ressource.
     */
    public function registerMenus()
    {
        $this->resources = $this->scanner->scan();
        $this->errors = $this->scanner->errors();

        // Page principale du toolkit : elle sert pour l'instant à voir ce qui a été détecté.
        add_menu_page(
            __('CodeTool', 'smbb-wpcodetool'),
            __('CodeTool', 'smbb-wpcodetool'),
            'manage_options',
            'smbb-wpcodetool',
            array($this, 'renderOverviewPage'),
            'dashicons-editor-code',
            58
        );

        // Les parents thématiques doivent être enregistrés avant leurs sous-menus.
        // Exemple : un plugin peut demander un parent "SMBB Sample", puis ranger
        // plusieurs ressources dessous.
        add_submenu_page(
            'smbb-wpcodetool',
            __('Overview', 'smbb-wpcodetool'),
            __('Overview', 'smbb-wpcodetool'),
            'manage_options',
            'smbb-wpcodetool',
            array($this, 'renderOverviewPage')
        );

        add_submenu_page(
            'smbb-wpcodetool',
            __('Resources', 'smbb-wpcodetool'),
            __('Resources', 'smbb-wpcodetool'),
            'manage_options',
            'smbb-wpcodetool-resources',
            array($this, 'renderIndexPage')
        );

        add_submenu_page(
            'smbb-wpcodetool',
            __('API', 'smbb-wpcodetool'),
            __('API', 'smbb-wpcodetool'),
            'manage_options',
            'smbb-wpcodetool-api',
            array($this, 'renderApiPage')
        );

        add_submenu_page(
            'smbb-wpcodetool',
            __('API Clients', 'smbb-wpcodetool'),
            __('API Clients', 'smbb-wpcodetool'),
            'manage_options',
            'smbb-wpcodetool-api-tokens',
            array($this, 'renderApiTokensPage')
        );

        $this->registerManagedParentMenus();

        foreach ($this->resources as $resource) {
            if (!$resource->adminEnabled()) {
                continue;
            }

            $this->registerResourceMenu($resource);
        }
    }

    /**
     * Ajoute les raccourcis declares dans la barre noire d'admin WordPress.
     *
     * On garde deux options separees :
     * - admin.adminBar.newContent : une entree dans le menu WordPress "+ New / Creer" ;
     * - admin.adminBar.shortcut   : un bouton direct dans la barre d'admin.
     *
     * Cela permet a chaque ressource de choisir sa visibilite sans multiplier les menus
     * lateraux WordPress.
     */
    public function registerAdminBarItems($wp_admin_bar)
    {
        if (!is_admin_bar_showing()) {
            return;
        }

        $this->ensureResources();
        $has_new_content_item = false;

        foreach ($this->resources as $resource) {
            if (!$resource->adminEnabled() || !current_user_can($resource->capability())) {
                continue;
            }

            if ($resource->adminBarNewContentEnabled()) {
                $has_new_content_item = true;
            }
        }

        /*
         * Le menu "new-content" existe generalement deja dans WordPress. On ajoute quand
         * meme un parent minimal si WordPress ne l'a pas cree, pour que l'option JSON reste
         * previsible dans les installations tres verrouillees.
         */
        if ($has_new_content_item && !$wp_admin_bar->get_node('new-content')) {
            $wp_admin_bar->add_node(array(
                'id' => 'new-content',
                'title' => '<span class="ab-icon" aria-hidden="true"></span><span class="ab-label">' . esc_html__('New', 'smbb-wpcodetool') . '</span>',
                'href' => admin_url(),
            ));
        }

        foreach ($this->resources as $resource) {
            if (!$resource->adminEnabled() || !current_user_can($resource->capability())) {
                continue;
            }

            if ($resource->adminBarNewContentEnabled()) {
                $wp_admin_bar->add_node(array(
                    'parent' => 'new-content',
                    'id' => 'smbb-codetool-new-' . $resource->name(),
                    'title' => esc_html($this->adminBarNewContentTitle($resource)),
                    'href' => $this->adminBarResourceUrl($resource, true),
                ));
            }

            if ($resource->adminBarShortcutEnabled()) {
                $wp_admin_bar->add_node(array(
                    'id' => 'smbb-codetool-shortcut-' . $resource->name(),
                    'title' => esc_html($resource->menuTitle()),
                    'href' => $this->adminBarResourceUrl($resource, false),
                    'meta' => array(
                        'class' => 'smbb-codetool-adminbar-shortcut',
                    ),
                ));
            }
        }
    }

    /**
     * URL cible d'un raccourci de barre d'admin.
     *
     * Pour une ressource CRUD, le raccourci "newContent" ouvre directement le formulaire
     * de creation. Pour une page de reglages, il n'y a pas de creation : on ouvre juste
     * la page formulaire de la ressource.
     */
    private function adminBarResourceUrl(ResourceDefinition $resource, $prefer_create)
    {
        $url = admin_url('admin.php?page=' . $resource->adminSlug());

        if ($prefer_create && $resource->adminType() === 'resource') {
            return add_query_arg(array('view' => 'form'), $url);
        }

        return $url;
    }

    /**
     * Libelle de l'entree ajoutee dans le menu WordPress "+ New / Creer".
     *
     * On evite "New Sample settings" pour les pages de reglages, parce que ce n'est pas
     * une creation d'objet. Dans ce cas le lien sert plutot de raccourci d'ouverture.
     */
    private function adminBarNewContentTitle(ResourceDefinition $resource)
    {
        if ($resource->adminType() !== 'resource') {
            return sprintf(
                /* translators: %s: resource label. */
                __('Open %s', 'smbb-wpcodetool'),
                $resource->label()
            );
        }

        return sprintf(
            /* translators: %s: resource label. */
            __('New %s', 'smbb-wpcodetool'),
            $resource->label()
        );
    }

    /**
     * Enregistre une ressource selon son placement admin.
     *
     * Placement supporté dans admin.menu.placement :
     * - main    : menu principal WordPress ;
     * - submenu : sous-menu du menu CodeTool ;
     * - hidden  : page enregistrée mais invisible dans les menus.
     */
    private function registerResourceMenu(ResourceDefinition $resource)
    {
        $callback = function () use ($resource) {
            $this->renderResourcePage($resource);
        };

        switch ($resource->menuPlacement()) {
            case 'submenu':
                add_submenu_page(
                    $resource->menuParentSlug(),
                    $resource->pluralLabel(),
                    $resource->menuTitle(),
                    $resource->capability(),
                    $resource->adminSlug(),
                    $callback
                );
                break;

            case 'hidden':
                // parent_slug null est l'astuce WordPress pour enregistrer une page
                // accessible par URL directe, sans entrée visible dans le menu.
                add_submenu_page(
                    null,
                    $resource->pluralLabel(),
                    $resource->menuTitle(),
                    $resource->capability(),
                    $resource->adminSlug(),
                    $callback
                );
                break;

            case 'main':
            default:
                add_menu_page(
                    $resource->pluralLabel(),
                    $resource->menuTitle(),
                    $resource->capability(),
                    $resource->adminSlug(),
                    $callback,
                    $resource->menuIcon(),
                    $resource->menuPosition()
                );
                break;
        }
    }

    /**
     * Crée les menus parents thématiques déclarés par les ressources.
     *
     * On ne crée un parent que lorsque admin.menu.parent est un objet JSON.
     * Si parent est une simple chaîne, on suppose qu'elle vise un menu WordPress existant
     * comme "woocommerce" ou "tools.php".
     */
    private function registerManagedParentMenus()
    {
        $registered = array();

        foreach ($this->resources as $resource) {
            if (!$resource->adminEnabled() || !$resource->menuParentManaged()) {
                continue;
            }

            $parent_slug = $resource->menuParentSlug();

            if (isset($registered[$parent_slug])) {
                continue;
            }

            $registered[$parent_slug] = true;

            add_menu_page(
                $resource->menuParentTitle(),
                $resource->menuParentTitle(),
                $resource->capability(),
                $parent_slug,
                function () use ($parent_slug) {
                    $this->renderParentPage($parent_slug);
                },
                $resource->menuParentIcon(),
                $resource->menuParentPosition()
            );
        }
    }

    /**
     * Page d'accueil d'un parent thématique géré par CodeTool.
     *
     * Elle liste les ressources rangées sous ce parent, ce qui rend le menu parent utile
     * même si l'utilisateur clique directement dessus.
     */
    private function renderParentPage($parent_slug)
    {
        (new ParentPage($this))->render($parent_slug);
    }

    /**
     * Retrouve le titre d'un parent thématique à partir de son slug.
     */
    public function parentTitle($parent_slug)
    {
        $this->ensureResources();

        foreach ($this->resources as $resource) {
            if ($resource->menuParentManaged() && $resource->menuParentSlug() === $parent_slug) {
                return $resource->menuParentTitle();
            }
        }

        return __('CodeTool resources', 'smbb-wpcodetool');
    }

    /**
     * Icône du parent thématique courant.
     */
    public function parentIcon($parent_slug)
    {
        $this->ensureResources();

        foreach ($this->resources as $resource) {
            if ($resource->menuParentManaged() && $resource->menuParentSlug() === $parent_slug) {
                return $resource->menuParentIcon();
            }
        }

        return 'dashicons-editor-code';
    }

    public function request()
    {
        return $this->request;
    }

    public function state()
    {
        return $this->state;
    }

    public function resources()
    {
        $this->ensureResources();

        return $this->resources;
    }

    public function errors()
    {
        $this->ensureResources();

        return $this->errors;
    }

    public function schema()
    {
        return $this->schema;
    }

    public function apiVisibility()
    {
        return $this->api_visibility;
    }

    public function apiClients()
    {
        return $this->api_clients;
    }

    /**
     * Détermine si la page admin courante appartient à CodeTool.
     *
     * On couvre trois cas :
     * - smbb-wpcodetool : page debug principale ;
     * - smbb-codetool-* : pages de ressources ;
     * - slug d'un parent thématique créé par CodeTool.
     */
    private function isCodeToolAdminPage()
    {
        $page = $this->request->page();

        if (strpos($page, 'smbb-wpcodetool') === 0 || strpos($page, 'smbb-codetool-') === 0) {
            return true;
        }

        // Normalement les ressources sont déjà chargées par admin_menu avant enqueue.
        // Ce fallback garde la méthode robuste si WordPress change l'ordre ou si on l'appelle
        // manuellement dans un test.
        if (!$this->resources) {
            $this->resources = $this->scanner->scan();
        }

        foreach ($this->resources as $resource) {
            if ($resource->menuParentManaged() && $resource->menuParentSlug() === $page) {
                return true;
            }
        }

        return false;
    }

    /**
     * Page de debug très simple.
     *
     * Elle nous permet de valider rapidement que le scanner voit bien les ressources
     * avant de rendre les CRUD totalement fonctionnels.
     */
    public function renderOverviewPage()
    {
        (new OverviewPage($this))->render();
    }

    public function renderIndexPage()
    {
        (new ResourcesIndexPage($this))->render();
    }

    /**
     * Namespace-level API documentation settings page.
     */
    public function renderApiPage()
    {
        (new ApiPage($this))->render();
    }

    /**
     * Managed API clients page.
     */
    public function renderApiTokensPage()
    {
        (new ApiClientsPage($this))->render();
    }

    /**
     * Save namespace visibility settings.
     */
    private function handleApiVisibilityActions()
    {
        $method = $this->request->method();
        $action = $this->request->postKey('codetool_api_action');

        if ($method !== 'POST' || $action !== 'save_visibility') {
            return;
        }

        check_admin_referer('smbb_codetool_api_visibility');
        $this->ensureResources();
        $submitted = $this->request->postArray('api_visibility');
        $this->api_visibility->updateMany($submitted, array_keys($this->apiNamespaces()));

        wp_safe_redirect(add_query_arg(
            array(
                'page' => 'smbb-wpcodetool-api',
                'codetool_notice' => 'api_visibility_saved',
            ),
            admin_url('admin.php')
        ));
        exit;
    }

    /**
     * Create/enable/disable/delete managed API clients.
     */
    private function handleApiTokenActions()
    {
        $method = $this->request->method();
        $action = $this->request->postKey('codetool_api_token_action');

        if ($method !== 'POST' || $action === '') {
            return;
        }

        check_admin_referer('smbb_codetool_api_tokens');
        $token_id = $this->request->postText('token_id');

        switch ($action) {
            case 'create':
                $label = $this->request->postText('client_label');
                $contact_email = $this->request->postEmail('contact_email');
                $token_ttl_seconds = $this->request->postAbsInt('token_ttl_seconds', 259200);
                $expires_at = $this->request->postText('expires_at');
                $created = $this->api_clients->create($label, $contact_email, $token_ttl_seconds, $expires_at);

                if (!$created) {
                    $this->addRuntimeNotice('error', __('Unable to create the API client.', 'smbb-wpcodetool'));
                    return;
                }

                $flash_id = $this->storeApiTokenFlash($created);

                wp_safe_redirect(add_query_arg(
                    array(
                        'page' => 'smbb-wpcodetool-api-tokens',
                        'codetool_notice' => 'api_client_created',
                        'token_flash' => $flash_id,
                    ),
                    admin_url('admin.php')
                ));
                exit;

            case 'update':
                if ($token_id !== '') {
                    $label = $this->request->postText('client_label');
                    $contact_email = $this->request->postEmail('contact_email');
                    $token_ttl_seconds = $this->request->postAbsInt('token_ttl_seconds', 259200);
                    $expires_at = $this->request->postText('expires_at');
                    $updated = $this->api_clients->update($token_id, $label, $contact_email, $token_ttl_seconds, $expires_at);

                    if (!$updated) {
                        $this->addRuntimeNotice('error', __('Unable to update the API client.', 'smbb-wpcodetool'));
                        return;
                    }
                }

                wp_safe_redirect(add_query_arg(
                    array(
                        'page' => 'smbb-wpcodetool-api-tokens',
                        'codetool_notice' => 'api_client_updated',
                    ),
                    admin_url('admin.php')
                ));
                exit;

            case 'enable':
                if ($token_id !== '') {
                    $this->api_clients->setActive($token_id, true);
                }

                wp_safe_redirect(add_query_arg(
                    array(
                        'page' => 'smbb-wpcodetool-api-tokens',
                        'codetool_notice' => 'api_client_enabled',
                    ),
                    admin_url('admin.php')
                ));
                exit;

            case 'disable':
                if ($token_id !== '') {
                    $this->api_clients->setActive($token_id, false);
                }

                wp_safe_redirect(add_query_arg(
                    array(
                        'page' => 'smbb-wpcodetool-api-tokens',
                        'codetool_notice' => 'api_client_disabled',
                    ),
                    admin_url('admin.php')
                ));
                exit;

            case 'delete':
                if ($token_id !== '') {
                    $this->api_clients->delete($token_id);
                }

                wp_safe_redirect(add_query_arg(
                    array(
                        'page' => 'smbb-wpcodetool-api-tokens',
                        'codetool_notice' => 'api_client_deleted',
                    ),
                    admin_url('admin.php')
                ));
                exit;
        }
    }

    /**
     * Group API-enabled resources by namespace.
     *
     * @return array<string, ResourceDefinition[]>
     */
    public function apiNamespaces()
    {
        $this->ensureResources();

        $namespaces = array();

        foreach ($this->resources as $resource) {
            if (!$resource->apiEnabled()) {
                continue;
            }

            $namespaces[$resource->apiNamespace()][] = $resource;
        }

        ksort($namespaces);

        return $namespaces;
    }

    /**
     * Store a one-time plain token flash.
     */
    private function storeApiTokenFlash(array $created)
    {
        $flash_id = wp_generate_password(12, false, false);

        set_transient(
            'smbb_wpcodetool_api_token_flash_' . $flash_id,
            $created,
            MINUTE_IN_SECONDS * 10
        );

        return $flash_id;
    }

    /**
     * Read and delete a one-time plain token flash.
     */
    public function consumeApiTokenFlash()
    {
        $flash_id = $this->request->queryText('token_flash');

        if ($flash_id === '') {
            return null;
        }

        $key = 'smbb_wpcodetool_api_token_flash_' . $flash_id;
        $value = get_transient($key);
        delete_transient($key);

        return is_array($value) ? $value : null;
    }

    /**
     * Format one SQL datetime for a datetime-local input.
     */
    public function dateTimeLocalInputValue($value)
    {
        $value = trim((string) $value);

        if ($value === '') {
            return '';
        }

        $date = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $value, wp_timezone());

        if ($date instanceof \DateTimeImmutable) {
            return $date->format('Y-m-d\TH:i');
        }

        $timestamp = strtotime($value);

        return $timestamp ? wp_date('Y-m-d\TH:i', $timestamp, wp_timezone()) : '';
    }

    /**
     * Add the REST nonce for the current logged-in session when available.
     */
    public function signedRestUrl($url)
    {
        if (!is_user_logged_in()) {
            return $url;
        }

        $nonce = wp_create_nonce('wp_rest');

        if ($nonce === '') {
            return $url;
        }

        return add_query_arg('_wpnonce', $nonce, $url);
    }

    /**
     * Recharge les ressources si la page est appelee dans un contexte atypique.
     */
    public function ensureResources()
    {
        if ($this->resources || $this->errors) {
            return;
        }

        $this->resources = $this->scanner->scan();
        $this->errors = $this->scanner->errors();
    }

    /**
     * Traite le bouton manuel "Apply schema".
     *
     * On garde l'action volontairement manuelle : aucune creation de table cachee pendant
     * le chargement de l'admin. L'utilisateur demande explicitement l'application.
     */
    public function handleSchemaApply()
    {
        $method = $this->request->method();

        if ($method !== 'POST') {
            return null;
        }

        $action = $this->request->postKey('codetool_schema_action');

        if ($action !== 'apply') {
            return null;
        }

        $resource_name = $this->request->postKey('resource');

        check_admin_referer('smbb_codetool_schema_apply_' . $resource_name);

        $resource = $this->resourceByName($resource_name);

        if (!$resource) {
            return array(
                'type' => 'error',
                'message' => __('Unknown CodeTool resource.', 'smbb-wpcodetool'),
                'changes' => array(),
            );
        }

        if (!current_user_can($resource->capability())) {
            return array(
                'type' => 'error',
                'message' => __('You are not allowed to synchronize this resource.', 'smbb-wpcodetool'),
                'changes' => array(),
            );
        }

        $result = $this->schema->apply($resource);

        return array(
            'type' => !empty($result['success']) ? 'success' : 'error',
            'message' => isset($result['message']) ? $result['message'] : '',
            'changes' => isset($result['changes']) && is_array($result['changes']) ? $result['changes'] : array(),
        );
    }

    /**
     * Prepare la preview SQL demandee par lien GET.
     */
    public function requestedSchemaPreview()
    {
        $action = $this->request->queryKey('codetool_schema_action');

        if ($action !== 'preview') {
            return null;
        }

        $resource_name = $this->request->queryKey('resource');
        $nonce = $this->request->queryText('_wpnonce');

        if (!wp_verify_nonce($nonce, 'smbb_codetool_schema_preview_' . $resource_name)) {
            return array(
                'error' => __('Invalid schema preview nonce.', 'smbb-wpcodetool'),
            );
        }

        $resource = $this->resourceByName($resource_name);

        if (!$resource) {
            return array(
                'error' => __('Unknown CodeTool resource.', 'smbb-wpcodetool'),
            );
        }

        if (!current_user_can($resource->capability())) {
            return array(
                'error' => __('You are not allowed to preview this resource schema.', 'smbb-wpcodetool'),
            );
        }

        return array(
            'resource' => $resource,
            'status' => $this->schema->status($resource),
            'sql' => $this->schema->previewSql($resource),
        );
    }

    /**
     * Affiche une notice apres application manuelle.
     */
    private function renderSchemaNotice($notice)
    {
        echo $this->schemaNoticeHtml($notice);
    }

    /**
     * Rend une notice de schema avec le meme layout que les runtime notices.
     */
    public function schemaNoticeHtml($notice)
    {
        if (!$notice) {
            return '';
        }

        $type = !empty($notice['type']) && $notice['type'] === 'success' ? 'success' : 'error';

        ob_start();
        ?>
        <div class="smbb-codetool-notices">
            <div class="smbb-codetool-notice is-<?php echo esc_attr($type); ?>">
                <div class="smbb-codetool-notice-body">
                    <p class="smbb-codetool-notice-message"><?php echo esc_html(isset($notice['message']) ? $notice['message'] : ''); ?></p>

                    <?php if (!empty($notice['changes'])) : ?>
                        <ul class="smbb-codetool-notice-list">
                            <?php foreach ($notice['changes'] as $change) : ?>
                                <li><?php echo esc_html((string) $change); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php

        return (string) ob_get_clean();
    }

    /**
     * Affiche le SQL qui sera envoye a dbDelta().
     */
    public function renderSchemaPreview($preview)
    {
        if (!$preview) {
            return;
        }

        if (!empty($preview['error'])) {
            $this->renderSchemaNotice(array(
                'type' => 'error',
                'message' => $preview['error'],
                'changes' => array(),
            ));
            return;
        }

        /** @var ResourceDefinition $resource */
        $resource = $preview['resource'];
        $status = $preview['status'];
        ?>
        <div class="smbb-codetool-panel">
            <h2><?php echo esc_html(sprintf(__('Schema preview: %s', 'smbb-wpcodetool'), $resource->label())); ?></h2>
            <p>
                <span class="<?php echo esc_attr($this->schemaStatusClass($status)); ?>">
                    <?php echo esc_html($status['label']); ?>
                </span>
                <?php if (!empty($status['table'])) : ?>
                    <code><?php echo esc_html($status['table']); ?></code>
                <?php endif; ?>
            </p>
            <pre class="smbb-codetool-sql-preview"><code><?php echo esc_html($preview['sql']); ?></code></pre>
        </div>
        <?php
    }

    /**
     * Cellule "Database" de la table des ressources.
     */
    public function renderSchemaCell(ResourceDefinition $resource)
    {
        $status = $this->schema->status($resource);

        if ($status['state'] === 'not_applicable') {
            echo '<span class="' . esc_attr($this->schemaStatusClass($status)) . '">' . esc_html($status['label']) . '</span>';
            return;
        }

        echo '<span class="' . esc_attr($this->schemaStatusClass($status)) . '">' . esc_html($status['label']) . '</span>';

        if (!empty($status['table'])) {
            echo '<br><code>' . esc_html($status['table']) . '</code>';
        }

        echo '<div class="smbb-codetool-actions">';
        echo '<a class="button button-small" href="' . esc_url($this->schemaPreviewUrl($resource)) . '">' . esc_html__('Preview SQL', 'smbb-wpcodetool') . '</a>';

        if ($status['state'] !== 'invalid' && $status['state'] !== 'ok') {
            ?>
            <form class="smbb-codetool-inline-form" method="post" action="<?php echo esc_url(admin_url('admin.php?page=smbb-wpcodetool-resources')); ?>">
                <input type="hidden" name="codetool_schema_action" value="apply">
                <input type="hidden" name="resource" value="<?php echo esc_attr($resource->name()); ?>">
                <?php wp_nonce_field('smbb_codetool_schema_apply_' . $resource->name()); ?>
                <?php submit_button(__('Apply', 'smbb-wpcodetool'), 'secondary small', 'submit', false); ?>
            </form>
            <?php
        }

        echo '</div>';
    }

    /**
     * URL de preview SQL pour une ressource.
     */
    public function schemaPreviewUrl(ResourceDefinition $resource)
    {
        $url = add_query_arg(
            array(
                'page' => 'smbb-wpcodetool-resources',
                'codetool_schema_action' => 'preview',
                'resource' => $resource->name(),
            ),
            admin_url('admin.php')
        );

        return wp_nonce_url($url, 'smbb_codetool_schema_preview_' . $resource->name());
    }

    /**
     * Classe CSS de badge schema.
     */
    public function schemaStatusClass(array $status)
    {
        $state = isset($status['state']) ? (string) $status['state'] : 'unknown';

        return 'smbb-codetool-status is-' . sanitize_html_class(str_replace('_', '-', $state));
    }

    /**
     * Retrouve une ressource par son nom technique.
     */
    private function resourceByName($name)
    {
        $this->ensureResources();

        return isset($this->resources[$name]) ? $this->resources[$name] : null;
    }

    /**
     * Retrouve une ressource par son slug de page admin.
     */
    private function resourceByAdminSlug($slug)
    {
        $this->ensureResources();

        foreach ($this->resources as $resource) {
            if ($resource->adminSlug() === $slug) {
                return $resource;
            }
        }

        return null;
    }

    /**
     * Endpoint Ajax tres leger pour les champs relationnels search().
     *
     * On reste cote admin WordPress pour traverser un minimum de couches :
     * - nonce ;
     * - capability ;
     * - lecture via TableStore ;
     * - reponse JSON concise.
     */
    public function ajaxLookup()
    {
        check_ajax_referer('smbb_codetool_lookup', 'nonce');

        $this->ensureResources();

        $resource_name = $this->request->queryKey('resource');
        $resource = $this->resourceByName($resource_name);

        if (!$resource || $resource->storageType() !== 'custom_table') {
            wp_send_json_error(array('message' => __('Unknown lookup resource.', 'smbb-wpcodetool')), 404);
        }

        if (!current_user_can($resource->capability())) {
            wp_send_json_error(array('message' => __('You are not allowed to query this resource.', 'smbb-wpcodetool')), 403);
        }

        $search = $this->request->queryText('search');
        $label_field = $this->request->queryText('label_field', 'name');
        $value_field = $this->request->queryKey('value_field', $resource->primaryKey());
        $limit = $this->request->queryHas('limit') ? max(1, min(50, $this->request->queryInt('limit'))) : 12;
        $exclude_id = $this->request->queryText('exclude_id');
        $search_fields = $this->lookupSearchFields($resource);
        $rows = $this->lookupRows($resource, $search, $search_fields, $limit);
        $items = array();

        foreach ($rows as $row) {
            $value = $this->rowValueFromPath($row, $value_field);

            if (!is_scalar($value) || (string) $value === '') {
                continue;
            }

            if ($exclude_id !== '' && (string) $value === $exclude_id) {
                continue;
            }

            $label = $this->rowValueFromPath($row, $label_field);

            if (!is_scalar($label) || (string) $label === '') {
                $label = $value;
            }

            $items[] = array(
                'value' => (string) $value,
                'label' => (string) $label,
            );

            if (count($items) >= $limit) {
                break;
            }
        }

        wp_send_json_success(array('items' => $items));
    }

    /**
     * Lecture generique pour search().
     */
    private function lookupRows(ResourceDefinition $resource, $search, array $search_fields, $limit)
    {
        $store = new TableStore($resource);
        $args = array(
            'search' => $search,
            'orderby' => $resource->primaryKey(),
            'order' => 'desc',
            'per_page' => $limit,
            'page' => 1,
        );

        if ($search !== '') {
            if ($search_fields) {
                $args['search_clause'] = $this->lookupSearchClause($resource, $search, $search_fields);
            } elseif ($resource->listSearchProvider() === 'hook') {
                $args['search_clause'] = $this->runtime->tableSearchClause($resource, $search);
            }
        }

        $rows = $store->search($args);

        return $this->lookupPrioritiseExactId($resource, $store, $search, $rows, $limit);
    }

    /**
     * Quand l'utilisateur tape un identifiant numerique, on remonte d'abord
     * la ligne exacte correspondante en tete des resultats.
     *
     * Cela rend le champ relationnel plus pratique en admin :
     * - "12" retrouve directement l'item #12 ;
     * - "#12" fonctionne aussi ;
     * - la recherche textuelle existante reste intacte pour le reste.
     */
    private function lookupPrioritiseExactId(ResourceDefinition $resource, TableStore $store, $search, array $rows, $limit)
    {
        $exact_id = $this->lookupExactSearchId($search);

        if ($exact_id === null) {
            return $rows;
        }

        $exact_row = $store->find($exact_id);

        if (!is_array($exact_row)) {
            return $rows;
        }

        $primary_key = $resource->primaryKey();
        $exact_value = (string) $this->rowValueFromPath($exact_row, $primary_key);
        $merged = array($exact_row);

        foreach ($rows as $row) {
            $row_value = (string) $this->rowValueFromPath($row, $primary_key);

            if ($row_value === '' || $row_value === $exact_value) {
                continue;
            }

            $merged[] = $row;

            if (count($merged) >= $limit) {
                break;
            }
        }

        return $merged;
    }

    /**
     * Extrait un ID de recherche depuis une saisie du type "12" ou "#12".
     */
    private function lookupExactSearchId($search)
    {
        $search = trim((string) $search);

        if ($search === '') {
            return null;
        }

        if ($search[0] === '#') {
            $search = substr($search, 1);
        }

        if ($search === '' || !ctype_digit($search)) {
            return null;
        }

        $id = (int) $search;

        return $id > 0 ? $id : null;
    }

    /**
     * Champs de recherche explicites passes par le controle search().
     */
    private function lookupSearchFields(ResourceDefinition $resource)
    {
        $fields = array();

        if ($this->request->queryHas('search_fields')) {
            $raw = $this->request->queryValue('search_fields');

            if (is_array($raw)) {
                $fields = array_map('sanitize_key', $raw);
            } else {
                $decoded = json_decode((string) $raw, true);

                if (is_array($decoded)) {
                    $fields = array_map('sanitize_key', $decoded);
                } else {
                    $fields = array_map('sanitize_key', array_filter(array_map('trim', explode(',', (string) $raw))));
                }
            }
        }

        $allowed = array_keys($resource->columns());

        return array_values(array_filter(array_unique($fields), function ($field) use ($allowed) {
            return in_array($field, $allowed, true);
        }));
    }

    /**
     * Clause LIKE custom pour un champ relationnel.
     */
    private function lookupSearchClause(ResourceDefinition $resource, $search, array $search_fields)
    {
        global $wpdb;

        $likes = array();
        $params = array();
        $term = '%' . $wpdb->esc_like($search) . '%';

        foreach ($search_fields as $field) {
            $likes[] = $this->lookupIdentifier($field) . ' LIKE %s';
            $params[] = $term;
        }

        if (!$likes) {
            return array();
        }

        return array(
            'sql' => '(' . implode(' OR ', $likes) . ')',
            'params' => $params,
        );
    }

    /**
     * Lit une valeur simple ou imbriquee dans une ligne.
     */
    private function rowValueFromPath(array $row, $field_name)
    {
        $parts = preg_split('/\\[|\\]/', (string) $field_name, -1, PREG_SPLIT_NO_EMPTY);
        $value = $row;

        foreach ($parts as $part) {
            if (!is_array($value) || !array_key_exists($part, $value)) {
                return '';
            }

            $value = $value[$part];
        }

        return $value;
    }

    /**
     * Identifiant SQL nettoye pour les clauses custom d'autocomplete.
     */
    private function lookupIdentifier($name)
    {
        $name = strtolower(str_replace('-', '_', (string) $name));
        $name = preg_replace('/[^a-z0-9_]/', '_', $name);
        $name = trim($name, '_');

        return $name !== '' ? $name : 'field';
    }

    /**
     * Raccourcit un chemin absolu pour l'affichage admin.
     *
     * Les chemins serveurs complets sont peu lisibles dans une table. Quand le fichier
     * est sous ABSPATH, on affiche plutot "wp-content/plugins/..." : c'est assez precis
     * pour retrouver le fichier, sans exposer tout le chemin disque du serveur.
     */
    private function displayPath($path)
    {
        $path = wp_normalize_path((string) $path);
        $root = wp_normalize_path(ABSPATH);

        if ($root && strpos($path, $root) === 0) {
            return ltrim(substr($path, strlen($root)), '/');
        }

        return $path;
    }

    /**
     * Raccourcit un chemin relativement a la racine d'un plugin.
     */
    public function displayPluginRelativePath($path, $plugin_dir)
    {
        $path = wp_normalize_path((string) $path);
        $plugin_dir = untrailingslashit(wp_normalize_path((string) $plugin_dir));

        if ($plugin_dir !== '' && strpos($path, $plugin_dir . '/') === 0) {
            return substr($path, strlen($plugin_dir) + 1);
        }

        return $this->displayPath($path);
    }

    /**
     * Regroupe les ressources detectees par plugin pour la page Resources.
     *
     * @return array<int,array<string,mixed>>
     */
    public function resourcesGroupedByPlugin()
    {
        $this->ensureResources();

        $groups = array();

        foreach ($this->resources as $resource) {
            $plugin_dir = $resource->pluginDir();

            if (!isset($groups[$plugin_dir])) {
                $groups[$plugin_dir] = array(
                    'label' => $this->pluginDisplayName($plugin_dir),
                    'path' => $this->displayPath($plugin_dir),
                    'resources' => array(),
                );
            }

            $groups[$plugin_dir]['resources'][] = $resource;
        }

        foreach ($groups as &$group) {
            usort($group['resources'], function (ResourceDefinition $left, ResourceDefinition $right) {
                return strcasecmp($left->name(), $right->name());
            });
        }
        unset($group);

        uasort($groups, function (array $left, array $right) {
            return strcasecmp((string) $left['label'], (string) $right['label']);
        });

        return array_values($groups);
    }

    /**
     * Nom lisible d'un plugin a partir de son dossier racine.
     */
    private function pluginDisplayName($plugin_dir)
    {
        $slug = wp_basename(wp_normalize_path((string) $plugin_dir));

        if ($slug === '') {
            return __('Unknown plugin', 'smbb-wpcodetool');
        }

        return ucwords(str_replace(array('-', '_'), ' ', $slug));
    }

    /**
     * Rend une ressource précise.
     */
    private function renderResourcePage(ResourceDefinition $resource)
    {
        (new ResourcePage($this))->render($resource);
    }

    /**
     * Route les actions mutantes admin d'une ressource.
     */
    private function handleResourceMutation(ResourceDefinition $resource)
    {
        $method = $this->request->method();

        if ($resource->storageType() === 'none') {
            return;
        }

        if ($resource->storageType() === 'option') {
            if ($method === 'POST') {
                $this->handleOptionSave($resource);
            }

            return;
        }

        if ($resource->storageType() !== 'custom_table') {
            return;
        }

        if ($method === 'POST') {
            if ($this->isTableBatchRequest()) {
                $this->handleTableBatch($resource);
                return;
            }

            $this->handleTableSave($resource);
            return;
        }

        $action = $this->request->queryKey('action');

        if ($action === 'delete') {
            $this->handleTableDelete($resource);
            return;
        }

        if ($action === 'duplicate') {
            $this->handleTableDuplicate($resource);
        }
    }

    /**
     * Sauvegarde une page de reglages stockee dans wp_options.
     */
    private function handleOptionSave(ResourceDefinition $resource)
    {
        check_admin_referer('smbb_codetool_save_' . $resource->name());

        $result = $this->mutations->saveOption($resource, $this->postedOptionData(), array(
            'action' => 'settings_update',
            'validation_callback' => function (array $data) {
                return $this->requiredFieldErrors($data);
            },
        ));

        if (empty($result['success'])) {
            $this->state->setForcedView('form');
            $this->state->setItemOverride(isset($result['data']) && is_array($result['data']) ? $result['data'] : array());

            if (isset($result['reason']) && $result['reason'] === 'validation') {
                $errors = $this->normalizeValidationErrors(isset($result['errors']) && is_array($result['errors']) ? $result['errors'] : array());
                $this->state->setFormErrors($errors);
                $this->addRuntimeNotice('error', __('The form contains errors.', 'smbb-wpcodetool'), ValidationErrors::noticeDetails($errors));
                return;
            }

            $this->addRuntimeNotice('error', !empty($result['message']) ? $result['message'] : __('The settings could not be saved.', 'smbb-wpcodetool'));
            return;
        }

        wp_safe_redirect(add_query_arg(
            array(
                'codetool_notice' => 'settings_saved',
            ),
            admin_url('admin.php?page=' . $resource->adminSlug())
        ));
        exit;
    }

    /**
     * Cree ou met a jour une ligne depuis le formulaire admin.
     */
    private function handleTableSave(ResourceDefinition $resource)
    {
        check_admin_referer('smbb_codetool_save_' . $resource->name());

        $primary_key = $resource->primaryKey();
        $requested_id = $this->requestedResourceId($primary_key);
        $is_update = $requested_id !== null;
        $result = $this->mutations->saveTable($resource, $this->postedResourceData($resource), array(
            'id' => $requested_id,
            'action' => $is_update ? 'update' : 'create',
            'not_found_message' => __('The item to update was not found.', 'smbb-wpcodetool'),
            'save_failed_message' => __('The item could not be saved.', 'smbb-wpcodetool'),
            'validation_callback' => function (array $data) {
                return $this->requiredFieldErrors($data);
            },
        ));

        if (empty($result['success'])) {
            $this->state->setForcedView('form');
            $this->state->setItemOverride(isset($result['data']) && is_array($result['data']) ? $result['data'] : array());

            if (isset($result['reason']) && $result['reason'] === 'validation') {
                $errors = $this->normalizeValidationErrors(isset($result['errors']) && is_array($result['errors']) ? $result['errors'] : array());
                $this->state->setFormErrors($errors);
                $this->addRuntimeNotice('error', __('The form contains errors.', 'smbb-wpcodetool'), ValidationErrors::noticeDetails($errors));
                return;
            }

            $this->addRuntimeNotice('error', !empty($result['message']) ? $result['message'] : __('The item could not be saved.', 'smbb-wpcodetool'));
            return;
        }

        $saved_id = $result['id'];
        $notice = $is_update ? 'updated' : 'created';

        wp_safe_redirect(add_query_arg(
            array(
                'view' => 'form',
                $primary_key => $saved_id,
                'codetool_notice' => $notice,
            ),
            admin_url('admin.php?page=' . $resource->adminSlug())
        ));
        exit;
    }

    /**
     * Supprime une ligne depuis une action de liste.
     */
    private function handleTableDelete(ResourceDefinition $resource)
    {
        $primary_key = $resource->primaryKey();
        $requested_id = $this->requestedResourceId($primary_key);

        if ($requested_id === null) {
            $this->addRuntimeNotice('error', __('Missing item identifier.', 'smbb-wpcodetool'));
            return;
        }

        check_admin_referer('smbb_codetool_delete_' . $resource->name() . '_' . $requested_id);

        $result = $this->deleteTableItem($resource, new TableStore($resource), $requested_id);

        if (empty($result['success'])) {
            $this->addRuntimeNotice('error', !empty($result['message']) ? $result['message'] : __('The item could not be deleted.', 'smbb-wpcodetool'));
            return;
        }

        wp_safe_redirect(add_query_arg(
            array(
                'codetool_notice' => 'deleted',
            ),
            admin_url('admin.php?page=' . $resource->adminSlug())
        ));
        exit;
    }

    /**
     * Clone une ligne existante dans une nouvelle ligne.
     *
     * La duplication reutilise le pipeline create : hooks, champs managed, beforeSave,
     * puis insert. La cle primaire auto-increment est ignoree par TableStore.
     */
    private function handleTableDuplicate(ResourceDefinition $resource)
    {
        $primary_key = $resource->primaryKey();
        $requested_id = $this->requestedResourceId($primary_key);

        if ($requested_id === null) {
            $this->addRuntimeNotice('error', __('Missing item identifier.', 'smbb-wpcodetool'));
            return;
        }

        check_admin_referer('smbb_codetool_duplicate_' . $resource->name() . '_' . $requested_id);
        $result = $this->duplicateTableItem($resource, new TableStore($resource), $requested_id);

        if (empty($result['success'])) {
            $this->addRuntimeNotice(
                'error',
                !empty($result['message']) ? $result['message'] : __('The item could not be duplicated.', 'smbb-wpcodetool'),
                !empty($result['details']) && is_array($result['details']) ? $result['details'] : array()
            );
            return;
        }

        $new_id = $result['id'];

        wp_safe_redirect(add_query_arg(
            array(
                'view' => 'form',
                $primary_key => $new_id,
                'codetool_notice' => 'duplicated',
            ),
            admin_url('admin.php?page=' . $resource->adminSlug())
        ));
        exit;
    }

    /**
     * Indique si le POST courant vient de la table de liste (actions batch).
     */
    private function isTableBatchRequest()
    {
        $flag = $this->request->postText('smbb_codetool_batch');

        return $flag === '1';
    }

    /**
     * Traite les actions groupées de la liste admin.
     *
     * V1 utile :
     * - delete    : suppression de plusieurs lignes ;
     * - duplicate : clonage de plusieurs lignes.
     */
    private function handleTableBatch(ResourceDefinition $resource)
    {
        check_admin_referer('smbb_codetool_batch_' . $resource->name());

        $action = $this->tableBatchAction();
        $ids = $this->selectedTableIds($resource);

        if (!in_array($action, array('delete', 'duplicate'), true)) {
            $this->addRuntimeNotice('error', __('Choose a batch action first.', 'smbb-wpcodetool'));
            return;
        }

        if (!$ids) {
            $this->addRuntimeNotice('error', __('Select at least one item first.', 'smbb-wpcodetool'));
            return;
        }

        $store = new TableStore($resource);
        $success = 0;
        $failures = array();

        foreach ($ids as $id) {
            if ($action === 'delete') {
                $result = $this->deleteTableItem($resource, $store, $id);
            } else {
                $result = $this->duplicateTableItem($resource, $store, $id);
            }

            if (!empty($result['success'])) {
                $success++;
                continue;
            }

            $label = sprintf('#%s', (string) $id);

            if (!empty($result['details'])) {
                foreach ($result['details'] as $field => $message) {
                    $failures[$label . ($field !== '' ? ' / ' . $field : '')] = $message;
                }
            } else {
                $failures[$label] = !empty($result['message'])
                    ? $result['message']
                    : __('The selected item could not be processed.', 'smbb-wpcodetool');
            }
        }

        if ($success > 0 && !$failures) {
            $notice = $action === 'delete' ? 'batch_deleted' : 'batch_duplicated';

            wp_safe_redirect(add_query_arg(
                array_merge(
                    $this->tableListRedirectArgs(),
                    array(
                        'codetool_notice' => $notice,
                        'codetool_count' => $success,
                    )
                ),
                admin_url('admin.php?page=' . $resource->adminSlug())
            ));
            exit;
        }

        if ($success > 0) {
            if ($action === 'delete') {
                $message = sprintf(
                    _n('%d item deleted.', '%d items deleted.', $success, 'smbb-wpcodetool'),
                    $success
                );
            } else {
                $message = sprintf(
                    _n('%d item duplicated.', '%d items duplicated.', $success, 'smbb-wpcodetool'),
                    $success
                );
            }

            $this->addRuntimeNotice('success', $message);
        }

        if ($failures) {
            $this->addRuntimeNotice('error', __('Some selected items could not be processed.', 'smbb-wpcodetool'), $failures);
        }
    }

    /**
     * Action batch choisie dans la barre du haut ou du bas.
     */
    private function tableBatchAction()
    {
        $action = $this->request->postKey('smbb_codetool_batch_action');

        if ($action !== '') {
            return $action;
        }

        return $this->request->postKey('smbb_codetool_batch_action_bottom');
    }

    /**
     * IDs cochés dans la liste admin.
     */
    private function selectedTableIds(ResourceDefinition $resource)
    {
        $raw_ids = $this->request->postArray('smbb_codetool_selected');
        $ids = array();
        $numeric = $this->runtime->primaryKeyIsNumeric($resource);

        foreach ($raw_ids as $id) {
            $id = sanitize_text_field((string) $id);

            if ($id === '') {
                continue;
            }

            $ids[] = $numeric ? (string) (int) $id : $id;
        }

        return array_values(array_unique($ids));
    }

    /**
     * Arguments de liste a conserver apres une action batch reussie.
     */
    private function tableListRedirectArgs()
    {
        $args = array();

        if ($this->request->postHas('s')) {
            $args['s'] = $this->request->postText('s');
        }

        if ($this->request->postHas('orderby')) {
            $args['orderby'] = $this->request->postKey('orderby');
        }

        if ($this->request->postHas('order')) {
            $args['order'] = $this->request->postKey('order');
        }

        if ($this->request->postHas('paged')) {
            $args['paged'] = max(1, $this->request->postInt('paged'));
        }

        if ($this->request->postHas('per_page')) {
            $args['per_page'] = max(1, $this->request->postInt('per_page'));
        }

        if ($this->request->postHas('filter')) {
            $filter = $this->request->postArray('filter');
            $args['filter'] = array(
                'field' => isset($filter['field']) ? sanitize_key((string) $filter['field']) : '',
                'operator' => isset($filter['operator']) ? sanitize_key((string) $filter['operator']) : '',
                'value' => isset($filter['value']) ? sanitize_text_field((string) $filter['value']) : '',
            );
        }

        return $args;
    }

    /**
     * Execute la suppression d'une ligne sans gerer la redirection.
     */
    private function deleteTableItem(ResourceDefinition $resource, TableStore $store, $requested_id)
    {
        return $this->mutations->deleteTable($resource, $requested_id, array(
            'store' => $store,
            'action' => 'delete',
            'not_found_message' => __('The item to delete was not found.', 'smbb-wpcodetool'),
            'blocked_message' => __('Deletion was blocked by the resource hooks.', 'smbb-wpcodetool'),
            'delete_failed_message' => __('The item could not be deleted.', 'smbb-wpcodetool'),
        ));
    }

    /**
     * Execute la duplication d'une ligne sans gerer la redirection.
     */
    private function duplicateTableItem(ResourceDefinition $resource, TableStore $store, $requested_id)
    {
        $result = $this->mutations->duplicateTable($resource, $requested_id, array(
            'store' => $store,
            'action' => 'duplicate',
            'not_found_message' => __('The item to duplicate was not found.', 'smbb-wpcodetool'),
            'validation_message' => __('The cloned item contains errors.', 'smbb-wpcodetool'),
            'save_failed_message' => __('The item could not be duplicated.', 'smbb-wpcodetool'),
        ));

        if (!empty($result['success']) || empty($result['errors']) || !is_array($result['errors'])) {
            return $result;
        }

        $errors = $this->normalizeValidationErrors($result['errors']);
        $result['details'] = ValidationErrors::noticeDetails($errors);

        return $result;
    }

    /**
     * Prépare les variables attendues par les views puis inclut le fichier PHP.
     */
    public function renderResourceView(ResourceDefinition $resource, $view, $view_path)
    {
        $admin_url = admin_url('admin.php?page=' . $resource->adminSlug());
        $primary_key = $resource->primaryKey();
        $resource_name = $resource->name();
        $resource_label = $view === 'list' ? $resource->pluralLabel() : $resource->label();
        $resource_subtitle = $resource->description();
        $resource_icon = $resource->menuIcon();

        $rows = array();
        $item = array();
        $store = null;
        $pagination = array();
        $requested_id = $this->requestedResourceId($primary_key);
        $current_view = $view;
        $resource_views = $resource->views();
        $resources = $this->resources();

        // Pour les ressources table, on lit maintenant vraiment la base via TableStore.
        // Si la table n'existe pas encore, le store retourne simplement un tableau vide :
        // l'utilisateur peut alors passer par CodeTool > Database > Apply.
        if ($resource->storageType() === 'custom_table') {
            $store = new TableStore($resource);

            if ($view === 'list') {
                $query_args = $this->tableQueryArgs($resource);
                $rows = $store->list($query_args);
                $pagination = array(
                    'current_page' => isset($query_args['page']) ? (int) $query_args['page'] : 1,
                    'per_page' => isset($query_args['per_page']) ? (int) $query_args['per_page'] : 20,
                    'total_items' => $store->count($query_args),
                );
            }

            if ($requested_id !== null && $view !== 'list') {
                $item = $store->find($requested_id);

                if (!$item) {
                    $item = array();
                    $this->renderPrototypeNotice(__('Requested item was not found.', 'smbb-wpcodetool'));
                }
            }
        }

        // Pour les pages de réglages, on peut déjà lire wp_options grâce à OptionStore.
        if ($resource->storageType() === 'option') {
            $store = new OptionStore($resource->optionName(), $resource->optionDefaults(), $resource->optionAutoload());
            $item = $store->get();
        }

        $item_override = $this->state->itemOverride();

        if (is_array($item_override)) {
            $item = $item_override;
        }

        $create_url = add_query_arg(array('view' => 'form'), $admin_url);
        $list_url = $admin_url;
        $edit_url = $requested_id !== null ? add_query_arg(array('view' => 'form', $primary_key => $requested_id), $admin_url) : add_query_arg(array('view' => 'form'), $admin_url);
        $action_url = $requested_id !== null ? $edit_url : $admin_url;
        $view_url = function ($target_view, array $args = array()) use ($admin_url) {
            return add_query_arg(array_merge(array('view' => sanitize_key((string) $target_view)), $args), $admin_url);
        };
        $button = $this->formButtonLabel($resource, $requested_id);
        $notices_html = $this->runtimeNoticesHtml();
        $page_header_html = $this->pageHeaderHtml($resource_label, $resource_subtitle, $resource_icon);
        $filter_fields = $resource->storageType() === 'custom_table' ? $this->tableFilterFields($resource) : array();
        $search_term = $resource->storageType() === 'custom_table' ? $this->tableSearchTerm($resource) : '';
        $hooks = $this->runtime->hooksFor($resource);

        // Helpers injectés dans les views. C'est cette injection qui permet aux views
        // de rester courtes et déclaratives.
        $table = new Table(array(
            'admin_url' => $admin_url,
            'create_url' => $resource->adminType() === 'resource' ? $create_url : '',
            'orderby' => $this->request->queryKey('orderby'),
            'order' => $this->request->queryKey('order'),
            'primary_key' => $primary_key,
            'resource_label' => $resource_label,
            'resource_subtitle' => $resource_subtitle,
            'resource_icon' => $resource_icon,
            'resource_name' => $resource_name,
            'rows' => $rows,
            'notices_html' => $notices_html,
            'search_enabled' => $resource->storageType() === 'custom_table' && $resource->listSearchEnabled(),
            'search_term' => $search_term,
            'search_placeholder' => $resource->storageType() === 'custom_table' ? $resource->listSearchPlaceholder() : '',
            'filter_enabled' => $resource->storageType() === 'custom_table' && $resource->listFiltersEnabled() && !empty($filter_fields),
            'filter_fields' => $filter_fields,
            'current_filter' => $this->tableFilterArgs($resource),
            'batch_enabled' => $resource->storageType() === 'custom_table',
            'pagination' => $pagination,
        ));

        $form = new Form(array(
            'action_url' => $action_url,
            'item' => $item,
            'field_errors' => $this->state->formErrors(),
            'nonce_action' => 'smbb_codetool_save_' . $resource_name,
            'resource_name' => $resource_name,
            'resource' => $resource,
            'requested_id' => $requested_id,
            'resources' => $this->resources,
            'store' => $store,
        ));

        $dashboard_ui = new Dashboard(array(
            'action_url' => $action_url,
            'admin_url' => $admin_url,
            'create_url' => $create_url,
            'edit_url' => $edit_url,
            'item' => $item,
            'notices_html' => $notices_html,
            'page_header_html' => $page_header_html,
            'resource' => $resource,
            'resource_icon' => $resource_icon,
            'resource_label' => $resource_label,
            'resource_name' => $resource_name,
            'resource_subtitle' => $resource_subtitle,
            'resources' => $resources,
            'rows' => $rows,
            'view' => $current_view,
        ));

        // Contexte standard injecte dans toutes les views de ressource.
        // Les pages "UX only" peuvent ainsi rester simples cote template et deleguer
        // les calculs a une classe via hooks.viewContext().
        $context = array(
            'action_url' => $action_url,
            'admin_url' => $admin_url,
            'button' => $button,
            'create_url' => $create_url,
            'current_filter' => $this->tableFilterArgs($resource),
            'dashboard_ui' => $dashboard_ui,
            'edit_url' => $edit_url,
            'filter_fields' => $filter_fields,
            'form' => $form,
            'hooks' => $hooks,
            'item' => $item,
            'list_url' => $list_url,
            'notices_html' => $notices_html,
            'page_header_html' => $page_header_html,
            'pagination' => $pagination,
            'primary_key' => $primary_key,
            'requested_id' => $requested_id,
            'resource' => $resource,
            'resource_icon' => $resource_icon,
            'resource_label' => $resource_label,
            'resource_name' => $resource_name,
            'resource_subtitle' => $resource_subtitle,
            'resources' => $resources,
            'resource_views' => $resource_views,
            'rows' => $rows,
            'search_term' => $search_term,
            'store' => $store,
            'table' => $table,
            'view' => $current_view,
            'view_url' => $view_url,
        );
        $context = $this->runtime->callViewContextHook($hooks, $context);

        // Les variables ci-dessus sont volontairement disponibles dans le scope de la view.
        include $view_path;
    }

    /**
     * Header admin commun a toutes les pages CodeTool.
     *
     * On centralise ce rendu pour garder la meme hierarchie visuelle sur :
     * - pages systeme CodeTool ;
     * - pages parentes thematiques ;
     * - formulaires de ressources ;
     * - listes CRUD.
     */
    private function pageHeaderHtml($title, $subtitle = '', $icon = '')
    {
        ob_start();
        ?>
        <div class="smbb-codetool-page-header">
            <div class="smbb-codetool-page-header-main">
                <?php if (strpos((string) $icon, 'dashicons-') === 0) : ?>
                    <span class="smbb-codetool-page-icon" aria-hidden="true">
                        <span class="dashicons <?php echo esc_attr((string) $icon); ?>"></span>
                    </span>
                <?php endif; ?>

                <div class="smbb-codetool-page-heading">
                    <h1 class="smbb-codetool-page-title"><?php echo esc_html((string) $title); ?></h1>

                    <?php if ((string) $subtitle !== '') : ?>
                        <p class="smbb-codetool-page-subtitle"><?php echo esc_html((string) $subtitle); ?></p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php

        return (string) ob_get_clean();
    }

    /**
     * Header partage suivi des notices associees.
     *
     * Les vues CRUD rendent deja le header puis les notices juste en dessous.
     * On reutilise ici exactement ce pattern pour les pages systeme.
     *
     * @param string|array<int,string> $notices_html
     */
    public function pageIntroHtml($title, $subtitle = '', $icon = '', $notices_html = '')
    {
        $fragments = is_array($notices_html) ? $notices_html : array($notices_html);

        ob_start();
        echo '<div class="smbb-codetool-page-intro">';
        echo $this->pageHeaderHtml($title, $subtitle, $icon);

        foreach ($fragments as $fragment) {
            if ((string) $fragment === '') {
                continue;
            }

            echo (string) $fragment;
        }

        echo '</div>';

        return (string) ob_get_clean();
    }

    /**
     * Arguments de lecture pour TableStore depuis la query admin.
     */
    private function tableQueryArgs(ResourceDefinition $resource)
    {
        $config = $resource->listConfig();
        $search = $this->tableSearchTerm($resource);
        $args = array(
            'search' => $search,
            'orderby' => $this->request->queryKey('orderby'),
            'order' => $this->request->queryKey('order'),
            'filter' => $this->tableFilterArgs($resource),
            'per_page' => $this->request->queryHas('per_page') ? max(1, $this->request->queryInt('per_page')) : (isset($config['perPage']) ? (int) $config['perPage'] : 20),
            'page' => $this->request->queryHas('paged') ? max(1, $this->request->queryInt('paged')) : 1,
        );

        if ($search !== '' && $resource->listSearchProvider() === 'hook') {
            $search_clause = $this->runtime->tableSearchClause($resource, $search);

            if ($search_clause) {
                $args['search_clause'] = $search_clause;
            }
        }

        return $args;
    }

    /**
     * Filtre courant lu depuis l'URL admin.
     *
     * V1 : une seule ligne de filtre, au format field/operator/value.
     */
    private function tableFilterArgs(ResourceDefinition $resource = null)
    {
        if ($resource && !$resource->listFiltersEnabled()) {
            return array(
                'field' => '',
                'operator' => '',
                'value' => '',
            );
        }

        $filter = $this->request->queryArray('filter');
        $field = isset($filter['field']) ? sanitize_key((string) $filter['field']) : '';

        if ($resource && $field !== '' && !in_array($field, $resource->listFilterColumns(), true)) {
            $field = '';
        }

        return array(
            'field' => $field,
            'operator' => isset($filter['operator']) ? sanitize_key((string) $filter['operator']) : '',
            'value' => isset($filter['value']) ? sanitize_text_field((string) $filter['value']) : '',
        );
    }

    /**
     * Definitions minimales des champs filtrables pour le helper Table.
     */
    private function tableFilterFields(ResourceDefinition $resource)
    {
        $fields = array();
        $columns = $resource->columns();

        foreach ($resource->listFilterDefinitions() as $field => $definition) {
            if ($field === '' || !isset($columns[$field]) || !is_array($columns[$field])) {
                continue;
            }

            $fields[$field] = array(
                'label' => !empty($definition['label']) ? (string) $definition['label'] : ucfirst(str_replace('_', ' ', $field)),
                'type' => isset($columns[$field]['type']) ? strtolower((string) $columns[$field]['type']) : 'varchar',
                'operators' => isset($definition['operators']) && is_array($definition['operators']) ? $definition['operators'] : array(),
            );
        }

        return $fields;
    }

    /**
     * Terme de recherche courant, si la ressource l'autorise.
     */
    private function tableSearchTerm(ResourceDefinition $resource)
    {
        if (!$resource->listSearchEnabled()) {
            return '';
        }

        return $this->request->queryText('s');
    }

    /**
     * Lit l'identifiant de la ressource courante depuis l'URL.
     */
    private function requestedResourceId($primary_key)
    {
        if (!$this->request->queryHas($primary_key)) {
            return null;
        }

        $value = sanitize_text_field((string) $this->request->queryValue($primary_key));

        return $value === '' ? null : $value;
    }

    /**
     * Libelle du bouton de formulaire selon le contexte.
     */
    private function formButtonLabel(ResourceDefinition $resource, $requested_id)
    {
        if ($resource->adminType() === 'settings_page') {
            return __('Save settings', 'smbb-wpcodetool');
        }

        return $requested_id !== null ? __('Update', 'smbb-wpcodetool') : __('Create', 'smbb-wpcodetool');
    }

    /**
     * Extrait les donnees POST correspondant aux colonnes declarees.
     */
    private function postedResourceData(ResourceDefinition $resource)
    {
        $posted = $this->request->postData();
        $data = array();

        foreach ($resource->columns() as $column => $definition) {
            if (!is_array($definition)) {
                continue;
            }

            if (!empty($definition['primary']) && !empty($definition['autoIncrement'])) {
                continue;
            }

            if (!empty($definition['managed'])) {
                continue;
            }

            if (array_key_exists($column, $posted)) {
                $data[$column] = $posted[$column];
            }
        }

        return $data;
    }

    /**
     * Extrait les donnees POST d'une page de reglages.
     *
     * Pour une ressource option, il n'y a pas de colonnes SQL pour filtrer les champs.
     * On prend donc tous les champs du formulaire sauf les meta WordPress (nonce, referer,
     * submit). Les defaults declares dans le JSON restent appliques par OptionStore.
     */
    private function postedOptionData()
    {
        $posted = $this->request->postData();
        $data = array();
        $ignored = array('_wpnonce', '_wp_http_referer', 'submit');

        foreach ($posted as $key => $value) {
            if (in_array($key, $ignored, true)) {
                continue;
            }

            if (strpos((string) $key, '_wp') === 0) {
                continue;
            }

            // Reserve a l'infrastructure interne CodeTool : required markers, etc.
            if (strpos((string) $key, '_smbb_') === 0) {
                continue;
            }

            $data[$key] = $value;
        }

        return $data;
    }

    /**
     * Validation generique des champs marques required dans la view.
     *
     * Cette couche evite de devoir ecrire un hook validate() juste pour dire
     * "ce champ est obligatoire". Les hooks restent utiles pour les regles
     * metier plus riches.
     */
    private function requiredFieldErrors(array $data)
    {
        $posted = $this->request->postData();
        $required_fields = isset($posted['_smbb_required']) && is_array($posted['_smbb_required']) ? $posted['_smbb_required'] : array();
        $required_fields = array_values(array_unique(array_filter(array_map('strval', $required_fields))));
        $posted_source = $this->postedValuesForRequiredValidation($posted);
        $errors = array();

        foreach ($required_fields as $field_name) {
            $exists = false;
            $value = $this->valueFromPostedPath($posted_source, $field_name, $exists);

            if ($exists && !$this->isBlankRequiredValue($value)) {
                continue;
            }

            $errors[$field_name] = __('This field is required.', 'smbb-wpcodetool');
        }

        return $errors;
    }

    /**
     * Nettoie le POST brut pour la validation required generique.
     */
    private function postedValuesForRequiredValidation(array $posted)
    {
        unset($posted['_wpnonce'], $posted['_wp_http_referer'], $posted['submit'], $posted['_smbb_required']);

        return $posted;
    }

    /**
     * Lit une valeur dans un tableau a partir d'un nom HTML du style api[token].
     */
    private function valueFromPostedPath(array $data, $field_name, &$exists)
    {
        $parts = preg_split('/\\[|\\]/', (string) $field_name, -1, PREG_SPLIT_NO_EMPTY);
        $value = $data;
        $exists = true;

        foreach ($parts as $part) {
            if (!is_array($value) || !array_key_exists($part, $value)) {
                $exists = false;
                return null;
            }

            $value = $value[$part];
        }

        return $value;
    }

    /**
     * Definit ce que CodeTool considere comme "vide" pour un required.
     */
    private function isBlankRequiredValue($value)
    {
        if ($value === null) {
            return true;
        }

        if (is_string($value)) {
            return trim($value) === '';
        }

        if (is_array($value)) {
            return count($value) === 0;
        }

        return false;
    }

    /**
     * Normalise les erreurs pour les reutiliser partout de la meme facon.
     *
     * Les hooks validate() peuvent donc retourner des formats tres simples
     * (field => message), pendant que l'admin et l'API manipulent ensuite
     * une structure stable avec path/html_name/message.
     */
    private function normalizeValidationErrors(array $errors)
    {
        return ValidationErrors::normalize($errors);
    }

    /**
     * Ajoute une notice a rendre sur la page courante.
     */
    public function addRuntimeNotice($type, $message, array $details = array())
    {
        $this->state->addNotice($type, $message, $details);
    }

    /**
     * Ajoute une notice de succes apres redirection.
     */
    public function addNoticeFromQuery()
    {
        $notice = $this->request->queryKey('codetool_notice');
        $count = $this->request->queryHas('codetool_count') ? max(0, $this->request->queryInt('codetool_count')) : 0;
        $messages = array(
            'created' => __('Item created.', 'smbb-wpcodetool'),
            'duplicated' => __('Item duplicated.', 'smbb-wpcodetool'),
            'updated' => __('Item updated.', 'smbb-wpcodetool'),
            'deleted' => __('Item deleted.', 'smbb-wpcodetool'),
            'settings_saved' => __('Settings saved.', 'smbb-wpcodetool'),
            'api_visibility_saved' => __('API visibility saved.', 'smbb-wpcodetool'),
            'api_token_created' => __('API token created.', 'smbb-wpcodetool'),
            'api_token_enabled' => __('API token enabled.', 'smbb-wpcodetool'),
            'api_token_disabled' => __('API token disabled.', 'smbb-wpcodetool'),
            'api_token_deleted' => __('API token deleted.', 'smbb-wpcodetool'),
            'api_client_created' => __('API client created.', 'smbb-wpcodetool'),
            'api_client_updated' => __('API client updated.', 'smbb-wpcodetool'),
            'api_client_enabled' => __('API client enabled.', 'smbb-wpcodetool'),
            'api_client_disabled' => __('API client disabled.', 'smbb-wpcodetool'),
            'api_client_deleted' => __('API client deleted.', 'smbb-wpcodetool'),
        );

        if ($notice === 'batch_deleted' && $count > 0) {
            $this->addRuntimeNotice('success', sprintf(
                _n('%d item deleted.', '%d items deleted.', $count, 'smbb-wpcodetool'),
                $count
            ));
            return;
        }

        if ($notice === 'batch_duplicated' && $count > 0) {
            $this->addRuntimeNotice('success', sprintf(
                _n('%d item duplicated.', '%d items duplicated.', $count, 'smbb-wpcodetool'),
                $count
            ));
            return;
        }

        if (isset($messages[$notice])) {
            $this->addRuntimeNotice('success', $messages[$notice]);
        }
    }

    /**
     * Rend les notices accumulees.
     */
    private function renderRuntimeNotices()
    {
        echo $this->runtimeNoticesHtml();
    }

    /**
     * Rend les notices runtime en HTML pour les laisser s'inserer dans le layout.
     *
     * On ne les affiche plus avant la vue elle-meme, parce qu'on veut pouvoir les
     * placer juste apres le header CodeTool et avant le contenu principal.
     */
    public function runtimeNoticesHtml()
    {
        $notices = $this->state->notices();

        if (!$notices) {
            return '';
        }

        ob_start();
        echo '<div class="smbb-codetool-notices">';

        foreach ($notices as $notice) {
            $type = $notice['type'] === 'success' ? 'success' : ($notice['type'] === 'error' ? 'error' : 'info');
            echo '<div class="smbb-codetool-notice is-' . esc_attr($type) . '">';
            echo '<div class="smbb-codetool-notice-body">';
            echo '<p class="smbb-codetool-notice-message">' . esc_html($notice['message']) . '</p>';

            if (!empty($notice['details'])) {
                echo '<ul class="smbb-codetool-notice-list">';

                foreach ($notice['details'] as $field => $message) {
                    echo '<li>';

                    if (is_string($field) && strpos($field, '__global_') !== 0) {
                        echo '<strong>' . esc_html($field) . ':</strong> ';
                    }

                    echo esc_html((string) $message);
                    echo '</li>';
                }

                echo '</ul>';
            }

            echo '</div>';
            echo '</div>';
        }

        echo '</div>';

        return (string) ob_get_clean();
    }

    /**
     * Affiche une notice prototype.
     */
    private function renderPrototypeNotice($message)
    {
        echo '<div class="notice notice-info"><p>' . esc_html($message) . '</p></div>';
    }

    /**
     * Affiche une erreur lisible quand une view déclarée manque.
     */
    public function renderMissingView(ResourceDefinition $resource, $view, $view_path)
    {
        ?>
        <div class="wrap smbb-codetool">
            <h1><?php echo esc_html($resource->label()); ?></h1>
            <div class="notice notice-error">
                <p>
                    <?php
                    printf(
                        esc_html__('CodeTool view "%1$s" is missing or unreadable: %2$s', 'smbb-wpcodetool'),
                        esc_html($view),
                        esc_html($view_path)
                    );
                    ?>
                </p>
            </div>
        </div>
        <?php
    }
}
