<?php
/**
 * Copyright © 2026 Watchtower. All rights reserved.
 * Licensed under the Business Source License 1.1 (BUSL-1.1). See LICENSE.
 */

declare(strict_types=1);

namespace Watchtower\Connector\Test\Unit\Model\EventCounter;

use Magento\Framework\Event;
use Magento\Quote\Api\Data\CartInterface;
use Magento\Framework\Event\Observer;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Watchtower\Connector\Model\Config;
use Watchtower\Connector\Model\EventCounter\CheckoutFailureObserver;
use Watchtower\Connector\Model\EventCounter\EventCounterRepository;

class CheckoutFailureObserverTest extends TestCase
{
    private const STORE_VIEW_ID = 7;

    public function testCountsAFailureAgainstTheQuotesStoreView(): void
    {
        $repository = $this->createMock(EventCounterRepository::class);
        $repository->expects(self::once())
            ->method('increment')
            ->with(
                self::STORE_VIEW_ID,
                CheckoutFailureObserver::EVENT_NAME,
                self::isInstanceOf(\DateTimeImmutable::class)
            );
        $repository->expects(self::never())->method('incrementDropped');

        $this->observerWith($repository)->execute($this->failureEvent(self::STORE_VIEW_ID));
    }

    /**
     * The order in the payload was never saved, so its store id is not the
     * source of record even when populated. Attribution must follow the quote,
     * which Magento's schema declares NOT NULL.
     */
    public function testAttributionFollowsTheQuoteNotTheUnsavedOrder(): void
    {
        $repository = $this->createMock(EventCounterRepository::class);
        $repository->expects(self::once())
            ->method('increment')
            ->with(self::STORE_VIEW_ID, self::anything(), self::anything());

        $observer = new Observer(['event' => new Event([
            'quote' => $this->quoteInStore(self::STORE_VIEW_ID),
            'order' => $this->quoteInStore(99),
            'exception' => new \RuntimeException('gateway declined'),
        ])]);

        $this->observerWith($repository)->execute($observer);
    }

    /**
     * Admin scope is not a storefront. Counting it against store view 0 would
     * be a wrong attribution, which the platform cannot detect after the fact;
     * a dropped count it can surface.
     */
    public function testAdminScopeIsDroppedRatherThanAttributedToAFallbackStore(): void
    {
        $repository = $this->createMock(EventCounterRepository::class);
        $repository->expects(self::never())->method('increment');
        $repository->expects(self::once())
            ->method('incrementDropped')
            ->with(CheckoutFailureObserver::EVENT_NAME, self::isInstanceOf(\DateTimeImmutable::class));

        $this->observerWith($repository)->execute($this->failureEvent(0));
    }

    public function testAPayloadWithNoQuoteIsDroppedRatherThanGuessed(): void
    {
        $repository = $this->createMock(EventCounterRepository::class);
        $repository->expects(self::never())->method('increment');
        $repository->expects(self::once())->method('incrementDropped');

        $observer = new Observer(['event' => new Event(['exception' => new \RuntimeException('boom')])]);

        $this->observerWith($repository)->execute($observer);
    }

    public function testWritesNothingWhenTheModuleIsDisabled(): void
    {
        $repository = $this->createMock(EventCounterRepository::class);
        $repository->expects(self::never())->method('increment');
        $repository->expects(self::never())->method('incrementDropped');

        $this->observerWith($repository, enabled: false)->execute($this->failureEvent(self::STORE_VIEW_ID));
    }

    /**
     * The whole reason the body is wrapped. This event fires during a checkout
     * that is already failing; if the counter write throws, the shopper's
     * error must stay the merchant's error, not ours. Magento's event invoker
     * has no try/catch, so without the wrapper this would propagate.
     */
    public function testAFailingCounterWriteIsLoggedAndSwallowedRatherThanBreakingCheckout(): void
    {
        $repository = $this->createStub(EventCounterRepository::class);
        $repository->method('increment')->willThrowException(new \RuntimeException('database is down'));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('critical')->with(self::isInstanceOf(\RuntimeException::class));

        $observer = new CheckoutFailureObserver($repository, $this->config(true), $logger);

        $observer->execute($this->failureEvent(self::STORE_VIEW_ID));
    }

    /**
     * A PHP Error, not an Exception -- the case a `catch (\Exception)` would
     * miss and a refactor is most likely to introduce.
     */
    public function testAThrownErrorIsAlsoContained(): void
    {
        $repository = $this->createStub(EventCounterRepository::class);
        $repository->method('increment')->willThrowException(new \TypeError('bad argument'));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('critical');

        (new CheckoutFailureObserver($repository, $this->config(true), $logger))
            ->execute($this->failureEvent(self::STORE_VIEW_ID));
    }

    /**
     * Leak guard for the most sensitive payload this module touches. Magento
     * exception messages routinely carry gateway responses, email addresses
     * and billing details, so only a count may leave this class. Asserts on
     * the arguments actually handed to the repository rather than on the
     * source, since this is the boundary the data would cross.
     */
    public function testTheExceptionIsNeverReadOrForwardedToStorage(): void
    {
        $secret = 'card 4111111111111111 declined for buyer@example.com';

        $recorded = [];
        $repository = $this->createStub(EventCounterRepository::class);
        $repository->method('increment')->willReturnCallback(
            static function (int $storeViewId, string $eventName) use (&$recorded): void {
                $recorded[] = $storeViewId;
                $recorded[] = $eventName;
            }
        );

        $observer = new Observer(['event' => new Event([
            'quote' => $this->quoteInStore(self::STORE_VIEW_ID),
            'exception' => new \RuntimeException($secret),
        ])]);

        $this->observerWith($repository)->execute($observer);

        foreach ($recorded as $value) {
            self::assertStringNotContainsString('4111111111111111', (string) $value);
            self::assertStringNotContainsString('buyer@example.com', (string) $value);
        }

        // The event name is the Magento event, not anything derived from the
        // exception's class or message.
        self::assertSame(
            [self::STORE_VIEW_ID, 'sales_model_service_quote_submit_failure'],
            $recorded
        );
    }

    private function observerWith(EventCounterRepository $repository, bool $enabled = true): CheckoutFailureObserver
    {
        return new CheckoutFailureObserver(
            $repository,
            $this->config($enabled),
            $this->createStub(LoggerInterface::class)
        );
    }

    private function config(bool $enabled): Config
    {
        $config = $this->createStub(Config::class);
        $config->method('isEnabled')->willReturn($enabled);

        return $config;
    }

    /**
     * A real CartInterface double rather than a bare DataObject: Magento
     * models answer getStoreId() through __call, so a DataObject satisfies
     * neither the interface check nor a reflection-based one.
     */
    private function quoteInStore(int $storeViewId): CartInterface
    {
        $quote = $this->createStub(CartInterface::class);
        $quote->method('getStoreId')->willReturn($storeViewId);

        return $quote;
    }

    private function failureEvent(int $storeViewId): Observer
    {
        return new Observer(['event' => new Event([
            'quote' => $this->quoteInStore($storeViewId),
            'exception' => new \RuntimeException('placement failed'),
        ])]);
    }
}
