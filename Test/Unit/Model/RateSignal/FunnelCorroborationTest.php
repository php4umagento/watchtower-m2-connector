<?php
/**
 * Copyright © 2026 Watchtower. All rights reserved.
 * Licensed under the Business Source License 1.1 (BUSL-1.1). See LICENSE.
 */

declare(strict_types=1);

namespace Watchtower\Connector\Test\Unit\Model\RateSignal;

use PHPUnit\Framework\TestCase;
use Watchtower\Connector\Model\RateSignal\FunnelCorroboration;
use Watchtower\Connector\Model\Seed\HistorySeeder;

class FunnelCorroborationTest extends TestCase
{
    public function testCheckoutMapsToBasketQuote(): void
    {
        $mapping = new FunnelCorroboration();

        self::assertSame(
            HistorySeeder::CATEGORY_BASKET_QUOTE,
            $mapping->corroboratingCategoryFor(HistorySeeder::CATEGORY_CHECKOUT)
        );
    }

    /**
     * basket_quote is the earliest funnel-ordered rate-based category, and
     * customer_account is engagement rather than funnel-ordered -- neither
     * has an earlier category to consult.
     */
    public function testCategoriesWithNoEarlierFunnelStepMapToNull(): void
    {
        $mapping = new FunnelCorroboration();

        foreach ([HistorySeeder::CATEGORY_BASKET_QUOTE, HistorySeeder::CATEGORY_CUSTOMER_ACCOUNT] as $category) {
            self::assertNull($mapping->corroboratingCategoryFor($category), $category);
        }
    }

    public function testUnrecognizedCategoryMapsToNull(): void
    {
        $mapping = new FunnelCorroboration();

        self::assertNull($mapping->corroboratingCategoryFor('cron_health'));
    }
}
