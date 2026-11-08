<?php
/**
 * Liste des inscriptions.
 *
 * @var \Smbb\WpCodeTool\Admin\Table $table
 */

use Smbb\Passflow\Admin\View;
use Smbb\Passflow\Repository\Slots;
use Smbb\Passflow\Repository\Bookings;
use Smbb\Passflow\Repository\Lookup;
use Smbb\Passflow\Support\Choices;

defined('ABSPATH') || exit;

$d = 'smbb-passflow';
$slots = array();

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
    'slot_id' => array(
        'label' => __('Créneau', $d),
        'sort' => true,
        'callback' => function ($value, $row) use (&$slots, $d) {
            if (!$value) {
                return '<em>' . esc_html(Lookup::activityName($row['activity_id'])) . '</em> · <span class="passflow-muted">' . esc_html__('sans date', $d) . '</span>';
            }

            if (!array_key_exists((int) $value, $slots)) {
                $slots[(int) $value] = Slots::find($value);
            }

            $slot = $slots[(int) $value];

            return $slot
                ? '<a href="' . esc_url(View::url('passflow_slot', array('view' => 'details', 'id' => (int) $value))) . '">' . esc_html(Lookup::slotLabel($slot)) . '</a>'
                    . '<br>' . View::seats($slot)
                : '#' . (int) $value;
        },
    ),
    'allergies' => array(
        'label' => __('Allergies', $d),
        'callback' => function ($value) {
            return $value ? '<span class="passflow-allergy">' . esc_html($value) . '</span>' : '';
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
            return Choices::bookingBadge($row);
        },
    ),
    'id' => array(
        'label' => '',
        'callback' => function ($value, $row) use (&$slots, $d) {
            if ($row['status'] === 'pending' && empty($row['slot_id'])) {
                return '<a class="button button-small" href="' . esc_url(View::url('passflow_booking', array('view' => 'details', 'id' => (int) $value))) . '">' . esc_html__('Placer', $d) . '</a>';
            }

            $slot_id = (int) $row['slot_id'];

            if ($slot_id && !array_key_exists($slot_id, $slots)) {
                $slots[$slot_id] = Slots::find($slot_id);
            }

            return '<span class="passflow-actions">' . View::quickActions($row, $slot_id ? $slots[$slot_id] : null) . '</span>';
        },
    ),
), 'last_name');

// Onglets de statut : déclarés dans le modèle (admin.list.presets), affichés par CodeTool sous le titre.
View::table($table, View::bookingSections('passflow_booking'));
