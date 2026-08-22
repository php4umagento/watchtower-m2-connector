<?php
/**
 * Copyright © 2026 Watchtower. All rights reserved.
 * Licensed under the Business Source License 1.1 (BUSL-1.1). See LICENSE.
 */

declare(strict_types=1);

namespace Watchtower\Connector\Console\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Watchtower\Connector\Model\Config;
use Watchtower\Connector\Model\Diagnostics\DiagnosticsSnapshot;
use Watchtower\Connector\Model\Diagnostics\DiagnosticsSnapshotProvider;

/**
 * The admin diagnostics page, headlessly. Deliberately continues past an
 * unreachable platform to still print local diagnostic state (buffered count,
 * per-signal status) -- this is the command support runs WHILE the platform
 * looks down, so local state must never be withheld just because the
 * connection check failed.
 *
 * Data assembly is delegated to DiagnosticsSnapshotProvider, shared with the
 * admin diagnostics page so the two cannot drift apart.
 */
class StatusCommand extends Command
{
    /**
     * @param Config $config
     * @param DiagnosticsSnapshotProvider $diagnosticsSnapshotProvider
     * @param string|null $name
     */
    public function __construct(
        private readonly Config $config,
        private readonly DiagnosticsSnapshotProvider $diagnosticsSnapshotProvider,
        ?string $name = null
    ) {
        parent::__construct($name);
    }

    /**
     * Registers the command name and description.
     *
     * @return void
     */
    protected function configure(): void
    {
        $this->setName('watchtower:status')
            ->setDescription('Print diagnostics: connection state, buffer backlog, and per-signal status');

        parent::configure();
    }

    /**
     * Prints connection state, submission/buffer diagnostics, and every tracked signal's last-known status.
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$this->config->isConfigured()) {
            $output->writeln(
                '<error>Watchtower is not configured. '
                . 'Set Stores > Configuration > Watchtower > Base URL and API Key first.</error>'
            );

            return Command::FAILURE;
        }

        $snapshot = $this->diagnosticsSnapshotProvider->snapshot(new \DateTimeImmutable());

        $this->printConnectionState($output, $snapshot);
        $this->printSubmissionState($output, $snapshot);
        $this->printSignalStatus($output, $snapshot);
        $this->printEnvironment($output, $snapshot);
        $this->printConnectorVersion($output, $snapshot);
        $this->printRecentOutcomes($output, $snapshot);

        return Command::SUCCESS;
    }

    /**
     * Prints the ping outcome (reachability, key validity, organization_paused).
     *
     * @param OutputInterface $output
     * @param DiagnosticsSnapshot $snapshot
     * @return void
     */
    private function printConnectionState(OutputInterface $output, DiagnosticsSnapshot $snapshot): void
    {
        if (!$snapshot->reachable) {
            $output->writeln(sprintf('<error>Reachable: no (%s)</error>', $snapshot->unreachableError));

            return;
        }

        $output->writeln(sprintf('Reachable: yes / Key valid: %s', $snapshot->keyValid ? 'yes' : 'no'));
        $output->writeln(sprintf('Organization paused: %s', $snapshot->organizationPaused ? 'yes' : 'no'));
    }

    /**
     * Prints buffer/submission diagnostics: last success, backlog size, dropped-event count.
     *
     * @param OutputInterface $output
     * @param DiagnosticsSnapshot $snapshot
     * @return void
     */
    private function printSubmissionState(OutputInterface $output, DiagnosticsSnapshot $snapshot): void
    {
        $lastSuccess = $snapshot->lastSuccessfulSubmissionAt;

        $output->writeln(sprintf(
            'Last successful submission: %s',
            $lastSuccess !== null ? $lastSuccess->format(\DateTimeInterface::ATOM) : 'never'
        ));
        $output->writeln(sprintf('Buffered reports: %d', $snapshot->bufferedReportCount));
        $output->writeln(sprintf('Dropped events (last 24h): %d', $snapshot->droppedEventCountLast24Hours));
    }

    /**
     * Prints cron_health plus every live store view's own per-category signal status.
     *
     * @param OutputInterface $output
     * @param DiagnosticsSnapshot $snapshot
     * @return void
     */
    private function printSignalStatus(OutputInterface $output, DiagnosticsSnapshot $snapshot): void
    {
        $output->writeln(sprintf(
            'cron_health: %s (sequence %d)',
            $snapshot->cronHealth->status?->value ?? 'no data yet',
            $snapshot->cronHealth->sequenceNumber
        ));

        foreach ($snapshot->storeViews as $storeView) {
            $output->writeln(sprintf('Store view "%s" (id=%d):', $storeView->storeViewCode, $storeView->storeViewId));

            foreach ($storeView->signals as $signal) {
                $latency = $signal->estimatedDetectionLatencyHours !== null
                    ? sprintf(
                        ', ~%dh to detect a full outage (low-volume mode)',
                        (int) ceil($signal->estimatedDetectionLatencyHours)
                    )
                    : '';

                $output->writeln(sprintf(
                    '  %s: %s (sequence %d)%s',
                    $signal->category,
                    $signal->status?->value ?? 'no data yet',
                    $signal->sequenceNumber,
                    $latency
                ));
            }
        }
    }

    /**
     * Prints the environment facts and EOL determination from the last successful sync.
     *
     * @param OutputInterface $output
     * @param DiagnosticsSnapshot $snapshot
     * @return void
     */
    private function printEnvironment(OutputInterface $output, DiagnosticsSnapshot $snapshot): void
    {
        $environment = $snapshot->environment;

        if ($environment->syncedAt === null) {
            $output->writeln('Environment: no data yet (run watchtower:sync).');

            return;
        }

        $output->writeln(sprintf(
            'Magento: %s %s%s',
            $environment->magentoEdition ?? 'unknown edition',
            $environment->magentoVersion ?? 'unknown version',
            $environment->magentoEol?->isEol === true
                ? sprintf(' <error>(EOL since %s)</error>', $environment->magentoEol->eolDate ?? 'unknown date')
                : ''
        ));

        $output->writeln(sprintf('Connector: v%s', $environment->connectorVersion ?? 'unknown'));
    }

    /**
     * Prints the last successful connector-version check outcome (PRD FR24-FR27).
     *
     * Covers the installed/minimum/latest version and whether reporting is currently self-disabled.
     *
     * @param OutputInterface $output
     * @param DiagnosticsSnapshot $snapshot
     * @return void
     */
    private function printConnectorVersion(OutputInterface $output, DiagnosticsSnapshot $snapshot): void
    {
        $versionState = $snapshot->connectorVersion;

        if ($versionState->checkedAt === null) {
            $output->writeln('Connector version check: no data yet.');

            return;
        }

        $output->writeln(sprintf(
            'Connector version check: installed=%s, minimum=%s, latest=%s',
            $versionState->installedVersion ?? 'unknown',
            $versionState->minimumVersion ?? 'unknown',
            $versionState->latestVersion ?? 'unknown'
        ));

        if ($versionState->belowMinimum) {
            $output->writeln(
                '<error>Reporting is self-disabled: installed version is below the minimum supported version.</error>'
            );
        } elseif ($versionState->updateAvailable) {
            $output->writeln('<comment>An update is available.</comment>');
        }
    }

    /**
     * Prints the last N submission outcomes, accepted/rejected with reasons.
     *
     * @param OutputInterface $output
     * @param DiagnosticsSnapshot $snapshot
     * @return void
     */
    private function printRecentOutcomes(OutputInterface $output, DiagnosticsSnapshot $snapshot): void
    {
        if ($snapshot->recentSubmissionOutcomes === []) {
            $output->writeln('Recent submission outcomes: none recorded yet.');

            return;
        }

        $output->writeln('Recent submission outcomes (newest first):');

        foreach ($snapshot->recentSubmissionOutcomes as $outcome) {
            $line = sprintf(
                '  %s: %s, accepted=%d, rejected=%d',
                $outcome->occurredAt->format(\DateTimeInterface::ATOM),
                $outcome->succeeded ? 'succeeded' : 'failed',
                $outcome->acceptedCount,
                $outcome->rejectedCount
            );

            if ($outcome->rejectedCount > 0) {
                $reasons = [];
                foreach ($outcome->rejectionReasons as $reason => $count) {
                    $reasons[] = sprintf('%s x%d', $reason, $count);
                }
                $line .= sprintf(' (%s)', implode(', ', $reasons));
            }

            if ($outcome->errorMessage !== null) {
                $line .= sprintf(' -- %s', $outcome->errorMessage);
            }

            $output->writeln($line);
        }
    }
}
