<?php

declare(strict_types=1);

namespace Watchtower\Connector\Controller\Adminhtml\IntegrationHealth;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Watchtower\Connector\Model\IntegrationHealth\AvailableSourcesProvider;

/**
 * Feeds the picker's queue_consumer source-identifier dropdowns. Fetched
 * once per page load and shared across every store view row.
 */
class QueueTopics extends Action implements HttpGetActionInterface
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
     * Returns the selectable message-queue topics.
     *
     * @return Json
     */
    public function execute(): Json
    {
        return $this->resultJsonFactory->create()->setData([
            'topics' => $this->availableSourcesProvider->queueTopics(),
        ]);
    }
}
