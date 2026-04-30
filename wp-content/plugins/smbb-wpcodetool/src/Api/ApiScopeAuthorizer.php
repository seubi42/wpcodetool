<?php

namespace Smbb\WpCodeTool\Api;

use Smbb\WpCodeTool\Resource\ResourceDefinition;

defined('ABSPATH') || exit;

/**
 * Normalise et evalue les scopes des clients API geres par CodeTool.
 */
final class ApiScopeAuthorizer
{
    /**
     * @return array<int,string>
     */
    public function defaultScopes()
    {
        return array('*');
    }

    /**
     * @param mixed $value
     * @return array<int,string>
     */
    public function normalizeScopeList($value)
    {
        $items = array();
        $use_default_on_empty = false;

        if (is_string($value)) {
            $trimmed = trim($value);

            if ($trimmed === '') {
                return $this->defaultScopes();
            }

            $use_default_on_empty = false;

            $decoded = json_decode($trimmed, true);

            if (is_array($decoded)) {
                $items = $decoded;
            } else {
                $items = preg_split('/[\r\n,]+/', $trimmed) ?: array();
            }
        } elseif (is_array($value)) {
            $items = $value;
        } elseif ($value === null) {
            return $this->defaultScopes();
        } else {
            $items = array($value);
        }

        $normalized = array();

        foreach ($items as $item) {
            $scope = trim((string) $item);

            if ($scope === '') {
                continue;
            }

            if ($scope === '*') {
                $normalized['*'] = '*';
                continue;
            }

            $parts = explode(':', $scope, 3);

            if (count($parts) === 2) {
                $parts[] = '*';
            }

            if (count($parts) !== 3) {
                continue;
            }

            $kind = sanitize_key($parts[0]);
            $target = $kind === 'namespace' ? $this->normalizeNamespace($parts[1]) : sanitize_key($parts[1]);
            $action = $this->normalizeAction($parts[2]);

            if (!in_array($kind, array('resource', 'namespace'), true) || $target === '' || $action === '') {
                continue;
            }

            $normalized[$kind . ':' . $target . ':' . $action] = $kind . ':' . $target . ':' . $action;
        }

        if ($normalized) {
            return array_values($normalized);
        }

        return $use_default_on_empty ? $this->defaultScopes() : array();
    }

    /**
     * @param mixed $value
     */
    public function textareaValue($value)
    {
        return implode("\n", $this->normalizeScopeList($value));
    }

    public function normalizeAction($action)
    {
        if (trim((string) $action) === '*') {
            return '*';
        }

        $raw = strtoupper(trim((string) $action));

        if (in_array($raw, array('GET', 'HEAD'), true)) {
            return 'read';
        }

        if (in_array($raw, array('POST', 'PUT', 'PATCH'), true)) {
            return 'write';
        }

        if ($raw === 'DELETE') {
            return 'delete';
        }

        $action = sanitize_key((string) $action);

        switch ($action) {
            case '*':
            case 'all':
                return '*';

            case 'list':
            case 'get':
            case 'read':
                return 'read';

            case 'create':
            case 'patch':
            case 'put':
            case 'write':
            case 'post':
                return 'write';

            case 'delete':
            case 'remove':
                return 'delete';

            default:
                return '';
        }
    }

    /**
     * @param array<string,mixed> $client
     */
    public function clientAllows(array $client, ResourceDefinition $resource, $action)
    {
        $scopes = isset($client['scopes']) ? $this->normalizeScopeList($client['scopes']) : $this->defaultScopes();
        $action = $this->normalizeAction($action);

        if ($action === '') {
            return false;
        }

        if (in_array('*', $scopes, true)) {
            return true;
        }

        $resource_name = sanitize_key($resource->name());
        $namespace = $resource->apiNamespace();
        $candidates = array(
            'resource:' . $resource_name . ':*',
            'resource:' . $resource_name . ':' . $action,
            'namespace:' . $namespace . ':*',
            'namespace:' . $namespace . ':' . $action,
        );

        foreach ($candidates as $candidate) {
            if (in_array($candidate, $scopes, true)) {
                return true;
            }
        }

        return false;
    }

    private function normalizeNamespace($namespace)
    {
        $segments = array_filter(
            array_map('sanitize_key', explode('/', trim(str_replace('\\', '/', (string) $namespace), '/')))
        );

        return $segments ? implode('/', $segments) : '';
    }
}
