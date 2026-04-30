<?php

namespace Smbb\WpCodeTool\Api;

use Smbb\WpCodeTool\Resource\ResourceDefinition;

defined('ABSPATH') || exit;

/**
 * Porte la semantique partagee de PATCH/PUT pour les ecritures REST.
 */
final class ApiWriteSemantics
{
    /**
     * @param array<string,mixed> $current
     * @param array<string,mixed> $incoming
     * @return array{0: array<string,mixed>, 1: array<string,mixed>}
     */
    public function prepareTableUpdateData(ResourceDefinition $resource, array $current, array $incoming, $mode)
    {
        $config = $resource->apiActionConfig($mode);
        $errors = array();
        $incoming = $this->normalizeNullFieldMode($incoming, $config, $errors);
        $writable_columns = $this->writableTableColumns($resource);
        $data = array();

        if ($config['missingFields'] === 'reject') {
            $data = $incoming;

            foreach ($writable_columns as $column) {
                if (!array_key_exists($column, $incoming)) {
                    $errors[$column] = __('This field must be sent for a full update.', 'smbb-wpcodetool');
                }
            }

            return array($data, $errors);
        }

        if ($config['missingFields'] === 'set_null') {
            foreach ($writable_columns as $column) {
                $data[$column] = array_key_exists($column, $incoming) ? $incoming[$column] : null;
            }

            return array($data, $errors);
        }

        foreach ($writable_columns as $column) {
            if (array_key_exists($column, $current)) {
                $data[$column] = $current[$column];
            }
        }

        foreach ($incoming as $column => $value) {
            $data[$column] = $value;
        }

        return array($data, $errors);
    }

    public function shouldMergeOptionCurrent(ResourceDefinition $resource, $mode)
    {
        if ($mode === 'patch') {
            return true;
        }

        if ($mode !== 'create' && $mode !== 'put') {
            return false;
        }

        $config = $resource->apiActionConfig($mode === 'create' ? 'put' : $mode);

        return isset($config['missingFields']) && $config['missingFields'] === 'keep';
    }

    /**
     * @param array<string,mixed> $incoming
     * @param array<string,mixed> $config
     * @param array<string,mixed> $errors
     * @return array<string,mixed>
     */
    private function normalizeNullFieldMode(array $incoming, array $config, array &$errors)
    {
        foreach ($incoming as $column => $value) {
            if ($value !== null) {
                continue;
            }

            if ($config['nullFields'] === 'ignore') {
                unset($incoming[$column]);
                continue;
            }

            if ($config['nullFields'] === 'reject') {
                $errors[$column] = __('Null is not allowed for this update mode.', 'smbb-wpcodetool');
                unset($incoming[$column]);
            }
        }

        return $incoming;
    }

    /**
     * @return array<int,string>
     */
    private function writableTableColumns(ResourceDefinition $resource)
    {
        $columns = array();

        foreach ($resource->columns() as $column => $definition) {
            if (!is_array($definition)) {
                continue;
            }

            if (!empty($definition['managed']) || (!empty($definition['primary']) && !empty($definition['autoIncrement']))) {
                continue;
            }

            $columns[] = $column;
        }

        return $columns;
    }
}
