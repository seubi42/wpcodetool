<?php

namespace Smbb\WpCodeTool\Api;

use Smbb\WpCodeTool\Resource\ResourceDefinition;
use Smbb\WpCodeTool\Resource\ResourceMutationService;
use Smbb\WpCodeTool\Resource\ResourceRuntime;
use Smbb\WpCodeTool\Store\OptionStore;
use Smbb\WpCodeTool\Store\TableStore;

defined('ABSPATH') || exit;

/**
 * Orchestre le CRUD REST standard, les permissions et les mutations de ressources.
 */
final class ApiResourceController
{
    private const ERROR_INVALID_ID = 'invalid_id';
    private const ERROR_CREATE_FAILED = 'create_failed';
    private const ERROR_UPDATE_FAILED = 'update_failed';
    private const ERROR_DELETE_FAILED = 'delete_failed';
    private const ERROR_DELETE_BLOCKED = 'delete_blocked';
    private const ERROR_FORBIDDEN = 'rest_forbidden';
    private const ERROR_FORBIDDEN_SCOPE = 'rest_forbidden_scope';
    private const MSG_INVALID_ID = 'Missing resource identifier.';
    private const MSG_NOT_FOUND = 'Resource not found.';
    private const MSG_CREATE_FAILED = 'The item could not be created.';
    private const MSG_UPDATE_FAILED = 'The item could not be updated.';
    private const MSG_DELETE_FAILED = 'The item could not be deleted.';
    private const MSG_DELETE_BLOCKED = 'Deletion was blocked by the resource hooks.';
    private const MSG_OPTION_SAVE_FAILED = 'The settings could not be saved.';
    private const MSG_TOKEN_REQUIRED = 'A valid Bearer token is required.';

    private $api;
    private $runtime;
    private $mutations;
    private $write_semantics;
    private $access_tokens;
    private $tokens;
    private $scope_authorizer;
    private $requests;
    private $custom_routes;
    private $access_client_cache = array();

    public function __construct(
        ApiHelper $api = null,
        ResourceRuntime $runtime = null,
        ResourceMutationService $mutations = null,
        ApiWriteSemantics $write_semantics = null,
        ApiAccessTokenStore $access_tokens = null,
        ApiTokenStore $tokens = null,
        ApiScopeAuthorizer $scope_authorizer = null,
        ApiResourceRequestReader $requests = null,
        ApiCustomRouteDispatcher $custom_routes = null
    ) {
        $this->api = $api ?: new ApiHelper();
        $this->runtime = $runtime ?: new ResourceRuntime();
        $this->mutations = $mutations ?: new ResourceMutationService($this->runtime);
        $this->write_semantics = $write_semantics ?: new ApiWriteSemantics();
        $this->access_tokens = $access_tokens ?: new ApiAccessTokenStore();
        $this->tokens = $tokens ?: new ApiTokenStore();
        $this->scope_authorizer = $scope_authorizer ?: new ApiScopeAuthorizer();
        $this->requests = $requests ?: new ApiResourceRequestReader($this->runtime);
        $this->custom_routes = $custom_routes ?: new ApiCustomRouteDispatcher($this->api, $this->runtime);
    }

    public function authorizeResource(ResourceDefinition $resource, $request, $action = 'read')
    {
        if (current_user_can($resource->apiCapability())) {
            return true;
        }

        $provided = $this->bearerTokenFromRequest($request);

        if ($provided !== '') {
            $client = $this->accessClientFromBearerToken($provided);

            if ($client !== null) {
                if ($this->scope_authorizer->clientAllows($client, $resource, $action)) {
                    return true;
                }

                $normalized_action = $this->scope_authorizer->normalizeAction($action);

                return $this->api->error(
                    self::ERROR_FORBIDDEN_SCOPE,
                    sprintf(
                        __('This Bearer token does not grant %1$s access to resource "%2$s".', 'smbb-wpcodetool'),
                        $normalized_action !== '' ? $normalized_action : __('requested', 'smbb-wpcodetool'),
                        $resource->name()
                    ),
                    403
                );
            }

            if ($this->tokens->verify($provided)) {
                return true;
            }
        }

        return $this->api->error(
            self::ERROR_FORBIDDEN,
            __(self::MSG_TOKEN_REQUIRED, 'smbb-wpcodetool'),
            401
        );
    }

    public function listTableItems(ResourceDefinition $resource, $request)
    {
        $store = new TableStore($resource);
        $search = $this->requests->tableSearchTerm($resource, $request);
        $args = array(
            'search' => $search,
            'orderby' => sanitize_key((string) $request->get_param('orderby')),
            'order' => sanitize_key((string) $request->get_param('order')),
            'filter' => $this->requests->tableFilterArgs($resource, $request),
            'per_page' => $this->requests->perPage($resource, $request),
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

    public function getTableItem(ResourceDefinition $resource, $request)
    {
        $store = new TableStore($resource);
        $id = $this->requests->resourceId($resource, $request);

        if ($id === null) {
            return $this->api->error(self::ERROR_INVALID_ID, __(self::MSG_INVALID_ID, 'smbb-wpcodetool'), 400);
        }

        $row = $store->find($id);

        return $row ? $row : $this->api->notFound(__(self::MSG_NOT_FOUND, 'smbb-wpcodetool'));
    }

    public function createTableItem(ResourceDefinition $resource, $request)
    {
        $result = $this->mutations->saveTable($resource, $this->requests->tableData($resource, $request), array(
            'action' => 'api_create',
            'context' => array(
                'request' => $request,
            ),
            'save_failed_message' => __(self::MSG_CREATE_FAILED, 'smbb-wpcodetool'),
            'validation_callback' => function (array $data) use ($resource) {
                return $this->requiredTableErrors($resource, $data);
            },
        ));

        if (empty($result['success'])) {
            if (isset($result['reason']) && $result['reason'] === 'validation') {
                return $this->api->validationError(isset($result['errors']) && is_array($result['errors']) ? $result['errors'] : array());
            }

            return $this->api->error(
                self::ERROR_CREATE_FAILED,
                !empty($result['message']) ? $result['message'] : __(self::MSG_CREATE_FAILED, 'smbb-wpcodetool'),
                500
            );
        }

        return new \WP_REST_Response($result['payload'], 201);
    }

    public function updateTableItem(ResourceDefinition $resource, $request, $mode)
    {
        $id = $this->requests->resourceId($resource, $request);

        if ($id === null) {
            return $this->api->error(self::ERROR_INVALID_ID, __(self::MSG_INVALID_ID, 'smbb-wpcodetool'), 400);
        }

        $incoming = $this->requests->tableData($resource, $request);
        $store = new TableStore($resource);
        $current = $store->find($id);

        if (!$current) {
            return $this->api->notFound(__(self::MSG_NOT_FOUND, 'smbb-wpcodetool'));
        }

        list($data, $update_errors) = $this->write_semantics->prepareTableUpdateData($resource, $current, $incoming, $mode);
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
            'not_found_message' => __(self::MSG_NOT_FOUND, 'smbb-wpcodetool'),
            'save_failed_message' => __(self::MSG_UPDATE_FAILED, 'smbb-wpcodetool'),
        ));

        if (empty($result['success'])) {
            if (isset($result['reason']) && $result['reason'] === 'validation') {
                return $this->api->validationError(isset($result['errors']) && is_array($result['errors']) ? $result['errors'] : array());
            }

            if (isset($result['reason']) && $result['reason'] === 'not_found') {
                return $this->api->notFound(!empty($result['message']) ? $result['message'] : __(self::MSG_NOT_FOUND, 'smbb-wpcodetool'));
            }

            return $this->api->error(
                self::ERROR_UPDATE_FAILED,
                !empty($result['message']) ? $result['message'] : __(self::MSG_UPDATE_FAILED, 'smbb-wpcodetool'),
                500
            );
        }

        return $result['payload'];
    }

    public function deleteTableItem(ResourceDefinition $resource, $request)
    {
        $id = $this->requests->resourceId($resource, $request);

        if ($id === null) {
            return $this->api->error(self::ERROR_INVALID_ID, __(self::MSG_INVALID_ID, 'smbb-wpcodetool'), 400);
        }

        $result = $this->mutations->deleteTable($resource, $id, array(
            'action' => 'api_delete',
            'context' => array(
                'request' => $request,
            ),
            'not_found_message' => __(self::MSG_NOT_FOUND, 'smbb-wpcodetool'),
            'blocked_message' => __(self::MSG_DELETE_BLOCKED, 'smbb-wpcodetool'),
            'delete_failed_message' => __(self::MSG_DELETE_FAILED, 'smbb-wpcodetool'),
        ));

        if (empty($result['success'])) {
            if (isset($result['reason']) && $result['reason'] === 'not_found') {
                return $this->api->notFound(!empty($result['message']) ? $result['message'] : __(self::MSG_NOT_FOUND, 'smbb-wpcodetool'));
            }

            if (isset($result['reason']) && $result['reason'] === 'blocked') {
                return $this->api->error(
                    self::ERROR_DELETE_BLOCKED,
                    !empty($result['message']) ? $result['message'] : __(self::MSG_DELETE_BLOCKED, 'smbb-wpcodetool'),
                    403
                );
            }

            return $this->api->error(
                self::ERROR_DELETE_FAILED,
                !empty($result['message']) ? $result['message'] : __(self::MSG_DELETE_FAILED, 'smbb-wpcodetool'),
                500
            );
        }

        return array(
            'deleted' => true,
            'id' => $id,
        );
    }

    public function getOptionResource(ResourceDefinition $resource, $request)
    {
        unset($request);

        $store = new OptionStore($resource->optionName(), $resource->optionDefaults(), $resource->optionAutoload());

        return $store->get();
    }

    public function saveOptionResource(ResourceDefinition $resource, $request, $mode)
    {
        $result = $this->mutations->saveOption($resource, $this->requests->optionData($request), array(
            'action' => 'api_' . $mode,
            'context' => array(
                'request' => $request,
            ),
            'merge_current' => $this->write_semantics->shouldMergeOptionCurrent($resource, $mode),
            'save_failed_message' => __(self::MSG_OPTION_SAVE_FAILED, 'smbb-wpcodetool'),
        ));

        if (empty($result['success'])) {
            if (isset($result['reason']) && $result['reason'] === 'validation') {
                return $this->api->validationError(isset($result['errors']) && is_array($result['errors']) ? $result['errors'] : array());
            }

            return $this->api->error(
                self::ERROR_UPDATE_FAILED,
                !empty($result['message']) ? $result['message'] : __(self::MSG_OPTION_SAVE_FAILED, 'smbb-wpcodetool'),
                500
            );
        }

        return $result['payload'];
    }

    public function deleteOptionResource(ResourceDefinition $resource, $request)
    {
        unset($request);

        $store = new OptionStore($resource->optionName(), $resource->optionDefaults(), $resource->optionAutoload());

        return array(
            'deleted' => $store->delete(),
        );
    }

    public function dispatchCustomRoute(ResourceDefinition $resource, $name, $request)
    {
        return $this->custom_routes->dispatch($resource, $name, $request);
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
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
     * @return array<string,mixed>|null
     */
    private function accessClientFromBearerToken($provided)
    {
        if ($provided === '') {
            return null;
        }

        if (array_key_exists($provided, $this->access_client_cache)) {
            return $this->access_client_cache[$provided];
        }

        $this->access_client_cache[$provided] = $this->access_tokens->resolveClient($provided);

        return $this->access_client_cache[$provided];
    }

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
}
