<?php
/**
 * Copyright © 2026 Watchtower. All rights reserved.
 * Licensed under the Business Source License 1.1 (BUSL-1.1). See LICENSE.
 */

declare(strict_types=1);

namespace Watchtower\Connector\Model\Seed;

/**
 * Renders a SeedCoverageResult in merchant-facing wording -- the answer to
 * "why is this still warming up?", shared by CoverageCommand (which
 * produces a fresh result every run) and the diagnostics page/CLI (which
 * read back the last persisted one via SeedCoverageRepository).
 */
class SeedCoverageLabel
{
    /**
     * Renders one category's coverage result in merchant-facing wording.
     *
     * @param SeedCoverageResult $result
     * @return string
     */
    public function describe(SeedCoverageResult $result): string
    {
        $label = $this->categoryLabel($result->category);

        if ($result->status === SeedCoverageStatus::Seeded) {
            return sprintf('%s history seeded: %d days', $label, $result->daysSeeded);
        }

        return match ($result->limitReason) {
            SeedLimitReason::RetentionCliff => sprintf(
                '%s history unavailable (quote lifetime is %d days); warming up',
                $label,
                $result->sourceRetentionDays ?? 0
            ),
            SeedLimitReason::RowCountCeiling => sprintf(
                '%s history seeded: %d of %d days (stopped early -- this store has a very large amount of history)',
                $label,
                $result->daysSeeded,
                $result->requestedDays
            ),
            SeedLimitReason::InsufficientHistory => sprintf(
                '%s history warming up: %d of %d days available so far',
                $label,
                $result->daysSeeded,
                $result->requestedDays
            ),
            SeedLimitReason::UnseedableSource => sprintf(
                '%s activity is counted as it happens, so there is no history to seed from; warming up',
                $label
            ),
            // HistorySeeder never returns Limited without a reason; this
            // branch exists only so match() is exhaustive for phpstan.
            null => sprintf('%s history limited: %d of %d days', $label, $result->daysSeeded, $result->requestedDays),
        };
    }

    /**
     * Plain merchant-readable label for a HistorySeeder::CATEGORY_* constant.
     *
     * @param string $category
     * @return string
     */
    private function categoryLabel(string $category): string
    {
        return match ($category) {
            HistorySeeder::CATEGORY_BASKET_QUOTE => 'cart',
            HistorySeeder::CATEGORY_CHECKOUT => 'checkout',
            HistorySeeder::CATEGORY_CUSTOMER_ACCOUNT => 'customer account',
            default => $category,
        };
    }
}
