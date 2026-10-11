<?php
/**
 * Copyright © 2026 Watchtower. All rights reserved.
 * Licensed under the Business Source License 1.1 (BUSL-1.1). See LICENSE.
 */

declare(strict_types=1);

namespace Watchtower\Connector\Test\Unit\Replay;

/**
 * Appends one scorecard line per scenario to the file named by the
 * WATCHTOWER_REPLAY_REPORT environment variable, if set. Lets the measured
 * numbers be pasted into docs without re-deriving them by hand.
 */
class ReplayReport
{
    public static function line(string $line): void
    {
        $path = getenv('WATCHTOWER_REPLAY_REPORT');

        if (is_string($path) && $path !== '') {
            file_put_contents($path, $line . PHP_EOL, FILE_APPEND);
        }
    }
}
