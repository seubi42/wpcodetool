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
$events = $document_id > 0 && class_exists('\Smbb\SignConnect\Repository\DocumentAuditRepository')
    ? (new \Smbb\SignConnect\Repository\DocumentAuditRepository())->listForDocument($document_id, 40)
    : array();
$signed_at = !empty($item['sign_date']) ? mysql2date(get_option('date_format') . ' ' . get_option('time_format'), (string) $item['sign_date']) : '';
$signer_name = trim((isset($item['signer_first_name']) ? (string) $item['signer_first_name'] : '') . ' ' . (isset($item['signer_last_name']) ? (string) $item['signer_last_name'] : ''));
$return_status = isset($item['signer_return_status']) ? (string) $item['signer_return_status'] : '';
$return_status_label = $return_status === 'approved'
    ? __('Good for approval', 'smbb-signconnect')
    : ($return_status === 'refused' ? __('Refusal', 'smbb-signconnect') : __('Signature', 'smbb-signconnect'));
$proof_rows = array(
    __('Response', 'smbb-signconnect') => $return_status_label,
    __('Signer', 'smbb-signconnect') => $signer_name,
    __('Contact', 'smbb-signconnect') => isset($item['signer_contact']) ? (string) $item['signer_contact'] : '',
    __('Signed on', 'smbb-signconnect') => $signed_at,
    __('Place', 'smbb-signconnect') => isset($item['signer_place']) ? (string) $item['signer_place'] : '',
    __('IP address', 'smbb-signconnect') => isset($item['signer_ip']) ? (string) $item['signer_ip'] : '',
);
$context_value = static function ($event) {
    if (empty($event['context'])) {
        return '';
    }

    $context = json_decode((string) $event['context'], true);

    if (!is_array($context)) {
        return (string) $event['context'];
    }

    $parts = array();

    foreach ($context as $key => $value) {
        if (is_array($value) || is_object($value)) {
            $value = wp_json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        if ($value === null || $value === '') {
            continue;
        }

        $parts[] = ucwords(str_replace('_', ' ', (string) $key)) . ': ' . (string) $value;
    }

    return implode("\n", $parts);
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

    <?php if (!empty($item['sign_date'])) : ?>
        <div class="smbb-codetool-details-panel" style="margin-bottom:16px;">
            <h2><?php esc_html_e('Signature proof', 'smbb-signconnect'); ?></h2>
            <table class="widefat smbb-codetool-details-table">
                <tbody>
                    <?php foreach ($proof_rows as $label => $value) : ?>
                        <?php if ($value === '') continue; ?>
                        <tr>
                            <th scope="row"><?php echo esc_html($label); ?></th>
                            <td><?php echo esc_html((string) $value); ?></td>
                        </tr>
                    <?php endforeach; ?>

                    <?php if (!empty($item['signer_return_message'])) : ?>
                        <tr>
                            <th scope="row"><?php esc_html_e('Return message', 'smbb-signconnect'); ?></th>
                            <td><?php echo nl2br(esc_html((string) $item['signer_return_message'])); ?></td>
                        </tr>
                    <?php endif; ?>

                    <?php if (!empty($item['identity_photo_storage_path'])) : ?>
                        <tr>
                            <th scope="row"><?php esc_html_e('Identity photo', 'smbb-signconnect'); ?></th>
                            <td><?php echo esc_html((string) $item['identity_photo_filename']); ?></td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
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

    <?php if ($events) : ?>
        <div class="smbb-codetool-details-panel" style="margin-top:16px;">
            <h2><?php esc_html_e('Audit trail', 'smbb-signconnect'); ?></h2>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Date', 'smbb-signconnect'); ?></th>
                        <th><?php esc_html_e('Event', 'smbb-signconnect'); ?></th>
                        <th><?php esc_html_e('Actor', 'smbb-signconnect'); ?></th>
                        <th><?php esc_html_e('Message', 'smbb-signconnect'); ?></th>
                        <th><?php esc_html_e('Details', 'smbb-signconnect'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($events as $event) : ?>
                        <?php $context = $context_value($event); ?>
                        <tr>
                            <td><?php echo esc_html(!empty($event['event_date']) ? mysql2date(get_option('date_format') . ' ' . get_option('time_format'), (string) $event['event_date']) : ''); ?></td>
                            <td><?php echo esc_html(isset($event['event_type']) ? (string) $event['event_type'] : ''); ?></td>
                            <td><?php echo esc_html(trim((isset($event['actor_type']) ? (string) $event['actor_type'] : '') . ' #' . (isset($event['actor_id']) ? (string) $event['actor_id'] : ''))); ?></td>
                            <td><?php echo esc_html(isset($event['message']) ? (string) $event['message'] : ''); ?></td>
                            <td><?php echo $context !== '' ? '<pre style="white-space:pre-wrap;margin:0;max-width:520px;">' . esc_html($context) . '</pre>' : '<span class="smbb-codetool-details-empty">&mdash;</span>'; ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
