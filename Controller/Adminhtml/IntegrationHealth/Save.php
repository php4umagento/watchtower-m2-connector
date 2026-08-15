<?php
/**
 * Copyright © 2026 Watchtower. All rights reserved.
 * Licensed under the Business Source License 1.1 (BUSL-1.1). See LICENSE.
 */

declare(strict_types=1);

namespace Watchtower\Connector\Controller\Adminhtml\IntegrationHealth;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Watchtower\Connector\Model\IntegrationHealth\IntegrationHealthConfig;
use Watchtower\Connector\Model\IntegrationHealth\IntegrationHealthConfigRepository;
use Watchtower\Connector\Model\IntegrationHealth\IntegrationHealthConfigValidator;

/**
 * Persists one store view's integration_health source from the picker.
 * Returns every validation error at once so the row can render them all
 * inline.
 */
class Save extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Watchtower_Connector::integration_health';

    /**
     * @param Context $context
     * @param IntegrationHealthConfigValidator $validator
     * @param IntegrationHealthConfigRepository $configRepository
     * @param JsonFactory $resultJsonFactory
     */
    public function __construct(
        Context $context,
        private readonly IntegrationHealthConfigValidator $validator,
        private readonly IntegrationHealthConfigRepository $configRepository,
        private readonly JsonFactory $resultJsonFactory
    ) {
        parent::__construct($context);
    }

    /**
     * Validates the submitted row and upserts it when clean.
     *
     * @return Json
     */
    public function execute(): Json
    {
        $result = $this->resultJsonFactory->create();

        $storeViewId = (int) $this->getRequest()->getParam('store_view_id');
        $sourceType = (string) $this->getRequest()->getParam('source_type', '');
        $sourceIdentifier = trim((string) $this->getRequest()->getParam('source_identifier', ''));
        $expectedMaxIntervalMinutes = (int) $this->getRequest()->getParam('expected_max_interval_minutes');

        $errors = $this->validator->validate(
            $storeViewId,
            $sourceType,
            $sourceIdentifier,
            $expectedMaxIntervalMinutes
        );

        if ($errors !== []) {
            return $result->setData(['success' => false, 'errors' => $errors]);
        }

        $this->configRepository->save(new IntegrationHealthConfig(
            storeViewId: $storeViewId,
            sourceType: $sourceType,
            sourceIdentifier: $sourceIdentifier,
            expectedMaxIntervalMinutes: $expectedMaxIntervalMinutes,
        ));

        return $result->setData(['success' => true]);
    }
}
