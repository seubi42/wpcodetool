<?php

namespace Smbb\Sample\CodeTool;

defined('ABSPATH') || exit;

/**
 * Code-behind d'exemple pour une page admin UX-only.
 *
 * Cette classe montre le pattern vise :
 * - le moteur construit un contexte standard ;
 * - viewContext() peut faire des calculs, des aggregations, ou appeler d'autres services ;
 * - la view recoit ensuite un $context enrichi, sans embarquer toute la logique metier.
 */
final class SampleDashboardHooks
{
    /**
     * Enrichit le contexte injecte dans la view dashboard.
     *
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    public function viewContext(array $context = array())
    {
        $resources = isset($context['resources']) && is_array($context['resources']) ? array_values($context['resources']) : array();
        $resource_items = array();
        $table_count = 0;
        $option_count = 0;
        $page_count = 0;

        foreach ($resources as $resource) {
            if (!$resource instanceof \Smbb\WpCodeTool\Resource\ResourceDefinition) {
                continue;
            }

            $resource_items[] = $resource;

            if ($resource->storageType() === 'custom_table') {
                $table_count++;
                continue;
            }

            if ($resource->storageType() === 'option') {
                $option_count++;
                continue;
            }

            if ($resource->storageType() === 'none') {
                $page_count++;
            }
        }

        return array(
            'dashboard' => array(
                'resource_items' => $resource_items,
                'metrics' => array(
                    array(
                        'label' => __('Detected resources', 'smbb-sample'),
                        'value' => count($resource_items),
                        'copy' => __('All CodeTool resources currently discovered on active plugins.', 'smbb-sample'),
                    ),
                    array(
                        'label' => __('Table resources', 'smbb-sample'),
                        'value' => $table_count,
                        'copy' => __('CRUD screens backed by custom SQL tables.', 'smbb-sample'),
                    ),
                    array(
                        'label' => __('Option resources', 'smbb-sample'),
                        'value' => $option_count,
                        'copy' => __('Singleton screens persisted in wp_options.', 'smbb-sample'),
                    ),
                    array(
                        'label' => __('Pure UX pages', 'smbb-sample'),
                        'value' => $page_count,
                        'copy' => __('Admin pages rendered without managed storage.', 'smbb-sample'),
                    ),
                ),
                'storage_labels' => array(
                    'custom_table' => __('Custom table', 'smbb-sample'),
                    'option' => __('Option', 'smbb-sample'),
                    'none' => __('UI only', 'smbb-sample'),
                ),
            ),
        );
    }
}
