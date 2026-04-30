<?php

namespace Smbb\WpCodeTool\Api;

use Smbb\WpCodeTool\Resource\ResourceDefinition;
use Smbb\WpCodeTool\Resource\ResourceRuntime;

defined('ABSPATH') || exit;

/**
 * Construit les schemas OpenAPI a partir des ressources CodeTool.
 */
final class OpenApiSchemaBuilder
{
    private $runtime;

    public function __construct(ResourceRuntime $runtime = null)
    {
        $this->runtime = $runtime ?: new ResourceRuntime();
    }

    /**
     * @param ResourceDefinition[] $resources
     * @return array<string,mixed>
     */
    public function schemas(array $resources, $component_prefix = '')
    {
        $schemas = array();

        foreach ($resources as $resource) {
            if (!$resource instanceof ResourceDefinition || !$resource->apiEnabled()) {
                continue;
            }

            if ($resource->storageType() === 'custom_table') {
                $schemas[$this->componentName($resource, 'Item', $component_prefix)] = $this->tableSchema($resource, true);
                $schemas[$this->componentName($resource, 'Write', $component_prefix)] = $this->tableSchema($resource, false);
            } elseif ($resource->storageType() === 'option') {
                $schemas[$this->componentName($resource, 'State', $component_prefix)] = $this->optionSchema($resource->optionDefaults());
            }
        }

        ksort($schemas);

        return $schemas;
    }

    public function schemaRef($name)
    {
        return array(
            '$ref' => '#/components/schemas/' . $name,
        );
    }

    public function componentName(ResourceDefinition $resource, $suffix, $prefix = '')
    {
        $base = ucwords(str_replace(array('-', '_'), ' ', $resource->name()));
        $base = str_replace(' ', '', $base);

        return (string) $prefix . $base . (string) $suffix;
    }

    public function namespaceComponentPrefix($namespace)
    {
        $namespace = trim(str_replace(array('/', '\\', '-'), '_', (string) $namespace), '_');
        $namespace = preg_replace('/[^a-zA-Z0-9_]/', '_', $namespace);
        $namespace = ucwords(str_replace('_', ' ', $namespace));

        return str_replace(' ', '', (string) $namespace);
    }

    /**
     * @return array<string,mixed>
     */
    public function primaryKeySchema(ResourceDefinition $resource)
    {
        return $this->runtime->primaryKeyIsNumeric($resource)
            ? array('type' => 'integer')
            : array('type' => 'string');
    }

    /**
     * @param array<string,mixed> $schema
     * @return array<string,mixed>
     */
    public function customArgSchema(array $schema)
    {
        $openapi = array(
            'type' => isset($schema['type']) ? strtolower((string) $schema['type']) : 'string',
        );

        foreach (array('default', 'enum', 'pattern', 'minimum', 'maximum', 'description') as $key) {
            if (array_key_exists($key, $schema)) {
                $openapi[$key] = $schema[$key];
            }
        }

        return $openapi;
    }

    /**
     * @return array<string,mixed>
     */
    private function tableSchema(ResourceDefinition $resource, $include_managed)
    {
        $properties = array();
        $required = array();

        foreach ($resource->columns() as $column => $definition) {
            if (!is_array($definition)) {
                continue;
            }

            if (!$include_managed && (!empty($definition['managed']) || (!empty($definition['primary']) && !empty($definition['autoIncrement'])))) {
                continue;
            }

            $properties[$column] = $this->columnSchema($resource, $column, $definition);

            if (empty($definition['nullable']) && !array_key_exists('default', $definition) && empty($definition['managed']) && (empty($definition['primary']) || empty($definition['autoIncrement']))) {
                $required[] = $column;
            }
        }

        $schema = array(
            'type' => 'object',
            'properties' => $properties,
        );

        if ($required && !$include_managed) {
            $schema['required'] = array_values(array_unique($required));
        }

        return $schema;
    }

    /**
     * @param array<string,mixed> $defaults
     * @return array<string,mixed>
     */
    private function optionSchema(array $defaults)
    {
        return $this->schemaFromValue($defaults);
    }

    /**
     * @param array<string,mixed> $definition
     * @return array<string,mixed>
     */
    private function columnSchema(ResourceDefinition $resource, $column, array $definition)
    {
        if ($resource->columnStoresJson($column)) {
            return array(
                'oneOf' => array(
                    array(
                        'type' => 'object',
                        'additionalProperties' => true,
                    ),
                    array(
                        'type' => 'array',
                        'items' => array(),
                    ),
                    array(
                        'type' => 'string',
                    ),
                ),
            );
        }

        $type = isset($definition['type']) ? strtolower((string) $definition['type']) : 'varchar';
        $schema = array();

        switch ($type) {
            case 'bigint':
            case 'int':
            case 'integer':
            case 'mediumint':
            case 'smallint':
            case 'tinyint':
                $schema['type'] = 'integer';
                break;

            case 'decimal':
            case 'float':
            case 'double':
                $schema['type'] = 'number';
                break;

            case 'date':
                $schema['type'] = 'string';
                $schema['format'] = 'date';
                break;

            case 'time':
                $schema['type'] = 'string';
                $schema['format'] = 'time';
                break;

            case 'datetime':
            case 'timestamp':
                $schema['type'] = 'string';
                $schema['format'] = 'date-time';
                break;

            default:
                $schema['type'] = 'string';
                break;
        }

        if (!empty($definition['nullable'])) {
            $schema['nullable'] = true;
        }

        if (array_key_exists('default', $definition)) {
            $schema['default'] = $definition['default'];
        }

        return $schema;
    }

    /**
     * @return array<string,mixed>
     */
    private function schemaFromValue($value)
    {
        if (is_bool($value)) {
            return array('type' => 'boolean');
        }

        if (is_int($value)) {
            return array('type' => 'integer');
        }

        if (is_float($value)) {
            return array('type' => 'number');
        }

        if (is_string($value)) {
            return array('type' => 'string');
        }

        if (!is_array($value)) {
            return array(
                'type' => 'string',
                'nullable' => true,
            );
        }

        if ($this->isAssoc($value)) {
            $properties = array();

            foreach ($value as $key => $item) {
                $properties[(string) $key] = $this->schemaFromValue($item);
            }

            return array(
                'type' => 'object',
                'properties' => $properties,
            );
        }

        $item_schema = array();

        if ($value) {
            $item_schema = $this->schemaFromValue(reset($value));
        }

        return array(
            'type' => 'array',
            'items' => $item_schema,
        );
    }

    private function isAssoc(array $value)
    {
        return array_keys($value) !== range(0, count($value) - 1);
    }
}
