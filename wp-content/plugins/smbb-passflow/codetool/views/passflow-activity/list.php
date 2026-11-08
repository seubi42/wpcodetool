<?php
/**
 * Liste des ateliers.
 *
 * @var \Smbb\WpCodeTool\Admin\Table $table
 */

use Smbb\Passflow\Admin\View;
use Smbb\Passflow\Repository\Lookup;
use Smbb\Passflow\Support\Choices;

defined('ABSPATH') || exit;

$d = 'smbb-passflow';

$shop = \Smbb\Passflow\WooCommerce\Module::active();
$columns = array(
    'name' => array(
        'label' => __('Atelier', $d),
        'sort' => true,
        'actions' => array('edit', 'duplicate', 'delete'),
        'callback' => function ($value, $row) {
            $dot = !empty($row['color']) ? '<span class="passflow-dot" style="background:' . esc_attr($row['color']) . '"></span>' : '';

            return $dot . esc_html($value);
        },
    ),
    'location' => array('label' => __('Lieu', 'smbb-passflow'), 'sort' => true),
    'duration_minutes' => array(
        'label' => __('Durée', $d),
        'callback' => function ($value) {
            return $value ? esc_html(sprintf(__('%d min', 'smbb-passflow'), (int) $value)) : '';
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
            $count = count(\Smbb\Passflow\Repository\Slots::upcoming(0, array('activity_id' => (int) $value)));
            $url = View::filtered('passflow_slot', 'activity_id', (int) $value);

            return '<a href="' . esc_url($url) . '">' . esc_html(sprintf(_n('%d créneau', '%d créneaux', $count, $d), $count)) . '</a>';
        },
    ),
    'status' => array(
        'label' => __('Statut', $d),
        'sort' => true,
        'callback' => function ($value) {
            return Choices::badge('activityStatuses', (string) $value);
        },
    ),
);

if ($shop) {
    $columns['product_id'] = array(
        'label' => __('Boutique', $d),
        'callback' => function ($value, $row) {
            return \Smbb\Passflow\WooCommerce\Products::listCell($row);
        },
    );
}

$table->setColumns($columns, 'name');

View::table($table);
