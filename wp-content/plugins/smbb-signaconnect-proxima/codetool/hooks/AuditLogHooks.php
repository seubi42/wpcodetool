<?php

namespace Smbb\SignConnectProxima\CodeTool;

defined('ABSPATH') || exit;

/**
 * Le journal d'audit est en lecture seule.
 */
final class AuditLogHooks
{
    public function validate(array $data, array $context = array())
    {
        return array(
            'event_type' => __("Le journal d'audit est en lecture seule.", 'smbb-signaconnect-proxima'),
        );
    }

    public function beforeDelete(array $row, array $context = array())
    {
        return __("Le journal d'audit ne peut pas être modifié.", 'smbb-signaconnect-proxima');
    }
}
