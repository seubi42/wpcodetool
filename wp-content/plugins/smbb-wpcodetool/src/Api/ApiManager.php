<?php

namespace Smbb\WpCodeTool\Api;

use Smbb\WpCodeTool\Resource\ResourceMutationService;
use Smbb\WpCodeTool\Resource\ResourceRuntime;
use Smbb\WpCodeTool\Resource\ResourceScanner;

defined('ABSPATH') || exit;

/**
 * Branche l'API REST CodeTool a l'aide de collaborateurs specialises.
 */
final class ApiManager
{
    private $scanner;
    private $registrar;
    private $resources = array();
    private $errors = array();

    public function __construct(ResourceScanner $scanner)
    {
        $this->scanner = $scanner;

        $api = new ApiHelper();
        $runtime = new ResourceRuntime();
        $mutations = new ResourceMutationService($runtime);
        $visibility = new ApiVisibilitySettings();
        $scopes = new ApiScopeAuthorizer();
        $access_tokens = new ApiAccessTokenStore(null, $scopes);
        $clients = new ApiClientStore(null, $scopes);

        $core = new ApiCoreController(
            $api,
            new OpenApiBuilder(),
            $clients,
            $access_tokens,
            $visibility
        );
        $resource_requests = new ApiResourceRequestReader($runtime);
        $custom_routes = new ApiCustomRouteDispatcher($api, $runtime);
        $resources = new ApiResourceController(
            $api,
            $runtime,
            $mutations,
            new ApiWriteSemantics(),
            $access_tokens,
            new ApiTokenStore(),
            $scopes,
            $resource_requests,
            $custom_routes
        );

        $this->registrar = new ApiRouteRegistrar($core, $resources, $runtime);
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
        $this->registrar->register($this->resources);
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
