<?php
/**
 * Copyright © 2026 Watchtower. All rights reserved.
 * Licensed under the Business Source License 1.1 (BUSL-1.1). See LICENSE.
 */

declare(strict_types=1);

namespace Watchtower\Connector\Test\Unit\Model\ApplicationErrors;

use PHPUnit\Framework\TestCase;

/**
 * Static-source guard for Handler.php, generalizing
 * Test/Unit/Model/Signal/AggregateOnlyQueryTest.php's approach (a regex scan
 * over the real source file, not a runtime assertion) to this module's other
 * kind of "must never read content" boundary: PRD EL15 asks for exactly this
 * class of check over the Monolog handler's own source, the same way
 * LeakTest's testTheUsernameAndExceptionAreNeverReadOrForwardedToStorage-style
 * tests cover the events.xml observers.
 *
 * One file, one test file: Handler.php holds both the Monolog 3 and Monolog
 * 2 implementations as two branches of one runtime if/else (see that file's
 * own docblock for why), so a single source-text scan covers both without
 * needing to know which one is actually loaded -- and, same as every test in
 * this directory that scans rather than loads, never references Handler::
 * class as executable code, since evaluating the file at all picks one
 * branch for the current Monolog major.
 */
class HandlerSourceSafetyTest extends TestCase
{
    public function testHandlerSourceNeverReadsMessageOrTraceContent(): void
    {
        $source = $this->sourceWithCommentsStripped();

        $forbidden = [
            'getMessage()' => '/getMessage\s*\(/',
            'getTraceAsString()' => '/getTraceAsString\s*\(/',
            'getTrace()' => '/\bgetTrace\s*\(/',
            '->message property/array access' => '/(?:->|\[\s*[\'"])message\b/',
        ];

        foreach ($forbidden as $label => $pattern) {
            self::assertSame(
                0,
                preg_match($pattern, $source),
                "Handler.php appears to read {$label} -- must count occurrences only, never content (PRD EL13)."
            );
        }
    }

    /**
     * PRD EL10: a fault in this handler must never propagate, and must never
     * be logged through the same logger it is registered on (re-entrancy
     * risk -- see Handler's own docblock). Both branches must wrap their
     * body and neither may hold a logger.
     */
    public function testBothBranchesAreWrappedAndNeverLogThroughTheirOwnLogger(): void
    {
        $source = $this->sourceWithCommentsStripped();

        self::assertSame(
            2,
            preg_match_all('/catch\s*\(\s*\\\\?Throwable\s+\$\w+\s*\)/', $source),
            'Both Monolog-version branches of Handler::handle() must wrap their body in catch (\Throwable ...).'
        );

        self::assertSame(
            0,
            preg_match('/\$this->logger/', $source),
            'Handler must not hold or call a logger -- see its docblock on re-entrancy.'
        );
    }

    /**
     * Both branches declare their own EVENT_NAME (no shared ancestor --
     * only one branch is ever actually declared at runtime) and must stay
     * in sync by hand, or the same install's counter would silently split
     * across two different keys depending on which Monolog major it runs.
     */
    public function testBothBranchesDeclareTheSameEventName(): void
    {
        $source = $this->sourceWithCommentsStripped();

        preg_match_all('/EVENT_NAME\s*=\s*[\'"]([^\'"]+)[\'"]/', $source, $matches);

        self::assertCount(2, $matches[1], 'Expected exactly two EVENT_NAME declarations, one per Monolog branch.');
        self::assertSame($matches[1][0], $matches[1][1]);
        self::assertLessThanOrEqual(
            64,
            strlen($matches[1][0]),
            'EVENT_NAME must fit InstallEventCounterRepository::MAX_EVENT_NAME_LENGTH.'
        );
    }

    private function sourceWithCommentsStripped(): string
    {
        $path = dirname(__DIR__, 4) . '/Model/ApplicationErrors/Handler.php';
        $source = file_get_contents($path);
        self::assertNotFalse($source, "Could not read {$path}");

        // Strip /** ... */, /* ... */, and // comments so the class's own
        // docblock prose (which necessarily names getMessage()/
        // getTraceAsString() to document what must never be called) can't
        // trip the very guard it explains.
        $withoutBlockComments = preg_replace('#/\*.*?\*/#s', '', $source);
        $withoutLineComments = preg_replace('#//[^\n]*#', '', (string) $withoutBlockComments);

        return (string) $withoutLineComments;
    }
}
