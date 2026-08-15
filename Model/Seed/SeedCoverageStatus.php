<?php
/**
 * Copyright © 2026 Watchtower. All rights reserved.
 * Licensed under the Business Source License 1.1 (BUSL-1.1). See LICENSE.
 */

declare(strict_types=1);

namespace Watchtower\Connector\Model\Seed;

/**
 * Whether HistorySeeder::seed() reached the full requested baseline window
 * for a (store view, category) pair, or fell short of it -- see
 * SeedLimitReason for why, when Limited.
 */
enum SeedCoverageStatus: string
{
    case Seeded = 'seeded';
    case Limited = 'limited';
}
