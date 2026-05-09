<?php

/**
 * Read-only details view for a SignConnect storage.
 */

defined('ABSPATH') || exit;

$item = isset($item) && is_array($item) ? $item : array();
$resource_label = isset($resource_label) ? $resource_label : __('Sign storage details', 'smbb-signconnect');
$resource_subtitle = isset($resource_subtitle) ? (string) $resource_subtitle : '';
$resource_icon = isset($resource_icon) ? (string) $resource_icon : '';
$list_url = isset($list_url) ? $list_url : '';
$edit_url = isset($edit_url) ? $edit_url : '';
$notices_html = isset($notices_html) ? (string) $notices_html : '';
$storage_id = isset($item['id']) ? absint($item['id']) : 0;
$test_notice = $storage_id > 0 && function_exists('smbb_signconnect_storage_test_notice')
    ? smbb_signconnect_storage_test_notice($storage_id)
    : null;
?>

<div class="wrap smbb-codetool smbb-signconnect">
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
                        <?php esc_html_e('Edit', 'smbb-signconnect'); ?>
                    </a>
                <?php endif; ?>

                <?php if ($storage_id > 0) : ?>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;">
                        <input type="hidden" name="action" value="smbb_signconnect_test_storage">
                        <input type="hidden" name="storage_id" value="<?php echo esc_attr((string) $storage_id); ?>">
                        <?php wp_nonce_field('smbb_signconnect_test_storage_' . $storage_id); ?>
                        <button type="submit" class="page-title-action">
                            <?php esc_html_e('Test connection', 'smbb-signconnect'); ?>
                        </button>
                    </form>
                <?php endif; ?>

                <?php if (!empty($list_url)) : ?>
                    <a href="<?php echo esc_url($list_url); ?>" class="page-title-action">
                        <?php esc_html_e('Back to list', 'smbb-signconnect'); ?>
                    </a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>

    <?php if ($notices_html !== '') : ?>
        <?php echo $notices_html; ?>
    <?php endif; ?>

    <?php if ($test_notice) : ?>
        <div class="smbb-codetool-notices">
            <div class="smbb-codetool-notice is-<?php echo esc_attr($test_notice['type'] === 'success' ? 'success' : 'error'); ?>">
                <div class="smbb-codetool-notice-body">
                    <p class="smbb-codetool-notice-message"><?php echo esc_html((string) $test_notice['message']); ?></p>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <div class="smbb-codetool-details-panel">
        <?php if (!$item) : ?>
            <div class="smbb-codetool-details-empty-state">
                <?php esc_html_e('No storage data available.', 'smbb-signconnect'); ?>
            </div>
        <?php else : ?>
            <table class="widefat smbb-codetool-details-table">
                <tbody>
                    <?php foreach ($item as $key => $value) : ?>
                        <tr>
                            <th scope="row"><?php echo esc_html(ucwords(str_replace('_', ' ', (string) $key))); ?></th>
                            <td>
                                <?php if ($value === null || $value === '') : ?>
                                    <span class="smbb-codetool-details-empty">&mdash;</span>
                                <?php else : ?>
                                    <?php echo esc_html((string) $value); ?>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>
