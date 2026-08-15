<?php
/**
 * Copyright © 2026 Watchtower. All rights reserved.
 * Licensed under the Business Source License 1.1 (BUSL-1.1). See LICENSE.
 */

declare(strict_types=1);

namespace Watchtower\Connector\Test\Unit\Model\Signal;

use PHPUnit\Framework\TestCase;

/**
 * The connector's signal computation queries contain only COUNT()
 * aggregates grouped by store and hour bucket -- never a row-level SELECT
 * against sales_order, quote, or customer_entity. Companion to
 * Test/Unit/LeakTest.php, which covers the wire-payload half of the same
 * discipline.
 *
 * A static source scan rather than a mocked-Select assertion: each
 * reader's behavioral test already proves its own where-clause shape, but
 * this test is the one place that guarantees, for every current AND future
 * reader dropped into this directory, that the columns requested from the
 * database are never anything but the count expression -- a class of bug a
 * per-reader unit test would only catch if someone remembered to write it
 * again each time.
 */
class AggregateOnlyQueryTest extends TestCase
{
    public function testEveryReaderInThisDirectoryQueriesOnlyACountAggregate(): void
    {
        $signalModelDirectory = dirname(__DIR__, 4) . '/Model/Signal';
        self::assertDirectoryExists($signalModelDirectory);

        $readerFiles = glob($signalModelDirectory . '/*Reader.php');
        self::assertNotEmpty($readerFiles, 'Expected at least one *Reader.php file to check.');

        foreach ($readerFiles as $file) {
            $source = file_get_contents($file);
            $className = basename($file, '.php');

            $countOnlyPattern = '/->from\(\s*\$table\s*,\s*\[\s*\'count\'\s*=>\s*new \\\\Zend_Db_Expr\('
                . '\s*\'COUNT\(\*\)\'\s*\)\s*\]\s*\)/';

            self::assertMatchesRegularExpression(
                $countOnlyPattern,
                $source,
                "$className must select only a single COUNT(*) aggregate column, aliased 'count'."
            );

            self::assertSame(
                1,
                preg_match_all('/->from\(/', $source),
                "$className must build exactly one query (one ->from() call)."
            );

            self::assertDoesNotMatchRegularExpression(
                '/\bfetchRow\b|\bfetchAll\b|\bfetchCol\b|\bfetchPairs\b/',
                $source,
                "$className must fetch its result via fetchOne() (a single aggregate scalar), "
                . 'never a row-level fetch method.'
            );
        }
    }
}
