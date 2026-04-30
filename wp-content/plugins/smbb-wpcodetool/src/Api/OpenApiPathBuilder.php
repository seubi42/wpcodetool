<?php

namespace Smbb\WpCodeTool\Api;

use Smbb\WpCodeTool\Resource\ResourceDefinition;

defined('ABSPATH') || exit;

/**
 * Construit les paths OpenAPI a partir des ressources CodeTool.
 */
final class OpenApiPathBuilder
{
    public const SECURITY_SCHEME = 'BearerAuth';
    public const AUTH_NAMESPACE = 'smbb-wpcodetool/v1';

    private const HTTP_GET = 'get';
    private const HTTP_POST = 'post';
    private const HTTP_PATCH = 'patch';
    private const HTTP_PUT = 'put';
    private const HTTP_DELETE = 'delete';
    private const BODYLESS_METHODS = array(self::HTTP_GET, self::HTTP_DELETE);

    private $schemas;

    public function __construct(OpenApiSchemaBuilder $schemas = null)
    {
        $this->schemas = $schemas ?: new OpenApiSchemaBuilder();
    }

    /**
     * @param ResourceDefinition[] $resources
     * @return array<string,mixed>
     */
    public function paths(array $resources, $path_prefix = '', $component_prefix = '')
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

            $this->appendCustomPaths($paths, $resource, $path_prefix);
        }

        ksort($paths);

        return $paths;
    }

    /**
     * @param array<string,mixed> $paths
     */
    public function appendAuthPaths(array &$paths, $aggregate)
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
                                'client_id' => array('type' => 'string'),
                                'client_secret' => array('type' => 'string'),
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
            $this->appendOperation($paths, '/' . self::AUTH_NAMESPACE . '/token', self::HTTP_POST, $operation);
            return;
        }

        $operation['servers'] = array(
            array(
                'url' => untrailingslashit(rest_url(self::AUTH_NAMESPACE)),
            ),
        );

        $this->appendOperation($paths, '/token', self::HTTP_POST, $operation);
    }

    /**
     * @param array<string,mixed> $paths
     */
    private function appendTablePaths(array &$paths, ResourceDefinition $resource, $path_prefix = '', $component_prefix = '')
    {
        $collection_path = $this->joinPath($path_prefix, '/' . $resource->apiBase());
        $item_path = $collection_path . '/{' . $resource->primaryKey() . '}';
        $item_schema = $this->schemas->schemaRef($this->schemas->componentName($resource, 'Item', $component_prefix));
        $write_schema = $this->schemas->schemaRef($this->schemas->componentName($resource, 'Write', $component_prefix));
        $primary_key_schema = $this->schemas->primaryKeySchema($resource);

        if ($resource->apiActionEnabled('list')) {
            $this->appendOperation($paths, $collection_path, self::HTTP_GET, array(
                'summary' => sprintf('List %s', $resource->pluralLabel()),
                'tags' => array($resource->label()),
                'parameters' => array(
                    array('name' => 'page', 'in' => 'query', 'schema' => array('type' => 'integer', 'minimum' => 1, 'default' => 1)),
                    array('name' => 'per_page', 'in' => 'query', 'schema' => array('type' => 'integer', 'minimum' => 1, 'maximum' => 200, 'default' => 20)),
                    array('name' => 'search', 'in' => 'query', 'schema' => array('type' => 'string')),
                    array('name' => 'orderby', 'in' => 'query', 'schema' => array('type' => 'string')),
                    array('name' => 'order', 'in' => 'query', 'schema' => array('type' => 'string', 'enum' => array('asc', 'desc'))),
                ),
                'responses' => array(
                    '200' => array(
                        'description' => 'Collection response',
                        'content' => array(
                            'application/json' => array(
                                'schema' => array(
                                    'type' => 'object',
                                    'properties' => array(
                                        'items' => array('type' => 'array', 'items' => $item_schema),
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
            $this->appendWriteOperation($paths, $collection_path, self::HTTP_POST, sprintf('Create %s', $resource->label()), $resource->label(), $write_schema, $item_schema, 'Created item', '201');
        }

        if ($resource->apiActionEnabled('get')) {
            $this->appendOperation($paths, $item_path, self::HTTP_GET, array(
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
            $this->appendItemWriteOperation($paths, $item_path, self::HTTP_PATCH, sprintf('Patch %s', $resource->label()), $resource, $write_schema, $item_schema, 'Updated item');
        }

        if ($resource->apiActionEnabled('put')) {
            $this->appendItemWriteOperation($paths, $item_path, self::HTTP_PUT, sprintf('Replace %s', $resource->label()), $resource, $write_schema, $item_schema, 'Replaced item');
        }

        if ($resource->apiActionEnabled('delete')) {
            $this->appendOperation($paths, $item_path, self::HTTP_DELETE, array(
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
     * @param array<string,mixed> $paths
     */
    private function appendOptionPaths(array &$paths, ResourceDefinition $resource, $path_prefix = '', $component_prefix = '')
    {
        $path = $this->joinPath($path_prefix, '/' . $resource->apiBase());
        $schema_ref = $this->schemas->schemaRef($this->schemas->componentName($resource, 'State', $component_prefix));

        if ($resource->apiActionEnabled('get')) {
            $this->appendOperation($paths, $path, self::HTTP_GET, array(
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
            $this->appendWriteOperation($paths, $path, self::HTTP_POST, sprintf('Save %s', $resource->label()), $resource->label(), $schema_ref, $schema_ref, 'Saved settings');
        }

        if ($resource->apiActionEnabled('patch')) {
            $this->appendWriteOperation($paths, $path, self::HTTP_PATCH, sprintf('Patch %s', $resource->label()), $resource->label(), $schema_ref, $schema_ref, 'Updated settings');
        }

        if ($resource->apiActionEnabled('put')) {
            $this->appendWriteOperation($paths, $path, self::HTTP_PUT, sprintf('Replace %s', $resource->label()), $resource->label(), $schema_ref, $schema_ref, 'Replaced settings');
        }

        if ($resource->apiActionEnabled('delete')) {
            $this->appendOperation($paths, $path, self::HTTP_DELETE, array(
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
     * @param array<string,mixed> $paths
     */
    private function appendCustomPaths(array &$paths, ResourceDefinition $resource, $path_prefix = '')
    {
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
     * @param array<string,mixed> $paths
     */
    private function appendWriteOperation(array &$paths, $path, $method, $summary, $tag, array $request_schema, array $response_schema, $response_description, $status_code = '200')
    {
        $this->appendOperation($paths, $path, $method, array(
            'summary' => $summary,
            'tags' => array($tag),
            'requestBody' => array(
                'required' => true,
                'content' => array(
                    'application/json' => array(
                        'schema' => $request_schema,
                    ),
                ),
            ),
            'responses' => array(
                $status_code => array(
                    'description' => $response_description,
                    'content' => array(
                        'application/json' => array(
                            'schema' => $response_schema,
                        ),
                    ),
                ),
            ),
        ));
    }

    /**
     * @param array<string,mixed> $paths
     */
    private function appendItemWriteOperation(array &$paths, $path, $method, $summary, ResourceDefinition $resource, array $request_schema, array $response_schema, $response_description)
    {
        $this->appendOperation($paths, $path, $method, array(
            'summary' => $summary,
            'tags' => array($resource->label()),
            'parameters' => array(
                array(
                    'name' => $resource->primaryKey(),
                    'in' => 'path',
                    'required' => true,
                    'schema' => $this->schemas->primaryKeySchema($resource),
                ),
            ),
            'requestBody' => array(
                'required' => true,
                'content' => array(
                    'application/json' => array(
                        'schema' => $request_schema,
                    ),
                ),
            ),
            'responses' => array(
                '200' => array(
                    'description' => $response_description,
                    'content' => array(
                        'application/json' => array(
                            'schema' => $response_schema,
                        ),
                    ),
                ),
            ),
        ));
    }

    /**
     * @param array<string,mixed> $paths
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
                        self::SECURITY_SCHEME => array(),
                    ),
                ),
            ),
            $operation
        );
    }

    private function normalizeCustomPath($path)
    {
        $path = preg_replace('/\(\?P<([a-zA-Z0-9_]+)>[^)]+\)/', '{$1}', (string) $path);

        return '/' . ltrim((string) $path, '/');
    }

    /**
     * @return array<int,array<string,mixed>>
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
     * @param array<string,mixed> $route
     * @return array<int,array<string,mixed>>
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

            if ($location === '' && !in_array($method, self::BODYLESS_METHODS, true)) {
                continue;
            }

            $parameters[] = array(
                'name' => (string) $name,
                'in' => $location !== '' ? $location : 'query',
                'required' => !empty($schema['required']),
                'schema' => $this->schemas->customArgSchema($schema),
            );
        }

        return $parameters;
    }

    /**
     * @param array<string,mixed> $route
     * @return array<string,mixed>|null
     */
    private function customRequestBody(array $route, $method)
    {
        if (in_array($method, self::BODYLESS_METHODS, true)) {
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

            $properties[(string) $name] = $this->schemas->customArgSchema($schema);

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

    private function customSummary(ResourceDefinition $resource, $name)
    {
        return sprintf(
            '%s: %s',
            $resource->label(),
            ucwords(str_replace(array('-', '_'), ' ', (string) $name))
        );
    }

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
}
