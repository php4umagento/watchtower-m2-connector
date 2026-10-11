<?php
/**
 * Copyright © 2026 Watchtower. All rights reserved.
 * Licensed under the Business Source License 1.1 (BUSL-1.1). See LICENSE.
 */

declare(strict_types=1);

namespace Watchtower\Connector\Test\Unit\Replay;

/**
 * Deterministic synthetic store traffic: hourly event counts with a business
 * day shape, a weekly shape, optional growth and seasonal peaks, and day-level
 * noise on top of Poisson arrivals. Pure Poisson is the easy case (real stores
 * have bad days), so $dayNoise multiplies each day's level by a random factor.
 *
 * Own xorshift generator rather than mt_rand(): output must be identical
 * across PHP versions for the asserted bounds to mean anything.
 */
class TrafficModel
{
    /** Relative share of a day's orders per UTC hour; sums to 1.0 after normalising. */
    private const HOUR_SHAPE = [
        0 => 0.4, 1 => 0.25, 2 => 0.2, 3 => 0.2, 4 => 0.25, 5 => 0.5,
        6 => 1.0, 7 => 2.0, 8 => 3.5, 9 => 4.5, 10 => 5.0, 11 => 5.5,
        12 => 6.0, 13 => 5.5, 14 => 5.0, 15 => 5.0, 16 => 5.0, 17 => 5.5,
        18 => 6.0, 19 => 6.5, 20 => 6.0, 21 => 4.5, 22 => 2.5, 23 => 1.0,
    ];

    /** ISO day of week (1=Mon) multiplier. */
    private const DAY_SHAPE = [1 => 1.0, 2 => 1.0, 3 => 0.95, 4 => 0.95, 5 => 0.9, 6 => 0.8, 7 => 0.9];

    private int $state;

    /** @var array<string, float> */
    private array $dayLevels = [];

    /**
     * @param int $ordersPerDay average daily volume on a weekday of multiplier 1.0
     * @param int $seed
     * @param float $dayNoise day-level standard deviation as a fraction (0.15 = +/-15% bad/good days)
     * @param float $weeklyGrowth compounding weekly growth rate (0.01 = 1%/week), applied from $growthFrom
     * @param callable|null $peak fn(\DateTimeImmutable $hour): float extra multiplier (e.g. Black Friday)
     */
    public function __construct(
        private readonly int $ordersPerDay,
        int $seed,
        private readonly float $dayNoise = 0.15,
        private readonly float $weeklyGrowth = 0.0,
        private readonly ?\DateTimeImmutable $growthFrom = null,
        private readonly mixed $peak = null,
    ) {
        $this->state = $seed * 2654435761 % 4294967296 ?: 88172645;
    }

    public function countFor(\DateTimeImmutable $hour): int
    {
        $ts = $hour->getTimestamp();
        $hourOfDay = (int) gmdate('G', $ts);
        $day = gmdate('Y-m-d', $ts);

        $this->dayLevels[$day] ??= max(0.2, 1.0 + $this->dayNoise * $this->gaussian());

        $lambda = $this->ordersPerDay
            * self::DAY_SHAPE[(int) gmdate('N', $ts)]
            * self::HOUR_SHAPE[$hourOfDay] / array_sum(self::HOUR_SHAPE)
            * $this->dayLevels[$day]
            * $this->growthFactor($hour)
            * ($this->peak !== null ? ($this->peak)($hour) : 1.0);

        return $this->poisson($lambda);
    }

    private function growthFactor(\DateTimeImmutable $hour): float
    {
        if ($this->weeklyGrowth === 0.0 || $this->growthFrom === null) {
            return 1.0;
        }

        $weeks = ($hour->getTimestamp() - $this->growthFrom->getTimestamp()) / (7 * 86400);

        return (1.0 + $this->weeklyGrowth) ** $weeks;
    }

    private function uniform(): float
    {
        $x = $this->state;
        $x ^= ($x << 13) & 0xFFFFFFFF;
        $x ^= $x >> 17;
        $x ^= ($x << 5) & 0xFFFFFFFF;
        $this->state = $x;

        return ($x + 1) / 4294967297;
    }

    private function gaussian(): float
    {
        return sqrt(-2.0 * log($this->uniform())) * cos(2.0 * M_PI * $this->uniform());
    }

    private function poisson(float $lambda): int
    {
        if ($lambda > 30.0) {
            return max(0, (int) round($lambda + sqrt($lambda) * $this->gaussian()));
        }

        $limit = exp(-$lambda);
        $product = 1.0;
        $k = 0;

        do {
            $k++;
            $product *= $this->uniform();
        } while ($product > $limit);

        return $k - 1;
    }
}
