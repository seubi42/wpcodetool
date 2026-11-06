<?php
/**
 * Liste des ateliers.
 *
 * @var \Smbb\WpCodeTool\Admin\Table $table
 */

use Smbb\Tomo\Admin\View;
use Smbb\Tomo\Repository\Lookup;
use Smbb\Tomo\Support\Choices;

defined('ABSPATH') || exit;

$d = 'smbb-tomo';

$shop = \Smbb\Tomo\WooCommerce\Module::active();
$columns = array(
    'name' => array(
        'label' => __('Atelier', $d),
        'sort' => true,
        'actions' => array('edit', 'duplicate', 'delete'),
        'callback' => function ($value, $row) {
            $dot = !empty($row['color']) ? '<span class="tomo-dot" style="background:' . esc_attr($row['color']) . '"></span>' : '';

            return $dot . esc_html($value);
        },
    ),
    'location' => array('label' => __('Lieu', 'smbb-tomo'), 'sort' => true),
    'duration_minutes' => array(
        'label' => __('Durée', $d),
        'callback' => function ($value) {
            return $value ? esc_html(sprintf(__('%d min', 'smbb-tomo'), (int) $value)) : '';
        },
    ),
    'default_price' => array(
        'label' => __('Prix', $d),
        'sort' => true,
        'callback' => function ($value) {
            return View::money($value);
        },
    ),
    'default_capacity' => array('label' => __('Places', $d)),
    'id' => array(
        'label' => __('Créneaux à venir', $d),
        'callback' => function ($value) use ($d) {
            $count = count(\Smbb\Tomo\Repository\Creneaux::upcoming(0, array('atelier_id' => (int) $value)));
            $url = View::filtered('tomo_creneau', 'atelier_id', (int) $value);

            return '<a href="' . esc_url($url) . '">' . esc_html(sprintf(_n('%d créneau', '%d créneaux', $count, $d), $count)) . '</a>';
        },
    ),
    'status' => array(
        'label' => __('Statut', $d),
        'sort' => true,
        'callback' => function ($value) {
            return Choices::badge('atelierStatuses', (string) $value);
        },
    ),
);

if ($shop) {
    $columns['product_id'] = array(
        'label' => __('Boutique', $d),
        'callback' => function ($value, $row) {
            return \Smbb\Tomo\WooCommerce\Products::listCell($row);
        },
    );
}

$table->setColumns($columns, 'name');

View::table($table);
