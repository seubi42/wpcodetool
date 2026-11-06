<?php

namespace Smbb\WpCodeTool\Admin\Pages;

defined('ABSPATH') || exit;

/**
 * Liste les routes publiques detectees dans codetool/routes/public.php.
 */
final class RoutesPage extends AbstractAdminPage
{
    public function render()
    {
        $manager = $this->manager();
        $manager->ensurePublicRoutes();
        $manager->addNoticeFromQuery();
        $route_groups = $manager->publicRoutesGroupedByPlugin();
        $errors = $manager->publicRouteErrors();
        ?>
        <div class="wrap smbb-codetool">
            <?php
            echo $manager->pageIntroHtml(
                __('Routes CodeTool', 'smbb-wpcodetool'),
                __('Consultez les routes publiques hors REST déclarées par les plugins actifs.', 'smbb-wpcodetool'),
                'dashicons-randomize',
                $manager->runtimeNoticesHtml()
            );
            ?>

            <h2><?php esc_html_e('Routes détectées', 'smbb-wpcodetool'); ?></h2>

            <?php if (!$route_groups) : ?>
                <p><?php esc_html_e('Aucun plugin actif ne fournit encore de fichier codetool/routes/public.php.', 'smbb-wpcodetool'); ?></p>
            <?php else : ?>
                <div class="smbb-codetool-resource-groups">
                    <?php foreach ($route_groups as $group) : ?>
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
                                            _n('%d route', '%d routes', count($group['routes']), 'smbb-wpcodetool'),
                                            count($group['routes'])
                                        )
                                    );
                                    ?>
                                </span>
                            </div>

                            <table class="widefat striped smbb-codetool-table">
                                <thead>
                                    <tr>
                                        <th><?php esc_html_e('Méthode', 'smbb-wpcodetool'); ?></th>
                                        <th><?php esc_html_e('Motif', 'smbb-wpcodetool'); ?></th>
                                        <th><?php esc_html_e('URL', 'smbb-wpcodetool'); ?></th>
                                        <th><?php esc_html_e('Fichier de routes', 'smbb-wpcodetool'); ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($group['routes'] as $route) : ?>
                                        <?php $method_class = 'is-' . strtolower($route->method()); ?>
                                        <tr>
                                            <td>
                                                <span class="smbb-codetool-route-method <?php echo esc_attr($method_class); ?>">
                                                    <?php echo esc_html($route->method()); ?>
                                                </span>
                                            </td>
                                            <td><code><?php echo esc_html($route->pattern()); ?></code></td>
                                            <td>
                                                <a href="<?php echo esc_url($route->displayUrl()); ?>" target="_blank" rel="noopener noreferrer">
                                                    <?php echo esc_html($route->displayUrl()); ?>
                                                </a>
                                            </td>
                                            <td><code><?php echo esc_html($manager->displayPluginRelativePath($route->file(), $route->pluginDir())); ?></code></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </section>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if ($errors) : ?>
                <h2><?php esc_html_e('Erreurs de lecture des routes', 'smbb-wpcodetool'); ?></h2>
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
