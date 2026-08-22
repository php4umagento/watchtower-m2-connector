<?php
/**
 * Copyright © 2026 Watchtower. All rights reserved.
 * Licensed under the Business Source License 1.1 (BUSL-1.1). See LICENSE.
 */

declare(strict_types=1);

namespace Watchtower\Connector\Test\Unit\Model\Seed;

use PHPUnit\Framework\TestCase;
use Watchtower\Connector\Model\Seed\HistorySeeder;
use Watchtower\Connector\Model\Seed\SeedCoverageLabel;
use Watchtower\Connector\Model\Seed\SeedCoverageResult;
use Watchtower\Connector\Model\Seed\SeedCoverageStatus;
use Watchtower\Connector\Model\Seed\SeedLimitReason;

/**
 * Extracted from CoverageCommand so the diagnostics page/CLI can render the
 * same wording without re-seeding -- CoverageCommandTest still covers this
 * indirectly through the command's own output, this file pins the
 * formatter's own scenarios directly.
 */
class SeedCoverageLabelTest extends TestCase
{
    public function testSeededRendersDaysAndCategoryLabel(): void
    {
        $label = new SeedCoverageLabel();

        $result = new SeedCoverageResult(
            category: HistorySeeder::CATEGORY_BASKET_QUOTE,
            requestedDays: 84,
            daysSeeded: 26,
            status: SeedCoverageStatus::Seeded,
        );

        self::assertSame('cart history seeded: 26 days', $label->describe($result));
    }

    public function testRetentionCliffRendersUnavailableWarmingUp(): void
    {
        $label = new SeedCoverageLabel();

        $result = new SeedCoverageResult(
            category: HistorySeeder::CATEGORY_BASKET_QUOTE,
            requestedDays: 84,
            daysSeeded: 5,
            status: SeedCoverageStatus::Limited,
            limitReason: SeedLimitReason::RetentionCliff,
            sourceRetentionDays: 7,
        );

        self::assertSame(
            'cart history unavailable (quote lifetime is 7 days); warming up',
            $label->describe($result)
        );
    }

    public function testRowCountCeilingRendersSeededWithStoppedEarlyCaveat(): void
    {
        $label = new SeedCoverageLabel();

        $result = new SeedCoverageResult(
            category: HistorySeeder::CATEGORY_CHECKOUT,
            requestedDays: 84,
            daysSeeded: 3,
            status: SeedCoverageStatus::Limited,
            limitReason: SeedLimitReason::RowCountCeiling,
        );

        self::assertSame(
            'checkout history seeded: 3 of 84 days (stopped early -- this store has a very large amount of history)',
            $label->describe($result)
        );
    }

    public function testInsufficientHistoryRendersWarmingUpWithAvailableDays(): void
    {
        $label = new SeedCoverageLabel();

        $result = new SeedCoverageResult(
            category: HistorySeeder::CATEGORY_CUSTOMER_ACCOUNT,
            requestedDays: 84,
            daysSeeded: 10,
            status: SeedCoverageStatus::Limited,
            limitReason: SeedLimitReason::InsufficientHistory,
        );

        self::assertSame(
            'customer account history warming up: 10 of 84 days available so far',
            $label->describe($result)
        );
    }

    public function testUnrecognizedCategoryFallsBackToTheRawCategoryString(): void
    {
        $label = new SeedCoverageLabel();

        $result = new SeedCoverageResult(
            category: 'some_future_category',
            requestedDays: 84,
            daysSeeded: 84,
            status: SeedCoverageStatus::Seeded,
        );

        self::assertSame('some_future_category history seeded: 84 days', $label->describe($result));
    }
}
