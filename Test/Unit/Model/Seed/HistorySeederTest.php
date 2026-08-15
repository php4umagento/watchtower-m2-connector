<?php

declare(strict_types=1);

namespace Watchtower\Connector\Test\Unit\Model\Seed;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use PHPUnit\Framework\TestCase;
use Watchtower\Connector\Model\Rollup\RollupRepository;
use Watchtower\Connector\Model\Seed\HistorySeeder;
use Watchtower\Connector\Model\Seed\SeedCoverageStatus;
use Watchtower\Connector\Model\Seed\SeedLimitReason;
use Watchtower\Connector\Model\Signal\BasketQuoteReader;
use Watchtower\Connector\Model\Signal\CheckoutReader;
use Watchtower\Connector\Model\Signal\CustomerAccountRegistrationReader;

/**
 * The seed-bounding formula:
 * seed_window = min(baseline_window, delete_quote_after - safety_margin)
 * for basket_quote only; checkout and customer_account's registrations
 * sub-counter are bounded only by the baseline window and the row-count
 * ceiling. These tests lock the exact coverage result each case reports,
 * since the CLI command formats that result verbatim into its output
 * ("cart history seeded: 26 days" / "cart history unavailable ... warming
 * up") and must be able to trust it.
 */
class HistorySeederTest extends TestCase
{
    private const NOW_STRING = '2026-08-13T15:00:00+00:00';
    private const STORE_VIEW_ID = 7;

    public function testBasketQuoteSeedsTheFullBaselineWindowWhenRetentionIsNotBinding(): void
    {
        $seeder = $this->buildSeeder(
            deleteQuoteAfterDays: 30,
            basketQuoteCount: 4,
        );

        $results = $seeder->seed(self::STORE_VIEW_ID, $this->now(), 28);
        $result = $results[HistorySeeder::CATEGORY_BASKET_QUOTE];

        self::assertSame(28, $result->requestedDays);
        self::assertSame(28, $result->daysSeeded);
        self::assertSame(SeedCoverageStatus::Seeded, $result->status);
        self::assertNull($result->limitReason);
    }

    public function testBasketQuoteSeedsLessThanBaselineWhenQuoteRetentionIsTheBindingConstraint(): void
    {
        $seeder = $this->buildSeeder(
            deleteQuoteAfterDays: 14,
            basketQuoteCount: 4,
        );

        $results = $seeder->seed(self::STORE_VIEW_ID, $this->now(), 28);
        $result = $results[HistorySeeder::CATEGORY_BASKET_QUOTE];

        // seed_window = min(28, 14 - 2) = 12, strictly less than the 28-day baseline.
        self::assertSame(28, $result->requestedDays);
        self::assertSame(12, $result->daysSeeded);
        self::assertLessThan(28, $result->daysSeeded);
        self::assertSame(SeedCoverageStatus::Limited, $result->status);
        self::assertSame(SeedLimitReason::RetentionCliff, $result->limitReason);
        self::assertSame(14, $result->sourceRetentionDays);
    }

    public function testBasketQuoteDeclinesEntirelyWhenRetentionIsBelowTheSafetyMargin(): void
    {
        $seeder = $this->buildSeeder(
            deleteQuoteAfterDays: 1,
            basketQuoteCount: 4,
        );

        $results = $seeder->seed(self::STORE_VIEW_ID, $this->now(), 28);
        $result = $results[HistorySeeder::CATEGORY_BASKET_QUOTE];

        self::assertSame(0, $result->daysSeeded);
        self::assertSame(SeedCoverageStatus::Limited, $result->status);
        self::assertSame(SeedLimitReason::RetentionCliff, $result->limitReason);
        self::assertSame(1, $result->sourceRetentionDays);
    }

    public function testCheckoutAndCustomerAccountWithAbundantHistoryAreNotTruncatedByAnyQuoteConstraint(): void
    {
        // delete_quote_after is intentionally tiny here -- it must have zero
        // effect on checkout/customer_account, only on basket_quote.
        $seeder = $this->buildSeeder(
            deleteQuoteAfterDays: 1,
            basketQuoteCount: 0,
            checkoutCount: 100,
            customerAccountCount: 100,
        );

        $results = $seeder->seed(self::STORE_VIEW_ID, $this->now(), 28);

        foreach ([HistorySeeder::CATEGORY_CHECKOUT, HistorySeeder::CATEGORY_CUSTOMER_ACCOUNT] as $category) {
            self::assertSame(28, $results[$category]->requestedDays);
            self::assertSame(28, $results[$category]->daysSeeded);
            self::assertSame(SeedCoverageStatus::Seeded, $results[$category]->status);
            self::assertNull($results[$category]->limitReason);
        }
    }

    public function testYoungStoreReportsHonestShorterCoverageInsteadOfClaimingTheFullBaselineWindow(): void
    {
        $cutoff = $this->now()->modify('-10 days');

        $seeder = $this->buildSeeder(
            deleteQuoteAfterDays: 30,
            basketQuoteCount: 0,
            checkoutCount: static fn (\DateTimeImmutable $windowStart): int => $windowStart >= $cutoff ? 5 : 0,
        );

        $results = $seeder->seed(self::STORE_VIEW_ID, $this->now(), 28);
        $result = $results[HistorySeeder::CATEGORY_CHECKOUT];

        self::assertSame(28, $result->requestedDays);
        self::assertSame(10, $result->daysSeeded);
        self::assertLessThan(28, $result->daysSeeded);
        self::assertSame(SeedCoverageStatus::Limited, $result->status);
        self::assertSame(SeedLimitReason::InsufficientHistory, $result->limitReason);
    }

    public function testRowCountCeilingStopsTheWalkAndReportsLimitedCoverage(): void
    {
        // 84 hours * 3000 = 252,000, crossing the 250,000 ceiling on the
        // 84th hour; the walk must stop at 83 completed hours (3 days).
        $seeder = $this->buildSeeder(
            deleteQuoteAfterDays: 30,
            basketQuoteCount: 0,
            checkoutCount: 3000,
        );

        $results = $seeder->seed(self::STORE_VIEW_ID, $this->now(), 28);
        $result = $results[HistorySeeder::CATEGORY_CHECKOUT];

        self::assertSame(28, $result->requestedDays);
        self::assertSame(3, $result->daysSeeded);
        self::assertSame(SeedCoverageStatus::Limited, $result->status);
        self::assertSame(SeedLimitReason::RowCountCeiling, $result->limitReason);
    }

    public function testRecordsEachHourlyCountAgainstTheRollupRepositoryForTheCorrectStoreViewAndCategory(): void
    {
        $checkoutCalls = [];
        $rollupRepository = $this->createMock(RollupRepository::class);
        $rollupRepository->expects(self::atLeastOnce())
            ->method('recordHourlyCount')
            ->willReturnCallback(
                function (
                    int $storeViewId,
                    string $category,
                    \DateTimeImmutable $hourBucket,
                    int $count
                ) use (&$checkoutCalls): void {
                    if ($category === HistorySeeder::CATEGORY_CHECKOUT) {
                        $checkoutCalls[] = [$storeViewId, $count];
                    }
                }
            );

        $seeder = $this->buildSeeder(
            deleteQuoteAfterDays: 30,
            basketQuoteCount: 0,
            checkoutCount: 7,
            customerAccountCount: 0,
            rollupRepository: $rollupRepository,
        );

        $seeder->seed(self::STORE_VIEW_ID, $this->now(), 1);

        self::assertNotEmpty($checkoutCalls);
        foreach ($checkoutCalls as $call) {
            self::assertSame([self::STORE_VIEW_ID, 7], $call);
        }
    }

    /**
     * @param int $deleteQuoteAfterDays
     * @param int $basketQuoteCount
     * @param int|callable(\DateTimeImmutable): int $checkoutCount
     * @param int $customerAccountCount
     * @param RollupRepository|null $rollupRepository
     * @return HistorySeeder
     */
    private function buildSeeder(
        int $deleteQuoteAfterDays,
        int $basketQuoteCount = 0,
        int|callable $checkoutCount = 0,
        int $customerAccountCount = 0,
        ?RollupRepository $rollupRepository = null,
    ): HistorySeeder {
        $basketQuoteReader = $this->createStub(BasketQuoteReader::class);
        $basketQuoteReader->method('countForWindow')->willReturn($basketQuoteCount);

        $checkoutReader = $this->createStub(CheckoutReader::class);
        if (is_callable($checkoutCount)) {
            $checkoutReader->method('countForWindow')->willReturnCallback(
                static fn (int $storeViewId, \DateTimeImmutable $windowStart, \DateTimeImmutable $windowEnd): int
                    => $checkoutCount($windowStart)
            );
        } else {
            $checkoutReader->method('countForWindow')->willReturn($checkoutCount);
        }

        $customerAccountReader = $this->createStub(CustomerAccountRegistrationReader::class);
        $customerAccountReader->method('countForWindow')->willReturn($customerAccountCount);

        $scopeConfig = $this->createStub(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnCallback(
            function (string $path, string $scopeType, $scopeCode) use ($deleteQuoteAfterDays) {
                self::assertSame('checkout/cart/delete_quote_after', $path);
                self::assertSame(ScopeInterface::SCOPE_STORE, $scopeType);
                self::assertSame(self::STORE_VIEW_ID, $scopeCode);

                return $deleteQuoteAfterDays;
            }
        );

        return new HistorySeeder(
            $basketQuoteReader,
            $checkoutReader,
            $customerAccountReader,
            $rollupRepository ?? $this->createStub(RollupRepository::class),
            $scopeConfig,
        );
    }

    private function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable(self::NOW_STRING);
    }
}
