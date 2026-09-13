<?php
/**
 * Copyright © 2026 Watchtower. All rights reserved.
 * Licensed under the Business Source License 1.1 (BUSL-1.1). See LICENSE.
 */

declare(strict_types=1);

namespace Watchtower\Connector\Model\RateSignal;

use Watchtower\Connector\Model\Seed\HistorySeeder;

/**
 * Maps a rate-based category to an earlier-funnel category worth consulting
 * when the first reports INSUFFICIENT_DATA from low volume. Add-to-cart
 * writes to `quote` regardless of full-page-cache state (it's an uncacheable
 * POST), which is what makes basket_quote a reliable "is there real traffic"
 * proxy where a raw page-view count would not be -- see
 * docs/connector-baseline-seasonality.md D9 in the platform repo.
 */
class FunnelCorroboration
{
    private const MAP = [
        HistorySeeder::CATEGORY_CHECKOUT => HistorySeeder::CATEGORY_BASKET_QUOTE,
    ];

    /**
     * The earlier-funnel category to consult for $category, or null if none is mapped.
     *
     * @param string $category
     * @return string|null
     */
    public function corroboratingCategoryFor(string $category): ?string
    {
        return self::MAP[$category] ?? null;
    }
}
