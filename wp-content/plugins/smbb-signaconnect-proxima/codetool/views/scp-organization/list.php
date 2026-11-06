<?php
/**
 * Liste des organisations.
 *
 * @var \Smbb\WpCodeTool\Admin\Table $table
 */

use Smbb\SignConnectProxima\Admin\View;
use Smbb\SignConnectProxima\Repository\Lookup;
use Smbb\SignConnectProxima\Support\Choices;
use Smbb\SignConnectProxima\Support\Levels;

defined('ABSPATH') || exit;

$d = 'smbb-signaconnect-proxima';

$table->setColumns(array(
    'name' => array('label' => __('Organisation', $d), 'sort' => true, 'actions' => array('edit', 'delete')),
    'status' => array(
        'label' => __('Statut', $d),
        'sort' => true,
        'callback' => function ($value) {
            return Choices::badge('organizationStatuses', (string) $value);
        },
    ),
    'allowed_levels' => array(
        'label' => __('Niveaux', $d),
        'callback' => function ($value) {
            $levels = is_array($value) ? $value : (array) json_decode((string) $value, true);
            $html = '';

            foreach ($levels as $level) {
                $html .= '<span class="scp-badge">' . esc_html(Levels::label($level)) . '</span> ';
            }

            return '<span class="scp-chips">' . $html . '</span>';
        },
    ),
    'teams' => array(
        'label' => __('Équipes', $d),
        'callback' => function ($value, $row) {
            return esc_html((string) Lookup::count('scp_team', 'organization_id', $row['id']));
        },
    ),
    'members' => array(
        'label' => __('Utilisateurs', $d),
        'callback' => function ($value, $row) {
            return esc_html((string) Lookup::count('scp_membership', 'organization_id', $row['id']));
        },
    ),
    'universign_api_key' => array(
        'label' => __('Universign', $d),
        'callback' => function ($value, $row) use ($d) {
            if (empty($value)) {
                return '<span class="scp-muted">' . esc_html__('Non configuré', $d) . '</span>';
            }

            return esc_html(Choices::label('universignEnvironments', $row['universign_environment']));
        },
    ),
    'fixed_monthly_fee' => array(
        'label' => __('Abonnement org.', $d),
        'sort' => true,
        'callback' => function ($value) {
            return (float) $value > 0 ? View::money($value) : '';
        },
    ),
), 'name');

View::table($table);
