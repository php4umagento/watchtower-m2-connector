<?php
/**
 * Copyright © 2026 Watchtower. All rights reserved.
 * Licensed under the Business Source License 1.1 (BUSL-1.1). See LICENSE.
 */

declare(strict_types=1);

namespace Watchtower\Connector\Test\Unit\Model\ApplicationErrors;

use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;
use Watchtower\Connector\Model\AdminAuthFailure\InstallEventCounterRepository;
use Watchtower\Connector\Model\ApplicationErrors\Handler;
use Watchtower\Connector\Model\Config;

/**
 * Monolog 3 (Magento 2.4.8+, LogRecord objects) -- see MonologTwoHandlerTest
 * for the identical suite against Monolog 2's array records.
 */
class MonologThreeHandlerTest extends TestCase
{
    protected function setUp(): void
    {
        if (!class_exists(LogRecord::class)) {
            self::markTestSkipped('Monolog 3 (LogRecord objects) is not the version installed here.');
        }
    }

    public function testCountsAQualifyingExceptionRecord(): void
    {
        $repository = $this->createMock(InstallEventCounterRepository::class);
        $repository->expects(self::once())
            ->method('increment')
            ->with(Handler::EVENT_NAME, self::isInstanceOf(\DateTimeImmutable::class));

        $result = $this->handlerWith($repository)->handle($this->exceptionRecord(Level::Error));

        self::assertFalse($result, 'must never stop bubbling -- exception.log/system.log still need this record');
    }

    public function testWritesNothingWhenTheModuleIsDisabled(): void
    {
        $repository = $this->createMock(InstallEventCounterRepository::class);
        $repository->expects(self::never())->method('increment');

        $this->handlerWith($repository, enabled: false)->handle($this->exceptionRecord(Level::Error));
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

        $this->handlerWith($repository)->handle($this->exceptionRecord(Level::Info));
        $this->handlerWith($repository)->handle($this->exceptionRecord(Level::Debug));
    }

    public function testCountsAtExactlyTheWarningFloor(): void
    {
        $repository = $this->createMock(InstallEventCounterRepository::class);
        $repository->expects(self::once())->method('increment');

        $this->handlerWith($repository)->handle($this->exceptionRecord(Level::Warning));
    }

    public function testIgnoresRecordsWithNoExceptionContext(): void
    {
        $repository = $this->createMock(InstallEventCounterRepository::class);
        $repository->expects(self::never())->method('increment');

        $record = new LogRecord(
            datetime: new \DateTimeImmutable(),
            channel: 'main',
            level: Level::Error,
            message: 'routine error with no exception object attached',
            context: [],
        );

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

        $result = $this->handlerWith($repository)->handle($this->exceptionRecord(Level::Error));

        self::assertFalse($result);
    }

    /**
     * A PHP Error, not an Exception -- the case a `catch (\Exception)` would miss.
     */
    public function testAThrownErrorIsAlsoContained(): void
    {
        $repository = $this->createStub(InstallEventCounterRepository::class);
        $repository->method('increment')->willThrowException(new \TypeError('bad argument'));

        $result = $this->handlerWith($repository)->handle($this->exceptionRecord(Level::Error));

        self::assertFalse($result);
    }

    public function testHandleBatchDelegatesToHandleForEveryRecord(): void
    {
        $repository = $this->createMock(InstallEventCounterRepository::class);
        $repository->expects(self::exactly(2))->method('increment');

        $this->handlerWith($repository)->handleBatch([
            $this->exceptionRecord(Level::Error),
            $this->exceptionRecord(Level::Critical),
        ]);
    }

    public function testIsHandlingReflectsTheLevelFloorOnly(): void
    {
        $handler = $this->handlerWith($this->createStub(InstallEventCounterRepository::class));

        self::assertTrue($handler->isHandling($this->exceptionRecord(Level::Warning)));
        self::assertTrue($handler->isHandling($this->exceptionRecord(Level::Error)));
        self::assertFalse($handler->isHandling($this->exceptionRecord(Level::Info)));
        self::assertFalse($handler->isHandling($this->exceptionRecord(Level::Debug)));
    }

    /**
     * The leak guard. Asserts on what actually crosses the repository
     * boundary -- since that is the boundary the data would have to cross to
     * leak -- rather than merely that the handler "doesn't call getMessage()"
     * (see MonologThreeHandlerSourceSafetyTest for that static check too).
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

        $record = new LogRecord(
            datetime: new \DateTimeImmutable(),
            channel: 'main',
            level: Level::Error,
            message: $secret,
            context: ['exception' => new \RuntimeException($secret)],
        );

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

    private function exceptionRecord(Level $level): LogRecord
    {
        return new LogRecord(
            datetime: new \DateTimeImmutable(),
            channel: 'main',
            level: $level,
            message: 'a module caught and logged an exception',
            context: ['exception' => new \RuntimeException('bench')],
        );
    }
}
