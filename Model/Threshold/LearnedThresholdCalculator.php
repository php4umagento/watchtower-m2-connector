<?php
/**
 * Copyright © 2026 Watchtower. All rights reserved.
 * Licensed under the Business Source License 1.1 (BUSL-1.1). See LICENSE.
 */

declare(strict_types=1);

namespace Watchtower\Connector\Model\Threshold;

/**
 * Refines a threshold-based signal's conservative fixed thresholds against a
 * single store's own history (connector-failure-signals-prd.md Q1).
 *
 * Used by CheckoutFailure\Evaluator. Deliberately NOT used by
 * AdminAuthFailure\Evaluator, even though both are threshold-based: the
 * median statistic here suits a value with a dense series of real
 * observations, like checkout's per-hour failure ratio, where a clean store
 * still produces a data point every busy hour. Admin sign-in failures are
 * sparse and bursty -- essentially every install's median hourly failure
 * count is zero -- so this would collapse every install onto the floor and
 * differentiate nothing. That signal keeps its fixed thresholds until a
 * statistic suited to burst detection (a high percentile, not a median) is
 * designed for it; see AdminAuthFailure\Evaluator.
 *
 * THE HYBRID, AND WHY IT IS SHAPED THIS WAY. The fixed thresholds a signal
 * ships with are deliberately high, so they never false-alarm on a store
 * whose normal we do not know. That safety costs detection: a clean store's
 * checkout can be 50% broken before the fixed severe threshold fires. This
 * class lets a store that has accumulated enough of its own history tighten
 * those thresholds toward its demonstrated normal, WITHOUT reintroducing a
 * warm-up: until the history exists, effective() returns the fixed values
 * unchanged, so the signal is fully live in its first hour and only gets
 * more accurate later. hasBaseline() therefore stays false for these
 * signals -- the learned threshold is a refinement, never a prerequisite.
 *
 * THE CLAMP IS THE SAFETY. effective = clamp(K * store_normal, [floor, fixed]):
 *
 *  - Never ABOVE the fixed value: learning can only make a signal MORE
 *    sensitive than its conservative default, never less. A store that has
 *    somehow learned "being broken is normal" cannot raise its own bar and
 *    go blind.
 *  - Never BELOW the per-signal floor: a clean store (normal ~0, so K*normal
 *    ~0) collapses onto the floor rather than to zero, which would alert on
 *    a single genuine card decline. The floor is what stops the learned path
 *    from ever becoming an alert storm.
 *
 * So a clean store lands on the floor (tighter than the fixed default, the
 * common and useful case); a store with a real, stable background failure
 * rate lands between the floor and the fixed default; a pathologically
 * failing store is held at the fixed default. Mild and severe stay ordered
 * because severe's floor and ceiling both sit above mild's.
 *
 * EVERY NUMBER HERE IS A PLACEHOLDER. NORMAL_MULTIPLIER and
 * MIN_LEARNING_SAMPLES, and the per-signal floors passed in by each caller,
 * are engineering guesses. No real install's failure distribution has been
 * observed to fit them against. The mechanism is correct and safe -- it can
 * only tighten within a bounded range and degrades to the fixed defaults --
 * but whether these specific values IMPROVE detection is unknown until real
 * installs report. Calibrate against real data before treating any of them
 * as settled; that is why they are constants in one place.
 */
class LearnedThresholdCalculator
{
    /**
     * How many times a store's own normal counts as "unusual". Chosen so a
     * store whose demonstrated normal already sits around a fifth of the
     * fixed severe threshold is held at the fixed threshold rather than
     * loosened past it. PLACEHOLDER.
     */
    private const NORMAL_MULTIPLIER = 2.5;

    /**
     * Qualifying historical observations required before the learned value
     * is trusted at all. Below this the fixed defaults are returned
     * unchanged. Deliberately generous: a store learns a tighter threshold
     * slowly and safely rather than off a handful of unrepresentative hours.
     * PLACEHOLDER.
     */
    private const MIN_LEARNING_SAMPLES = 100;

    /**
     * How far back callers fetch a store's own history to learn from. Recent
     * enough to reflect the store's current behaviour rather than a season
     * it has since grown out of, comfortably inside the counters' 90-day
     * retention. Exposed so the fetch window and the sample requirement stay
     * in one place. PLACEHOLDER.
     */
    public const LEARNING_WINDOW_DAYS = 28;

    /**
     * The effective thresholds for this tick.
     *
     * @param float[] $history qualifying historical values -- hourly failure
     *     ratios for checkout_failure, hourly failure counts for
     *     admin_auth_failure. Each caller decides what "qualifying" means and
     *     excludes the hour being evaluated.
     * @param float $fixedMild the signal's conservative default mild threshold
     * @param float $fixedSevere the signal's conservative default severe threshold
     * @param float $mildFloor the learned mild threshold never drops below this
     * @param float $severeFloor the learned severe threshold never drops below this
     * @return LearnedThresholds
     */
    public function effective(
        array $history,
        float $fixedMild,
        float $fixedSevere,
        float $mildFloor,
        float $severeFloor
    ): LearnedThresholds {
        if (count($history) < self::MIN_LEARNING_SAMPLES) {
            // Not enough of this store's own history yet: the signal runs on
            // its conservative fixed defaults, exactly as it does in hour one.
            return new LearnedThresholds($fixedMild, $fixedSevere);
        }

        $normal = $this->median($history);
        $unusual = self::NORMAL_MULTIPLIER * $normal;

        return new LearnedThresholds(
            mild: $this->clamp($unusual, $mildFloor, $fixedMild),
            severe: $this->clamp($unusual, $severeFloor, $fixedSevere),
        );
    }

    /**
     * Confines a value to the inclusive [$min, $max] range.
     *
     * @param float $value
     * @param float $min
     * @param float $max
     * @return float
     */
    private function clamp(float $value, float $min, float $max): float
    {
        return max($min, min($max, $value));
    }

    /**
     * The median of a non-empty list of values.
     *
     * @param float[] $values non-empty
     * @return float
     */
    private function median(array $values): float
    {
        sort($values);
        $count = count($values);
        $middle = intdiv($count, 2);

        if ($count % 2 === 0) {
            return ($values[$middle - 1] + $values[$middle]) / 2;
        }

        return $values[$middle];
    }
}
