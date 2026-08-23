<?php
/**
 * Copyright © 2026 Watchtower. All rights reserved.
 * Licensed under the Business Source License 1.1 (BUSL-1.1). See LICENSE.
 */

declare(strict_types=1);

namespace Watchtower\Connector\Test\Unit\Model\Signal;

use PHPUnit\Framework\TestCase;
use Watchtower\Connector\Model\EventCounter\EventCounterRepository;
use Watchtower\Connector\Model\Signal\CustomerAccountReader;
use Watchtower\Connector\Model\Signal\CustomerAccountRegistrationReader;

/**
 * customer_account is the only category with a mixed source, so this is the
 * one place proving the table term and both event-bus terms actually reach
 * the reported number. Before this reader existed the login/logout counters
 * were written and pruned but read by nothing, and the category silently
 * reported registrations alone.
 */
class CustomerAccountReaderTest extends TestCase
{
    private const STORE_VIEW_ID = 7;

    public function testSumsRegistrationsAndLoginsAndLogouts(): void
    {
        $reader = $this->buildReader(registrations: 2, countsByEventName: [
            'customer_login' => 5,
            'customer_logout' => 3,
        ]);

        self::assertSame(10, $reader->countForWindow(self::STORE_VIEW_ID, $this->hour(), $this->nextHour()));
    }

    /**
     * The event counters return 0 rather than false for an hour with no
     * activity, so a quiet hour must report the registration count alone and
     * not be mistaken for missing data.
     */
    public function testAQuietHourReportsRegistrationsAlone(): void
    {
        $reader = $this->buildReader(registrations: 4, countsByEventName: []);

        self::assertSame(4, $reader->countForWindow(self::STORE_VIEW_ID, $this->hour(), $this->nextHour()));
    }

    public function testAnHourWithNoActivityAtAllIsZeroRatherThanAnError(): void
    {
        $reader = $this->buildReader(registrations: 0, countsByEventName: []);

        self::assertSame(0, $reader->countForWindow(self::STORE_VIEW_ID, $this->hour(), $this->nextHour()));
    }

    /**
     * The registration reader is range-based and the event counters are
     * hour-bucketed, so the bucket identity handed to the counters must be
     * the window's start. Passing the end would attribute every event to the
     * following hour.
     */
    public function testEventCountersAreKeyedOnTheWindowStartNotItsEnd(): void
    {
        $requestedBuckets = [];

        $registrationReader = $this->createStub(CustomerAccountRegistrationReader::class);
        $registrationReader->method('countForWindow')->willReturn(0);

        $eventCounterRepository = $this->createStub(EventCounterRepository::class);
        $eventCounterRepository->method('countFor')->willReturnCallback(
            static function (
                int $storeViewId,
                string $eventName,
                \DateTimeImmutable $hourBucket
            ) use (&$requestedBuckets): int {
                $requestedBuckets[] = $hourBucket->format('Y-m-d H:i:s');

                return 0;
            }
        );

        (new CustomerAccountReader($registrationReader, $eventCounterRepository))
            ->countForWindow(self::STORE_VIEW_ID, $this->hour(), $this->nextHour());

        self::assertSame(['2026-08-13 15:00:00', '2026-08-13 15:00:00'], $requestedBuckets);
    }

    /**
     * A login on one store view must not inflate another's count -- the
     * whole reason CustomerSessionObserver drops events it cannot attribute
     * rather than filing them under a fallback store.
     */
    public function testCountsAreScopedToTheRequestedStoreView(): void
    {
        $registrationReader = $this->createStub(CustomerAccountRegistrationReader::class);
        $registrationReader->method('countForWindow')->willReturn(0);

        $eventCounterRepository = $this->createStub(EventCounterRepository::class);
        $eventCounterRepository->method('countFor')->willReturnCallback(
            static fn (int $storeViewId, string $eventName): int => $storeViewId === self::STORE_VIEW_ID ? 1 : 99
        );

        $reader = new CustomerAccountReader($registrationReader, $eventCounterRepository);

        self::assertSame(2, $reader->countForWindow(self::STORE_VIEW_ID, $this->hour(), $this->nextHour()));
    }

    /**
     * @param int $registrations
     * @param array<string, int> $countsByEventName
     * @return CustomerAccountReader
     */
    private function buildReader(int $registrations, array $countsByEventName): CustomerAccountReader
    {
        $registrationReader = $this->createStub(CustomerAccountRegistrationReader::class);
        $registrationReader->method('countForWindow')->willReturn($registrations);

        $eventCounterRepository = $this->createStub(EventCounterRepository::class);
        $eventCounterRepository->method('countFor')->willReturnCallback(
            static fn (int $storeViewId, string $eventName): int => $countsByEventName[$eventName] ?? 0
        );

        return new CustomerAccountReader($registrationReader, $eventCounterRepository);
    }

    private function hour(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-08-13T15:00:00+00:00');
    }

    private function nextHour(): \DateTimeImmutable
    {
        return $this->hour()->modify('+1 hour');
    }
}
