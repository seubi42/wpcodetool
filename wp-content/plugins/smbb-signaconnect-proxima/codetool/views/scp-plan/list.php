<?php
/**
 * Catalogue des forfaits.
 *
 * @var \Smbb\WpCodeTool\Admin\Table $table
 */

use Smbb\SignConnectProxima\Admin\View;
use Smbb\SignConnectProxima\Repository\Lookup;
use Smbb\SignConnectProxima\Support\Choices;
use Smbb\SignConnectProxima\Support\Levels;

defined('ABSPATH') || exit;

$d = 'smbb-signaconnect-proxima';

$columns = array(
    'name' => array('label' => __('Forfait', $d), 'sort' => true, 'actions' => array('edit', 'duplicate', 'delete')),
    'monthly_fee' => array(
        'label' => __('Abonnement', $d),
        'sort' => true,
        'callback' => function ($value) {
            return View::money($value);
        },
    ),
);

foreach (Levels::labels() as $level => $label) {
    $columns['included_' . $level] = array(
        'label' => $label,
        'callback' => function ($value, $row) use ($level, $d) {
            if (!empty($row['unlimited_' . $level])) {
                return esc_html__('Illimité', $d);
            }

            return esc_html(sprintf(
                /* translators: 1: included signatures, 2: overage price, 3: recharge size */
                __('%1$d incluses · %2$s € au-delà · par %3$d', $d),
                (int) $value,
                number_format_i18n((float) $row['overage_price_' . $level], 2),
                (int) $row['recharge_size_' . $level]
            ));
        },
    );
}

$columns['status'] = array(
    'label' => __('Statut', $d),
    'sort' => true,
    'callback' => function ($value) {
        return Choices::badge('planStatuses', (string) $value);
    },
);
$columns['usage'] = array(
    'label' => __('Utilisateurs', $d),
    'callback' => function ($value, $row) {
        return esc_html((string) Lookup::count('scp_membership', 'plan_id', $row['id']));
    },
);

$table->setColumns($columns, 'name');

View::table($table);
