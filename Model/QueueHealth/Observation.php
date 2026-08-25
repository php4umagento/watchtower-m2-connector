<?php
/**
 * Copyright © 2026 Watchtower. All rights reserved.
 * Licensed under the Business Source License 1.1 (BUSL-1.1). See LICENSE.
 */

declare(strict_types=1);

namespace Watchtower\Connector\Model\QueueHealth;

/**
 * One poll of the watched message-queue consumers.
 *
 * Carries two facts about undrained work rather than one, because the two
 * backends each answer a different half and neither answers both.
 */
class Observation
{
    /**
     * @param \DateTimeImmutable|null $undrainedSince when the oldest waiting
     *        work arrived, across consumers whose backend can say. Only MySQL
     *        can: a pending row carries the time it was written.
     * @param bool $undrainedWithoutOnset a queue holds work with no consumer
     *        attached, on a backend that cannot say for how long. AMQP reports
     *        a consumer count but no message age, so the caller carries that
     *        onset across polls.
     * @param string[] $affectedQueues LOCAL DIAGNOSTIC ONLY, never put on a
     *        MetricReport. Which queue is backed up implies which part of the
     *        business is busy (async.operations.all means ERP volume,
     *        catalog_product_generate_urls means catalogue churn). Collected
     *        only so the merchant's own admin and CLI can act on an alert whose
     *        payload deliberately omits it.
     */
    public function __construct(
        public readonly ?\DateTimeImmutable $undrainedSince,
        public readonly bool $undrainedWithoutOnset,
        public readonly array $affectedQueues = [],
    ) {
    }
}
