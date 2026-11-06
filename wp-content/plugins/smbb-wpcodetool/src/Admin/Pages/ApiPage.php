<?php

namespace Smbb\WpCodeTool\Admin\Pages;

defined('ABSPATH') || exit;

/**
 * Ecran de reglage de la visibilite OpenAPI par namespace.
 */
final class ApiPage extends AbstractAdminPage
{
    public function render()
    {
        $manager = $this->manager();
        $manager->ensureResources();
        $manager->addNoticeFromQuery();
        $namespaces = $manager->apiNamespaces();
        $aggregate_url = rest_url('smbb-wpcodetool/v1/openapi-all');
        $aggregate_session_url = $manager->signedRestUrl($aggregate_url);
        ?>
        <div class="wrap smbb-codetool">
            <?php
            echo $manager->pageIntroHtml(
                __('API CodeTool', 'smbb-wpcodetool'),
                __('Gérez la visibilité OpenAPI et Swagger par espace de noms.', 'smbb-wpcodetool'),
                'dashicons-rest-api',
                $manager->runtimeNoticesHtml()
            );
            ?>

            <div class="smbb-codetool-panel">
                <p>
                    <strong><?php esc_html_e('OpenAPI global', 'smbb-wpcodetool'); ?>:</strong>
                    <code><?php echo esc_html($aggregate_url); ?></code>
                </p>
                <?php if ($aggregate_session_url !== $aggregate_url) : ?>
                    <p>
                        <a class="button button-secondary" href="<?php echo esc_url($aggregate_session_url); ?>" target="_blank" rel="noopener noreferrer">
                            <?php esc_html_e('Ouvrir avec la session actuelle', 'smbb-wpcodetool'); ?>
                        </a>
                    </p>
                <?php endif; ?>
                <p>
                    <strong><?php esc_html_e('Point d\'accès des jetons', 'smbb-wpcodetool'); ?>:</strong>
                    <code><?php echo esc_html(rest_url('smbb-wpcodetool/v1/token')); ?></code>
                </p>
                <p><?php esc_html_e('Le point d\'accès global et le shortcode Swagger n\'exposent que les espaces de noms visibles par le visiteur. Les documentations protégées par une capacité demandent un nonce REST WordPress : une URL /wp-json ouverte à la main peut donc paraître anonyme même si vous êtes connecté.', 'smbb-wpcodetool'); ?></p>
            </div>

            <?php if (!$namespaces) : ?>
                <div class="smbb-codetool-panel">
                    <p><?php esc_html_e('Aucun espace de noms exposé en API n\'a encore été détecté.', 'smbb-wpcodetool'); ?></p>
                </div>
            <?php else : ?>
                <form method="post" action="<?php echo esc_url(admin_url('admin.php?page=smbb-wpcodetool-api')); ?>">
                    <?php wp_nonce_field('smbb_codetool_api_visibility'); ?>
                    <input type="hidden" name="codetool_api_action" value="save_visibility">

                    <table class="widefat striped smbb-codetool-table">
                        <thead>
                            <tr>
                                <th><?php esc_html_e('Espace de noms', 'smbb-wpcodetool'); ?></th>
                                <th><?php esc_html_e('Ressources', 'smbb-wpcodetool'); ?></th>
                                <th><?php esc_html_e('Visibilité', 'smbb-wpcodetool'); ?></th>
                                <th><?php esc_html_e('Capacité', 'smbb-wpcodetool'); ?></th>
                                <th><?php esc_html_e('OpenAPI', 'smbb-wpcodetool'); ?></th>
                                <th><?php esc_html_e('Shortcode', 'smbb-wpcodetool'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($namespaces as $namespace => $resources) : ?>
                                <?php $settings = $manager->apiVisibility()->forNamespace($namespace); ?>
                                <tr>
                                    <td><code><?php echo esc_html($namespace); ?></code></td>
                                    <td>
                                        <?php
                                        $labels = array();

                                        foreach ($resources as $resource) {
                                            $labels[] = $resource->name();
                                        }

                                        echo esc_html(implode(', ', $labels));
                                        ?>
                                    </td>
                                    <td>
                                        <select name="api_visibility[<?php echo esc_attr($namespace); ?>][visibility]">
                                            <option value="public" <?php selected($settings['visibility'], 'public'); ?>><?php esc_html_e('Public', 'smbb-wpcodetool'); ?></option>
                                            <option value="capability" <?php selected($settings['visibility'], 'capability'); ?>><?php esc_html_e('Capacité WP', 'smbb-wpcodetool'); ?></option>
                                            <option value="hidden" <?php selected($settings['visibility'], 'hidden'); ?>><?php esc_html_e('Masqué', 'smbb-wpcodetool'); ?></option>
                                        </select>
                                    </td>
                                    <td>
                                        <input type="text" class="regular-text" name="api_visibility[<?php echo esc_attr($namespace); ?>][capability]" value="<?php echo esc_attr($settings['capability']); ?>">
                                    </td>
                                    <td>
                                        <?php $openapi_url = rest_url($namespace . '/openapi'); ?>
                                        <?php $openapi_session_url = $manager->signedRestUrl($openapi_url); ?>
                                        <code><?php echo esc_html($openapi_url); ?></code>
                                        <?php if ($openapi_session_url !== $openapi_url) : ?>
                                            <br>
                                            <a class="button button-small" href="<?php echo esc_url($openapi_session_url); ?>" target="_blank" rel="noopener noreferrer">
                                                <?php esc_html_e('Ouvrir', 'smbb-wpcodetool'); ?>
                                            </a>
                                        <?php endif; ?>
                                    </td>
                                    <td><code>[smbb_codetool_api_docs namespace="<?php echo esc_html($namespace); ?>"]</code></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>

                    <p>
                        <button type="submit" class="button button-primary"><?php esc_html_e('Enregistrer la visibilité de l\'API', 'smbb-wpcodetool'); ?></button>
                    </p>
                </form>
            <?php endif; ?>
        </div>
        <?php
    }
}
