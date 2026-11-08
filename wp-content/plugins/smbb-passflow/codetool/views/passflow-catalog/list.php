<?php
/**
 * Catalogue sur place : la liste des catalogues et sous-catalogues.
 *
 * @var \Smbb\WpCodeTool\Admin\Table $table
 */

use Smbb\Passflow\Admin\View;
use Smbb\Passflow\Repository\Catalogs;
use Smbb\Passflow\Repository\Lookup;

defined('ABSPATH') || exit;

$d = 'smbb-passflow';
$everyone = '<span class="passflow-muted">' . esc_html__('Tous', $d) . '</span>';

$table->setColumns(array(
    'name' => array(
        'label' => __('Catalogue', $d),
        'sort' => true,
        'actions' => array('edit', 'duplicate', 'delete'),
        'callback' => function ($value, $row) use ($d) {
            $path = Catalogs::path($row['id']);
            $parent = strrpos($path, ' › ') !== false ? substr($path, 0, strrpos($path, ' › ')) : '';

            return ($parent !== '' ? '<span class="passflow-muted">' . esc_html($parent) . ' › </span>' : '')
                . '<strong>' . esc_html((string) $value) . '</strong>'
                . (!(int) $row['active'] ? ' <span class="passflow-badge passflow-badge--draft">' . esc_html__('Masqué', $d) . '</span>' : '');
        },
    ),
    'activity_ids' => array(
        'label' => __('Ateliers', $d),
        'callback' => function ($value) use ($everyone) {
            $names = array_filter(array_map(array(Lookup::class, 'activityName'), Lookup::ids($value)));

            return $names ? esc_html(implode(', ', $names)) : $everyone;
        },
    ),
    'host_ids' => array(
        'label' => __('Intervenants', $d),
        'callback' => function ($value) use ($everyone) {
            $names = Lookup::hostNames($value);

            return $names !== '' ? esc_html($names) : $everyone;
        },
    ),
    'product_ids' => array(
        'label' => __('Produits', $d),
        'callback' => function ($value) use ($d) {
            $count = count(Lookup::ids($value));

            return esc_html(sprintf(_n('%d produit', '%d produits', $count, $d), $count));
        },
    ),
    'position' => array('label' => __('Ordre', $d), 'sort' => true),
), 'name');

View::table($table);
