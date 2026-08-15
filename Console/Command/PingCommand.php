<?php

declare(strict_types=1);

namespace Watchtower\Connector\Console\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Watchtower\Connector\Model\Api\PingService;
use Watchtower\Connector\Model\Config;

class PingCommand extends Command
{
    /**
     * @param Config $config
     * @param PingService $pingService
     * @param string|null $name
     */
    public function __construct(
        private readonly Config $config,
        private readonly PingService $pingService,
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
        $this->setName('watchtower:ping')
            ->setDescription('Check connectivity to the configured Watchtower platform');

        parent::configure();
    }

    /**
     * Pings the configured platform and reports reachability, key validity, and clock skew.
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

        // Deliberately NOT gated on isEnabled(), unlike SyncCommand: ping is
        // a read-only diagnostic (no StoreView is created, nothing billable
        // happens) and is exactly what a merchant needs to work while
        // troubleshooting why a *disabled* connector isn't doing anything.
        $ping = $this->pingService->ping($this->config->baseUrl(), $this->config->apiKey());

        if (!$ping->reachable) {
            $output->writeln(sprintf(
                '<error>Could not reach %s: %s</error>',
                $this->config->baseUrl(),
                $ping->errorMessage
            ));

            return Command::FAILURE;
        }

        if (!$ping->keyValid()) {
            $output->writeln(sprintf(
                '<error>Reached Watchtower but the API key was rejected (HTTP %d).</error>',
                $ping->httpStatus
            ));

            return Command::FAILURE;
        }

        $output->writeln('<info>Reachable: yes</info>');
        $output->writeln('<info>Key valid: yes</info>');
        $output->writeln(sprintf('Install: %s', $ping->install ?? '(unknown)'));
        $output->writeln(sprintf('Organization paused: %s', $ping->organizationPaused ? 'yes' : 'no'));
        $output->writeln(sprintf('Alerting enabled: %s', $ping->alertingEnabled ? 'yes' : 'no'));
        $output->writeln(sprintf(
            'Entitled signals: %s',
            $ping->entitledSignals ? implode(', ', $ping->entitledSignals) : '(none)'
        ));
        $output->writeln(sprintf('Server time: %s', $ping->serverTime ?? '(unknown)'));
        $output->writeln(sprintf(
            'Clock skew: %s',
            $ping->clockSkewSeconds() !== null ? $ping->clockSkewSeconds() . 's' : '(unknown)'
        ));

        return Command::SUCCESS;
    }
}
