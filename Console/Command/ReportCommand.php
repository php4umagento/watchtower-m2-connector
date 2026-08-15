<?php

declare(strict_types=1);

namespace Watchtower\Connector\Console\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Watchtower\Connector\Model\Api\MetricsSubmissionResult;
use Watchtower\Connector\Model\ReportingService;

class ReportCommand extends Command
{
    /**
     * @param ReportingService $reportingService
     * @param string|null $name
     */
    public function __construct(
        private readonly ReportingService $reportingService,
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
        $this->setName('watchtower:report')
            ->setDescription('Evaluate tracked signals and submit any due reports to Watchtower now');

        parent::configure();
    }

    /**
     * Evaluates tracked signals and submits any due reports to the platform.
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $outcome = $this->reportingService->run();

        if (!$outcome['ran']) {
            $notConfigured = $outcome['skippedReason'] === 'not configured';
            $message = $notConfigured
                ? 'Watchtower is not configured. Set Stores > Configuration > Watchtower > Base URL and API Key first.'
                : 'Watchtower is disabled (Stores > Configuration > Watchtower > Enabled = No). Not reporting.';

            $output->writeln($notConfigured ? "<error>{$message}</error>" : $message);

            return $notConfigured ? Command::FAILURE : Command::SUCCESS;
        }

        if ($outcome['expiredBufferedCount'] > 0) {
            $output->writeln(sprintf(
                '<comment>Discarded %d buffered report(s) that exceeded the platform\'s report-age horizon.</comment>',
                $outcome['expiredBufferedCount']
            ));
        }

        if ($outcome['evictedForCapacityCount'] > 0) {
            $output->writeln(sprintf(
                '<comment>Evicted %d buffered report(s) to stay under the buffer capacity cap -- '
                    . 'the backlog is growing faster than it can be delivered.</comment>',
                $outcome['evictedForCapacityCount']
            ));
        }

        $report = $outcome['report'];
        $result = $outcome['result'];

        if ($result === null) {
            $reason = $outcome['organizationPaused']
                ? 'organization is paused'
                : 'still backing off after a prior failure';

            $output->writeln(sprintf(
                '<info>cron_health: %s (%s)</info> -- %s, not submitted this run.',
                $report->status->value,
                $report->reason->value,
                $reason
            ));
            $output->writeln($outcome['organizationPaused']
                ? 'Buffered -- will be included automatically once the organization is unpaused.'
                : 'Buffered -- will be included automatically once the backoff window '
                    . 'passes and Watchtower is reachable.');

            return Command::SUCCESS;
        }

        if (!$result->succeeded) {
            $output->writeln(sprintf('<error>Report submission failed: %s</error>', $result->errorMessage));
            $output->writeln(
                'Buffered for retry -- will be included automatically once Watchtower is reachable again.'
            );

            return Command::FAILURE;
        }

        $output->writeln(sprintf(
            '<info>cron_health: %s (%s)</info>',
            $report->status->value,
            $report->reason->value
        ));
        $output->writeln(sprintf('Accepted: %d', $result->accepted));

        if ($outcome['includedBufferedCount'] > 0) {
            $output->writeln(sprintf('Buffered reports delivered this run: %d', $outcome['includedBufferedCount']));
        }

        foreach ($result->rejected as $rejection) {
            // Dedup is expected/benign -- proof this exact report already
            // reached the platform on an earlier attempt, not a genuine
            // problem worth the same visual weight as an unrecognized
            // store view code.
            $isDedup = ($rejection['reason'] ?? null) === MetricsSubmissionResult::DEDUP_REJECTION_REASON;

            $output->writeln(sprintf(
                $isDedup ? '  - %s: already delivered (%s)' : '  - %s: <comment>%s</comment>',
                $rejection['event_type'] ?? '(unknown)',
                $rejection['reason'] ?? '(no reason given)'
            ));
        }

        return Command::SUCCESS;
    }
}
