<?php
/**
 * Copyright © 2026 Watchtower. All rights reserved.
 * Licensed under the Business Source License 1.1 (BUSL-1.1). See LICENSE.
 */

declare(strict_types=1);

namespace Watchtower\Connector\Test\Unit\Model\Diagnostics;

use PHPUnit\Framework\TestCase;
use Watchtower\Connector\Model\Api\MagentoEolInfo;
use Watchtower\Connector\Model\Api\SignalStatus;
use Watchtower\Connector\Model\Diagnostics\DiagnosticsSnapshot;
use Watchtower\Connector\Model\Diagnostics\DiagnosticsSnapshotProvider;
use Watchtower\Connector\Model\Diagnostics\InsufficientDataLabel;
use Watchtower\Connector\Model\Diagnostics\PlainLanguage;
use Watchtower\Connector\Model\Diagnostics\PlainStatus;
use Watchtower\Connector\Model\Diagnostics\SignalSnapshot;
use Watchtower\Connector\Model\Diagnostics\StoreViewSnapshot;
use Watchtower\Connector\Model\Environment\ConnectorVersionState;
use Watchtower\Connector\Model\Environment\EnvironmentState;
use Watchtower\Connector\Model\Seed\SeedCoverageResult;
use Watchtower\Connector\Model\Seed\SeedCoverageStatus;

class PlainLanguageTest extends TestCase
{
    private const NOW = '2026-10-11 12:00:00';

    public function testANormalShopSaysEverythingLooksNormal(): void
    {
        $overall = $this->plain()->overall($this->snapshot(), $this->now());

        self::assertSame('Everything looks normal', $overall->label);
        self::assertSame(PlainStatus::GOOD, $overall->tone);
    }

    public function testAnUnreachableShopIsTheHeadlineAndTellsTheOwnerWhatToDo(): void
    {
        $overall = $this->plain()->overall($this->snapshot(reachable: false), $this->now());

        self::assertSame('Watchtower cannot be reached', $overall->label);
        self::assertSame(PlainStatus::BAD, $overall->tone);
        self::assertStringContainsString('internet', $overall->detail);
    }

    public function testAnUnconfiguredShopIsToldToAddItsKeyNotToCheckTheInternet(): void
    {
        $snapshot = $this->snapshot(reachable: false, unreachableError: DiagnosticsSnapshotProvider::NOT_CONFIGURED_ERROR);

        $overall = $this->plain()->overall($snapshot, $this->now());

        self::assertSame('Watchtower is not set up yet', $overall->label);
        self::assertStringContainsString('Stores > Configuration > Watchtower', $overall->detail);
    }

    public function testEachBlockingProblemOutranksAnAnomalyInTheHeadline(): void
    {
        $anomaly = [$this->storeView([$this->signal('checkout', SignalStatus::SevereDrop)])];

        $cases = [
            'The connection key was not accepted' => $this->snapshot(keyValid: false, storeViews: $anomaly),
            'Your Watchtower account is paused' => $this->snapshot(paused: true, storeViews: $anomaly),
            'This connector version is too old and has stopped reporting'
                => $this->snapshot(belowMinimum: true, storeViews: $anomaly),
        ];

        foreach ($cases as $expected => $snapshot) {
            self::assertSame($expected, $this->plain()->overall($snapshot, $this->now())->label);
        }
    }

    public function testNoReportForHoursPointsAtCron(): void
    {
        $stale = new \DateTimeImmutable('2026-10-11 07:00:00');
        $overall = $this->plain()->overall($this->snapshot(lastSubmission: $stale), $this->now());

        self::assertSame('No report has reached Watchtower for a while', $overall->label);
        self::assertSame(PlainStatus::WARN, $overall->tone);
        self::assertStringContainsString('5 hours ago', $overall->detail);
        self::assertStringContainsString('cron', $overall->detail);
    }

    public function testNoReportYetIsCalmNotAnError(): void
    {
        $overall = $this->plain()->overall($this->snapshot(lastSubmission: null), $this->now());

        self::assertSame('Waiting for the first report', $overall->label);
        self::assertSame(PlainStatus::INFO, $overall->tone);
    }

    public function testAnAnomalyIsCountedAndNamedInThePluralCorrectly(): void
    {
        $one = $this->snapshot(storeViews: [$this->storeView([$this->signal('checkout', SignalStatus::MildDrop)])]);
        $two = $this->snapshot(storeViews: [$this->storeView([
            $this->signal('checkout', SignalStatus::MildDrop),
            $this->signal('basket_quote', SignalStatus::SevereSpike),
        ])]);

        self::assertSame('One thing needs your attention', $this->plain()->overall($one, $this->now())->label);
        self::assertSame('2 things need your attention', $this->plain()->overall($two, $this->now())->label);
    }

    public function testRateSignalsReadAsFewerOrMoreThanUsualWithSeverity(): void
    {
        $expected = [
            [SignalStatus::MildDrop, 'Fewer orders than usual'],
            [SignalStatus::SevereDrop, 'Far fewer orders than usual'],
            [SignalStatus::MildSpike, 'More orders than usual'],
            [SignalStatus::SevereSpike, 'Far more orders than usual'],
        ];

        foreach ($expected as [$status, $sentence]) {
            $state = $this->plain()->signal($this->signal('checkout', $status));

            self::assertSame('Needs attention', $state->label);
            self::assertStringStartsWith($sentence, $state->detail);
        }
    }

    public function testANonRateSignalOnlyEverSaysItIsNotRunningAsExpected(): void
    {
        $state = $this->plain()->signal($this->signal('cron_health', SignalStatus::SevereDrop));

        self::assertSame('Needs attention', $state->label);
        self::assertSame('This is not running as expected.', $state->detail);
    }

    public function testTooQuietAndStillLearningAreDistinctAndNeitherNeedsAttention(): void
    {
        $learning = $this->signal('checkout', SignalStatus::InsufficientData, sequence: 10);
        $quiet = $this->signal('checkout', SignalStatus::InsufficientData, sequence: 28 * 24, seeded: true);

        self::assertSame('Still learning', $this->plain()->signal($learning)->label);
        self::assertSame('Too quiet to judge', $this->plain()->signal($quiet)->label);
        self::assertFalse($this->plain()->needsAttention($learning));
        self::assertFalse($this->plain()->needsAttention($quiet));
        self::assertTrue($this->plain()->isLearning($learning));
        self::assertFalse($this->plain()->isLearning($quiet));
    }

    public function testLearningShowsACalmHeadlineWhenNothingIsWrong(): void
    {
        $snapshot = $this->snapshot(storeViews: [$this->storeView([
            $this->signal('checkout', SignalStatus::InsufficientData, sequence: 10),
        ])]);

        $overall = $this->plain()->overall($snapshot, $this->now());

        self::assertSame('Watchtower is connected and still learning', $overall->label);
        self::assertSame(PlainStatus::INFO, $overall->tone);
    }

    public function testNoticesCoverUpdatesEndOfLifeMagentoAndWaitingReports(): void
    {
        $snapshot = $this->snapshot(updateAvailable: true, eol: true, buffered: 1);

        $labels = array_map(static fn (PlainStatus $notice): string => $notice->label, $this->plain()->notices($snapshot));

        self::assertCount(3, $labels);
        self::assertStringContainsString('you have 1.32.1, the latest is 1.33.0', $labels[0]);
        self::assertStringContainsString('stopped being supported on 2025-06-11', $labels[1]);
        self::assertSame('1 report is waiting to be sent.', $labels[2]);
    }

    public function testNoNoticesWhenNothingIsWorthMentioning(): void
    {
        self::assertSame([], $this->plain()->notices($this->snapshot()));
    }

    public function testNoUserVisibleWordingLeaksEngineeringVocabulary(): void
    {
        $banned = ['dispersion', 'seasonal', 'trend', 'sustained', 'baseline', 'MAD', 'z-score', 'ruleset',
            'low-volume', 'seed', 'Poisson', 'sequence', 'debounce', 'heartbeat'];

        $texts = [];

        foreach (SignalStatus::cases() as $status) {
            foreach (['checkout', 'cron_health', 'integration_health'] as $category) {
                foreach ([10, 28 * 24] as $sequence) {
                    $state = $this->plain()->signal($this->signal($category, $status, sequence: $sequence));
                    $texts[] = $state->label . ' ' . $state->detail;
                }
            }
        }

        $texts[] = $this->plain()->overall($this->snapshot(lastSubmission: null), $this->now())->detail;
        $texts[] = $this->plain()->overall($this->snapshot(), $this->now())->detail;

        foreach ($texts as $text) {
            foreach ($banned as $word) {
                self::assertStringNotContainsStringIgnoringCase($word, $text);
            }

            self::assertStringNotContainsString("\u{2014}", $text);
        }
    }

    public function testAgoWording(): void
    {
        $now = $this->now();

        self::assertSame('Not yet', $this->plain()->ago(null, $now));
        self::assertSame('Just now', $this->plain()->ago($now->modify('-90 seconds'), $now));
        self::assertSame('12 minutes ago', $this->plain()->ago($now->modify('-12 minutes'), $now));
        self::assertSame('5 hours ago', $this->plain()->ago($now->modify('-5 hours'), $now));
        self::assertSame('3 days ago', $this->plain()->ago($now->modify('-3 days'), $now));
    }

    private function plain(): PlainLanguage
    {
        return new PlainLanguage(new InsufficientDataLabel());
    }

    private function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable(self::NOW);
    }

    private function signal(string $category, ?SignalStatus $status, int $sequence = 100, bool $seeded = false): SignalSnapshot
    {
        return new SignalSnapshot(
            category: $category,
            status: $status,
            sequenceNumber: $sequence,
            seedCoverage: $seeded ? new SeedCoverageResult($category, 28, 28, SeedCoverageStatus::Seeded) : null,
        );
    }

    /**
     * @param SignalSnapshot[] $signals
     */
    private function storeView(array $signals): StoreViewSnapshot
    {
        return new StoreViewSnapshot(1, 'default', $signals);
    }

    /**
     * @param StoreViewSnapshot[]|null $storeViews
     */
    private function snapshot(
        bool $reachable = true,
        string $unreachableError = 'timeout',
        ?bool $keyValid = true,
        bool $paused = false,
        bool $belowMinimum = false,
        bool $updateAvailable = false,
        bool $eol = false,
        int $buffered = 0,
        ?\DateTimeImmutable $lastSubmission = new \DateTimeImmutable('2026-10-11 11:30:00'),
        ?array $storeViews = null,
    ): DiagnosticsSnapshot {
        return new DiagnosticsSnapshot(
            reachable: $reachable,
            unreachableError: $reachable ? null : $unreachableError,
            keyValid: $reachable ? $keyValid : null,
            organizationPaused: $reachable ? $paused : null,
            lastSuccessfulSubmissionAt: $lastSubmission,
            bufferedReportCount: $buffered,
            droppedEventCountLast24Hours: 0,
            cronHealth: $this->signal('cron_health', SignalStatus::Normal),
            storeViews: $storeViews ?? [$this->storeView([$this->signal('checkout', SignalStatus::Normal)])],
            recentSubmissionOutcomes: [],
            environment: new EnvironmentState(
                '2.4.8',
                'Community',
                '1.32.1',
                new MagentoEolInfo(isEol: $eol, eolDate: '2025-06-11', statusLabel: $eol ? 'eol' : 'supported'),
                new \DateTimeImmutable('2026-10-11 09:00:00'),
            ),
            connectorVersion: new ConnectorVersionState(
                '1.32.1',
                '1.0.0',
                '1.33.0',
                $belowMinimum,
                $updateAvailable,
                new \DateTimeImmutable('2026-10-11 09:00:00'),
            ),
        );
    }
}
