<?php

namespace Smbb\WpCodeTool\Admin\Pages;

defined('ABSPATH') || exit;

/**
 * Page parent minimale pour les menus thematiques geres par CodeTool.
 */
final class ParentPage extends AbstractAdminPage
{
    public function render($parent_slug)
    {
        $manager = $this->manager();
        $title = $manager->parentTitle($parent_slug);
        $subtitle = __('Resources grouped under this menu.', 'smbb-wpcodetool');
        $icon = $manager->parentIcon($parent_slug);
        ?>
        <div class="wrap smbb-codetool">
            <?php echo $manager->pageIntroHtml($title, $subtitle, $icon); ?>

            <table class="widefat striped smbb-codetool-table">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Resource', 'smbb-wpcodetool'); ?></th>
                        <th><?php esc_html_e('Storage', 'smbb-wpcodetool'); ?></th>
                        <th><?php esc_html_e('Action', 'smbb-wpcodetool'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($manager->resources() as $resource) : ?>
                        <?php if (!$resource->adminEnabled() || $resource->menuPlacement() !== 'submenu' || $resource->menuParentSlug() !== $parent_slug) : ?>
                            <?php continue; ?>
                        <?php endif; ?>
                        <tr>
                            <td><?php echo esc_html($resource->menuTitle()); ?></td>
                            <td><?php echo esc_html($resource->storageType()); ?></td>
                            <td>
                                <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=' . $resource->adminSlug())); ?>">
                                    <?php esc_html_e('Open', 'smbb-wpcodetool'); ?>
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }
}
