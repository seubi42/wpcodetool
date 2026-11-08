<?php
/**
 * Liste d'attente, tous créneaux confondus.
 *
 * @var \Smbb\WpCodeTool\Admin\Table $table
 */

use Smbb\Passflow\Admin\Actions;
use Smbb\Passflow\Admin\View;
use Smbb\Passflow\Repository\Slots;
use Smbb\Passflow\Repository\Lookup;
use Smbb\Passflow\Support\Choices;

defined('ABSPATH') || exit;

$d = 'smbb-passflow';
$slots = array();
$slot = function ($id) use (&$slots) {
    if (!array_key_exists((int) $id, $slots)) {
        $slots[(int) $id] = Slots::find($id);
    }

    return $slots[(int) $id];
};

$table->setColumns(array(
    'last_name' => array(
        'label' => __('Personne', $d),
        'sort' => true,
        'actions' => array('edit', 'delete'),
        'callback' => function ($value, $row) {
            return '<strong>' . esc_html(Lookup::personName($row)) . '</strong><br><span class="passflow-muted">' . esc_html(trim($row['email'] . ' · ' . $row['phone'], ' ·')) . '</span>';
        },
    ),
    'slot_id' => array(
        'label' => __('Créneau', $d),
        'sort' => true,
        'callback' => function ($value) use ($slot) {
            $row = $slot($value);

            return $row ? '<a href="' . esc_url(View::url('passflow_slot', array('view' => 'details', 'id' => (int) $value))) . '">' . esc_html(Lookup::slotLabel($row)) . '</a><br>' . View::fill($row['booked'], $row['capacity']) : '#' . (int) $value;
        },
    ),
    'places' => array('label' => __('Places', $d)),
    'creation_date' => array(
        'label' => __('Demandé le', $d),
        'sort' => true,
        'callback' => function ($value) {
            return View::datetime($value);
        },
    ),
    'status' => array(
        'label' => __('Statut', $d),
        'sort' => true,
        'callback' => function ($value, $row) use ($slot, $d) {
            $html = Choices::badge('waitlistStatuses', (string) $value);
            $slot_info = $slot($row['slot_id']);

            if (in_array($value, array('waiting', 'notified'), true) && $slot_info && $slot_info['remaining'] >= max(1, (int) $row['places'])) {
                $html .= ' <a class="button button-small button-primary" href="' . esc_url(View::actionUrl(Actions::WAITLIST_BOOK, array('id' => (int) $row['id']))) . '">' . esc_html__('Inscrire', $d) . '</a>';
            }

            return $html;
        },
    ),
), 'last_name');

View::table($table, View::bookingSections('passflow_waitlist'));
