<?php
/**
 * Journal des e-mails et SMS envoyés.
 *
 * @var \Smbb\WpCodeTool\Admin\Table $table
 */

use Smbb\Passflow\Admin\View;
use Smbb\Passflow\Repository\Slots;
use Smbb\Passflow\Repository\Lookup;
use Smbb\Passflow\Support\Choices;

defined('ABSPATH') || exit;

$d = 'smbb-passflow';
$slots = array();

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
            return Choices::badge('channels', $row['channel']) . ' ' . esc_html(Choices::label('deliveryEvents', $value));
        },
    ),
    'recipient_name' => array(
        'label' => __('Destinataire', $d),
        'callback' => function ($value, $row) {
            $name = $value !== '' ? '<strong>' . esc_html($value) . '</strong> · ' : '';

            return $name . '<span class="passflow-muted">' . esc_html(Choices::label('recipientTypes', $row['recipient_type'])) . '</span><br>' . esc_html($row['recipient']);
        },
    ),
    'slot_id' => array(
        'label' => __('Créneau', $d),
        'callback' => function ($value) use (&$slots) {
            if (!$value) {
                return '';
            }

            if (!array_key_exists((int) $value, $slots)) {
                $slots[(int) $value] = Slots::find($value);
            }

            return $slots[(int) $value]
                ? '<a href="' . esc_url(View::url('passflow_slot', array('view' => 'details', 'id' => (int) $value))) . '">' . esc_html(Lookup::slotLabel($slots[(int) $value])) . '</a>'
                : '#' . (int) $value;
        },
    ),
    'status' => array(
        'label' => __('Résultat', $d),
        'sort' => true,
        'callback' => function ($value, $row) {
            return Choices::badge('deliveryStatuses', $value) . ($row['error'] ? '<br><span class="passflow-error">' . esc_html(wp_trim_words((string) $row['error'], 14)) . '</span>' : '');
        },
    ),
), 'sent_at');

View::table(View::readOnlyTable($table), View::journalSections('passflow_delivery'));
