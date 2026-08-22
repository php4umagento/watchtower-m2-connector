<?php
/**
 * Copyright © 2026 Watchtower. All rights reserved.
 * Licensed under the Business Source License 1.1 (BUSL-1.1). See LICENSE.
 */

declare(strict_types=1);

namespace Watchtower\Connector\Console\Command;

use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Watchtower\Connector\Model\Seed\HistorySeeder;
use Watchtower\Connector\Model\Seed\SeedCoverageLabel;
use Watchtower\Connector\Model\Seed\SeedCoverageRepository;

/**
 * Seeds and reports local historical baseline coverage for every live store view.
 *
 * This command's own execution IS one of the seeding triggers -- the other is
 * ReportingService's own first-evaluation seed (see its seedIfNeverSeeded()) --
 * so every invocation here re-seeds rather than only reading back already-seeded
 * state. Re-seeding an already-seeded hour is wasteful but never incorrect,
 * since RollupRepository::recordHourlyCount() is an idempotent upsert. Each
 * result is also persisted via SeedCoverageRepository, so the diagnostics page
 * and watchtower:status can read back the outcome without re-seeding.
 */
class CoverageCommand extends Command
{
    /**
     * @param HistorySeeder $historySeeder
     * @param StoreManagerInterface $storeManager
     * @param SeedCoverageRepository $seedCoverageRepository
     * @param SeedCoverageLabel $seedCoverageLabel
     * @param string|null $name
     */
    public function __construct(
        private readonly HistorySeeder $historySeeder,
        private readonly StoreManagerInterface $storeManager,
        private readonly SeedCoverageRepository $seedCoverageRepository,
        private readonly SeedCoverageLabel $seedCoverageLabel,
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
        $this->setName('watchtower:coverage')
            ->setDescription('Seed and report local historical baseline coverage for every live store view');

        parent::configure();
    }

    /**
     * Seeds and reports coverage for every live store view.
     *
     * Deliberately NOT gated on Config::isConfigured()/isEnabled(): this only
     * reads/writes local rollup state and never talks to the platform, so it
     * stays usable while troubleshooting either of those states.
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $liveStores = $this->liveStores();

        if ($liveStores === []) {
            $output->writeln('<error>No live store views found on this Magento install.</error>');

            return Command::FAILURE;
        }

        $now = new \DateTimeImmutable();

        foreach ($liveStores as $store) {
            $output->writeln(sprintf('<info>%s (%s):</info>', $store->getName(), $store->getCode()));

            $storeViewId = (int) $store->getId();
            $results = $this->historySeeder->seed(
                $storeViewId,
                $now,
                $this->historySeeder->defaultBaselineWindowDays()
            );

            foreach ($results as $result) {
                $this->seedCoverageRepository->save($storeViewId, $result);
                $output->writeln('  '.$this->seedCoverageLabel->describe($result));
            }
        }

        return Command::SUCCESS;
    }

    /**
     * This install's live (non-admin, enabled) store views.
     *
     * StoreManagerInterface::getStores() only excludes the admin store, not
     * disabled store views, so the is_active filter is still required.
     *
     * @return StoreInterface[]
     */
    private function liveStores(): array
    {
        return array_values(array_filter(
            $this->storeManager->getStores(),
            static fn (StoreInterface $store): bool => (bool) $store->getIsActive()
        ));
    }
}
