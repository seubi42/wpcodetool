<?php

namespace Smbb\WpCodeTool\Admin\Pages;

defined('ABSPATH') || exit;

/**
 * Liste les ressources detectees et les erreurs de scan/validation.
 */
final class ResourcesIndexPage extends AbstractAdminPage
{
    public function render()
    {
        $manager = $this->manager();
        $manager->ensureResources();
        $manager->addNoticeFromQuery();
        $schema_notice = $manager->handleSchemaApply();
        $schema_preview = $manager->requestedSchemaPreview();
        $resource_groups = $manager->resourcesGroupedByPlugin();
        $errors = $manager->errors();
        ?>
        <div class="wrap smbb-codetool">
            <?php
            echo $manager->pageIntroHtml(
                __('CodeTool resources', 'smbb-wpcodetool'),
                __('Inspect detected resources, database status, and schema preview actions.', 'smbb-wpcodetool'),
                'dashicons-editor-code',
                array(
                    $manager->runtimeNoticesHtml(),
                    $manager->schemaNoticeHtml($schema_notice),
                )
            );
            ?>

            <?php $manager->renderSchemaPreview($schema_preview); ?>

            <h2><?php esc_html_e('Detected resources', 'smbb-wpcodetool'); ?></h2>

            <?php if (!$resource_groups) : ?>
                <p><?php esc_html_e('No active plugin exposes a codetool/models directory yet.', 'smbb-wpcodetool'); ?></p>
            <?php else : ?>
                <div class="smbb-codetool-resource-groups">
                    <?php foreach ($resource_groups as $group) : ?>
                        <section class="smbb-codetool-panel smbb-codetool-plugin-section">
                            <div class="smbb-codetool-panel-header">
                                <div class="smbb-codetool-plugin-heading">
                                    <h3 class="smbb-codetool-plugin-title"><?php echo esc_html($group['label']); ?></h3>
                                    <p class="smbb-codetool-plugin-path">
                                        <code><?php echo esc_html($group['path']); ?></code>
                                    </p>
                                </div>
                                <span class="smbb-codetool-badge">
                                    <?php
                                    echo esc_html(
                                        sprintf(
                                            _n('%d resource', '%d resources', count($group['resources']), 'smbb-wpcodetool'),
                                            count($group['resources'])
                                        )
                                    );
                                    ?>
                                </span>
                            </div>

                            <table class="widefat striped smbb-codetool-table">
                                <thead>
                                    <tr>
                                        <th><?php esc_html_e('Resource', 'smbb-wpcodetool'); ?></th>
                                        <th><?php esc_html_e('Storage', 'smbb-wpcodetool'); ?></th>
                                        <th><?php esc_html_e('Database', 'smbb-wpcodetool'); ?></th>
                                        <th><?php esc_html_e('Admin', 'smbb-wpcodetool'); ?></th>
                                        <th><?php esc_html_e('Menu', 'smbb-wpcodetool'); ?></th>
                                        <th><?php esc_html_e('Model file', 'smbb-wpcodetool'); ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($group['resources'] as $resource) : ?>
                                        <tr>
                                            <td>
                                                <a href="<?php echo esc_url(admin_url('admin.php?page=' . $resource->adminSlug())); ?>">
                                                    <?php echo esc_html($resource->name()); ?>
                                                </a>
                                                <div class="smbb-codetool-plugin-resource-meta">
                                                    <?php echo esc_html($resource->label()); ?>
                                                </div>
                                            </td>
                                            <td><?php echo esc_html($resource->storageType()); ?></td>
                                            <td><?php $manager->renderSchemaCell($resource); ?></td>
                                            <td><?php echo esc_html($resource->adminEnabled() ? 'enabled' : 'disabled'); ?></td>
                                            <td>
                                                <?php echo esc_html($resource->menuPlacement()); ?>
                                                <?php if ($resource->menuPlacement() === 'submenu') : ?>
                                                    <br><code><?php echo esc_html($resource->menuParentSlug()); ?></code>
                                                <?php endif; ?>
                                            </td>
                                            <td><code><?php echo esc_html($manager->displayPluginRelativePath($resource->modelFile(), $resource->pluginDir())); ?></code></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </section>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if ($errors) : ?>
                <h2><?php esc_html_e('Scan and validation errors', 'smbb-wpcodetool'); ?></h2>
                <table class="widefat striped smbb-codetool-table">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('File', 'smbb-wpcodetool'); ?></th>
                            <th><?php esc_html_e('Error', 'smbb-wpcodetool'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($errors as $error) : ?>
                            <tr>
                                <td><code><?php echo esc_html(isset($error['file']) ? $error['file'] : ''); ?></code></td>
                                <td><?php echo esc_html(isset($error['message']) ? $error['message'] : ''); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
    }
}
