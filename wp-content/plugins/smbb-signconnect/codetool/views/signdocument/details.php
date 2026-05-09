<?php

/**
 * Read-only details view for a SignConnect document.
 */

defined('ABSPATH') || exit;

$item = isset($item) && is_array($item) ? $item : array();
$resource_label = isset($resource_label) ? $resource_label : __('Sign document details', 'smbb-signconnect');
$resource_subtitle = isset($resource_subtitle) ? (string) $resource_subtitle : '';
$resource_icon = isset($resource_icon) ? (string) $resource_icon : '';
$list_url = isset($list_url) ? $list_url : '';
$edit_url = isset($edit_url) ? $edit_url : '';
$notices_html = isset($notices_html) ? (string) $notices_html : '';
$document_id = isset($item['id']) ? absint($item['id']) : 0;
$can_download = $document_id > 0 && !empty($item['storage_id']) && !empty($item['storage_path']);
$download_url = $can_download
    ? wp_nonce_url(
        add_query_arg(array(
            'action' => 'smbb_signconnect_download_document',
            'document_id' => $document_id,
        ), admin_url('admin-post.php')),
        'smbb_signconnect_download_' . $document_id
    )
    : '';
$pdf_url = $can_download
    ? wp_nonce_url(
        add_query_arg(array(
            'action' => 'smbb_signconnect_pdf_document',
            'document_id' => $document_id,
        ), admin_url('admin-ajax.php')),
        'smbb_signconnect_view_pdf_' . $document_id
    )
    : '';

$format_size = static function ($value) {
    $bytes = max(0, (int) $value);

    return function_exists('size_format') ? size_format($bytes, 1) : number_format_i18n($bytes) . ' B';
};
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

                <?php if ($can_download) : ?>
                    <a href="<?php echo esc_url($download_url); ?>" class="page-title-action">
                        <?php esc_html_e('Download', 'smbb-signconnect'); ?>
                    </a>
                    <a href="<?php echo esc_url($pdf_url); ?>" class="page-title-action" target="_blank" rel="noopener noreferrer">
                        <?php esc_html_e('View PDF', 'smbb-signconnect'); ?>
                    </a>
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

    <div class="smbb-codetool-details-panel">
        <?php if (!$item) : ?>
            <div class="smbb-codetool-details-empty-state">
                <?php esc_html_e('No document data available.', 'smbb-signconnect'); ?>
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
                                <?php elseif (in_array((string) $key, array('file_size', 'signed_file_size'), true)) : ?>
                                    <?php echo esc_html($format_size($value)); ?>
                                <?php elseif ((string) $key === 'system_log') : ?>
                                    <pre style="white-space: pre-wrap; max-height: 520px; overflow: auto; margin: 0;"><?php echo esc_html((string) $value); ?></pre>
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
