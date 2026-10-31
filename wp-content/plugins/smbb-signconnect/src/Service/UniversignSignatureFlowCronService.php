<?php

namespace Smbb\SignConnect\Service;

use Smbb\SignConnect\Support\SignConnectSettings;

defined('ABSPATH') || exit;

final class UniversignSignatureFlowCronService
{
    private const LOCK_KEY = 'smbb_signconnect_universign_signature_cron_lock';

    private $signature_flow;

    public function __construct(UniversignSignatureFlowService $signature_flow = null)
    {
        $this->signature_flow = $signature_flow ?: new UniversignSignatureFlowService();
    }

    public function run()
    {
        if (SignConnectSettings::universignApiKey() === '') {
            return array(
                'checked' => 0,
                'completed' => 0,
                'errors' => 0,
                'skipped' => 'not_configured',
            );
        }

        if (get_transient(self::LOCK_KEY)) {
            return array(
                'checked' => 0,
                'completed' => 0,
                'errors' => 0,
                'skipped' => 'locked',
            );
        }

        set_transient(self::LOCK_KEY, 1, 4 * MINUTE_IN_SECONDS);

        try {
            return $this->signature_flow->checkPending(10);
        } finally {
            delete_transient(self::LOCK_KEY);
        }
    }
}
