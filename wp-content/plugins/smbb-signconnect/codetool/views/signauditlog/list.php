<?php

/**
 * Admin list for SignConnect audit events.
 *
 * @var \Smbb\WpCodeTool\Admin\Table $table
 */

defined('ABSPATH') || exit;

if (!isset($table)) {
    $table = new \Smbb\WpCodeTool\Admin\Table(array(
        'admin_url' => isset($admin_url) ? $admin_url : '',
        'create_url' => isset($create_url) ? $create_url : '',
        'primary_key' => isset($primary_key) ? $primary_key : 'id',
        'resource_label' => isset($resource_label) ? $resource_label : 'Sign audit logs',
        'resource_name' => isset($resource_name) ? $resource_name : 'signauditlog',
        'rows' => isset($rows) && is_array($rows) ? $rows : array(),
    ));
}

$table->setColumns(array(
    'event_date' => array(
        'label' => __('Date', 'smbb-signconnect'),
        'sort' => true,
        'actions' => array('view', 'delete'),
    ),
    'document_id' => array(
        'label' => __('Document', 'smbb-signconnect'),
        'sort' => true,
    ),
    'event_type' => array(
        'label' => __('Event', 'smbb-signconnect'),
        'sort' => true,
    ),
    'actor_type' => array(
        'label' => __('Actor', 'smbb-signconnect'),
        'sort' => true,
        'callback' => function ($value, $row) {
            $actor_id = isset($row['actor_id']) && (int) $row['actor_id'] > 0 ? ' #' . (int) $row['actor_id'] : '';

            return esc_html(trim((string) $value . $actor_id));
        },
    ),
    'message' => array(
        'label' => __('Message', 'smbb-signconnect'),
        'sort' => true,
    ),
    'context' => array(
        'label' => __('Details', 'smbb-signconnect'),
        'callback' => function ($value) {
            $context = json_decode((string) $value, true);

            if (!is_array($context)) {
                return esc_html(mb_substr((string) $value, 0, 180));
            }

            $parts = array();

            foreach ($context as $key => $item) {
                if ($item === null || $item === '') {
                    continue;
                }

                if (is_array($item) || is_object($item)) {
                    $item = wp_json_encode($item, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                }

                if ((string) $key === 'cryptographic_signature_applied') {
                    $item = !empty($item) ? __('Yes', 'smbb-signconnect') : __('No', 'smbb-signconnect');
                }

                $parts[] = ucwords(str_replace('_', ' ', (string) $key)) . ': ' . (string) $item;

                if (count($parts) >= 4) {
                    break;
                }
            }

            return $parts ? '<pre style="white-space:pre-wrap;margin:0;max-width:520px;">' . esc_html(implode("\n", $parts)) . '</pre>' : '&mdash;';
        },
    ),
), 'event_date');

$table->render();
