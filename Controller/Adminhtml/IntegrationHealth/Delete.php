<?php

declare(strict_types=1);

namespace Watchtower\Connector\Controller\Adminhtml\IntegrationHealth;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Watchtower\Connector\Model\IntegrationHealth\IntegrationHealthConfigRepository;

/**
 * Clears one store view's integration_health source, returning that store
 * view to the "signal not evaluated" state.
 */
class Delete extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Watchtower_Connector::integration_health';

    /**
     * @param Context $context
     * @param IntegrationHealthConfigRepository $configRepository
     * @param JsonFactory $resultJsonFactory
     */
    public function __construct(
        Context $context,
        private readonly IntegrationHealthConfigRepository $configRepository,
        private readonly JsonFactory $resultJsonFactory
    ) {
        parent::__construct($context);
    }

    /**
     * Deletes the configured source for the submitted store view.
     *
     * @return Json
     */
    public function execute(): Json
    {
        $this->configRepository->delete((int) $this->getRequest()->getParam('store_view_id'));

        return $this->resultJsonFactory->create()->setData(['success' => true]);
    }
}
