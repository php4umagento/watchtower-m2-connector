<?php
/**
 * Copyright © 2026 Watchtower. All rights reserved.
 * Licensed under the Business Source License 1.1 (BUSL-1.1). See LICENSE.
 */

declare(strict_types=1);

namespace Watchtower\Connector\Cron;

use Psr\Log\LoggerInterface;
use Watchtower\Connector\Model\Api\StoreViewSyncService;
use Watchtower\Connector\Model\Config;

/**
 * Scheduled counterpart to bin/magento watchtower:sync. Wraps the same
 * StoreViewSyncService the CLI command already exposes rather than
 * reimplementing the sync call.
 */
class SyncJob
{
    /**
     * @param Config $config
     * @param StoreViewSyncService $storeViewSyncService
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly Config $config,
        private readonly StoreViewSyncService $storeViewSyncService,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Syncs this install's live store views to the platform, logging outcomes that need operator visibility.
     *
     * @return void
     */
    public function execute(): void
    {
        if (!$this->config->isConfigured()) {
            // Not worth a log line on every tick; an unconfigured install
            // is an ordinary, expected state, same reasoning as ReportJob.
            return;
        }

        // Same reasoning as SyncCommand: sync creates a platform-side
        // StoreView and consumes metered billing quantity, so a disabled
        // connector must never run it, not even on a schedule.
        if (!$this->config->isEnabled()) {
            return;
        }

        $result = $this->storeViewSyncService->sync(
            (string) $this->config->baseUrl(),
            (string) $this->config->apiKey()
        );

        if (!$result->succeeded) {
            $this->logger->warning('Watchtower scheduled store-view sync failed.', [
                'error' => $result->errorMessage,
            ]);

            return;
        }

        if ($result->rejected !== []) {
            $this->logger->warning('Watchtower scheduled store-view sync had rejected store views.', [
                'rejected' => $result->rejected,
            ]);
        }
    }
}
