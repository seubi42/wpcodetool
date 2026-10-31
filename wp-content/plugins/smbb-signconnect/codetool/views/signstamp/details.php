<?php

defined('ABSPATH') || exit;

$item = isset($item) && is_array($item) ? $item : array();
$resource_label = isset($resource_label) ? $resource_label : __('Sender stamp details', 'smbb-signconnect');
?>

<div class="wrap smbb-codetool smbb-signconnect">
    <?php echo !empty($page_header_html) ? $page_header_html : '<h1>' . esc_html($resource_label) . '</h1>'; ?>
    <?php echo !empty($notices_html) ? $notices_html : ''; ?>
    <div class="smbb-codetool-details-panel">
        <table class="widefat smbb-codetool-details-table">
            <tbody>
                <?php foreach ($item as $key => $value) : ?>
                    <tr>
                        <th scope="row"><?php echo esc_html(ucwords(str_replace('_', ' ', (string) $key))); ?></th>
                        <td>
                            <?php if ((string) $key === 'image_attachment_id' && absint($value) > 0) : ?>
                                <?php echo wp_get_attachment_image(absint($value), 'medium'); ?>
                            <?php elseif ($value === null || $value === '') : ?>
                                &mdash;
                            <?php else : ?>
                                <?php echo esc_html((string) $value); ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
