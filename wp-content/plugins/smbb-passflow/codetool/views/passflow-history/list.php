<?php
/**
 * Historique de tous les mouvements.
 *
 * @var \Smbb\WpCodeTool\Admin\Table $table
 */

use Smbb\Passflow\Admin\View;
use Smbb\Passflow\Repository\Bookings;
use Smbb\Passflow\Repository\Lookup;
use Smbb\Passflow\Support\Choices;

defined('ABSPATH') || exit;

$d = 'smbb-passflow';

$table->setColumns(array(
    'created_at' => array(
        'label' => __('Date', $d),
        'sort' => true,
        'callback' => function ($value) {
            return View::datetime($value);
        },
    ),
    'action' => array(
        'label' => __('Action', $d),
        'sort' => true,
        'callback' => function ($value) {
            return esc_html(Choices::label('historyActions', $value));
        },
    ),
    'booking_id' => array(
        'label' => __('Inscription', $d),
        'callback' => function ($value) {
            $booking = Bookings::find($value);
            $label = $booking ? Lookup::personName($booking) : '#' . (int) $value;

            return '<a href="' . esc_url(View::url('passflow_booking', array('view' => 'details', 'id' => (int) $value))) . '">' . esc_html($label) . '</a>';
        },
    ),
    'message' => array('label' => __('Détail', $d)),
    'user_id' => array(
        'label' => __('Par', $d),
        'callback' => function ($value) use ($d) {
            $user = $value ? get_userdata((int) $value) : null;

            return esc_html($user ? $user->display_name : __('Système', $d));
        },
    ),
), 'created_at');

View::table($table, View::journalSections('passflow_history'));
