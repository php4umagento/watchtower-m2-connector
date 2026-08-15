<?php

declare(strict_types=1);

namespace Watchtower\Connector\Model\RateSignal;

use Watchtower\Connector\Model\Rollup\HourlyCountSample;

/**
 * Pure computation for Low-Volume Signal Mode's inter-arrival check: given a
 * chronological series of hourly counts, derives how long it's been since the
 * last observed event and what that gap has historically looked like at this
 * same hour-of-day/day-of-week bucket. The rollup series is the only data
 * source, so nothing here reaches live Magento business tables.
 *
 * One forward pass carries a running "hours since last nonzero hour" counter,
 * snapshotting it into both distributions -- so historical gaps use the exact
 * same "gap immediately before this hour" definition as the live current gap
 * and the two are directly comparable.
 */
class InterArrivalGapCalculator
{
    /**
     * Computes the current gap and both historical gap-distributions for one (store view, category) series.
     *
     * @param HourlyCountSample[] $series chronological (oldest first), including the evaluated hour's own row
     * @param \DateTimeImmutable $evaluatedHour top-of-hour instant of the hour being evaluated
     * @return InterArrivalGapResult
     */
    public function compute(array $series, \DateTimeImmutable $evaluatedHour): InterArrivalGapResult
    {
        $evaluatedIsoDayOfWeek = (int) $evaluatedHour->format('N');
        $evaluatedHourOfDay = (int) $evaluatedHour->format('G');
        $evaluatedBucketKey = $this->bucketKey($evaluatedHour);

        $runningGap = null;
        $prevBucket = null;
        $currentGap = null;
        $bucketDistribution = [];
        $storeWideDistribution = [];

        foreach ($series as $sample) {
            if ($prevBucket !== null && $runningGap !== null) {
                $runningGap += $this->hoursBetween($prevBucket, $sample->bucket);
            }

            if ($this->bucketKey($sample->bucket) === $evaluatedBucketKey) {
                // The evaluated hour's own row contributes its position but not
                // its count, so the value being classified can't pollute its own gap.
                $currentGap = $runningGap;
                $prevBucket = $sample->bucket;

                continue;
            }

            if ($runningGap !== null) {
                $storeWideDistribution[] = $runningGap;

                if ((int) $sample->bucket->format('N') === $evaluatedIsoDayOfWeek
                    && (int) $sample->bucket->format('G') === $evaluatedHourOfDay
                ) {
                    $bucketDistribution[] = $runningGap;
                }
            }

            $runningGap = $sample->count > 0 ? 0 : $runningGap;
            $prevBucket = $sample->bucket;
        }

        if ($currentGap === null) {
            // No nonzero hour before the evaluated one (or its row is missing):
            // floor the gap to the window's span. This does not by itself force a
            // SEVERE_DROP -- if the window is entirely empty both distributions are
            // empty too, and the caller reports INSUFFICIENT_DATA before comparing.
            $currentGap = empty($series) ? 0 : $this->hoursBetween($series[0]->bucket, $evaluatedHour);
        }

        return new InterArrivalGapResult($currentGap, $bucketDistribution, $storeWideDistribution);
    }

    /**
     * Whole hours between two instants, rounded to the nearest hour.
     *
     * Public because DispersionEvaluator::lowVolumeThresholdPercentile() reuses it.
     *
     * @param \DateTimeImmutable $from
     * @param \DateTimeImmutable $to
     * @return int
     */
    public function hoursBetween(\DateTimeImmutable $from, \DateTimeImmutable $to): int
    {
        return (int) round(($to->getTimestamp() - $from->getTimestamp()) / 3600);
    }

    /**
     * Formats an instant as its UTC top-of-hour string, for bucket-identity comparisons.
     *
     * @param \DateTimeImmutable $dateTime
     * @return string
     */
    private function bucketKey(\DateTimeImmutable $dateTime): string
    {
        return $dateTime->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:00:00');
    }
}
