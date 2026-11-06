<?php
/**
 * Liste d'attente, tous créneaux confondus.
 *
 * @var \Smbb\WpCodeTool\Admin\Table $table
 */

use Smbb\Tomo\Admin\Actions;
use Smbb\Tomo\Admin\View;
use Smbb\Tomo\Repository\Creneaux;
use Smbb\Tomo\Repository\Lookup;
use Smbb\Tomo\Support\Choices;

defined('ABSPATH') || exit;

$d = 'smbb-tomo';
$creneaux = array();
$creneau = function ($id) use (&$creneaux) {
    if (!array_key_exists((int) $id, $creneaux)) {
        $creneaux[(int) $id] = Creneaux::find($id);
    }

    return $creneaux[(int) $id];
};

$table->setColumns(array(
    'last_name' => array(
        'label' => __('Personne', $d),
        'sort' => true,
        'actions' => array('edit', 'delete'),
        'callback' => function ($value, $row) {
            return '<strong>' . esc_html(Lookup::personName($row)) . '</strong><br><span class="tomo-muted">' . esc_html(trim($row['email'] . ' · ' . $row['phone'], ' ·')) . '</span>';
        },
    ),
    'creneau_id' => array(
        'label' => __('Créneau', $d),
        'sort' => true,
        'callback' => function ($value) use ($creneau) {
            $row = $creneau($value);

            return $row ? '<a href="' . esc_url(View::url('tomo_creneau', array('view' => 'details', 'id' => (int) $value))) . '">' . esc_html(Lookup::creneauLabel($row)) . '</a><br>' . View::fill($row['booked'], $row['capacity']) : '#' . (int) $value;
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
        'callback' => function ($value, $row) use ($creneau, $d) {
            $html = Choices::badge('waitlistStatuses', (string) $value);
            $slot = $creneau($row['creneau_id']);

            if (in_array($value, array('waiting', 'notified'), true) && $slot && $slot['remaining'] >= max(1, (int) $row['places'])) {
                $html .= ' <a class="button button-small button-primary" href="' . esc_url(View::actionUrl(Actions::WAITLIST_BOOK, array('id' => (int) $row['id']))) . '">' . esc_html__('Inscrire', $d) . '</a>';
            }

            return $html;
        },
    ),
), 'last_name');

View::table($table, View::bookingSections('tomo_attente'));
