<?php

namespace Smbb\WpCodeTool\Admin\Pages;

defined('ABSPATH') || exit;

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
                __('CodeTool API', 'smbb-wpcodetool'),
                __('Manage OpenAPI and Swagger visibility per namespace.', 'smbb-wpcodetool'),
                'dashicons-rest-api',
                $manager->runtimeNoticesHtml()
            );
            ?>

            <div class="smbb-codetool-panel">
                <p>
                    <strong><?php esc_html_e('Aggregate OpenAPI', 'smbb-wpcodetool'); ?>:</strong>
                    <code><?php echo esc_html($aggregate_url); ?></code>
                </p>
                <?php if ($aggregate_session_url !== $aggregate_url) : ?>
                    <p>
                        <a class="button button-secondary" href="<?php echo esc_url($aggregate_session_url); ?>" target="_blank" rel="noopener noreferrer">
                            <?php esc_html_e('Open with current session', 'smbb-wpcodetool'); ?>
                        </a>
                    </p>
                <?php endif; ?>
                <p>
                    <strong><?php esc_html_e('Token endpoint', 'smbb-wpcodetool'); ?>:</strong>
                    <code><?php echo esc_html(rest_url('smbb-wpcodetool/v1/token')); ?></code>
                </p>
                <p><?php esc_html_e('The aggregate endpoint and the Swagger shortcode only expose namespaces visible to the current visitor. Capability-protected REST docs need a WordPress REST nonce, so a raw /wp-json URL opened manually may look anonymous even when you are logged in.', 'smbb-wpcodetool'); ?></p>
            </div>

            <?php if (!$namespaces) : ?>
                <div class="smbb-codetool-panel">
                    <p><?php esc_html_e('No API-enabled namespace has been detected yet.', 'smbb-wpcodetool'); ?></p>
                </div>
            <?php else : ?>
                <form method="post" action="<?php echo esc_url(admin_url('admin.php?page=smbb-wpcodetool-api')); ?>">
                    <?php wp_nonce_field('smbb_codetool_api_visibility'); ?>
                    <input type="hidden" name="codetool_api_action" value="save_visibility">

                    <table class="widefat striped smbb-codetool-table">
                        <thead>
                            <tr>
                                <th><?php esc_html_e('Namespace', 'smbb-wpcodetool'); ?></th>
                                <th><?php esc_html_e('Resources', 'smbb-wpcodetool'); ?></th>
                                <th><?php esc_html_e('Visibility', 'smbb-wpcodetool'); ?></th>
                                <th><?php esc_html_e('Capability', 'smbb-wpcodetool'); ?></th>
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
                                            <option value="capability" <?php selected($settings['visibility'], 'capability'); ?>><?php esc_html_e('WP capability', 'smbb-wpcodetool'); ?></option>
                                            <option value="hidden" <?php selected($settings['visibility'], 'hidden'); ?>><?php esc_html_e('Hidden', 'smbb-wpcodetool'); ?></option>
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
                                                <?php esc_html_e('Open', 'smbb-wpcodetool'); ?>
                                            </a>
                                        <?php endif; ?>
                                    </td>
                                    <td><code>[smbb_codetool_api_docs namespace="<?php echo esc_html($namespace); ?>"]</code></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>

                    <p>
                        <button type="submit" class="button button-primary"><?php esc_html_e('Save API visibility', 'smbb-wpcodetool'); ?></button>
                    </p>
                </form>
            <?php endif; ?>
        </div>
        <?php
    }
}
