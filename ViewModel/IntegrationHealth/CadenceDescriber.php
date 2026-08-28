<?php
/**
 * Copyright © 2026 Watchtower. All rights reserved.
 * Licensed under the Business Source License 1.1 (BUSL-1.1). See LICENSE.
 */

declare(strict_types=1);

namespace Watchtower\Connector\ViewModel\IntegrationHealth;

use Magento\Framework\Phrase;
use Watchtower\Connector\Model\CronJobObservation\Cadence;

/**
 * Turns a measured Cadence into a phrase a merchant can act on.
 *
 * This is the whole of what replaced the expected-max-interval field. Nobody
 * types a threshold any more, so the page's remaining job is to show what was
 * measured plainly enough that the merchant can tell "we know this one" from
 * "we are still learning it" from "this one is too erratic to trust".
 */
class CadenceDescriber
{
    /**
     * How far a rounded period may sit from the measured one before the next
     * smaller unit is used instead.
     *
     * Cron gaps carry jitter, so an hourly job typically measures at 3600-odd
     * seconds rather than exactly 3600, and "every hour" is the useful reading
     * of that. A genuinely 90 minute period is 33% away from an hour, well
     * outside this, and is reported as "every 90 min" rather than rounded into
     * a number the merchant would not recognize.
     */
    private const ROUNDING_TOLERANCE = 0.1;

    private const SECONDS_PER_MINUTE = 60;
    private const SECONDS_PER_HOUR = 3600;
    private const SECONDS_PER_DAY = 86400;

    /**
     * How often this job was measured running, and on how much evidence.
     *
     * @param Cadence $cadence
     * @return Phrase
     */
    public function describe(Cadence $cadence): Phrase
    {
        if (!$cadence->isConfident || $cadence->periodSeconds === null) {
            return __('learning cadence');
        }

        $period = $this->humanizePeriod($cadence->periodSeconds);

        return $cadence->observedRunCount === 1
            ? __('%1 (observed, 1 run)', $period)
            : __('%1 (observed, %2 runs)', $period, $cadence->observedRunCount);
    }

    /**
     * The caveat to show beside a job that has been measured but has no dependable rhythm.
     *
     * Deliberately a warning rather than a reason to hide the job: a merchant
     * whose one bespoke integration runs irregularly still needs a way to
     * watch it, they just need to know the alerting will be looser.
     *
     * @param Cadence $cadence
     * @return Phrase|null
     */
    public function warning(Cadence $cadence): ?Phrase
    {
        return $cadence->isConfident && !$cadence->isRegular
            ? __('runs irregularly, alerting may be unreliable')
            : null;
    }

    /**
     * Whether this job is still being measured.
     *
     * @param Cadence $cadence
     * @return bool
     */
    public function isLearning(Cadence $cadence): bool
    {
        return !$cadence->isConfident;
    }

    /**
     * The measured period in the largest unit that still describes it honestly.
     *
     * @param int $seconds
     * @return Phrase
     */
    public function humanizePeriod(int $seconds): Phrase
    {
        if ($seconds >= self::SECONDS_PER_DAY && $this->roundsCleanly($seconds, self::SECONDS_PER_DAY)) {
            $days = $this->inUnits($seconds, self::SECONDS_PER_DAY);

            return $days === 1 ? __('every day') : __('every %1 days', $days);
        }

        if ($seconds >= self::SECONDS_PER_HOUR && $this->roundsCleanly($seconds, self::SECONDS_PER_HOUR)) {
            $hours = $this->inUnits($seconds, self::SECONDS_PER_HOUR);

            return $hours === 1 ? __('every hour') : __('every %1 hours', $hours);
        }

        if ($seconds >= self::SECONDS_PER_MINUTE && $this->roundsCleanly($seconds, self::SECONDS_PER_MINUTE)) {
            $minutes = $this->inUnits($seconds, self::SECONDS_PER_MINUTE);

            return $minutes === 1 ? __('every minute') : __('every %1 min', $minutes);
        }

        return $seconds === 1 ? __('every second') : __('every %1 sec', max(1, $seconds));
    }

    /**
     * The period expressed in whole units of the given size, never zero.
     *
     * @param int $seconds
     * @param int $unitSeconds
     * @return int
     */
    private function inUnits(int $seconds, int $unitSeconds): int
    {
        return max(1, (int) round($seconds / $unitSeconds));
    }

    /**
     * Whether expressing this period in the given unit stays close enough to the measurement.
     *
     * @param int $seconds
     * @param int $unitSeconds
     * @return bool
     */
    private function roundsCleanly(int $seconds, int $unitSeconds): bool
    {
        $rounded = $this->inUnits($seconds, $unitSeconds) * $unitSeconds;

        return abs($rounded - $seconds) <= $seconds * self::ROUNDING_TOLERANCE;
    }
}
