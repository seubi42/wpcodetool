<?php

namespace Smbb\WpCodeTool\Api;

use Smbb\WpCodeTool\Resource\ResourceDefinition;

defined('ABSPATH') || exit;

/**
 * Builds a small OpenAPI document from CodeTool resource definitions.
 */
final class OpenApiBuilder
{
    /**
     * Build an OpenAPI 3 document for one WordPress REST namespace.
     *
     * @param string               $namespace REST namespace, for example smbb-sample/v1.
     * @param ResourceDefinition[] $resources Resources exposed in that namespace.
     * @return array
     */
    public function build($namespace, array $resources)
    {
        $paths = $this->paths($resources);
        $this->appendAuthPaths($paths, false);
        ksort($paths);

        return array(
            'openapi' => '3.0.3',
            'info' => array(
                'title' => sprintf('%s API', get_bloginfo('name')),
                'version' => defined('SMBB_WPCODETOOL_VERSION') ? SMBB_WPCODETOOL_VERSION : '1.0.0',
                'description' => sprintf(
                    'Auto-generated CodeTool specification for the "%s" namespace.',
                    (string) $namespace
                ),
            ),
            'servers' => array(
                array(
                    'url' => untrailingslashit(rest_url($namespace)),
                ),
            ),
            'security' => array(
                array(
                    'BearerAuth' => array(),
                ),
            ),
            'paths' => $paths,
            'components' => array(
                'securitySchemes' => array(
                    'BearerAuth' => array(
                        'type' => 'http',
                        'scheme' => 'bearer',
                        'bearerFormat' => 'Token',
                        'description' => $this->bearerSecurityDescription(),
                    ),
                ),
                'schemas' => $this->schemas($resources),
            ),
        );
    }

    /**
     * Build an aggregate OpenAPI document for several namespaces.
     *
     * @param array<string, ResourceDefinition[]> $resources_by_namespace
     * @return array
     */
    public function buildAggregate(array $resources_by_namespace)
    {
        $paths = array();
        $schemas = array();
        $namespaces = array();

        foreach ($resources_by_namespace as $namespace => $resources) {
            $path_prefix = '/' . trim((string) $namespace, '/');
            $component_prefix = $this->namespaceComponentPrefix($namespace);

            $paths = array_merge($paths, $this->paths($resources, $path_prefix, $component_prefix));
            $schemas = array_merge($schemas, $this->schemas($resources, $component_prefix));
            $namespaces[] = (string) $namespace;
        }

        $this->appendAuthPaths($paths, true);
        ksort($paths);
        ksort($schemas);

        return array(
            'openapi' => '3.0.3',
            'info' => array(
                'title' => sprintf('%s APIs', get_bloginfo('name')),
                'version' => defined('SMBB_WPCODETOOL_VERSION') ? SMBB_WPCODETOOL_VERSION : '1.0.0',
                'description' => sprintf(
                    'Auto-generated CodeTool specification for %d namespaces.',
                    count($namespaces)
                ),
            ),
            'servers' => array(
                array(
                    'url' => untrailingslashit(rest_url()),
                ),
            ),
            'security' => array(
                array(
                    'BearerAuth' => array(),
                ),
            ),
            'paths' => $paths,
            'components' => array(
                'securitySchemes' => array(
                    'BearerAuth' => array(
                        'type' => 'http',
                        'scheme' => 'bearer',
                        'bearerFormat' => 'Token',
                        'description' => $this->bearerSecurityDescription(),
                    ),
                ),
                'schemas' => $schemas,
            ),
            'x-codetool-namespaces' => array_values(array_unique($namespaces)),
        );
    }

    /**
     * Build the path map.
     */
    private function paths(array $resources, $path_prefix = '', $component_prefix = '')
    {
        $paths = array();

        foreach ($resources as $resource) {
            if (!$resource instanceof ResourceDefinition || !$resource->apiEnabled()) {
                continue;
            }

            if ($resource->storageType() === 'custom_table') {
                $this->appendTablePaths($paths, $resource, $path_prefix, $component_prefix);
            } elseif ($resource->storageType() === 'option') {
                $this->appendOptionPaths($paths, $resource, $path_prefix, $component_prefix);
            }

            $this->appendCustomPaths($paths, $resource, $path_prefix, $component_prefix);
        }

        ksort($paths);

        return $paths;
    }

    /**
     * Paths for collection resources backed by custom tables.
     */
    private function appendTablePaths(array &$paths, ResourceDefinition $resource, $path_prefix = '', $component_prefix = '')
    {
        $collection_path = $this->joinPath($path_prefix, '/' . $resource->apiBase());
        $item_path = $collection_path . '/{' . $resource->primaryKey() . '}';
        $item_schema = $this->schemaRef($this->componentName($resource, 'Item', $component_prefix));
        $write_schema = $this->schemaRef($this->componentName($resource, 'Write', $component_prefix));
        $primary_key_schema = $this->isNumericPrimaryKey($resource)
            ? array('type' => 'integer')
            : array('type' => 'string');

        if ($resource->apiActionEnabled('list')) {
            $this->appendOperation($paths, $collection_path, 'get', array(
                'summary' => sprintf('List %s', $resource->pluralLabel()),
                'tags' => array($resource->label()),
                'parameters' => array(
                    array(
                        'name' => 'page',
                        'in' => 'query',
                        'schema' => array('type' => 'integer', 'minimum' => 1, 'default' => 1),
                    ),
                    array(
                        'name' => 'per_page',
                        'in' => 'query',
                        'schema' => array('type' => 'integer', 'minimum' => 1, 'maximum' => 200, 'default' => 20),
                    ),
                    array(
                        'name' => 'search',
                        'in' => 'query',
                        'schema' => array('type' => 'string'),
                    ),
                    array(
                        'name' => 'orderby',
                        'in' => 'query',
                        'schema' => array('type' => 'string'),
                    ),
                    array(
                        'name' => 'order',
                        'in' => 'query',
                        'schema' => array('type' => 'string', 'enum' => array('asc', 'desc')),
                    ),
                ),
                'responses' => array(
                    '200' => array(
                        'description' => 'Collection response',
                        'content' => array(
                            'application/json' => array(
                                'schema' => array(
                                    'type' => 'object',
                                    'properties' => array(
                                        'items' => array(
                                            'type' => 'array',
                                            'items' => $item_schema,
                                        ),
                                        'meta' => array(
                                            'type' => 'object',
                                            'properties' => array(
                                                'page' => array('type' => 'integer'),
                                                'per_page' => array('type' => 'integer'),
                                                'total' => array('type' => 'integer'),
                                                'total_pages' => array('type' => 'integer'),
                                            ),
                                        ),
                                    ),
                                ),
                            ),
                        ),
                    ),
                ),
            ));
        }

        if ($resource->apiActionEnabled('create')) {
            $this->appendOperation($paths, $collection_path, 'post', array(
                'summary' => sprintf('Create %s', $resource->label()),
                'tags' => array($resource->label()),
                'requestBody' => array(
                    'required' => true,
                    'content' => array(
                        'application/json' => array(
                            'schema' => $write_schema,
                        ),
                    ),
                ),
                'responses' => array(
                    '201' => array(
                        'description' => 'Created item',
                        'content' => array(
                            'application/json' => array(
                                'schema' => $item_schema,
                            ),
                        ),
                    ),
                ),
            ));
        }

        if ($resource->apiActionEnabled('get')) {
            $this->appendOperation($paths, $item_path, 'get', array(
                'summary' => sprintf('Get %s', $resource->label()),
                'tags' => array($resource->label()),
                'parameters' => array(
                    array(
                        'name' => $resource->primaryKey(),
                        'in' => 'path',
                        'required' => true,
                        'schema' => $primary_key_schema,
                    ),
                ),
                'responses' => array(
                    '200' => array(
                        'description' => 'Requested item',
                        'content' => array(
                            'application/json' => array(
                                'schema' => $item_schema,
                            ),
                        ),
                    ),
                ),
            ));
        }

        if ($resource->apiActionEnabled('patch')) {
            $this->appendOperation($paths, $item_path, 'patch', array(
                'summary' => sprintf('Patch %s', $resource->label()),
                'tags' => array($resource->label()),
                'parameters' => array(
                    array(
                        'name' => $resource->primaryKey(),
                        'in' => 'path',
                        'required' => true,
                        'schema' => $primary_key_schema,
                    ),
                ),
                'requestBody' => array(
                    'required' => true,
                    'content' => array(
                        'application/json' => array(
                            'schema' => $write_schema,
                        ),
                    ),
                ),
                'responses' => array(
                    '200' => array(
                        'description' => 'Updated item',
                        'content' => array(
                            'application/json' => array(
                                'schema' => $item_schema,
                            ),
                        ),
                    ),
                ),
            ));
        }

        if ($resource->apiActionEnabled('put')) {
            $this->appendOperation($paths, $item_path, 'put', array(
                'summary' => sprintf('Replace %s', $resource->label()),
                'tags' => array($resource->label()),
                'parameters' => array(
                    array(
                        'name' => $resource->primaryKey(),
                        'in' => 'path',
                        'required' => true,
                        'schema' => $primary_key_schema,
                    ),
                ),
                'requestBody' => array(
                    'required' => true,
                    'content' => array(
                        'application/json' => array(
                            'schema' => $write_schema,
                        ),
                    ),
                ),
                'responses' => array(
                    '200' => array(
                        'description' => 'Replaced item',
                        'content' => array(
                            'application/json' => array(
                                'schema' => $item_schema,
                            ),
                        ),
                    ),
                ),
            ));
        }

        if ($resource->apiActionEnabled('delete')) {
            $this->appendOperation($paths, $item_path, 'delete', array(
                'summary' => sprintf('Delete %s', $resource->label()),
                'tags' => array($resource->label()),
                'parameters' => array(
                    array(
                        'name' => $resource->primaryKey(),
                        'in' => 'path',
                        'required' => true,
                        'schema' => $primary_key_schema,
                    ),
                ),
                'responses' => array(
                    '200' => array(
                        'description' => 'Deletion result',
                        'content' => array(
                            'application/json' => array(
                                'schema' => array(
                                    'type' => 'object',
                                    'properties' => array(
                                        'deleted' => array('type' => 'boolean'),
                                        'id' => $primary_key_schema,
                                    ),
                                ),
                            ),
                        ),
                    ),
                ),
            ));
        }
    }

    /**
     * Paths for singleton option resources.
     */
    private function appendOptionPaths(array &$paths, ResourceDefinition $resource, $path_prefix = '', $component_prefix = '')
    {
        $path = $this->joinPath($path_prefix, '/' . $resource->apiBase());
        $schema_ref = $this->schemaRef($this->componentName($resource, 'State', $component_prefix));

        if ($resource->apiActionEnabled('get')) {
            $this->appendOperation($paths, $path, 'get', array(
                'summary' => sprintf('Get %s', $resource->label()),
                'tags' => array($resource->label()),
                'responses' => array(
                    '200' => array(
                        'description' => 'Current settings',
                        'content' => array(
                            'application/json' => array(
                                'schema' => $schema_ref,
                            ),
                        ),
                    ),
                ),
            ));
        }

        if ($resource->apiActionEnabled('create')) {
            $this->appendOperation($paths, $path, 'post', array(
                'summary' => sprintf('Save %s', $resource->label()),
                'tags' => array($resource->label()),
                'requestBody' => array(
                    'required' => true,
                    'content' => array(
                        'application/json' => array(
                            'schema' => $schema_ref,
                        ),
                    ),
                ),
                'responses' => array(
                    '200' => array(
                        'description' => 'Saved settings',
                        'content' => array(
                            'application/json' => array(
                                'schema' => $schema_ref,
                            ),
                        ),
                    ),
                ),
            ));
        }

        if ($resource->apiActionEnabled('patch')) {
            $this->appendOperation($paths, $path, 'patch', array(
                'summary' => sprintf('Patch %s', $resource->label()),
                'tags' => array($resource->label()),
                'requestBody' => array(
                    'required' => true,
                    'content' => array(
                        'application/json' => array(
                            'schema' => $schema_ref,
                        ),
                    ),
                ),
                'responses' => array(
                    '200' => array(
                        'description' => 'Updated settings',
                        'content' => array(
                            'application/json' => array(
                                'schema' => $schema_ref,
                            ),
                        ),
                    ),
                ),
            ));
        }

        if ($resource->apiActionEnabled('put')) {
            $this->appendOperation($paths, $path, 'put', array(
                'summary' => sprintf('Replace %s', $resource->label()),
                'tags' => array($resource->label()),
                'requestBody' => array(
                    'required' => true,
                    'content' => array(
                        'application/json' => array(
                            'schema' => $schema_ref,
                        ),
                    ),
                ),
                'responses' => array(
                    '200' => array(
                        'description' => 'Replaced settings',
                        'content' => array(
                            'application/json' => array(
                                'schema' => $schema_ref,
                            ),
                        ),
                    ),
                ),
            ));
        }

        if ($resource->apiActionEnabled('delete')) {
            $this->appendOperation($paths, $path, 'delete', array(
                'summary' => sprintf('Delete %s', $resource->label()),
                'tags' => array($resource->label()),
                'responses' => array(
                    '200' => array(
                        'description' => 'Deletion result',
                        'content' => array(
                            'application/json' => array(
                                'schema' => array(
                                    'type' => 'object',
                                    'properties' => array(
                                        'deleted' => array('type' => 'boolean'),
                                    ),
                                ),
                            ),
                        ),
                    ),
                ),
            ));
        }
    }

    /**
     * Generic documentation for custom routes.
     */
    private function appendCustomPaths(array &$paths, ResourceDefinition $resource, $path_prefix = '', $component_prefix = '')
    {
        unset($component_prefix);

        foreach ($resource->apiCustomRoutes() as $name => $route) {
            if (empty($route['enabled']) || empty($route['path'])) {
                continue;
            }

            $path = $this->joinPath($path_prefix, $this->normalizeCustomPath($route['path']));
            $method = strtolower((string) $route['method']);
            $parameters = $this->customPathParameters($route['path']);
            $parameters = array_merge($parameters, $this->customDeclaredParameters($route, $method));
            $request_body = $this->customRequestBody($route, $method);

            $operation = array(
                'summary' => $route['summary'] !== '' ? $route['summary'] : $this->customSummary($resource, $name),
                'description' => $route['description'],
                'tags' => array($resource->label()),
                'parameters' => $parameters,
                'responses' => array(
                    '200' => array(
                        'description' => 'Custom route response',
                        'content' => array(
                            'application/json' => array(
                                'schema' => array(
                                    'type' => 'object',
                                    'additionalProperties' => true,
                                ),
                            ),
                        ),
                    ),
                ),
            );

            if ($request_body) {
                $operation['requestBody'] = $request_body;
            }

            $this->appendOperation($paths, $path, $method, $operation);
        }
    }

    /**
     * Core auth documentation exposed by CodeTool itself.
     */
    private function appendAuthPaths(array &$paths, $aggregate)
    {
        $operation = array(
            'summary' => 'Request an access token',
            'description' => 'Exchange a client_id and client_secret for a bearer access token.',
            'tags' => array('Authentication'),
            'security' => array(),
            'requestBody' => array(
                'required' => true,
                'content' => array(
                    'application/json' => array(
                        'schema' => array(
                            'type' => 'object',
                            'required' => array('client_id', 'client_secret'),
                            'properties' => array(
                                'client_id' => array(
                                    'type' => 'string',
                                ),
                                'client_secret' => array(
                                    'type' => 'string',
                                ),
                                'expires_in' => array(
                                    'type' => 'integer',
                                    'minimum' => 1,
                                    'description' => 'Optional requested lifetime in seconds. The final token lifetime is capped by the client default TTL.',
                                ),
                            ),
                        ),
                    ),
                ),
            ),
            'responses' => array(
                '200' => array(
                    'description' => 'Issued bearer access token',
                    'content' => array(
                        'application/json' => array(
                            'schema' => array(
                                'type' => 'object',
                                'properties' => array(
                                    'access_token' => array('type' => 'string'),
                                    'token_type' => array('type' => 'string', 'default' => 'bearer'),
                                    'expires_in' => array('type' => 'integer'),
                                ),
                                'required' => array('access_token', 'token_type', 'expires_in'),
                            ),
                        ),
                    ),
                ),
                '401' => array(
                    'description' => 'Invalid client credentials',
                ),
            ),
        );

        if ($aggregate) {
            $this->appendOperation($paths, '/smbb-wpcodetool/v1/token', 'post', $operation);
            return;
        }

        $operation['servers'] = array(
            array(
                'url' => untrailingslashit(rest_url('smbb-wpcodetool/v1')),
            ),
        );

        $this->appendOperation($paths, '/token', 'post', $operation);
    }

    /**
     * Components/schemas map.
     */
    private function schemas(array $resources, $component_prefix = '')
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

    /**
     * Schema for a table row or a write payload.
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
     * Schema for a settings object stored in wp_options.
     */
    private function optionSchema(array $defaults)
    {
        return $this->schemaFromValue($defaults);
    }

    /**
     * Schema for one column.
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
     * Schema inference for option defaults.
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

    /**
     * Ensure the operation is attached to its path and method.
     */
    private function appendOperation(array &$paths, $path, $method, array $operation)
    {
        if (!isset($paths[$path])) {
            $paths[$path] = array();
        }

        $paths[$path][strtolower((string) $method)] = array_merge(
            array(
                'security' => array(
                    array(
                        'BearerAuth' => array(),
                    ),
                ),
            ),
            $operation
        );
    }

    /**
     * Shared description for the Bearer security scheme.
     */
    private function bearerSecurityDescription()
    {
        return 'Use a bearer access token obtained from POST /smbb-wpcodetool/v1/token.';
    }

    /**
     * Convert the regex route path into an OpenAPI path.
     */
    private function normalizeCustomPath($path)
    {
        $path = preg_replace('/\(\?P<([a-zA-Z0-9_]+)>[^)]+\)/', '{$1}', (string) $path);

        return '/' . ltrim((string) $path, '/');
    }

    /**
     * Parameters extracted from a WordPress regex path.
     */
    private function customPathParameters($path)
    {
        $parameters = array();
        $match_count = preg_match_all('/\(\?P<([a-zA-Z0-9_]+)>([^)]+)\)/', (string) $path, $matches, PREG_SET_ORDER);

        if ($match_count === false || $match_count === 0) {
            return $parameters;
        }

        foreach ($matches as $match) {
            $parameters[] = array(
                'name' => $match[1],
                'in' => 'path',
                'required' => true,
                'schema' => strpos($match[2], '\\d') !== false
                    ? array('type' => 'integer')
                    : array('type' => 'string'),
            );
        }

        return $parameters;
    }

    /**
     * Declared custom route args exposed as OpenAPI parameters.
     */
    private function customDeclaredParameters(array $route, $method)
    {
        $parameters = array();
        $args = isset($route['args']) && is_array($route['args']) ? $route['args'] : array();

        foreach ($args as $name => $schema) {
            if (!is_array($schema)) {
                continue;
            }

            $location = isset($schema['in']) ? strtolower((string) $schema['in']) : '';

            if ($location === 'body' || $location === 'requestbody') {
                continue;
            }

            if ($location === '' && !in_array($method, array('get', 'delete'), true)) {
                continue;
            }

            $parameters[] = array(
                'name' => (string) $name,
                'in' => $location !== '' ? $location : 'query',
                'required' => !empty($schema['required']),
                'schema' => $this->openApiSchemaFromCustomArg($schema),
            );
        }

        return $parameters;
    }

    /**
     * Declared custom route args exposed as requestBody.
     */
    private function customRequestBody(array $route, $method)
    {
        if (in_array($method, array('get', 'delete'), true)) {
            return null;
        }

        $args = isset($route['args']) && is_array($route['args']) ? $route['args'] : array();
        $properties = array();
        $required = array();

        foreach ($args as $name => $schema) {
            if (!is_array($schema)) {
                continue;
            }

            $location = isset($schema['in']) ? strtolower((string) $schema['in']) : '';

            if ($location === 'query' || $location === 'path') {
                continue;
            }

            $properties[(string) $name] = $this->openApiSchemaFromCustomArg($schema);

            if (!empty($schema['required'])) {
                $required[] = (string) $name;
            }
        }

        if (!$properties) {
            return null;
        }

        $schema = array(
            'type' => 'object',
            'properties' => $properties,
        );

        if ($required) {
            $schema['required'] = array_values(array_unique($required));
        }

        return array(
            'required' => !empty($required),
            'content' => array(
                'application/json' => array(
                    'schema' => $schema,
                ),
            ),
        );
    }

    /**
     * Convert custom JSON arg metadata to OpenAPI schema.
     */
    private function openApiSchemaFromCustomArg(array $schema)
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
     * Stable component name for a resource.
     */
    private function componentName(ResourceDefinition $resource, $suffix, $prefix = '')
    {
        $base = ucwords(str_replace(array('-', '_'), ' ', $resource->name()));
        $base = str_replace(' ', '', $base);

        return (string) $prefix . $base . (string) $suffix;
    }

    /**
     * Reference helper.
     */
    private function schemaRef($name)
    {
        return array(
            '$ref' => '#/components/schemas/' . $name,
        );
    }

    /**
     * Default summary for a custom route.
     */
    private function customSummary(ResourceDefinition $resource, $name)
    {
        return sprintf(
            '%s: %s',
            $resource->label(),
            ucwords(str_replace(array('-', '_'), ' ', (string) $name))
        );
    }

    /**
     * Detect associative arrays.
     */
    private function isAssoc(array $value)
    {
        return array_keys($value) !== range(0, count($value) - 1);
    }

    /**
     * Prefix path helper for aggregate docs.
     */
    private function joinPath($prefix, $path)
    {
        $prefix = trim((string) $prefix, '/');
        $path = trim((string) $path, '/');

        if ($prefix === '') {
            return '/' . $path;
        }

        if ($path === '') {
            return '/' . $prefix;
        }

        return '/' . $prefix . '/' . $path;
    }

    /**
     * Stable schema prefix for a namespace in aggregate mode.
     */
    private function namespaceComponentPrefix($namespace)
    {
        $namespace = trim(str_replace(array('/', '\\', '-'), '_', (string) $namespace), '_');
        $namespace = preg_replace('/[^a-zA-Z0-9_]/', '_', $namespace);
        $namespace = ucwords(str_replace('_', ' ', $namespace));

        return str_replace(' ', '', (string) $namespace);
    }

    /**
     * Detect the type of the primary key.
     */
    private function isNumericPrimaryKey(ResourceDefinition $resource)
    {
        $primary_key = $resource->primaryKey();
        $columns = $resource->columns();
        $definition = isset($columns[$primary_key]) && is_array($columns[$primary_key]) ? $columns[$primary_key] : array();
        $type = isset($definition['type']) ? strtolower((string) $definition['type']) : '';

        return in_array($type, array('bigint', 'int', 'integer', 'mediumint', 'smallint', 'tinyint'), true);
    }
}
