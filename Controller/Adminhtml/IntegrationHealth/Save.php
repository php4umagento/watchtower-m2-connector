<?php
/**
 * Copyright © 2026 Watchtower. All rights reserved.
 * Licensed under the Business Source License 1.1 (BUSL-1.1). See LICENSE.
 */

declare(strict_types=1);

namespace Watchtower\Connector\Controller\Adminhtml\IntegrationHealth;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\View\Result\Redirect;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Watchtower\Connector\Model\IntegrationHealth\IntegrationDiscovery;
use Watchtower\Connector\Model\IntegrationHealth\WatchedIntegrationRepository;

/**
 * Replaces the whole watched set from one page-level submit.
 *
 * The submitted set is intersected with what discovery currently offers
 * rather than trusted. That is what keeps a hand-rolled POST from storing the
 * connector's own jobs (discovery excludes them, so watching them would make
 * the signal its own source) or an arbitrary string that would then sit in
 * the watched set forever, matching nothing.
 */
class Save extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Watchtower_Connector::integration_health';

    /**
     * @param Context $context
     * @param IntegrationDiscovery $discovery
     * @param WatchedIntegrationRepository $watchedRepository
     */
    public function __construct(
        Context $context,
        private readonly IntegrationDiscovery $discovery,
        private readonly WatchedIntegrationRepository $watchedRepository
    ) {
        parent::__construct($context);
    }

    /**
     * Persists the ticked integrations and job codes.
     *
     * @return Redirect
     */
    public function execute(): Redirect
    {
        $submittedModules = $this->submitted('watched_modules');
        $submittedJobCodes = $this->submitted('watched_jobs');

        [$offeredModules, $offeredJobCodes] = $this->offered();

        $modules = array_values(array_intersect($submittedModules, $offeredModules));
        $jobCodes = array_values(array_intersect($submittedJobCodes, $offeredJobCodes));

        $this->watchedRepository->save($modules, $jobCodes);

        $this->messageManager->addSuccessMessage((string) __('Saved. We are now watching your selected integrations.'));

        $ignored = count($submittedModules) - count($modules) + count($submittedJobCodes) - count($jobCodes);

        if ($ignored > 0) {
            $this->messageManager->addWarningMessage((string) ($ignored === 1
                ? __('One selection was ignored because it is no longer installed.')
                : __('%1 selections were ignored because they are no longer installed.', $ignored)));
        }

        /** @var Redirect $redirect */
        $redirect = $this->resultRedirectFactory->create();
        $redirect->setPath('watchtower/integrationhealth/index');

        return $redirect;
    }

    /**
     * The submitted values for one checkbox group, as a de-duplicated list of strings.
     *
     * @param string $param
     * @return string[]
     */
    private function submitted(string $param): array
    {
        $values = $this->getRequest()->getParam($param, []);

        if (!is_array($values)) {
            return [];
        }

        $strings = [];

        foreach ($values as $value) {
            if (is_string($value) && $value !== '') {
                $strings[] = $value;
            }
        }

        return array_values(array_unique($strings));
    }

    /**
     * Everything currently selectable: module names, and job codes.
     *
     * @return array{0: string[], 1: string[]}
     */
    private function offered(): array
    {
        $modules = [];
        $jobCodes = [];

        foreach ($this->discovery->discover() as $integration) {
            if ($integration->moduleName !== IntegrationDiscovery::UNATTRIBUTED_MODULE) {
                $modules[] = $integration->moduleName;
            }

            foreach ($integration->jobs as $job) {
                $jobCodes[] = $job->jobCode;
            }
        }

        return [$modules, $jobCodes];
    }
}
