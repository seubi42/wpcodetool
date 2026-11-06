<?php
/**
 * Utilisateurs : une ligne par rattachement utilisateur → organisation.
 *
 * @var \Smbb\WpCodeTool\Admin\Table $table
 */

use Smbb\SignConnectProxima\Admin\View;
use Smbb\SignConnectProxima\Repository\Lookup;
use Smbb\SignConnectProxima\Support\Choices;

defined('ABSPATH') || exit;

$d = 'smbb-signaconnect-proxima';

$table->setColumns(array(
    'user_id' => array(
        'label' => __('Utilisateur', $d),
        'sort' => true,
        'actions' => array('edit', 'delete'),
        'callback' => function ($value) {
            return esc_html(Lookup::userLabel($value));
        },
    ),
    'organization_id' => array(
        'label' => __('Organisation', $d),
        'sort' => true,
        'callback' => function ($value) {
            return esc_html(Lookup::organizationName($value));
        },
    ),
    'team_ids' => array(
        'label' => __('Équipes', $d),
        'callback' => function ($value) {
            $ids = is_array($value) ? $value : (array) json_decode((string) $value, true);
            $html = '';

            foreach ($ids as $id) {
                $html .= '<span class="scp-badge">' . esc_html(Lookup::teamName($id)) . '</span> ';
            }

            return '<span class="scp-chips">' . $html . '</span>';
        },
    ),
    'plan_id' => array(
        'label' => __('Forfait', $d),
        'sort' => true,
        'callback' => function ($value) {
            return esc_html(Lookup::planName($value));
        },
    ),
    'role' => array(
        'label' => __('Rôle', $d),
        'sort' => true,
        'callback' => function ($value, $row) use ($d) {
            $html = esc_html(Choices::label('roles', (string) $value));

            if (!empty($row['can_manage_templates'])) {
                $html .= ' <span class="scp-badge">' . esc_html__('Modèles', $d) . '</span>';
            }

            return $html;
        },
    ),
    'is_active' => array(
        'label' => __('Statut', $d),
        'sort' => true,
        'callback' => function ($value) use ($d) {
            return $value ? '<span class="scp-badge scp-badge--active">' . esc_html__('Actif', $d) . '</span>' : '<span class="scp-badge scp-badge--archived">' . esc_html__('Désactivé', $d) . '</span>';
        },
    ),
), 'user_id');

View::table($table);
