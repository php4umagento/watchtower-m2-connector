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
use Watchtower\Connector\Model\AdminAuthFailure\InstallEventCounterRepository;
use Watchtower\Connector\Model\EventCounter\EventCounterRepository;

class EventCounterPruneCommand extends Command
{
    /**
     * @param EventCounterRepository $eventCounterRepository
     * @param InstallEventCounterRepository $installEventCounterRepository
     * @param string|null $name
     */
    public function __construct(
        private readonly EventCounterRepository $eventCounterRepository,
        private readonly InstallEventCounterRepository $installEventCounterRepository,
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
        $this->setName('watchtower:event-counter-prune')
            ->setDescription('Prune every raw event counter table past retention');

        parent::configure();
    }

    /**
     * Prunes the local event counter tables.
     *
     * Purely local storage maintenance; unlike the other commands in this
     * module it never talks to the platform, so it deliberately does not
     * check Config::isConfigured()/isEnabled().
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $now = new \DateTimeImmutable();
        $result = $this->eventCounterRepository->prune($now);
        $installRowsPruned = $this->installEventCounterRepository->prune($now);

        $output->writeln(sprintf('<info>Event counter rows pruned: %d</info>', $result->counterRowsPruned));
        $output->writeln(sprintf('Event drop counter rows pruned: %d', $result->dropCounterRowsPruned));
        $output->writeln(sprintf('Install event counter rows pruned: %d', $installRowsPruned));

        return Command::SUCCESS;
    }
}
