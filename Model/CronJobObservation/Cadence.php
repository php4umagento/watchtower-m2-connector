<?php
/**
 * Copyright © 2026 Watchtower. All rights reserved.
 * Licensed under the Business Source License 1.1 (BUSL-1.1). See LICENSE.
 */

declare(strict_types=1);

namespace Watchtower\Connector\Model\CronJobObservation;

/**
 * What CadenceEstimator concluded about one job's rhythm: how often it runs,
 * how long a success may go unrenewed before that is a problem, and whether
 * either number is trustworthy yet.
 *
 * Three distinct states the admin picker and the evaluator both branch on:
 * not confident (still learning, too few samples), confident but irregular
 * (a period exists but the spread is too wide to alert on tightly), and
 * confident and regular (the normal case).
 */
class Cadence
{
    /**
     * @param int|null $periodSeconds median observed gap, null while learning
     * @param int|null $thresholdSeconds how long a success may go unrenewed, null while learning
     * @param bool $isConfident enough samples to have measured anything
     * @param bool $isRegular gap spread tight enough for the threshold to be dependable
     * @param int $sampleCount gaps measured so far
     * @param int $observedRunCount successes recorded so far
     */
    public function __construct(
        public readonly ?int $periodSeconds,
        public readonly ?int $thresholdSeconds,
        public readonly bool $isConfident,
        public readonly bool $isRegular,
        public readonly int $sampleCount,
        public readonly int $observedRunCount,
    ) {
    }
}
