<?php

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
