<?php
/**
 * Copyright © 2026 Watchtower. All rights reserved.
 * Licensed under the Business Source License 1.1 (BUSL-1.1). See LICENSE.
 */

declare(strict_types=1);

namespace Watchtower\Connector\Model\IndexerHealth;

/**
 * What one poll of indexer_state and mview_state found. Evaluator turns this
 * into a status; nothing here interprets anything.
 *
 * Unlike CronHealth\Observation this needs no carry-forward across ticks.
 * Magento purges cron_schedule within the hour, so a single cron observation
 * can be misleadingly empty; indexer_state and mview_state are fixed-size
 * status tables rewritten in place, and each row's `updated` column already
 * records when its current status began. One poll answers "how long has this
 * been wrong" on its own.
 */
class Observation
{
    /**
     * @param \DateTimeImmutable|null $unhealthySince when the longest-running
     *        unhealthy condition began, or null when nothing is unhealthy. The
     *        OLDEST onset across every affected row, so one indexer stuck for a
     *        day is not masked by another that only just went invalid.
     * @param bool $suspended a materialized view is suspended, which is
     *        duration-independent: a suspended view is not draining and will not
     *        start on its own, so it is wrong the moment it is observed.
     */
    public function __construct(
        public readonly ?\DateTimeImmutable $unhealthySince,
        public readonly bool $suspended,
    ) {
    }
}
