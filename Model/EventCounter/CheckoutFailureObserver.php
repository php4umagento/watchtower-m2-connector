<?php
/**
 * Copyright © 2026 Watchtower. All rights reserved.
 * Licensed under the Business Source License 1.1 (BUSL-1.1). See LICENSE.
 */

declare(strict_types=1);

namespace Watchtower\Connector\Model\EventCounter;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Quote\Api\Data\CartInterface;
use Psr\Log\LoggerInterface;
use Magento\Store\Model\Store;
use Watchtower\Connector\Model\Config;

/**
 * Counts failed order placements for the checkout_failure signal
 * (connector-metrics-spec.md v2.7, "Ratio-Based Signals").
 *
 * Magento's tables record successes. An order placement that throws leaves an
 * active quote and no order row, which is byte-identical to an ordinary
 * abandoned cart, so no table read can distinguish the two. The event bus is
 * the only source for this, which is why this signal gets an observer at all
 * when the rate-based signals deliberately do not.
 *
 * WHAT THIS COUNTS, PRECISELY. Magento dispatches
 * sales_model_service_quote_submit_failure from QuoteManagement::rollbackAddresses(),
 * reached only from submitQuote()'s catch around orderManagement->place().
 * Everything earlier in submitQuote (quote validation, customer preparation,
 * address validation and conversion, order validation) throws without
 * dispatching, and that catch is \Exception rather than \Throwable, so a PHP
 * Error never arrives here either. This is therefore an ORDER PLACEMENT
 * failure count -- payment authorization and capture, inventory reservation at
 * place time -- not "all failed checkouts", and nothing merchant-facing may
 * describe it as the latter.
 *
 * WHY THE QUOTE, NOT THE STORE MANAGER. Unlike CustomerSessionObserver, this
 * reads the store view off the quote in the payload. The quote is a persisted
 * entity whose store_id is NOT NULL in Magento's own schema, so it is always
 * present and is the store the order was actually being placed against. The
 * ambient store context is a weaker answer to the same question, and the order
 * in the payload is useless here: at this point it was never saved.
 *
 * WHAT IT MUST NEVER READ. The payload also carries the 'exception'. Magento
 * exception messages routinely contain gateway responses, email addresses and
 * billing details, so this observer never reads, stores, or forwards it. Only
 * a count leaves this class. LeakTest and CheckoutFailureObserverTest both
 * pin that.
 */
class CheckoutFailureObserver implements ObserverInterface
{
    /**
     * The Magento event this counts, reused as the counter's event_name so
     * watchtower_event_counter stays literal about what was observed rather
     * than storing a name this module invented. Shared with the reader that
     * sums it into the signal.
     */
    public const EVENT_NAME = 'sales_model_service_quote_submit_failure';

    /**
     * @param EventCounterRepository $eventCounterRepository
     * @param Config $config
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly EventCounterRepository $eventCounterRepository,
        private readonly Config $config,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Increments the failure counter for the store view whose checkout failed.
     *
     * The whole body is wrapped: Magento\Framework\Event\Invoker\InvokerDefault
     * has no try/catch of its own, so an uncaught throw here would propagate
     * into a checkout that is ALREADY failing, replacing the merchant's real
     * error with ours and breaking the diagnosis. A monitoring module must
     * never be the reason an incident is harder to read.
     *
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer): void
    {
        try {
            if (!$this->config->isEnabled()) {
                return;
            }

            $storeViewId = $this->resolveStoreViewId($observer);
            $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

            if ($storeViewId === null) {
                $this->eventCounterRepository->incrementDropped(self::EVENT_NAME, $now);

                return;
            }

            $this->eventCounterRepository->increment($storeViewId, self::EVENT_NAME, $now);
        } catch (\Throwable $e) {
            $this->logger->critical($e);
        }
    }

    /**
     * The store view whose checkout failed, or null when unresolvable.
     *
     * The payload must carry a usable quote; anything else is dropped.
     *
     * Admin scope (store id 0) is dropped rather than attributed to a fallback
     * store view, matching CustomerSessionObserver: a miscounted store view is
     * worse than a known-missing one, since the platform can surface a drop
     * count but cannot detect a wrong attribution.
     *
     * @param Observer $observer
     * @return int|null
     */
    private function resolveStoreViewId(Observer $observer): ?int
    {
        $quote = $observer->getEvent()->getData('quote');

        // instanceof CartInterface, not method_exists(): Magento models
        // resolve most getters through DataObject::__call, which
        // method_exists() cannot see, so that check would have silently
        // dropped every real dispatch. CartInterface is what
        // QuoteManagement::rollbackAddresses() is typed to hand us, and it
        // declares getStoreId() explicitly.
        if (!$quote instanceof CartInterface) {
            return null;
        }

        $storeViewId = (int) $quote->getStoreId();

        return $storeViewId !== Store::DEFAULT_STORE_ID ? $storeViewId : null;
    }
}
