<?php
/**
 * Copyright © 2026 Watchtower. All rights reserved.
 * Licensed under the Business Source License 1.1 (BUSL-1.1). See LICENSE.
 */

declare(strict_types=1);

namespace Watchtower\Connector\Model\Threshold;

/**
 * The mild/severe thresholds a threshold-based signal should use this tick,
 * after LearnedThresholdCalculator has (or has not) refined the fixed
 * defaults against a store's own history.
 */
class LearnedThresholds
{
    /**
     * @param float $mild value at or above which the signal is MILD_DROP
     * @param float $severe value at or above which the signal is SEVERE_DROP
     */
    public function __construct(
        public readonly float $mild,
        public readonly float $severe,
    ) {
    }
}
