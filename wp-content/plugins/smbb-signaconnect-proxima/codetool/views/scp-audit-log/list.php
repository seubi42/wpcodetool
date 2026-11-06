<?php
/**
 * Journal d'audit (lecture seule).
 *
 * @var \Smbb\WpCodeTool\Admin\Table $table
 */

use Smbb\SignConnectProxima\Admin\View;
use Smbb\SignConnectProxima\Repository\Lookup;

defined('ABSPATH') || exit;

$d = 'smbb-signaconnect-proxima';

$table->setColumns(array(
    'event_date' => array(
        'label' => __('Date', $d),
        'sort' => true,
        'callback' => function ($value) {
            return View::datetime($value);
        },
    ),
    'organization_id' => array(
        'label' => __('Organisation', $d),
        'sort' => true,
        'callback' => function ($value) {
            return esc_html(Lookup::organizationName($value));
        },
    ),
    'event_type' => array('label' => __('Événement', $d), 'sort' => true),
    'actor_type' => array(
        'label' => __('Acteur', $d),
        'sort' => true,
        'callback' => function ($value, $row) {
            $user = $row['actor_id'] ? Lookup::userLabel($row['actor_id']) : '';

            return esc_html($value . ($user !== '' ? ' · ' . $user : ''));
        },
    ),
    'document_id' => array(
        'label' => __('Document', $d),
        'callback' => function ($value) {
            return $value ? '<a href="' . esc_url(admin_url('admin.php?page=smbb-codetool-scp_document&view=details&id=' . (int) $value)) . '">#' . (int) $value . '</a>' : '';
        },
    ),
    'message' => array('label' => __('Détail', $d)),
    'actor_ip' => array('label' => __('IP', $d)),
), 'event_date');

View::table($table);
