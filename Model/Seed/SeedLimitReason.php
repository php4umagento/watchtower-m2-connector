<?php

declare(strict_types=1);

namespace Watchtower\Connector\Model\Seed;

/**
 * Why a SeedCoverageResult fell short of the requested baseline window.
 * Distinguishes the three cases a merchant-facing diagnostic needs to word
 * differently -- "unavailable" (a hard retention cliff), "limited" (the
 * seeder deliberately stopped scanning a very large store), and "warming
 * up" (the store itself doesn't have that much history yet) -- even though
 * every case ends in the same "not fully seeded" outcome.
 */
enum SeedLimitReason: string
{
    /**
     * basket_quote only: bounded by checkout/cart/delete_quote_after --
     * data beyond that window is provably gone, not merely thin.
     */
    case RetentionCliff = 'retention_cliff';

    /**
     * The seeder stopped before the requested window to avoid scanning an
     * unbounded number of rows on a very large store -- see
     * HistorySeeder::ROW_COUNT_CEILING.
     */
    case RowCountCeiling = 'row_count_ceiling';

    /**
     * The source table itself doesn't hold that much history yet (e.g. a
     * young store) -- distinct from a retention cliff because nothing was
     * deleted, there was simply never anything there.
     */
    case InsufficientHistory = 'insufficient_history';
}
