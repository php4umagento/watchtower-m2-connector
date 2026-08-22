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
use Watchtower\Connector\Model\Api\ReportReason;
use Watchtower\Connector\Model\Api\SignalStatus;
use Watchtower\Connector\Model\Config;
use Watchtower\Connector\Model\Api\MagentoEolInfo;
use Watchtower\Connector\Model\Diagnostics\DiagnosticsSnapshot;
use Watchtower\Connector\Model\Diagnostics\DiagnosticsSnapshotProvider;
use Watchtower\Connector\Model\Diagnostics\SignalSnapshot;
use Watchtower\Connector\Model\Diagnostics\StoreViewSnapshot;
use Watchtower\Connector\Model\Diagnostics\SubmissionOutcome;
use Watchtower\Connector\Model\Environment\ConnectorVersionState;
use Watchtower\Connector\Model\Environment\EnvironmentState;
use Watchtower\Connector\Model\Seed\SeedCoverageLabel;

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
            environment: $this->emptyEnvironmentState(),
            connectorVersion: $this->uncheckedConnectorVersionState(),
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
            cronHealth: new SignalSnapshot('cron_health', SignalStatus::Normal, 12, ReportReason::Heartbeat),
            storeViews: [
                new StoreViewSnapshot(1, 'default', [
                    new SignalSnapshot('basket_quote', SignalStatus::InsufficientData, 1, ReportReason::Transition),
                    new SignalSnapshot('checkout', SignalStatus::InsufficientData, 1, ReportReason::Transition),
                    new SignalSnapshot(
                        'customer_account',
                        SignalStatus::InsufficientData,
                        1,
                        ReportReason::Transition
                    ),
                ]),
            ],
            recentSubmissionOutcomes: [],
            environment: $this->emptyEnvironmentState(),
            connectorVersion: $this->uncheckedConnectorVersionState(),
        );

        $tester = new CommandTester($this->command(snapshot: $snapshot));
        $tester->execute([]);

        $display = $tester->getDisplay();
        self::assertStringContainsString('cron_health: NORMAL (sequence 12, reason: heartbeat)', $display);
        self::assertStringContainsString('Store view "default" (id=1):', $display);
        self::assertStringContainsString(
            'basket_quote: INSUFFICIENT_DATA (sequence 1, reason: transition)',
            $display
        );
        self::assertStringContainsString('checkout: INSUFFICIENT_DATA (sequence 1, reason: transition)', $display);
        self::assertStringContainsString(
            'customer_account: INSUFFICIENT_DATA (sequence 1, reason: transition)',
            $display
        );
    }

    public function testPrintsEstimatedDetectionLatencyOnlyForALowVolumeSignal(): void
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
                    new SignalSnapshot(
                        'basket_quote',
                        SignalStatus::Normal,
                        5,
                        ReportReason::Heartbeat,
                        estimatedDetectionLatencyHours: 18.73
                    ),
                    new SignalSnapshot('checkout', SignalStatus::InsufficientData, 1, ReportReason::Transition),
                ]),
            ],
            recentSubmissionOutcomes: [],
            environment: $this->emptyEnvironmentState(),
            connectorVersion: $this->uncheckedConnectorVersionState(),
        );

        $tester = new CommandTester($this->command(snapshot: $snapshot));
        $tester->execute([]);

        $display = $tester->getDisplay();
        self::assertStringContainsString(
            'basket_quote: NORMAL (sequence 5, reason: heartbeat), ~19h to detect a full outage (low-volume mode)',
            $display
        );
        self::assertStringContainsString(
            'checkout: INSUFFICIENT_DATA (sequence 1, reason: transition)',
            $display
        );
        self::assertStringNotContainsString(
            'checkout: INSUFFICIENT_DATA (sequence 1, reason: transition), ~',
            $display
        );
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
            environment: $this->emptyEnvironmentState(),
            connectorVersion: $this->uncheckedConnectorVersionState(),
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

    public function testPrintsNoDataYetWhenNeverSynced(): void
    {
        $tester = new CommandTester($this->command());
        $tester->execute([]);

        self::assertStringContainsString('Environment: no data yet (run watchtower:sync).', $tester->getDisplay());
    }

    public function testPrintsMagentoEolWarning(): void
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
            recentSubmissionOutcomes: [],
            environment: new EnvironmentState(
                magentoVersion: '2.4.6-p5',
                magentoEdition: 'Community',
                connectorVersion: '1.0.1',
                magentoEol: new MagentoEolInfo(isEol: true, eolDate: '2025-06-11', statusLabel: 'eol'),
                syncedAt: new \DateTimeImmutable('2026-08-14T10:00:00+00:00'),
            ),
            connectorVersion: $this->uncheckedConnectorVersionState(),
        );

        $tester = new CommandTester($this->command(snapshot: $snapshot));
        $tester->execute([]);

        $display = $tester->getDisplay();
        self::assertStringContainsString('Magento: Community 2.4.6-p5', $display);
        self::assertStringContainsString('EOL since 2025-06-11', $display);
        self::assertStringContainsString('Connector: v1.0.1', $display);
    }

    public function testPrintsNoDataYetWhenTheVersionCheckHasNeverSucceeded(): void
    {
        $tester = new CommandTester($this->command());
        $tester->execute([]);

        self::assertStringContainsString('Connector version check: no data yet.', $tester->getDisplay());
    }

    /**
     * Self-disabled is the state support most needs this command to explain:
     * every other section can look healthy while nothing is being submitted,
     * so the reason has to be stated outright rather than inferred from the
     * installed/minimum numbers.
     */
    public function testPrintsTheSelfDisabledReasonWhenBelowTheMinimumVersion(): void
    {
        $tester = new CommandTester($this->command(
            snapshot: $this->snapshotWithConnectorVersion(new ConnectorVersionState(
                installedVersion: '1.0.1',
                minimumVersion: '1.2.0',
                latestVersion: '1.2.0',
                belowMinimum: true,
                updateAvailable: true,
                checkedAt: new \DateTimeImmutable('2026-08-14T10:00:00+00:00'),
            ))
        ));
        $tester->execute([]);

        $display = $tester->getDisplay();
        self::assertStringContainsString(
            'Connector version check: installed=1.0.1, minimum=1.2.0, latest=1.2.0',
            $display
        );
        self::assertStringContainsString('Reporting is self-disabled', $display);
        self::assertStringNotContainsString('An update is available.', $display);
    }

    /**
     * Behind latest but at or above minimum is non-blocking -- it must read
     * as an advisory, never as the self-disabled message.
     */
    public function testPrintsANonBlockingUpdateNoticeWhenBehindLatestButAtOrAboveMinimum(): void
    {
        $tester = new CommandTester($this->command(
            snapshot: $this->snapshotWithConnectorVersion(new ConnectorVersionState(
                installedVersion: '1.1.0',
                minimumVersion: '1.0.0',
                latestVersion: '1.2.0',
                belowMinimum: false,
                updateAvailable: true,
                checkedAt: new \DateTimeImmutable('2026-08-14T10:00:00+00:00'),
            ))
        ));
        $tester->execute([]);

        $display = $tester->getDisplay();
        self::assertStringContainsString('An update is available.', $display);
        self::assertStringNotContainsString('self-disabled', $display);
    }

    private function snapshotWithConnectorVersion(ConnectorVersionState $connectorVersion): DiagnosticsSnapshot
    {
        return new DiagnosticsSnapshot(
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
            environment: $this->emptyEnvironmentState(),
            connectorVersion: $connectorVersion,
        );
    }

    private function uncheckedConnectorVersionState(): ConnectorVersionState
    {
        return new ConnectorVersionState(
            installedVersion: null,
            minimumVersion: null,
            latestVersion: null,
            belowMinimum: false,
            updateAvailable: false,
            checkedAt: null,
        );
    }

    private function emptyEnvironmentState(): EnvironmentState
    {
        return new EnvironmentState(
            magentoVersion: null,
            magentoEdition: null,
            connectorVersion: null,
            magentoEol: null,
            syncedAt: null,
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
                environment: $this->emptyEnvironmentState(),
                connectorVersion: $this->uncheckedConnectorVersionState(),
            );
        }

        $provider = $this->createStub(DiagnosticsSnapshotProvider::class);
        $provider->method('snapshot')->willReturn($snapshot);

        return new StatusCommand($config, $provider, new SeedCoverageLabel());
    }
}
