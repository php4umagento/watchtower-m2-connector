<?php
/**
 * Copyright © 2026 Watchtower. All rights reserved.
 * Licensed under the Business Source License 1.1 (BUSL-1.1). See LICENSE.
 */

declare(strict_types=1);

namespace Watchtower\Connector\Model\Signal;

use Watchtower\Connector\Model\EventCounter\EventCounterRepository;

/**
 * customer_account's whole value: registrations, logins, and logouts summed
 * for one store view and hour.
 *
 * This is the only category with a mixed source (connector-signal-sourcing.md
 * D1/D4). Registrations come from customer_entity, which is immutable and
 * readable retroactively. Logins and logouts come from the event bus, because
 * customer_log cannot serve them -- it holds one row per customer, overwritten
 * on every login, with no store_id, so it can answer "who logged in this hour"
 * but neither backfills nor attributes to a store view.
 *
 * The consequence of that mix is that the category as a whole is NOT
 * seedable, and HistorySeeder deliberately declines to seed it. Seeding the
 * registrations term alone would leave every live hour compared against a
 * baseline missing the login term, which on any real store is the larger of
 * the two by a wide margin (a customer registers once and logs in
 * repeatedly). That is a permanent structural spike rather than a warm-up,
 * so partial seeding is worse than none.
 *
 * Two known distortions in the event-sourced terms, both accepted because a
 * baseline built from this store's own history absorbs a systematic bias so
 * long as it is stationary, and both of these are:
 *
 * - customer_login is also dispatched by Integration\Model\CustomerTokenService
 *   on REST token issuance, so on a headless store some "logins" are token
 *   refreshes.
 * - customer_logout is dispatched only from Customer\Model\Session::logout(),
 *   so sessions that simply expire are never counted.
 *
 * Neither may be used to compute logins minus logouts; that difference is
 * unbounded and meaningless. They are only ever summed alongside
 * registrations into a single per-hour occurrence count.
 */
class CustomerAccountReader implements RateSignalReaderInterface
{
    /**
     * Event-bus names contributing to this category, matching the bindings
     * in etc/events.xml that CustomerSessionObserver counts.
     */
    private const EVENT_NAMES = ['customer_login', 'customer_logout'];

    /**
     * @param CustomerAccountRegistrationReader $registrationReader
     * @param EventCounterRepository $eventCounterRepository
     */
    public function __construct(
        private readonly CustomerAccountRegistrationReader $registrationReader,
        private readonly EventCounterRepository $eventCounterRepository
    ) {
    }

    /**
     * Registrations plus logins plus logouts for one store view and window.
     *
     * The window is always exactly one top-of-hour bucket (ReportingService
     * evaluates the last complete hour), which is what lets the hour-bucketed
     * event counters be added to the timestamp-ranged registration count
     * without either double-counting or dropping an event. $windowStart is
     * passed to the counters as the bucket identity; countFor() compares only
     * its UTC top-of-hour.
     *
     * @param int $storeViewId
     * @param \DateTimeImmutable $windowStart inclusive
     * @param \DateTimeImmutable $windowEnd exclusive
     * @return int
     */
    public function countForWindow(
        int $storeViewId,
        \DateTimeImmutable $windowStart,
        \DateTimeImmutable $windowEnd
    ): int {
        $total = $this->registrationReader->countForWindow($storeViewId, $windowStart, $windowEnd);

        foreach (self::EVENT_NAMES as $eventName) {
            $total += $this->eventCounterRepository->countFor($storeViewId, $eventName, $windowStart);
        }

        return $total;
    }
}
