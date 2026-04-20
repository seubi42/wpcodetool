<?php

namespace Smbb\WpCodeTool\Resource;

// Une définition de ressource n'a de sens que dans WordPress.
defined('ABSPATH') || exit;

/**
 * Représente un fichier codetool/models/*.json chargé en mémoire.
 *
 * Cette classe est volontairement légère : elle ne fait pas encore de validation avancée,
 * ni de migration, ni de CRUD. Son rôle est de donner au reste du moteur une façon stable
 * de lire les informations utiles sans manipuler le tableau JSON brut partout.
 */
final class ResourceDefinition
{
    // Données JSON décodées.
    private $data;

    // Dossier racine du plugin consommateur, par exemple wp-content/plugins/smbb-sample.
    private $plugin_dir;

    // Chemin absolu du fichier JSON source.
    private $model_file;

    // Cache local des colonnes structurees stockees en JSON.
    private $json_columns = null;

    /**
     * @param array  $data       Contenu JSON décodé.
     * @param string $plugin_dir Dossier du plugin qui possède le dossier codetool.
     * @param string $model_file Fichier JSON source.
     */
    public function __construct(array $data, $plugin_dir, $model_file)
    {
        $this->data = $data;
        $this->plugin_dir = rtrim((string) $plugin_dir, '/\\');
        $this->model_file = (string) $model_file;
    }

    /**
     * Nom technique de la ressource.
     *
     * Il servira dans les slugs admin, les routes REST et les clés internes.
     */
    public function name()
    {
        return isset($this->data['name']) ? sanitize_key($this->data['name']) : '';
    }

    /**
     * Libellé singulier affichable.
     */
    public function label()
    {
        return isset($this->data['label']) ? (string) $this->data['label'] : ucfirst($this->name());
    }

    /**
     * Libellé pluriel affichable.
     */
    public function pluralLabel()
    {
        return isset($this->data['pluralLabel']) ? (string) $this->data['pluralLabel'] : $this->label();
    }

    /**
     * Description lisible de la ressource.
     *
     * Elle sert surtout aux sous-titres d'ecran admin pour donner un peu de contexte
     * sans obliger chaque view a re-ecrire le meme texte.
     */
    public function description()
    {
        return isset($this->data['description']) ? (string) $this->data['description'] : '';
    }

    /**
     * Slug WordPress de la page admin de cette ressource.
     */
    public function adminSlug()
    {
        return 'smbb-codetool-' . $this->name();
    }

    /**
     * Retourne le bloc admin du JSON.
     */
    public function admin()
    {
        return isset($this->data['admin']) && is_array($this->data['admin']) ? $this->data['admin'] : array();
    }

    /**
     * Indique si la ressource doit apparaître dans l'admin.
     */
    public function adminEnabled()
    {
        $admin = $this->admin();

        return !isset($admin['enabled']) || (bool) $admin['enabled'];
    }

    /**
     * Type d'écran admin.
     *
     * - resource : liste/form/details autour d'une table ou d'un store ;
     * - settings_page : formulaire unique, typiquement stocké dans wp_options ;
     * - page : écran purement UX, sans persistence obligatoire.
     */
    public function adminType()
    {
        $admin = $this->admin();
        $type = isset($admin['type']) ? sanitize_key((string) $admin['type']) : 'resource';

        return in_array($type, array('resource', 'settings_page', 'page'), true) ? $type : 'resource';
    }

    /**
     * Capability WordPress nécessaire pour accéder à la page.
     */
    public function capability()
    {
        $admin = $this->admin();

        return isset($admin['capability']) ? (string) $admin['capability'] : 'manage_options';
    }

    /**
     * Configuration du menu admin.
     */
    public function menu()
    {
        $admin = $this->admin();

        return isset($admin['menu']) && is_array($admin['menu']) ? $admin['menu'] : array();
    }

    /**
     * Emplacement de la ressource dans l'admin WordPress.
     *
     * Valeurs prévues :
     * - main    : menu principal WordPress, pour les ressources utilisées très souvent ;
     * - submenu : sous-menu du menu CodeTool, pour regrouper les ressources moins centrales ;
     * - hidden  : pas de menu visible, mais page accessible depuis "CodeTool resources".
     *
     * On garde "main" par défaut pour rester compatible avec les JSON déjà écrits.
     */
    public function menuPlacement()
    {
        $menu = $this->menu();
        $placement = isset($menu['placement']) ? sanitize_key($menu['placement']) : 'main';
        $allowed = array('main', 'submenu', 'hidden');

        return in_array($placement, $allowed, true) ? $placement : 'main';
    }

    /**
     * Slug du parent quand la ressource est placée en sous-menu.
     *
     * Deux formes sont supportées :
     *
     * 1. Parent WordPress existant :
     *    "parent": "woocommerce"
     *
     * 2. Parent thématique géré par CodeTool :
     *    "parent": {
     *      "slug": "smbb-sample",
     *      "title": "SMBB Sample",
     *      "icon": "dashicons-admin-generic",
     *      "position": 31
     *    }
     *
     * Sans parent explicite, on range la ressource sous le menu CodeTool.
     */
    public function menuParentSlug()
    {
        $menu = $this->menu();

        if (empty($menu['parent'])) {
            return 'smbb-wpcodetool';
        }

        if (is_array($menu['parent'])) {
            if (!empty($menu['parent']['slug'])) {
                return sanitize_key($menu['parent']['slug']);
            }

            if (!empty($menu['parent']['title'])) {
                return sanitize_key($menu['parent']['title']);
            }

            return 'smbb-wpcodetool';
        }

        // On ne sanitize pas trop fort ici : certains menus WordPress existants peuvent
        // utiliser des slugs avec query string, par exemple edit.php?post_type=product.
        return (string) $menu['parent'];
    }

    /**
     * Indique si CodeTool doit créer lui-même le menu parent.
     *
     * Si parent est une chaîne, on suppose que c'est un menu WordPress déjà existant.
     * Si parent est un objet, CodeTool crée le menu top-level correspondant.
     */
    public function menuParentManaged()
    {
        $menu = $this->menu();

        return $this->menuPlacement() === 'submenu' && !empty($menu['parent']) && is_array($menu['parent']);
    }

    /**
     * Titre d'un parent thématique géré par CodeTool.
     */
    public function menuParentTitle()
    {
        $menu = $this->menu();

        if (empty($menu['parent']) || !is_array($menu['parent'])) {
            return __('CodeTool', 'smbb-wpcodetool');
        }

        if (!empty($menu['parent']['title'])) {
            return (string) $menu['parent']['title'];
        }

        return ucfirst(str_replace('-', ' ', $this->menuParentSlug()));
    }

    /**
     * Icône d'un parent thématique géré par CodeTool.
     */
    public function menuParentIcon()
    {
        $menu = $this->menu();

        if (!empty($menu['parent']) && is_array($menu['parent']) && !empty($menu['parent']['icon'])) {
            return (string) $menu['parent']['icon'];
        }

        return 'dashicons-admin-generic';
    }

    /**
     * Position d'un parent thématique géré par CodeTool.
     */
    public function menuParentPosition()
    {
        $menu = $this->menu();

        if (!empty($menu['parent']) && is_array($menu['parent']) && isset($menu['parent']['position'])) {
            return (float) $menu['parent']['position'];
        }

        return null;
    }

    /**
     * Titre visible dans le menu WordPress.
     */
    public function menuTitle()
    {
        $menu = $this->menu();

        return isset($menu['title']) ? (string) $menu['title'] : $this->pluralLabel();
    }

    /**
     * Icône Dashicons du menu.
     */
    public function menuIcon()
    {
        $menu = $this->menu();

        return isset($menu['icon']) ? (string) $menu['icon'] : 'dashicons-admin-generic';
    }

    /**
     * Position du menu dans l'admin WordPress.
     */
    public function menuPosition()
    {
        $menu = $this->menu();

        return isset($menu['position']) ? (float) $menu['position'] : null;
    }

    /**
     * Bloc views du JSON.
     */
    public function views()
    {
        $admin = $this->admin();

        return isset($admin['views']) && is_array($admin['views']) ? $admin['views'] : array();
    }

    /**
     * Configuration optionnelle d'une page admin purement UX.
     *
     * Exemple :
     * "page": {
     *   "defaultView": "dashboard",
     *   "hero": {...},
     *   "quickLinks": [...]
     * }
     */
    public function pageConfig()
    {
        $admin = $this->admin();

        return isset($admin['page']) && is_array($admin['page']) ? $admin['page'] : array();
    }

    /**
     * Vue admin ouverte par defaut pour cette ressource.
     *
     * - resource      -> list ;
     * - settings_page -> form ;
     * - page          -> page.defaultView, puis views.view/dashboard/page, puis premiere vue.
     */
    public function defaultAdminView()
    {
        if ($this->adminType() === 'settings_page') {
            return 'form';
        }

        if ($this->adminType() !== 'page') {
            return 'list';
        }

        $views = $this->views();
        $page = $this->pageConfig();
        $configured = isset($page['defaultView']) ? sanitize_key((string) $page['defaultView']) : '';

        if ($configured !== '' && !empty($views[$configured])) {
            return $configured;
        }

        foreach (array('view', 'dashboard', 'page', 'form', 'list') as $candidate) {
            if (!empty($views[$candidate])) {
                return $candidate;
            }
        }

        $keys = array_keys($views);

        return !empty($keys) ? sanitize_key((string) reset($keys)) : 'view';
    }

    /**
     * Configuration de liste admin.
     *
     * Exemple dans le JSON :
     * "list": {
     *   "perPage": 20,
     *   "search": ["name"],
     *   "defaultOrder": {"by": "id", "direction": "desc"}
     * }
     */
    public function listConfig()
    {
        $admin = $this->admin();

        return isset($admin['list']) && is_array($admin['list']) ? $admin['list'] : array();
    }

    /**
     * Configuration normalisee de la recherche de liste.
     *
     * Formats supportes :
     *
     * 1. Ancien format :
     * "search": ["name", "reference"]
     *
     * 2. Nouveau format :
     * "search": {
     *   "enabled": true,
     *   "fields": ["name", "reference"],
     *   "mode": "or",
     *   "placeholder": "Search tests",
     *   "provider": "default"
     * }
     */
    public function listSearchConfig()
    {
        $config = $this->listConfig();

        if (!isset($config['search'])) {
            return array(
                'enabled' => false,
                'fields' => array(),
                'mode' => 'or',
                'placeholder' => '',
                'provider' => 'default',
            );
        }

        if (is_array($config['search']) && $this->looksLikeStructuredSearchConfig($config['search'])) {
            $fields = isset($config['search']['fields']) && is_array($config['search']['fields']) ? $config['search']['fields'] : array();

            return array(
                'enabled' => !array_key_exists('enabled', $config['search']) || (bool) $config['search']['enabled'],
                'fields' => $fields,
                'mode' => isset($config['search']['mode']) ? strtolower((string) $config['search']['mode']) : 'or',
                'placeholder' => isset($config['search']['placeholder']) ? (string) $config['search']['placeholder'] : '',
                'provider' => isset($config['search']['provider']) ? sanitize_key((string) $config['search']['provider']) : 'default',
            );
        }

        return array(
            'enabled' => !empty($config['search']),
            'fields' => is_array($config['search']) ? $config['search'] : array(),
            'mode' => 'or',
            'placeholder' => '',
            'provider' => 'default',
        );
    }

    /**
     * Indique si la recherche libre est active dans la liste.
     */
    public function listSearchEnabled()
    {
        $search = $this->listSearchConfig();

        return !empty($search['enabled']);
    }

    /**
     * Colonnes sur lesquelles la recherche libre peut porter.
     */
    public function listSearchColumns()
    {
        $columns = array();

        foreach ($this->listSearchConfig()['fields'] as $field) {
            $field = sanitize_key((string) $field);

            if ($field !== '') {
                $columns[] = $field;
            }
        }

        return array_values(array_unique($columns));
    }

    /**
     * Mode de combinaison de la recherche multi-colonnes : OR ou AND.
     */
    public function listSearchMode()
    {
        return $this->listSearchConfig()['mode'] === 'and' ? 'and' : 'or';
    }

    /**
     * Placeholder personnalise de la barre de recherche.
     */
    public function listSearchPlaceholder()
    {
        $placeholder = $this->listSearchConfig()['placeholder'];

        return $placeholder !== '' ? $placeholder : __('Search', 'smbb-wpcodetool');
    }

    /**
     * Fournisseur de recherche : "default" ou "hook".
     */
    public function listSearchProvider()
    {
        $provider = $this->listSearchConfig()['provider'];

        return in_array($provider, array('default', 'hook'), true) ? $provider : 'default';
    }

    /**
     * Colonnes autorisees pour le filtre simple de liste.
     *
     * Exemple JSON :
     * "filters": ["name", "amount"]
     *
     * Forme etendue supportee pour plus tard :
     * "filters": [
     *   {"field": "amount", "label": "Amount"}
     * ]
     */
    public function listFilters()
    {
        return $this->listFiltersConfig()['fields'];
    }

    /**
     * Noms techniques des colonnes filtrables.
     */
    public function listFilterColumns()
    {
        $columns = array();

        foreach ($this->listFilters() as $key => $filter) {
            $field = '';

            if (is_string($filter)) {
                $field = $filter;
            } elseif (is_array($filter)) {
                if (!empty($filter['field'])) {
                    $field = $filter['field'];
                } elseif (is_string($key)) {
                    $field = $key;
                }
            }

            $field = sanitize_key((string) $field);

            if ($field !== '') {
                $columns[] = $field;
            }
        }

        return array_values(array_unique($columns));
    }

    /**
     * Indique si les filtres de liste sont actives.
     */
    public function listFiltersEnabled()
    {
        $filters = $this->listFiltersConfig();

        return !empty($filters['enabled']) && !empty($filters['fields']);
    }

    /**
     * Definitions detaillees des champs filtrables.
     */
    public function listFilterDefinitions()
    {
        $definitions = array();

        foreach ($this->listFilters() as $key => $filter) {
            $field = '';
            $definition = array();

            if (is_string($filter)) {
                $field = $filter;
            } elseif (is_array($filter)) {
                $definition = $filter;

                if (!empty($filter['field'])) {
                    $field = $filter['field'];
                } elseif (is_string($key)) {
                    $field = $key;
                }
            }

            $field = sanitize_key((string) $field);

            if ($field === '') {
                continue;
            }

            $definitions[$field] = array(
                'label' => !empty($definition['label']) ? (string) $definition['label'] : ucfirst(str_replace('_', ' ', $field)),
                'operators' => isset($definition['operators']) && is_array($definition['operators']) ? array_values(array_unique(array_map('sanitize_key', $definition['operators']))) : array(),
            );
        }

        return $definitions;
    }

    /**
     * Configuration normalisee des filtres de liste.
     */
    public function listFiltersConfig()
    {
        $config = $this->listConfig();

        if (!isset($config['filters'])) {
            return array(
                'enabled' => false,
                'fields' => array(),
            );
        }

        if (is_array($config['filters']) && $this->looksLikeStructuredFiltersConfig($config['filters'])) {
            return array(
                'enabled' => !array_key_exists('enabled', $config['filters']) || (bool) $config['filters']['enabled'],
                'fields' => isset($config['filters']['fields']) && is_array($config['filters']['fields']) ? $config['filters']['fields'] : array(),
            );
        }

        return array(
            'enabled' => !empty($config['filters']),
            'fields' => is_array($config['filters']) ? $config['filters'] : array(),
        );
    }

    /**
     * Repere le nouveau format search={...}.
     */
    private function looksLikeStructuredSearchConfig(array $search)
    {
        return array_key_exists('enabled', $search)
            || array_key_exists('fields', $search)
            || array_key_exists('mode', $search)
            || array_key_exists('placeholder', $search)
            || array_key_exists('provider', $search);
    }

    /**
     * Repere le nouveau format filters={...}.
     */
    private function looksLikeStructuredFiltersConfig(array $filters)
    {
        return array_key_exists('enabled', $filters)
            || array_key_exists('fields', $filters);
    }

    /**
     * Configuration de la barre d'admin WordPress.
     *
     * Exemple :
     * "adminBar": {
     *   "newContent": true,
     *   "shortcut": true
     * }
     *
     * - newContent ajoute une entree sous le bouton WordPress "+ New / Creer" ;
     * - shortcut ajoute un bouton direct dans la barre noire d'admin.
     */
    public function adminBar()
    {
        $admin = $this->admin();

        return isset($admin['adminBar']) && is_array($admin['adminBar']) ? $admin['adminBar'] : array();
    }

    /**
     * Indique si la ressource doit apparaitre dans le menu "+ New / Creer".
     */
    public function adminBarNewContentEnabled()
    {
        $admin_bar = $this->adminBar();

        return !empty($admin_bar['newContent']);
    }

    /**
     * Indique si la ressource doit avoir son propre raccourci dans la barre d'admin.
     */
    public function adminBarShortcutEnabled()
    {
        $admin_bar = $this->adminBar();

        return !empty($admin_bar['shortcut']);
    }

    /**
     * Chemin absolu d'une view admin.
     *
     * Les chemins dans le JSON sont relatifs au dossier codetool du plugin consommateur.
     * Exemple : views/test/list.php -> smbb-sample/codetool/views/test/list.php.
     */
    public function viewPath($view)
    {
        $views = $this->views();

        if (empty($views[$view])) {
            return '';
        }

        return $this->plugin_dir . DIRECTORY_SEPARATOR . 'codetool' . DIRECTORY_SEPARATOR . str_replace(array('/', '\\'), DIRECTORY_SEPARATOR, $views[$view]);
    }

    /**
     * Bloc storage du JSON.
     */
    public function storage()
    {
        return isset($this->data['storage']) && is_array($this->data['storage']) ? $this->data['storage'] : array();
    }

    /**
     * Colonnes SQL declarees par le modele.
     *
     * On garde volontairement le tableau brut : la validation fine et la conversion SQL
     * sont la responsabilite du SchemaBuilder. Cette classe reste un lecteur stable du JSON.
     */
    public function columns()
    {
        return isset($this->data['columns']) && is_array($this->data['columns']) ? $this->data['columns'] : array();
    }

    /**
     * Colonnes qui stockent explicitement une structure JSON.
     *
     * Regles supportees :
     * - type = json ;
     * - format = json ou codec = json ;
     * - default tableau/objet ;
     * - compatibilite legacy : nom json_* ou *_json.
     *
     * Cette detection est volontairement centralisee ici pour eviter que le store
     * decode "au pif" n'importe quel longtext qui commence par { ou [.
     */
    public function jsonColumns()
    {
        if ($this->json_columns !== null) {
            return $this->json_columns;
        }

        $columns = array();

        foreach ($this->columns() as $column_name => $definition) {
            if (!is_array($definition)) {
                continue;
            }

            $type = isset($definition['type']) ? strtolower((string) $definition['type']) : '';
            $format = isset($definition['format']) ? sanitize_key((string) $definition['format']) : '';
            $codec = isset($definition['codec']) ? sanitize_key((string) $definition['codec']) : '';
            $default = array_key_exists('default', $definition) ? $definition['default'] : null;
            $legacy_name = preg_match('/(^json_|_json$)/', (string) $column_name) === 1;

            if ($type === 'json' || $format === 'json' || $codec === 'json' || is_array($default) || is_object($default) || $legacy_name) {
                $columns[] = sanitize_key((string) $column_name);
            }
        }

        $this->json_columns = array_values(array_unique(array_filter($columns)));

        return $this->json_columns;
    }

    /**
     * Indique si une colonne est censee stocker une structure JSON.
     */
    public function columnStoresJson($column_name)
    {
        return in_array(sanitize_key((string) $column_name), $this->jsonColumns(), true);
    }

    /**
     * Index SQL declares par le modele.
     */
    public function indexes()
    {
        return isset($this->data['indexes']) && is_array($this->data['indexes']) ? $this->data['indexes'] : array();
    }

    /**
     * Type de stockage.
     *
     * Par convention du prototype :
     * - custom_table : ressource CRUD stockée en table SQL ;
     * - option       : objet unique stocké dans wp_options ;
     * - none         : aucune persistence gérée par CodeTool.
     */
    public function storageType()
    {
        $storage = $this->storage();
        $type = isset($storage['type']) ? sanitize_key((string) $storage['type']) : 'custom_table';

        return in_array($type, array('custom_table', 'option', 'none'), true) ? $type : 'custom_table';
    }

    /**
     * Nom de table sans prefixe WordPress.
     *
     * Dans CodeTool on a decide de toujours utiliser le prefixe WP. Le JSON declare donc
     * seulement le nom metier : "smbb_sample_tests", jamais "wp_smbb_sample_tests".
     */
    public function tableBaseName()
    {
        $storage = $this->storage();
        $table = isset($storage['table']) ? (string) $storage['table'] : $this->name();
        $table = strtolower(str_replace('-', '_', $table));
        $table = preg_replace('/[^a-z0-9_]/', '_', $table);
        $table = trim($table, '_');

        return $table !== '' ? $table : $this->name();
    }

    /**
     * Nom complet de la table SQL, avec prefixe WordPress.
     */
    public function tableName()
    {
        global $wpdb;

        return $wpdb->prefix . $this->tableBaseName();
    }

    /**
     * Nom de la clé primaire pour les ressources table.
     */
    public function primaryKey()
    {
        $storage = $this->storage();

        return isset($storage['primaryKey']) ? (string) $storage['primaryKey'] : 'id';
    }

    /**
     * Valeurs par défaut d'une ressource option.
     */
    public function optionDefaults()
    {
        $storage = $this->storage();

        return isset($storage['default']) && is_array($storage['default']) ? $storage['default'] : array();
    }

    /**
     * Nom de l'option WordPress pour une ressource storage.type=option.
     */
    public function optionName()
    {
        $storage = $this->storage();

        return isset($storage['optionName']) ? (string) $storage['optionName'] : $this->name();
    }

    /**
     * Réglage autoload d'une ressource option.
     *
     * null signifie : ne pas forcer le troisième paramètre de update_option().
     */
    public function optionAutoload()
    {
        $storage = $this->storage();

        return array_key_exists('autoload', $storage) ? (bool) $storage['autoload'] : null;
    }

    /**
     * Bloc hooks du JSON.
     *
     * Les hooks restent optionnels. Ils permettent a un plugin consommateur
     * d'intervenir dans le cycle de vie sans coder le CRUD standard lui-meme.
     */
    public function hooks()
    {
        return isset($this->data['hooks']) && is_array($this->data['hooks']) ? $this->data['hooks'] : array();
    }

    /**
     * Chemin absolu du fichier de hooks, si declare.
     */
    public function hookFilePath()
    {
        $hooks = $this->hooks();

        if (empty($hooks['file'])) {
            return '';
        }

        return $this->plugin_dir . DIRECTORY_SEPARATOR . 'codetool' . DIRECTORY_SEPARATOR . str_replace(array('/', '\\'), DIRECTORY_SEPARATOR, $hooks['file']);
    }

    /**
     * Classe PHP de hooks, si declaree.
     */
    public function hookClass()
    {
        $hooks = $this->hooks();

        return !empty($hooks['class']) ? (string) $hooks['class'] : '';
    }

    /**
     * Bloc api du JSON.
     */
    public function api()
    {
        return isset($this->data['api']) && is_array($this->data['api']) ? $this->data['api'] : array();
    }

    /**
     * Indique si la ressource expose une API REST.
     */
    public function apiEnabled()
    {
        $api = $this->api();

        return !empty($api['enabled']);
    }

    /**
     * Namespace REST WordPress de la ressource.
     */
    public function apiNamespace()
    {
        $api = $this->api();
        $namespace = isset($api['namespace']) ? (string) $api['namespace'] : 'smbb-wpcodetool/v1';
        $segments = array_filter(array_map('sanitize_key', explode('/', trim(str_replace('\\', '/', $namespace), '/'))));

        return $segments ? implode('/', $segments) : 'smbb-wpcodetool/v1';
    }

    /**
     * Base path REST de la ressource dans son namespace.
     */
    public function apiBase()
    {
        $api = $this->api();
        $base = isset($api['base']) ? (string) $api['base'] : $this->name();
        $segments = array_filter(array_map('sanitize_key', explode('/', trim(str_replace('\\', '/', $base), '/'))));

        return $segments ? implode('/', $segments) : $this->name();
    }

    /**
     * Capability requise pour la ressource cote API.
     */
    public function apiCapability()
    {
        $api = $this->api();

        return isset($api['capability']) ? (string) $api['capability'] : $this->capability();
    }

    /**
     * Configuration normalisee des actions REST standard.
     */
    public function apiActions()
    {
        $api = $this->api();
        $actions = isset($api['actions']) && is_array($api['actions']) ? $api['actions'] : array();

        $normalized = array(
            'list' => $this->normalizeApiAction(isset($actions['list']) ? $actions['list'] : false, array(
                'enabled' => false,
            )),
            'get' => $this->normalizeApiAction(isset($actions['get']) ? $actions['get'] : false, array(
                'enabled' => false,
            )),
            'create' => $this->normalizeApiAction(isset($actions['create']) ? $actions['create'] : false, array(
                'enabled' => false,
            )),
            'patch' => $this->normalizeApiAction(isset($actions['patch']) ? $actions['patch'] : false, array(
                'enabled' => false,
                'missingFields' => 'keep',
                'nullFields' => 'set_null',
            )),
            'put' => $this->normalizeApiAction(isset($actions['put']) ? $actions['put'] : false, array(
                'enabled' => false,
                'missingFields' => 'reject',
                'nullFields' => 'set_null',
            )),
            'delete' => $this->normalizeApiAction(isset($actions['delete']) ? $actions['delete'] : false, array(
                'enabled' => false,
            )),
        );

        foreach (array('patch', 'put') as $action) {
            $normalized[$action]['missingFields'] = $this->normalizeApiMode(
                isset($normalized[$action]['missingFields']) ? $normalized[$action]['missingFields'] : '',
                array('keep', 'reject', 'set_null'),
                $action === 'patch' ? 'keep' : 'reject'
            );
            $normalized[$action]['nullFields'] = $this->normalizeApiMode(
                isset($normalized[$action]['nullFields']) ? $normalized[$action]['nullFields'] : '',
                array('set_null', 'ignore', 'reject'),
                'set_null'
            );
        }

        return $normalized;
    }

    /**
     * Configuration d'une action REST standard.
     */
    public function apiActionConfig($action)
    {
        $action = sanitize_key((string) $action);
        $actions = $this->apiActions();

        return isset($actions[$action]) ? $actions[$action] : array('enabled' => false);
    }

    /**
     * Indique si une action REST standard est active.
     */
    public function apiActionEnabled($action)
    {
        $config = $this->apiActionConfig($action);

        return !empty($config['enabled']);
    }

    /**
     * Routes custom declarees dans le JSON.
     */
    public function apiCustomRoutes()
    {
        $api = $this->api();
        $custom = isset($api['custom']) && is_array($api['custom']) ? $api['custom'] : array();
        $routes = array();

        foreach ($custom as $name => $definition) {
            if (!is_array($definition)) {
                continue;
            }

            $method = strtoupper(isset($definition['method']) ? (string) $definition['method'] : 'GET');

            if (!in_array($method, array('GET', 'POST', 'PUT', 'PATCH', 'DELETE'), true)) {
                $method = 'GET';
            }

            $routes[sanitize_key((string) $name)] = array(
                'enabled' => !array_key_exists('enabled', $definition) || (bool) $definition['enabled'],
                'method' => $method,
                'path' => $this->normalizeApiPath(isset($definition['path']) ? (string) $definition['path'] : '/' . $this->apiBase()),
                'file' => isset($definition['file']) ? (string) $definition['file'] : '',
                'class' => isset($definition['class']) ? (string) $definition['class'] : '',
                'callback' => isset($definition['callback']) ? (string) $definition['callback'] : '',
                'summary' => isset($definition['summary']) ? (string) $definition['summary'] : '',
                'description' => isset($definition['description']) ? (string) $definition['description'] : '',
                'args' => isset($definition['args']) && is_array($definition['args']) ? $definition['args'] : array(),
            );
        }

        return $routes;
    }

    /**
     * Chemin absolu du fichier PHP d'une route custom.
     */
    public function apiCustomRouteFilePath($route_name)
    {
        $routes = $this->apiCustomRoutes();

        if (empty($routes[$route_name]['file'])) {
            return '';
        }

        return $this->plugin_dir . DIRECTORY_SEPARATOR . 'codetool' . DIRECTORY_SEPARATOR . str_replace(array('/', '\\'), DIRECTORY_SEPARATOR, $routes[$route_name]['file']);
    }

    /**
     * Chemin absolu du fichier JSON source, utile pour les pages debug.
     */
    public function modelFile()
    {
        return $this->model_file;
    }

    public function pluginDir()
    {
        return $this->plugin_dir;
    }

    /**
     * Données brutes, réservées aux écrans debug ou aux futures validations.
     */
    public function raw()
    {
        return $this->data;
    }

    /**
     * Normalise une action REST declaree en bool ou en objet.
     */
    private function normalizeApiAction($value, array $defaults)
    {
        if (!is_array($value)) {
            return array_merge($defaults, array(
                'enabled' => (bool) $value,
            ));
        }

        return array_merge(
            $defaults,
            $value,
            array(
                'enabled' => !array_key_exists('enabled', $value) || (bool) $value['enabled'],
            )
        );
    }

    /**
     * Normalise un mode connu d'action REST.
     */
    private function normalizeApiMode($value, array $allowed, $default)
    {
        $value = sanitize_key((string) $value);

        return in_array($value, $allowed, true) ? $value : $default;
    }

    /**
     * Garantit un path REST avec slash initial.
     */
    private function normalizeApiPath($path)
    {
        $path = trim((string) $path);

        if ($path === '') {
            return '/' . $this->apiBase();
        }

        return '/' . ltrim($path, '/');
    }
}
