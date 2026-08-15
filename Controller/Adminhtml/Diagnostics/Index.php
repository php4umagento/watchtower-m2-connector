<?php

declare(strict_types=1);

namespace Watchtower\Connector\Controller\Adminhtml\Diagnostics;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\View\Result\Page;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\View\Result\PageFactory;

/**
 * Renders the diagnostics page: connection state, submission/buffer backlog,
 * per-store-view per-signal status, and recent submission outcomes -- the
 * admin counterpart to watchtower:status, both backed by the shared
 * DiagnosticsSnapshotProvider.
 */
class Index extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'Watchtower_Connector::diagnostics';

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
     * Builds the diagnostics page.
     *
     * @return Page
     */
    public function execute(): Page
    {
        /** @var Page $resultPage */
        $resultPage = $this->resultPageFactory->create();
        $resultPage->setActiveMenu('Watchtower_Connector::diagnostics');
        $resultPage->getConfig()->getTitle()->prepend((string) __('Diagnostics'));

        return $resultPage;
    }
}
