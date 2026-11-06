<?php
/**
 * Historique de tous les mouvements.
 *
 * @var \Smbb\WpCodeTool\Admin\Table $table
 */

use Smbb\Tomo\Admin\View;
use Smbb\Tomo\Repository\Inscriptions;
use Smbb\Tomo\Repository\Lookup;
use Smbb\Tomo\Support\Choices;

defined('ABSPATH') || exit;

$d = 'smbb-tomo';

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
    'inscription_id' => array(
        'label' => __('Inscription', $d),
        'callback' => function ($value) {
            $inscription = Inscriptions::find($value);
            $label = $inscription ? Lookup::personName($inscription) : '#' . (int) $value;

            return '<a href="' . esc_url(View::url('tomo_inscription', array('view' => 'details', 'id' => (int) $value))) . '">' . esc_html($label) . '</a>';
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

View::table($table, View::journalSections('tomo_historique'));
