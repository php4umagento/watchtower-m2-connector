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
use Watchtower\Connector\Model\Api\StoreViewSyncService;
use Watchtower\Connector\Model\Config;

class SyncCommand extends Command
{
    /**
     * @param Config $config
     * @param StoreViewSyncService $storeViewSyncService
     * @param string|null $name
     */
    public function __construct(
        private readonly Config $config,
        private readonly StoreViewSyncService $storeViewSyncService,
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
        $this->setName('watchtower:sync')
            ->setDescription('Report this install\'s live store views to Watchtower now');

        parent::configure();
    }

    /**
     * Syncs this install's live store views to the platform.
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

        // Sync creates a platform-side StoreView and consumes the shop
        // allowance and metered billing quantity, so a disabled connector must
        // not run it. Not an error: the command did what "disabled" means.
        if (!$this->config->isEnabled()) {
            $output->writeln(
                'Watchtower is disabled (Stores > Configuration > Watchtower > Enabled = No). Not syncing.'
            );

            return Command::SUCCESS;
        }

        $result = $this->storeViewSyncService->sync($this->config->baseUrl(), $this->config->apiKey());

        if (!$result->succeeded) {
            $output->writeln(sprintf('<error>Sync failed: %s</error>', $result->errorMessage));

            return Command::FAILURE;
        }

        $output->writeln(sprintf('<info>Synced (already known): %d</info>', count($result->synced)));
        $output->writeln(sprintf('<info>Created (new): %d</info>', count($result->created)));
        $output->writeln(sprintf('Rejected: %d', count($result->rejected)));

        foreach ($result->rejected as $rejection) {
            $output->writeln(sprintf(
                '  - %s: %s',
                $rejection['code'] ?? '(unknown)',
                $rejection['reason'] ?? '(no reason given)'
            ));
        }

        if ($result->magentoEol?->isEol === true) {
            $output->writeln(sprintf(
                '<error>This Magento version reached end of life on %s.</error>',
                $result->magentoEol->eolDate ?? 'an unknown date'
            ));
        }

        return Command::SUCCESS;
    }
}
