<?php

defined('ABSPATH') || exit;

$item = isset($item) && is_array($item) ? $item : array();
$resource_label = isset($resource_label) ? $resource_label : __('Sender signature details', 'smbb-signconnect');
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
                            <?php if ((string) $key === 'signature_data' && is_string($value) && strpos($value, 'data:image/png;base64,') === 0) : ?>
                                <img src="<?php echo esc_attr($value); ?>" alt="" style="max-width:280px;max-height:100px;background:#fff;border:1px solid #ddd;">
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
