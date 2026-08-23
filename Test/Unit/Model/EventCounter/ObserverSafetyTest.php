<?php
/**
 * Copyright © 2026 Watchtower. All rights reserved.
 * Licensed under the Business Source License 1.1 (BUSL-1.1). See LICENSE.
 */

declare(strict_types=1);

namespace Watchtower\Connector\Test\Unit\Model\EventCounter;

use PHPUnit\Framework\TestCase;
use Watchtower\Connector\Model\EventCounter\EventCounterRepository;

/**
 * Static guards on every observer this module registers, in the same spirit
 * as Model/Signal's AggregateOnlyQueryTest: enforced once here for all
 * current AND future observers, rather than relying on whoever adds the next
 * one remembering the rules.
 *
 * Both rules exist because of one fact about Magento:
 * Magento\Framework\Event\Invoker\InvokerDefault contains no try/catch, so an
 * uncaught throw in an observer propagates to whatever dispatched the event
 * and additionally prevents every later-registered observer for that event
 * from running. On the order path that costs the merchant a sale. A
 * monitoring module causing a failed or duplicated order is the one outcome
 * that would make it worse than not being installed.
 */
class ObserverSafetyTest extends TestCase
{
    /**
     * Events an observer must never be bound to, with the reason.
     *
     * The save-family entries are the dangerous ones:
     * Framework\Model\ResourceModel\Db\AbstractDb::save() opens a transaction,
     * calls processAfterSaves() (which dispatches {prefix}_save_after), and
     * only then commits -- so a throwing sales_order_save_after observer
     * rolls the merchant's order back. sales_order_save_after additionally
     * fires many times per order from a dozen call sites, making it useless
     * as a counter even if it were safe.
     */
    private const FORBIDDEN_EVENTS = [
        'sales_order_save_after',
        'sales_order_save_before',
        'sales_quote_save_after',
        'sales_quote_save_before',
        'clean_cache_by_tags',
        'controller_action_predispatch',
        'model_save_after',
    ];

    public function testNoObserverIsBoundToAForbiddenEvent(): void
    {
        foreach (array_keys($this->registeredObservers()) as $eventName) {
            self::assertNotContains(
                $eventName,
                self::FORBIDDEN_EVENTS,
                sprintf(
                    'etc/events.xml binds an observer to "%s", which this module forbids. '
                    . 'See this test\'s FORBIDDEN_EVENTS for why.',
                    $eventName
                )
            );
        }
    }

    /**
     * A blanket catch is the only thing standing between a bug in this module
     * and a broken checkout, so it is verified rather than trusted. Matches
     * \Throwable specifically: catching \Exception would still let a TypeError
     * escape, which is exactly the class of bug a refactor introduces.
     */
    public function testEveryRegisteredObserverCatchesThrowable(): void
    {
        $observers = $this->registeredObservers();
        self::assertNotEmpty($observers, 'Expected etc/events.xml to register at least one observer.');

        foreach ($observers as $eventName => $classNames) {
            foreach ($classNames as $className) {
                $source = $this->sourceFor($className);

                self::assertMatchesRegularExpression(
                    '/catch\s*\(\s*\\\\Throwable\s+\$/',
                    $source,
                    sprintf(
                        '%s observes "%s" but does not catch \\Throwable. Magento\'s event invoker has '
                        . 'no try/catch, so anything this class throws breaks the request that dispatched '
                        . 'the event.',
                        $className,
                        $eventName
                    )
                );
            }
        }
    }

    /**
     * A merchant who switches the module off expects it to stop writing to
     * their database, including on the storefront path.
     */
    public function testEveryRegisteredObserverGatesOnTheEnabledConfig(): void
    {
        foreach ($this->registeredObservers() as $eventName => $classNames) {
            foreach ($classNames as $className) {
                self::assertStringContainsString(
                    'isEnabled()',
                    $this->sourceFor($className),
                    sprintf(
                        '%s observes "%s" but never checks Config::isEnabled(), so it would keep '
                        . 'writing after the merchant disabled the module.',
                        $className,
                        $eventName
                    )
                );
            }
        }
    }

    /**
     * Reads the real etc/events.xml rather than a fixture, so a binding added
     * without a matching safe implementation fails here.
     *
     * @return array<string, list<string>> event name => observer class names
     */
    private function registeredObservers(): array
    {
        $path = dirname(__DIR__, 4) . '/etc/events.xml';
        self::assertFileExists($path);

        $xml = simplexml_load_file($path);
        self::assertNotFalse($xml, 'etc/events.xml is not well-formed XML.');

        $registered = [];

        foreach ($xml->event as $event) {
            $eventName = (string) $event['name'];

            foreach ($event->observer as $observer) {
                $registered[$eventName][] = (string) $observer['instance'];
            }
        }

        return $registered;
    }

    /**
     * @param string $className
     * @return string
     */
    private function sourceFor(string $className): string
    {
        $relative = str_replace('\\', '/', substr($className, strlen('Watchtower\\Connector\\')));
        $path = dirname(__DIR__, 4) . '/' . $relative . '.php';

        self::assertFileExists($path, sprintf('etc/events.xml names %s, which does not exist.', $className));

        return (string) file_get_contents($path);
    }

    /**
     * Every event name this module writes must fit the event_name column.
     *
     * This is a regression test with a real story: the column was
     * varchar(32), CheckoutFailureObserver wrote a 40-character name, and
     * MySQL in its default non-strict mode truncated it silently. The writer
     * then used one key and the reader another, so the signal read zero
     * failures forever and was indistinguishable from a healthy store. Unit
     * tests could not catch it because they mock the repository, and the
     * column width only exists in the database.
     *
     * Reads the schema rather than a constant so the two cannot drift: if
     * someone narrows the column, this fails instead of the signal quietly
     * dying in production.
     */
    public function testEveryObservedEventNameFitsTheEventNameColumn(): void
    {
        $declaredWidth = $this->eventNameColumnWidth();

        self::assertSame(
            EventCounterRepository::MAX_EVENT_NAME_LENGTH,
            $declaredWidth,
            'EventCounterRepository::MAX_EVENT_NAME_LENGTH must match the event_name column in db_schema.xml.'
        );

        foreach (array_keys($this->registeredObservers()) as $eventName) {
            self::assertLessThanOrEqual(
                $declaredWidth,
                strlen($eventName),
                sprintf(
                    'Observed event "%s" is %d characters and would be silently truncated into the '
                    . '%d-character event_name column.',
                    $eventName,
                    strlen($eventName),
                    $declaredWidth
                )
            );
        }
    }

    /**
     * The narrowest event_name column declared across the counter tables.
     *
     * @return int
     */
    private function eventNameColumnWidth(): int
    {
        $path = dirname(__DIR__, 4) . '/etc/db_schema.xml';
        self::assertFileExists($path);

        $xml = simplexml_load_file($path);
        self::assertNotFalse($xml, 'etc/db_schema.xml is not well-formed XML.');

        $widths = [];

        foreach ($xml->table as $table) {
            if (!str_contains((string) $table['name'], 'event_counter')) {
                continue;
            }

            foreach ($table->column as $column) {
                if ((string) $column['name'] === 'event_name') {
                    $widths[] = (int) $column['length'];
                }
            }
        }

        self::assertNotEmpty($widths, 'Found no event_name column on any *event_counter* table.');

        return min($widths);
    }
}
