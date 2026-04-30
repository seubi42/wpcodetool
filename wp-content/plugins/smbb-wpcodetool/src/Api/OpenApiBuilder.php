<?php

namespace Smbb\WpCodeTool\Api;

defined('ABSPATH') || exit;

/**
 * Orchestre la generation d'un document OpenAPI a partir des ressources CodeTool.
 */
final class OpenApiBuilder
{
    private const OPENAPI_VERSION = '3.0.3';

    private $paths;
    private $schemas;

    public function __construct(OpenApiPathBuilder $paths = null, OpenApiSchemaBuilder $schemas = null)
    {
        $this->schemas = $schemas ?: new OpenApiSchemaBuilder();
        $this->paths = $paths ?: new OpenApiPathBuilder($this->schemas);
    }

    /**
     * Build an OpenAPI 3 document for one WordPress REST namespace.
     *
     * @param string               $namespace REST namespace, for example smbb-sample/v1.
     * @param ResourceDefinition[] $resources Resources exposed in that namespace.
     * @return array
     */
    public function build($namespace, array $resources)
    {
        $paths = $this->paths->paths($resources);
        $this->paths->appendAuthPaths($paths, false);
        ksort($paths);

        return array(
            'openapi' => self::OPENAPI_VERSION,
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
                    OpenApiPathBuilder::SECURITY_SCHEME => array(),
                ),
            ),
            'paths' => $paths,
            'components' => array(
                'securitySchemes' => array(
                    OpenApiPathBuilder::SECURITY_SCHEME => array(
                        'type' => 'http',
                        'scheme' => 'bearer',
                        'bearerFormat' => 'Token',
                        'description' => $this->bearerSecurityDescription(),
                    ),
                ),
                'schemas' => $this->schemas->schemas($resources),
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
            $component_prefix = $this->schemas->namespaceComponentPrefix($namespace);

            $paths = array_merge($paths, $this->paths->paths($resources, $path_prefix, $component_prefix));
            $schemas = array_merge($schemas, $this->schemas->schemas($resources, $component_prefix));
            $namespaces[] = (string) $namespace;
        }

        $this->paths->appendAuthPaths($paths, true);
        ksort($paths);
        ksort($schemas);

        return array(
            'openapi' => self::OPENAPI_VERSION,
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
                    OpenApiPathBuilder::SECURITY_SCHEME => array(),
                ),
            ),
            'paths' => $paths,
            'components' => array(
                'securitySchemes' => array(
                    OpenApiPathBuilder::SECURITY_SCHEME => array(
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
     * Shared description for the Bearer security scheme.
     */
    private function bearerSecurityDescription()
    {
        return 'Use a bearer access token obtained from POST /' . OpenApiPathBuilder::AUTH_NAMESPACE . '/token.';
    }
}
