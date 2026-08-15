<?php
/**
 * Copyright © 2026 Watchtower. All rights reserved.
 * Licensed under the Business Source License 1.1 (BUSL-1.1). See LICENSE.
 */

declare(strict_types=1);

namespace Watchtower\Connector\Test\Unit\Console\Command;

use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Watchtower\Connector\Console\Command\CoverageCommand;
use Watchtower\Connector\Model\Seed\HistorySeeder;
use Watchtower\Connector\Model\Seed\SeedCoverageResult;
use Watchtower\Connector\Model\Seed\SeedCoverageStatus;
use Watchtower\Connector\Model\Seed\SeedLimitReason;
use Watchtower\Connector\Test\Unit\StoreStubTrait;

/**
 * Covers the two rendering scenarios: a fully-seeded store view (Seeded)
 * and one limited by quote retention (Limited/RetentionCliff) -- proving
 * the command renders operator-readable text rather than raw enum/DTO
 * output.
 */
class CoverageCommandTest extends TestCase
{
    use StoreStubTrait;

    public function testFullCoveragePrintsTheSeededDaysMessage(): void
    {
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStores')->willReturn([$this->activeStore('default')]);

        $historySeeder = $this->createMock(HistorySeeder::class);
        $historySeeder->expects($this->once())
            ->method('seed')
            ->with(1, $this->isInstanceOf(\DateTimeImmutable::class), 84)
            ->willReturn([
                HistorySeeder::CATEGORY_BASKET_QUOTE => new SeedCoverageResult(
                    category: HistorySeeder::CATEGORY_BASKET_QUOTE,
                    requestedDays: 84,
                    daysSeeded: 26,
                    status: SeedCoverageStatus::Seeded,
                ),
            ]);

        $tester = new CommandTester(new CoverageCommand($historySeeder, $storeManager));
        $tester->execute([]);

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertStringContainsString('cart history seeded: 26 days', $tester->getDisplay());
    }

    public function testRetentionLimitedCoveragePrintsTheLimitedMessageWithTheReason(): void
    {
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStores')->willReturn([$this->activeStore('default')]);

        $historySeeder = $this->createStub(HistorySeeder::class);
        $historySeeder->method('seed')->willReturn([
            HistorySeeder::CATEGORY_BASKET_QUOTE => new SeedCoverageResult(
                category: HistorySeeder::CATEGORY_BASKET_QUOTE,
                requestedDays: 84,
                daysSeeded: 5,
                status: SeedCoverageStatus::Limited,
                limitReason: SeedLimitReason::RetentionCliff,
                sourceRetentionDays: 7,
            ),
        ]);

        $tester = new CommandTester(new CoverageCommand($historySeeder, $storeManager));
        $tester->execute([]);

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertStringContainsString(
            'cart history unavailable (quote lifetime is 7 days); warming up',
            $tester->getDisplay()
        );
    }

    public function testNoLiveStoreViewsFails(): void
    {
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStores')->willReturn([$this->inactiveStore('disabled')]);

        $historySeeder = $this->createMock(HistorySeeder::class);
        $historySeeder->expects($this->never())->method('seed');

        $tester = new CommandTester(new CoverageCommand($historySeeder, $storeManager));
        $tester->execute([]);

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('No live store views found', $tester->getDisplay());
    }

    public function testRowCountCeilingAndInsufficientHistoryUsePlainMerchantWording(): void
    {
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStores')->willReturn([$this->activeStore('default')]);

        $historySeeder = $this->createStub(HistorySeeder::class);
        $historySeeder->method('seed')->willReturn([
            HistorySeeder::CATEGORY_CHECKOUT => new SeedCoverageResult(
                category: HistorySeeder::CATEGORY_CHECKOUT,
                requestedDays: 84,
                daysSeeded: 3,
                status: SeedCoverageStatus::Limited,
                limitReason: SeedLimitReason::RowCountCeiling,
            ),
            HistorySeeder::CATEGORY_CUSTOMER_ACCOUNT => new SeedCoverageResult(
                category: HistorySeeder::CATEGORY_CUSTOMER_ACCOUNT,
                requestedDays: 84,
                daysSeeded: 10,
                status: SeedCoverageStatus::Limited,
                limitReason: SeedLimitReason::InsufficientHistory,
            ),
        ]);

        $tester = new CommandTester(new CoverageCommand($historySeeder, $storeManager));
        $tester->execute([]);

        $display = $tester->getDisplay();
        $this->assertStringNotContainsString('RowCountCeiling', $display);
        $this->assertStringNotContainsString('InsufficientHistory', $display);
        $this->assertStringContainsString('checkout history seeded: 3 of 84 days', $display);
        $this->assertStringContainsString(
            'customer account history warming up: 10 of 84 days available so far',
            $display
        );
    }
}
