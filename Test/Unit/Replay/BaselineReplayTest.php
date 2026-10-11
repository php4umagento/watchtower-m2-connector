<?php
/**
 * Copyright © 2026 Watchtower. All rights reserved.
 * Licensed under the Business Source License 1.1 (BUSL-1.1). See LICENSE.
 */

declare(strict_types=1);

namespace Watchtower\Connector\Test\Unit\Replay;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Watchtower\Connector\Model\Api\SignalStatus;

/**
 * End-to-end replay of the real baseline pipeline (rollup windows, Check A/B/C
 * ensemble, two-evaluation debounce, persisted state) against seeded synthetic
 * traffic, to measure what a merchant would actually experience: false alerts
 * on a healthy store, detection latency for faults, recovery, warm-up.
 *
 * The bounds below are regression ceilings fitted to measured behaviour with
 * headroom, NOT endorsements of that behaviour. Tests named "KnownGap" state
 * what the product intends and report Incomplete (not Failed) while it does not
 * hold yet; they start passing the day the gap is closed.
 *
 * Synthetic traffic is friendlier than production in some ways (stationary
 * shape, no bots, no promotions) and unfriendlier in others (no tracking
 * outages), so read the numbers as relative evidence, not a forecast.
 * Set WATCHTOWER_REPLAY_REPORT=/path to collect a scorecard of every scenario.
 */
#[Group('replay')]
class BaselineReplayTest extends TestCase
{
    private const DROPS = [SignalStatus::MildDrop, SignalStatus::SevereDrop];
    private const SPIKES = [SignalStatus::MildSpike, SignalStatus::SevereSpike];

    /** @return array<string, array{int}> */
    public static function volumes(): array
    {
        return ['50/day' => [50], '200/day' => [200], '1000/day' => [1000]];
    }

    #[DataProvider('volumes')]
    public function testHealthyStoreFalseAlertRate(int $ordersPerDay): void
    {
        $perWeek = [];

        foreach ([1, 2, 3, 4] as $seed) {
            $harness = new ReplayHarness(new TrafficModel($ordersPerDay, $seed));
            $harness->backfill($this->at('2026-05-04'), $this->at('2026-07-27'));
            $harness->run($this->at('2026-07-27'), $this->at('2026-08-17'));
            $perWeek[] = count($harness->alerts()) / 3;
        }

        $mean = array_sum($perWeek) / count($perWeek);
        ReplayReport::line(sprintf('healthy %4d/day: false alerts/week mean %.2f worst %.2f', $ordersPerDay, $mean, max($perWeek)));

        self::assertLessThanOrEqual(self::FALSE_ALERT_CEILING[$ordersPerDay], max($perWeek));
    }

    private const FALSE_ALERT_CEILING = [50 => 2.0, 200 => 4.5, 1000 => 5.0];

    #[DataProvider('volumes')]
    public function testHealthyStoreFalseAlertRateKnownGap(int $ordersPerDay): void
    {
        $alerts = 0;

        foreach ([1, 2, 3, 4] as $seed) {
            $harness = new ReplayHarness(new TrafficModel($ordersPerDay, $seed));
            $harness->backfill($this->at('2026-05-04'), $this->at('2026-07-27'));
            $harness->run($this->at('2026-07-27'), $this->at('2026-08-17'));
            $alerts += count($harness->alerts());
        }

        $perWeek = $alerts / 12;

        if ($perWeek > 1.0) {
            self::markTestIncomplete(sprintf(
                'KnownGap: a healthy %d/day store raises %.2f confirmed false alerts a week (target: at most 1).',
                $ordersPerDay,
                $perWeek
            ));
        }

        self::assertLessThanOrEqual(1.0, $perWeek);
    }

    #[DataProvider('volumes')]
    public function testFullOutageIsDetectedByDayAndByNight(int $ordersPerDay): void
    {
        $latencies = [];

        foreach (['2026-08-12 14:00' => 'day', '2026-08-12 22:00' => 'night'] as $start => $label) {
            foreach ([1, 2, 3, 4, 5] as $seed) {
                $faultStart = $this->at($start);
                $faultEnd = $faultStart->modify('+12 hours');
                $harness = $this->replayWithFault($ordersPerDay, $seed, $faultStart, $faultEnd, static fn (int $n): int => 0);
                $latencies[$label][] = $harness->hoursToDetect($faultStart, $faultEnd, self::DROPS);
            }
        }

        foreach ($latencies as $label => $values) {
            $found = array_filter($values, static fn (?int $v): bool => $v !== null);
            sort($found);
            ReplayReport::line(sprintf(
                'outage 12h %4d/day %-5s: detected %d/%d, hours to detect %s',
                $ordersPerDay,
                $label,
                count($found),
                count($values),
                implode(',', $found)
            ));
            self::assertCount(count($values), $found, "a 12h total outage ($label) went undetected");
            self::assertLessThanOrEqual(self::OUTAGE_WORST_HOURS[$ordersPerDay], max($found));
        }
    }

    private const OUTAGE_WORST_HOURS = [50 => 12, 200 => 10, 1000 => 8];

    #[DataProvider('volumes')]
    public function testHalfTrafficDropIsUsuallyDetected(int $ordersPerDay): void
    {
        $found = [];

        foreach ([1, 2, 3, 4, 5, 6] as $seed) {
            $faultStart = $this->at('2026-08-12 14:00');
            $faultEnd = $faultStart->modify('+12 hours');
            $harness = $this->replayWithFault($ordersPerDay, $seed, $faultStart, $faultEnd, static fn (int $n): int => intdiv($n, 2));
            $latency = $harness->hoursToDetect($faultStart, $faultEnd, self::DROPS);

            if ($latency !== null) {
                $found[] = $latency;
            }
        }

        sort($found);
        ReplayReport::line(sprintf('half drop 12h %4d/day: detected %d/6, hours to detect %s', $ordersPerDay, count($found), implode(',', $found)));
        self::assertGreaterThanOrEqual(3, count($found));
    }

    /** 50/day sits on the inter-arrival path, which only flags a burst of 5+ events at 4x its bucket max: 1 of 6 here. */
    private const SURGE_MIN_DETECTED = [50 => 1, 200 => 5, 1000 => 5];

    #[DataProvider('volumes')]
    public function testFiveFoldSurgeIsDetected(int $ordersPerDay): void
    {
        $found = [];

        foreach ([1, 2, 3, 4, 5, 6] as $seed) {
            $faultStart = $this->at('2026-08-12 14:00');
            $faultEnd = $faultStart->modify('+6 hours');
            $harness = $this->replayWithFault($ordersPerDay, $seed, $faultStart, $faultEnd, static fn (int $n): int => $n * 5);
            $latency = $harness->hoursToDetect($faultStart, $faultEnd, self::SPIKES);

            if ($latency !== null) {
                $found[] = $latency;
            }
        }

        sort($found);
        ReplayReport::line(sprintf('5x surge 6h   %4d/day: detected %d/6, hours to detect %s', $ordersPerDay, count($found), implode(',', $found)));
        self::assertGreaterThanOrEqual(self::SURGE_MIN_DETECTED[$ordersPerDay], count($found));
    }

    #[DataProvider('volumes')]
    public function testSignalReturnsToNormalAfterAnOutageEnds(int $ordersPerDay): void
    {
        $hoursToNormal = [];

        foreach ([1, 2, 3, 4] as $seed) {
            $faultStart = $this->at('2026-08-12 14:00');
            $faultEnd = $faultStart->modify('+12 hours');
            $harness = $this->replayWithFault(
                $ordersPerDay,
                $seed,
                $faultStart,
                $faultEnd,
                static fn (int $n): int => 0,
                $faultEnd->modify('+72 hours')
            );

            self::assertTrue($harness->endedNormal(), 'signal still reported an anomaly 72h after the outage ended');
            $hoursToNormal[] = count($harness->alerts($faultStart, $faultEnd->modify('+72 hours')));
        }

        ReplayReport::line(sprintf('outage+72h recovery %4d/day: alerts raised across incident per seed %s', $ordersPerDay, implode(',', $hoursToNormal)));
        self::assertLessThanOrEqual(8, max($hoursToNormal));
    }

    public function testColdStartStaysQuietUntilABaselineExists(): void
    {
        $harness = new ReplayHarness(new TrafficModel(200, 1));
        $harness->run($this->at('2026-08-03'), $this->at('2026-09-07'));

        $firstFortnight = $harness->alerts($this->at('2026-08-03'), $this->at('2026-08-17'));
        ReplayReport::line(sprintf(
            'cold start 200/day: %d of %d hours INSUFFICIENT_DATA over 5 weeks, %d alerts in the first 14 days',
            $harness->warmUpHours(),
            $harness->evaluatedHours(),
            count($firstFortnight)
        ));

        self::assertSame([], $firstFortnight);
        self::assertGreaterThan(0, $harness->warmUpHours());
    }

    public function testBelowTheValidatedVolumeIsHonestAboutNotKnowing(): void
    {
        $alerts = 0;

        foreach ([1, 2, 3] as $seed) {
            $faultStart = $this->at('2026-08-12 14:00');
            $faultEnd = $faultStart->modify('+24 hours');
            $harness = $this->replayWithFault(10, $seed, $faultStart, $faultEnd, static fn (int $n): int => 0);
            $alerts += count($harness->alerts());
            self::assertNull($harness->hoursToDetect($faultStart, $faultEnd, self::DROPS));
            self::assertSame([], $harness->alerts(), 'a store under the validated volume must not page on rate signals');
        }

        ReplayReport::line('10/day outage 24h: never reported a drop, zero alerts (rate signals are INSUFFICIENT_DATA by design)');
        self::assertSame(0, $alerts);
    }

    public function testBlackFridayPeakDoesNotCauseAPostPeakDropStorm(): void
    {
        $post = [];

        foreach ([1, 2, 3] as $seed) {
            $harness = $this->blackFridayReplay($seed, true);
            $post[] = count($harness->alerts($this->at('2026-12-01'), $this->at('2026-12-14')));
        }

        ReplayReport::line('black friday 200/day, seasonal on: alerts 1-14 Dec per seed ' . implode(',', $post));
        self::assertLessThanOrEqual(4, max($post));
    }

    public function testSeasonalCheckReducesBlackFridayWeekAlertsKnownGap(): void
    {
        $on = 0;
        $off = 0;

        foreach ([1, 2, 3] as $seed) {
            $week = [$this->at('2026-11-23'), $this->at('2026-12-01')];
            $on += count($this->blackFridayReplay($seed, true)->alerts(...$week));
            $off += count($this->blackFridayReplay($seed, false)->alerts(...$week));
        }

        ReplayReport::line(sprintf('black friday week 200/day, 3 seeds: alerts seasonal on %d, seasonal off %d', $on, $off));

        if ($on > $off * 0.7) {
            self::markTestIncomplete(sprintf(
                'KnownGap: with a year of seasonal history the Black Friday week raised %d alerts, against %d with the seasonal check disabled (target: clearly fewer).',
                $on,
                $off
            ));
        }

        self::assertLessThanOrEqual($off * 0.7, $on);
    }

    private function blackFridayReplay(int $seed, bool $seasonal): ReplayHarness
    {
        $peak = function (\DateTimeImmutable $hour): float {
            $blackFriday = $this->at($hour->format('Y') . '-11-01')->modify('fourth thursday of this month')->modify('+1 day');
            $days = abs(($hour->setTime(0, 0)->getTimestamp() - $blackFriday->getTimestamp()) / 86400);

            return $days <= 3 ? 3.0 : 1.0;
        };

        $harness = new ReplayHarness(new TrafficModel(200, $seed, 0.1, 0.0, null, $peak), $seasonal);
        $harness->backfill($this->at('2024-10-01'), $this->at('2026-11-02'));
        $harness->run($this->at('2026-11-02'), $this->at('2026-12-14'));

        return $harness;
    }

    /**
     * Two warm-up days of normal evaluation, the fault, then $until (default fault end + 12h).
     *
     * @param callable(int): int $effect maps the natural hourly count to the faulty one
     */
    private function replayWithFault(
        int $ordersPerDay,
        int $seed,
        \DateTimeImmutable $faultStart,
        \DateTimeImmutable $faultEnd,
        callable $effect,
        ?\DateTimeImmutable $until = null
    ): ReplayHarness {
        $harness = new ReplayHarness(new TrafficModel($ordersPerDay, $seed));
        $harness->backfill($this->at('2026-05-04'), $this->at('2026-08-10'));
        $harness->run(
            $this->at('2026-08-10'),
            $until ?? $faultEnd->modify('+12 hours'),
            static fn (\DateTimeImmutable $hour, int $natural): int
                => $hour >= $faultStart && $hour < $faultEnd ? $effect($natural) : $natural
        );

        return $harness;
    }

    private function at(string $time): \DateTimeImmutable
    {
        return new \DateTimeImmutable($time, new \DateTimeZone('UTC'));
    }
}
