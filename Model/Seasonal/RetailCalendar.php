<?php

declare(strict_types=1);

namespace Watchtower\Connector\Model\Seasonal;

/**
 * Resolves a date to a seasonal period key that stays aligned across years even
 * for holidays whose date moves (Black Friday, Easter), unlike a naive "same
 * date last year" comparison. US-centric: Black Friday is anchored to US
 * Thanksgiving, so an install in another retail tradition gets a key that
 * misses its own seasonal peaks.
 */
class RetailCalendar
{
    /**
     * Days either side of a holiday anchor counted as that holiday's own period;
     * one shared value covers both Black Friday-Cyber Monday and Easter week.
     */
    private const HOLIDAY_WINDOW_DAYS = 3;

    /**
     * Width of the ordinary (non-holiday) fallback bucket. Exact "MM-DD" keys
     * cannot satisfy the seasonal comparison's >=2-occurrence requirement --
     * anniversaries are ~365 days apart, past RollupRepository's 400-day daily
     * retention -- at the cost of blurring single-day spikes into their week.
     */
    private const ORDINARY_PERIOD_BUCKET_DAYS = 7;

    /**
     * Resolves a date to its calendar-period key: a named moving-holiday key
     * inside a holiday window, otherwise a day-of-year bucket that recurs on the
     * same key every year (+/-1 day around a leap day).
     *
     * @param \DateTimeImmutable $date
     * @return string
     */
    public function periodKeyFor(\DateTimeImmutable $date): string
    {
        $year = (int) $date->format('Y');

        // Only this year's anchors: neither Black Friday nor Easter can land within
        // HOLIDAY_WINDOW_DAYS of a year boundary. One near Dec 31 would need an
        // adjacent-year lookup.
        foreach ($this->holidayAnchors($year) as $key => $anchor) {
            if ($this->withinWindow($date, $anchor)) {
                return $key;
            }
        }

        $dayOfYear = (int) $date->format('z');

        return 'day-bucket-' . intdiv($dayOfYear, self::ORDINARY_PERIOD_BUCKET_DAYS);
    }

    /**
     * Whether $date falls within HOLIDAY_WINDOW_DAYS of $anchor.
     *
     * @param \DateTimeImmutable $date
     * @param \DateTimeImmutable $anchor
     * @return bool
     */
    private function withinWindow(\DateTimeImmutable $date, \DateTimeImmutable $anchor): bool
    {
        $deltaDays = abs(
            (int) round(($date->setTime(0, 0)->getTimestamp() - $anchor->setTime(0, 0)->getTimestamp()) / 86400)
        );

        return $deltaDays <= self::HOLIDAY_WINDOW_DAYS;
    }

    /**
     * Every recognized moving holiday's anchor date for one calendar year.
     *
     * @param int $year
     * @return array<string, \DateTimeImmutable> period key => anchor date
     */
    private function holidayAnchors(int $year): array
    {
        return [
            'black_friday' => $this->blackFriday($year),
            'easter' => $this->easterSunday($year),
        ];
    }

    /**
     * The day after the 4th Thursday of November (US Thanksgiving).
     *
     * @param int $year
     * @return \DateTimeImmutable
     */
    private function blackFriday(int $year): \DateTimeImmutable
    {
        $novemberFirst = new \DateTimeImmutable(sprintf('%04d-11-01', $year));
        $fourthThursday = $novemberFirst->modify('fourth thursday of this month');

        return $fourthThursday->modify('+1 day');
    }

    /**
     * Easter Sunday via the Gregorian algorithm (Meeus/Jones/Butcher).
     *
     * PHP's own easter_date() needs the optional calendar extension.
     *
     * @param int $year
     * @return \DateTimeImmutable
     */
    private function easterSunday(int $year): \DateTimeImmutable
    {
        $a = $year % 19;
        $b = intdiv($year, 100);
        $c = $year % 100;
        $d = intdiv($b, 4);
        $e = $b % 4;
        $f = intdiv($b + 8, 25);
        $g = intdiv($b - $f + 1, 3);
        $h = (19 * $a + $b - $d - $g + 15) % 30;
        $i = intdiv($c, 4);
        $k = $c % 4;
        $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
        $m = intdiv($a + 11 * $h + 22 * $l, 451);
        $month = intdiv($h + $l - 7 * $m + 114, 31);
        $day = (($h + $l - 7 * $m + 114) % 31) + 1;

        return new \DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, $month, $day));
    }
}
