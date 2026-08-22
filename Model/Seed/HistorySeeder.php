<?php
/**
 * Copyright © 2026 Watchtower. All rights reserved.
 * Licensed under the Business Source License 1.1 (BUSL-1.1). See LICENSE.
 */

declare(strict_types=1);

namespace Watchtower\Connector\Model\Seed;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use Watchtower\Connector\Model\RateSignal\DispersionEvaluator;
use Watchtower\Connector\Model\Rollup\RollupRepository;
use Watchtower\Connector\Model\Signal\BasketQuoteReader;
use Watchtower\Connector\Model\Signal\CheckoutReader;
use Watchtower\Connector\Model\Signal\CustomerAccountRegistrationReader;
use Watchtower\Connector\Model\Signal\RateSignalReaderInterface;

/**
 * One-time historical backfill of RollupRepository from the table-sourced
 * readers, so a category exits INSUFFICIENT_DATA immediately instead of cold
 * starting. Walks backward hour by hour from the last complete hour, recording
 * each reader's countForWindow(); it writes rollup rows only, never a report.
 *
 * `checkout` and `customer_account` (registrations only -- logins/logouts stay
 * event-sourced) are bounded by the baseline window and ROW_COUNT_CEILING.
 * `basket_quote` is additionally bounded by the store's own quote retention:
 * seed_window = min(baseline_window, delete_quote_after - safety_margin).
 */
class HistorySeeder
{
    public const CATEGORY_BASKET_QUOTE = 'basket_quote';
    public const CATEGORY_CHECKOUT = 'checkout';
    public const CATEGORY_CUSTOMER_ACCOUNT = 'customer_account';

    private const XML_PATH_DELETE_QUOTE_AFTER = 'checkout/cart/delete_quote_after';

    /** Keeps seeding clear of the quote-eviction cliff, since "now" and Magento's cleanup cron aren't aligned. */
    private const SAFETY_MARGIN_DAYS = 2;

    /** Cumulative per (store view, category) cap for one seed run, so a very large store can't scan unbounded rows. */
    private const ROW_COUNT_CEILING = 250_000;

    /**
     * @param BasketQuoteReader $basketQuoteReader
     * @param CheckoutReader $checkoutReader
     * @param CustomerAccountRegistrationReader $customerAccountRegistrationReader
     * @param RollupRepository $rollupRepository
     * @param ScopeConfigInterface $scopeConfig
     */
    public function __construct(
        private readonly BasketQuoteReader $basketQuoteReader,
        private readonly CheckoutReader $checkoutReader,
        private readonly CustomerAccountRegistrationReader $customerAccountRegistrationReader,
        private readonly RollupRepository $rollupRepository,
        private readonly ScopeConfigInterface $scopeConfig,
    ) {
    }

    /**
     * The longest of DispersionEvaluator's own lookback windows, so a new
     * install accumulates enough rollup history to fill whichever evaluation
     * actually queries it -- shared by every seed() caller (CoverageCommand's
     * manual trigger, ReportingService's automatic on-enable trigger) so they
     * can never drift apart on what "a full baseline" means. Previously a
     * hand-maintained mirror of BASELINE_WEEKS alone, which meant
     * LOW_VOLUME_LOOKBACK_WEEKS's wider query window had no real history to
     * find on a freshly-seeded install regardless of how far back it was
     * willing to look.
     *
     * An instance method, not static -- a static method can't be intercepted
     * by a Magento plugin, same reasoning this codebase already applies to
     * avoiding `final` classes (see this repo's own CLAUDE.md).
     *
     * @return int
     */
    public function defaultBaselineWindowDays(): int
    {
        return 7 * max(DispersionEvaluator::BASELINE_WEEKS, DispersionEvaluator::LOW_VOLUME_LOOKBACK_WEEKS);
    }

    /**
     * Seeds every table-sourced category for one store view.
     *
     * @param int $storeViewId
     * @param \DateTimeImmutable $now
     * @param int $baselineWindowDays the evaluator's baseline window, e.g. 28
     * @return array<string, SeedCoverageResult> keyed by category (self::CATEGORY_*)
     */
    public function seed(int $storeViewId, \DateTimeImmutable $now, int $baselineWindowDays): array
    {
        return [
            self::CATEGORY_BASKET_QUOTE => $this->seedBasketQuote($storeViewId, $now, $baselineWindowDays),
            self::CATEGORY_CHECKOUT => $this->seedUnboundedCategory(
                self::CATEGORY_CHECKOUT,
                $this->checkoutReader,
                $storeViewId,
                $now,
                $baselineWindowDays
            ),
            self::CATEGORY_CUSTOMER_ACCOUNT => $this->seedUnboundedCategory(
                self::CATEGORY_CUSTOMER_ACCOUNT,
                $this->customerAccountRegistrationReader,
                $storeViewId,
                $now,
                $baselineWindowDays
            ),
        ];
    }

    /**
     * Seeds basket_quote, bounded by both the baseline window and quote retention.
     *
     * @param int $storeViewId
     * @param \DateTimeImmutable $now
     * @param int $baselineWindowDays
     * @return SeedCoverageResult
     */
    private function seedBasketQuote(
        int $storeViewId,
        \DateTimeImmutable $now,
        int $baselineWindowDays
    ): SeedCoverageResult {
        $deleteQuoteAfterDays = (int) $this->scopeConfig->getValue(
            self::XML_PATH_DELETE_QUOTE_AFTER,
            ScopeInterface::SCOPE_STORE,
            $storeViewId
        );

        $seedWindowDays = min($baselineWindowDays, $deleteQuoteAfterDays - self::SAFETY_MARGIN_DAYS);

        if ($seedWindowDays <= 0) {
            return new SeedCoverageResult(
                category: self::CATEGORY_BASKET_QUOTE,
                requestedDays: $baselineWindowDays,
                daysSeeded: 0,
                status: SeedCoverageStatus::Limited,
                limitReason: SeedLimitReason::RetentionCliff,
                sourceRetentionDays: $deleteQuoteAfterDays,
            );
        }

        $walk = $this->walkAndRecord(
            $this->basketQuoteReader,
            $storeViewId,
            self::CATEGORY_BASKET_QUOTE,
            $now,
            $seedWindowDays
        );

        // Retention only bound the result if it was the smaller of the two inputs;
        // otherwise the baseline window was, and this is an ordinary full seed.
        $retentionBound = $seedWindowDays < $baselineWindowDays;

        if ($walk['limitedByCeiling']) {
            return new SeedCoverageResult(
                category: self::CATEGORY_BASKET_QUOTE,
                requestedDays: $baselineWindowDays,
                daysSeeded: $walk['daysSeeded'],
                status: SeedCoverageStatus::Limited,
                limitReason: SeedLimitReason::RowCountCeiling,
            );
        }

        if ($retentionBound || $walk['daysSeeded'] < $seedWindowDays) {
            return new SeedCoverageResult(
                category: self::CATEGORY_BASKET_QUOTE,
                requestedDays: $baselineWindowDays,
                daysSeeded: $walk['daysSeeded'],
                status: SeedCoverageStatus::Limited,
                limitReason: $retentionBound ? SeedLimitReason::RetentionCliff : SeedLimitReason::InsufficientHistory,
                sourceRetentionDays: $retentionBound ? $deleteQuoteAfterDays : null,
            );
        }

        return new SeedCoverageResult(
            category: self::CATEGORY_BASKET_QUOTE,
            requestedDays: $baselineWindowDays,
            daysSeeded: $walk['daysSeeded'],
            status: SeedCoverageStatus::Seeded,
        );
    }

    /**
     * Seeds a category with no table-retention constraint of its own.
     *
     * Bounded only by the baseline window and the row-count ceiling.
     *
     * @param string $category
     * @param RateSignalReaderInterface $reader
     * @param int $storeViewId
     * @param \DateTimeImmutable $now
     * @param int $baselineWindowDays
     * @return SeedCoverageResult
     */
    private function seedUnboundedCategory(
        string $category,
        RateSignalReaderInterface $reader,
        int $storeViewId,
        \DateTimeImmutable $now,
        int $baselineWindowDays
    ): SeedCoverageResult {
        $walk = $this->walkAndRecord($reader, $storeViewId, $category, $now, $baselineWindowDays);

        if ($walk['limitedByCeiling']) {
            return new SeedCoverageResult(
                category: $category,
                requestedDays: $baselineWindowDays,
                daysSeeded: $walk['daysSeeded'],
                status: SeedCoverageStatus::Limited,
                limitReason: SeedLimitReason::RowCountCeiling,
            );
        }

        if ($walk['daysSeeded'] < $baselineWindowDays) {
            return new SeedCoverageResult(
                category: $category,
                requestedDays: $baselineWindowDays,
                daysSeeded: $walk['daysSeeded'],
                status: SeedCoverageStatus::Limited,
                limitReason: SeedLimitReason::InsufficientHistory,
            );
        }

        return new SeedCoverageResult(
            category: $category,
            requestedDays: $baselineWindowDays,
            daysSeeded: $walk['daysSeeded'],
            status: SeedCoverageStatus::Seeded,
        );
    }

    /**
     * Walks backward hour by hour from the last complete hour for up to
     * $requestedDays, recording each hour's count and stopping before any hour
     * that would push the running total past ROW_COUNT_CEILING.
     *
     * "Days seeded" comes from the oldest hour with a nonzero count, not from
     * how many windows were queried, so a young store reports its genuine
     * history depth. A ceiling-limited walk instead reports how far it got,
     * that case being about scan volume rather than data availability.
     *
     * @param RateSignalReaderInterface $reader
     * @param int $storeViewId
     * @param string $category
     * @param \DateTimeImmutable $now
     * @param int $requestedDays
     * @return array{daysSeeded: int, limitedByCeiling: bool}
     */
    private function walkAndRecord(
        RateSignalReaderInterface $reader,
        int $storeViewId,
        string $category,
        \DateTimeImmutable $now,
        int $requestedDays
    ): array {
        $hourTop = $this->topOfHour($now);
        $totalHours = $requestedDays * 24;

        $cumulativeCount = 0;
        $hoursProcessed = 0;
        $oldestNonZeroHourOffset = 0;
        $limitedByCeiling = false;

        for ($hourOffset = 1; $hourOffset <= $totalHours; $hourOffset++) {
            $windowEnd = $hourTop->modify(sprintf('-%d hours', $hourOffset - 1));
            $windowStart = $windowEnd->modify('-1 hour');

            $count = $reader->countForWindow($storeViewId, $windowStart, $windowEnd);

            if ($cumulativeCount + $count > self::ROW_COUNT_CEILING) {
                $limitedByCeiling = true;
                break;
            }

            $this->rollupRepository->recordHourlyCount($storeViewId, $category, $windowStart, $count);
            $cumulativeCount += $count;
            $hoursProcessed++;

            if ($count > 0) {
                $oldestNonZeroHourOffset = $hourOffset;
            }
        }

        $daysSeeded = $limitedByCeiling
            ? intdiv($hoursProcessed, 24)
            : (int) ceil($oldestNonZeroHourOffset / 24);

        return ['daysSeeded' => $daysSeeded, 'limitedByCeiling' => $limitedByCeiling];
    }

    /**
     * The UTC top of the hour containing $dateTime.
     *
     * @param \DateTimeImmutable $dateTime
     * @return \DateTimeImmutable
     */
    private function topOfHour(\DateTimeImmutable $dateTime): \DateTimeImmutable
    {
        $utc = $dateTime->setTimezone(new \DateTimeZone('UTC'));

        return new \DateTimeImmutable($utc->format('Y-m-d H:00:00'), new \DateTimeZone('UTC'));
    }
}
