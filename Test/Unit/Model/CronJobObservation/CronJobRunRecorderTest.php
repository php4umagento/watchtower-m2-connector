<?php
/**
 * Copyright © 2026 Watchtower. All rights reserved.
 * Licensed under the Business Source License 1.1 (BUSL-1.1). See LICENSE.
 */

declare(strict_types=1);

namespace Watchtower\Connector\Test\Unit\Model\CronJobObservation;

use Magento\Cron\Model\Schedule;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use PHPUnit\Framework\TestCase;
use Watchtower\Connector\Model\Config;
use Watchtower\Connector\Model\CronJobObservation\CronJobRunRecorder;
use Watchtower\Connector\Model\CronJobObservation\JobRunObservation;
use Watchtower\Connector\Model\CronJobObservation\JobRunObservationRepository;

/**
 * Follows CronJobObserverTest's pattern for asserting Select where() calls.
 *
 * The per-minute test below is the load-bearing one: it locks the reason
 * this recorder walks every success in the window rather than taking the
 * freshest, which is the difference between measuring a job's own period and
 * measuring the cron tick period.
 */
class CronJobRunRecorderTest extends TestCase
{
    private const NOW_STRING = '2026-08-13T15:00:00+00:00';

    /**
     * A job running once a minute produces five successes between two
     * five-minute ticks. Recording only the newest would measure its gap as
     * 300 seconds, the tick period, and every threshold derived from that
     * would be five times too loose.
     */
    public function testMeasuresAPerMinuteJobAsPerMinuteNotAsTheTickPeriod(): void
    {
        $saved = $this->record([
            $this->row('ess_m2epro', '2026-08-13 14:55:00'),
            $this->row('ess_m2epro', '2026-08-13 14:56:00'),
            $this->row('ess_m2epro', '2026-08-13 14:57:00'),
            $this->row('ess_m2epro', '2026-08-13 14:58:00'),
            $this->row('ess_m2epro', '2026-08-13 14:59:00'),
        ]);

        self::assertCount(1, $saved);
        self::assertSame([60, 60, 60, 60], $saved[0]->gapSamples);
        self::assertSame(5, $saved[0]->observedRunCount);
    }

    public function testCarriesForwardAndSkipsSuccessesAlreadyRecorded(): void
    {
        $existing = [
            'ess_m2epro' => $this->observation(
                lastSuccessAt: '2026-08-13 14:57:00',
                gaps: [60, 60],
                runCount: 3,
            ),
        ];

        // The lookback window deliberately overlaps earlier ticks, so the
        // first three rows here have already been folded in.
        $saved = $this->record([
            $this->row('ess_m2epro', '2026-08-13 14:55:00'),
            $this->row('ess_m2epro', '2026-08-13 14:56:00'),
            $this->row('ess_m2epro', '2026-08-13 14:57:00'),
            $this->row('ess_m2epro', '2026-08-13 14:58:00'),
            $this->row('ess_m2epro', '2026-08-13 14:59:00'),
        ], $existing);

        self::assertSame([60, 60, 60, 60], $saved[0]->gapSamples);
        self::assertSame(5, $saved[0]->observedRunCount);
        self::assertEquals(new \DateTimeImmutable('2026-08-13T14:59:00+00:00'), $saved[0]->lastSuccessAt);
    }

    public function testWritesNothingWhenEverySuccessWasAlreadyRecorded(): void
    {
        $existing = [
            'ess_m2epro' => $this->observation(
                lastSuccessAt: '2026-08-13 14:59:00',
                gaps: [60, 60],
                runCount: 3,
            ),
        ];

        $saved = $this->record([
            $this->row('ess_m2epro', '2026-08-13 14:58:00'),
            $this->row('ess_m2epro', '2026-08-13 14:59:00'),
        ], $existing);

        self::assertSame([], $saved);
    }

    /**
     * On a job's first sighting the window usually already holds several past
     * successes. Anchoring to "now" instead would understate how long the job
     * has really been watched and hold the signal in its learning state
     * longer than the evidence warrants.
     */
    public function testFirstSightingAnchorsFirstObservedAtToTheEarliestSuccessInTheWindow(): void
    {
        $saved = $this->record([
            $this->row('ebizmarts_ecommerce', '2026-08-13 13:00:00'),
            $this->row('ebizmarts_ecommerce', '2026-08-13 14:00:00'),
        ]);

        self::assertEquals(new \DateTimeImmutable('2026-08-13T13:00:00+00:00'), $saved[0]->firstObservedAt);
    }

    public function testTwoSuccessesInTheSameSecondDoNotContributeAZeroGap(): void
    {
        $saved = $this->record([
            $this->row('avalon_conditions', '2026-08-13 14:00:00'),
            $this->row('avalon_conditions', '2026-08-13 14:00:00'),
            $this->row('avalon_conditions', '2026-08-13 14:15:00'),
        ]);

        self::assertSame([900], $saved[0]->gapSamples);
    }

    public function testGroupsSuccessesByJobCode(): void
    {
        $saved = $this->record([
            $this->row('ebizmarts_ecommerce', '2026-08-13 14:50:00'),
            $this->row('ebizmarts_ecommerce', '2026-08-13 14:55:00'),
            $this->row('ess_m2epro', '2026-08-13 14:58:00'),
            $this->row('ess_m2epro', '2026-08-13 14:59:00'),
        ]);

        $byCode = [];

        foreach ($saved as $observation) {
            $byCode[$observation->jobCode] = $observation->gapSamples;
        }

        self::assertSame([300], $byCode['ebizmarts_ecommerce']);
        self::assertSame([60], $byCode['ess_m2epro']);
    }

    public function testRetainedGapSamplesAreCappedOldestFirst(): void
    {
        $existing = [
            'ess_m2epro' => $this->observation(
                lastSuccessAt: '2026-08-13 14:57:00',
                gaps: range(1, CronJobRunRecorder::MAX_GAP_SAMPLES),
                runCount: CronJobRunRecorder::MAX_GAP_SAMPLES + 1,
            ),
        ];

        $saved = $this->record([
            $this->row('ess_m2epro', '2026-08-13 14:58:00'),
            $this->row('ess_m2epro', '2026-08-13 14:59:00'),
        ], $existing);

        $gaps = $saved[0]->gapSamples;

        self::assertCount(CronJobRunRecorder::MAX_GAP_SAMPLES, $gaps);
        // The two oldest were dropped to make room, so the window now starts at 3.
        self::assertSame(3, $gaps[0]);
        self::assertSame([60, 60], array_slice($gaps, -2));
    }

    public function testDoesNotReadOrWriteAnythingWhenTheModuleIsDisabled(): void
    {
        $repository = $this->createMock(JobRunObservationRepository::class);
        $repository->expects(self::never())->method('getAll');
        $repository->expects(self::never())->method('saveAll');

        $config = $this->createStub(Config::class);
        $config->method('isEnabled')->willReturn(false);

        $resourceConnection = $this->createMock(ResourceConnection::class);
        $resourceConnection->expects(self::never())->method('getConnection');

        (new CronJobRunRecorder($resourceConnection, $repository, $config))
            ->record(new \DateTimeImmutable(self::NOW_STRING));
    }

    public function testQueriesOnlySuccessesAndExcludesTheConnectorsOwnJobs(): void
    {
        $seenWhere = [];
        $select = $this->createMock(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('order')->willReturnSelf();
        $select->expects(self::exactly(3))->method('where')->willReturnCallback(
            function (string $condition, mixed $value = null) use ($select, &$seenWhere) {
                $seenWhere[] = [$condition, $value];

                return $select;
            }
        );

        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        $connection->method('fetchAll')->willReturn([]);

        $resourceConnection = $this->createStub(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnArgument(0);

        $config = $this->createStub(Config::class);
        $config->method('isEnabled')->willReturn(true);

        (new CronJobRunRecorder($resourceConnection, $this->createStub(JobRunObservationRepository::class), $config))
            ->record(new \DateTimeImmutable(self::NOW_STRING));

        self::assertSame(['status = ?', Schedule::STATUS_SUCCESS], $seenWhere[0]);
        self::assertSame('job_code NOT LIKE ?', $seenWhere[2][0]);
        self::assertSame('watchtower\_%', $seenWhere[2][1]);
    }

    /**
     * Runs the recorder over a fixed result set and returns whatever it saved.
     *
     * @param array<int,array<string,string>> $rows
     * @param array<string,JobRunObservation> $existing
     * @return JobRunObservation[]
     */
    private function record(array $rows, array $existing = []): array
    {
        $select = $this->createStub(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();
        $select->method('order')->willReturnSelf();

        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        $connection->method('fetchAll')->willReturn($rows);

        $resourceConnection = $this->createStub(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnArgument(0);

        $saved = [];
        $repository = $this->createStub(JobRunObservationRepository::class);
        $repository->method('getAll')->willReturn($existing);
        $repository->method('saveAll')->willReturnCallback(
            function (array $observations) use (&$saved): void {
                $saved = $observations;
            }
        );

        $config = $this->createStub(Config::class);
        $config->method('isEnabled')->willReturn(true);

        (new CronJobRunRecorder($resourceConnection, $repository, $config))
            ->record(new \DateTimeImmutable(self::NOW_STRING));

        return $saved;
    }

    /**
     * One cron_schedule success row as the recorder's query returns it.
     *
     * @param string $jobCode
     * @param string $finishedAt
     * @return array<string,string>
     */
    private function row(string $jobCode, string $finishedAt): array
    {
        return ['job_code' => $jobCode, 'finished_at' => $finishedAt];
    }

    /**
     * An already-recorded observation to carry into the tick under test.
     *
     * @param string $lastSuccessAt
     * @param int[] $gaps
     * @param int $runCount
     * @return JobRunObservation
     */
    private function observation(string $lastSuccessAt, array $gaps, int $runCount): JobRunObservation
    {
        return new JobRunObservation(
            jobCode: 'ess_m2epro',
            firstObservedAt: new \DateTimeImmutable('2026-08-13T10:00:00+00:00'),
            lastSuccessAt: new \DateTimeImmutable($lastSuccessAt, new \DateTimeZone('UTC')),
            observedRunCount: $runCount,
            gapSamples: $gaps,
        );
    }
}
