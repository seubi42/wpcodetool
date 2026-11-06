<?php
/**
 * Liste des inscriptions.
 *
 * @var \Smbb\WpCodeTool\Admin\Table $table
 */

use Smbb\Tomo\Admin\View;
use Smbb\Tomo\Repository\Creneaux;
use Smbb\Tomo\Repository\Inscriptions;
use Smbb\Tomo\Repository\Lookup;
use Smbb\Tomo\Support\Choices;

defined('ABSPATH') || exit;

$d = 'smbb-tomo';
$creneaux = array();

$table->setColumns(array(
    'last_name' => array(
        'label' => __('Participant', $d),
        'sort' => true,
        'actions' => array('view', 'edit', 'delete'),
        'callback' => function ($value, $row) {
            return '<strong>' . esc_html(Lookup::personName($row)) . '</strong>';
        },
    ),
    'email' => array(
        'label' => __('Contact', $d),
        'callback' => function ($value, $row) {
            return esc_html(trim($row['email'] . ' · ' . $row['phone'], ' ·'));
        },
    ),
    'creneau_id' => array(
        'label' => __('Créneau', $d),
        'sort' => true,
        'callback' => function ($value, $row) use (&$creneaux, $d) {
            if (!$value) {
                return '<em>' . esc_html(Lookup::atelierName($row['atelier_id'])) . '</em> · <span class="tomo-muted">' . esc_html__('sans date', $d) . '</span>';
            }

            if (!array_key_exists((int) $value, $creneaux)) {
                $creneaux[(int) $value] = Creneaux::find($value);
            }

            $creneau = $creneaux[(int) $value];

            return $creneau
                ? '<a href="' . esc_url(View::url('tomo_creneau', array('view' => 'details', 'id' => (int) $value))) . '">' . esc_html(Lookup::creneauLabel($creneau)) . '</a>'
                    . '<br>' . View::seats($creneau)
                : '#' . (int) $value;
        },
    ),
    'allergies' => array(
        'label' => __('Allergies', $d),
        'callback' => function ($value) {
            return $value ? '<span class="tomo-allergy">' . esc_html($value) . '</span>' : '';
        },
    ),
    'source' => array(
        'label' => __('Source', $d),
        'sort' => true,
        'callback' => function ($value) {
            return esc_html(Choices::label('sources', $value));
        },
    ),
    'status' => array(
        'label' => __('Statut', $d),
        'sort' => true,
        'callback' => function ($value, $row) {
            return Choices::inscriptionBadge($row);
        },
    ),
    'id' => array(
        'label' => '',
        'callback' => function ($value, $row) use (&$creneaux, $d) {
            if ($row['status'] === 'pending' && empty($row['creneau_id'])) {
                return '<a class="button button-small" href="' . esc_url(View::url('tomo_inscription', array('view' => 'details', 'id' => (int) $value))) . '">' . esc_html__('Placer', $d) . '</a>';
            }

            $creneau_id = (int) $row['creneau_id'];

            if ($creneau_id && !array_key_exists($creneau_id, $creneaux)) {
                $creneaux[$creneau_id] = Creneaux::find($creneau_id);
            }

            return '<span class="tomo-actions">' . View::quickActions($row, $creneau_id ? $creneaux[$creneau_id] : null) . '</span>';
        },
    ),
), 'last_name');

// Onglets de statut : déclarés dans le modèle (admin.list.presets), affichés par CodeTool sous le titre.
View::table($table, View::bookingSections('tomo_inscription'));
