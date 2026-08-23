<?php
/**
 * Copyright © 2026 Watchtower. All rights reserved.
 * Licensed under the Business Source License 1.1 (BUSL-1.1). See LICENSE.
 */

declare(strict_types=1);

namespace Watchtower\Connector\Test\Unit\Model\Threshold;

use PHPUnit\Framework\TestCase;
use Watchtower\Connector\Model\Threshold\LearnedThresholdCalculator;

/**
 * The learned-threshold formula on its own, with no signal and no storage.
 * Uses checkout_failure's real fixed values and floors (0.25/0.50,
 * 0.08/0.15) as concrete numbers, though the calculator is value-agnostic.
 *
 * MIN_LEARNING_SAMPLES (100) is private; the sample-gate tests reference the
 * boundary by array length deliberately, as a contract on that value.
 */
class LearnedThresholdCalculatorTest extends TestCase
{
    private const FIXED_MILD = 0.25;
    private const FIXED_SEVERE = 0.50;
    private const MILD_FLOOR = 0.08;
    private const SEVERE_FLOOR = 0.15;

    public function testBelowTheSampleMinimumReturnsTheFixedDefaultsUntouched(): void
    {
        $result = $this->effective(array_fill(0, 99, 0.0));

        self::assertSame(self::FIXED_MILD, $result->mild);
        self::assertSame(self::FIXED_SEVERE, $result->severe);
    }

    /**
     * A clean store (all-zero history, at the sample minimum) tightens onto
     * the floors, not to zero -- the floor is what stops a single genuine
     * decline from alerting.
     */
    public function testACleanHistoryCollapsesOntoTheFloorsRatherThanZero(): void
    {
        $result = $this->effective(array_fill(0, 100, 0.0));

        self::assertEqualsWithDelta(self::MILD_FLOOR, $result->mild, 1e-9);
        self::assertEqualsWithDelta(self::SEVERE_FLOOR, $result->severe, 1e-9);
    }

    /**
     * A store whose own normal is high is held at the fixed defaults, never
     * loosened past them: 2.5 x 0.30 = 0.75, clamped back down to 0.25/0.50.
     */
    public function testAHighNormalIsClampedBackToTheFixedDefaults(): void
    {
        $result = $this->effective(array_fill(0, 100, 0.30));

        self::assertEqualsWithDelta(self::FIXED_MILD, $result->mild, 1e-9);
        self::assertEqualsWithDelta(self::FIXED_SEVERE, $result->severe, 1e-9);
    }

    /**
     * A moderate normal lands between the floor and the fixed default:
     * 2.5 x 0.05 = 0.125, which clears the mild floor (0.08) but is caught by
     * the severe floor (0.15).
     */
    public function testAModerateNormalLandsBetweenFloorAndFixedDefault(): void
    {
        $result = $this->effective(array_fill(0, 100, 0.05));

        self::assertEqualsWithDelta(0.125, $result->mild, 1e-9);
        self::assertEqualsWithDelta(self::SEVERE_FLOOR, $result->severe, 1e-9);
        self::assertLessThanOrEqual($result->severe, $result->mild);
    }

    /**
     * The median, not the mean, drives the learned value, so a training
     * window containing a handful of attack-spike hours does not drag the
     * threshold up: 90 clean hours and 10 hours at 90% still learns off a
     * median of zero and lands on the floors.
     */
    public function testASparseBurstInHistoryDoesNotMoveTheThresholdBecauseTheMedianIsRobust(): void
    {
        $history = array_merge(array_fill(0, 90, 0.0), array_fill(0, 10, 0.90));

        $result = $this->effective($history);

        self::assertEqualsWithDelta(self::MILD_FLOOR, $result->mild, 1e-9);
        self::assertEqualsWithDelta(self::SEVERE_FLOOR, $result->severe, 1e-9);
    }

    /**
     * Mild never exceeds severe for any history, since severe's floor and
     * ceiling both sit above mild's.
     */
    public function testMildNeverExceedsSevereAcrossTheWholeRange(): void
    {
        foreach ([0.0, 0.02, 0.05, 0.10, 0.20, 0.40, 0.95] as $normal) {
            $result = $this->effective(array_fill(0, 100, $normal));

            self::assertLessThanOrEqual(
                $result->severe,
                $result->mild,
                sprintf('mild %.4f exceeded severe %.4f at normal %.2f', $result->mild, $result->severe, $normal)
            );
        }
    }

    /**
     * @param float[] $history
     * @return \Watchtower\Connector\Model\Threshold\LearnedThresholds
     */
    private function effective(array $history)
    {
        return (new LearnedThresholdCalculator())->effective(
            $history,
            self::FIXED_MILD,
            self::FIXED_SEVERE,
            self::MILD_FLOOR,
            self::SEVERE_FLOOR
        );
    }
}
