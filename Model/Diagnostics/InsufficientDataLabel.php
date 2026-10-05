<?php
/**
 * Copyright © 2026 Watchtower. All rights reserved.
 * Licensed under the Business Source License 1.1 (BUSL-1.1). See LICENSE.
 */

declare(strict_types=1);

namespace Watchtower\Connector\Model\Diagnostics;

use Watchtower\Connector\Model\Api\SignalStatus;
use Watchtower\Connector\Model\Seed\HistorySeeder;
use Watchtower\Connector\Model\Seed\SeedCoverageStatus;

/**
 * Merchant-facing wording for a signal with no status, split by why. Only a
 * rate-based signal still building its baseline is "warming up"; one with a
 * built baseline is just too quiet this hour, and a state-based signal is
 * learning a schedule.
 */
class InsufficientDataLabel
{
    private const RATE_CATEGORIES = [
        HistorySeeder::CATEGORY_BASKET_QUOTE,
        HistorySeeder::CATEGORY_CHECKOUT,
        HistorySeeder::CATEGORY_CUSTOMER_ACCOUNT,
    ];

    private const DEFAULT_BASELINE_WINDOW_DAYS = 28;

    /**
     * The badge text for a signal that has no status.
     *
     * @param SignalSnapshot $signal
     * @return string
     */
    public function label(SignalSnapshot $signal): string
    {
        if (!in_array($signal->category, self::RATE_CATEGORIES, true)) {
            return 'Learning the schedule';
        }

        return $this->hasBuiltBaseline($signal) ? 'Too quiet to judge' : 'Warming up';
    }

    /**
     * Whether the signal is genuinely still learning, which drives the store view's "Warming up" note.
     *
     * @param SignalSnapshot $signal
     * @return bool
     */
    public function isWarmingUp(SignalSnapshot $signal): bool
    {
        return $signal->status === SignalStatus::InsufficientData
            && $this->label($signal) !== 'Too quiet to judge';
    }

    /**
     * Whether history exists, so a withheld status means low activity rather than a missing baseline.
     *
     * A full seed from Magento's own tables means the baseline existed from day one; otherwise a baseline
     * window of hourly evaluations must have passed.
     *
     * @param SignalSnapshot $signal
     * @return bool
     */
    private function hasBuiltBaseline(SignalSnapshot $signal): bool
    {
        $coverage = $signal->seedCoverage;

        if ($coverage !== null && $coverage->status === SeedCoverageStatus::Seeded) {
            return true;
        }

        $windowDays = $coverage->requestedDays ?? self::DEFAULT_BASELINE_WINDOW_DAYS;

        return $signal->sequenceNumber >= $windowDays * 24;
    }
}
