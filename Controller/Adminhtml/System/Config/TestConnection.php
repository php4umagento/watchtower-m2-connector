<?php

declare(strict_types=1);

namespace Watchtower\Connector\Controller\Adminhtml\System\Config;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Watchtower\Connector\Model\Api\PingService;
use Watchtower\Connector\Model\Config;

/**
 * Backs the "Test Connection" button. Deliberately tests the saved config
 * (Model\Config), never unsaved form input: the API key field is
 * type="obscure", so its live DOM value after a save is a masked placeholder
 * rather than the real key. Merchants save first, then test.
 */
class TestConnection extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Watchtower_Connector::config_watchtower';

    /**
     * @param Context $context
     * @param PingService $pingService
     * @param Config $config
     * @param JsonFactory $resultJsonFactory
     */
    public function __construct(
        Context $context,
        private readonly PingService $pingService,
        private readonly Config $config,
        private readonly JsonFactory $resultJsonFactory
    ) {
        parent::__construct($context);
    }

    /**
     * Pings the platform with the saved config and reports reachability, key validity, and clock skew.
     *
     * @return Json
     */
    public function execute(): Json
    {
        $result = $this->resultJsonFactory->create();

        if (!$this->config->isConfigured()) {
            return $result->setData([
                'success' => false,
                'errorMessage' => __('Save a base URL and API key before testing the connection.')->render(),
            ]);
        }

        // Deliberately NOT gated on isEnabled(), same reasoning as
        // Console\Command\PingCommand: this is a read-only diagnostic, and a
        // merchant troubleshooting a disabled connector needs it to work.
        $ping = $this->pingService->ping($this->config->baseUrl(), $this->config->apiKey());

        if (!$ping->reachable) {
            return $result->setData([
                'success' => false,
                'errorMessage' => __(
                    'Could not reach %1: %2',
                    $this->config->baseUrl(),
                    $ping->errorMessage
                )->render(),
            ]);
        }

        if (!$ping->keyValid()) {
            return $result->setData([
                'success' => false,
                'errorMessage' => __(
                    'Reached Watchtower but the API key was rejected (HTTP %1).',
                    $ping->httpStatus
                )->render(),
            ]);
        }

        return $result->setData([
            'success' => true,
            'organizationPaused' => $ping->organizationPaused,
            'alertingEnabled' => $ping->alertingEnabled,
            'entitledSignals' => $ping->entitledSignals,
            'serverTime' => $ping->serverTime,
            'clockSkewSeconds' => $ping->clockSkewSeconds(),
        ]);
    }
}
