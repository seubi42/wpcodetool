<?php

namespace Smbb\SignConnect\Service;

use Smbb\SignConnect\Support\SignConnectSettings;

defined('ABSPATH') || exit;

final class YousignSignatureFlowCronService
{
    private const LOCK_KEY = 'smbb_signconnect_yousign_signature_cron_lock';

    private $signature_flow;

    public function __construct(YousignSignatureFlowService $signature_flow = null)
    {
        $this->signature_flow = $signature_flow ?: new YousignSignatureFlowService();
    }

    public function run()
    {
        if (SignConnectSettings::yousignApiKey() === '') {
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
