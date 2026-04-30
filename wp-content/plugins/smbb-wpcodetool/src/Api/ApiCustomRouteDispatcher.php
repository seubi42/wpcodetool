<?php

namespace Smbb\WpCodeTool\Api;

use Smbb\WpCodeTool\Resource\ResourceDefinition;
use Smbb\WpCodeTool\Resource\ResourceRuntime;
use Smbb\WpCodeTool\Store\OptionStore;
use Smbb\WpCodeTool\Store\OptionStoreInterface;
use Smbb\WpCodeTool\Store\TableStore;
use Smbb\WpCodeTool\Store\TableStoreInterface;

defined('ABSPATH') || exit;

/**
 * Charge et execute les callbacks REST custom declares par les ressources.
 */
final class ApiCustomRouteDispatcher
{
    private $api;
    private $runtime;
    private $instances = array();

    public function __construct(ApiHelper $api = null, ResourceRuntime $runtime = null)
    {
        $this->api = $api ?: new ApiHelper();
        $this->runtime = $runtime ?: new ResourceRuntime();
    }

    public function dispatch(ResourceDefinition $resource, $name, $request)
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

        $instance = $this->instanceFor($resource, $name, $route['class']);

        if (!$instance || !is_callable(array($instance, $route['callback']))) {
            return $this->api->error('missing_callback', __('The custom API callback is not callable.', 'smbb-wpcodetool'), 500);
        }

        return call_user_func(array($instance, $route['callback']), $request, array(
            'action' => 'api_custom_' . $name,
            'hooks' => $this->runtime->hooksFor($resource),
            'request' => $request,
            'resource' => $resource,
            'store' => $this->storeFor($resource),
        ));
    }

    /**
     * @return TableStoreInterface|OptionStoreInterface|null
     */
    private function storeFor(ResourceDefinition $resource)
    {
        if ($resource->storageType() === 'custom_table') {
            return new TableStore($resource);
        }

        if ($resource->storageType() === 'option') {
            return new OptionStore($resource->optionName(), $resource->optionDefaults(), $resource->optionAutoload());
        }

        return null;
    }

    private function instanceFor(ResourceDefinition $resource, $name, $class)
    {
        $cache_key = $resource->name() . ':' . $name;

        if (array_key_exists($cache_key, $this->instances)) {
            return $this->instances[$cache_key];
        }

        try {
            $reflection = new \ReflectionClass($class);
            $constructor = $reflection->getConstructor();

            if ($constructor === null || $constructor->getNumberOfRequiredParameters() === 0) {
                $this->instances[$cache_key] = $reflection->newInstance();
            } elseif ($constructor->getNumberOfRequiredParameters() === 1) {
                $this->instances[$cache_key] = $reflection->newInstance($this->api);
            } else {
                $this->instances[$cache_key] = null;
            }
        } catch (\ReflectionException $exception) {
            $this->instances[$cache_key] = null;
        }

        return $this->instances[$cache_key];
    }
}
