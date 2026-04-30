<?php

namespace Smbb\WpCodeTool\Admin\Pages;

defined('ABSPATH') || exit;

/**
 * Ecran d'administration des clients API geres par CodeTool.
 */
final class ApiClientsPage extends AbstractAdminPage
{
    public function render()
    {
        $manager = $this->manager();
        $manager->addNoticeFromQuery();
        $clients = $manager->apiClients()->listing();
        $flash = $manager->consumeApiTokenFlash();
        $token_url = rest_url('smbb-wpcodetool/v1/token');
        ?>
        <div class="wrap smbb-codetool">
            <?php
            echo $manager->pageIntroHtml(
                __('CodeTool API clients', 'smbb-wpcodetool'),
                __('Create API clients, rotate their secret by recreation, and issue bearer access tokens via POST /token.', 'smbb-wpcodetool'),
                'dashicons-lock',
                $manager->runtimeNoticesHtml()
            );
            ?>

            <?php if ($flash) : ?>
                <div class="notice notice-success">
                    <p><strong><?php esc_html_e('Copy these credentials now.', 'smbb-wpcodetool'); ?></strong> <?php esc_html_e('The client secret is shown only once.', 'smbb-wpcodetool'); ?></p>
                    <p><strong><?php esc_html_e('client_id', 'smbb-wpcodetool'); ?>:</strong> <code><?php echo esc_html($flash['client_id']); ?></code></p>
                    <p><strong><?php esc_html_e('client_secret', 'smbb-wpcodetool'); ?>:</strong> <code><?php echo esc_html($flash['client_secret']); ?></code></p>
                    <p><strong><?php esc_html_e('token endpoint', 'smbb-wpcodetool'); ?>:</strong> <code><?php echo esc_html($token_url); ?></code></p>
                </div>
            <?php endif; ?>

            <div class="smbb-codetool-panel">
                <form method="post" action="<?php echo esc_url(admin_url('admin.php?page=smbb-wpcodetool-api-tokens')); ?>">
                    <?php wp_nonce_field('smbb_codetool_api_tokens'); ?>
                    <input type="hidden" name="codetool_api_token_action" value="create">
                    <table class="form-table" role="presentation">
                        <tbody>
                            <tr>
                                <th scope="row"><label for="codetool_api_client_label"><?php esc_html_e('Client label', 'smbb-wpcodetool'); ?></label></th>
                                <td>
                                    <input type="text" name="client_label" id="codetool_api_client_label" class="regular-text" placeholder="<?php echo esc_attr__('Example: Partner CRM', 'smbb-wpcodetool'); ?>">
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="codetool_api_client_email"><?php esc_html_e('Contact email', 'smbb-wpcodetool'); ?></label></th>
                                <td>
                                    <input type="email" name="contact_email" id="codetool_api_client_email" class="regular-text" placeholder="<?php echo esc_attr__('partner@example.com', 'smbb-wpcodetool'); ?>">
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="codetool_api_client_ttl"><?php esc_html_e('Default token lifetime (seconds)', 'smbb-wpcodetool'); ?></label></th>
                                <td>
                                    <input type="number" min="1200" max="864000" step="1" name="token_ttl_seconds" id="codetool_api_client_ttl" class="small-text" value="259200">
                                    <p class="description"><?php esc_html_e('Allowed range: 1200 to 864000 seconds. 259200 seconds = 3 days. A client may request a shorter token, never a longer one.', 'smbb-wpcodetool'); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="codetool_api_client_expires"><?php esc_html_e('Client expiration date', 'smbb-wpcodetool'); ?></label></th>
                                <td>
                                    <input type="datetime-local" name="expires_at" id="codetool_api_client_expires">
                                    <p class="description"><?php esc_html_e('Optional. Once reached, the client is auto-disabled and its issued tokens stop working.', 'smbb-wpcodetool'); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="codetool_api_client_scopes"><?php esc_html_e('Scopes', 'smbb-wpcodetool'); ?></label></th>
                                <td>
                                    <textarea name="client_scopes" id="codetool_api_client_scopes" rows="5" class="large-text code">*</textarea>
                                    <p class="description"><?php esc_html_e('One scope per line. Leave "*" for full access. Examples: resource:orders:read, resource:invoices:write, namespace:partner/v1:read.', 'smbb-wpcodetool'); ?></p>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    <p><button type="submit" class="button button-primary"><?php esc_html_e('Create API client', 'smbb-wpcodetool'); ?></button></p>
                </form>
            </div>

            <div class="smbb-codetool-panel">
                <p><strong><?php esc_html_e('Token endpoint', 'smbb-wpcodetool'); ?>:</strong> <code><?php echo esc_html($token_url); ?></code></p>
                <p><?php esc_html_e('Use this endpoint to exchange a client_id and client_secret for a bearer access token.', 'smbb-wpcodetool'); ?></p>
            </div>

            <table class="widefat striped smbb-codetool-table">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Label', 'smbb-wpcodetool'); ?></th>
                        <th><?php esc_html_e('Client ID', 'smbb-wpcodetool'); ?></th>
                        <th><?php esc_html_e('Secret hint', 'smbb-wpcodetool'); ?></th>
                        <th><?php esc_html_e('Contact', 'smbb-wpcodetool'); ?></th>
                        <th><?php esc_html_e('Scopes', 'smbb-wpcodetool'); ?></th>
                        <th><?php esc_html_e('Default TTL', 'smbb-wpcodetool'); ?></th>
                        <th><?php esc_html_e('Expires', 'smbb-wpcodetool'); ?></th>
                        <th><?php esc_html_e('Last token', 'smbb-wpcodetool'); ?></th>
                        <th><?php esc_html_e('Status', 'smbb-wpcodetool'); ?></th>
                        <th><?php esc_html_e('Action', 'smbb-wpcodetool'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$clients) : ?>
                        <tr>
                            <td colspan="10"><?php esc_html_e('No API client has been created yet.', 'smbb-wpcodetool'); ?></td>
                        </tr>
                    <?php else : ?>
                        <?php foreach ($clients as $client) : ?>
                            <?php
                            $client_form_id = 'smbb-codetool-api-client-' . (int) $client['id'];
                            $is_expired = !empty($client['expires_at']) && strtotime((string) $client['expires_at']) !== false && strtotime((string) $client['expires_at']) <= time();
                            $status = $is_expired
                                ? __('Expired', 'smbb-wpcodetool')
                                : (!empty($client['active']) ? __('Active', 'smbb-wpcodetool') : __('Disabled', 'smbb-wpcodetool'));
                            ?>
                            <tr>
                                <td>
                                    <input
                                        type="text"
                                        class="regular-text"
                                        style="width: 100%;"
                                        name="client_label"
                                        form="<?php echo esc_attr($client_form_id); ?>"
                                        value="<?php echo esc_attr(isset($client['label']) ? $client['label'] : ''); ?>"
                                    >
                                </td>
                                <td><code><?php echo esc_html(isset($client['client_id']) ? $client['client_id'] : ''); ?></code></td>
                                <td><code><?php echo esc_html(isset($client['secret_prefix']) ? $client['secret_prefix'] : ''); ?></code></td>
                                <td>
                                    <input
                                        type="email"
                                        class="regular-text"
                                        style="width: 100%;"
                                        name="contact_email"
                                        form="<?php echo esc_attr($client_form_id); ?>"
                                        value="<?php echo esc_attr(isset($client['contact_email']) ? $client['contact_email'] : ''); ?>"
                                    >
                                </td>
                                <td style="min-width: 240px;">
                                    <textarea
                                        class="large-text code"
                                        rows="4"
                                        name="client_scopes"
                                        form="<?php echo esc_attr($client_form_id); ?>"
                                    ><?php echo esc_textarea($manager->apiClients()->scopesTextarea(isset($client['scopes']) ? $client['scopes'] : array('*'))); ?></textarea>
                                </td>
                                <td>
                                    <input
                                        type="number"
                                        min="1200"
                                        max="864000"
                                        step="1"
                                        class="small-text"
                                        name="token_ttl_seconds"
                                        form="<?php echo esc_attr($client_form_id); ?>"
                                        value="<?php echo esc_attr(isset($client['token_ttl_seconds']) ? (int) $client['token_ttl_seconds'] : 259200); ?>"
                                    >
                                </td>
                                <td>
                                    <input
                                        type="datetime-local"
                                        name="expires_at"
                                        form="<?php echo esc_attr($client_form_id); ?>"
                                        value="<?php echo esc_attr($manager->dateTimeLocalInputValue(isset($client['expires_at']) ? $client['expires_at'] : '')); ?>"
                                    >
                                </td>
                                <td><?php echo esc_html(!empty($client['last_token_at']) ? $client['last_token_at'] : __('Never', 'smbb-wpcodetool')); ?></td>
                                <td><?php echo esc_html($status); ?></td>
                                <td>
                                    <form id="<?php echo esc_attr($client_form_id); ?>" method="post" action="<?php echo esc_url(admin_url('admin.php?page=smbb-wpcodetool-api-tokens')); ?>">
                                        <?php wp_nonce_field('smbb_codetool_api_tokens'); ?>
                                        <input type="hidden" name="token_id" value="<?php echo esc_attr(isset($client['id']) ? $client['id'] : ''); ?>">
                                        <input type="hidden" name="codetool_api_token_action" value="update">
                                    </form>
                                    <button type="submit" class="button button-primary" form="<?php echo esc_attr($client_form_id); ?>"><?php esc_html_e('Save', 'smbb-wpcodetool'); ?></button>
                                    <?php if (!$is_expired) : ?>
                                        <form method="post" action="<?php echo esc_url(admin_url('admin.php?page=smbb-wpcodetool-api-tokens')); ?>" style="display:inline-block;">
                                            <?php wp_nonce_field('smbb_codetool_api_tokens'); ?>
                                            <input type="hidden" name="token_id" value="<?php echo esc_attr(isset($client['id']) ? $client['id'] : ''); ?>">
                                            <?php if (!empty($client['active'])) : ?>
                                                <input type="hidden" name="codetool_api_token_action" value="disable">
                                                <button type="submit" class="button"><?php esc_html_e('Disable', 'smbb-wpcodetool'); ?></button>
                                            <?php else : ?>
                                                <input type="hidden" name="codetool_api_token_action" value="enable">
                                                <button type="submit" class="button"><?php esc_html_e('Enable', 'smbb-wpcodetool'); ?></button>
                                            <?php endif; ?>
                                        </form>
                                    <?php endif; ?>
                                    <form method="post" action="<?php echo esc_url(admin_url('admin.php?page=smbb-wpcodetool-api-tokens')); ?>" style="display:inline-block;">
                                        <?php wp_nonce_field('smbb_codetool_api_tokens'); ?>
                                        <input type="hidden" name="token_id" value="<?php echo esc_attr(isset($client['id']) ? $client['id'] : ''); ?>">
                                        <input type="hidden" name="codetool_api_token_action" value="delete">
                                        <button type="submit" class="button"><?php esc_html_e('Delete', 'smbb-wpcodetool'); ?></button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }
}
