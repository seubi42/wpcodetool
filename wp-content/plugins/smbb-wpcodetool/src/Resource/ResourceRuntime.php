<?php

namespace Smbb\WpCodeTool\Resource;

defined('ABSPATH') || exit;

/**
 * Regroupe les helpers runtime partages autour des hooks et metadonnees de ressource.
 *
 * Cela permet de garder l'admin et l'API concentres sur le transport, pendant qu'un
 * seul service porte la resolution des hooks, les colonnes gerees et quelques aides
 * transverses.
 */
final class ResourceRuntime
{
    private $hooks_cache = array();

    /**
     * Indicates whether the primary key uses a numeric SQL type.
     */
    public function primaryKeyIsNumeric(ResourceDefinition $resource)
    {
        $primary_key = $resource->primaryKey();
        $columns = $resource->columns();
        $definition = isset($columns[$primary_key]) && is_array($columns[$primary_key]) ? $columns[$primary_key] : array();
        $type = isset($definition['type']) ? strtolower((string) $definition['type']) : '';

        return in_array($type, array('bigint', 'int', 'integer', 'mediumint', 'smallint', 'tinyint'), true);
    }

    /**
     * Adds engine-managed audit columns before persistence.
     */
    public function applyManagedColumns(ResourceDefinition $resource, array $data, $is_create)
    {
        $now = current_time('mysql');
        $user_id = get_current_user_id();

        foreach ($resource->columns() as $column => $definition) {
            if (!is_array($definition) || empty($definition['managed'])) {
                continue;
            }

            switch ((string) $definition['managed']) {
                case 'create_datetime':
                    if ($is_create) {
                        $data[$column] = $now;
                    }
                    break;

                case 'update_datetime':
                    $data[$column] = $now;
                    break;

                case 'create_user':
                    if ($is_create) {
                        $data[$column] = $user_id;
                    }
                    break;

                case 'update_user':
                    $data[$column] = $user_id;
                    break;
            }
        }

        return $data;
    }

    /**
     * Resolves the hooks class declared by the resource.
     */
    public function hooksFor(ResourceDefinition $resource)
    {
        $cache_key = $resource->name();

        if (array_key_exists($cache_key, $this->hooks_cache)) {
            return $this->hooks_cache[$cache_key];
        }

        $file = $resource->hookFilePath();
        $class = $resource->hookClass();

        if ($file && is_readable($file)) {
            require_once $file;
        }

        if ($class && class_exists($class)) {
            $this->hooks_cache[$cache_key] = new $class();

            return $this->hooks_cache[$cache_key];
        }

        $this->hooks_cache[$cache_key] = null;

        return null;
    }

    /**
     * Actions groupées proposées sur la liste d'une ressource table.
     *
     * Intégrées : delete et duplicate. Sources, dans l'ordre (la première déclaration d'une clé l'emporte) :
     * 1. le JSON du modèle, admin.list.batchActions (voir ResourceDefinition::listBatchActions) ;
     * 2. la classe de hooks, batchActions(array $context), pour les cas dynamiques ;
     * 3. le filtre smbb_wpcodetool_batch_actions sur la liste finale.
     * Format d'une entrée : libellé, ou ['label', 'method', 'confirm', 'capability', 'danger'] ;
     * false retire une action intégrée.
     *
     * @return array<string,array{label:string,method:string,confirm:string,capability:string,danger:bool,builtin:bool}>
     */
    public function batchActions(ResourceDefinition $resource)
    {
        $builtin = array(
            'duplicate' => array(
                'label' => __('Dupliquer', 'smbb-wpcodetool'),
                'confirm' => '',
                'capability' => '',
                'danger' => false,
                'builtin' => true,
            ),
            'delete' => array(
                'label' => __('Supprimer', 'smbb-wpcodetool'),
                'confirm' => __('Supprimer les éléments sélectionnés ? Cette action est irréversible.', 'smbb-wpcodetool'),
                'capability' => '',
                'danger' => true,
                'builtin' => true,
            ),
        );
        $custom = array();
        $hooks = $this->hooksFor($resource);
        $sources = array($resource->listBatchActions());

        if (is_object($hooks) && method_exists($hooks, 'batchActions')) {
            $declared = call_user_func(array($hooks, 'batchActions'), array('resource' => $resource->name()));
            $sources[] = is_array($declared) ? $declared : array();
        }

        foreach ($sources as $declared) {
            foreach ($declared as $key => $definition) {
                $key = sanitize_key((string) $key);

                if ($key === '') {
                    continue;
                }

                if ($definition === false) {
                    unset($builtin[$key]);
                    continue;
                }

                // delete et duplicate sont réservées : on peut les retirer, pas les redéfinir.
                if (in_array($key, array('delete', 'duplicate'), true) || isset($custom[$key])) {
                    continue;
                }

                $definition = is_array($definition) ? $definition : array('label' => (string) $definition);

                if (empty($definition['label'])) {
                    continue;
                }

                $custom[$key] = array(
                    'label' => (string) $definition['label'],
                    'method' => isset($definition['method']) ? (string) $definition['method'] : '',
                    'confirm' => isset($definition['confirm']) ? (string) $definition['confirm'] : '',
                    'capability' => isset($definition['capability']) ? (string) $definition['capability'] : '',
                    'danger' => !empty($definition['danger']),
                    'builtin' => false,
                );
            }
        }

        // Les actions de la ressource d'abord, les actions génériques (dont la suppression) en dernier.
        $actions = apply_filters('smbb_wpcodetool_batch_actions', $custom + $builtin, $resource->name());
        $allowed = array();

        foreach (is_array($actions) ? $actions : array() as $key => $action) {
            if (!is_array($action) || empty($action['label'])) {
                continue;
            }

            if (!empty($action['capability']) && !current_user_can($action['capability'])) {
                continue;
            }

            $allowed[$key] = $action;
        }

        return $allowed;
    }

    /**
     * Exécute une action groupée déclarée par la classe de hooks.
     *
     * handleBatch() peut renvoyer :
     * - ['success' => bool, 'message' => string, 'count' => int, 'details' => [libellé => message]] ;
     * - un WP_Error ;
     * - un entier (nombre d'éléments traités) ou true.
     *
     * @return array{success:bool,message:string,count:int,details:array}
     */
    public function callBatchHook(ResourceDefinition $resource, $action, array $ids, array $context = array())
    {
        $hooks = $this->hooksFor($resource);
        $actions = $this->batchActions($resource);
        $method = isset($actions[$action]['method']) && $actions[$action]['method'] !== '' ? $actions[$action]['method'] : 'handleBatch';

        if (!isset($actions[$action]) || !empty($actions[$action]['builtin']) || !is_object($hooks) || !method_exists($hooks, $method)) {
            return array(
                'success' => false,
                'message' => sprintf(
                    /* translators: %s: méthode attendue dans la classe de hooks */
                    __('Cette action groupée n\'est pas prise en charge (méthode %s introuvable dans les hooks).', 'smbb-wpcodetool'),
                    $method
                ),
                'count' => 0,
                'details' => array(),
            );
        }

        $context['resource'] = $resource->name();
        $context['batch_action'] = (string) $action;
        $result = $method === 'handleBatch'
            ? call_user_func(array($hooks, 'handleBatch'), (string) $action, $ids, $context)
            : call_user_func(array($hooks, $method), $ids, $context);

        if (is_wp_error($result)) {
            return array('success' => false, 'message' => $result->get_error_message(), 'count' => 0, 'details' => array());
        }

        if (is_int($result) || $result === true) {
            $count = $result === true ? count($ids) : max(0, $result);

            return array('success' => true, 'message' => '', 'count' => $count, 'details' => array());
        }

        $result = is_array($result) ? $result : array();

        return array(
            'success' => !empty($result['success']),
            'message' => isset($result['message']) ? (string) $result['message'] : '',
            'count' => isset($result['count']) ? max(0, (int) $result['count']) : (!empty($result['success']) ? count($ids) : 0),
            'details' => isset($result['details']) && is_array($result['details']) ? $result['details'] : array(),
        );
    }

    /**
     * Calls a hook that transforms resource data.
     */
    public function callDataHook($hooks, $method, array $data, array $context)
    {
        if (is_object($hooks) && method_exists($hooks, $method)) {
            $result = call_user_func(array($hooks, $method), $data, $context);

            return is_array($result) ? $result : $data;
        }

        return $data;
    }

    /**
     * Calls validate() and keeps a predictable array result.
     */
    public function callValidateHook($hooks, array $data, array $context)
    {
        if (!is_object($hooks) || !method_exists($hooks, 'validate')) {
            return array();
        }

        $errors = call_user_func(array($hooks, 'validate'), $data, $context);

        return is_array($errors) ? array_filter($errors) : array();
    }

    /**
     * Lets a hooks class enrich the admin view context.
     */
    public function callViewContextHook($hooks, array $context)
    {
        if (!is_object($hooks) || !method_exists($hooks, 'viewContext')) {
            return $context;
        }

        $result = call_user_func(array($hooks, 'viewContext'), $context);

        if (!is_array($result)) {
            return $context;
        }

        return array_merge($context, $result);
    }

    /**
     * Calls afterSave() when the hooks class provides it.
     */
    public function callAfterSaveHook($hooks, array $row, array $context)
    {
        if (is_object($hooks) && method_exists($hooks, 'afterSave')) {
            call_user_func(array($hooks, 'afterSave'), $row, $context);
        }
    }

    /**
     * Calls beforeDelete() and normalizes WP_Error into a message.
     */
    public function callBeforeDeleteHook($hooks, array $row, array $context)
    {
        if (is_object($hooks) && method_exists($hooks, 'beforeDelete')) {
            $result = call_user_func(array($hooks, 'beforeDelete'), $row, $context);

            if (function_exists('is_wp_error') && is_wp_error($result)) {
                return $result->get_error_message();
            }

            return $result === null ? true : $result;
        }

        return true;
    }

    /**
     * Reads an optional SQL search clause from resource hooks.
     */
    public function tableSearchClause(ResourceDefinition $resource, $search)
    {
        $hooks = $this->hooksFor($resource);

        if (!is_object($hooks) || !method_exists($hooks, 'listSearchClause')) {
            return array();
        }

        $result = call_user_func(array($hooks, 'listSearchClause'), $search, array(
            'resource' => $resource,
        ));

        if (!is_array($result) || empty($result['sql']) || !is_string($result['sql'])) {
            return array();
        }

        return array(
            'sql' => $result['sql'],
            'params' => isset($result['params']) && is_array($result['params']) ? array_values($result['params']) : array(),
        );
    }
}
