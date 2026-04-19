<?php

/**
 * Vue de detail lecture seule pour la ressource "test".
 *
 * Elle sert a illustrer qu'une action de ligne ne doit pas forcement ouvrir
 * le formulaire. Depuis list.php, l'action "view" peut pointer vers cette vue.
 */

defined('ABSPATH') || exit;

// Le moteur injecte normalement toutes ces variables. On garde des fallbacks simples
// pour que la vue reste tolérante pendant le prototypage.
$item = isset($item) && is_array($item) ? $item : array();
$resource_label = isset($resource_label) ? $resource_label : 'Test details';
$resource_subtitle = isset($resource_subtitle) ? (string) $resource_subtitle : '';
$resource_icon = isset($resource_icon) ? (string) $resource_icon : '';
$list_url = isset($list_url) ? $list_url : '';
$edit_url = isset($edit_url) ? $edit_url : '';
$notices_html = isset($notices_html) ? (string) $notices_html : '';

// On humanise legerement les noms techniques des colonnes pour une lecture plus douce.
$label_for_key = static function ($key) {
    return ucwords(str_replace('_', ' ', (string) $key));
};

// Les valeurs vides gagnent a etre affichees comme un tiret plutot qu'une cellule vide.
$is_empty_value = static function ($value) {
    return $value === null || $value === '';
};
?>

<div class="wrap smbb-codetool smbb-codetool-test">
    <div class="smbb-codetool-page-header">
        <div class="smbb-codetool-page-header-main">
            <?php if (strpos($resource_icon, 'dashicons-') === 0) : ?>
                <span class="smbb-codetool-page-icon" aria-hidden="true">
                    <span class="dashicons <?php echo esc_attr($resource_icon); ?>"></span>
                </span>
            <?php endif; ?>

            <div class="smbb-codetool-page-heading">
                <h1 class="wp-heading-inline"><?php echo esc_html($resource_label); ?></h1>

                <?php if ($resource_subtitle !== '') : ?>
                    <p class="smbb-codetool-page-subtitle"><?php echo esc_html($resource_subtitle); ?></p>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($edit_url || $list_url) : ?>
            <div class="smbb-codetool-page-header-actions">
                <?php if (!empty($edit_url)) : ?>
                    <a href="<?php echo esc_url($edit_url); ?>" class="page-title-action">
                        <span class="dashicons dashicons-edit" aria-hidden="true"></span>
                        <?php esc_html_e('Edit', 'smbb-sample'); ?>
                    </a>
                <?php endif; ?>

                <?php if (!empty($list_url)) : ?>
                    <a href="<?php echo esc_url($list_url); ?>" class="page-title-action">
                        <span class="dashicons dashicons-arrow-left-alt2" aria-hidden="true"></span>
                        <?php esc_html_e('Back to list', 'smbb-sample'); ?>
                    </a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>

    <?php if ($notices_html !== '') : ?>
        <?php echo $notices_html; ?>
    <?php endif; ?>

    <div class="smbb-codetool-details-panel">
        <?php if (!$item) : ?>
            <div class="smbb-codetool-details-empty-state">
                <?php esc_html_e('No item data available.', 'smbb-sample'); ?>
            </div>
        <?php else : ?>
            <table class="widefat smbb-codetool-details-table">
                <tbody>
                    <?php foreach ($item as $key => $value) : ?>
                        <tr>
                            <th scope="row"><?php echo esc_html($label_for_key($key)); ?></th>
                            <td>
                                <?php if ($is_empty_value($value)) : ?>
                                    <span class="smbb-codetool-details-empty">&mdash;</span>
                                <?php elseif (is_bool($value)) : ?>
                                    <?php echo esc_html($value ? __('Yes', 'smbb-sample') : __('No', 'smbb-sample')); ?>
                                <?php elseif (is_scalar($value)) : ?>
                                    <?php echo esc_html((string) $value); ?>
                                <?php else : ?>
                                    <pre class="smbb-codetool-details-json"><code><?php echo esc_html(wp_json_encode($value, JSON_PRETTY_PRINT)); ?></code></pre>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>
