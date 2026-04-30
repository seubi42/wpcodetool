<?php

namespace Smbb\WpCodeTool\Api;

use Smbb\WpCodeTool\Resource\ResourceDefinition;
use Smbb\WpCodeTool\Resource\ResourceRuntime;

defined('ABSPATH') || exit;

/**
 * Construit les definitions d'arguments REST WordPress pour les routes CodeTool.
 */
final class ApiArgsBuilder
{
    private $runtime;

    public function __construct(ResourceRuntime $runtime = null)
    {
        $this->runtime = $runtime ?: new ResourceRuntime();
    }

    /**
     * @return array<string,mixed>
     */
    public function tableListArgs(ResourceDefinition $resource)
    {
        $columns = array_keys($resource->columns());

        return array(
            'page' => array(
                'default' => 1,
                'sanitize_callback' => 'absint',
                'validate_callback' => function ($value) {
                    return is_numeric($value) && (int) $value >= 1;
                },
            ),
            'per_page' => array(
                'default' => 20,
                'sanitize_callback' => 'absint',
                'validate_callback' => function ($value) {
                    return is_numeric($value) && (int) $value >= 1 && (int) $value <= 200;
                },
            ),
            'limit' => array(
                'sanitize_callback' => 'absint',
                'validate_callback' => function ($value) {
                    return $value === null || $value === '' || (is_numeric($value) && (int) $value >= 1 && (int) $value <= 200);
                },
            ),
            'search' => array(
                'validate_callback' => function ($value) {
                    return is_scalar($value);
                },
            ),
            's' => array(
                'validate_callback' => function ($value) {
                    return is_scalar($value);
                },
            ),
            'orderby' => array(
                'validate_callback' => function ($value) use ($columns) {
                    return $value === null || $value === '' || in_array((string) $value, $columns, true);
                },
            ),
            'order' => array(
                'sanitize_callback' => function ($value) {
                    return strtolower((string) $value);
                },
                'validate_callback' => function ($value) {
                    return $value === null || $value === '' || in_array(strtolower((string) $value), array('asc', 'desc'), true);
                },
            ),
            'filter' => array(
                'validate_callback' => function ($value) use ($resource) {
                    if ($value === null || $value === '') {
                        return true;
                    }

                    if (!is_array($value)) {
                        return false;
                    }

                    $field = isset($value['field']) ? sanitize_key((string) $value['field']) : '';
                    $operator = isset($value['operator']) ? sanitize_key((string) $value['operator']) : '';

                    if ($field !== '' && !in_array($field, $resource->listFilterColumns(), true)) {
                        return false;
                    }

                    if ($operator !== '') {
                        $definitions = $resource->listFilterDefinitions();
                        $allowed = isset($definitions[$field]['operators']) && is_array($definitions[$field]['operators'])
                            ? $definitions[$field]['operators']
                            : array('contains', 'eq', 'neq', 'gt', 'gte', 'lt', 'lte', 'starts_with', 'ends_with', 'empty', 'not_empty');

                        if (!in_array($operator, $allowed, true)) {
                            return false;
                        }
                    }

                    return true;
                },
            ),
        );
    }

    /**
     * @return array<string,mixed>
     */
    public function resourceIdArg(ResourceDefinition $resource)
    {
        $primary_key = $resource->primaryKey();

        return array(
            $primary_key => array(
                'required' => true,
                'validate_callback' => function ($value) use ($resource) {
                    if ($this->runtime->primaryKeyIsNumeric($resource)) {
                        return is_numeric($value) && (int) $value >= 1;
                    }

                    return is_scalar($value) && trim((string) $value) !== '';
                },
            ),
        );
    }

    /**
     * @return array<string,mixed>
     */
    public function tableWriteArgs(ResourceDefinition $resource, $mode)
    {
        $args = in_array($mode, array('patch', 'put'), true) ? $this->resourceIdArg($resource) : array();
        $require_all = $mode === 'put' && $resource->apiActionConfig('put')['missingFields'] === 'reject';

        foreach ($resource->columns() as $column => $definition) {
            if (!is_array($definition)) {
                continue;
            }

            if (!empty($definition['managed']) || (!empty($definition['primary']) && !empty($definition['autoIncrement']))) {
                continue;
            }

            $required = false;

            if ($mode === 'create') {
                $required = empty($definition['nullable']) && !array_key_exists('default', $definition);
            } elseif ($require_all) {
                $required = true;
            }

            $args[$column] = $this->columnArgDefinition($resource, $column, $definition, $required);
        }

        return $args;
    }

    /**
     * @return array<string,mixed>
     */
    public function optionWriteArgs(ResourceDefinition $resource, $mode)
    {
        unset($resource, $mode);

        return array();
    }

    /**
     * @param array<string,mixed> $route
     * @return array<string,mixed>
     */
    public function customRouteArgs(ResourceDefinition $resource, array $route)
    {
        unset($resource);

        $args = $this->pathArgsFromRoutePath(isset($route['path']) ? (string) $route['path'] : '');
        $definitions = isset($route['args']) && is_array($route['args']) ? $route['args'] : array();

        foreach ($definitions as $name => $schema) {
            if (!is_array($schema)) {
                continue;
            }

            $args[(string) $name] = $this->customArgDefinition($schema);
        }

        return $args;
    }

    public function resourceIdPattern(ResourceDefinition $resource)
    {
        return $this->runtime->primaryKeyIsNumeric($resource) ? '\\d+' : '[^\/]+';
    }

    /**
     * @return array<string,mixed>
     */
    private function pathArgsFromRoutePath($path)
    {
        $args = array();
        $matches = array();

        if (preg_match_all('/\(\?P<([a-zA-Z0-9_]+)>([^)]+)\)/', (string) $path, $matches, PREG_SET_ORDER) < 1) {
            return $args;
        }

        foreach ($matches as $match) {
            $args[$match[1]] = array(
                'required' => true,
                'validate_callback' => function ($value) use ($match) {
                    if (strpos((string) $match[2], '\\d') !== false) {
                        return is_numeric($value) && (int) $value >= 0;
                    }

                    return is_scalar($value) && trim((string) $value) !== '';
                },
            );
        }

        return $args;
    }

    /**
     * @return array<string,mixed>
     */
    private function columnArgDefinition(ResourceDefinition $resource, $column, array $definition, $required)
    {
        return array(
            'required' => (bool) $required,
            'validate_callback' => function ($value) use ($resource, $column, $definition) {
                return $this->validateColumnValue($resource, $column, $definition, $value);
            },
        );
    }

    /**
     * @param array<string,mixed> $schema
     * @return array<string,mixed>
     */
    private function customArgDefinition(array $schema)
    {
        $arg = array(
            'required' => !empty($schema['required']),
            'sanitize_callback' => $this->customArgSanitizer($schema),
            'validate_callback' => function ($value) use ($schema) {
                return $this->validateCustomArgValue($value, $schema);
            },
        );

        if (array_key_exists('default', $schema)) {
            $arg['default'] = $schema['default'];
        }

        if ($arg['sanitize_callback'] === null) {
            unset($arg['sanitize_callback']);
        }

        return $arg;
    }

    /**
     * @param array<string,mixed> $schema
     * @return callable|string|null
     */
    private function customArgSanitizer(array $schema)
    {
        $sanitize = isset($schema['sanitize']) ? sanitize_key((string) $schema['sanitize']) : '';

        switch ($sanitize) {
            case 'text':
                return 'sanitize_text_field';

            case 'key':
                return 'sanitize_key';

            case 'email':
                return 'sanitize_email';

            case 'url':
                return 'esc_url_raw';

            default:
                return null;
        }
    }

    private function validateColumnValue(ResourceDefinition $resource, $column, array $definition, $value)
    {
        if ($value === null) {
            return true;
        }

        if ($resource->columnStoresJson($column)) {
            return is_array($value) || is_object($value) || is_string($value);
        }

        if (is_array($value) || is_object($value)) {
            return false;
        }

        $type = isset($definition['type']) ? strtolower((string) $definition['type']) : 'varchar';

        switch ($type) {
            case 'bigint':
            case 'int':
            case 'integer':
            case 'mediumint':
            case 'smallint':
            case 'tinyint':
                return is_numeric($value);

            case 'decimal':
            case 'float':
            case 'double':
                return is_numeric($value);

            case 'date':
                return preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $value) === 1;

            case 'time':
                return preg_match('/^\d{2}:\d{2}(:\d{2})?$/', (string) $value) === 1;

            case 'datetime':
            case 'timestamp':
                return preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}(:\d{2})?$/', (string) $value) === 1;

            default:
                return is_scalar($value);
        }
    }

    /**
     * @param array<string,mixed> $schema
     */
    private function validateCustomArgValue($value, array $schema)
    {
        if ($value === null) {
            return empty($schema['required']);
        }

        $type = isset($schema['type']) ? strtolower((string) $schema['type']) : 'string';

        if (!empty($schema['enum']) && is_array($schema['enum']) && !in_array($value, $schema['enum'], true)) {
            return false;
        }

        if (!empty($schema['pattern']) && @preg_match('/' . str_replace('/', '\/', (string) $schema['pattern']) . '/', '') !== false) {
            if (preg_match('/' . str_replace('/', '\/', (string) $schema['pattern']) . '/', (string) $value) !== 1) {
                return false;
            }
        }

        switch ($type) {
            case 'integer':
                if (!is_numeric($value) || ((string) (int) $value !== (string) $value && (int) $value != $value)) {
                    return false;
                }
                break;

            case 'number':
                if (!is_numeric($value)) {
                    return false;
                }
                break;

            case 'boolean':
                if (!is_bool($value) && !in_array($value, array(0, 1, '0', '1', 'true', 'false'), true)) {
                    return false;
                }
                break;

            case 'array':
                if (!is_array($value)) {
                    return false;
                }
                break;

            case 'object':
                if (!is_array($value) && !is_object($value)) {
                    return false;
                }
                break;

            case 'string':
            default:
                if (!is_scalar($value)) {
                    return false;
                }
                break;
        }

        if (isset($schema['minimum']) && is_numeric($value) && $value < $schema['minimum']) {
            return false;
        }

        if (isset($schema['maximum']) && is_numeric($value) && $value > $schema['maximum']) {
            return false;
        }

        return true;
    }
}
