<?php
/**
 * Plugin Name: SMBB WP DocUX
 * Description: Gutenberg blocks for presenting documented app steps inside a simulated phone or tablet.
 * Version: 0.1.0
 * Author: SMBB
 * Text Domain: smbb-wpdocux
 * Requires at least: 6.0
 * Requires PHP: 7.4
 */

defined('ABSPATH') || exit;

define('SMBB_WPDOCUX_FILE', __FILE__);
define('SMBB_WPDOCUX_PATH', plugin_dir_path(__FILE__));
define('SMBB_WPDOCUX_URL', plugin_dir_url(__FILE__));
define('SMBB_WPDOCUX_VERSION', '0.1.0');

add_action('init', 'smbb_wpdocux_register_blocks');

function smbb_wpdocux_register_blocks()
{
    register_block_type(SMBB_WPDOCUX_PATH . 'blocks/device-step');
    register_block_type(SMBB_WPDOCUX_PATH . 'blocks/device-walkthrough', array(
        'render_callback' => 'smbb_wpdocux_render_device_walkthrough',
    ));
}

function smbb_wpdocux_render_device_walkthrough($attributes, $content, $block)
{
    $format = isset($attributes['format']) ? sanitize_key((string) $attributes['format']) : 'landscape';
    $transition = isset($attributes['transition']) ? sanitize_key((string) $attributes['transition']) : 'fade';
    $indicator_style = isset($attributes['indicatorStyle']) ? sanitize_key((string) $attributes['indicatorStyle']) : 'numbers';
    $button_font_size = isset($attributes['buttonFontSize']) ? (int) $attributes['buttonFontSize'] : 14;
    $instance_id = function_exists('wp_unique_id') ? wp_unique_id('smbb-wpdocux-') : ('smbb-wpdocux-' . uniqid());

    if (!in_array($format, array('landscape', 'portrait', 'computer'), true)) {
        $format = 'landscape';
    }

    if (!in_array($transition, array('fade', 'slide', 'zoom', 'none'), true)) {
        $transition = 'fade';
    }

    if (!in_array($indicator_style, array('numbers', 'dots'), true)) {
        $indicator_style = 'numbers';
    }

    $button_font_size = max(11, min(20, $button_font_size));

    $steps = smbb_wpdocux_collect_device_steps($block, $content);

    if (count($steps) === 0) {
        return '';
    }

    $wrapper_attributes = get_block_wrapper_attributes(array(
        'class' => 'smbb-wpdocux-device is-' . $format . ' has-transition-' . $transition . ' has-indicators-' . $indicator_style,
        'data-smbb-wpdocux-device' => '1',
        'data-transition' => $transition,
        'style' => '--smbb-wpdocux-step-font-size: ' . $button_font_size . 'px;',
    ));

    ob_start();
    ?>
    <div <?php echo $wrapper_attributes; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
        <div class="smbb-wpdocux-device__steps" role="tablist">
            <?php foreach ($steps as $index => $step) : ?>
                <button
                    class="smbb-wpdocux-device__step<?php echo $index === 0 ? ' is-active' : ''; ?>"
                    type="button"
                    role="tab"
                    aria-selected="<?php echo $index === 0 ? 'true' : 'false'; ?>"
                    aria-controls="<?php echo esc_attr($instance_id . 'panel-' . $index); ?>"
                    aria-label="<?php echo esc_attr($step['number'] . ' ' . $step['title']); ?>"
                    data-smbb-wpdocux-step="<?php echo esc_attr($index); ?>"
                >
                    <span class="smbb-wpdocux-device__step-number"><?php echo esc_html($step['number']); ?></span>
                    <span class="smbb-wpdocux-device__step-dot" aria-hidden="true"></span>
                    <span class="smbb-wpdocux-device__step-title"><?php echo esc_html($step['title']); ?></span>
                </button>
            <?php endforeach; ?>
        </div>

        <div class="smbb-wpdocux-device__shell" aria-live="polite">
            <?php if ($format === 'computer') : ?>
                <div class="smbb-wpdocux-device__monitor-bar" aria-hidden="true">
                    <span></span>
                    <span></span>
                    <span></span>
                </div>
            <?php endif; ?>
            <div class="smbb-wpdocux-device__screen">
                <?php foreach ($steps as $index => $step) : ?>
                    <figure
                        class="smbb-wpdocux-device__panel<?php echo $index === 0 ? ' is-active' : ''; ?>"
                        id="<?php echo esc_attr($instance_id . 'panel-' . $index); ?>"
                        role="tabpanel"
                        data-smbb-wpdocux-panel="<?php echo esc_attr($index); ?>"
                        <?php echo $index === 0 ? '' : 'hidden'; ?>
                    >
                        <?php if ($step['image_url'] !== '') : ?>
                            <img src="<?php echo esc_url($step['image_url']); ?>" alt="<?php echo esc_attr($step['image_alt']); ?>" loading="lazy">
                            <?php foreach ($step['hotspots'] as $hotspot_index => $hotspot) : ?>
                                <span
                                    class="smbb-wpdocux-device__hotspot"
                                    style="<?php echo esc_attr('--hotspot-x: ' . $hotspot['x'] . '%; --hotspot-y: ' . $hotspot['y'] . '%; --hotspot-color: ' . $hotspot['color'] . ';'); ?>"
                                    tabindex="0"
                                    aria-label="<?php echo esc_attr($hotspot['tooltip']); ?>"
                                >
                                    <span class="smbb-wpdocux-device__hotspot-dot"><?php echo esc_html((string) ($hotspot_index + 1)); ?></span>
                                    <?php if ($hotspot['tooltip'] !== '') : ?>
                                        <span class="smbb-wpdocux-device__hotspot-tooltip"><?php echo esc_html($hotspot['tooltip']); ?></span>
                                    <?php endif; ?>
                                </span>
                            <?php endforeach; ?>
                        <?php else : ?>
                            <div class="smbb-wpdocux-device__empty"><?php echo esc_html__('Screenshot missing', 'smbb-wpdocux'); ?></div>
                        <?php endif; ?>
                    </figure>
                <?php endforeach; ?>
            </div>
        </div>
        <?php if ($format === 'computer') : ?>
            <div class="smbb-wpdocux-device__stand" aria-hidden="true">
                <span></span>
            </div>
        <?php endif; ?>
    </div>
    <?php

    return ob_get_clean();
}

function smbb_wpdocux_collect_device_steps($block, $content)
{
    $steps = array();

    if (is_object($block) && isset($block->inner_blocks) && is_array($block->inner_blocks)) {
        foreach ($block->inner_blocks as $inner_block) {
            if (!is_object($inner_block) || !isset($inner_block->name) || $inner_block->name !== 'smbb-wpdocux/device-step') {
                continue;
            }

            $attributes = isset($inner_block->attributes) && is_array($inner_block->attributes) ? $inner_block->attributes : array();
            $steps[] = smbb_wpdocux_normalize_device_step($attributes, count($steps));
        }
    }

    if (count($steps) > 0) {
        return $steps;
    }

    if (is_object($block) && isset($block->parsed_block['innerBlocks']) && is_array($block->parsed_block['innerBlocks'])) {
        foreach ($block->parsed_block['innerBlocks'] as $inner_block) {
            if (!is_array($inner_block) || !isset($inner_block['blockName']) || $inner_block['blockName'] !== 'smbb-wpdocux/device-step') {
                continue;
            }

            $attributes = isset($inner_block['attrs']) && is_array($inner_block['attrs']) ? $inner_block['attrs'] : array();
            $steps[] = smbb_wpdocux_normalize_device_step($attributes, count($steps));
        }
    }

    if (count($steps) > 0 || trim((string) $content) === '' || !function_exists('parse_blocks')) {
        return $steps;
    }

    foreach (parse_blocks($content) as $inner_block) {
        if (!is_array($inner_block) || !isset($inner_block['blockName']) || $inner_block['blockName'] !== 'smbb-wpdocux/device-step') {
            continue;
        }

        $attributes = isset($inner_block['attrs']) && is_array($inner_block['attrs']) ? $inner_block['attrs'] : array();
        $steps[] = smbb_wpdocux_normalize_device_step($attributes, count($steps));
    }

    return $steps;
}

function smbb_wpdocux_normalize_device_step($attributes, $index)
{
    $title = isset($attributes['title']) ? sanitize_text_field((string) $attributes['title']) : '';
    $image_url = isset($attributes['imageUrl']) ? esc_url_raw((string) $attributes['imageUrl']) : '';
    $image_alt = isset($attributes['imageAlt']) ? sanitize_text_field((string) $attributes['imageAlt']) : '';
    $hotspots = isset($attributes['hotspots']) && is_array($attributes['hotspots']) ? $attributes['hotspots'] : array();

    return array(
        'number' => str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT),
        'title' => $title !== '' ? $title : sprintf(__('Step %d', 'smbb-wpdocux'), $index + 1),
        'image_url' => $image_url,
        'image_alt' => $image_alt,
        'hotspots' => smbb_wpdocux_normalize_hotspots($hotspots),
    );
}

function smbb_wpdocux_normalize_hotspots($hotspots)
{
    $normalized = array();

    foreach ($hotspots as $hotspot) {
        if (!is_array($hotspot)) {
            continue;
        }

        $x = isset($hotspot['x']) ? (float) $hotspot['x'] : 50;
        $y = isset($hotspot['y']) ? (float) $hotspot['y'] : 50;
        $color = isset($hotspot['color']) ? (string) $hotspot['color'] : '#e11d48';

        if (function_exists('sanitize_hex_color')) {
            $color = sanitize_hex_color($color);
        } elseif (!preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
            $color = '#e11d48';
        }

        $normalized[] = array(
            'x' => max(0, min(100, $x)),
            'y' => max(0, min(100, $y)),
            'color' => $color !== '' ? $color : '#e11d48',
            'tooltip' => isset($hotspot['tooltip']) ? sanitize_text_field((string) $hotspot['tooltip']) : '',
        );
    }

    return $normalized;
}
