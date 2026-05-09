<?php

defined('ABSPATH') || exit;

$item = isset($item) && is_array($item) ? $item : array();
?>

<div class="wrap smbb-codetool smbb-signconnect">
    <?php echo !empty($page_header_html) ? $page_header_html : '<h1>' . esc_html__('Signature field', 'smbb-signconnect') . '</h1>'; ?>
    <?php echo !empty($notices_html) ? $notices_html : ''; ?>

    <div class="smbb-codetool-details-panel">
        <?php if (!$item) : ?>
            <div class="smbb-codetool-details-empty-state"><?php esc_html_e('No signature field data available.', 'smbb-signconnect'); ?></div>
        <?php else : ?>
            <table class="widefat smbb-codetool-details-table">
                <tbody>
                    <?php foreach ($item as $key => $value) : ?>
                        <tr>
                            <th scope="row"><?php echo esc_html(ucwords(str_replace('_', ' ', (string) $key))); ?></th>
                            <td><?php echo $value === null || $value === '' ? '&mdash;' : esc_html((string) $value); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>
