<?php
/**
 * Copyright © 2026 Watchtower. All rights reserved.
 * Licensed under the Business Source License 1.1 (BUSL-1.1). See LICENSE.
 */

declare(strict_types=1);

namespace Watchtower\Connector\Model\ApplicationErrors;

use Watchtower\Connector\Model\AdminAuthFailure\InstallEventCounterRepository;
use Watchtower\Connector\Model\Config;

/**
 * Counts logged application exceptions for the application_errors signal
 * (docs/connector-error-log-signal-prd.md, this repo's watchtower-saas
 * sibling).
 *
 * WHAT THIS COUNTS. Registered directly onto Magento\Framework\Logger\Monolog's
 * own handler stack (etc/di.xml), alongside core's Handler\System/Debug/Syslog
 * -- not an events.xml observer like every other signal in this module. Counts
 * one occurrence per Monolog record that both (a) carries exception context
 * (isset($record['context']['exception']) -- the same condition Magento core's
 * own Handler\System::write() uses to route a record to exception.log instead
 * of system.log) and (b) is at Warning level or above, excluding the
 * INFO-level "logged but handled" diagnostic noise Handler\Exception's own
 * $loggerType = Logger::INFO otherwise lets through (PRD Q2).
 *
 * NEVER READS THE FILE. This intercepts each record in memory, at the moment
 * Logger::error()/critical() is called, as one independent branch of the
 * handler stack -- it never opens, reads, or tracks an offset into
 * exception.log. Whatever later rotates, compresses, or truncates that file
 * (or nothing at all) has zero effect on this counter -- see the PRD's §5 and
 * §9.1 for why file-tailing was rejected in the first place.
 *
 * WHAT THIS MUST NEVER READ. The message, the exception's getMessage()/
 * getTraceAsString(), or any other content -- count only (PRD EL13). Verified
 * statically by Test/Unit/Model/ApplicationErrors/HandlerSourceSafetyTest.
 *
 * NEVER THROWS, NEVER LOGS ITS OWN FAILURE THROUGH $logger. Unlike every
 * other signal in this module (which logs a caught \Throwable via
 * LoggerInterface::critical()), this class is itself registered on that same
 * logger's handler stack -- calling back into it from inside handle() would
 * re-enter this same method for the resulting log call. Failures are
 * swallowed silently instead (PRD EL10).
 *
 * NEVER STOPS BUBBLING. handle() always returns false: Handler\System and
 * every other handler in the stack still need to process this same record to
 * write it to exception.log/system.log -- this handler is additive, not a
 * replacement.
 *
 * TWO MONOLOG MAJORS, ONE CLASS NAME, A RUNTIME BRANCH -- NOT TWO FILES.
 * Magento 2.4.7 ships Monolog 2.x, whose HandlerInterface types every method
 * as `array $record`; 2.4.8 and 2.4.9 ship Monolog 3.x, whose
 * HandlerInterface types the same methods as `LogRecord $record`. PHP cannot
 * declare one method with two incompatible parameter types, so a single
 * implementation cannot satisfy both.
 *
 * The first version of this file split the two into separate classes in
 * separate files (MonologThreeHandler.php/MonologTwoHandler.php), selected
 * at runtime via class_alias(). That worked for ordinary autoloading but
 * broke `bin/magento setup:di:compile`, confirmed live: the compiler's
 * repository/proxy generation pass eagerly requires every class file in the
 * module for reflection, regardless of whether anything actually references
 * it, so the "wrong" file's top-level `class ... implements HandlerInterface`
 * declaration fatals immediately on whichever Monolog major happens to be
 * installed where compilation runs -- the class_alias() gate never gets a
 * chance to run first, because nothing routes through Handler.php at all.
 *
 * The fix is the `if`/`else` below. PHP hoists and interface-checks a
 * top-level `class` statement immediately when its file is parsed, but a
 * class declared inside a conditional block is bound only when that branch
 * actually executes -- so the untaken branch's `implements HandlerInterface`
 * is never checked against the currently-loaded interface at all, compiler
 * included. Both branches declare the same class name, so etc/di.xml's
 * reference to Watchtower\Connector\Model\ApplicationErrors\Handler resolves
 * correctly either way with no alias needed.
 */
if (class_exists(\Monolog\LogRecord::class)) {
    // Monolog 3 (Magento 2.4.8+): $record is an immutable Monolog\LogRecord
    // object (level is a Level enum, not a bare int).
    class Handler implements \Monolog\Handler\HandlerInterface
    {
        /**
         * Reused as the counter's event_name (InstallEventCounterRepository),
         * well under MAX_EVENT_NAME_LENGTH (64) -- see that class's docblock
         * for the incident this limit exists because of. Shared with the
         * evaluator that reads it back. Kept identical to the Monolog-2
         * branch's own EVENT_NAME below by hand -- matches this module's own
         * precedent (EventCounterRepository::MAX_EVENT_NAME_LENGTH vs
         * InstallEventCounterRepository::MAX_EVENT_NAME_LENGTH) for two
         * definitions that must agree on a constant but share no common
         * ancestor to hold it (here, because only one branch is ever
         * actually declared).
         */
        public const EVENT_NAME = 'application_error_logged';

        private const MIN_LEVEL = \Monolog\Level::Warning;

        /**
         * @param InstallEventCounterRepository $repository
         * @param Config $config
         */
        public function __construct(
            private readonly InstallEventCounterRepository $repository,
            private readonly Config $config,
        ) {
        }

        /**
         * @inheritDoc
         */
        public function isHandling(\Monolog\LogRecord $record): bool
        {
            return $record->level->value >= self::MIN_LEVEL->value;
        }

        /**
         * @inheritDoc
         */
        public function handle(\Monolog\LogRecord $record): bool
        {
            try {
                if (!$this->config->isEnabled()) {
                    return false;
                }

                if ($record->level->value < self::MIN_LEVEL->value) {
                    return false;
                }

                if (!isset($record->context['exception'])) {
                    return false;
                }

                $this->repository->increment(self::EVENT_NAME, new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
                // phpcs:ignore Magento2.CodeAnalysis.EmptyBlock.DetectedCatch -- swallowed deliberately, not logged, see this class's docblock.
            } catch (\Throwable $e) {
            }

            return false;
        }

        /**
         * @inheritDoc
         *
         * @param \Monolog\LogRecord[] $records
         */
        public function handleBatch(array $records): void
        {
            foreach ($records as $record) {
                $this->handle($record);
            }
        }

        /**
         * @inheritDoc
         */
        // phpcs:ignore Magento2.CodeAnalysis.EmptyBlock.DetectedFunction -- no resources to release; HandlerInterface requires the method.
        public function close(): void
        {
        }
    }
} else {
    // Monolog 2 (Magento 2.4.7): $record is a plain array
    // ($record['level'] a bare int, $record['context']['exception']).
    // phpcs:ignore PSR1.Classes.ClassDeclaration.MultipleClasses,Generic.Classes.DuplicateClassName.Found -- deliberate: this branch and the Monolog 3 branch above declare the same class name conditionally, exactly once each ever actually runs, see this file's own docblock.
    class Handler implements \Monolog\Handler\HandlerInterface
    {
        /** Kept identical to the Monolog-3 branch's own EVENT_NAME above by hand -- see that constant's docblock. */
        public const EVENT_NAME = 'application_error_logged';

        private const MIN_LEVEL = \Monolog\Logger::WARNING;

        /**
         * @param InstallEventCounterRepository $repository
         * @param Config $config
         */
        public function __construct(
            private readonly InstallEventCounterRepository $repository,
            private readonly Config $config,
        ) {
        }

        /**
         * @inheritDoc
         *
         * @param array $record
         */
        public function isHandling(array $record): bool
        {
            return ($record['level'] ?? 0) >= self::MIN_LEVEL;
        }

        /**
         * @inheritDoc
         *
         * @param array $record
         */
        public function handle(array $record): bool
        {
            try {
                if (!$this->config->isEnabled()) {
                    return false;
                }

                if (($record['level'] ?? 0) < self::MIN_LEVEL) {
                    return false;
                }

                if (!isset($record['context']['exception'])) {
                    return false;
                }

                $this->repository->increment(self::EVENT_NAME, new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
                // phpcs:ignore Magento2.CodeAnalysis.EmptyBlock.DetectedCatch -- swallowed deliberately, not logged, see this class's docblock.
            } catch (\Throwable $e) {
            }

            return false;
        }

        /**
         * @inheritDoc
         *
         * @param array[] $records
         */
        public function handleBatch(array $records): void
        {
            foreach ($records as $record) {
                $this->handle($record);
            }
        }

        /**
         * @inheritDoc
         */
        // phpcs:ignore Magento2.CodeAnalysis.EmptyBlock.DetectedFunction -- no resources to release; HandlerInterface requires the method.
        public function close(): void
        {
        }
    }
}
