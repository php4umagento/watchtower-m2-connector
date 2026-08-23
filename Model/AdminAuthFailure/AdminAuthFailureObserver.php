<?php
/**
 * Copyright © 2026 Watchtower. All rights reserved.
 * Licensed under the Business Source License 1.1 (BUSL-1.1). See LICENSE.
 */

declare(strict_types=1);

namespace Watchtower\Connector\Model\AdminAuthFailure;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Psr\Log\LoggerInterface;
use Watchtower\Connector\Model\Config;

/**
 * Counts failed Magento admin sign-ins for the admin_auth_failure signal
 * (connector-metrics-spec.md v2.8, "Threshold-Based Signals").
 *
 * WHAT THIS COUNTS. Magento dispatches backend_auth_user_login_failed from
 * two places: Backend\Model\Auth::login() (password auth) and
 * AdminAdobeIms\Model\Auth::login() (Adobe IMS SSO, only present when that
 * feature is configured). Both dispatch the identical event name with the
 * identical payload shape, so one observer catches both.
 *
 * INSTALL-SCOPED, NOT STORE-VIEW-SCOPED. The Magento admin panel is one per
 * installation. The event carries no store context at all -- unlike
 * CheckoutFailureObserver, there is no quote or order to read a store_id
 * from -- so there is nothing to resolve and nothing to drop. Every dispatch
 * counts.
 *
 * WHAT THIS MUST NEVER READ. The payload carries 'user_name' (the attempted
 * login identity -- personal data, and precisely what an attacker would
 * want) and 'exception'. This observer reads neither. A merchant
 * investigating a real attack has Magento's own admin action log for detail;
 * the platform only ever needs to know that failures crossed a threshold in
 * some hour. Same discipline as CheckoutFailureObserver, verified the same
 * way: a leak test asserts only a count crosses the repository boundary.
 */
class AdminAuthFailureObserver implements ObserverInterface
{
    /**
     * The Magento event this counts, reused as the counter's event_name so
     * watchtower_install_event_counter stays literal about what was
     * observed. Shared with the evaluator that reads it back.
     */
    public const EVENT_NAME = 'backend_auth_user_login_failed';

    /**
     * @param InstallEventCounterRepository $repository
     * @param Config $config
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly InstallEventCounterRepository $repository,
        private readonly Config $config,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Increments the failure counter.
     *
     * Deliberately ignores every field on the dispatched event -- see this
     * class's docblock for why.
     *
     * The whole body is wrapped: Magento\Framework\Event\Invoker\InvokerDefault
     * has no try/catch of its own, and this fires from inside a catch block
     * in Auth::login() that re-throws afterward -- an uncaught throw here
     * would replace the real authentication failure the admin is about to
     * see with an unrelated error from this module.
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

            $this->repository->increment(self::EVENT_NAME, new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
        } catch (\Throwable $e) {
            $this->logger->critical($e);
        }
    }
}
