<?php

namespace Smbb\WpCodeTool\Admin\Pages;

use Smbb\WpCodeTool\Resource\ResourceDefinition;

defined('ABSPATH') || exit;

/**
 * Page d'accueil admin qui resume l'etat global du plugin.
 */
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
        $route_count = count($manager->publicRoutes());
        $scan_errors = $manager->errors();
        $scan_error_count = count($scan_errors);
        $resources_url = admin_url('admin.php?page=smbb-wpcodetool-resources');
        $routes_url = admin_url('admin.php?page=smbb-wpcodetool-routes');
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
                'label' => __('Tables personnalisées', 'smbb-wpcodetool'),
                'meta' => sprintf(_n('%d ressource', '%d ressources', $table_resource_count, 'smbb-wpcodetool'), $table_resource_count),
            ),
            array(
                'icon' => 'dashicons-admin-settings',
                'label' => __('Pages de réglages', 'smbb-wpcodetool'),
                'meta' => sprintf(_n('%d page de réglages', '%d pages de réglages', $option_resource_count, 'smbb-wpcodetool'), $option_resource_count),
            ),
            array(
                'icon' => 'dashicons-layout',
                'label' => __('Tableaux de bord', 'smbb-wpcodetool'),
                'meta' => sprintf(_n('%d page', '%d pages', $ui_page_count, 'smbb-wpcodetool'), $ui_page_count),
            ),
            array(
                'icon' => 'dashicons-rest-api',
                'label' => __('Exposition REST', 'smbb-wpcodetool'),
                'meta' => sprintf(_n('%d ressource API', '%d ressources API', $api_resource_count, 'smbb-wpcodetool'), $api_resource_count),
            ),
            array(
                'icon' => 'dashicons-randomize',
                'label' => __('Routes publiques', 'smbb-wpcodetool'),
                'meta' => sprintf(_n('%d route détectée', '%d routes détectées', $route_count, 'smbb-wpcodetool'), $route_count),
            ),
            array(
                'icon' => 'dashicons-editor-code',
                'label' => __('Registre des ressources', 'smbb-wpcodetool'),
                'meta' => sprintf(_n('%d modèle détecté', '%d modèles détectés', $resource_count, 'smbb-wpcodetool'), $resource_count),
            ),
            array(
                'icon' => 'dashicons-admin-network',
                'label' => __('Clients gérés', 'smbb-wpcodetool'),
                'meta' => sprintf(_n('%d client API', '%d clients API', $client_count, 'smbb-wpcodetool'), $client_count),
            ),
        );

        $quick_access = array(
            array(
                'title' => __('Voir les ressources', 'smbb-wpcodetool'),
                'description' => __('Consultez les modèles détectés, leur place dans le menu, l\'état du schéma et les pages d\'admin générées.', 'smbb-wpcodetool'),
                'url' => $resources_url,
            ),
            array(
                'title' => __('Voir les routes', 'smbb-wpcodetool'),
                'description' => __('Consultez les URL publiques déclarées dans les fichiers codetool/routes/public.php.', 'smbb-wpcodetool'),
                'url' => $routes_url,
            ),
            array(
                'title' => __('Gérer la visibilité OpenAPI', 'smbb-wpcodetool'),
                'description' => __('Choisissez quels espaces de noms sont publics, protégés par une capacité ou masqués.', 'smbb-wpcodetool'),
                'url' => admin_url('admin.php?page=smbb-wpcodetool-api'),
            ),
            array(
                'title' => __('Gérer les clients API', 'smbb-wpcodetool'),
                'description' => __('Délivrez des identifiants et consultez les clients autorisés à demander des jetons d\'accès.', 'smbb-wpcodetool'),
                'url' => admin_url('admin.php?page=smbb-wpcodetool-api-tokens'),
            ),
        );

        if ($schema_pending_count > 0) {
            array_unshift(
                $quick_access,
                array(
                    'title' => __('Appliquer les mises à jour du schéma SQL', 'smbb-wpcodetool'),
                    'description' => sprintf(
                        _n(
                            '%d ressource en table personnalisée demande une mise à jour SQL avant que ses données soient synchronisées.',
                            '%d ressources en table personnalisée demandent une mise à jour SQL avant que leurs données soient synchronisées.',
                            $schema_pending_count,
                            'smbb-wpcodetool'
                        ),
                        $schema_pending_count
                    ),
                    'url' => $resources_url,
                )
            );
        }

        if ($scan_error_count > 0) {
            array_unshift(
                $quick_access,
                array(
                    'title' => __('Voir les problèmes de modèles', 'smbb-wpcodetool'),
                    'description' => sprintf(
                        _n(
                            '%d fichier de modèle présente une erreur de lecture ou de validation.',
                            '%d fichiers de modèle présentent des erreurs de lecture ou de validation.',
                            $scan_error_count,
                            'smbb-wpcodetool'
                        ),
                        $scan_error_count
                    ),
                    'url' => $resources_url,
                )
            );
        }

        $schema_attention_variant = ($schema_invalid_count > 0 || $schema_missing_count > 0) ? 'is-error' : 'is-warning';
        $schema_summary = array();

        if ($schema_missing_count > 0) {
            $schema_summary[] = sprintf(
                _n('%d table manquante', '%d tables manquantes', $schema_missing_count, 'smbb-wpcodetool'),
                $schema_missing_count
            );
        }

        if ($schema_update_count > 0) {
            $schema_summary[] = sprintf(
                _n('%d mise à jour requise', '%d mises à jour requises', $schema_update_count, 'smbb-wpcodetool'),
                $schema_update_count
            );
        }

        if ($schema_invalid_count > 0) {
            $schema_summary[] = sprintf(
                _n('%d schéma invalide', '%d schémas invalides', $schema_invalid_count, 'smbb-wpcodetool'),
                $schema_invalid_count
            );
        }
        ?>
        <div class="wrap smbb-codetool">
            <?php
            echo $manager->pageIntroHtml(
                __('Vue d\'ensemble CodeTool', 'smbb-wpcodetool'),
                __('Accès rapide aux ressources, à l\'exposition API et aux clients API.', 'smbb-wpcodetool'),
                'dashicons-editor-code',
                $manager->runtimeNoticesHtml()
            );
            ?>

            <section class="smbb-codetool-hero">
                <div class="smbb-codetool-hero-inner">
                    <div class="smbb-codetool-hero-main">
                        <p class="smbb-codetool-hero-eyebrow"><?php esc_html_e('Admin CodeTool', 'smbb-wpcodetool'); ?></p>
                        <h2 class="smbb-codetool-hero-title">
                            <?php esc_html_e('Construisez plus vite des écrans d\'admin WordPress sur mesure', 'smbb-wpcodetool'); ?>
                            <span class="smbb-codetool-hero-chip"><?php esc_html_e('Interface d\'abord', 'smbb-wpcodetool'); ?></span>
                        </h2>
                        <p class="smbb-codetool-hero-desc">
                            <?php esc_html_e('Lisez les modèles de ressources, exposez des espaces de noms REST et livrez tableaux de bord, écrans de gestion ou pages de réglages avec un seul outil.', 'smbb-wpcodetool'); ?>
                        </p>

                        <div class="smbb-codetool-hero-actions">
                            <a class="smbb-codetool-hero-button is-secondary" href="<?php echo esc_url($resources_url); ?>">
                                <?php esc_html_e('Voir les ressources', 'smbb-wpcodetool'); ?>
                                <span class="dashicons dashicons-arrow-right-alt2" aria-hidden="true"></span>
                            </a>
                            <a class="smbb-codetool-hero-button is-primary" href="<?php echo esc_url(admin_url('admin.php?page=smbb-wpcodetool-api')); ?>">
                                <?php esc_html_e('Ouvrir les réglages de l\'API', 'smbb-wpcodetool'); ?>
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
                        <?php esc_html_e('Pensé pour les développeurs WordPress qui veulent des modèles déclaratifs et des écrans d\'admin sur mesure dans le même outil.', 'smbb-wpcodetool'); ?>
                    </div>
                    <a class="smbb-codetool-hero-footer-link" href="<?php echo esc_url($resources_url); ?>">
                        <?php esc_html_e('Ouvrir le registre des ressources', 'smbb-wpcodetool'); ?>
                        <span class="dashicons dashicons-arrow-right-alt2" aria-hidden="true"></span>
                    </a>
                </div>
            </section>

            <div class="smbb-codetool-dashboard-grid">
                <section class="smbb-codetool-metric <?php echo $schema_pending_count > 0 ? esc_attr($schema_attention_variant) : ''; ?>">
                    <p class="smbb-codetool-metric-label"><?php esc_html_e('Schémas en attente', 'smbb-wpcodetool'); ?></p>
                    <p class="smbb-codetool-metric-value"><?php echo esc_html((string) $schema_pending_count); ?></p>
                    <p class="smbb-codetool-metric-copy">
                        <?php
                        echo esc_html(
                            $schema_pending_count > 0
                                ? __('Ressources en table personnalisée en attente d\'une mise à jour ou d\'une correction du schéma.', 'smbb-wpcodetool')
                                : __('Tous les schémas de tables personnalisées détectés sont synchronisés.', 'smbb-wpcodetool')
                        );
                        ?>
                    </p>
                </section>

                <section class="smbb-codetool-metric">
                    <p class="smbb-codetool-metric-label"><?php esc_html_e('Ressources détectées', 'smbb-wpcodetool'); ?></p>
                    <p class="smbb-codetool-metric-value"><?php echo esc_html((string) $resource_count); ?></p>
                    <p class="smbb-codetool-metric-copy"><?php esc_html_e('Tous les modèles CodeTool actifs de ce WordPress.', 'smbb-wpcodetool'); ?></p>
                </section>

                <section class="smbb-codetool-metric">
                    <p class="smbb-codetool-metric-label"><?php esc_html_e('Espaces de noms API', 'smbb-wpcodetool'); ?></p>
                    <p class="smbb-codetool-metric-value"><?php echo esc_html((string) $namespace_count); ?></p>
                    <p class="smbb-codetool-metric-copy"><?php esc_html_e('Espaces de noms REST exposés par les ressources détectées.', 'smbb-wpcodetool'); ?></p>
                </section>

                <section class="smbb-codetool-metric">
                    <p class="smbb-codetool-metric-label"><?php esc_html_e('Clients API', 'smbb-wpcodetool'); ?></p>
                    <p class="smbb-codetool-metric-value"><?php echo esc_html((string) $client_count); ?></p>
                    <p class="smbb-codetool-metric-copy"><?php esc_html_e('Identifiants clients autorisés à demander des jetons d\'accès.', 'smbb-wpcodetool'); ?></p>
                </section>

                <section class="smbb-codetool-metric">
                    <p class="smbb-codetool-metric-label"><?php esc_html_e('Routes publiques', 'smbb-wpcodetool'); ?></p>
                    <p class="smbb-codetool-metric-value"><?php echo esc_html((string) $route_count); ?></p>
                    <p class="smbb-codetool-metric-copy"><?php esc_html_e('Points d\'accès hors REST déclarés par les plugins actifs.', 'smbb-wpcodetool'); ?></p>
                </section>

                <section class="smbb-codetool-metric <?php echo $scan_error_count > 0 ? 'is-warning' : ''; ?>">
                    <p class="smbb-codetool-metric-label"><?php esc_html_e('Problèmes de modèles', 'smbb-wpcodetool'); ?></p>
                    <p class="smbb-codetool-metric-value"><?php echo esc_html((string) $scan_error_count); ?></p>
                    <p class="smbb-codetool-metric-copy">
                        <?php
                        echo esc_html(
                            $scan_error_count > 0
                                ? __('Certains modèles JSON n\'ont pas pu être chargés correctement et demandent votre attention.', 'smbb-wpcodetool')
                                : __('Aucune erreur de lecture ou de validation sur les fichiers de modèle détectés.', 'smbb-wpcodetool')
                        );
                        ?>
                    </p>
                </section>

                <section class="smbb-codetool-metric">
                    <p class="smbb-codetool-metric-label"><?php esc_html_e('Pages d\'interface seule', 'smbb-wpcodetool'); ?></p>
                    <p class="smbb-codetool-metric-value"><?php echo esc_html((string) $ui_page_count); ?></p>
                    <p class="smbb-codetool-metric-copy"><?php esc_html_e('Pages d\'admin sans stockage géré.', 'smbb-wpcodetool'); ?></p>
                </section>
            </div>

            <?php if ($scan_error_count > 0) : ?>
                <section class="smbb-codetool-panel smbb-codetool-panel-attention is-warning">
                    <div class="smbb-codetool-panel-header">
                        <h2><?php esc_html_e('Erreurs de validation des modèles', 'smbb-wpcodetool'); ?></h2>
                        <span class="smbb-codetool-badge is-warning">
                            <?php
                            echo esc_html(
                                sprintf(
                                    _n('%d problème', '%d problèmes', $scan_error_count, 'smbb-wpcodetool'),
                                    $scan_error_count
                                )
                            );
                            ?>
                        </span>
                    </div>

                    <p><?php esc_html_e('Au moins un modèle JSON n\'a pas pu être lu ou validé correctement. Ouvrez le registre des ressources pour voir les erreurs par fichier.', 'smbb-wpcodetool'); ?></p>

                    <p>
                        <a class="button button-secondary" href="<?php echo esc_url($resources_url); ?>">
                            <?php esc_html_e('Ouvrir le registre des ressources', 'smbb-wpcodetool'); ?>
                        </a>
                    </p>
                </section>
            <?php endif; ?>

            <?php if ($schema_pending_count > 0) : ?>
                <section class="smbb-codetool-panel smbb-codetool-panel-attention <?php echo esc_attr($schema_attention_variant); ?>">
                    <div class="smbb-codetool-panel-header">
                        <h2><?php esc_html_e('Schéma à mettre à jour', 'smbb-wpcodetool'); ?></h2>
                        <span class="smbb-codetool-badge <?php echo esc_attr($schema_attention_variant); ?>">
                            <?php
                            echo esc_html(
                                sprintf(
                                    _n('%d en attente', '%d en attente', $schema_pending_count, 'smbb-wpcodetool'),
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
                                    __('Certaines ressources en table personnalisée doivent être synchronisées en SQL : %s.', 'smbb-wpcodetool'),
                                    implode(', ', $schema_summary)
                                )
                                : __('Certaines ressources en table personnalisée doivent être synchronisées en SQL.', 'smbb-wpcodetool')
                        );
                        ?>
                    </p>

                    <table class="widefat striped smbb-codetool-table">
                        <thead>
                            <tr>
                                <th><?php esc_html_e('Ressource', 'smbb-wpcodetool'); ?></th>
                                <th><?php esc_html_e('Statut', 'smbb-wpcodetool'); ?></th>
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
                                                <?php esc_html_e('Aperçu SQL', 'smbb-wpcodetool'); ?>
                                            </a>

                                            <?php if ($schema_status['state'] !== 'invalid') : ?>
                                                <form class="smbb-codetool-inline-form" method="post" action="<?php echo esc_url($resources_url); ?>">
                                                    <input type="hidden" name="codetool_schema_action" value="apply">
                                                    <input type="hidden" name="resource" value="<?php echo esc_attr($schema_resource->name()); ?>">
                                                    <?php wp_nonce_field('smbb_codetool_schema_apply_' . $schema_resource->name()); ?>
                                                    <?php submit_button(__('Appliquer', 'smbb-wpcodetool'), 'secondary small', 'submit', false); ?>
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
                    <h2><?php esc_html_e('Accès rapide', 'smbb-wpcodetool'); ?></h2>
                    <p><?php esc_html_e('Accédez directement aux principales zones de l\'outil.', 'smbb-wpcodetool'); ?></p>

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
                    <h2><?php esc_html_e('État du système', 'smbb-wpcodetool'); ?></h2>
                    <table class="widefat striped smbb-codetool-table">
                        <tbody>
                            <tr>
                                <th><?php esc_html_e('Ressources en table', 'smbb-wpcodetool'); ?></th>
                                <td><?php echo esc_html((string) $table_resource_count); ?></td>
                            </tr>
                            <tr>
                                <th><?php esc_html_e('Ressources en options', 'smbb-wpcodetool'); ?></th>
                                <td><?php echo esc_html((string) $option_resource_count); ?></td>
                            </tr>
                            <tr>
                                <th><?php esc_html_e('Pages d\'interface seule', 'smbb-wpcodetool'); ?></th>
                                <td><?php echo esc_html((string) $ui_page_count); ?></td>
                            </tr>
                            <tr>
                                <th><?php esc_html_e('Ressources exposées en API', 'smbb-wpcodetool'); ?></th>
                                <td><?php echo esc_html((string) $api_resource_count); ?></td>
                            </tr>
                            <tr>
                                <th><?php esc_html_e('Routes publiques', 'smbb-wpcodetool'); ?></th>
                                <td><?php echo esc_html((string) $route_count); ?></td>
                            </tr>
                            <tr>
                                <th><?php esc_html_e('Schémas en attente', 'smbb-wpcodetool'); ?></th>
                                <td><?php echo esc_html((string) $schema_pending_count); ?></td>
                            </tr>
                        </tbody>
                    </table>

                    <ul class="smbb-codetool-bullets">
                        <li><?php esc_html_e('La vue d\'ensemble sert de page d\'accueil, pas seulement de tableau de débogage.', 'smbb-wpcodetool'); ?></li>
                        <?php if ($schema_pending_count > 0) : ?>
                            <li><?php esc_html_e('Cette vue d\'ensemble signale les mises à jour SQL à faire avant même d\'ouvrir le registre des ressources.', 'smbb-wpcodetool'); ?></li>
                        <?php else : ?>
                            <li><?php esc_html_e('Tous les schémas de tables personnalisées détectés sont synchronisés.', 'smbb-wpcodetool'); ?></li>
                        <?php endif; ?>
                        <li><?php esc_html_e('Les pages de ressources restent libres d\'offrir une interface sur mesure au-dessus du modèle.', 'smbb-wpcodetool'); ?></li>
                    </ul>
                </aside>
            </div>
        </div>
        <?php
    }
}
