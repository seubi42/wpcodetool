<?php

namespace Smbb\WpCodeTool\Resource;

use Smbb\WpCodeTool\Store\OptionStore;
use Smbb\WpCodeTool\Store\TableStore;

defined('ABSPATH') || exit;

/**
 * Shared mutation pipeline for admin and API writes.
 *
 * The goal is to centralize the resource lifecycle:
 * beforeValidate -> validate -> managed columns -> beforeSave -> persist -> afterSave.
 */
final class ResourceMutationService
{
    private $runtime;

    public function __construct(ResourceRuntime $runtime = null)
    {
        $this->runtime = $runtime ?: new ResourceRuntime();
    }

    /**
     * Persists a singleton option resource.
     *
     * @param array<string,mixed> $data
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    public function saveOption(ResourceDefinition $resource, array $data, array $options = array())
    {
        $store = $this->optionStore($resource, $options);
        $current = isset($options['current']) && is_array($options['current']) ? $options['current'] : $store->get();
        $hooks = $this->runtime->hooksFor($resource);

        if (!empty($options['merge_current'])) {
            $data = array_replace_recursive($current, $data);
        }

        $context = $this->context(array(
            'action' => isset($options['action']) ? (string) $options['action'] : 'save_option',
            'resource' => $resource,
            'store' => $store,
            'current' => $current,
        ), $options);

        $data = $this->runtime->callDataHook($hooks, 'beforeValidate', $data, $context);
        $errors = $this->validationErrors($options, $data, $context, $hooks, $store);

        if ($errors) {
            return array(
                'success' => false,
                'reason' => 'validation',
                'data' => $data,
                'errors' => $errors,
                'context' => $context,
                'hooks' => $hooks,
                'store' => $store,
            );
        }

        $data = $this->runtime->callDataHook($hooks, 'beforeSave', $data, $context);

        if (!$store->replace($data)) {
            return array(
                'success' => false,
                'reason' => 'storage',
                'message' => $this->storeMessage($options, 'save_failed_message', __('The settings could not be saved.', 'smbb-wpcodetool')),
                'data' => $data,
                'errors' => array(),
                'context' => $context,
                'hooks' => $hooks,
                'store' => $store,
            );
        }

        $payload = $store->get();
        $this->runtime->callAfterSaveHook($hooks, $payload, $context);

        return array(
            'success' => true,
            'reason' => 'saved',
            'data' => $data,
            'payload' => $payload,
            'errors' => array(),
            'context' => $context,
            'hooks' => $hooks,
            'store' => $store,
        );
    }

    /**
     * Creates or updates a table-backed resource.
     *
     * @param array<string,mixed> $data
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    public function saveTable(ResourceDefinition $resource, array $data, array $options = array())
    {
        $store = $this->tableStore($resource, $options);
        $hooks = $this->runtime->hooksFor($resource);
        $id = array_key_exists('id', $options) ? $options['id'] : null;
        $is_update = $id !== null;
        $current = isset($options['current']) && is_array($options['current']) ? $options['current'] : null;

        if ($is_update && $current === null) {
            $current = $store->find($id);

            if (!$current) {
                return array(
                    'success' => false,
                    'reason' => 'not_found',
                    'message' => $this->storeMessage($options, 'not_found_message', __('The item to update was not found.', 'smbb-wpcodetool')),
                    'data' => $data,
                    'errors' => array(),
                    'store' => $store,
                    'hooks' => $hooks,
                );
            }
        }

        $context = $this->context(array(
            'action' => isset($options['action']) ? (string) $options['action'] : ($is_update ? 'update' : 'create'),
            'id' => $id,
            'resource' => $resource,
            'store' => $store,
        ), $options);

        if ($current !== null) {
            $context['current'] = $current;
        }

        $data = $this->runtime->callDataHook($hooks, 'beforeValidate', $data, $context);
        $errors = $this->validationErrors($options, $data, $context, $hooks, $store);

        if ($errors) {
            return array(
                'success' => false,
                'reason' => 'validation',
                'data' => $data,
                'errors' => $errors,
                'context' => $context,
                'hooks' => $hooks,
                'store' => $store,
            );
        }

        $data = $this->runtime->applyManagedColumns($resource, $data, !$is_update);
        $data = $this->runtime->callDataHook($hooks, 'beforeSave', $data, $context);

        if ($is_update) {
            $result = $store->update($id, $data);
            $saved_id = $id;
        } else {
            $result = $store->create($data);
            $saved_id = $result;
        }

        if ($result === false) {
            return array(
                'success' => false,
                'reason' => 'storage',
                'message' => $store->lastError() ?: $this->storeMessage($options, 'save_failed_message', __('The item could not be saved.', 'smbb-wpcodetool')),
                'data' => $data,
                'errors' => array(),
                'context' => $context,
                'hooks' => $hooks,
                'store' => $store,
            );
        }

        $row = $store->find($saved_id);
        $payload = is_array($row) ? $row : $data;
        $context['id'] = $saved_id;
        $this->runtime->callAfterSaveHook($hooks, $payload, $context);

        return array(
            'success' => true,
            'reason' => $is_update ? 'updated' : 'created',
            'id' => $saved_id,
            'data' => $data,
            'payload' => $payload,
            'errors' => array(),
            'context' => $context,
            'hooks' => $hooks,
            'store' => $store,
        );
    }

    /**
     * Soft-deletes a table-backed row.
     *
     * @param mixed $id
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    public function deleteTable(ResourceDefinition $resource, $id, array $options = array())
    {
        $store = $this->tableStore($resource, $options);
        $hooks = $this->runtime->hooksFor($resource);
        $row = $store->find($id);

        if (!$row) {
            return array(
                'success' => false,
                'reason' => 'not_found',
                'message' => $this->storeMessage($options, 'not_found_message', __('The item to delete was not found.', 'smbb-wpcodetool')),
                'errors' => array(),
                'store' => $store,
                'hooks' => $hooks,
            );
        }

        $context = $this->context(array(
            'action' => isset($options['action']) ? (string) $options['action'] : 'delete',
            'id' => $id,
            'resource' => $resource,
            'store' => $store,
        ), $options);

        $allowed = $this->runtime->callBeforeDeleteHook($hooks, $row, $context);

        if ($allowed !== true) {
            return array(
                'success' => false,
                'reason' => 'blocked',
                'message' => is_string($allowed) ? $allowed : $this->storeMessage($options, 'blocked_message', __('Deletion was blocked by the resource hooks.', 'smbb-wpcodetool')),
                'errors' => array(),
                'context' => $context,
                'store' => $store,
                'hooks' => $hooks,
            );
        }

        if (!$store->delete($id)) {
            return array(
                'success' => false,
                'reason' => 'storage',
                'message' => $store->lastError() ?: $this->storeMessage($options, 'delete_failed_message', __('The item could not be deleted.', 'smbb-wpcodetool')),
                'errors' => array(),
                'context' => $context,
                'store' => $store,
                'hooks' => $hooks,
            );
        }

        return array(
            'success' => true,
            'reason' => 'deleted',
            'id' => $id,
            'errors' => array(),
            'context' => $context,
            'store' => $store,
            'hooks' => $hooks,
        );
    }

    /**
     * Duplicates an existing table-backed row through the create pipeline.
     *
     * @param mixed $id
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    public function duplicateTable(ResourceDefinition $resource, $id, array $options = array())
    {
        $store = $this->tableStore($resource, $options);
        $hooks = $this->runtime->hooksFor($resource);
        $source = $store->find($id);

        if (!$source) {
            return array(
                'success' => false,
                'reason' => 'not_found',
                'message' => $this->storeMessage($options, 'not_found_message', __('The item to duplicate was not found.', 'smbb-wpcodetool')),
                'errors' => array(),
                'store' => $store,
                'hooks' => $hooks,
            );
        }

        unset($source[$resource->primaryKey()]);

        $context = $this->context(array(
            'action' => isset($options['action']) ? (string) $options['action'] : 'duplicate',
            'source_id' => $id,
            'resource' => $resource,
            'store' => $store,
        ), $options);

        $data = $this->runtime->callDataHook($hooks, 'beforeValidate', $source, $context);
        $errors = $this->validationErrors($options, $data, $context, $hooks, $store);

        if ($errors) {
            return array(
                'success' => false,
                'reason' => 'validation',
                'message' => $this->storeMessage($options, 'validation_message', __('The cloned item contains errors.', 'smbb-wpcodetool')),
                'data' => $data,
                'errors' => $errors,
                'context' => $context,
                'hooks' => $hooks,
                'store' => $store,
            );
        }

        $data = $this->runtime->applyManagedColumns($resource, $data, true);
        $data = $this->runtime->callDataHook($hooks, 'beforeSave', $data, $context);
        $new_id = $store->create($data);

        if ($new_id === false) {
            return array(
                'success' => false,
                'reason' => 'storage',
                'message' => $store->lastError() ?: $this->storeMessage($options, 'save_failed_message', __('The item could not be duplicated.', 'smbb-wpcodetool')),
                'data' => $data,
                'errors' => array(),
                'context' => $context,
                'hooks' => $hooks,
                'store' => $store,
            );
        }

        $row = $store->find($new_id);
        $payload = is_array($row) ? $row : $data;
        $context['id'] = $new_id;
        $this->runtime->callAfterSaveHook($hooks, $payload, $context);

        return array(
            'success' => true,
            'reason' => 'duplicated',
            'id' => $new_id,
            'data' => $data,
            'payload' => $payload,
            'errors' => array(),
            'context' => $context,
            'hooks' => $hooks,
            'store' => $store,
        );
    }

    /**
     * @param array<string,mixed> $options
     * @return OptionStore
     */
    private function optionStore(ResourceDefinition $resource, array $options)
    {
        if (isset($options['store']) && $options['store'] instanceof OptionStore) {
            return $options['store'];
        }

        return new OptionStore($resource->optionName(), $resource->optionDefaults(), $resource->optionAutoload());
    }

    /**
     * @param array<string,mixed> $options
     * @return TableStore
     */
    private function tableStore(ResourceDefinition $resource, array $options)
    {
        if (isset($options['store']) && $options['store'] instanceof TableStore) {
            return $options['store'];
        }

        return new TableStore($resource);
    }

    /**
     * @param array<string,mixed> $defaults
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    private function context(array $defaults, array $options)
    {
        $context = isset($options['context']) && is_array($options['context']) ? $options['context'] : array();

        return array_merge($defaults, $context);
    }

    /**
     * Builds the complete validation error list for a mutation.
     *
     * @param array<string,mixed> $options
     * @param array<string,mixed> $data
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    private function validationErrors(array $options, array $data, array $context, $hooks, $store)
    {
        $errors = isset($options['validation_errors']) && is_array($options['validation_errors']) ? $options['validation_errors'] : array();
        $callback = isset($options['validation_callback']) ? $options['validation_callback'] : null;

        if (is_callable($callback)) {
            $extra_errors = call_user_func($callback, $data, $context, $hooks, $store);

            if (is_array($extra_errors)) {
                $errors = array_merge($errors, array_filter($extra_errors));
            }
        }

        return array_merge($errors, $this->runtime->callValidateHook($hooks, $data, $context));
    }

    /**
     * Reads an optional custom message from mutation options.
     *
     * @param array<string,mixed> $options
     */
    private function storeMessage(array $options, $key, $default)
    {
        return isset($options[$key]) && is_string($options[$key]) && $options[$key] !== ''
            ? $options[$key]
            : $default;
    }
}
