<?php

namespace Smbb\WpCodeTool\Api;

defined('ABSPATH') || exit;

/**
 * Stores and resolves OpenAPI/Swagger visibility rules per namespace.
 */
final class ApiVisibilitySettings
{
    private const OPTION_NAME = 'smbb_wpcodetool_api_visibility';

    /**
     * Return all stored settings.
     */
    public function all()
    {
        $value = get_option(self::OPTION_NAME, array());

        return is_array($value) ? $value : array();
    }

    /**
     * Return one namespace setting merged with defaults.
     */
    public function forNamespace($namespace)
    {
        $all = $this->all();
        $namespace = (string) $namespace;
        $stored = isset($all[$namespace]) && is_array($all[$namespace]) ? $all[$namespace] : array();

        return array(
            'visibility' => $this->sanitizeVisibility(isset($stored['visibility']) ? $stored['visibility'] : 'public'),
            'capability' => $this->sanitizeCapability(isset($stored['capability']) ? $stored['capability'] : 'manage_options'),
        );
    }

    /**
     * Update settings for the allowed namespaces only.
     *
     * @param array $submitted            Raw submitted settings.
     * @param array $allowed_namespaces   Namespace strings detected by the scanner.
     * @return array
     */
    public function updateMany(array $submitted, array $allowed_namespaces)
    {
        $normalized = array();

        foreach (array_values(array_unique(array_filter(array_map('strval', $allowed_namespaces)))) as $namespace) {
            $row = isset($submitted[$namespace]) && is_array($submitted[$namespace]) ? $submitted[$namespace] : array();

            $normalized[$namespace] = array(
                'visibility' => $this->sanitizeVisibility(isset($row['visibility']) ? $row['visibility'] : 'public'),
                'capability' => $this->sanitizeCapability(isset($row['capability']) ? $row['capability'] : 'manage_options'),
            );
        }

        update_option(self::OPTION_NAME, $normalized, false);

        return $normalized;
    }

    /**
     * Check whether the current request can see one namespace doc.
     */
    public function currentUserCanView($namespace)
    {
        $setting = $this->forNamespace($namespace);

        if ($setting['visibility'] === 'hidden') {
            return false;
        }

        if ($setting['visibility'] === 'capability') {
            return current_user_can($setting['capability']);
        }

        return true;
    }

    /**
     * Keep only namespaces visible to the current request.
     *
     * @param array<string,mixed> $resources_by_namespace
     * @return array<string,mixed>
     */
    public function filterVisibleNamespaces(array $resources_by_namespace)
    {
        $visible = array();

        foreach ($resources_by_namespace as $namespace => $resources) {
            if ($this->currentUserCanView($namespace)) {
                $visible[$namespace] = $resources;
            }
        }

        return $visible;
    }

    /**
     * Allowed visibility values.
     */
    private function sanitizeVisibility($value)
    {
        $value = sanitize_key((string) $value);

        return in_array($value, array('public', 'capability', 'hidden'), true) ? $value : 'public';
    }

    /**
     * Capability fallback.
     */
    private function sanitizeCapability($value)
    {
        $value = sanitize_key((string) $value);

        return $value !== '' ? $value : 'manage_options';
    }
}
