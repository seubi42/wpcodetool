<?php

namespace Smbb\WpCodeTool\Admin\Pages;

use Smbb\WpCodeTool\Resource\ResourceDefinition;

defined('ABSPATH') || exit;

final class OverviewPage extends AbstractAdminPage
{
    public function render()
    {
        $manager = $this->manager();
        $manager->ensureResources();
        $manager->addNoticeFromQuery();

        $resources = $manager->resources();
        $resource_count = count($resources);
        $namespace_count = count($manager->apiNamespaces());
        $client_count = $manager->apiClients()->count();
        $resources_url = admin_url('admin.php?page=smbb-wpcodetool-resources');
        $table_resource_count = 0;
        $option_resource_count = 0;
        $ui_page_count = 0;
        $api_resource_count = 0;
        $schema_pending_count = 0;
        $schema_missing_count = 0;
        $schema_update_count = 0;
        $schema_invalid_count = 0;
        $pending_schema_resources = array();

        foreach ($resources as $resource) {
            if ($resource->storageType() === 'custom_table') {
                $table_resource_count++;

                $status = $manager->schema()->status($resource);

                if (in_array($status['state'], array('missing', 'needs_update', 'invalid'), true)) {
                    $schema_pending_count++;

                    if ($status['state'] === 'missing') {
                        $schema_missing_count++;
                    } elseif ($status['state'] === 'needs_update') {
                        $schema_update_count++;
                    } elseif ($status['state'] === 'invalid') {
                        $schema_invalid_count++;
                    }

                    $pending_schema_resources[] = array(
                        'resource' => $resource,
                        'status' => $status,
                    );
                }
            } elseif ($resource->storageType() === 'option') {
                $option_resource_count++;
            } elseif ($resource->storageType() === 'none') {
                $ui_page_count++;
            }

            if ($resource->apiEnabled()) {
                $api_resource_count++;
            }
        }

        $hero_tiles = array(
            array(
                'icon' => 'dashicons-database',
                'label' => __('Custom tables', 'smbb-wpcodetool'),
                'meta' => sprintf(_n('%d resource', '%d resources', $table_resource_count, 'smbb-wpcodetool'), $table_resource_count),
            ),
            array(
                'icon' => 'dashicons-admin-settings',
                'label' => __('Settings pages', 'smbb-wpcodetool'),
                'meta' => sprintf(_n('%d singleton', '%d singletons', $option_resource_count, 'smbb-wpcodetool'), $option_resource_count),
            ),
            array(
                'icon' => 'dashicons-layout',
                'label' => __('UI dashboards', 'smbb-wpcodetool'),
                'meta' => sprintf(_n('%d page', '%d pages', $ui_page_count, 'smbb-wpcodetool'), $ui_page_count),
            ),
            array(
                'icon' => 'dashicons-rest-api',
                'label' => __('REST exposure', 'smbb-wpcodetool'),
                'meta' => sprintf(_n('%d API resource', '%d API resources', $api_resource_count, 'smbb-wpcodetool'), $api_resource_count),
            ),
            array(
                'icon' => 'dashicons-editor-code',
                'label' => __('Resource registry', 'smbb-wpcodetool'),
                'meta' => sprintf(_n('%d model detected', '%d models detected', $resource_count, 'smbb-wpcodetool'), $resource_count),
            ),
            array(
                'icon' => 'dashicons-admin-network',
                'label' => __('Managed clients', 'smbb-wpcodetool'),
                'meta' => sprintf(_n('%d API client', '%d API clients', $client_count, 'smbb-wpcodetool'), $client_count),
            ),
        );

        $quick_access = array(
            array(
                'title' => __('Browse resources', 'smbb-wpcodetool'),
                'description' => __('Inspect detected models, menu placement, schema state, and generated admin pages.', 'smbb-wpcodetool'),
                'url' => $resources_url,
            ),
            array(
                'title' => __('Manage OpenAPI visibility', 'smbb-wpcodetool'),
                'description' => __('Control which namespaces are public, capability-protected, or hidden.', 'smbb-wpcodetool'),
                'url' => admin_url('admin.php?page=smbb-wpcodetool-api'),
            ),
            array(
                'title' => __('Manage API clients', 'smbb-wpcodetool'),
                'description' => __('Issue credentials and inspect the clients allowed to request access tokens.', 'smbb-wpcodetool'),
                'url' => admin_url('admin.php?page=smbb-wpcodetool-api-tokens'),
            ),
        );

        if ($schema_pending_count > 0) {
            array_unshift(
                $quick_access,
                array(
                    'title' => __('Resolve SQL schema updates', 'smbb-wpcodetool'),
                    'description' => sprintf(
                        _n(
                            '%d custom-table resource needs SQL attention before its data layer is fully in sync.',
                            '%d custom-table resources need SQL attention before their data layer is fully in sync.',
                            $schema_pending_count,
                            'smbb-wpcodetool'
                        ),
                        $schema_pending_count
                    ),
                    'url' => $resources_url,
                )
            );
        }

        $schema_attention_variant = ($schema_invalid_count > 0 || $schema_missing_count > 0) ? 'is-error' : 'is-warning';
        $schema_summary = array();

        if ($schema_missing_count > 0) {
            $schema_summary[] = sprintf(
                _n('%d table missing', '%d tables missing', $schema_missing_count, 'smbb-wpcodetool'),
                $schema_missing_count
            );
        }

        if ($schema_update_count > 0) {
            $schema_summary[] = sprintf(
                _n('%d update required', '%d updates required', $schema_update_count, 'smbb-wpcodetool'),
                $schema_update_count
            );
        }

        if ($schema_invalid_count > 0) {
            $schema_summary[] = sprintf(
                _n('%d invalid schema', '%d invalid schemas', $schema_invalid_count, 'smbb-wpcodetool'),
                $schema_invalid_count
            );
        }
        ?>
        <div class="wrap smbb-codetool">
            <?php
            echo $manager->pageIntroHtml(
                __('CodeTool overview', 'smbb-wpcodetool'),
                __('Quick access to resources, API exposure, and managed API clients.', 'smbb-wpcodetool'),
                'dashicons-editor-code',
                $manager->runtimeNoticesHtml()
            );
            ?>

            <section class="smbb-codetool-hero">
                <div class="smbb-codetool-hero-inner">
                    <div class="smbb-codetool-hero-main">
                        <p class="smbb-codetool-hero-eyebrow"><?php esc_html_e('CodeTool Admin', 'smbb-wpcodetool'); ?></p>
                        <h2 class="smbb-codetool-hero-title">
                            <?php esc_html_e('Build custom WordPress admin experiences faster', 'smbb-wpcodetool'); ?>
                            <span class="smbb-codetool-hero-chip"><?php esc_html_e('UI-first', 'smbb-wpcodetool'); ?></span>
                        </h2>
                        <p class="smbb-codetool-hero-desc">
                            <?php esc_html_e('Scan resource models, expose REST namespaces, and ship dashboards, CRUD screens, or settings pages from a single toolkit.', 'smbb-wpcodetool'); ?>
                        </p>

                        <div class="smbb-codetool-hero-actions">
                            <a class="smbb-codetool-hero-button is-secondary" href="<?php echo esc_url($resources_url); ?>">
                                <?php esc_html_e('Browse resources', 'smbb-wpcodetool'); ?>
                                <span class="dashicons dashicons-arrow-right-alt2" aria-hidden="true"></span>
                            </a>
                            <a class="smbb-codetool-hero-button is-primary" href="<?php echo esc_url(admin_url('admin.php?page=smbb-wpcodetool-api')); ?>">
                                <?php esc_html_e('Open API settings', 'smbb-wpcodetool'); ?>
                                <span class="dashicons dashicons-arrow-right-alt2" aria-hidden="true"></span>
                            </a>
                        </div>
                    </div>

                    <div class="smbb-codetool-hero-grid">
                        <?php foreach ($hero_tiles as $tile) : ?>
                            <div class="smbb-codetool-hero-tile">
                                <span class="smbb-codetool-hero-tile-icon">
                                    <span class="dashicons <?php echo esc_attr($tile['icon']); ?>" aria-hidden="true"></span>
                                </span>
                                <div class="smbb-codetool-hero-tile-copy">
                                    <strong class="smbb-codetool-hero-tile-label"><?php echo esc_html($tile['label']); ?></strong>
                                    <span class="smbb-codetool-hero-tile-meta"><?php echo esc_html($tile['meta']); ?></span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="smbb-codetool-hero-footer">
                    <div class="smbb-codetool-hero-footer-copy">
                        <?php esc_html_e('Designed for WordPress builders who want declarative models and bespoke admin UX in the same toolkit.', 'smbb-wpcodetool'); ?>
                    </div>
                    <a class="smbb-codetool-hero-footer-link" href="<?php echo esc_url($resources_url); ?>">
                        <?php esc_html_e('Open resource registry', 'smbb-wpcodetool'); ?>
                        <span class="dashicons dashicons-arrow-right-alt2" aria-hidden="true"></span>
                    </a>
                </div>
            </section>

            <div class="smbb-codetool-dashboard-grid">
                <section class="smbb-codetool-metric <?php echo $schema_pending_count > 0 ? esc_attr($schema_attention_variant) : ''; ?>">
                    <p class="smbb-codetool-metric-label"><?php esc_html_e('Schemas pending', 'smbb-wpcodetool'); ?></p>
                    <p class="smbb-codetool-metric-value"><?php echo esc_html((string) $schema_pending_count); ?></p>
                    <p class="smbb-codetool-metric-copy">
                        <?php
                        echo esc_html(
                            $schema_pending_count > 0
                                ? __('Custom-table resources currently waiting for a schema apply or fix.', 'smbb-wpcodetool')
                                : __('All detected custom-table schemas are currently synchronized.', 'smbb-wpcodetool')
                        );
                        ?>
                    </p>
                </section>

                <section class="smbb-codetool-metric">
                    <p class="smbb-codetool-metric-label"><?php esc_html_e('Detected resources', 'smbb-wpcodetool'); ?></p>
                    <p class="smbb-codetool-metric-value"><?php echo esc_html((string) $resource_count); ?></p>
                    <p class="smbb-codetool-metric-copy"><?php esc_html_e('All active CodeTool models available in this WordPress instance.', 'smbb-wpcodetool'); ?></p>
                </section>

                <section class="smbb-codetool-metric">
                    <p class="smbb-codetool-metric-label"><?php esc_html_e('API namespaces', 'smbb-wpcodetool'); ?></p>
                    <p class="smbb-codetool-metric-value"><?php echo esc_html((string) $namespace_count); ?></p>
                    <p class="smbb-codetool-metric-copy"><?php esc_html_e('REST namespaces currently exposed by detected resources.', 'smbb-wpcodetool'); ?></p>
                </section>

                <section class="smbb-codetool-metric">
                    <p class="smbb-codetool-metric-label"><?php esc_html_e('API clients', 'smbb-wpcodetool'); ?></p>
                    <p class="smbb-codetool-metric-value"><?php echo esc_html((string) $client_count); ?></p>
                    <p class="smbb-codetool-metric-copy"><?php esc_html_e('Managed client credentials allowed to request access tokens.', 'smbb-wpcodetool'); ?></p>
                </section>

                <section class="smbb-codetool-metric">
                    <p class="smbb-codetool-metric-label"><?php esc_html_e('Pure UI pages', 'smbb-wpcodetool'); ?></p>
                    <p class="smbb-codetool-metric-value"><?php echo esc_html((string) $ui_page_count); ?></p>
                    <p class="smbb-codetool-metric-copy"><?php esc_html_e('Admin pages rendered without managed storage.', 'smbb-wpcodetool'); ?></p>
                </section>
            </div>

            <?php if ($schema_pending_count > 0) : ?>
                <section class="smbb-codetool-panel smbb-codetool-panel-attention <?php echo esc_attr($schema_attention_variant); ?>">
                    <div class="smbb-codetool-panel-header">
                        <h2><?php esc_html_e('Schema attention needed', 'smbb-wpcodetool'); ?></h2>
                        <span class="smbb-codetool-badge <?php echo esc_attr($schema_attention_variant); ?>">
                            <?php
                            echo esc_html(
                                sprintf(
                                    _n('%d pending', '%d pending', $schema_pending_count, 'smbb-wpcodetool'),
                                    $schema_pending_count
                                )
                            );
                            ?>
                        </span>
                    </div>

                    <p>
                        <?php
                        echo esc_html(
                            !empty($schema_summary)
                                ? sprintf(
                                    __('Some custom-table resources need SQL synchronization: %s.', 'smbb-wpcodetool'),
                                    implode(', ', $schema_summary)
                                )
                                : __('Some custom-table resources need SQL synchronization.', 'smbb-wpcodetool')
                        );
                        ?>
                    </p>

                    <table class="widefat striped smbb-codetool-table">
                        <thead>
                            <tr>
                                <th><?php esc_html_e('Resource', 'smbb-wpcodetool'); ?></th>
                                <th><?php esc_html_e('Status', 'smbb-wpcodetool'); ?></th>
                                <th><?php esc_html_e('Table', 'smbb-wpcodetool'); ?></th>
                                <th><?php esc_html_e('Actions', 'smbb-wpcodetool'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($pending_schema_resources as $schema_item) : ?>
                                <?php
                                /** @var ResourceDefinition $schema_resource */
                                $schema_resource = $schema_item['resource'];
                                $schema_status = $schema_item['status'];
                                ?>
                                <tr>
                                    <td>
                                        <a href="<?php echo esc_url(admin_url('admin.php?page=' . $schema_resource->adminSlug())); ?>">
                                            <?php echo esc_html($schema_resource->label()); ?>
                                        </a>
                                    </td>
                                    <td>
                                        <span class="<?php echo esc_attr($manager->schemaStatusClass($schema_status)); ?>">
                                            <?php echo esc_html($schema_status['label']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if (!empty($schema_status['table'])) : ?>
                                            <code><?php echo esc_html($schema_status['table']); ?></code>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="smbb-codetool-status-actions">
                                            <a class="button button-small" href="<?php echo esc_url($manager->schemaPreviewUrl($schema_resource)); ?>">
                                                <?php esc_html_e('Preview SQL', 'smbb-wpcodetool'); ?>
                                            </a>

                                            <?php if ($schema_status['state'] !== 'invalid') : ?>
                                                <form class="smbb-codetool-inline-form" method="post" action="<?php echo esc_url($resources_url); ?>">
                                                    <input type="hidden" name="codetool_schema_action" value="apply">
                                                    <input type="hidden" name="resource" value="<?php echo esc_attr($schema_resource->name()); ?>">
                                                    <?php wp_nonce_field('smbb_codetool_schema_apply_' . $schema_resource->name()); ?>
                                                    <?php submit_button(__('Apply', 'smbb-wpcodetool'), 'secondary small', 'submit', false); ?>
                                                </form>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </section>
            <?php endif; ?>

            <div class="smbb-codetool-split">
                <section class="smbb-codetool-panel">
                    <h2><?php esc_html_e('Quick access', 'smbb-wpcodetool'); ?></h2>
                    <p><?php esc_html_e('Jump straight to the main operating areas of the toolkit.', 'smbb-wpcodetool'); ?></p>

                    <ul class="smbb-codetool-link-list">
                        <?php foreach ($quick_access as $item) : ?>
                            <li>
                                <a href="<?php echo esc_url($item['url']); ?>"><?php echo esc_html($item['title']); ?></a>
                                <small class="smbb-codetool-link-meta"><?php echo esc_html($item['description']); ?></small>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </section>

                <aside class="smbb-codetool-panel">
                    <h2><?php esc_html_e('System snapshot', 'smbb-wpcodetool'); ?></h2>
                    <table class="widefat striped smbb-codetool-table">
                        <tbody>
                            <tr>
                                <th><?php esc_html_e('Table resources', 'smbb-wpcodetool'); ?></th>
                                <td><?php echo esc_html((string) $table_resource_count); ?></td>
                            </tr>
                            <tr>
                                <th><?php esc_html_e('Option resources', 'smbb-wpcodetool'); ?></th>
                                <td><?php echo esc_html((string) $option_resource_count); ?></td>
                            </tr>
                            <tr>
                                <th><?php esc_html_e('UI-only pages', 'smbb-wpcodetool'); ?></th>
                                <td><?php echo esc_html((string) $ui_page_count); ?></td>
                            </tr>
                            <tr>
                                <th><?php esc_html_e('API-enabled resources', 'smbb-wpcodetool'); ?></th>
                                <td><?php echo esc_html((string) $api_resource_count); ?></td>
                            </tr>
                            <tr>
                                <th><?php esc_html_e('Schemas pending', 'smbb-wpcodetool'); ?></th>
                                <td><?php echo esc_html((string) $schema_pending_count); ?></td>
                            </tr>
                        </tbody>
                    </table>

                    <ul class="smbb-codetool-bullets">
                        <li><?php esc_html_e('The overview is now meant to be a landing page, not just a debug table.', 'smbb-wpcodetool'); ?></li>
                        <?php if ($schema_pending_count > 0) : ?>
                            <li><?php esc_html_e('This overview now surfaces SQL schema work before you open the full resource registry.', 'smbb-wpcodetool'); ?></li>
                        <?php else : ?>
                            <li><?php esc_html_e('All currently detected custom-table schemas are synchronized.', 'smbb-wpcodetool'); ?></li>
                        <?php endif; ?>
                        <li><?php esc_html_e('Resource pages remain fully free to implement bespoke UX on top of the model.', 'smbb-wpcodetool'); ?></li>
                    </ul>
                </aside>
            </div>
        </div>
        <?php
    }
}
