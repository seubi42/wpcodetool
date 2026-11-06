<?php
/**
 * Supervision des documents (lecture seule : ils naissent dans l'app).
 *
 * @var \Smbb\WpCodeTool\Admin\Table $table
 */

use Smbb\SignConnectProxima\Admin\View;
use Smbb\SignConnectProxima\Repository\Lookup;
use Smbb\SignConnectProxima\Support\Choices;
use Smbb\SignConnectProxima\Support\Levels;

defined('ABSPATH') || exit;

$d = 'smbb-signaconnect-proxima';

$table->setColumns(array(
    'filename' => array('label' => __('Document', $d), 'sort' => true, 'actions' => array('view', 'delete')),
    'sent_at' => array(
        'label' => __('Envoyé le', $d),
        'sort' => true,
        'callback' => function ($value) {
            return View::datetime($value);
        },
    ),
    'organization_id' => array(
        'label' => __('Organisation → équipe', $d),
        'sort' => true,
        'callback' => function ($value, $row) {
            $team = Lookup::teamName($row['team_id']);

            return esc_html(Lookup::organizationName($value) . ($team !== '' ? ' → ' . $team : ''));
        },
    ),
    'sender_id' => array(
        'label' => __('Expéditeur', $d),
        'callback' => function ($value) {
            return esc_html(Lookup::userLabel($value));
        },
    ),
    'recipient_email' => array(
        'label' => __('Destinataire', $d),
        'sort' => true,
        'callback' => function ($value, $row) {
            $name = \Smbb\SignConnectProxima\Repository\Documents::recipientName($row);

            return esc_html($name !== '' ? $name . ' <' . $value . '>' : (string) $value);
        },
    ),
    'signature_level' => array(
        'label' => __('Niveau', $d),
        'sort' => true,
        'callback' => function ($value) {
            return esc_html(Levels::label($value));
        },
    ),
    'status' => array(
        'label' => __('Statut', $d),
        'sort' => true,
        'callback' => function ($value) {
            return Choices::badge('documentStatuses', (string) $value);
        },
    ),
    'signed_at' => array(
        'label' => __('Signé le', $d),
        'sort' => true,
        'callback' => function ($value) {
            return View::datetime($value);
        },
    ),
), 'filename');

View::table($table);
