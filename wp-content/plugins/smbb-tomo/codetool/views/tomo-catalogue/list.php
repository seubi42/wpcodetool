<?php
/**
 * Catalogue sur place : la liste des catalogues et sous-catalogues.
 *
 * @var \Smbb\WpCodeTool\Admin\Table $table
 */

use Smbb\Tomo\Admin\View;
use Smbb\Tomo\Repository\Catalogues;
use Smbb\Tomo\Repository\Lookup;

defined('ABSPATH') || exit;

$d = 'smbb-tomo';
$everyone = '<span class="tomo-muted">' . esc_html__('Tous', $d) . '</span>';

$table->setColumns(array(
    'name' => array(
        'label' => __('Catalogue', $d),
        'sort' => true,
        'actions' => array('edit', 'duplicate', 'delete'),
        'callback' => function ($value, $row) use ($d) {
            $path = Catalogues::path($row['id']);
            $parent = strrpos($path, ' › ') !== false ? substr($path, 0, strrpos($path, ' › ')) : '';

            return ($parent !== '' ? '<span class="tomo-muted">' . esc_html($parent) . ' › </span>' : '')
                . '<strong>' . esc_html((string) $value) . '</strong>'
                . (!(int) $row['active'] ? ' <span class="tomo-badge tomo-badge--draft">' . esc_html__('Masqué', $d) . '</span>' : '');
        },
    ),
    'atelier_ids' => array(
        'label' => __('Ateliers', $d),
        'callback' => function ($value) use ($everyone) {
            $names = array_filter(array_map(array(Lookup::class, 'atelierName'), Lookup::ids($value)));

            return $names ? esc_html(implode(', ', $names)) : $everyone;
        },
    ),
    'intervenant_ids' => array(
        'label' => __('Intervenants', $d),
        'callback' => function ($value) use ($everyone) {
            $names = Lookup::intervenantNames($value);

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
