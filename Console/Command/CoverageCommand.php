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
use Watchtower\Connector\Model\RateSignal\DispersionEvaluator;
use Watchtower\Connector\Model\Seed\HistorySeeder;
use Watchtower\Connector\Model\Seed\SeedCoverageResult;
use Watchtower\Connector\Model\Seed\SeedCoverageStatus;
use Watchtower\Connector\Model\Seed\SeedLimitReason;

/**
 * Reports local historical baseline coverage for every live store view.
 *
 * This command's own execution IS the seeding trigger -- HistorySeeder has no
 * other caller and no crontab entry, so every invocation re-seeds rather than
 * only reading back already-seeded state. Re-seeding an already-seeded hour is
 * wasteful but never incorrect, since RollupRepository::recordHourlyCount() is
 * an idempotent upsert.
 */
class CoverageCommand extends Command
{
    /**
     * @param HistorySeeder $historySeeder
     * @param StoreManagerInterface $storeManager
     * @param string|null $name
     */
    public function __construct(
        private readonly HistorySeeder $historySeeder,
        private readonly StoreManagerInterface $storeManager,
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

            $results = $this->historySeeder->seed((int) $store->getId(), $now, $this->baselineWindowDays());

            foreach ($results as $result) {
                $output->writeln('  '.$this->describe($result));
            }
        }

        return Command::SUCCESS;
    }

    /**
     * The longest of DispersionEvaluator's own lookback windows, so a new
     * install accumulates enough rollup history to fill whichever one
     * evaluation actually queries -- previously a hand-maintained mirror of
     * BASELINE_WEEKS alone, which meant LOW_VOLUME_LOOKBACK_WEEKS's wider
     * query window had no real history to find on a freshly-seeded install
     * regardless of how far back it was willing to look.
     *
     * @return int
     */
    private function baselineWindowDays(): int
    {
        return 7 * max(DispersionEvaluator::BASELINE_WEEKS, DispersionEvaluator::LOW_VOLUME_LOOKBACK_WEEKS);
    }

    /**
     * Renders one category's coverage result in merchant-facing wording.
     *
     * @param SeedCoverageResult $result
     * @return string
     */
    private function describe(SeedCoverageResult $result): string
    {
        $label = $this->categoryLabel($result->category);

        if ($result->status === SeedCoverageStatus::Seeded) {
            return sprintf('%s history seeded: %d days', $label, $result->daysSeeded);
        }

        return match ($result->limitReason) {
            SeedLimitReason::RetentionCliff => sprintf(
                '%s history unavailable (quote lifetime is %d days); warming up',
                $label,
                $result->sourceRetentionDays ?? 0
            ),
            SeedLimitReason::RowCountCeiling => sprintf(
                '%s history seeded: %d of %d days (stopped early -- this store has a very large amount of history)',
                $label,
                $result->daysSeeded,
                $result->requestedDays
            ),
            SeedLimitReason::InsufficientHistory => sprintf(
                '%s history warming up: %d of %d days available so far',
                $label,
                $result->daysSeeded,
                $result->requestedDays
            ),
            // HistorySeeder never returns Limited without a reason; this
            // branch exists only so match() is exhaustive for phpstan.
            null => sprintf('%s history limited: %d of %d days', $label, $result->daysSeeded, $result->requestedDays),
        };
    }

    /**
     * Plain merchant-readable label for a HistorySeeder::CATEGORY_* constant.
     *
     * @param string $category
     * @return string
     */
    private function categoryLabel(string $category): string
    {
        return match ($category) {
            HistorySeeder::CATEGORY_BASKET_QUOTE => 'cart',
            HistorySeeder::CATEGORY_CHECKOUT => 'checkout',
            HistorySeeder::CATEGORY_CUSTOMER_ACCOUNT => 'customer account',
            default => $category,
        };
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
