<?php
/**
 * Copyright © 2026 Watchtower. All rights reserved.
 * Licensed under the Business Source License 1.1 (BUSL-1.1). See LICENSE.
 */

declare(strict_types=1);

namespace Watchtower\Connector\Model\Seed;

/**
 * Outcome of seeding one (store view, category) pair, returned by
 * HistorySeeder::seed(). Carries enough for a diagnostics surface to
 * describe the outcome -- "cart history seeded: 26 days" is Seeded with
 * daysSeeded=26; "cart history unavailable (quote lifetime is 7 days)" is
 * Limited with limitReason=RetentionCliff and sourceRetentionDays=7 --
 * without this class itself owning any presentation string.
 */
class SeedCoverageResult
{
    /**
     * @param string $category one of HistorySeeder::CATEGORY_*
     * @param int $requestedDays the baseline window seeding was asked to cover
     * @param int $daysSeeded how many days of history were actually seeded
     * @param SeedCoverageStatus $status
     * @param SeedLimitReason|null $limitReason set only when $status is Limited
     * @param int|null $sourceRetentionDays the source table's own retention
     *        (currently only meaningful for basket_quote's
     *        checkout/cart/delete_quote_after), when that retention bound
     *        $daysSeeded below $requestedDays
     */
    public function __construct(
        public readonly string $category,
        public readonly int $requestedDays,
        public readonly int $daysSeeded,
        public readonly SeedCoverageStatus $status,
        public readonly ?SeedLimitReason $limitReason = null,
        public readonly ?int $sourceRetentionDays = null,
    ) {
    }
}
