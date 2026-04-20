<?php

namespace Smbb\WpCodeTool\Api;

use Smbb\WpCodeTool\Resource\ResourceDefinition;
use Smbb\WpCodeTool\Resource\ResourceMutationService;
use Smbb\WpCodeTool\Resource\ResourceRuntime;
use Smbb\WpCodeTool\Resource\ResourceScanner;
use Smbb\WpCodeTool\Store\OptionStore;
use Smbb\WpCodeTool\Store\TableStore;

defined('ABSPATH') || exit;

/**
 * Registers CodeTool resources as WordPress REST endpoints.
 */
final class ApiManager
{
    private $api;
    private $openapi;
    private $scanner;
    private $api_clients;
    private $access_tokens;
    private $tokens;
    private $visibility;
    private $runtime;
    private $mutations;
    private $resources = array();
    private $errors = array();
    private $custom_api_cache = array();

    public function __construct(ResourceScanner $scanner)
    {
        $this->api = new ApiHelper();
        $this->openapi = new OpenApiBuilder();
        $this->scanner = $scanner;
        $this->api_clients = new ApiClientStore();
        $this->access_tokens = new ApiAccessTokenStore();
        $this->tokens = new ApiTokenStore();
        $this->visibility = new ApiVisibilitySettings();
        $this->runtime = new ResourceRuntime();
        $this->mutations = new ResourceMutationService($this->runtime);
    }

    /**
     * Register WordPress hooks.
     */
    public function hooks()
    {
        add_action('rest_api_init', array($this, 'registerRoutes'));
    }

    /**
     * Register all routes declared by active CodeTool resources.
     */
    public function registerRoutes()
    {
        $this->ensureResources();
        $namespaces = array();

        $this->registerCoreRoutes();

        foreach ($this->resources as $resource) {
            if (!$resource->apiEnabled()) {
                continue;
            }

            $namespaces[$resource->apiNamespace()][] = $resource;
            $this->registerResourceRoutes($resource);
            $this->registerCustomRoutes($resource);
        }

        foreach ($namespaces as $namespace => $resources) {
            register_rest_route($namespace, '/openapi', array(
                'methods' => \WP_REST_Server::READABLE,
                'callback' => function ($request) use ($namespace, $resources) {
                    unset($request);

                    return $this->openapi->build($namespace, $resources);
                },
                'permission_callback' => function () use ($namespace) {
                    return $this->authorizeOpenApiNamespace($namespace);
                },
            ));
        }

        register_rest_route('smbb-wpcodetool/v1', '/openapi-all', array(
            'methods' => \WP_REST_Server::READABLE,
            'callback' => function ($request) use ($namespaces) {
                unset($request);

                $visible = $this->visibility->filterVisibleNamespaces($namespaces);

                if (!$visible) {
                    return $this->api->error(
                        'openapi_forbidden',
                        __('You are not allowed to view the OpenAPI documentation.', 'smbb-wpcodetool'),
                        403
                    );
                }

                return $this->openapi->buildAggregate($visible);
            },
            'permission_callback' => function () use ($namespaces) {
                return $this->authorizeOpenApiAggregate($namespaces);
            },
        ));
    }

    /**
     * Register the core auth endpoints provided by CodeTool itself.
     */
    private function registerCoreRoutes()
    {
        register_rest_route('smbb-wpcodetool/v1', '/token', array(
            'methods' => \WP_REST_Server::CREATABLE,
            'callback' => function ($request) {
                return $this->issueAccessToken($request);
            },
            'permission_callback' => '__return_true',
            'args' => array(
                'client_id' => array(
                    'required' => true,
                    'sanitize_callback' => 'sanitize_text_field',
                    'validate_callback' => function ($value) {
                        return trim((string) $value) !== '';
                    },
                ),
                'client_secret' => array(
                    'required' => true,
                    'sanitize_callback' => function ($value) {
                        return trim((string) $value);
                    },
                    'validate_callback' => function ($value) {
                        return trim((string) $value) !== '';
                    },
                ),
                'expires_in' => array(
                    'required' => false,
                    'sanitize_callback' => 'absint',
                    'validate_callback' => function ($value) {
                        return $value === null || ((int) $value) > 0;
                    },
                ),
            ),
        ));
    }

    /**
     * Exchange client credentials for a bearer access token.
     */
    private function issueAccessToken($request)
    {
        $client_id = trim((string) $this->api->param($request, 'client_id', ''));
        $client_secret = trim((string) $this->api->param($request, 'client_secret', ''));

        if ($client_id === '' || $client_secret === '') {
            return $this->api->error(
                'invalid_request',
                __('client_id and client_secret are required.', 'smbb-wpcodetool'),
                400
            );
        }

        $client = $this->api_clients->findActiveByCredentials($client_id, $client_secret);

        if (!$client) {
            return $this->api->error(
                'invalid_client',
                __('Invalid client_id or client_secret.', 'smbb-wpcodetool'),
                401
            );
        }

        $ttl = $this->api_clients->resolveTokenTtl(
            $client,
            (int) $this->api->param($request, 'expires_in', 0)
        );
        $issued = $this->access_tokens->issue((int) $client['id'], $ttl);

        if (!$issued) {
            return $this->api->error(
                'token_issue_failed',
                __('Unable to issue an access token right now.', 'smbb-wpcodetool'),
                500
            );
        }

        $this->api_clients->recordTokenIssued((int) $client['id']);

        return new \WP_REST_Response(array(
            'access_token' => $issued['access_token'],
            'token_type' => 'bearer',
            'expires_in' => (int) $issued['expires_in'],
        ), 200);
    }

    /**
     * Register the standard routes for one resource.
     */
    private function registerResourceRoutes(ResourceDefinition $resource)
    {
        if ($resource->storageType() === 'custom_table') {
            $this->registerTableRoutes($resource);
            return;
        }

        if ($resource->storageType() === 'option') {
            $this->registerOptionRoutes($resource);
        }
    }

    /**
     * Register collection/item routes for table resources.
     */
    private function registerTableRoutes(ResourceDefinition $resource)
    {
        $namespace = $resource->apiNamespace();
        $collection_route = '/' . $resource->apiBase();
        $item_route = $collection_route . '/(?P<' . $resource->primaryKey() . '>' . $this->resourceIdPattern($resource) . ')';
        $collection_endpoints = array();
        $item_endpoints = array();

        if ($resource->apiActionEnabled('list')) {
            $collection_endpoints[] = array(
                'methods' => \WP_REST_Server::READABLE,
                'callback' => function ($request) use ($resource) {
                    return $this->listTableItems($resource, $request);
                },
                'args' => $this->tableListArgs($resource),
                'permission_callback' => function ($request) use ($resource) {
                    return $this->authorizeResource($resource, $request);
                },
            );
        }

        if ($resource->apiActionEnabled('create')) {
            $collection_endpoints[] = array(
                'methods' => \WP_REST_Server::CREATABLE,
                'callback' => function ($request) use ($resource) {
                    return $this->createTableItem($resource, $request);
                },
                'args' => $this->tableWriteArgs($resource, 'create'),
                'permission_callback' => function ($request) use ($resource) {
                    return $this->authorizeResource($resource, $request);
                },
            );
        }

        if ($collection_endpoints) {
            register_rest_route($namespace, $collection_route, $collection_endpoints);
        }

        if ($resource->apiActionEnabled('get')) {
            $item_endpoints[] = array(
                'methods' => \WP_REST_Server::READABLE,
                'callback' => function ($request) use ($resource) {
                    return $this->getTableItem($resource, $request);
                },
                'args' => $this->resourceIdArg($resource),
                'permission_callback' => function ($request) use ($resource) {
                    return $this->authorizeResource($resource, $request);
                },
            );
        }

        if ($resource->apiActionEnabled('patch')) {
            $item_endpoints[] = array(
                'methods' => 'PATCH',
                'callback' => function ($request) use ($resource) {
                    return $this->updateTableItem($resource, $request, 'patch');
                },
                'args' => $this->tableWriteArgs($resource, 'patch'),
                'permission_callback' => function ($request) use ($resource) {
                    return $this->authorizeResource($resource, $request);
                },
            );
        }

        if ($resource->apiActionEnabled('put')) {
            $item_endpoints[] = array(
                'methods' => 'PUT',
                'callback' => function ($request) use ($resource) {
                    return $this->updateTableItem($resource, $request, 'put');
                },
                'args' => $this->tableWriteArgs($resource, 'put'),
                'permission_callback' => function ($request) use ($resource) {
                    return $this->authorizeResource($resource, $request);
                },
            );
        }

        if ($resource->apiActionEnabled('delete')) {
            $item_endpoints[] = array(
                'methods' => \WP_REST_Server::DELETABLE,
                'callback' => function ($request) use ($resource) {
                    return $this->deleteTableItem($resource, $request);
                },
                'args' => $this->resourceIdArg($resource),
                'permission_callback' => function ($request) use ($resource) {
                    return $this->authorizeResource($resource, $request);
                },
            );
        }

        if ($item_endpoints) {
            register_rest_route($namespace, $item_route, $item_endpoints);
        }
    }

    /**
     * Register singleton routes for option resources.
     */
    private function registerOptionRoutes(ResourceDefinition $resource)
    {
        $namespace = $resource->apiNamespace();
        $route = '/' . $resource->apiBase();
        $endpoints = array();

        if ($resource->apiActionEnabled('get')) {
            $endpoints[] = array(
                'methods' => \WP_REST_Server::READABLE,
                'callback' => function ($request) use ($resource) {
                    return $this->getOptionResource($resource, $request);
                },
                'args' => array(),
                'permission_callback' => function ($request) use ($resource) {
                    return $this->authorizeResource($resource, $request);
                },
            );
        }

        if ($resource->apiActionEnabled('create')) {
            $endpoints[] = array(
                'methods' => \WP_REST_Server::CREATABLE,
                'callback' => function ($request) use ($resource) {
                    return $this->saveOptionResource($resource, $request, 'create');
                },
                'args' => $this->optionWriteArgs($resource, 'create'),
                'permission_callback' => function ($request) use ($resource) {
                    return $this->authorizeResource($resource, $request);
                },
            );
        }

        if ($resource->apiActionEnabled('patch')) {
            $endpoints[] = array(
                'methods' => 'PATCH',
                'callback' => function ($request) use ($resource) {
                    return $this->saveOptionResource($resource, $request, 'patch');
                },
                'args' => $this->optionWriteArgs($resource, 'patch'),
                'permission_callback' => function ($request) use ($resource) {
                    return $this->authorizeResource($resource, $request);
                },
            );
        }

        if ($resource->apiActionEnabled('put')) {
            $endpoints[] = array(
                'methods' => 'PUT',
                'callback' => function ($request) use ($resource) {
                    return $this->saveOptionResource($resource, $request, 'put');
                },
                'args' => $this->optionWriteArgs($resource, 'put'),
                'permission_callback' => function ($request) use ($resource) {
                    return $this->authorizeResource($resource, $request);
                },
            );
        }

        if ($resource->apiActionEnabled('delete')) {
            $endpoints[] = array(
                'methods' => \WP_REST_Server::DELETABLE,
                'callback' => function ($request) use ($resource) {
                    return $this->deleteOptionResource($resource, $request);
                },
                'args' => array(),
                'permission_callback' => function ($request) use ($resource) {
                    return $this->authorizeResource($resource, $request);
                },
            );
        }

        if ($endpoints) {
            register_rest_route($namespace, $route, $endpoints);
        }
    }

    /**
     * Register all custom routes declared by the resource.
     */
    private function registerCustomRoutes(ResourceDefinition $resource)
    {
        foreach ($resource->apiCustomRoutes() as $name => $route) {
            if (empty($route['enabled']) || empty($route['path']) || empty($route['class']) || empty($route['callback'])) {
                continue;
            }

            register_rest_route($resource->apiNamespace(), $route['path'], array(
                'methods' => $route['method'],
                'callback' => function ($request) use ($resource, $name) {
                    return $this->dispatchCustomRoute($resource, $name, $request);
                },
                'args' => $this->customRouteArgs($resource, $route),
                'permission_callback' => function ($request) use ($resource) {
                    return $this->authorizeResource($resource, $request);
                },
            ));
        }
    }

    /**
     * Standard validation for collection reads.
     */
    private function tableListArgs(ResourceDefinition $resource)
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
     * Standard validation for resource item ids.
     */
    private function resourceIdArg(ResourceDefinition $resource)
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
     * Validation map for table writes.
     */
    private function tableWriteArgs(ResourceDefinition $resource, $mode)
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
     * First draft: option payloads keep hook-level validation for now.
     */
    private function optionWriteArgs(ResourceDefinition $resource, $mode)
    {
        unset($resource, $mode);

        return array();
    }

    /**
     * Validation map for one custom route.
     */
    private function customRouteArgs(ResourceDefinition $resource, array $route)
    {
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

    /**
     * Infer path params from a WordPress regex route.
     */
    private function pathArgsFromRoutePath($path)
    {
        $args = array();
        $matches = array();

        if (preg_match_all('/\(\?P<([a-zA-Z0-9_]+)>([^)]+)\)/', (string) $path, $matches, PREG_SET_ORDER) !== 1 && empty($matches)) {
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
     * One WordPress endpoint arg definition derived from a column.
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
     * One WordPress endpoint arg definition derived from a custom schema.
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
     * Optional sanitizer for custom route args.
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

    /**
     * Validation for one table column value.
     */
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
     * Validation for one custom route arg.
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
                if (!is_numeric($value) || (string) (int) $value !== (string) $value && (int) $value != $value) {
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

    /**
     * Authorize one resource endpoint.
     */
    private function authorizeResource(ResourceDefinition $resource, $request)
    {
        if (current_user_can($resource->apiCapability())) {
            return true;
        }

        if ($this->requestHasValidBearerToken($resource, $request)) {
            return true;
        }

        return $this->api->error(
            'rest_forbidden',
            __('A valid Bearer token is required.', 'smbb-wpcodetool'),
            401
        );
    }

    /**
     * Authorization for one namespace OpenAPI endpoint.
     */
    private function authorizeOpenApiNamespace($namespace)
    {
        if ($this->visibility->currentUserCanView($namespace)) {
            return true;
        }

        return $this->api->error(
            'openapi_forbidden',
            __('You are not allowed to view this OpenAPI documentation.', 'smbb-wpcodetool'),
            403
        );
    }

    /**
     * Authorization for the aggregate OpenAPI endpoint.
     *
     * @param array<string,mixed> $namespaces
     */
    private function authorizeOpenApiAggregate(array $namespaces)
    {
        if ($this->visibility->filterVisibleNamespaces($namespaces)) {
            return true;
        }

        return $this->api->error(
            'openapi_forbidden',
            __('You are not allowed to view the OpenAPI documentation.', 'smbb-wpcodetool'),
            403
        );
    }

    /**
     * List table rows.
     */
    private function listTableItems(ResourceDefinition $resource, $request)
    {
        $store = new TableStore($resource);
        $search = $this->tableSearchTerm($resource, $request);
        $args = array(
            'search' => $search,
            'orderby' => sanitize_key((string) $request->get_param('orderby')),
            'order' => sanitize_key((string) $request->get_param('order')),
            'filter' => $this->tableFilterArgs($resource, $request),
            'per_page' => $this->perPageFromRequest($resource, $request),
            'page' => max(1, (int) $request->get_param('page')),
        );

        if ($search !== '' && $resource->listSearchProvider() === 'hook') {
            $search_clause = $this->runtime->tableSearchClause($resource, $search);

            if ($search_clause) {
                $args['search_clause'] = $search_clause;
            }
        }

        $items = $store->list($args);
        $total = $store->count($args);
        $total_pages = $args['per_page'] > 0 ? (int) ceil($total / $args['per_page']) : 0;
        $response = rest_ensure_response(array(
            'items' => $items,
            'meta' => array(
                'page' => $args['page'],
                'per_page' => $args['per_page'],
                'total' => $total,
                'total_pages' => $total_pages,
            ),
        ));

        if (is_object($response) && method_exists($response, 'header')) {
            $response->header('X-WP-Total', (string) $total);
            $response->header('X-WP-TotalPages', (string) $total_pages);
        }

        return $response;
    }

    /**
     * Read one table row.
     */
    private function getTableItem(ResourceDefinition $resource, $request)
    {
        $store = new TableStore($resource);
        $id = $this->resourceIdFromRequest($resource, $request);

        if ($id === null) {
            return $this->api->error('invalid_id', __('Missing resource identifier.', 'smbb-wpcodetool'), 400);
        }

        $row = $store->find($id);

        return $row ? $row : $this->api->notFound(__('Resource not found.', 'smbb-wpcodetool'));
    }

    /**
     * Create a table row.
     */
    private function createTableItem(ResourceDefinition $resource, $request)
    {
        $result = $this->mutations->saveTable($resource, $this->requestTableData($resource, $request), array(
            'action' => 'api_create',
            'context' => array(
                'request' => $request,
            ),
            'save_failed_message' => __('The item could not be created.', 'smbb-wpcodetool'),
            'validation_callback' => function (array $data) use ($resource) {
                return $this->requiredTableErrors($resource, $data);
            },
        ));

        if (empty($result['success'])) {
            if (isset($result['reason']) && $result['reason'] === 'validation') {
                return $this->api->validationError(isset($result['errors']) && is_array($result['errors']) ? $result['errors'] : array());
            }

            return $this->api->error(
                'create_failed',
                !empty($result['message']) ? $result['message'] : __('The item could not be created.', 'smbb-wpcodetool'),
                500
            );
        }

        return new \WP_REST_Response($result['payload'], 201);
    }

    /**
     * Update a table row with PATCH or PUT semantics.
     */
    private function updateTableItem(ResourceDefinition $resource, $request, $mode)
    {
        $id = $this->resourceIdFromRequest($resource, $request);

        if ($id === null) {
            return $this->api->error('invalid_id', __('Missing resource identifier.', 'smbb-wpcodetool'), 400);
        }

        $incoming = $this->requestTableData($resource, $request);
        $store = new TableStore($resource);
        $current = $store->find($id);

        if (!$current) {
            return $this->api->notFound(__('Resource not found.', 'smbb-wpcodetool'));
        }

        list($data, $update_errors) = $this->prepareTableUpdateData($resource, $current, $incoming, $mode);
        $result = $this->mutations->saveTable($resource, $data, array(
            'id' => $id,
            'store' => $store,
            'current' => $current,
            'action' => 'api_' . $mode,
            'context' => array(
                'request' => $request,
            ),
            'validation_errors' => $update_errors,
            'validation_callback' => function (array $validated_data) use ($resource, $mode) {
                return $mode === 'put' ? $this->requiredTableErrors($resource, $validated_data) : array();
            },
            'not_found_message' => __('Resource not found.', 'smbb-wpcodetool'),
            'save_failed_message' => __('The item could not be updated.', 'smbb-wpcodetool'),
        ));

        if (empty($result['success'])) {
            if (isset($result['reason']) && $result['reason'] === 'validation') {
                return $this->api->validationError(isset($result['errors']) && is_array($result['errors']) ? $result['errors'] : array());
            }

            if (isset($result['reason']) && $result['reason'] === 'not_found') {
                return $this->api->notFound(!empty($result['message']) ? $result['message'] : __('Resource not found.', 'smbb-wpcodetool'));
            }

            return $this->api->error(
                'update_failed',
                !empty($result['message']) ? $result['message'] : __('The item could not be updated.', 'smbb-wpcodetool'),
                500
            );
        }

        return $result['payload'];
    }

    /**
     * Delete a table row.
     */
    private function deleteTableItem(ResourceDefinition $resource, $request)
    {
        $id = $this->resourceIdFromRequest($resource, $request);

        if ($id === null) {
            return $this->api->error('invalid_id', __('Missing resource identifier.', 'smbb-wpcodetool'), 400);
        }

        $result = $this->mutations->deleteTable($resource, $id, array(
            'action' => 'api_delete',
            'context' => array(
                'request' => $request,
            ),
            'not_found_message' => __('Resource not found.', 'smbb-wpcodetool'),
            'blocked_message' => __('Deletion was blocked by the resource hooks.', 'smbb-wpcodetool'),
            'delete_failed_message' => __('The item could not be deleted.', 'smbb-wpcodetool'),
        ));

        if (empty($result['success'])) {
            if (isset($result['reason']) && $result['reason'] === 'not_found') {
                return $this->api->notFound(!empty($result['message']) ? $result['message'] : __('Resource not found.', 'smbb-wpcodetool'));
            }

            if (isset($result['reason']) && $result['reason'] === 'blocked') {
                return $this->api->error(
                    'delete_blocked',
                    !empty($result['message']) ? $result['message'] : __('Deletion was blocked by the resource hooks.', 'smbb-wpcodetool'),
                    403
                );
            }

            return $this->api->error(
                'delete_failed',
                !empty($result['message']) ? $result['message'] : __('The item could not be deleted.', 'smbb-wpcodetool'),
                500
            );
        }

        return array(
            'deleted' => true,
            'id' => $id,
        );
    }

    /**
     * Read a singleton option resource.
     */
    private function getOptionResource(ResourceDefinition $resource, $request)
    {
        unset($request);

        $store = new OptionStore($resource->optionName(), $resource->optionDefaults(), $resource->optionAutoload());

        return $store->get();
    }

    /**
     * Save a singleton option resource.
     */
    private function saveOptionResource(ResourceDefinition $resource, $request, $mode)
    {
        $merge_current = $mode === 'patch'
            || (($mode === 'create' || $mode === 'put') && $resource->apiActionConfig($mode === 'create' ? 'put' : $mode)['missingFields'] === 'keep');
        $result = $this->mutations->saveOption($resource, $this->requestOptionData($request), array(
            'action' => 'api_' . $mode,
            'context' => array(
                'request' => $request,
            ),
            'merge_current' => $merge_current,
            'save_failed_message' => __('The settings could not be saved.', 'smbb-wpcodetool'),
        ));

        if (empty($result['success'])) {
            if (isset($result['reason']) && $result['reason'] === 'validation') {
                return $this->api->validationError(isset($result['errors']) && is_array($result['errors']) ? $result['errors'] : array());
            }

            return $this->api->error(
                'update_failed',
                !empty($result['message']) ? $result['message'] : __('The settings could not be saved.', 'smbb-wpcodetool'),
                500
            );
        }

        return $result['payload'];
    }

    /**
     * Delete a singleton option resource.
     */
    private function deleteOptionResource(ResourceDefinition $resource, $request)
    {
        unset($request);

        $store = new OptionStore($resource->optionName(), $resource->optionDefaults(), $resource->optionAutoload());

        return array(
            'deleted' => $store->delete(),
        );
    }

    /**
     * Dispatch a custom route to the class declared in the JSON model.
     */
    private function dispatchCustomRoute(ResourceDefinition $resource, $name, $request)
    {
        $routes = $resource->apiCustomRoutes();

        if (!isset($routes[$name])) {
            return $this->api->error('missing_route', __('Unknown custom route.', 'smbb-wpcodetool'), 500);
        }

        $route = $routes[$name];
        $file = $resource->apiCustomRouteFilePath($name);

        if ($file && is_readable($file)) {
            require_once $file;
        }

        if (!class_exists($route['class'])) {
            return $this->api->error('missing_class', __('The custom API class could not be loaded.', 'smbb-wpcodetool'), 500);
        }

        $instance = $this->customApiInstance($resource, $name, $route['class']);

        if (!$instance || !is_callable(array($instance, $route['callback']))) {
            return $this->api->error('missing_callback', __('The custom API callback is not callable.', 'smbb-wpcodetool'), 500);
        }

        return call_user_func(array($instance, $route['callback']), $request, array(
            'action' => 'api_custom_' . $name,
            'hooks' => $this->runtime->hooksFor($resource),
            'request' => $request,
            'resource' => $resource,
            'store' => $this->storeForResource($resource),
        ));
    }

    /**
     * Cached custom API class instance.
     */
    private function customApiInstance(ResourceDefinition $resource, $name, $class)
    {
        $cache_key = $resource->name() . ':' . $name;

        if (array_key_exists($cache_key, $this->custom_api_cache)) {
            return $this->custom_api_cache[$cache_key];
        }

        try {
            $reflection = new \ReflectionClass($class);
            $constructor = $reflection->getConstructor();

            if ($constructor === null || $constructor->getNumberOfRequiredParameters() === 0) {
                $this->custom_api_cache[$cache_key] = $reflection->newInstance();
            } elseif ($constructor->getNumberOfRequiredParameters() === 1) {
                $this->custom_api_cache[$cache_key] = $reflection->newInstance($this->api);
            } else {
                $this->custom_api_cache[$cache_key] = null;
            }
        } catch (\ReflectionException $exception) {
            $this->custom_api_cache[$cache_key] = null;
        }

        return $this->custom_api_cache[$cache_key];
    }

    /**
     * Resolve a store for the resource type.
     */
    private function storeForResource(ResourceDefinition $resource)
    {
        if ($resource->storageType() === 'custom_table') {
            return new TableStore($resource);
        }

        if ($resource->storageType() === 'option') {
            return new OptionStore($resource->optionName(), $resource->optionDefaults(), $resource->optionAutoload());
        }

        return null;
    }

    /**
     * Merge an update payload according to the action config.
     */
    private function prepareTableUpdateData(ResourceDefinition $resource, array $current, array $incoming, $mode)
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

    /**
     * Apply null-handling rules for PATCH/PUT.
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
     * Required field validation derived from the column map.
     */
    private function requiredTableErrors(ResourceDefinition $resource, array $data)
    {
        $errors = array();

        foreach ($resource->columns() as $column => $definition) {
            if (!is_array($definition)) {
                continue;
            }

            if (!empty($definition['managed']) || (!empty($definition['primary']) && !empty($definition['autoIncrement']))) {
                continue;
            }

            if (!empty($definition['nullable']) || array_key_exists('default', $definition)) {
                continue;
            }

            if (!array_key_exists($column, $data) || $this->isBlankValue($data[$column])) {
                $errors[$column] = __('This field is required.', 'smbb-wpcodetool');
            }
        }

        return $errors;
    }

    /**
     * User-writable table columns.
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

    /**
     * Prepare request data for table resources.
     */
    private function requestTableData(ResourceDefinition $resource, $request)
    {
        $payload = $this->requestPayload($request);
        $data = array();

        foreach ($resource->columns() as $column => $definition) {
            if (!is_array($definition)) {
                continue;
            }

            if (!array_key_exists($column, $payload)) {
                continue;
            }

            if (!empty($definition['managed']) || (!empty($definition['primary']) && !empty($definition['autoIncrement']))) {
                continue;
            }

            $data[$column] = $payload[$column];
        }

        return $data;
    }

    /**
     * Prepare request data for option resources.
     */
    private function requestOptionData($request)
    {
        return $this->requestPayload($request);
    }

    /**
     * Decode the request payload from JSON or form data.
     */
    private function requestPayload($request)
    {
        if (is_object($request) && method_exists($request, 'get_json_params')) {
            $payload = $request->get_json_params();

            if (is_array($payload)) {
                return $payload;
            }
        }

        if (is_object($request) && method_exists($request, 'get_body_params')) {
            $payload = $request->get_body_params();

            if (is_array($payload)) {
                return $payload;
            }
        }

        return array();
    }

    /**
     * Build the list search term.
     */
    private function tableSearchTerm(ResourceDefinition $resource, $request)
    {
        if (!$resource->listSearchEnabled()) {
            return '';
        }

        $value = $request->get_param('search');

        if ($value === null || $value === '') {
            $value = $request->get_param('s');
        }

        return sanitize_text_field((string) $value);
    }

    /**
     * Filter args compatible with TableStore.
     */
    private function tableFilterArgs(ResourceDefinition $resource, $request)
    {
        if (!$resource->listFiltersEnabled()) {
            return array(
                'field' => '',
                'operator' => '',
                'value' => '',
            );
        }

        $filter = $request->get_param('filter');
        $filter = is_array($filter) ? $filter : array();
        $field = isset($filter['field']) ? sanitize_key((string) $filter['field']) : '';

        if ($field !== '' && !in_array($field, $resource->listFilterColumns(), true)) {
            $field = '';
        }

        return array(
            'field' => $field,
            'operator' => isset($filter['operator']) ? sanitize_key((string) $filter['operator']) : '',
            'value' => isset($filter['value']) ? sanitize_text_field((string) $filter['value']) : '',
        );
    }

    /**
     * Resolve the effective page size.
     */
    private function perPageFromRequest(ResourceDefinition $resource, $request)
    {
        $config = $resource->listConfig();
        $default = isset($config['perPage']) ? (int) $config['perPage'] : 20;
        $value = $request->get_param('per_page');

        if ($value === null || $value === '') {
            $value = $request->get_param('limit');
        }

        return min(200, max(1, (int) $value ?: $default));
    }

    /**
     * Read the resource identifier from the request.
     */
    private function resourceIdFromRequest(ResourceDefinition $resource, $request)
    {
        $primary_key = $resource->primaryKey();
        $value = $request->get_param($primary_key);

        if ($value === null || $value === '') {
            return null;
        }

            return $this->runtime->primaryKeyIsNumeric($resource) ? (int) $value : (string) $value;
    }

    /**
     * Validate a bearer token against managed SQL tokens and legacy managed tokens.
     */
    private function requestHasValidBearerToken(ResourceDefinition $resource, $request)
    {
        $header = '';

        if (is_object($request) && method_exists($request, 'get_header')) {
            $header = (string) $request->get_header('authorization');
        }

        if ($header === '' && isset($_SERVER['HTTP_AUTHORIZATION'])) {
            $header = (string) wp_unslash($_SERVER['HTTP_AUTHORIZATION']);
        }

        if (!preg_match('/^Bearer\s+(.+)$/i', trim($header), $matches)) {
            return false;
        }

        $provided = trim((string) $matches[1]);

        if ($provided !== '' && $this->access_tokens->verify($provided)) {
            return true;
        }

        if ($provided !== '' && $this->tokens->verify($provided)) {
            return true;
        }

        return false;
    }

    /**
     * Regex for the item route identifier.
     */
    private function resourceIdPattern(ResourceDefinition $resource)
    {
        return $this->runtime->primaryKeyIsNumeric($resource) ? '\\d+' : '[^\/]+';
    }

    /**
     * Basic blank-value detection for required checks.
     */
    private function isBlankValue($value)
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
     * Load the scanner result once per request.
     */
    private function ensureResources()
    {
        if ($this->resources || $this->errors) {
            return;
        }

        $this->resources = $this->scanner->scan();
        $this->errors = $this->scanner->errors();
    }
}
