<?php
/**
 * Copyright © 2026 Watchtower. All rights reserved.
 * Licensed under the Business Source License 1.1 (BUSL-1.1). See LICENSE.
 */

declare(strict_types=1);

namespace Watchtower\Connector\Test\Unit\Model\IntegrationHealth;

use PHPUnit\Framework\TestCase;
use Watchtower\Connector\Model\Api\MetricReport;
use Watchtower\Connector\Model\Api\ReportReason;
use Watchtower\Connector\Model\Api\SignalStatus;
use Watchtower\Connector\Model\CronJobObservation\CadenceEstimator;
use Watchtower\Connector\Model\CronJobObservation\JobRunObservation;
use Watchtower\Connector\Model\CronJobObservation\JobRunObservationRepository;
use Watchtower\Connector\Model\IntegrationHealth\CronJobObserver;
use Watchtower\Connector\Model\IntegrationHealth\IntegrationHealthState;
use Watchtower\Connector\Model\IntegrationHealth\IntegrationHealthStateRepository;
use Watchtower\Connector\Model\IntegrationHealth\Observation;
use Watchtower\Connector\Model\IntegrationHealth\WatchedSetEvaluator;

/**
 * Covers the rollup: many watched integrations collapsing to the one
 * integration_health status per store view the wire contract allows.
 */
class WatchedSetEvaluatorTest extends TestCase
{
    private const NOW = '2026-08-13T15:00:00+00:00';
    private const STORE_VIEW_ID = 1;
    private const STORE_VIEW_CODE = 'default';

    public function testAHealthySetReportsNormal(): void
    {
        $report = $this->evaluate(
            ['ebizmarts_ecommerce', 'ess_m2epro'],
            ['ebizmarts_ecommerce' => '-5 minutes', 'ess_m2epro' => '-2 minutes']
        );

        self::assertSame(SignalStatus::Normal, $report->status);
    }

    /**
     * The point of worst-of: one dead integration among several healthy ones
     * still has to reach the merchant.
     */
    public function testOneStalledJobDrivesTheWholeSetSevere(): void
    {
        $saved = null;
        $this->evaluate(
            ['ebizmarts_ecommerce', 'ess_m2epro'],
            ['ebizmarts_ecommerce' => '-5 minutes', 'ess_m2epro' => '-3 days'],
            saved: $saved
        );

        // Asserted on the save, not the report: this is the first differing
        // tick, so the debounce still reports the confirmed status and only
        // records the new raw one as pending.
        self::assertSame(SignalStatus::SevereDrop, $saved->pendingStatus);
    }

    /**
     * The observation table records successes only, so without consulting
     * cron_schedule for failures a job that runs and fails every time would
     * be judged healthy on cadence alone.
     */
    public function testAJobFailingMoreRecentlyThanItSucceededIsMild(): void
    {
        $saved = null;
        $this->evaluate(
            ['ebizmarts_ecommerce'],
            ['ebizmarts_ecommerce' => '-3 days'],
            failures: ['ebizmarts_ecommerce' => '-1 minute'],
            saved: $saved
        );

        self::assertSame(SignalStatus::MildDrop, $saved->pendingStatus);
    }

    /**
     * A set where one job is still learning and another is demonstrably fine
     * is not "unknown", it is fine so far. Downgrading it would hide a real
     * recovery behind a job that merely happens to be new.
     */
    public function testAStillLearningJobDoesNotDowngradeAHealthySibling(): void
    {
        $report = $this->evaluate(
            ['ebizmarts_ecommerce', 'brand_new_job'],
            ['ebizmarts_ecommerce' => '-5 minutes'],
            learning: ['brand_new_job']
        );

        self::assertSame(SignalStatus::Normal, $report->status);
    }

    public function testASetWhereNothingHasBeenMeasuredYetIsInsufficientData(): void
    {
        $saved = null;
        $this->evaluate(['brand_new_job'], [], learning: ['brand_new_job'], saved: $saved);

        self::assertSame(SignalStatus::InsufficientData, $saved->pendingStatus);
    }

    /**
     * A stored verdict describes the jobs it was computed from. Editing the
     * selection must re-seed rather than carry that verdict onto a different
     * set, and it reports a Heartbeat because a merchant changing their own
     * selection has nothing to be alerted about.
     */
    public function testChangingTheWatchedSetReseedsWithoutAlerting(): void
    {
        $report = $this->evaluate(
            ['ebizmarts_ecommerce', 'newly_added_job'],
            ['ebizmarts_ecommerce' => '-5 minutes'],
            learning: ['newly_added_job'],
            stateFingerprintFor: ['ebizmarts_ecommerce']
        );

        self::assertSame(SignalStatus::InsufficientData, $report->status);
        self::assertSame(ReportReason::Heartbeat, $report->reason);
    }

    public function testTheSetIdentityIgnoresOrdering(): void
    {
        // Same two jobs, stored in the other order: not a change, so it must
        // not re-seed and must evaluate normally.
        $report = $this->evaluate(
            ['ess_m2epro', 'ebizmarts_ecommerce'],
            ['ebizmarts_ecommerce' => '-5 minutes', 'ess_m2epro' => '-2 minutes'],
            stateFingerprintFor: ['ebizmarts_ecommerce', 'ess_m2epro']
        );

        self::assertSame(SignalStatus::Normal, $report->status);
    }

    /**
     * The alert cannot name the failing integration, because only a rolled-up
     * status crosses the wire. This is how watchtower:status closes that gap
     * locally.
     */
    public function testUnhealthyJobCodesNamesOnlyTheFailingJobs(): void
    {
        $evaluator = $this->evaluatorFor(
            ['ebizmarts_ecommerce' => '-5 minutes', 'ess_m2epro' => '-3 days'],
            [],
            []
        );

        self::assertSame(
            ['ess_m2epro'],
            $evaluator->unhealthyJobCodes(['ebizmarts_ecommerce', 'ess_m2epro'], $this->now())
        );
    }

    /**
     * Runs one tick over a watched set.
     *
     * @param string[] $jobCodes
     * @param array<string,string> $successes job code => relative time of last success
     * @param array<string,string> $failures job code => relative time of last failure
     * @param string[] $learning job codes with too little history to judge
     * @param string[]|null $stateFingerprintFor the set the stored state describes, defaults to $jobCodes
     * @param IntegrationHealthState|null $saved receives the state this tick persisted
     * @return MetricReport
     */
    private function evaluate(
        array $jobCodes,
        array $successes,
        array $failures = [],
        array $learning = [],
        ?array $stateFingerprintFor = null,
        ?IntegrationHealthState &$saved = null
    ): MetricReport {
        $evaluator = $this->evaluatorFor(
            $successes,
            $failures,
            $learning,
            $stateFingerprintFor ?? $jobCodes,
            $saved
        );

        return $evaluator->evaluate(self::STORE_VIEW_ID, self::STORE_VIEW_CODE, $jobCodes, $this->now());
    }

    /**
     * Builds an evaluator over a fixed set of measured jobs.
     *
     * @param array<string,string> $successes
     * @param array<string,string> $failures
     * @param string[] $learning
     * @param string[] $stateFingerprintFor
     * @return WatchedSetEvaluator
     */
    private function evaluatorFor(
        array $successes,
        array $failures,
        array $learning,
        array $stateFingerprintFor = [],
        ?IntegrationHealthState &$saved = null
    ): WatchedSetEvaluator {
        $observationRepository = $this->createStub(JobRunObservationRepository::class);
        $observationRepository->method('get')->willReturnCallback(
            function (string $jobCode) use ($successes, $learning): ?JobRunObservation {
                if (in_array($jobCode, $learning, true)) {
                    // Seen once, never enough gaps to establish a cadence.
                    return new JobRunObservation(
                        jobCode: $jobCode,
                        firstObservedAt: $this->now(),
                        lastSuccessAt: $this->now(),
                        observedRunCount: 1,
                        gapSamples: [],
                    );
                }

                if (!isset($successes[$jobCode])) {
                    return null;
                }

                return new JobRunObservation(
                    jobCode: $jobCode,
                    firstObservedAt: $this->now()->modify('-30 days'),
                    lastSuccessAt: $this->now()->modify($successes[$jobCode]),
                    observedRunCount: 9,
                    gapSamples: array_fill(0, 8, 300),
                );
            }
        );

        $cronJobObserver = $this->createStub(CronJobObserver::class);
        $cronJobObserver->method('observe')->willReturnCallback(
            fn (string $jobCode): Observation => new Observation(
                latestSuccessAt: null,
                latestFailureAt: isset($failures[$jobCode]) ? $this->now()->modify($failures[$jobCode]) : null,
            )
        );

        $stateRepository = $this->createStub(IntegrationHealthStateRepository::class);
        $stateRepository->method('save')->willReturnCallback(
            function (IntegrationHealthState $state) use (&$saved): void {
                $saved = $state;
            }
        );
        $stateRepository->method('get')->willReturn(new IntegrationHealthState(
            storeViewId: self::STORE_VIEW_ID,
            lastSuccessAt: null,
            lastFailureAt: null,
            pendingStatus: null,
            // Already confirmed, so the debounce reports a steady state rather
            // than a first-evaluation seed.
            confirmedStatus: SignalStatus::Normal,
            sequenceNumber: 5,
            lastReportedReason: ReportReason::Heartbeat,
            sourceType: WatchedSetEvaluator::SOURCE_TYPE,
            sourceIdentifier: $this->fingerprint($stateFingerprintFor),
            observingSince: null,
        ));

        return new WatchedSetEvaluator(
            $stateRepository,
            $observationRepository,
            new CadenceEstimator(),
            $cronJobObserver
        );
    }

    /**
     * Mirrors the evaluator's own set identity.
     *
     * @param string[] $jobCodes
     * @return string
     */
    private function fingerprint(array $jobCodes): string
    {
        $sorted = array_values(array_unique($jobCodes));
        sort($sorted, SORT_STRING);

        return sha1(implode("\n", $sorted));
    }

    /**
     * @return \DateTimeImmutable
     */
    private function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable(self::NOW);
    }
}
