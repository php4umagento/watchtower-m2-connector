<?php
/**
 * Copyright © 2026 Watchtower. All rights reserved.
 * Licensed under the Business Source License 1.1 (BUSL-1.1). See LICENSE.
 */

declare(strict_types=1);

namespace Watchtower\Connector\Test\Unit\Replay;

use PHPUnit\Framework\TestCase;
use Watchtower\Connector\Model\Api\SignalStatus;

/**
 * End-to-end replay of the real baseline pipeline (rollup windows, Check A/B/C
 * ensemble, sustained-shift check, two-evaluation debounce, persisted state)
 * against seeded synthetic traffic, to measure what a merchant would actually
 * experience: false alerts on a healthy store, detection latency for faults,
 * recovery, warm-up.
 *
 * The bounds below are regression ceilings fitted to measured behaviour with
 * headroom. When a new gap is found, add a test that states the intended
 * behaviour and reports it Incomplete (markTestIncomplete) while it does not
 * hold, rather than loosening a ceiling.
 *
 * Synthetic traffic is friendlier than production in some ways (stationary
 * shape, no bots, no promotions) and unfriendlier in others (no tracking
 * outages), so read the numbers as relative evidence, not a forecast.
 * Set WATCHTOWER_REPLAY_REPORT=/path to collect a scorecard of every scenario.
 * Plain loops rather than data providers: the suite must run on PHPUnit 9, 10 and 12.
 */
class BaselineReplayTest extends TestCase
{
    private const VOLUMES = [50, 200, 1000];

    private const DROPS = [SignalStatus::MildDrop, SignalStatus::SevereDrop];
    private const SPIKES = [SignalStatus::MildSpike, SignalStatus::SevereSpike];

    /** Worst seed's confirmed false alerts per week on a healthy store, with headroom. */
    private const FALSE_ALERT_CEILING = [50 => 2.0, 200 => 2.0, 1000 => 3.0];

    /** Slowest detection of a 12h total outage across day and night starts, in hours. */
    private const OUTAGE_WORST_HOURS = [50 => 12, 200 => 8, 1000 => 8];

    /** Of 6 seeds, how many must catch a 12h 50% drop: single hours cannot prove it, the sustained-shift check does. */
    private const HALF_DROP_MIN_DETECTED_OF_6 = [50 => 3, 200 => 3, 1000 => 4];

    /** Of 6 seeds, how many must catch a 6h 5x surge. */
    private const SURGE_MIN_DETECTED_OF_6 = [50 => 4, 200 => 5, 1000 => 5];

    public function testHealthyStoreFalseAlertRateStaysWithinCeiling(): void
    {
        foreach (self::VOLUMES as $ordersPerDay) {
            $perWeek = $this->healthyAlertsPerWeekBySeed($ordersPerDay);
            $mean = array_sum($perWeek) / count($perWeek);
            ReplayReport::line(sprintf('healthy %4d/day: false alerts/week mean %.2f worst %.2f', $ordersPerDay, $mean, max($perWeek)));

            self::assertLessThanOrEqual(self::FALSE_ALERT_CEILING[$ordersPerDay], max($perWeek), "$ordersPerDay/day");
        }
    }

    public function testHealthyStoreRaisesAtMostOneFalseAlertAWeekOnAverage(): void
    {
        foreach (self::VOLUMES as $ordersPerDay) {
            $perWeek = $this->healthyAlertsPerWeekBySeed($ordersPerDay);

            self::assertLessThanOrEqual(1.0, array_sum($perWeek) / count($perWeek), "$ordersPerDay/day");
        }
    }

    public function testFullOutageIsDetectedByDayAndByNight(): void
    {
        foreach (self::VOLUMES as $ordersPerDay) {
            foreach (['2026-08-12 14:00' => 'day', '2026-08-12 22:00' => 'night'] as $start => $label) {
                $found = [];

                foreach ([1, 2, 3, 4, 5] as $seed) {
                    $faultStart = $this->utc($start);
                    $faultEnd = $faultStart->modify('+12 hours');
                    $harness = $this->replayWithFault($ordersPerDay, $seed, $faultStart, $faultEnd, static fn (int $n): int => 0);
                    $latency = $harness->hoursToDetect($faultStart, $faultEnd, self::DROPS);

                    self::assertNotNull($latency, "12h total outage ($label, $ordersPerDay/day, seed $seed) went undetected");
                    $found[] = $latency;
                }

                sort($found);
                ReplayReport::line(sprintf('outage 12h %4d/day %-5s: detected 5/5, hours to detect %s', $ordersPerDay, $label, implode(',', $found)));
                self::assertLessThanOrEqual(self::OUTAGE_WORST_HOURS[$ordersPerDay], max($found), "$ordersPerDay/day $label");
            }
        }
    }

    public function testHalfTrafficDropIsUsuallyDetected(): void
    {
        foreach (self::VOLUMES as $ordersPerDay) {
            $found = [];

            foreach ([1, 2, 3, 4, 5, 6] as $seed) {
                $faultStart = $this->utc('2026-08-12 14:00');
                $faultEnd = $faultStart->modify('+12 hours');
                $harness = $this->replayWithFault($ordersPerDay, $seed, $faultStart, $faultEnd, static fn (int $n): int => intdiv($n, 2));
                $latency = $harness->hoursToDetect($faultStart, $faultEnd, self::DROPS);

                if ($latency !== null) {
                    $found[] = $latency;
                }
            }

            sort($found);
            ReplayReport::line(sprintf('half drop 12h %4d/day: detected %d/6, hours to detect %s', $ordersPerDay, count($found), implode(',', $found)));
            self::assertGreaterThanOrEqual(self::HALF_DROP_MIN_DETECTED_OF_6[$ordersPerDay], count($found), "$ordersPerDay/day");
        }
    }

    public function testFiveFoldSurgeIsDetected(): void
    {
        foreach (self::VOLUMES as $ordersPerDay) {
            $found = [];

            foreach ([1, 2, 3, 4, 5, 6] as $seed) {
                $faultStart = $this->utc('2026-08-12 14:00');
                $faultEnd = $faultStart->modify('+6 hours');
                $harness = $this->replayWithFault($ordersPerDay, $seed, $faultStart, $faultEnd, static fn (int $n): int => $n * 5);
                $latency = $harness->hoursToDetect($faultStart, $faultEnd, self::SPIKES);

                if ($latency !== null) {
                    $found[] = $latency;
                }
            }

            sort($found);
            ReplayReport::line(sprintf('5x surge 6h   %4d/day: detected %d/6, hours to detect %s', $ordersPerDay, count($found), implode(',', $found)));
            self::assertGreaterThanOrEqual(self::SURGE_MIN_DETECTED_OF_6[$ordersPerDay], count($found), "$ordersPerDay/day");
        }
    }

    public function testSignalReturnsToNormalAfterAnOutageEnds(): void
    {
        foreach (self::VOLUMES as $ordersPerDay) {
            $alertsPerIncident = [];

            foreach ([1, 2, 3, 4] as $seed) {
                $faultStart = $this->utc('2026-08-12 14:00');
                $faultEnd = $faultStart->modify('+12 hours');
                $until = $faultEnd->modify('+72 hours');
                $harness = $this->replayWithFault($ordersPerDay, $seed, $faultStart, $faultEnd, static fn (int $n): int => 0, $until);

                self::assertTrue($harness->endedNormal(), "$ordersPerDay/day seed $seed: still anomalous 72h after the outage ended");
                $alertsPerIncident[] = count($harness->alerts($faultStart, $until));
            }

            ReplayReport::line(sprintf('outage 12h + 72h recovery %4d/day: alerts across the incident per seed %s', $ordersPerDay, implode(',', $alertsPerIncident)));
            self::assertLessThanOrEqual(8, max($alertsPerIncident), "$ordersPerDay/day");
        }
    }

    public function testColdStartStaysQuietUntilABaselineExists(): void
    {
        $harness = new ReplayHarness(new TrafficModel(200, 1));
        $harness->run($this->utc('2026-08-03'), $this->utc('2026-09-07'));

        $firstFortnight = $harness->alerts($this->utc('2026-08-03'), $this->utc('2026-08-17'));
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
        foreach ([1, 2, 3] as $seed) {
            $faultStart = $this->utc('2026-08-12 14:00');
            $faultEnd = $faultStart->modify('+24 hours');
            $harness = $this->replayWithFault(10, $seed, $faultStart, $faultEnd, static fn (int $n): int => 0);

            self::assertNull($harness->hoursToDetect($faultStart, $faultEnd, self::DROPS), 'seed ' . $seed);
            self::assertSame([], $harness->alerts(), "seed $seed: a store under the validated volume must not page on rate signals");
        }

        ReplayReport::line('10/day outage 24h: never reported a drop, zero alerts (rate signals stay INSUFFICIENT_DATA by design)');
    }

    public function testBlackFridayPeakDoesNotCauseAPostPeakDropStorm(): void
    {
        $post = [];

        foreach ([1, 2, 3] as $seed) {
            $harness = $this->blackFridayReplay($seed, true);
            $post[] = count($harness->alerts($this->utc('2026-12-01'), $this->utc('2026-12-14')));
        }

        ReplayReport::line('black friday 200/day, seasonal on: alerts 1-14 Dec per seed ' . implode(',', $post));
        self::assertLessThanOrEqual(5, max($post));
    }

    public function testSeasonalCheckReducesBlackFridayWeekAlerts(): void
    {
        $on = 0;
        $off = 0;
        $week = [$this->utc('2026-11-23'), $this->utc('2026-12-01')];

        foreach ([1, 2, 3] as $seed) {
            $on += count($this->blackFridayReplay($seed, true)->alerts(...$week));
            $off += count($this->blackFridayReplay($seed, false)->alerts(...$week));
        }

        ReplayReport::line(sprintf('black friday week 200/day, 3 seeds: alerts seasonal on %d, seasonal off %d', $on, $off));
        self::assertLessThanOrEqual($off * 0.6, $on);
    }

    /**
     * @return float[] confirmed anomaly alerts per week, one entry per seed
     */
    private function healthyAlertsPerWeekBySeed(int $ordersPerDay): array
    {
        $perWeek = [];

        foreach ([1, 2, 3, 4] as $seed) {
            $harness = new ReplayHarness(new TrafficModel($ordersPerDay, $seed));
            $harness->backfill($this->utc('2026-05-04'), $this->utc('2026-07-27'));
            $harness->run($this->utc('2026-07-27'), $this->utc('2026-08-17'));
            $perWeek[] = count($harness->alerts()) / 3;
        }

        return $perWeek;
    }

    private function blackFridayReplay(int $seed, bool $seasonal): ReplayHarness
    {
        $peak = function (\DateTimeImmutable $hour): float {
            $blackFriday = $this->utc($hour->format('Y') . '-11-01')->modify('fourth thursday of this month')->modify('+1 day');
            $days = abs(($hour->setTime(0, 0)->getTimestamp() - $blackFriday->getTimestamp()) / 86400);

            return $days <= 3 ? 3.0 : 1.0;
        };

        $harness = new ReplayHarness(new TrafficModel(200, $seed, 0.1, 0.0, null, $peak), $seasonal);
        $harness->backfill($this->utc('2024-10-01'), $this->utc('2026-11-02'));
        $harness->run($this->utc('2026-11-02'), $this->utc('2026-12-14'));

        return $harness;
    }

    /**
     * Normal evaluation from 2026-08-10, the fault, then up to $until (default fault end + 12h).
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
        $harness->backfill($this->utc('2026-05-04'), $this->utc('2026-08-10'));
        $harness->run(
            $this->utc('2026-08-10'),
            $until ?? $faultEnd->modify('+12 hours'),
            static fn (\DateTimeImmutable $hour, int $natural): int
                => $hour >= $faultStart && $hour < $faultEnd ? $effect($natural) : $natural
        );

        return $harness;
    }

    private function utc(string $time): \DateTimeImmutable
    {
        return new \DateTimeImmutable($time, new \DateTimeZone('UTC'));
    }
}
