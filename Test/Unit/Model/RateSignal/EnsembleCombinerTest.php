<?php
/**
 * Copyright © 2026 Watchtower. All rights reserved.
 * Licensed under the Business Source License 1.1 (BUSL-1.1). See LICENSE.
 */

declare(strict_types=1);

namespace Watchtower\Connector\Test\Unit\Model\RateSignal;

use PHPUnit\Framework\TestCase;
use Watchtower\Connector\Model\Api\SignalStatus;
use Watchtower\Connector\Model\RateSignal\DispersionEvaluator;
use Watchtower\Connector\Model\RateSignal\DispersionState;
use Watchtower\Connector\Model\RateSignal\DispersionStateRepository;
use Watchtower\Connector\Model\RateSignal\SeasonalIndexEvaluator;
use Watchtower\Connector\Model\RateSignal\TrendAdjustmentEvaluator;
use Watchtower\Connector\Model\Rollup\HourlyCountSample;
use Watchtower\Connector\Model\Rollup\RollupRepository;

/**
 * The ensemble combiner itself, isolated from Check A's own
 * classification formula (covered by DispersionEvaluatorTest.php) and
 * from Check B/C's own adjusted-
 * expected-value math (covered by SeasonalIndexEvaluatorTest.php /
 * TrendAdjustmentEvaluatorTest.php). Check B and Check C are mocked
 * directly here -- their adjustedExpectedValue() return controls exactly
 * what each check "votes" via the SAME classify() formula Check A itself
 * uses, so these tests can pin every combining scenario precisely without
 * needing to reverse-engineer RollupRepository fixtures that happen to
 * produce a specific seasonal/trend outcome indirectly.
 *
 * Check A's own bucket is held constant across every test here (median
 * 100.5, MAD 1.0, observedCount 100 -> Check A always votes Normal
 * itself) so only the COMBINING logic varies between scenarios, not
 * Check A's own formula.
 */
class EnsembleCombinerTest extends TestCase
{
    private const NOW_STRING = '2026-08-13T15:00:00+00:00';
    private const STORE_VIEW_ID = 7;
    private const STORE_VIEW_CODE = 'default';
    private const CATEGORY = 'checkout';
    private const OBSERVED_COUNT = 100;

    /** Check A's own bucket: median 100.5, MAD 1.0 -- observedCount 100 votes Normal (z = -0.337). */
    private const CHECK_A_SAMPLES_BY_WEEKS_AGO = [4 => 100, 3 => 102, 2 => 98, 1 => 101];

    public function testOnlyCheckAActiveStandsAlone(): void
    {
        $status = $this->ensembleStatus(seasonalVote: null, trendVote: null);

        self::assertSame(SignalStatus::Normal, $status);
    }

    public function testTwoActiveChecksThatAgreeFormAMajority(): void
    {
        // Check A votes Normal (fixed); Check B independently also votes
        // Normal (expected == observed, z = 0); Check C abstains. 2-of-2
        // agreement, not the trivial "only Check A" path.
        $status = $this->ensembleStatus(seasonalVote: SignalStatus::Normal, trendVote: null);

        self::assertSame(SignalStatus::Normal, $status);
    }

    public function testTwoActiveChecksThatDisagreeWithNoThirdVoteDefersToCheckA(): void
    {
        // Check A votes Normal; Check B votes SevereDrop; Check C abstains.
        // A 1-1 split with only 2 active checks has no majority -> defers
        // to Check A, never to B alone.
        $status = $this->ensembleStatus(seasonalVote: SignalStatus::SevereDrop, trendVote: null);

        self::assertSame(SignalStatus::Normal, $status);
    }

    public function testThreeActiveChecksWithATwoOfThreeMajorityOverridesCheckAsOwnRawVerdict(): void
    {
        // Check A votes Normal; Check B and Check C both independently
        // vote SevereDrop. 2 of 3 is a strict majority, so the ensemble's
        // FINAL verdict is SevereDrop, genuinely overriding Check A's own
        // raw classification.
        $status = $this->ensembleStatus(seasonalVote: SignalStatus::SevereDrop, trendVote: SignalStatus::SevereDrop);

        self::assertSame(SignalStatus::SevereDrop, $status);
    }

    public function testThreeActiveChecksAllDisagreeingHasNoMajorityAndDefersToCheckA(): void
    {
        // Check A votes Normal; Check B votes SevereDrop; Check C votes
        // MildSpike. Three genuinely distinct votes, no pair agrees -> no
        // majority -> defers to Check A.
        $status = $this->ensembleStatus(seasonalVote: SignalStatus::SevereDrop, trendVote: SignalStatus::MildSpike);

        self::assertSame(SignalStatus::Normal, $status);
    }

    /**
     * The ensemble applies to the standard per-hour-count evaluation path
     * only; low-volume signal mode is not folded into it. Below
     * VOLUME_FLOOR, ensembleClassify() must never even be reached, so
     * Check B/Check C
     * must never be consulted at all -- proven directly via mock
     * expectations, not inferred from the outcome.
     */
    public function testTheShortHistoryLowVolumeFallthroughNeverConsultsCheckBOrCheckC(): void
    {
        // Empty Check A history -> short-history fallthrough to the
        // inter-arrival path.
        $this->assertLowVolumePathNeverConsultsCheckBOrC([]);
    }

    /**
     * The OTHER low-volume trigger, distinct from short history: a
     * genuinely sufficient (>= MIN_HISTORICAL_SAMPLES) Check A bucket
     * whose OWN median is below VOLUME_FLOOR. Distinct from the
     * short-history fallthrough: both routes bypass ensembleClassify(),
     * but only this one actually reaches rawStatus()'s own
     * $median >= VOLUME_FLOOR check and falls through it, rather than
     * never reaching the check at all.
     */
    public function testABelowFloorMedianLowVolumeSignalNeverConsultsCheckBOrCheckC(): void
    {
        $now = new \DateTimeImmutable(self::NOW_STRING);
        $samples = [
            new HourlyCountSample($now->modify('-4 weeks'), 2),
            new HourlyCountSample($now->modify('-3 weeks'), 2),
            new HourlyCountSample($now->modify('-2 weeks'), 3),
            new HourlyCountSample($now->modify('-1 weeks'), 3),
        ];

        $this->assertLowVolumePathNeverConsultsCheckBOrC($samples);
    }

    /**
     * @param HourlyCountSample[] $checkASamples
     */
    private function assertLowVolumePathNeverConsultsCheckBOrC(array $checkASamples): void
    {
        $now = new \DateTimeImmutable(self::NOW_STRING);

        $rollupRepository = $this->createStub(RollupRepository::class);
        $rollupRepository->method('hourlyCountsForBucket')->willReturn($checkASamples);
        $rollupRepository->method('allHourlyCountsInWindow')->willReturn([]);

        $seasonalIndexEvaluator = $this->createMock(SeasonalIndexEvaluator::class);
        $seasonalIndexEvaluator->expects(self::never())->method('adjustedExpectedValue');

        $trendAdjustmentEvaluator = $this->createMock(TrendAdjustmentEvaluator::class);
        $trendAdjustmentEvaluator->expects(self::never())->method('adjustedExpectedValue');

        $state = new DispersionState(
            storeViewId: self::STORE_VIEW_ID,
            category: self::CATEGORY,
            pendingStatus: null,
            confirmedStatus: SignalStatus::InsufficientData,
            sequenceNumber: 3,
        );
        $stateRepository = $this->createStub(DispersionStateRepository::class);
        $stateRepository->method('get')->willReturn($state);

        (new DispersionEvaluator(
            $rollupRepository,
            $stateRepository,
            null,
            $seasonalIndexEvaluator,
            $trendAdjustmentEvaluator
        ))->evaluate(self::STORE_VIEW_ID, self::STORE_VIEW_CODE, self::CATEGORY, 0, $now, $now);
    }

    /**
     * Runs one ensemble tick with Check A's own bucket fixed (see class
     * docblock) and Check B/Check C mocked to vote exactly $seasonalVote /
     * $trendVote (null = abstain), via an adjusted expected value chosen
     * to classify to that exact status against the SAME mad/observedCount
     * Check A itself uses.
     */
    private function ensembleStatus(?SignalStatus $seasonalVote, ?SignalStatus $trendVote): SignalStatus
    {
        $now = new \DateTimeImmutable(self::NOW_STRING);

        $samples = [];
        foreach (self::CHECK_A_SAMPLES_BY_WEEKS_AGO as $weeksAgo => $count) {
            $samples[] = new HourlyCountSample($now->modify("-{$weeksAgo} weeks"), $count);
        }

        $rollupRepository = $this->createStub(RollupRepository::class);
        $rollupRepository->method('hourlyCountsForBucket')->willReturn($samples);

        $seasonalIndexEvaluator = $this->createStub(SeasonalIndexEvaluator::class);
        $seasonalIndexEvaluator->method('adjustedExpectedValue')
            ->willReturn($this->expectedValueVotingFor($seasonalVote));

        $trendAdjustmentEvaluator = $this->createStub(TrendAdjustmentEvaluator::class);
        $trendAdjustmentEvaluator->method('adjustedExpectedValue')
            ->willReturn($this->expectedValueVotingFor($trendVote));

        // confirmedStatus pinned to Normal (Check A's own known raw vote)
        // so a run where the ensemble does NOT override anything still
        // isolates the ensemble's classification via the "no change"
        // branch, the same isolation technique DispersionEvaluatorTest.php
        // establishes; a run where the ensemble DOES override lands on
        // the "first differing tick" heartbeat-of-old-status branch
        // instead, which still reports rawStatus() via its own report()
        // call -- see the assertion below, which reads the CONFIRMED
        // status only when it changed, and the debounce's own pending
        // value otherwise via a second tick.
        $state = new DispersionState(
            storeViewId: self::STORE_VIEW_ID,
            category: self::CATEGORY,
            pendingStatus: null,
            confirmedStatus: SignalStatus::Normal,
            sequenceNumber: 3,
        );

        $stateRepository = $this->createMock(DispersionStateRepository::class);
        $stateRepository->method('get')->willReturn($state);

        $savedState = null;
        $stateRepository->expects(self::once())->method('save')->willReturnCallback(
            function (DispersionState $saved) use (&$savedState): void {
                $savedState = $saved;
            }
        );

        (new DispersionEvaluator(
            $rollupRepository,
            $stateRepository,
            null,
            $seasonalIndexEvaluator,
            $trendAdjustmentEvaluator
        ))->evaluate(self::STORE_VIEW_ID, self::STORE_VIEW_CODE, self::CATEGORY, self::OBSERVED_COUNT, $now, $now);

        // The debounce state machine only reports a raw status directly
        // once it's been confirmed (Normal, matching the pinned confirmed
        // state -- rawStatus === confirmed, "no change") or after a
        // second consecutive differing tick. A single tick's raw
        // classification is exactly what this test wants to isolate, and
        // it is recoverable either way: if raw === confirmed, the saved
        // state's own confirmedStatus IS the raw value (unchanged); if
        // raw differs, the saved state's pendingStatus holds the raw
        // value directly (first differing tick).
        return $savedState->pendingStatus ?? $savedState->confirmedStatus;
    }

    /**
     * Picks an adjusted expected value that classifies to exactly $vote
     * against Check A's own fixed MAD of 1.0 and OBSERVED_COUNT of 100 --
     * or null (abstain) when $vote itself is null.
     */
    private function expectedValueVotingFor(?SignalStatus $vote): ?float
    {
        return match ($vote) {
            null => null,
            SignalStatus::Normal => (float) self::OBSERVED_COUNT,
            SignalStatus::MildDrop => self::OBSERVED_COUNT + 6.0,
            SignalStatus::SevereDrop => self::OBSERVED_COUNT + 20.0,
            SignalStatus::MildSpike => self::OBSERVED_COUNT - 6.0,
            SignalStatus::SevereSpike => self::OBSERVED_COUNT - 20.0,
            default => throw new \InvalidArgumentException("Unsupported vote for this fixture: {$vote->value}"),
        };
    }
}
