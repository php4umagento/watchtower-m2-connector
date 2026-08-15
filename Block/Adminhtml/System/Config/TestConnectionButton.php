<?php

declare(strict_types=1);

namespace Watchtower\Connector\Block\Adminhtml\System\Config;

use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;

/**
 * Renders the "Test Connection" button on the Watchtower config page.
 * Presentation-only field; no backing config value, so scope controls are
 * stripped from the underlying form element.
 */
class TestConnectionButton extends Field
{
    /**
     * Assigns the button template.
     *
     * @return $this
     */
    protected function _prepareLayout()
    {
        parent::_prepareLayout();
        $this->setTemplate('Watchtower_Connector::system/config/testconnection.phtml');

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
     * Renders the button via its own template rather than a default form-field markup.
     *
     * @param AbstractElement $element
     * @return string
     */
    protected function _getElementHtml(AbstractElement $element)
    {
        return $this->_toHtml();
    }

    /**
     * URL the button's JS calls to run the connectivity test.
     *
     * @return string
     */
    public function getAjaxUrl(): string
    {
        return $this->getUrl('watchtower/system_config/testconnection');
    }

    /**
     * DOM id the button's JS binds its click handler to.
     *
     * @return string
     */
    public function getButtonHtmlId(): string
    {
        return 'watchtower_test_connection';
    }
}
