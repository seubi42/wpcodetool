<?php

namespace Smbb\WpCodeTool\Admin;

defined('ABSPATH') || exit;

/**
 * Petit renderer pour les pages dashboard/overview.
 *
 * L'objectif n'est pas de remplacer completement les vues PHP, mais de leur donner
 * des briques reutilisables comme on le fait deja avec Table et Form :
 * - hero marketing / intro ;
 * - grille de metrics ;
 * - panneaux ;
 * - listes de liens ;
 * - tableau simple de lecture seule ;
 * - table "definition / valeur".
 */
final class Dashboard
{
    private $context;

    public function __construct(array $context = array())
    {
        $this->context = $context;
    }

    public function hero(array $hero = array())
    {
        if (!$hero) {
            return '';
        }

        $actions = isset($hero['actions']) && is_array($hero['actions']) ? $hero['actions'] : array();
        $tiles = isset($hero['tiles']) && is_array($hero['tiles']) ? $hero['tiles'] : array();
        $footer = isset($hero['footer']) && is_array($hero['footer']) ? $hero['footer'] : array();

        ob_start();
        ?>
        <section class="smbb-codetool-hero">
            <div class="smbb-codetool-hero-inner">
                <div class="smbb-codetool-hero-main">
                    <?php if (!empty($hero['eyebrow'])) : ?>
                        <p class="smbb-codetool-hero-eyebrow"><?php echo esc_html((string) $hero['eyebrow']); ?></p>
                    <?php endif; ?>

                    <?php if (!empty($hero['title'])) : ?>
                        <h2 class="smbb-codetool-hero-title">
                            <?php echo esc_html((string) $hero['title']); ?>
                            <?php if (!empty($hero['badge'])) : ?>
                                <span class="smbb-codetool-hero-chip"><?php echo esc_html((string) $hero['badge']); ?></span>
                            <?php endif; ?>
                        </h2>
                    <?php endif; ?>

                    <?php if (!empty($hero['description'])) : ?>
                        <p class="smbb-codetool-hero-desc"><?php echo esc_html((string) $hero['description']); ?></p>
                    <?php endif; ?>

                    <?php if ($actions) : ?>
                        <div class="smbb-codetool-hero-actions">
                            <?php foreach ($actions as $action) : ?>
                                <?php
                                if (!is_array($action) || empty($action['label']) || empty($action['url'])) {
                                    continue;
                                }

                                $variant = !empty($action['variant']) && $action['variant'] === 'primary' ? 'is-primary' : 'is-secondary';
                                $target = !empty($action['target']) ? (string) $action['target'] : '';
                                ?>
                                <a class="smbb-codetool-hero-button <?php echo esc_attr($variant); ?>" href="<?php echo esc_url((string) $action['url']); ?>"<?php echo $target === '_blank' ? ' target="_blank" rel="noopener noreferrer"' : ''; ?>>
                                    <?php echo esc_html((string) $action['label']); ?>
                                    <span class="dashicons dashicons-arrow-right-alt2" aria-hidden="true"></span>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <?php if ($tiles) : ?>
                    <div class="smbb-codetool-hero-grid">
                        <?php foreach ($tiles as $tile) : ?>
                            <?php if (!is_array($tile) || empty($tile['label'])) : ?>
                                <?php continue; ?>
                            <?php endif; ?>
                            <div class="smbb-codetool-hero-tile">
                                <span class="smbb-codetool-hero-tile-icon">
                                    <span class="dashicons <?php echo esc_attr(!empty($tile['icon']) ? (string) $tile['icon'] : 'dashicons-admin-generic'); ?>" aria-hidden="true"></span>
                                </span>
                                <div class="smbb-codetool-hero-tile-copy">
                                    <strong class="smbb-codetool-hero-tile-label"><?php echo esc_html((string) $tile['label']); ?></strong>
                                    <?php if (!empty($tile['meta'])) : ?>
                                        <span class="smbb-codetool-hero-tile-meta"><?php echo esc_html((string) $tile['meta']); ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <?php if (!empty($footer['text']) || !empty($footer['link'])) : ?>
                <div class="smbb-codetool-hero-footer">
                    <div class="smbb-codetool-hero-footer-copy">
                        <?php echo !empty($footer['text']) ? esc_html((string) $footer['text']) : ''; ?>
                    </div>
                    <?php if (!empty($footer['link']) && is_array($footer['link']) && !empty($footer['link']['label']) && !empty($footer['link']['url'])) : ?>
                        <a class="smbb-codetool-hero-footer-link" href="<?php echo esc_url((string) $footer['link']['url']); ?>">
                            <?php echo esc_html((string) $footer['link']['label']); ?>
                            <span class="dashicons dashicons-arrow-right-alt2" aria-hidden="true"></span>
                        </a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </section>
        <?php

        return (string) ob_get_clean();
    }

    public function metrics(array $metrics = array())
    {
        if (!$metrics) {
            return '';
        }

        ob_start();
        ?>
        <div class="smbb-codetool-dashboard-grid">
            <?php foreach ($metrics as $metric) : ?>
                <?php if (!is_array($metric) || empty($metric['label'])) : ?>
                    <?php continue; ?>
                <?php endif; ?>
                <section class="smbb-codetool-metric<?php echo !empty($metric['class']) ? ' ' . esc_attr((string) $metric['class']) : ''; ?>">
                    <p class="smbb-codetool-metric-label"><?php echo esc_html((string) $metric['label']); ?></p>
                    <p class="smbb-codetool-metric-value"><?php echo esc_html(isset($metric['value']) ? (string) $metric['value'] : ''); ?></p>
                    <?php if (!empty($metric['copy'])) : ?>
                        <p class="smbb-codetool-metric-copy"><?php echo esc_html((string) $metric['copy']); ?></p>
                    <?php endif; ?>
                </section>
            <?php endforeach; ?>
        </div>
        <?php

        return (string) ob_get_clean();
    }

    public function split(array $sections = array())
    {
        $sections = array_values(array_filter($sections, static function ($section) {
            return (string) $section !== '';
        }));

        if (!$sections) {
            return '';
        }

        return '<div class="smbb-codetool-split">' . implode('', $sections) . '</div>';
    }

    public function panel($title, $description = '', $content = '', array $options = array())
    {
        $tag = !empty($options['tag']) && in_array($options['tag'], array('section', 'aside', 'div'), true) ? $options['tag'] : 'section';
        $class = 'smbb-codetool-panel';

        if (!empty($options['class'])) {
            $class .= ' ' . trim((string) $options['class']);
        }

        ob_start();
        ?>
        <<?php echo $tag; ?> class="<?php echo esc_attr($class); ?>">
            <?php if ((string) $title !== '') : ?>
                <h2><?php echo esc_html((string) $title); ?></h2>
            <?php endif; ?>

            <?php if ((string) $description !== '') : ?>
                <p><?php echo esc_html((string) $description); ?></p>
            <?php endif; ?>

            <?php echo (string) $content; ?>
        </<?php echo $tag; ?>>
        <?php

        return (string) ob_get_clean();
    }

    public function linkList(array $items = array(), $empty_message = '')
    {
        $renderable = array_values(array_filter($items, static function ($item) {
            return is_array($item) && !empty($item['label']) && !empty($item['url']);
        }));

        if (!$renderable) {
            return $empty_message !== '' ? '<p>' . esc_html((string) $empty_message) . '</p>' : '';
        }

        ob_start();
        ?>
        <ul class="smbb-codetool-link-list">
            <?php foreach ($renderable as $item) : ?>
                <?php $target = !empty($item['target']) ? (string) $item['target'] : ''; ?>
                <li>
                    <a href="<?php echo esc_url((string) $item['url']); ?>"<?php echo $target === '_blank' ? ' target="_blank" rel="noopener noreferrer"' : ''; ?>>
                        <?php echo esc_html((string) $item['label']); ?>
                    </a>
                    <?php if (!empty($item['description'])) : ?>
                        <small class="smbb-codetool-link-meta"><?php echo esc_html((string) $item['description']); ?></small>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
        <?php

        return (string) ob_get_clean();
    }

    public function bullets(array $items = array())
    {
        $items = array_values(array_filter($items, static function ($item) {
            return (string) $item !== '';
        }));

        if (!$items) {
            return '';
        }

        ob_start();
        ?>
        <ul class="smbb-codetool-bullets">
            <?php foreach ($items as $item) : ?>
                <li><?php echo esc_html((string) $item); ?></li>
            <?php endforeach; ?>
        </ul>
        <?php

        return (string) ob_get_clean();
    }

    public function definitionTable(array $rows = array())
    {
        $rows = array_values(array_filter($rows, static function ($row) {
            return is_array($row) && !empty($row['label']);
        }));

        if (!$rows) {
            return '';
        }

        ob_start();
        ?>
        <table class="widefat striped smbb-codetool-table">
            <tbody>
                <?php foreach ($rows as $row) : ?>
                    <tr>
                        <th><?php echo esc_html((string) $row['label']); ?></th>
                        <td>
                            <?php
                            if (isset($row['html'])) {
                                echo wp_kses_post((string) $row['html']);
                            } else {
                                echo esc_html(isset($row['value']) ? (string) $row['value'] : '');
                            }
                            ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php

        return (string) ob_get_clean();
    }

    public function table(array $columns = array(), array $rows = array(), array $options = array())
    {
        $empty_message = isset($options['empty_message']) ? (string) $options['empty_message'] : '';

        ob_start();
        ?>
        <table class="widefat striped smbb-codetool-table">
            <thead>
                <tr>
                    <?php foreach ($columns as $column) : ?>
                        <?php if (!is_array($column) || empty($column['label'])) : ?>
                            <?php continue; ?>
                        <?php endif; ?>
                        <th><?php echo esc_html((string) $column['label']); ?></th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
                <?php if (!$rows) : ?>
                    <tr>
                        <td colspan="<?php echo esc_attr((string) count($columns)); ?>">
                            <?php echo esc_html($empty_message !== '' ? $empty_message : __('No items found.', 'smbb-wpcodetool')); ?>
                        </td>
                    </tr>
                <?php else : ?>
                    <?php foreach ($rows as $index => $row) : ?>
                        <tr>
                            <?php foreach ($columns as $column) : ?>
                                <td>
                                    <?php
                                    $value = '';

                                    if (isset($column['render']) && is_callable($column['render'])) {
                                        $value = (string) call_user_func($column['render'], $row, $index, $this->context);
                                    } elseif (!empty($column['key']) && is_array($row) && array_key_exists($column['key'], $row)) {
                                        $value = esc_html((string) $row[$column['key']]);
                                    }

                                    echo wp_kses_post($value);
                                    ?>
                                </td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
        <?php

        return (string) ob_get_clean();
    }
}
