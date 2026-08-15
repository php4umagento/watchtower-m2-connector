<?php

declare(strict_types=1);

namespace Watchtower\Connector\Controller\Adminhtml\IntegrationHealth;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\View\Result\Page;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\View\Result\PageFactory;

/**
 * Renders the integration_health source picker: one row per live store view,
 * each choosing at most one source for the integration_health signal.
 */
class Index extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'Watchtower_Connector::integration_health';

    /**
     * @param Context $context
     * @param PageFactory $resultPageFactory
     */
    public function __construct(
        Context $context,
        private readonly PageFactory $resultPageFactory
    ) {
        parent::__construct($context);
    }

    /**
     * Builds the picker page.
     *
     * @return Page
     */
    public function execute(): Page
    {
        /** @var Page $resultPage */
        $resultPage = $this->resultPageFactory->create();
        $resultPage->setActiveMenu('Watchtower_Connector::integration_health');
        $resultPage->getConfig()->getTitle()->prepend((string) __('Integration Health Sources'));

        return $resultPage;
    }
}
