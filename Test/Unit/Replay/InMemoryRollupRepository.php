<?php
/**
 * Copyright © 2026 Watchtower. All rights reserved.
 * Licensed under the Business Source License 1.1 (BUSL-1.1). See LICENSE.
 */

declare(strict_types=1);

namespace Watchtower\Connector\Test\Unit\Replay;

use Watchtower\Connector\Model\Rollup\DailyCountSample;
use Watchtower\Connector\Model\Rollup\HourlyCountSample;
use Watchtower\Connector\Model\Rollup\RollupRepository;

/**
 * Array-backed stand-in for RollupRepository that mirrors its query windows
 * (same lower/upper bounds, same ordering, same day-of-week mapping) so the
 * real evaluators run against it unchanged. Skips the real class's constructor
 * on purpose: every method an evaluator reads is overridden below.
 *
 * One deliberate simplification: daily totals are summed from hourly rows on
 * demand instead of going through rollupAndPrune(), so history older than the
 * 90-day hourly retention stays queryable at hourly grain. No evaluator reads
 * hourly data past 12 weeks, so this cannot change a verdict.
 */
class InMemoryRollupRepository extends RollupRepository
{
    /** @var array<string, array<int, int>> "storeViewId|category" => [unix hour => count] */
    private array $hourly = [];

    /** @var array<string, array<string, int>> "storeViewId|category" => [Y-m-d => count] */
    private array $daily = [];

    public function __construct()
    {
    }

    public function recordHourlyCount(int $storeViewId, string $category, \DateTimeImmutable $hourBucket, int $count): void
    {
        $key = $storeViewId . '|' . $category;
        $hour = $this->topOfHour($hourBucket);
        $day = gmdate('Y-m-d', $hour);

        $previous = $this->hourly[$key][$hour] ?? 0;
        $this->hourly[$key][$hour] = $count;
        $this->daily[$key][$day] = ($this->daily[$key][$day] ?? 0) + $count - $previous;
    }

    public function hasAnyHourlyDataForCategories(int $storeViewId, array $categories): bool
    {
        foreach ($categories as $category) {
            if (!empty($this->hourly[$storeViewId . '|' . $category])) {
                return true;
            }
        }

        return false;
    }

    public function hourlyCountsForBucket(
        int $storeViewId,
        string $category,
        int $isoDayOfWeek,
        int $hourOfDay,
        int $weeks,
        \DateTimeImmutable $now
    ): array {
        $rows = $this->hourly[$storeViewId . '|' . $category] ?? [];
        $upper = $this->topOfHour($now);
        $lower = $this->lowerBound($now, $weeks * 7);

        $samples = [];

        // First matching slot at or after the lower bound, then every 7 days.
        for ($ts = $lower; $ts <= $upper; $ts += 3600) {
            if ((int) gmdate('N', $ts) === $isoDayOfWeek && (int) gmdate('G', $ts) === $hourOfDay) {
                break;
            }
        }

        for (; $ts <= $upper; $ts += 7 * 86400) {
            if (isset($rows[$ts])) {
                $samples[] = $this->hourlySample($ts, $rows[$ts]);
            }
        }

        return $samples;
    }

    public function allHourlyCountsInWindow(int $storeViewId, string $category, int $weeks, \DateTimeImmutable $now): array
    {
        $rows = $this->hourly[$storeViewId . '|' . $category] ?? [];
        $upper = $this->topOfHour($now);
        $samples = [];

        for ($ts = $this->lowerBound($now, $weeks * 7); $ts <= $upper; $ts += 3600) {
            if (isset($rows[$ts])) {
                $samples[] = $this->hourlySample($ts, $rows[$ts]);
            }
        }

        return $samples;
    }

    public function dailyCountsInWindow(int $storeViewId, string $category, \DateTimeImmutable $now, int $days): array
    {
        $rows = $this->daily[$storeViewId . '|' . $category] ?? [];
        $utc = new \DateTimeZone('UTC');
        $lower = $now->setTimezone($utc)->modify(sprintf('-%d days', $days))->format('Y-m-d');
        $upper = $now->setTimezone($utc)->format('Y-m-d');

        $samples = [];

        foreach ($rows as $date => $count) {
            if ($date >= $lower && $date <= $upper) {
                $samples[$date] = new DailyCountSample(new \DateTimeImmutable($date, $utc), $count);
            }
        }

        ksort($samples);

        return array_values($samples);
    }

    private function topOfHour(\DateTimeImmutable $instant): int
    {
        return intdiv($instant->getTimestamp(), 3600) * 3600;
    }

    /** Real query: hour_bucket >= (now - N days) as a full datetime, not midnight. */
    private function lowerBound(\DateTimeImmutable $now, int $days): int
    {
        return $now->getTimestamp() - $days * 86400;
    }

    private function hourlySample(int $ts, int $count): HourlyCountSample
    {
        return new HourlyCountSample((new \DateTimeImmutable('@' . $ts))->setTimezone(new \DateTimeZone('UTC')), $count);
    }
}
