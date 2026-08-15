<?php
/**
 * Copyright © 2026 Watchtower. All rights reserved.
 * Licensed under the Business Source License 1.1 (BUSL-1.1). See LICENSE.
 */

declare(strict_types=1);

namespace Watchtower\Connector\Test\Unit\Model\Seasonal;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Watchtower\Connector\Model\Seasonal\RetailCalendar;

/**
 * Verified against independently-known real calendar facts (public
 * Thanksgiving/Black Friday and Easter Sunday dates), not just internal
 * consistency -- a computus/holiday algorithm can be self-consistent and
 * still wrong.
 */
class RetailCalendarTest extends TestCase
{
    #[DataProvider('blackFridayDates')]
    public function testBlackFridayIsTheDayAfterTheFourthThursdayOfNovember(string $date): void
    {
        self::assertSame('black_friday', (new RetailCalendar())->periodKeyFor(new \DateTimeImmutable($date)));
    }

    public static function blackFridayDates(): array
    {
        return [
            'Nov 29, 2024 (real Black Friday)' => ['2024-11-29'],
            'Nov 28, 2025 (real Black Friday)' => ['2025-11-28'],
            'Nov 27, 2026 (real Black Friday)' => ['2026-11-27'],
        ];
    }

    #[DataProvider('easterSundayDates')]
    public function testEasterSundayResolvesToTheEasterPeriodKey(string $date): void
    {
        self::assertSame('easter', (new RetailCalendar())->periodKeyFor(new \DateTimeImmutable($date)));
    }

    public static function easterSundayDates(): array
    {
        return [
            'Mar 31, 2024 (real Easter Sunday)' => ['2024-03-31'],
            'Apr 20, 2025 (real Easter Sunday)' => ['2025-04-20'],
            'Apr 5, 2026 (real Easter Sunday)' => ['2026-04-05'],
        ];
    }

    public function testADaysWithinTheHolidayWindowAlsoResolveToTheHolidayKey(): void
    {
        $calendar = new RetailCalendar();

        // Black Friday 2026 is Nov 27; the following Monday (Cyber Monday,
        // 3 days later) is still within the holiday window.
        self::assertSame('black_friday', $calendar->periodKeyFor(new \DateTimeImmutable('2026-11-30')));
        // The Thursday before (Thanksgiving itself, 1 day earlier).
        self::assertSame('black_friday', $calendar->periodKeyFor(new \DateTimeImmutable('2026-11-26')));
    }

    public function testTheSameCalendarDateInTwoDifferentYearsProducesTheSameBucketKeyOutsideAnyHoliday(): void
    {
        $calendar = new RetailCalendar();

        // Both 2025 and 2026 are non-leap years, so day-of-year for Jul 15
        // matches exactly in both (day 195, bucket 27).
        self::assertSame('day-bucket-27', $calendar->periodKeyFor(new \DateTimeImmutable('2025-07-15')));
        self::assertSame('day-bucket-27', $calendar->periodKeyFor(new \DateTimeImmutable('2026-07-15')));
    }

    public function testNearbyDatesWithinTheSameBucketShareAKeyGivingASingleYearMultipleSamples(): void
    {
        // A whole calendar week (7 days) shares one bucket key -- the fix
        // for Check B's own >=2-historical-occurrences requirement, which
        // an exact single-date match could never satisfy from just one
        // retained year (see ORDINARY_PERIOD_BUCKET_DAYS's own docblock).
        $calendar = new RetailCalendar();

        $base = $calendar->periodKeyFor(new \DateTimeImmutable('2026-07-13')); // day-of-year 193, bucket 27.
        $sameWeek = $calendar->periodKeyFor(new \DateTimeImmutable('2026-07-17')); // day-of-year 197, bucket 28.

        // 2026-07-13 (day 193) and 2026-07-15 (day 195) are both bucket 27.
        self::assertSame($base, $calendar->periodKeyFor(new \DateTimeImmutable('2026-07-15')));
        self::assertNotSame($base, $sameWeek, 'Sanity check: the fixture dates chosen actually span two buckets.');
    }

    public function testADateJustOutsideTheHolidayWindowFallsBackToTheBucketKey(): void
    {
        // Black Friday 2026 is Nov 27; the window is +/-3 days, so Nov 23
        // (4 days before) must NOT resolve to black_friday.
        $key = (new RetailCalendar())->periodKeyFor(new \DateTimeImmutable('2026-11-23'));

        self::assertSame('day-bucket-46', $key);
    }
}
