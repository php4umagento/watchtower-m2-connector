<?php
/**
 * Copyright © 2026 Watchtower. All rights reserved.
 * Licensed under the Business Source License 1.1 (BUSL-1.1). See LICENSE.
 */

declare(strict_types=1);

namespace Watchtower\Connector\Model\QueueHealth;

use Magento\Framework\Amqp\ConfigPool;

/**
 * Reads one AMQP queue's depth and consumer count without consuming from it.
 *
 * Split out of QueueStateObserver so that class can be tested against a stub
 * rather than a four-deep ConfigPool -> Config -> Channel -> Connection chain.
 *
 * Magento's own CheckIsAvailableMessagesInQueue looks like the API for this
 * and must not be used: on AMQP it degrades to basic_get plus reject(requeue),
 * which returns a bare boolean and disturbs a live queue.
 */
class AmqpQueueProbe
{
    /**
     * @param ConfigPool $configPool
     */
    public function __construct(
        private readonly ConfigPool $configPool
    ) {
    }

    /**
     * Whether this queue holds work with no consumer attached.
     *
     * Depth alone is not the test: a bulk import legitimately fills a queue
     * that is draining fine. Sustained-ness comes from the caller's debounce,
     * which matters because Magento's default only_spawn_when_message_available
     * makes consumers short-lived, so a momentary count of 0 is normal idle.
     *
     * @param string $connectionName
     * @param string $queueName
     * @return bool
     */
    public function isUndrained(string $connectionName, string $queueName): bool
    {
        try {
            // Fresh channel per probe: a passive declare against a missing
            // queue raises NOT_FOUND, and AMQP closes the CHANNEL on that, so a
            // shared one would throw for every later queue. Config::getChannel()
            // checks the connection, not the channel, so it does not save us.
            $channel = $this->configPool->get($connectionName)->getChannel()->getConnection()->channel();
        } catch (\Throwable $e) {
            // Broker unreachable or no config. A backend it cannot reach is
            // unmeasured, not measured-as-bad; reporting a fault from an
            // inconclusive read is what ConnectorVersionReader's "never treat
            // unknown as outdated" rule exists to prevent.
            return false;
        }

        try {
            [, $messageCount, $consumerCount] = $channel->queue_declare($queueName, true);

            return (int) $messageCount > 0 && (int) $consumerCount === 0;
        } catch (\Throwable $e) {
            // NOT_FOUND, overwhelmingly: a queue this install does not use has
            // nothing to fall behind on, as with a missing changelog table.
            return false;
        } finally {
            // Guarded rather than wrapped: a failed passive declare has already
            // closed the channel, and close() on a closed one throws.
            if ($channel->is_open()) {
                $channel->close();
            }
        }
    }
}
