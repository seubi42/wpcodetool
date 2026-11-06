<?php
/**
 * Liste des intervenants.
 *
 * @var \Smbb\WpCodeTool\Admin\Table $table
 */

use Smbb\Tomo\Admin\View;
use Smbb\Tomo\Repository\Creneaux;
use Smbb\Tomo\Repository\Lookup;
use Smbb\Tomo\Support\Choices;

defined('ABSPATH') || exit;

$d = 'smbb-tomo';

$table->setColumns(array(
    'last_name' => array(
        'label' => __('Intervenant', $d),
        'sort' => true,
        'actions' => array('edit', 'delete'),
        'callback' => function ($value, $row) {
            return esc_html(Lookup::personName($row));
        },
    ),
    'email' => array('label' => __('E-mail', $d), 'sort' => true),
    'phone' => array('label' => __('Téléphone', $d)),
    'user_id' => array(
        'label' => __('Compte WordPress', $d),
        'callback' => function ($value) use ($d) {
            return $value && get_userdata((int) $value)
                ? '<span class="dashicons dashicons-yes-alt tomo-ok" aria-hidden="true"></span> ' . esc_html(get_userdata((int) $value)->user_login)
                : '<span class="tomo-muted">' . esc_html__('Aucun', $d) . '</span>';
        },
    ),
    'id' => array(
        'label' => __('Créneaux à venir', $d),
        'callback' => function ($value) use ($d) {
            $count = count(Creneaux::upcoming(0, array('intervenant_id' => (int) $value)));

            return esc_html(sprintf(_n('%d créneau', '%d créneaux', $count, $d), $count));
        },
    ),
    'status' => array(
        'label' => __('Statut', $d),
        'sort' => true,
        'callback' => function ($value) {
            return Choices::badge('intervenantStatuses', (string) $value);
        },
    ),
), 'last_name');

View::table($table);
