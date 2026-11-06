<?php
/**
 * Journal des e-mails et SMS envoyés.
 *
 * @var \Smbb\WpCodeTool\Admin\Table $table
 */

use Smbb\Tomo\Admin\View;
use Smbb\Tomo\Repository\Creneaux;
use Smbb\Tomo\Repository\Lookup;
use Smbb\Tomo\Support\Choices;

defined('ABSPATH') || exit;

$d = 'smbb-tomo';
$creneaux = array();

$table->setColumns(array(
    'sent_at' => array(
        'label' => __('Date', $d),
        'sort' => true,
        'actions' => array('view'),
        'callback' => function ($value) {
            return View::datetime($value);
        },
    ),
    'event' => array(
        'label' => __('Message', $d),
        'sort' => true,
        'callback' => function ($value, $row) {
            return Choices::badge('channels', $row['channel']) . ' ' . esc_html(Choices::label('envoiEvents', $value));
        },
    ),
    'recipient_name' => array(
        'label' => __('Destinataire', $d),
        'callback' => function ($value, $row) {
            $name = $value !== '' ? '<strong>' . esc_html($value) . '</strong> · ' : '';

            return $name . '<span class="tomo-muted">' . esc_html(Choices::label('recipientTypes', $row['recipient_type'])) . '</span><br>' . esc_html($row['recipient']);
        },
    ),
    'creneau_id' => array(
        'label' => __('Créneau', $d),
        'callback' => function ($value) use (&$creneaux) {
            if (!$value) {
                return '';
            }

            if (!array_key_exists((int) $value, $creneaux)) {
                $creneaux[(int) $value] = Creneaux::find($value);
            }

            return $creneaux[(int) $value]
                ? '<a href="' . esc_url(View::url('tomo_creneau', array('view' => 'details', 'id' => (int) $value))) . '">' . esc_html(Lookup::creneauLabel($creneaux[(int) $value])) . '</a>'
                : '#' . (int) $value;
        },
    ),
    'status' => array(
        'label' => __('Résultat', $d),
        'sort' => true,
        'callback' => function ($value, $row) {
            return Choices::badge('envoiStatuses', $value) . ($row['error'] ? '<br><span class="tomo-error">' . esc_html(wp_trim_words((string) $row['error'], 14)) . '</span>' : '');
        },
    ),
), 'sent_at');

View::table(View::readOnlyTable($table), View::journalSections('tomo_envoi'));
