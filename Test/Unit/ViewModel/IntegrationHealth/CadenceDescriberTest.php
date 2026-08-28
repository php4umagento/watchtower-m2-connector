<?php
/**
 * Copyright © 2026 Watchtower. All rights reserved.
 * Licensed under the Business Source License 1.1 (BUSL-1.1). See LICENSE.
 */

declare(strict_types=1);

namespace Watchtower\Connector\Test\Unit\ViewModel\IntegrationHealth;

use PHPUnit\Framework\TestCase;
use Watchtower\Connector\Model\CronJobObservation\Cadence;
use Watchtower\Connector\ViewModel\IntegrationHealth\CadenceDescriber;

class CadenceDescriberTest extends TestCase
{
    /**
     * @var CadenceDescriber
     */
    private CadenceDescriber $describer;

    protected function setUp(): void
    {
        $this->describer = new CadenceDescriber();
    }

    /**
     * Cases are looped rather than fed through a data provider, so this suite
     * runs on the PHPUnit shipping with 2.4.7 and 2.4.8 as well as 2.4.9.
     * Attribute providers need PHPUnit 10+, annotation providers were removed
     * in 12, so neither syntax spans all three.
     */
    public function testHumanizesTheMeasuredPeriod(): void
    {
        foreach (self::periodCases() as $case => [$periodSeconds, $expected]) {
            $described = (string) $this->describer->describe($this->confident($periodSeconds, runs: 240));

            self::assertSame($expected . ' (observed, 240 runs)', $described, $case);
        }
    }

    /**
     * @return array<string, array{int, string}>
     */
    private static function periodCases(): array
    {
        return [
            'sub-minute' => [45, 'every 45 sec'],
            'per minute' => [60, 'every minute'],
            'five minutes' => [300, 'every 5 min'],
            'hourly' => [3600, 'every hour'],
            'hourly with cron jitter' => [3700, 'every hour'],
            'ninety minutes stays in minutes' => [5400, 'every 90 min'],
            'six hours' => [21600, 'every 6 hours'],
            'nightly' => [86400, 'every day'],
            'nightly with drift' => [90000, 'every day'],
            'every other day' => [172800, 'every 2 days'],
        ];
    }

    /**
     * A period of 90 minutes is a third away from an hour, so reporting it as
     * "every 2 hours" would describe a job the merchant would not recognize.
     * Covered by periodCases; asserted separately because it is the reason
     * the unit choice is tolerance-based rather than a fixed cutoff.
     */
    public function testPrefersTheSmallerUnitWhenRoundingWouldDistortThePeriod(): void
    {
        self::assertStringStartsWith('every 90 min', (string) $this->describer->describe($this->confident(5400)));
    }

    public function testReportsAJobStillBeingMeasuredAsLearning(): void
    {
        $learning = new Cadence(
            periodSeconds: null,
            thresholdSeconds: null,
            isConfident: false,
            isRegular: false,
            sampleCount: 2,
            observedRunCount: 3,
        );

        self::assertSame('learning cadence', (string) $this->describer->describe($learning));
        self::assertTrue($this->describer->isLearning($learning));
    }

    public function testSaysOneRunRatherThanOneRuns(): void
    {
        $described = (string) $this->describer->describe($this->confident(86400, runs: 1));

        self::assertSame('every day (observed, 1 run)', $described);
    }

    public function testWarnsAboutAJobMeasuredAsIrregular(): void
    {
        $warning = $this->describer->warning($this->confident(300, regular: false));

        self::assertSame('runs irregularly, alerting may be unreliable', (string) $warning);
    }

    public function testDoesNotWarnAboutARegularJob(): void
    {
        self::assertNull($this->describer->warning($this->confident(300)));
    }

    /**
     * A job still being learned has isRegular false only because nothing has
     * been concluded yet, which is not the same claim as "measured, and
     * erratic". Warning on it would tell the merchant something untrue.
     */
    public function testDoesNotWarnAboutAJobItHasNotMeasuredYet(): void
    {
        $learning = new Cadence(
            periodSeconds: null,
            thresholdSeconds: null,
            isConfident: false,
            isRegular: false,
            sampleCount: 1,
            observedRunCount: 2,
        );

        self::assertNull($this->describer->warning($learning));
    }

    /**
     * @param int $periodSeconds
     * @param bool $regular
     * @param int $runs
     * @return Cadence
     */
    private function confident(int $periodSeconds, bool $regular = true, int $runs = 240): Cadence
    {
        return new Cadence(
            periodSeconds: $periodSeconds,
            thresholdSeconds: max(3600, $periodSeconds * 2),
            isConfident: true,
            isRegular: $regular,
            sampleCount: 20,
            observedRunCount: $runs,
        );
    }
}
