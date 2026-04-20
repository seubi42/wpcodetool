<?php

namespace Smbb\WpCodeTool\Api;

use Smbb\WpCodeTool\Resource\ResourceDefinition;
use Smbb\WpCodeTool\Resource\ResourceScanner;

defined('ABSPATH') || exit;

/**
 * Frontend Swagger UI renderer via shortcode.
 */
final class ApiDocsShortcode
{
    private $scanner;
    private $visibility;
    private $resources = array();
    private $errors = array();

    public function __construct(ResourceScanner $scanner)
    {
        $this->scanner = $scanner;
        $this->visibility = new ApiVisibilitySettings();
    }

    /**
     * Register shortcode hooks.
     */
    public function hooks()
    {
        add_shortcode('smbb_codetool_api_docs', array($this, 'render'));
        add_shortcode('smbb_codetool_swagger', array($this, 'render'));
    }

    /**
     * Render Swagger UI for one namespace.
     */
    public function render($atts = array())
    {
        static $assets_rendered = false;

        $atts = shortcode_atts(array(
            'namespace' => '',
            'title' => '',
            'height' => '75vh',
            'spec_url' => '',
        ), is_array($atts) ? $atts : array(), 'smbb_codetool_api_docs');

        $this->ensureResources();

        $explicit_namespace = $atts['namespace'] !== '';
        $has_custom_spec = $atts['spec_url'] !== '';
        $namespace = $explicit_namespace
            ? sanitize_text_field((string) $atts['namespace'])
            : ($has_custom_spec ? '' : $this->defaultNamespace());
        $render_all = !$explicit_namespace && !$has_custom_spec;

        if (!$render_all && !$has_custom_spec && $namespace === '') {
            return '<p>' . esc_html__('No API namespace was provided and none could be inferred.', 'smbb-wpcodetool') . '</p>';
        }

        if (!$render_all && !$has_custom_spec && !$this->namespaceExists($namespace)) {
            return '<p>' . esc_html(sprintf(__('Unknown CodeTool API namespace: %s', 'smbb-wpcodetool'), $namespace)) . '</p>';
        }

        if (!$render_all && !$has_custom_spec && !$this->visibility->currentUserCanView($namespace)) {
            return '<p>' . esc_html__('You are not allowed to view this API documentation.', 'smbb-wpcodetool') . '</p>';
        }

        if ($render_all && !$this->visibleNamespaces()) {
            return '<p>' . esc_html__('No API documentation is visible for the current visitor.', 'smbb-wpcodetool') . '</p>';
        }

        $spec_url = $atts['spec_url'] !== ''
            ? esc_url_raw((string) $atts['spec_url'])
            : ($render_all ? $this->aggregateSpecUrl() : $this->namespaceSpecUrl($namespace));
        $title = $atts['title'] !== ''
            ? (string) $atts['title']
            : ($render_all
                ? $this->allNamespacesTitle()
                : ($namespace !== '' ? $this->namespaceTitle($namespace) : __('API documentation', 'smbb-wpcodetool')));
        $height = preg_match('/^\d+(px|vh|vw|%)$/', (string) $atts['height']) === 1 ? (string) $atts['height'] : '75vh';
        $container_id = 'smbb-codetool-swagger-' . wp_generate_password(8, false, false);
        $css_url = esc_url(apply_filters(
            'smbb_wpcodetool_swagger_ui_css_url',
            'https://unpkg.com/swagger-ui-dist@5/swagger-ui.css'
        ));
        $bundle_url = esc_url(apply_filters(
            'smbb_wpcodetool_swagger_ui_bundle_url',
            'https://unpkg.com/swagger-ui-dist@5/swagger-ui-bundle.js'
        ));

        ob_start();

        if (!$assets_rendered) {
            $assets_rendered = true;
            ?>
            <link rel="stylesheet" href="<?php echo esc_url($css_url); ?>">
            <script src="<?php echo esc_url($bundle_url); ?>"></script>
            <?php
        }
        ?>
        <div class="smbb-codetool-swagger-wrap">
            <div class="smbb-codetool-swagger-header">
                <h2><?php echo esc_html($title); ?></h2>
                <p><code><?php echo esc_html($spec_url); ?></code></p>
                <?php if ($render_all) : ?>
                    <p><?php echo esc_html($this->allNamespacesSummary()); ?></p>
                <?php endif; ?>
            </div>
            <div id="<?php echo esc_attr($container_id); ?>" class="smbb-codetool-swagger-ui" style="<?php echo esc_attr('min-height:' . $height . ';'); ?>"></div>
        </div>
        <script>
        (function () {
            function bootSwagger() {
                if (typeof window.SwaggerUIBundle !== 'function') {
                    return;
                }

                window.SwaggerUIBundle({
                    url: <?php echo wp_json_encode($spec_url); ?>,
                    dom_id: <?php echo wp_json_encode('#' . $container_id); ?>,
                    deepLinking: true,
                    persistAuthorization: true,
                    displayRequestDuration: true,
                    docExpansion: 'list',
                    presets: [
                        window.SwaggerUIBundle.presets.apis
                    ],
                    layout: 'BaseLayout'
                });
            }

            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', bootSwagger);
            } else {
                bootSwagger();
            }
        })();
        </script>
        <?php

        return (string) ob_get_clean();
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

    /**
     * Choose a namespace automatically when there is a single obvious candidate.
     */
    private function defaultNamespace()
    {
        $visible = array_keys($this->visibleNamespaces());

        return count($visible) === 1 ? $visible[0] : '';
    }

    /**
     * Group API-enabled resources by namespace.
     *
     * @return array<string, ResourceDefinition[]>
     */
    private function namespaces()
    {
        $namespaces = array();

        foreach ($this->resources as $resource) {
            if (!$resource instanceof ResourceDefinition || !$resource->apiEnabled()) {
                continue;
            }

            $namespaces[$resource->apiNamespace()][] = $resource;
        }

        ksort($namespaces);

        return $namespaces;
    }

    /**
     * Check whether a namespace exists in scanned resources.
     */
    private function namespaceExists($namespace)
    {
        return isset($this->namespaces()[$namespace]);
    }

    /**
     * Namespaces visible to the current request according to admin settings.
     *
     * @return array<string, ResourceDefinition[]>
     */
    private function visibleNamespaces()
    {
        return $this->visibility->filterVisibleNamespaces($this->namespaces());
    }

    /**
     * Human title for the current namespace.
     */
    private function namespaceTitle($namespace)
    {
        $resources = $this->namespaces();

        if (empty($resources[$namespace])) {
            return sprintf(__('API documentation: %s', 'smbb-wpcodetool'), $namespace);
        }

        $label = count($resources[$namespace]) === 1
            ? $resources[$namespace][0]->pluralLabel()
            : $namespace;

        return sprintf(__('API documentation: %s', 'smbb-wpcodetool'), $label);
    }

    /**
     * Aggregate OpenAPI endpoint used when the shortcode has no namespace.
     */
    private function aggregateSpecUrl()
    {
        return $this->signedRestUrl(rest_url('smbb-wpcodetool/v1/openapi-all'));
    }

    /**
     * Namespace OpenAPI endpoint used by the shortcode.
     */
    private function namespaceSpecUrl($namespace)
    {
        return $this->signedRestUrl(rest_url($namespace . '/openapi'));
    }

    /**
     * Human title for aggregate documentation.
     */
    private function allNamespacesTitle()
    {
        return __('API documentation: all CodeTool namespaces', 'smbb-wpcodetool');
    }

    /**
     * Human summary of included namespaces.
     */
    private function allNamespacesSummary()
    {
        $namespaces = array_keys($this->visibleNamespaces());

        if (!$namespaces) {
            return __('No API documentation is visible for the current visitor.', 'smbb-wpcodetool');
        }

        return sprintf(
            __('Included namespaces: %s', 'smbb-wpcodetool'),
            implode(', ', $namespaces)
        );
    }

    /**
     * Add the REST nonce for the current logged-in session when available.
     */
    private function signedRestUrl($url)
    {
        if (!is_user_logged_in()) {
            return $url;
        }

        $nonce = wp_create_nonce('wp_rest');

        if ($nonce === '') {
            return $url;
        }

        return add_query_arg('_wpnonce', $nonce, $url);
    }
}
