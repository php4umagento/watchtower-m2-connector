<?php
/**
 * Copyright © 2026 Watchtower. All rights reserved.
 * Licensed under the Business Source License 1.1 (BUSL-1.1). See LICENSE.
 */

declare(strict_types=1);

namespace Watchtower\Connector\Test\Unit\Controller\Adminhtml\IntegrationHealth;

use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\View\Result\Redirect;
use Magento\Backend\Model\View\Result\RedirectFactory;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Message\ManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Watchtower\Connector\Model\IntegrationHealth\IntegrationHealthEventRepository;
use Watchtower\Connector\Controller\Adminhtml\IntegrationHealth\Save;
use Watchtower\Connector\Model\CronJobObservation\Cadence;
use Watchtower\Connector\Model\IntegrationHealth\DiscoveredIntegration;
use Watchtower\Connector\Model\IntegrationHealth\DiscoveredJob;
use Watchtower\Connector\Model\IntegrationHealth\IntegrationDiscovery;
use Watchtower\Connector\Model\IntegrationHealth\WatchedIntegrationRepository;

class SaveTest extends TestCase
{
    /**
     * @var array<int, array{0: string[], 1: string[]}>
     */
    private array $saved = [];

    /**
     * @var string[] event labels the last save persisted
     */
    private array $savedEvents = [];

    /**
     * @var ManagerInterface
     */
    private ManagerInterface $messageManager;

    protected function setUp(): void
    {
        $this->saved = [];
        $this->savedEvents = [];
        $this->messageManager = $this->createStub(ManagerInterface::class);
    }

    /**
     * The picker only ever offers labels the connector has really seen
     * dispatched, and the controller enforces that rather than trusting the
     * post. A label that was never dispatched would sit in the watched set
     * forever matching nothing, which is the silent-failure this whole
     * redesign exists to remove.
     */
    public function testAnEventLabelThatWasNeverDispatchedIsRejected(): void
    {
        $this->execute(
            events: ['acme_erp', 'typo_erp'],
            observedEvents: ['acme_erp' => 12]
        );

        self::assertSame(['acme_erp'], $this->savedEvents);
    }

    /**
     * Swaps the stubbed message manager for one this test asserts against.
     *
     * @return ManagerInterface&MockObject
     */
    private function expectMessages(): ManagerInterface&MockObject
    {
        $mock = $this->createMock(ManagerInterface::class);
        $this->messageManager = $mock;

        return $mock;
    }

    public function testPersistsTheTickedIntegrationsAndJobCodes(): void
    {
        $this->execute(
            modules: ['Ebizmarts_MailChimp'],
            jobCodes: ['avalon_conditions']
        );

        self::assertSame([[['Ebizmarts_MailChimp'], ['avalon_conditions']]], $this->saved);
    }

    /**
     * The whole watched set is replaced on every submit, so unticking
     * everything has to be storable rather than read as "nothing submitted,
     * leave it alone".
     */
    public function testAnEmptySubmissionClearsTheWatchedSet(): void
    {
        $this->execute();

        self::assertSame([[[], []]], $this->saved);
    }

    /**
     * A hand-rolled POST is the only way to reach this, and the connector's
     * own jobs are the case that matters: discovery excludes them because a
     * signal sourced from the job that reports it is circular.
     */
    public function testIgnoresAnythingDiscoveryDoesNotOffer(): void
    {
        $this->expectMessages()->expects(self::once())
            ->method('addWarningMessage')
            ->with('2 selections were ignored because they are no longer installed.');

        $this->execute(
            modules: ['Ebizmarts_MailChimp', 'Made_Up'],
            jobCodes: ['watchtower_report', 'avalon_conditions']
        );

        self::assertSame([[['Ebizmarts_MailChimp'], ['avalon_conditions']]], $this->saved);
    }

    public function testWordsTheWarningForASingleIgnoredSelection(): void
    {
        $this->expectMessages()->expects(self::once())
            ->method('addWarningMessage')
            ->with('One selection was ignored because it is no longer installed.');

        $this->execute(modules: ['Made_Up']);
    }

    public function testDoesNotWarnWhenEverythingSubmittedWasOffered(): void
    {
        $this->expectMessages()->expects(self::never())->method('addWarningMessage');

        $this->execute(modules: ['Ebizmarts_MailChimp']);
    }

    /**
     * The unattributed bucket has no module name to store, so a submission
     * naming it must not put an empty string into the watched set.
     */
    public function testNeverStoresAnEmptyModuleName(): void
    {
        $this->execute(modules: ['', 'Ebizmarts_MailChimp']);

        self::assertSame([[['Ebizmarts_MailChimp'], []]], $this->saved);
    }

    public function testSendsTheMerchantBackToTheChecklist(): void
    {
        $redirect = $this->createMock(Redirect::class);
        $redirect->expects(self::once())
            ->method('setPath')
            ->with('watchtower/integrationhealth/index')
            ->willReturnSelf();

        $this->execute(redirect: $redirect);
    }

    /**
     * @param string[] $modules
     * @param string[] $jobCodes
     * @param Redirect|null $redirect
     * @return void
     */
    private function execute(
        array $modules = [],
        array $jobCodes = [],
        ?Redirect $redirect = null,
        array $events = [],
        array $observedEvents = []
    ): void {
        $request = $this->createStub(RequestInterface::class);
        $request->method('getParam')->willReturnCallback(
            static fn (string $key, $default = null) => match ($key) {
                'watched_modules' => $modules,
                'watched_jobs' => $jobCodes,
                'watched_events' => $events,
                default => $default,
            }
        );

        $redirect ??= $this->createStub(Redirect::class);
        $redirectFactory = $this->createStub(RedirectFactory::class);
        $redirectFactory->method('create')->willReturn($redirect);

        $context = $this->createStub(Context::class);
        $context->method('getRequest')->willReturn($request);
        $context->method('getMessageManager')->willReturn($this->messageManager);
        $context->method('getResultRedirectFactory')->willReturn($redirectFactory);

        $discovery = $this->createStub(IntegrationDiscovery::class);
        $discovery->method('discover')->willReturn($this->offered());

        $controller = new Save(
            $context,
            $discovery,
            $this->watchedRepository(),
            $this->eventRepository($observedEvents)
        );
        $controller->execute();
    }

    /**
     * Records what was saved instead of asserting inline, so each test can
     * state its own expectation about the stored set.
     *
     * @return WatchedIntegrationRepository
     */
    private function watchedRepository(): WatchedIntegrationRepository
    {
        $repository = $this->createStub(WatchedIntegrationRepository::class);
        $repository->method('save')->willReturnCallback(
            function (array $moduleNames, array $jobCodes, ?array $eventLabels = null): void {
                $this->saved[] = [$moduleNames, $jobCodes];
                $this->savedEvents = $eventLabels ?? [];
            }
        );

        return $repository;
    }

    /**
     * @return DiscoveredIntegration[]
     */
    private function offered(): array
    {
        $cadence = new Cadence(
            periodSeconds: 300,
            thresholdSeconds: 3600,
            isConfident: true,
            isRegular: true,
            sampleCount: 20,
            observedRunCount: 240,
        );

        return [
            new DiscoveredIntegration(
                moduleName: 'Ebizmarts_MailChimp',
                vendorLabel: 'Mailchimp',
                packageName: 'mailchimp/mc-magento2',
                isThirdParty: true,
                jobs: [new DiscoveredJob('ebizmarts_ecommerce', '*/5 * * * *', $cadence)],
                consumerNames: [],
            ),
            new DiscoveredIntegration(
                moduleName: IntegrationDiscovery::UNATTRIBUTED_MODULE,
                vendorLabel: 'Other scheduled jobs',
                packageName: null,
                isThirdParty: true,
                jobs: [new DiscoveredJob('avalon_conditions', null, $cadence)],
                consumerNames: [],
            ),
        ];
    }

    /**
     * An event repository offering the labels the tests may select from.
     *
     * @param array<string,int> $observed
     * @return IntegrationHealthEventRepository
     */
    private function eventRepository(array $observed = []): IntegrationHealthEventRepository
    {
        $repository = $this->createStub(IntegrationHealthEventRepository::class);
        $repository->method('observedLabels')->willReturn($observed);

        return $repository;
    }
}
