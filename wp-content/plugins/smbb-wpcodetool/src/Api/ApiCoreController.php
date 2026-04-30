<?php

namespace Smbb\WpCodeTool\Api;

defined('ABSPATH') || exit;

/**
 * Regroupe les endpoints coeur et les regles de visibilite OpenAPI.
 */
final class ApiCoreController
{
    private $api;
    private $openapi;
    private $api_clients;
    private $access_tokens;
    private $visibility;

    public function __construct(
        ApiHelper $api = null,
        OpenApiBuilder $openapi = null,
        ApiClientStore $api_clients = null,
        ApiAccessTokenStore $access_tokens = null,
        ApiVisibilitySettings $visibility = null
    ) {
        $this->api = $api ?: new ApiHelper();
        $this->openapi = $openapi ?: new OpenApiBuilder();
        $this->api_clients = $api_clients ?: new ApiClientStore();
        $this->access_tokens = $access_tokens ?: new ApiAccessTokenStore();
        $this->visibility = $visibility ?: new ApiVisibilitySettings();
    }

    public function issueAccessToken($request)
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
     * @param array<int,\Smbb\WpCodeTool\Resource\ResourceDefinition> $resources
     */
    public function buildNamespaceOpenApi($namespace, array $resources)
    {
        return $this->openapi->build($namespace, $resources);
    }

    /**
     * @param array<string,mixed> $namespaces
     */
    public function buildAggregateOpenApi(array $namespaces)
    {
        $visible = $this->visibility->filterVisibleNamespaces($namespaces);

        if (!$visible) {
            return $this->api->error(
                'openapi_forbidden',
                __('You are not allowed to view the OpenAPI documentation.', 'smbb-wpcodetool'),
                403
            );
        }

        return $this->openapi->buildAggregate($visible);
    }

    public function authorizeOpenApiNamespace($namespace)
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
     * @param array<string,mixed> $namespaces
     */
    public function authorizeOpenApiAggregate(array $namespaces)
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
}
