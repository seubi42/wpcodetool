<?php

namespace Smbb\WpCodeTool\Api;

use Smbb\WpCodeTool\Resource\ResourceDefinition;
use Smbb\WpCodeTool\Resource\ResourceRuntime;

defined('ABSPATH') || exit;

/**
 * Lit et normalise les donnees utiles d'une requete REST CodeTool.
 */
final class ApiResourceRequestReader
{
    private $runtime;

    public function __construct(ResourceRuntime $runtime = null)
    {
        $this->runtime = $runtime ?: new ResourceRuntime();
    }

    /**
     * @return array<string,mixed>
     */
    public function tableData(ResourceDefinition $resource, $request)
    {
        $payload = $this->payload($request);
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
     * @return array<string,mixed>
     */
    public function optionData($request)
    {
        return $this->payload($request);
    }

    public function tableSearchTerm(ResourceDefinition $resource, $request)
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
     * @return array<string,string>
     */
    public function tableFilterArgs(ResourceDefinition $resource, $request)
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

    public function perPage(ResourceDefinition $resource, $request)
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
     * @return int|string|null
     */
    public function resourceId(ResourceDefinition $resource, $request)
    {
        $primary_key = $resource->primaryKey();
        $value = $request->get_param($primary_key);

        if ($value === null || $value === '') {
            return null;
        }

        return $this->runtime->primaryKeyIsNumeric($resource) ? (int) $value : (string) $value;
    }

    public function bearerToken($request)
    {
        $header = '';

        if (is_object($request) && method_exists($request, 'get_header')) {
            $header = (string) $request->get_header('authorization');
        }

        if ($header === '' && isset($_SERVER['HTTP_AUTHORIZATION'])) {
            $header = (string) wp_unslash($_SERVER['HTTP_AUTHORIZATION']);
        }

        if (!preg_match('/^Bearer\s+(.+)$/i', trim($header), $matches)) {
            return '';
        }

        return trim((string) $matches[1]);
    }

    /**
     * @return array<string,mixed>
     */
    private function payload($request)
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
}
