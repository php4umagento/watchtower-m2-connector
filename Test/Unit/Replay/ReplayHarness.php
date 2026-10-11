<?php
/**
 * Copyright © 2026 Watchtower. All rights reserved.
 * Licensed under the Business Source License 1.1 (BUSL-1.1). See LICENSE.
 */

declare(strict_types=1);

namespace Watchtower\Connector\Test\Unit\Replay;

use Watchtower\Connector\Model\Api\ReportReason;
use Watchtower\Connector\Model\Api\SignalStatus;
use Watchtower\Connector\Model\RateSignal\DispersionEvaluator;
use Watchtower\Connector\Model\RateSignal\SeasonalIndexEvaluator;
use Watchtower\Connector\Model\Seasonal\RetailCalendar;

/**
 * Drives the real DispersionEvaluator (Check A/B/C, ensemble, debounce, state)
 * hour by hour over synthetic traffic, in the same order ReportingService
 * does: record the completed hour, then evaluate it. Only the two persistence
 * classes are swapped for in-memory versions.
 */
class ReplayHarness
{
    public const STORE_VIEW_ID = 1;
    public const CATEGORY = 'checkout';

    private const ANOMALIES = [
        SignalStatus::MildDrop, SignalStatus::SevereDrop, SignalStatus::MildSpike, SignalStatus::SevereSpike,
    ];

    private InMemoryRollupRepository $rollup;
    private DispersionEvaluator $evaluator;

    /** @var array<int, array{hour: \DateTimeImmutable, count: int, status: SignalStatus, reason: ReportReason}> */
    private array $log = [];

    public function __construct(private readonly TrafficModel $traffic, bool $seasonalEnabled = true)
    {
        $this->rollup = new InMemoryRollupRepository();
        $seasonal = $seasonalEnabled ? null : new class ($this->rollup) extends SeasonalIndexEvaluator {
            public function __construct(\Watchtower\Connector\Model\Rollup\RollupRepository $rollup)
            {
                parent::__construct($rollup, new RetailCalendar());
            }

            public function adjustedExpectedValue(int $storeViewId, string $category, \DateTimeImmutable $evaluatedHour, float $median): ?float
            {
                return null;
            }
        };
        $this->evaluator = new DispersionEvaluator(
            $this->rollup,
            new InMemoryDispersionStateRepository(),
            seasonalIndexEvaluator: $seasonal
        );
    }

    /**
     * Records history without evaluating, like HistorySeeder does at install time.
     */
    public function backfill(\DateTimeImmutable $from, \DateTimeImmutable $to): void
    {
        for ($hour = $from; $hour < $to; $hour = $hour->modify('+1 hour')) {
            $this->rollup->recordHourlyCount(self::STORE_VIEW_ID, self::CATEGORY, $hour, $this->traffic->countFor($hour));
        }
    }

    /**
     * Evaluates every hour in [from, to). $fault, if given, maps an hour and its
     * natural count to the count the connector actually sees (0 for an outage).
     *
     * @param callable|null $fault fn(\DateTimeImmutable $hour, int $natural): int
     */
    public function run(\DateTimeImmutable $from, \DateTimeImmutable $to, ?callable $fault = null): void
    {
        for ($hour = $from; $hour < $to; $hour = $hour->modify('+1 hour')) {
            $natural = $this->traffic->countFor($hour);
            $count = $fault !== null ? $fault($hour, $natural) : $natural;

            $this->rollup->recordHourlyCount(self::STORE_VIEW_ID, self::CATEGORY, $hour, $count);

            $report = $this->evaluator->evaluate(
                self::STORE_VIEW_ID,
                'default',
                self::CATEGORY,
                $count,
                $hour,
                $hour->modify('+1 hour')
            );

            $this->log[] = ['hour' => $hour, 'count' => $count, 'status' => $report->status, 'reason' => $report->reason];
        }
    }

    /**
     * Alerts a merchant would receive: confirmed transitions into an anomalous status.
     *
     * @return array<int, array{hour: \DateTimeImmutable, status: SignalStatus}>
     */
    public function alerts(?\DateTimeImmutable $from = null, ?\DateTimeImmutable $to = null): array
    {
        $alerts = [];

        foreach ($this->log as $entry) {
            if ($from !== null && $entry['hour'] < $from) {
                continue;
            }

            if ($to !== null && $entry['hour'] >= $to) {
                continue;
            }

            if ($entry['reason'] === ReportReason::Transition && in_array($entry['status'], self::ANOMALIES, true)) {
                $alerts[] = ['hour' => $entry['hour'], 'status' => $entry['status']];
            }
        }

        return $alerts;
    }

    /**
     * Hours from $faultStart until the connector first REPORTS one of $statuses
     * (a fresh transition or a heartbeat repeating an already-confirmed status),
     * or null if it never does before $until. 1 means the fault's own first hour.
     *
     * @param SignalStatus[] $statuses
     */
    public function hoursToDetect(\DateTimeImmutable $faultStart, \DateTimeImmutable $until, array $statuses): ?int
    {
        foreach ($this->log as $entry) {
            if ($entry['hour'] >= $faultStart && $entry['hour'] < $until && in_array($entry['status'], $statuses, true)) {
                return (int) (($entry['hour']->getTimestamp() - $faultStart->getTimestamp()) / 3600) + 1;
            }
        }

        return null;
    }

    /** Whether the signal was back to a confirmed Normal at the last evaluated hour. */
    public function endedNormal(): bool
    {
        return end($this->log)['status'] === SignalStatus::Normal;
    }

    /** Hours evaluated that reported INSUFFICIENT_DATA (warm-up). */
    public function warmUpHours(): int
    {
        return count(array_filter($this->log, static fn (array $e): bool => $e['status'] === SignalStatus::InsufficientData));
    }

    public function evaluatedHours(): int
    {
        return count($this->log);
    }
}
