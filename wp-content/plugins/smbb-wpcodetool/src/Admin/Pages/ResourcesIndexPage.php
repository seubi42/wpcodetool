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
                __('Ressources CodeTool', 'smbb-wpcodetool'),
                __('Consultez les ressources détectées, l\'état de la base et l\'aperçu du schéma.', 'smbb-wpcodetool'),
                'dashicons-editor-code',
                array(
                    $manager->runtimeNoticesHtml(),
                    $manager->schemaNoticeHtml($schema_notice),
                )
            );
            ?>

            <?php $manager->renderSchemaPreview($schema_preview); ?>

            <h2><?php esc_html_e('Ressources détectées', 'smbb-wpcodetool'); ?></h2>

            <?php if (!$resource_groups) : ?>
                <p><?php esc_html_e('Aucun plugin actif ne fournit encore de dossier codetool/models.', 'smbb-wpcodetool'); ?></p>
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
                                            _n('%d ressource', '%d ressources', count($group['resources']), 'smbb-wpcodetool'),
                                            count($group['resources'])
                                        )
                                    );
                                    ?>
                                </span>
                            </div>

                            <table class="widefat striped smbb-codetool-table">
                                <thead>
                                    <tr>
                                        <th><?php esc_html_e('Ressource', 'smbb-wpcodetool'); ?></th>
                                        <th><?php esc_html_e('Stockage', 'smbb-wpcodetool'); ?></th>
                                        <th><?php esc_html_e('Base de données', 'smbb-wpcodetool'); ?></th>
                                        <th><?php esc_html_e('Admin', 'smbb-wpcodetool'); ?></th>
                                        <th><?php esc_html_e('Menu', 'smbb-wpcodetool'); ?></th>
                                        <th><?php esc_html_e('Fichier de modèle', 'smbb-wpcodetool'); ?></th>
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
                <h2><?php esc_html_e('Erreurs de lecture et de validation', 'smbb-wpcodetool'); ?></h2>
                <table class="widefat striped smbb-codetool-table">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Fichier', 'smbb-wpcodetool'); ?></th>
                            <th><?php esc_html_e('Erreur', 'smbb-wpcodetool'); ?></th>
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
