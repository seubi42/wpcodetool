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
                __('Clients API CodeTool', 'smbb-wpcodetool'),
                __('Créez des clients API, renouvelez leur secret en les recréant et délivrez des jetons d\'accès Bearer via POST /token.', 'smbb-wpcodetool'),
                'dashicons-lock',
                $manager->runtimeNoticesHtml()
            );
            ?>

            <?php if ($flash) : ?>
                <div class="notice notice-success">
                    <p><strong><?php esc_html_e('Copiez ces identifiants maintenant.', 'smbb-wpcodetool'); ?></strong> <?php esc_html_e('Le secret client n\'est affiché qu\'une seule fois.', 'smbb-wpcodetool'); ?></p>
                    <p><strong><?php esc_html_e('client_id', 'smbb-wpcodetool'); ?>:</strong> <code><?php echo esc_html($flash['client_id']); ?></code></p>
                    <p><strong><?php esc_html_e('client_secret', 'smbb-wpcodetool'); ?>:</strong> <code><?php echo esc_html($flash['client_secret']); ?></code></p>
                    <p><strong><?php esc_html_e('point d\'accès des jetons', 'smbb-wpcodetool'); ?>:</strong> <code><?php echo esc_html($token_url); ?></code></p>
                </div>
            <?php endif; ?>

            <div class="smbb-codetool-panel">
                <form method="post" action="<?php echo esc_url(admin_url('admin.php?page=smbb-wpcodetool-api-tokens')); ?>">
                    <?php wp_nonce_field('smbb_codetool_api_tokens'); ?>
                    <input type="hidden" name="codetool_api_token_action" value="create">
                    <table class="form-table" role="presentation">
                        <tbody>
                            <tr>
                                <th scope="row"><label for="codetool_api_client_label"><?php esc_html_e('Nom du client', 'smbb-wpcodetool'); ?></label></th>
                                <td>
                                    <input type="text" name="client_label" id="codetool_api_client_label" class="regular-text" placeholder="<?php echo esc_attr__('Exemple : CRM partenaire', 'smbb-wpcodetool'); ?>">
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="codetool_api_client_email"><?php esc_html_e('E-mail de contact', 'smbb-wpcodetool'); ?></label></th>
                                <td>
                                    <input type="email" name="contact_email" id="codetool_api_client_email" class="regular-text" placeholder="<?php echo esc_attr__('partenaire@exemple.fr', 'smbb-wpcodetool'); ?>">
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="codetool_api_client_ttl"><?php esc_html_e('Durée de vie par défaut des jetons (secondes)', 'smbb-wpcodetool'); ?></label></th>
                                <td>
                                    <input type="number" min="1200" max="864000" step="1" name="token_ttl_seconds" id="codetool_api_client_ttl" class="small-text" value="259200">
                                    <p class="description"><?php esc_html_e('Plage autorisée : 1200 à 864000 secondes. 259200 secondes = 3 jours. Un client peut demander un jeton plus court, jamais plus long.', 'smbb-wpcodetool'); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="codetool_api_client_expires"><?php esc_html_e('Date d\'expiration du client', 'smbb-wpcodetool'); ?></label></th>
                                <td>
                                    <input type="datetime-local" name="expires_at" id="codetool_api_client_expires">
                                    <p class="description"><?php esc_html_e('Facultatif. Une fois la date atteinte, le client est désactivé et ses jetons cessent de fonctionner.', 'smbb-wpcodetool'); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="codetool_api_client_scopes"><?php esc_html_e('Périmètres', 'smbb-wpcodetool'); ?></label></th>
                                <td>
                                    <textarea name="client_scopes" id="codetool_api_client_scopes" rows="5" class="large-text code">*</textarea>
                                    <p class="description"><?php esc_html_e('Un périmètre par ligne. Laissez « * » pour un accès complet. Exemples : resource:orders:read, resource:invoices:write, namespace:partner/v1:read.', 'smbb-wpcodetool'); ?></p>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    <p><button type="submit" class="button button-primary"><?php esc_html_e('Créer un client API', 'smbb-wpcodetool'); ?></button></p>
                </form>
            </div>

            <div class="smbb-codetool-panel">
                <p><strong><?php esc_html_e('Point d\'accès des jetons', 'smbb-wpcodetool'); ?>:</strong> <code><?php echo esc_html($token_url); ?></code></p>
                <p><?php esc_html_e('Utilisez ce point d\'accès pour échanger un client_id et un client_secret contre un jeton d\'accès Bearer.', 'smbb-wpcodetool'); ?></p>
            </div>

            <table class="widefat striped smbb-codetool-table">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Libellé', 'smbb-wpcodetool'); ?></th>
                        <th><?php esc_html_e('Identifiant client', 'smbb-wpcodetool'); ?></th>
                        <th><?php esc_html_e('Indice du secret', 'smbb-wpcodetool'); ?></th>
                        <th><?php esc_html_e('Contact', 'smbb-wpcodetool'); ?></th>
                        <th><?php esc_html_e('Périmètres', 'smbb-wpcodetool'); ?></th>
                        <th><?php esc_html_e('Durée par défaut', 'smbb-wpcodetool'); ?></th>
                        <th><?php esc_html_e('Expire le', 'smbb-wpcodetool'); ?></th>
                        <th><?php esc_html_e('Dernier jeton', 'smbb-wpcodetool'); ?></th>
                        <th><?php esc_html_e('Statut', 'smbb-wpcodetool'); ?></th>
                        <th><?php esc_html_e('Action', 'smbb-wpcodetool'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$clients) : ?>
                        <tr>
                            <td colspan="10"><?php esc_html_e('Aucun client API n\'a encore été créé.', 'smbb-wpcodetool'); ?></td>
                        </tr>
                    <?php else : ?>
                        <?php foreach ($clients as $client) : ?>
                            <?php
                            $client_form_id = 'smbb-codetool-api-client-' . (int) $client['id'];
                            $is_expired = !empty($client['expires_at']) && strtotime((string) $client['expires_at']) !== false && strtotime((string) $client['expires_at']) <= time();
                            $status = $is_expired
                                ? __('Expiré', 'smbb-wpcodetool')
                                : (!empty($client['active']) ? __('Actif', 'smbb-wpcodetool') : __('Désactivé', 'smbb-wpcodetool'));
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
                                <td><?php echo esc_html(!empty($client['last_token_at']) ? $client['last_token_at'] : __('Jamais', 'smbb-wpcodetool')); ?></td>
                                <td><?php echo esc_html($status); ?></td>
                                <td>
                                    <form id="<?php echo esc_attr($client_form_id); ?>" method="post" action="<?php echo esc_url(admin_url('admin.php?page=smbb-wpcodetool-api-tokens')); ?>">
                                        <?php wp_nonce_field('smbb_codetool_api_tokens'); ?>
                                        <input type="hidden" name="token_id" value="<?php echo esc_attr(isset($client['id']) ? $client['id'] : ''); ?>">
                                        <input type="hidden" name="codetool_api_token_action" value="update">
                                    </form>
                                    <button type="submit" class="button button-primary" form="<?php echo esc_attr($client_form_id); ?>"><?php esc_html_e('Enregistrer', 'smbb-wpcodetool'); ?></button>
                                    <?php if (!$is_expired) : ?>
                                        <form method="post" action="<?php echo esc_url(admin_url('admin.php?page=smbb-wpcodetool-api-tokens')); ?>" style="display:inline-block;">
                                            <?php wp_nonce_field('smbb_codetool_api_tokens'); ?>
                                            <input type="hidden" name="token_id" value="<?php echo esc_attr(isset($client['id']) ? $client['id'] : ''); ?>">
                                            <?php if (!empty($client['active'])) : ?>
                                                <input type="hidden" name="codetool_api_token_action" value="disable">
                                                <button type="submit" class="button"><?php esc_html_e('Désactiver', 'smbb-wpcodetool'); ?></button>
                                            <?php else : ?>
                                                <input type="hidden" name="codetool_api_token_action" value="enable">
                                                <button type="submit" class="button"><?php esc_html_e('Activer', 'smbb-wpcodetool'); ?></button>
                                            <?php endif; ?>
                                        </form>
                                    <?php endif; ?>
                                    <form method="post" action="<?php echo esc_url(admin_url('admin.php?page=smbb-wpcodetool-api-tokens')); ?>" style="display:inline-block;">
                                        <?php wp_nonce_field('smbb_codetool_api_tokens'); ?>
                                        <input type="hidden" name="token_id" value="<?php echo esc_attr(isset($client['id']) ? $client['id'] : ''); ?>">
                                        <input type="hidden" name="codetool_api_token_action" value="delete">
                                        <button type="submit" class="button"><?php esc_html_e('Supprimer', 'smbb-wpcodetool'); ?></button>
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
