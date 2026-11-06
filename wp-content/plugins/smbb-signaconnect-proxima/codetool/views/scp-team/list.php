<?php
/**
 * Liste des équipes.
 *
 * @var \Smbb\WpCodeTool\Admin\Table $table
 */

use Smbb\SignConnectProxima\Admin\View;
use Smbb\SignConnectProxima\Repository\Lookup;
use Smbb\SignConnectProxima\Support\Choices;

defined('ABSPATH') || exit;

$d = 'smbb-signaconnect-proxima';

$table->setColumns(array(
    'name' => array('label' => __('Équipe', $d), 'sort' => true, 'actions' => array('edit', 'delete')),
    'organization_id' => array(
        'label' => __('Organisation', $d),
        'sort' => true,
        'callback' => function ($value) {
            return esc_html(Lookup::organizationName($value));
        },
    ),
    'code' => array('label' => __('Code', $d), 'sort' => true),
    'status' => array(
        'label' => __('Statut', $d),
        'sort' => true,
        'callback' => function ($value) {
            return Choices::badge('teamStatuses', (string) $value);
        },
    ),
    'fixed_monthly_fee' => array(
        'label' => __('Abonnement', $d),
        'callback' => function ($value) {
            return (float) $value > 0 ? View::money($value) : '';
        },
    ),
    'discount_subscription_pct' => array(
        'label' => __('Remises', $d),
        'callback' => function ($value, $row) use ($d) {
            $parts = array();

            if ((float) $value > 0) {
                $parts[] = sprintf(__('abonnement −%s', $d), View::percent($value));
            }

            if ((float) $row['discount_overage_pct'] > 0) {
                $parts[] = sprintf(__('dépassement −%s', $d), View::percent($row['discount_overage_pct']));
            }

            return implode('<br>', $parts);
        },
    ),
), 'name');

View::table($table);
