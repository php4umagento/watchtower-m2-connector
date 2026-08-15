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
use Watchtower\Connector\Model\Rollup\RollupRepository;

class RollupPruneCommand extends Command
{
    /**
     * @param RollupRepository $rollupRepository
     * @param string|null $name
     */
    public function __construct(
        private readonly RollupRepository $rollupRepository,
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
        $this->setName('watchtower:rollup-prune')
            ->setDescription('Roll aged hourly counters into daily rollups and prune both tables to retention now');

        parent::configure();
    }

    /**
     * Rolls up and prunes the local historical rollup tables.
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
        $result = $this->rollupRepository->rollupAndPrune(new \DateTimeImmutable());

        $output->writeln(sprintf('<info>Rolled up day groups: %d</info>', $result->rolledDayGroups));
        $output->writeln(sprintf('Hourly rows pruned: %d', $result->hourlyRowsPruned));
        $output->writeln(sprintf('Daily rows pruned (exceeded retention): %d', $result->dailyRowsPruned));

        return Command::SUCCESS;
    }
}
