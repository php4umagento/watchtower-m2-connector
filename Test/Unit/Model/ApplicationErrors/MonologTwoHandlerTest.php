<?php
/**
 * Copyright © 2026 Watchtower. All rights reserved.
 * Licensed under the Business Source License 1.1 (BUSL-1.1). See LICENSE.
 */

declare(strict_types=1);

namespace Watchtower\Connector\Test\Unit\Model\ApplicationErrors;

use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use Watchtower\Connector\Model\AdminAuthFailure\InstallEventCounterRepository;
use Watchtower\Connector\Model\ApplicationErrors\Handler;
use Watchtower\Connector\Model\Config;

/**
 * Monolog 2 (Magento 2.4.7, array records) -- see MonologThreeHandlerTest for
 * the identical suite against Monolog 3's LogRecord objects. This class only
 * runs where monolog/monolog 2.x is actually installed (this module's
 * composer constraint follows whatever Magento pulls in), so it is skipped
 * rather than failed when run against a Monolog 3 environment.
 */
class MonologTwoHandlerTest extends TestCase
{
    protected function setUp(): void
    {
        if (!class_exists(Logger::class) || class_exists(\Monolog\LogRecord::class)) {
            self::markTestSkipped('Monolog 2 (array records) is not the version installed here.');
        }
    }

    public function testCountsAQualifyingExceptionRecord(): void
    {
        $repository = $this->createMock(InstallEventCounterRepository::class);
        $repository->expects(self::once())
            ->method('increment')
            ->with(Handler::EVENT_NAME, self::isInstanceOf(\DateTimeImmutable::class));

        $result = $this->handlerWith($repository)->handle($this->exceptionRecord(Logger::ERROR));

        self::assertFalse($result, 'must never stop bubbling -- exception.log/system.log still need this record');
    }

    public function testWritesNothingWhenTheModuleIsDisabled(): void
    {
        $repository = $this->createMock(InstallEventCounterRepository::class);
        $repository->expects(self::never())->method('increment');

        $this->handlerWith($repository, enabled: false)->handle($this->exceptionRecord(Logger::ERROR));
    }

    /**
     * PRD Q2: Handler\Exception's own $loggerType = Logger::INFO lets
     * "logged but handled" diagnostic noise through at INFO level. This
     * signal only counts Warning and above.
     */
    public function testIgnoresExceptionRecordsBelowWarningLevel(): void
    {
        $repository = $this->createMock(InstallEventCounterRepository::class);
        $repository->expects(self::never())->method('increment');

        $this->handlerWith($repository)->handle($this->exceptionRecord(Logger::INFO));
        $this->handlerWith($repository)->handle($this->exceptionRecord(Logger::DEBUG));
    }

    public function testCountsAtExactlyTheWarningFloor(): void
    {
        $repository = $this->createMock(InstallEventCounterRepository::class);
        $repository->expects(self::once())->method('increment');

        $this->handlerWith($repository)->handle($this->exceptionRecord(Logger::WARNING));
    }

    public function testIgnoresRecordsWithNoExceptionContext(): void
    {
        $repository = $this->createMock(InstallEventCounterRepository::class);
        $repository->expects(self::never())->method('increment');

        $record = [
            'level' => Logger::ERROR,
            'message' => 'routine error with no exception object attached',
            'context' => [],
        ];

        $this->handlerWith($repository)->handle($record);
    }

    /**
     * A failing counter write must never propagate: this handler sits on the
     * same Monolog\Logger instance it is registered against, so the usual
     * "log the caught \Throwable via LoggerInterface::critical()" pattern
     * every other signal in this module uses would re-enter handle() for the
     * resulting record. Swallow, don't log.
     */
    public function testAFailingCounterWriteIsSwallowedWithoutRethrowing(): void
    {
        $repository = $this->createStub(InstallEventCounterRepository::class);
        $repository->method('increment')->willThrowException(new \RuntimeException('database is down'));

        $result = $this->handlerWith($repository)->handle($this->exceptionRecord(Logger::ERROR));

        self::assertFalse($result);
    }

    /**
     * A PHP Error, not an Exception -- the case a `catch (\Exception)` would miss.
     */
    public function testAThrownErrorIsAlsoContained(): void
    {
        $repository = $this->createStub(InstallEventCounterRepository::class);
        $repository->method('increment')->willThrowException(new \TypeError('bad argument'));

        $result = $this->handlerWith($repository)->handle($this->exceptionRecord(Logger::ERROR));

        self::assertFalse($result);
    }

    public function testHandleBatchDelegatesToHandleForEveryRecord(): void
    {
        $repository = $this->createMock(InstallEventCounterRepository::class);
        $repository->expects(self::exactly(2))->method('increment');

        $this->handlerWith($repository)->handleBatch([
            $this->exceptionRecord(Logger::ERROR),
            $this->exceptionRecord(Logger::CRITICAL),
        ]);
    }

    public function testIsHandlingReflectsTheLevelFloorOnly(): void
    {
        $handler = $this->handlerWith($this->createStub(InstallEventCounterRepository::class));

        self::assertTrue($handler->isHandling($this->exceptionRecord(Logger::WARNING)));
        self::assertTrue($handler->isHandling($this->exceptionRecord(Logger::ERROR)));
        self::assertFalse($handler->isHandling($this->exceptionRecord(Logger::INFO)));
        self::assertFalse($handler->isHandling($this->exceptionRecord(Logger::DEBUG)));
    }

    /**
     * The leak guard. Asserts on what actually crosses the repository
     * boundary -- since that is the boundary the data would have to cross to
     * leak -- rather than merely that the handler "doesn't call getMessage()"
     * (see MonologTwoHandlerSourceSafetyTest for that static check too).
     */
    public function testTheMessageAndExceptionContentAreNeverForwardedToStorage(): void
    {
        $secret = 'customer card token abc123, order #99887';

        $recorded = [];
        $repository = $this->createStub(InstallEventCounterRepository::class);
        $repository->method('increment')->willReturnCallback(
            static function (string $eventName) use (&$recorded): void {
                $recorded[] = $eventName;
            }
        );

        $record = [
            'level' => Logger::ERROR,
            'message' => $secret,
            'context' => ['exception' => new \RuntimeException($secret)],
        ];

        $this->handlerWith($repository)->handle($record);

        foreach ($recorded as $value) {
            self::assertStringNotContainsString($secret, (string) $value);
        }

        self::assertSame([Handler::EVENT_NAME], $recorded);
    }

    private function handlerWith(InstallEventCounterRepository $repository, bool $enabled = true): Handler
    {
        $config = $this->createStub(Config::class);
        $config->method('isEnabled')->willReturn($enabled);

        return new Handler($repository, $config);
    }

    private function exceptionRecord(int $level): array
    {
        return [
            'level' => $level,
            'message' => 'a module caught and logged an exception',
            'context' => ['exception' => new \RuntimeException('bench')],
        ];
    }
}
