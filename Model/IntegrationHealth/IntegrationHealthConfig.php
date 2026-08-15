<?php
/**
 * Copyright © 2026 Watchtower. All rights reserved.
 * Licensed under the Business Source License 1.1 (BUSL-1.1). See LICENSE.
 */

declare(strict_types=1);

namespace Watchtower\Connector\Model\IntegrationHealth;

/**
 * One store view's configured integration_health source. A store view with
 * no configured source has no row at all -- integration_health is optional
 * and is not evaluated until a source has been chosen
 * (IntegrationHealthConfigRepository::get() returns null, and
 * ReportingService skips the signal entirely for that store view).
 *
 * There is exactly ONE source per store view, whose status data yields both
 * success and failure inference together; independently configurable
 * success and failure sources are not supported.
 */
class IntegrationHealthConfig
{
    public const SOURCE_TYPE_CRON_JOB = 'cron_job';
    public const SOURCE_TYPE_QUEUE_CONSUMER = 'queue_consumer';
    public const SOURCE_TYPE_CONVENTION_EVENT = 'convention_event';

    /**
     * @param int $storeViewId
     * @param string $sourceType one of the SOURCE_TYPE_* constants
     * @param string $sourceIdentifier a job_code, topic_name, or integration label, depending on $sourceType
     * @param int $expectedMaxIntervalMinutes
     */
    public function __construct(
        public readonly int $storeViewId,
        public readonly string $sourceType,
        public readonly string $sourceIdentifier,
        public readonly int $expectedMaxIntervalMinutes,
    ) {
    }
}
