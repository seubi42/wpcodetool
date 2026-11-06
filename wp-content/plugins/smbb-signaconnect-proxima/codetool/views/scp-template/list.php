<?php
/**
 * Modèles de détection.
 *
 * @var \Smbb\WpCodeTool\Admin\Table $table
 */

use Smbb\SignConnectProxima\Admin\View;
use Smbb\SignConnectProxima\Repository\Lookup;
use Smbb\SignConnectProxima\Support\Levels;

defined('ABSPATH') || exit;

$d = 'smbb-signaconnect-proxima';

$table->setColumns(array(
    'name' => array('label' => __('Modèle', $d), 'sort' => true, 'actions' => array('edit', 'duplicate', 'delete')),
    'organization_id' => array(
        'label' => __('Organisation', $d),
        'sort' => true,
        'callback' => function ($value) {
            return esc_html(Lookup::organizationName($value));
        },
    ),
    'suggested_level' => array(
        'label' => __('Niveau', $d),
        'sort' => true,
        'callback' => function ($value, $row) use ($d) {
            return esc_html(Levels::label($value)) . (!empty($row['level_enforced']) ? ' <span class="scp-badge">' . esc_html__('imposé', $d) . '</span>' : '');
        },
    ),
    'match_count' => array('label' => __('Détections', $d), 'sort' => true),
    'is_active' => array(
        'label' => __('Statut', $d),
        'sort' => true,
        'callback' => function ($value) use ($d) {
            return $value ? '<span class="scp-badge scp-badge--active">' . esc_html__('Actif', $d) . '</span>' : '<span class="scp-badge scp-badge--archived">' . esc_html__('Inactif', $d) . '</span>';
        },
    ),
), 'name');

View::table($table);
