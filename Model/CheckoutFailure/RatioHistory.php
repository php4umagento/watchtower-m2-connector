<?php
/**
 * Copyright © 2026 Watchtower. All rights reserved.
 * Licensed under the Business Source License 1.1 (BUSL-1.1). See LICENSE.
 */

declare(strict_types=1);

namespace Watchtower\Connector\Model\CheckoutFailure;

use Watchtower\Connector\Model\EventCounter\CheckoutFailureObserver;
use Watchtower\Connector\Model\EventCounter\EventCounterRepository;
use Watchtower\Connector\Model\Rollup\RollupRepository;
use Watchtower\Connector\Model\Seed\HistorySeeder;
use Watchtower\Connector\Model\Threshold\LearnedThresholdCalculator;

/**
 * Reconstructs a store's own historical checkout-failure ratios, so
 * LearnedThresholdCalculator can refine the fixed thresholds against them.
 *
 * The ratio for a past hour is failures / (failures + orders), and the two
 * terms live in different tables written by different paths: failures in
 * watchtower_event_counter (the observer, live), orders in
 * watchtower_rollup_hourly (the checkout signal's cron reader). This class
 * aligns them by hour bucket, applies the same minimum-attempts floor the
 * evaluator uses so a two-order hour cannot masquerade as a data point, and
 * excludes the hour being evaluated so a store never learns from the hour it
 * is currently judging.
 *
 * A clean busy hour (zero failures, many orders) is a legitimate data point
 * with ratio 0 -- it is exactly what drives a clean store's learned
 * threshold down onto its floor. Only hours below the attempts floor are
 * dropped.
 */
class RatioHistory
{
    /**
     * @param EventCounterRepository $eventCounterRepository
     * @param RollupRepository $rollupRepository
     */
    public function __construct(
        private readonly EventCounterRepository $eventCounterRepository,
        private readonly RollupRepository $rollupRepository,
    ) {
    }

    /**
     * Qualifying hourly failure ratios over the learning window, oldest first.
     *
     * @param int $storeViewId
     * @param int $minAttempts hours with fewer total attempts than this are dropped
     * @param \DateTimeImmutable $evaluatedHour the hour being judged; history is strictly earlier
     * @return float[]
     */
    public function qualifyingRatios(int $storeViewId, int $minAttempts, \DateTimeImmutable $evaluatedHour): array
    {
        $days = LearnedThresholdCalculator::LEARNING_WINDOW_DAYS;

        $failures = $this->eventCounterRepository->countsInWindow(
            $storeViewId,
            CheckoutFailureObserver::EVENT_NAME,
            $days,
            $evaluatedHour
        );

        $orders = [];
        $orderSamples = $this->rollupRepository->allHourlyCountsInWindow(
            $storeViewId,
            HistorySeeder::CATEGORY_CHECKOUT,
            (int) ceil($days / 7),
            $evaluatedHour
        );
        foreach ($orderSamples as $sample) {
            $orders[$sample->bucket->format('Y-m-d H:00:00')] = $sample->count;
        }

        // allHourlyCountsInWindow() includes the evaluated hour (<=), unlike
        // the event counter's window (<); drop it so both sides exclude it.
        unset($orders[$evaluatedHour->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:00:00')]);

        $ratios = [];
        foreach (array_keys($failures + $orders) as $hour) {
            $failureCount = $failures[$hour] ?? 0;
            $orderCount = $orders[$hour] ?? 0;
            $attempts = $failureCount + $orderCount;

            if ($attempts >= $minAttempts) {
                // Cast: PHP's / returns int for an evenly-dividing result
                // (0 / 50), and this method's contract is float[].
                $ratios[] = (float) ($failureCount / $attempts);
            }
        }

        return $ratios;
    }
}
