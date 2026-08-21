<?php
/**
 * Copyright © 2026 Watchtower. All rights reserved.
 * Licensed under the Business Source License 1.1 (BUSL-1.1). See LICENSE.
 */

declare(strict_types=1);

namespace Watchtower\Connector\Block\Adminhtml\System\Config;

use Magento\Backend\Block\Template\Context;
use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;
use Magento\Store\Model\StoreManagerInterface;
use Watchtower\Connector\Model\StoreView\LocalDomainDetector;

/**
 * Proactive heads-up on the config page when the store's base URL looks
 * local/dev (PRD FR30), before the merchant ever syncs. Advisory only, see
 * LocalDomainDetector. Presentation-only field, same shape as
 * TestConnectionButton.
 */
class LocalDomainNotice extends Field
{
    /**
     * @param Context $context
     * @param StoreManagerInterface $storeManager
     * @param LocalDomainDetector $localDomainDetector
     * @param array $data
     */
    public function __construct(
        Context $context,
        private readonly StoreManagerInterface $storeManager,
        private readonly LocalDomainDetector $localDomainDetector,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /**
     * Assigns the notice template.
     *
     * @return $this
     */
    protected function _prepareLayout()
    {
        parent::_prepareLayout();
        $this->setTemplate('Watchtower_Connector::system/config/localdomainnotice.phtml');

        return $this;
    }

    /**
     * Strips scope controls before rendering, since this field has no backing config value.
     *
     * @param AbstractElement $element
     * @return string
     */
    public function render(AbstractElement $element)
    {
        $element = clone $element;
        $element->unsScope()->unsCanUseWebsiteValue()->unsCanUseDefaultValue();

        return parent::render($element);
    }

    /**
     * Renders the notice via its own template rather than a default form-field markup.
     *
     * @param AbstractElement $element
     * @return string
     */
    protected function _getElementHtml(AbstractElement $element)
    {
        return $this->_toHtml();
    }

    /**
     * Whether the current default store's base URL looks like a local/development environment.
     *
     * Failure-safe: a StoreManager exception (misconfigured store, running
     * during install) hides the notice rather than breaking config page
     * rendering over a purely advisory heads-up.
     *
     * @return bool
     */
    public function shouldShowNotice(): bool
    {
        try {
            /** @var \Magento\Store\Model\Store $store phpstan/psr type widening; StoreInterface has no getBaseUrl() */
            $store = $this->storeManager->getStore();
            $baseUrl = (string) $store->getBaseUrl();
        } catch (\Throwable) {
            return false;
        }

        return $this->localDomainDetector->looksLocal($baseUrl);
    }
}
