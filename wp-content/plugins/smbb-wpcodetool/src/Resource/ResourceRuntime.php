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
