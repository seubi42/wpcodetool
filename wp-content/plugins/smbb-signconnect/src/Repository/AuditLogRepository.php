<?php

namespace Smbb\SignConnect\Repository;

defined('ABSPATH') || exit;

final class AuditLogRepository
{
    public function record($document_id, $event_type, array $context = array(), $actor_id = null)
    {
        return (new DocumentAuditRepository())->record($document_id, $event_type, $context, 'system', $actor_id);
    }

    public function listForDocument($document_id, $limit = 100)
    {
        return (new DocumentAuditRepository())->listForDocument($document_id, $limit);
    }
}
