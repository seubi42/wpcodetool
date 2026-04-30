<?php

namespace Smbb\WpCodeTool\Api;

use Smbb\WpCodeTool\Resource\ResourceDefinition;
use Smbb\WpCodeTool\Resource\ResourceRuntime;

defined('ABSPATH') || exit;

/**
 * Enregistre les routes REST WordPress exposees par les ressources CodeTool.
 */
final class ApiRouteRegistrar
{
    private $core;
    private $resources;
    private $args;

    public function __construct(
        ApiCoreController $core = null,
        ApiResourceController $resources = null,
        ResourceRuntime $runtime = null,
        ApiArgsBuilder $args = null
    ) {
        $runtime = $runtime ?: new ResourceRuntime();
        $this->core = $core ?: new ApiCoreController();
        $this->resources = $resources ?: new ApiResourceController();
        $this->args = $args ?: new ApiArgsBuilder($runtime);
    }

    /**
     * @param array<string,ResourceDefinition> $resources
     */
    public function register(array $resources)
    {
        $namespaces = array();

        $this->registerCoreRoutes();

        foreach ($resources as $resource) {
            if (!$resource->apiEnabled()) {
                continue;
            }

            $namespaces[$resource->apiNamespace()][] = $resource;
            $this->registerResourceRoutes($resource);
            $this->registerCustomRoutes($resource);
        }

        foreach ($namespaces as $namespace => $namespace_resources) {
            register_rest_route($namespace, '/openapi', array(
                'methods' => \WP_REST_Server::READABLE,
                'callback' => function ($request) use ($namespace, $namespace_resources) {
                    unset($request);

                    return $this->core->buildNamespaceOpenApi($namespace, $namespace_resources);
                },
                'permission_callback' => function () use ($namespace) {
                    return $this->core->authorizeOpenApiNamespace($namespace);
                },
            ));
        }

        register_rest_route('smbb-wpcodetool/v1', '/openapi-all', array(
            'methods' => \WP_REST_Server::READABLE,
            'callback' => function ($request) use ($namespaces) {
                unset($request);

                return $this->core->buildAggregateOpenApi($namespaces);
            },
            'permission_callback' => function () use ($namespaces) {
                return $this->core->authorizeOpenApiAggregate($namespaces);
            },
        ));
    }

    private function registerCoreRoutes()
    {
        register_rest_route('smbb-wpcodetool/v1', '/token', array(
            'methods' => \WP_REST_Server::CREATABLE,
            'callback' => function ($request) {
                return $this->core->issueAccessToken($request);
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

    private function registerTableRoutes(ResourceDefinition $resource)
    {
        $namespace = $resource->apiNamespace();
        $collection_route = '/' . $resource->apiBase();
        $item_route = $collection_route . '/(?P<' . $resource->primaryKey() . '>' . $this->args->resourceIdPattern($resource) . ')';
        $collection_endpoints = array();
        $item_endpoints = array();

        if ($resource->apiActionEnabled('list')) {
            $collection_endpoints[] = array(
                'methods' => \WP_REST_Server::READABLE,
                'callback' => function ($request) use ($resource) {
                    return $this->resources->listTableItems($resource, $request);
                },
                'args' => $this->args->tableListArgs($resource),
                'permission_callback' => function ($request) use ($resource) {
                    return $this->resources->authorizeResource($resource, $request, 'list');
                },
            );
        }

        if ($resource->apiActionEnabled('create')) {
            $collection_endpoints[] = array(
                'methods' => \WP_REST_Server::CREATABLE,
                'callback' => function ($request) use ($resource) {
                    return $this->resources->createTableItem($resource, $request);
                },
                'args' => $this->args->tableWriteArgs($resource, 'create'),
                'permission_callback' => function ($request) use ($resource) {
                    return $this->resources->authorizeResource($resource, $request, 'create');
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
                    return $this->resources->getTableItem($resource, $request);
                },
                'args' => $this->args->resourceIdArg($resource),
                'permission_callback' => function ($request) use ($resource) {
                    return $this->resources->authorizeResource($resource, $request, 'get');
                },
            );
        }

        if ($resource->apiActionEnabled('patch')) {
            $item_endpoints[] = array(
                'methods' => 'PATCH',
                'callback' => function ($request) use ($resource) {
                    return $this->resources->updateTableItem($resource, $request, 'patch');
                },
                'args' => $this->args->tableWriteArgs($resource, 'patch'),
                'permission_callback' => function ($request) use ($resource) {
                    return $this->resources->authorizeResource($resource, $request, 'patch');
                },
            );
        }

        if ($resource->apiActionEnabled('put')) {
            $item_endpoints[] = array(
                'methods' => 'PUT',
                'callback' => function ($request) use ($resource) {
                    return $this->resources->updateTableItem($resource, $request, 'put');
                },
                'args' => $this->args->tableWriteArgs($resource, 'put'),
                'permission_callback' => function ($request) use ($resource) {
                    return $this->resources->authorizeResource($resource, $request, 'put');
                },
            );
        }

        if ($resource->apiActionEnabled('delete')) {
            $item_endpoints[] = array(
                'methods' => \WP_REST_Server::DELETABLE,
                'callback' => function ($request) use ($resource) {
                    return $this->resources->deleteTableItem($resource, $request);
                },
                'args' => $this->args->resourceIdArg($resource),
                'permission_callback' => function ($request) use ($resource) {
                    return $this->resources->authorizeResource($resource, $request, 'delete');
                },
            );
        }

        if ($item_endpoints) {
            register_rest_route($namespace, $item_route, $item_endpoints);
        }
    }

    private function registerOptionRoutes(ResourceDefinition $resource)
    {
        $namespace = $resource->apiNamespace();
        $route = '/' . $resource->apiBase();
        $endpoints = array();

        if ($resource->apiActionEnabled('get')) {
            $endpoints[] = array(
                'methods' => \WP_REST_Server::READABLE,
                'callback' => function ($request) use ($resource) {
                    return $this->resources->getOptionResource($resource, $request);
                },
                'args' => array(),
                'permission_callback' => function ($request) use ($resource) {
                    return $this->resources->authorizeResource($resource, $request, 'get');
                },
            );
        }

        if ($resource->apiActionEnabled('create')) {
            $endpoints[] = array(
                'methods' => \WP_REST_Server::CREATABLE,
                'callback' => function ($request) use ($resource) {
                    return $this->resources->saveOptionResource($resource, $request, 'create');
                },
                'args' => $this->args->optionWriteArgs($resource, 'create'),
                'permission_callback' => function ($request) use ($resource) {
                    return $this->resources->authorizeResource($resource, $request, 'create');
                },
            );
        }

        if ($resource->apiActionEnabled('patch')) {
            $endpoints[] = array(
                'methods' => 'PATCH',
                'callback' => function ($request) use ($resource) {
                    return $this->resources->saveOptionResource($resource, $request, 'patch');
                },
                'args' => $this->args->optionWriteArgs($resource, 'patch'),
                'permission_callback' => function ($request) use ($resource) {
                    return $this->resources->authorizeResource($resource, $request, 'patch');
                },
            );
        }

        if ($resource->apiActionEnabled('put')) {
            $endpoints[] = array(
                'methods' => 'PUT',
                'callback' => function ($request) use ($resource) {
                    return $this->resources->saveOptionResource($resource, $request, 'put');
                },
                'args' => $this->args->optionWriteArgs($resource, 'put'),
                'permission_callback' => function ($request) use ($resource) {
                    return $this->resources->authorizeResource($resource, $request, 'put');
                },
            );
        }

        if ($resource->apiActionEnabled('delete')) {
            $endpoints[] = array(
                'methods' => \WP_REST_Server::DELETABLE,
                'callback' => function ($request) use ($resource) {
                    return $this->resources->deleteOptionResource($resource, $request);
                },
                'args' => array(),
                'permission_callback' => function ($request) use ($resource) {
                    return $this->resources->authorizeResource($resource, $request, 'delete');
                },
            );
        }

        if ($endpoints) {
            register_rest_route($namespace, $route, $endpoints);
        }
    }

    private function registerCustomRoutes(ResourceDefinition $resource)
    {
        foreach ($resource->apiCustomRoutes() as $name => $route) {
            if (empty($route['enabled']) || empty($route['path']) || empty($route['class']) || empty($route['callback'])) {
                continue;
            }

            register_rest_route($resource->apiNamespace(), $route['path'], array(
                'methods' => $route['method'],
                'callback' => function ($request) use ($resource, $name) {
                    return $this->resources->dispatchCustomRoute($resource, $name, $request);
                },
                'args' => $this->args->customRouteArgs($resource, $route),
                'permission_callback' => function ($request) use ($resource, $route) {
                    return $this->resources->authorizeResource($resource, $request, isset($route['method']) ? (string) $route['method'] : 'GET');
                },
            ));
        }
    }
}
