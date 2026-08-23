<?php
/**
 * Copyright © 2026 Watchtower. All rights reserved.
 * Licensed under the Business Source License 1.1 (BUSL-1.1). See LICENSE.
 */

declare(strict_types=1);

namespace Watchtower\Connector\Test\Unit\Model\CheckoutFailure;

use PHPUnit\Framework\TestCase;
use Watchtower\Connector\Model\CheckoutFailure\RatioHistory;
use Watchtower\Connector\Model\EventCounter\EventCounterRepository;
use Watchtower\Connector\Model\Rollup\HourlyCountSample;
use Watchtower\Connector\Model\Rollup\RollupRepository;

class RatioHistoryTest extends TestCase
{
    private const STORE_VIEW_ID = 7;
    private const MIN_ATTEMPTS = 5;

    /**
     * The core alignment: failures (event counter) and orders (rollup) are
     * matched by hour, and each qualifying hour's ratio is failures /
     * (failures + orders).
     */
    public function testAlignsFailuresAndOrdersByHourIntoRatios(): void
    {
        $ratios = $this->history(
            failures: ['2026-08-01 10:00:00' => 3, '2026-08-01 11:00:00' => 1],
            orders: ['2026-08-01 10:00:00' => 7, '2026-08-01 11:00:00' => 9],
        )->qualifyingRatios(self::STORE_VIEW_ID, self::MIN_ATTEMPTS, $this->evaluatedHour());

        // 3/(3+7)=0.30, 1/(1+9)=0.10.
        sort($ratios);
        self::assertEqualsWithDelta([0.10, 0.30], $ratios, 1e-9);
    }

    /**
     * A clean busy hour -- orders, no failures -- is a legitimate zero-ratio
     * data point, not a dropped one. This is what gives a clean store the
     * dense zero-heavy series its learned threshold needs.
     */
    public function testACleanBusyHourIsAZeroRatioDataPointNotDropped(): void
    {
        $ratios = $this->history(
            failures: [],
            orders: ['2026-08-01 10:00:00' => 50],
        )->qualifyingRatios(self::STORE_VIEW_ID, self::MIN_ATTEMPTS, $this->evaluatedHour());

        self::assertSame([0.0], $ratios);
    }

    /**
     * An hour below the attempts floor is dropped, so a two-order hour cannot
     * masquerade as a data point.
     */
    public function testAnHourBelowTheAttemptsFloorIsDropped(): void
    {
        $ratios = $this->history(
            failures: ['2026-08-01 10:00:00' => 1],
            orders: ['2026-08-01 10:00:00' => 2],
        )->qualifyingRatios(self::STORE_VIEW_ID, self::MIN_ATTEMPTS, $this->evaluatedHour());

        self::assertSame([], $ratios);
    }

    /**
     * The rollup includes the evaluated hour (<=) while the event counter
     * excludes it (<); RatioHistory must drop it so a store never learns from
     * the hour it is judging.
     */
    public function testTheEvaluatedHourItselfIsExcluded(): void
    {
        $evaluatedKey = $this->evaluatedHour()->format('Y-m-d H:00:00');

        $ratios = $this->history(
            failures: [],
            // Only the evaluated hour has orders; it must not become a ratio.
            orders: [$evaluatedKey => 100],
        )->qualifyingRatios(self::STORE_VIEW_ID, self::MIN_ATTEMPTS, $this->evaluatedHour());

        self::assertSame([], $ratios);
    }

    /**
     * @param array<string, int> $failures
     * @param array<string, int> $orders
     * @return RatioHistory
     */
    private function history(array $failures, array $orders): RatioHistory
    {
        $eventCounter = $this->createStub(EventCounterRepository::class);
        $eventCounter->method('countsInWindow')->willReturn($failures);

        $samples = [];
        foreach ($orders as $hour => $count) {
            $samples[] = new HourlyCountSample(
                new \DateTimeImmutable($hour, new \DateTimeZone('UTC')),
                $count
            );
        }

        $rollup = $this->createStub(RollupRepository::class);
        $rollup->method('allHourlyCountsInWindow')->willReturn($samples);

        return new RatioHistory($eventCounter, $rollup);
    }

    private function evaluatedHour(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-08-23T14:00:00+00:00');
    }
}
