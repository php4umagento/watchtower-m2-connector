<?php
/**
 * Copyright © 2026 Watchtower. All rights reserved.
 * Licensed under the Business Source License 1.1 (BUSL-1.1). See LICENSE.
 */

declare(strict_types=1);

namespace Watchtower\Connector\Model\IntegrationHealth;

use Watchtower\Connector\Model\CronJobObservation\Cadence;

/**
 * One selectable cron job inside a discovered integration, carrying both what
 * its module says it should do and what the connector has measured it doing.
 *
 * Both are kept because they answer different questions. The declared
 * schedule is shown to help a merchant recognize the job; the measured
 * cadence is what the threshold is actually derived from.
 */
class DiscoveredJob
{
    /**
     * @param string $jobCode
     * @param string|null $declaredSchedule cron expression from crontab.xml, null when config-driven
     * @param Cadence $cadence what the connector has actually measured
     */
    public function __construct(
        public readonly string $jobCode,
        public readonly ?string $declaredSchedule,
        public readonly Cadence $cadence,
    ) {
    }
}
