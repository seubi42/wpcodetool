<?php

/**
 * Example of a pure UX admin page backed by a CodeTool JSON model with storage.type=none.
 *
 * @var array<string,mixed> $context
 * @var \Smbb\WpCodeTool\Admin\Dashboard $dashboard_ui
 * @var \Smbb\WpCodeTool\Resource\ResourceDefinition $resource
 * @var array<string,\Smbb\WpCodeTool\Resource\ResourceDefinition> $resources
 * @var string $page_header_html
 * @var string $notices_html
 */

defined('ABSPATH') || exit;

$dashboard_data = isset($context['dashboard']) && is_array($context['dashboard']) ? $context['dashboard'] : array();
$resource_items = isset($dashboard_data['resource_items']) && is_array($dashboard_data['resource_items'])
    ? $dashboard_data['resource_items']
    : (isset($resources) && is_array($resources) ? array_values($resources) : array());
$metrics = isset($dashboard_data['metrics']) && is_array($dashboard_data['metrics']) ? $dashboard_data['metrics'] : array();
$storage_labels = isset($dashboard_data['storage_labels']) && is_array($dashboard_data['storage_labels']) ? $dashboard_data['storage_labels'] : array(
    'custom_table' => __('Custom table', 'smbb-sample'),
    'option' => __('Option', 'smbb-sample'),
    'none' => __('UI only', 'smbb-sample'),
);

$resolve_link = static function (array $link) {
    if (!empty($link['page'])) {
        return admin_url('admin.php?page=' . sanitize_text_field((string) $link['page']));
    }

    if (!empty($link['url'])) {
        return esc_url_raw((string) $link['url']);
    }

    return '';
};

$map_links = static function (array $items) use ($resolve_link) {
    $links = array();

    foreach ($items as $item) {
        if (!is_array($item) || empty($item['label'])) {
            continue;
        }

        $url = $resolve_link($item);

        if ($url === '') {
            continue;
        }

        $item['url'] = $url;
        $links[] = $item;
    }

    return $links;
};

$hero_config = array(
    'eyebrow' => __('Pure UX page', 'smbb-sample'),
    'badge' => __('UI first', 'smbb-sample'),
    'title' => __('Sample operations cockpit', 'smbb-sample'),
    'description' => __('This screen is declared by a JSON model, rendered by a PHP view, and does not require a SQL table or a wp_options object.', 'smbb-sample'),
    'actions' => $map_links(array(
        array(
            'label' => __('Open tests', 'smbb-sample'),
            'page' => 'smbb-codetool-test',
            'variant' => 'secondary',
        ),
        array(
            'label' => __('Open settings', 'smbb-sample'),
            'page' => 'smbb-codetool-sample_settings',
            'variant' => 'primary',
        ),
    )),
    'tiles' => array(
        array(
            'icon' => 'dashicons-database',
            'label' => __('Custom table CRUD', 'smbb-sample'),
            'meta' => __('List, form, details', 'smbb-sample'),
        ),
        array(
            'icon' => 'dashicons-admin-settings',
            'label' => __('Singleton settings', 'smbb-sample'),
            'meta' => __('wp_options backed', 'smbb-sample'),
        ),
        array(
            'icon' => 'dashicons-layout',
            'label' => __('UI-only dashboard', 'smbb-sample'),
            'meta' => __('No managed storage', 'smbb-sample'),
        ),
        array(
            'icon' => 'dashicons-rest-api',
            'label' => __('REST routes', 'smbb-sample'),
            'meta' => __('OpenAPI ready', 'smbb-sample'),
        ),
        array(
            'icon' => 'dashicons-editor-code',
            'label' => __('Model-driven menus', 'smbb-sample'),
            'meta' => __('JSON declared', 'smbb-sample'),
        ),
        array(
            'icon' => 'dashicons-screenoptions',
            'label' => __('Custom views', 'smbb-sample'),
            'meta' => __('PHP remains free', 'smbb-sample'),
        ),
    ),
    'footer' => array(
        'text' => __('Designed for WordPress builders who want declarative models without losing control of the UX.', 'smbb-sample'),
        'link' => array(
            'label' => __('Open CodeTool overview', 'smbb-sample'),
            'url' => $resolve_link(array('page' => 'smbb-wpcodetool')),
        ),
    ),
);

$quick_links_items = $map_links(array(
    array(
        'label' => __('Open tests', 'smbb-sample'),
        'page' => 'smbb-codetool-test',
        'description' => __('Jump to the custom-table CRUD sample.', 'smbb-sample'),
    ),
    array(
        'label' => __('Open settings', 'smbb-sample'),
        'page' => 'smbb-codetool-sample_settings',
        'description' => __('Open the option-backed singleton settings page.', 'smbb-sample'),
    ),
    array(
        'label' => __('Open CodeTool resources', 'smbb-sample'),
        'page' => 'smbb-wpcodetool-resources',
        'description' => __('Inspect every resource discovered by the scanner.', 'smbb-sample'),
    ),
));

$highlights = array(
    __('One JSON model creates a real WordPress menu entry.', 'smbb-sample'),
    __('The page view stays free to render any dashboard or bespoke layout.', 'smbb-sample'),
    __('No managed storage is required when the page is only UX.', 'smbb-sample'),
);

$resource_columns = array(
    array(
        'label' => __('Resource', 'smbb-sample'),
        'render' => static function ($resource_item) {
            if (!$resource_item instanceof \Smbb\WpCodeTool\Resource\ResourceDefinition) {
                return '';
            }

            $open_url = admin_url('admin.php?page=' . $resource_item->adminSlug());

            return sprintf(
                '<a href="%1$s">%2$s</a>',
                esc_url($open_url),
                esc_html($resource_item->menuTitle())
            );
        },
    ),
    array(
        'label' => __('Admin type', 'smbb-sample'),
        'render' => static function ($resource_item) {
            if (!$resource_item instanceof \Smbb\WpCodeTool\Resource\ResourceDefinition) {
                return '';
            }

            return '<code>' . esc_html($resource_item->adminType()) . '</code>';
        },
    ),
    array(
        'label' => __('Storage', 'smbb-sample'),
        'render' => static function ($resource_item) use ($storage_labels) {
            if (!$resource_item instanceof \Smbb\WpCodeTool\Resource\ResourceDefinition) {
                return '';
            }

            $storage_type = $resource_item->storageType();

            return esc_html(isset($storage_labels[$storage_type]) ? $storage_labels[$storage_type] : $storage_type);
        },
    ),
    array(
        'label' => __('Open', 'smbb-sample'),
        'render' => static function ($resource_item) {
            if (!$resource_item instanceof \Smbb\WpCodeTool\Resource\ResourceDefinition) {
                return '';
            }

            $open_url = admin_url('admin.php?page=' . $resource_item->adminSlug());

            return sprintf(
                '<a class="button" href="%1$s">%2$s</a>',
                esc_url($open_url),
                esc_html__('Open', 'smbb-sample')
            );
        },
    ),
);

echo '<div class="wrap smbb-codetool">';
echo $page_header_html;

if (!empty($notices_html)) {
    echo $notices_html;
}

echo $dashboard_ui->hero($hero_config);
echo $dashboard_ui->metrics($metrics);

echo $dashboard_ui->split(array(
    $dashboard_ui->panel(
        __('Quick links', 'smbb-sample'),
        __('The JSON model can declare the menu entry, while the PHP view stays in charge of the UX layout.', 'smbb-sample'),
        $dashboard_ui->linkList($quick_links_items, __('No quick links declared in the JSON model.', 'smbb-sample'))
    ),
    $dashboard_ui->panel(
        __('What this resource declares', 'smbb-sample'),
        '',
        $dashboard_ui->definitionTable(array(
            array(
                'label' => __('Model file', 'smbb-sample'),
                'html' => '<code>' . esc_html(wp_basename($resource->modelFile())) . '</code>',
            ),
            array(
                'label' => __('Admin type', 'smbb-sample'),
                'html' => '<code>' . esc_html($resource->adminType()) . '</code>',
            ),
            array(
                'label' => __('Storage type', 'smbb-sample'),
                'html' => '<code>' . esc_html($resource->storageType()) . '</code>',
            ),
            array(
                'label' => __('Default view', 'smbb-sample'),
                'html' => '<code>' . esc_html(method_exists($resource, 'defaultAdminView') ? $resource->defaultAdminView() : 'dashboard') . '</code>',
            ),
        )) . $dashboard_ui->bullets($highlights),
        array('tag' => 'aside')
    ),
));

echo $dashboard_ui->panel(
    __('Detected resources', 'smbb-sample'),
    __('This table is read-only here: the page itself has no managed storage, but it can still consume runtime information and link to other screens.', 'smbb-sample'),
    $dashboard_ui->table(
        $resource_columns,
        array_values(array_filter($resource_items, static function ($resource_item) {
            return $resource_item instanceof \Smbb\WpCodeTool\Resource\ResourceDefinition && $resource_item->adminEnabled();
        })),
        array(
            'empty_message' => __('No resources detected yet.', 'smbb-sample'),
        )
    )
);

echo '</div>';
