<?php
/**
 * Copyright © 2026 Watchtower. All rights reserved.
 * Licensed under the Business Source License 1.1 (BUSL-1.1). See LICENSE.
 */

declare(strict_types=1);

namespace Watchtower\Connector\Test\Unit\Console\Command;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Watchtower\Connector\Console\Command\StatusCommand;
use Watchtower\Connector\Model\Api\SignalStatus;
use Watchtower\Connector\Model\Config;
use Watchtower\Connector\Model\Diagnostics\DiagnosticsSnapshot;
use Watchtower\Connector\Model\Diagnostics\DiagnosticsSnapshotProvider;
use Watchtower\Connector\Model\Diagnostics\SignalSnapshot;
use Watchtower\Connector\Model\Diagnostics\StoreViewSnapshot;
use Watchtower\Connector\Model\Diagnostics\SubmissionOutcome;

/**
 * Delegates all data assembly to DiagnosticsSnapshotProvider (shared with
 * the admin diagnostics page); this file only proves the command's own
 * rendering of a given snapshot, not the assembly logic itself (see
 * DiagnosticsSnapshotProviderTest.php for that).
 */
class StatusCommandTest extends TestCase
{
    public function testNotConfiguredFails(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('isConfigured')->willReturn(false);

        $tester = new CommandTester($this->command($config));
        $tester->execute([]);

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('not configured', $tester->getDisplay());
    }

    /**
     * The whole point of this command: it must still print local
     * diagnostics even when the platform itself is unreachable, since
     * that's exactly when support runs it.
     */
    public function testStillPrintsLocalDiagnosticsWhenUnreachable(): void
    {
        $snapshot = new DiagnosticsSnapshot(
            reachable: false,
            unreachableError: 'Connection refused',
            keyValid: null,
            organizationPaused: null,
            lastSuccessfulSubmissionAt: null,
            bufferedReportCount: 3,
            droppedEventCountLast24Hours: 0,
            cronHealth: new SignalSnapshot('cron_health', null, 1),
            storeViews: [],
            recentSubmissionOutcomes: [],
        );

        $tester = new CommandTester($this->command(snapshot: $snapshot));
        $tester->execute([]);

        $display = $tester->getDisplay();
        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('Reachable: no', $display);
        self::assertStringContainsString('Connection refused', $display);
        self::assertStringContainsString('Buffered reports: 3', $display);
        self::assertStringContainsString('Last successful submission: never', $display);
    }

    public function testPrintsCronHealthAndPerStoreViewSignalStatus(): void
    {
        $snapshot = new DiagnosticsSnapshot(
            reachable: true,
            unreachableError: null,
            keyValid: true,
            organizationPaused: false,
            lastSuccessfulSubmissionAt: new \DateTimeImmutable('2026-08-14T10:00:00+00:00'),
            bufferedReportCount: 0,
            droppedEventCountLast24Hours: 0,
            cronHealth: new SignalSnapshot('cron_health', SignalStatus::Normal, 12),
            storeViews: [
                new StoreViewSnapshot(1, 'default', [
                    new SignalSnapshot('basket_quote', SignalStatus::InsufficientData, 1),
                    new SignalSnapshot('checkout', SignalStatus::InsufficientData, 1),
                    new SignalSnapshot('customer_account', SignalStatus::InsufficientData, 1),
                ]),
            ],
            recentSubmissionOutcomes: [],
        );

        $tester = new CommandTester($this->command(snapshot: $snapshot));
        $tester->execute([]);

        $display = $tester->getDisplay();
        self::assertStringContainsString('cron_health: NORMAL (sequence 12)', $display);
        self::assertStringContainsString('Store view "default" (id=1):', $display);
        self::assertStringContainsString('basket_quote: INSUFFICIENT_DATA (sequence 1)', $display);
        self::assertStringContainsString('checkout: INSUFFICIENT_DATA (sequence 1)', $display);
        self::assertStringContainsString('customer_account: INSUFFICIENT_DATA (sequence 1)', $display);
    }

    public function testPrintsNoOutcomesRecordedYetWhenNoneExist(): void
    {
        $tester = new CommandTester($this->command());
        $tester->execute([]);

        self::assertStringContainsString('Recent submission outcomes: none recorded yet.', $tester->getDisplay());
    }

    /**
     * The last N submission outcomes (accepted/rejected with reasons) are
     * fetched via DiagnosticsSnapshotProvider and must actually reach the
     * output, the same data the admin diagnostics page renders.
     */
    public function testPrintsRecentSubmissionOutcomesWithReasons(): void
    {
        $snapshot = new DiagnosticsSnapshot(
            reachable: true,
            unreachableError: null,
            keyValid: true,
            organizationPaused: false,
            lastSuccessfulSubmissionAt: null,
            bufferedReportCount: 0,
            droppedEventCountLast24Hours: 0,
            cronHealth: new SignalSnapshot('cron_health', null, 1),
            storeViews: [],
            recentSubmissionOutcomes: [
                new SubmissionOutcome(
                    succeeded: true,
                    acceptedCount: 3,
                    rejectedCount: 2,
                    rejectionReasons: ['sequence_number is out of order or already recorded' => 2],
                    errorMessage: null,
                    occurredAt: new \DateTimeImmutable('2026-08-14T10:00:00+00:00'),
                ),
                new SubmissionOutcome(
                    succeeded: false,
                    acceptedCount: 0,
                    rejectedCount: 0,
                    rejectionReasons: [],
                    errorMessage: 'Connection refused',
                    occurredAt: new \DateTimeImmutable('2026-08-14T09:00:00+00:00'),
                ),
            ],
        );

        $tester = new CommandTester($this->command(snapshot: $snapshot));
        $tester->execute([]);

        $display = $tester->getDisplay();
        self::assertStringContainsString('Recent submission outcomes (newest first):', $display);
        self::assertStringContainsString(
            '2026-08-14T10:00:00+00:00: succeeded, accepted=3, rejected=2 '
                . '(sequence_number is out of order or already recorded x2)',
            $display
        );
        self::assertStringContainsString(
            '2026-08-14T09:00:00+00:00: failed, accepted=0, rejected=0 -- Connection refused',
            $display
        );
    }

    private function command(?Config $config = null, ?DiagnosticsSnapshot $snapshot = null): StatusCommand
    {
        if ($config === null) {
            $config = $this->createStub(Config::class);
            $config->method('isConfigured')->willReturn(true);
        }

        if ($snapshot === null) {
            $snapshot = new DiagnosticsSnapshot(
                reachable: true,
                unreachableError: null,
                keyValid: true,
                organizationPaused: false,
                lastSuccessfulSubmissionAt: null,
                bufferedReportCount: 0,
                droppedEventCountLast24Hours: 0,
                cronHealth: new SignalSnapshot('cron_health', null, 1),
                storeViews: [],
                recentSubmissionOutcomes: [],
            );
        }

        $provider = $this->createStub(DiagnosticsSnapshotProvider::class);
        $provider->method('snapshot')->willReturn($snapshot);

        return new StatusCommand($config, $provider);
    }
}
