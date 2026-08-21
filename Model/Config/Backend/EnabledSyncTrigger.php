<?php
/**
 * Copyright © 2026 Watchtower. All rights reserved.
 * Licensed under the Business Source License 1.1 (BUSL-1.1). See LICENSE.
 */

declare(strict_types=1);

namespace Watchtower\Connector\Model\Config\Backend;

use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ReinitableConfigInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Value;
use Magento\Framework\Data\Collection\AbstractDb;
use Magento\Framework\Model\Context;
use Magento\Framework\Model\ResourceModel\AbstractResource;
use Magento\Framework\Registry;
use Psr\Log\LoggerInterface;
use Watchtower\Connector\Model\Api\StoreViewSyncService;
use Watchtower\Connector\Model\Config;

/**
 * backend_model for watchtower/general/enabled: triggers a store view sync
 * the moment the module is enabled (SyncJob covers the scheduled sync).
 *
 * isValueChanged() is the same guard the framework's own Value::afterSave()
 * uses for cache invalidation: every field in a saved section gets
 * afterSave() called whether or not its value actually changed, so without
 * this check saving an unrelated field would re-trigger a sync every time.
 *
 * A single admin-form save that sets base URL, API key, and enabled
 * together for the very first time used to read stale pre-save (null)
 * values from ScopeConfigInterface's in-request cache -- isConfigured()
 * then came back false and the sync silently never fired, with no error
 * anywhere. reinit() (see below) is the fix, and is not a workaround of our
 * own invention: it's the exact call Magento\Config\Model\Config::save()
 * itself makes right after its own save transaction commits, for this same
 * reason -- ours just runs one step earlier, before that method reaches it.
 */
class EnabledSyncTrigger extends Value
{
    /**
     * @param Context $context
     * @param Registry $registry
     * @param ScopeConfigInterface $config
     * @param TypeListInterface $cacheTypeList
     * @param ReinitableConfigInterface $reinitableConfig
     * @param Config $watchtowerConfig
     * @param StoreViewSyncService $storeViewSyncService
     * @param LoggerInterface $logger
     * @param AbstractResource|null $resource
     * @param AbstractDb|null $resourceCollection
     * @param array<string,mixed> $data
     */
    public function __construct(
        Context $context,
        Registry $registry,
        ScopeConfigInterface $config,
        TypeListInterface $cacheTypeList,
        private readonly ReinitableConfigInterface $reinitableConfig,
        private readonly Config $watchtowerConfig,
        private readonly StoreViewSyncService $storeViewSyncService,
        private readonly LoggerInterface $logger,
        ?AbstractResource $resource = null,
        ?AbstractDb $resourceCollection = null,
        array $data = []
    ) {
        parent::__construct($context, $registry, $config, $cacheTypeList, $resource, $resourceCollection, $data);
    }

    /**
     * Fires an immediate sync the moment "Enabled" transitions from 0/unset to 1.
     *
     * @return $this
     */
    public function afterSave()
    {
        $justEnabled = $this->isValueChanged() && (bool) $this->getValue();

        if ($justEnabled) {
            // Forces base_url/api_key to be re-read from the database
            // rather than whatever ScopeConfigInterface had cached before
            // this request -- see the class docblock.
            $this->reinitableConfig->reinit();

            if ($this->watchtowerConfig->isConfigured()) {
                $result = $this->storeViewSyncService->sync(
                    (string) $this->watchtowerConfig->baseUrl(),
                    (string) $this->watchtowerConfig->apiKey()
                );

                if (!$result->succeeded) {
                    $this->logger->warning('Watchtower on-enable sync failed.', [
                        'error' => $result->errorMessage,
                    ]);
                }
            }
        }

        return parent::afterSave();
    }
}
