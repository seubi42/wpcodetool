<?php

defined('ABSPATH') || exit;

$item = isset($item) && is_array($item) ? $item : array();
$resource_label = isset($resource_label) ? $resource_label : __('Signature template details', 'smbb-signconnect');
$page_header_html = isset($page_header_html) ? (string) $page_header_html : '';
$notices_html = isset($notices_html) ? (string) $notices_html : '';

$pretty_json = static function ($value) {
    $decoded = is_string($value) && $value !== '' ? json_decode($value, true) : array();

    if (!is_array($decoded)) {
        return '';
    }

    return wp_json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
};
?>

<div class="wrap smbb-codetool smbb-signconnect">
    <?php echo $page_header_html !== '' ? $page_header_html : '<h1>' . esc_html($resource_label) . '</h1>'; ?>
    <?php echo $notices_html; ?>

    <div class="smbb-codetool-details-panel">
        <table class="widefat smbb-codetool-details-table">
            <tbody>
                <?php foreach ($item as $key => $value) : ?>
                    <tr>
                        <th scope="row"><?php echo esc_html(ucwords(str_replace('_', ' ', (string) $key))); ?></th>
                        <td>
                            <?php if (in_array((string) $key, array('detection_zones', 'signature_zones'), true)) : ?>
                                <pre style="white-space:pre-wrap;margin:0;max-height:420px;overflow:auto;"><?php echo esc_html($pretty_json($value)); ?></pre>
                            <?php elseif ($value === null || $value === '') : ?>
                                <span class="smbb-codetool-details-empty">&mdash;</span>
                            <?php elseif ((string) $key === 'is_active') : ?>
                                <?php echo !empty($value) ? esc_html__('Yes', 'smbb-signconnect') : esc_html__('No', 'smbb-signconnect'); ?>
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
