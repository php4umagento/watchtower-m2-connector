<?php
/**
 * Copyright © 2026 Watchtower. All rights reserved.
 * Licensed under the Business Source License 1.1 (BUSL-1.1). See LICENSE.
 */

declare(strict_types=1);

namespace Watchtower\Connector\Test\Unit\Model\AdminAuthFailure;

use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Watchtower\Connector\Model\AdminAuthFailure\AdminAuthFailureObserver;
use Watchtower\Connector\Model\AdminAuthFailure\InstallEventCounterRepository;
use Watchtower\Connector\Model\Config;

class AdminAuthFailureObserverTest extends TestCase
{
    public function testCountsAFailure(): void
    {
        $repository = $this->createMock(InstallEventCounterRepository::class);
        $repository->expects(self::once())
            ->method('increment')
            ->with(AdminAuthFailureObserver::EVENT_NAME, self::isInstanceOf(\DateTimeImmutable::class));

        $this->observerWith($repository)->execute($this->failureEvent());
    }

    public function testWritesNothingWhenTheModuleIsDisabled(): void
    {
        $repository = $this->createMock(InstallEventCounterRepository::class);
        $repository->expects(self::never())->method('increment');

        $this->observerWith($repository, enabled: false)->execute($this->failureEvent());
    }

    /**
     * This event fires from inside a catch block in Auth::login() that
     * re-throws the real authentication failure afterward. If the counter
     * write itself throws, the admin must still see the real error, not one
     * from this module. Magento's event invoker has no try/catch, so
     * without the wrapper this would propagate and replace it.
     */
    public function testAFailingCounterWriteIsLoggedAndSwallowed(): void
    {
        $repository = $this->createStub(InstallEventCounterRepository::class);
        $repository->method('increment')->willThrowException(new \RuntimeException('database is down'));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('critical')->with(self::isInstanceOf(\RuntimeException::class));

        (new AdminAuthFailureObserver($repository, $this->config(true), $logger))->execute($this->failureEvent());
    }

    /**
     * A PHP Error, not an Exception -- the case a `catch (\Exception)`
     * would miss.
     */
    public function testAThrownErrorIsAlsoContained(): void
    {
        $repository = $this->createStub(InstallEventCounterRepository::class);
        $repository->method('increment')->willThrowException(new \TypeError('bad argument'));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('critical');

        (new AdminAuthFailureObserver($repository, $this->config(true), $logger))->execute($this->failureEvent());
    }

    /**
     * The leak guard. backend_auth_user_login_failed carries 'user_name'
     * (the attempted login identity -- personal data, and exactly what an
     * attacker would want) and 'exception'. Asserts on what actually
     * crosses the repository boundary, since that is the boundary the data
     * would have to cross to leak: only a count and the fixed Magento event
     * name may pass it.
     */
    public function testTheUsernameAndExceptionAreNeverReadOrForwardedToStorage(): void
    {
        $secret = 'admin_jsmith';

        $recorded = [];
        $repository = $this->createStub(InstallEventCounterRepository::class);
        $repository->method('increment')->willReturnCallback(
            static function (string $eventName) use (&$recorded): void {
                $recorded[] = $eventName;
            }
        );

        $exception = new \Magento\Framework\Exception\LocalizedException(__('invalid credentials for %1', $secret));
        $observer = new Observer(['event' => new Event([
            'user_name' => $secret,
            'exception' => $exception,
        ])]);

        $this->observerWith($repository)->execute($observer);

        foreach ($recorded as $value) {
            self::assertStringNotContainsString($secret, (string) $value);
        }

        self::assertSame(['backend_auth_user_login_failed'], $recorded);
    }

    private function observerWith(
        InstallEventCounterRepository $repository,
        bool $enabled = true
    ): AdminAuthFailureObserver {
        return new AdminAuthFailureObserver(
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

    private function failureEvent(): Observer
    {
        return new Observer(['event' => new Event([
            'user_name' => 'admin',
            'exception' => new \RuntimeException('invalid credentials'),
        ])]);
    }
}
