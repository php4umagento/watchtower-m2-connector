<?php
/**
 * Copyright © 2026 Watchtower. All rights reserved.
 * Licensed under the Business Source License 1.1 (BUSL-1.1). See LICENSE.
 */

declare(strict_types=1);

namespace Watchtower\Connector\Controller\Adminhtml\IntegrationHealth;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Watchtower\Connector\Model\IntegrationHealth\AvailableSourcesProvider;

/**
 * Feeds the picker's cron_job source-identifier dropdowns. Fetched once per
 * page load and shared across every store view row.
 */
class CronJobs extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'Watchtower_Connector::integration_health';

    /**
     * @param Context $context
     * @param AvailableSourcesProvider $availableSourcesProvider
     * @param JsonFactory $resultJsonFactory
     */
    public function __construct(
        Context $context,
        private readonly AvailableSourcesProvider $availableSourcesProvider,
        private readonly JsonFactory $resultJsonFactory
    ) {
        parent::__construct($context);
    }

    /**
     * Returns the selectable cron job codes, grouped by cron group.
     *
     * Emitted as an ordered list of {group, jobCodes} objects rather than a
     * group-keyed object so the provider's natural sort survives the JSON
     * round trip: a JSON object's key order is not something the client is
     * entitled to rely on.
     *
     * @return Json
     */
    public function execute(): Json
    {
        $jobGroups = [];

        foreach ($this->availableSourcesProvider->cronJobCodesByGroup() as $group => $jobCodes) {
            $jobGroups[] = ['group' => $group, 'jobCodes' => $jobCodes];
        }

        return $this->resultJsonFactory->create()->setData(['jobGroups' => $jobGroups]);
    }
}
