<?php
/**
 * Copyright © 2026 Watchtower. All rights reserved.
 * Licensed under the Business Source License 1.1 (BUSL-1.1). See LICENSE.
 */

declare(strict_types=1);

namespace Watchtower\Connector\Model\Diagnostics;

/**
 * One line of the diagnostics page in plain words: a short label, how worried
 * to look, and an optional sentence saying what it means and what to do.
 */
class PlainStatus
{
    public const GOOD = 'good';
    public const INFO = 'info';
    public const WARN = 'warn';
    public const BAD = 'bad';

    /**
     * @param string $label
     * @param string $tone one of the tone constants above
     * @param string $detail empty when the label says it all
     */
    public function __construct(
        public readonly string $label,
        public readonly string $tone,
        public readonly string $detail = '',
    ) {
    }
}
