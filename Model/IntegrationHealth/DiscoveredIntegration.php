<?php
/**
 * Copyright © 2026 Watchtower. All rights reserved.
 * Licensed under the Business Source License 1.1 (BUSL-1.1). See LICENSE.
 */

declare(strict_types=1);

namespace Watchtower\Connector\Model\IntegrationHealth;

/**
 * One thing a merchant can choose to watch: a module, everything it schedules,
 * and everything it consumes.
 *
 * The module rather than the vendor is the unit, because a vendor may ship
 * several genuinely separate integrations and merging them would remove the
 * merchant's ability to watch one without the other. Presentation is free to
 * group these by vendorLabel; that is a display decision, not a data one.
 */
class DiscoveredIntegration
{
    /**
     * @param string $moduleName Vendor_Module, or empty for the unattributed bucket
     * @param string $displayName what the merchant sees, from the Composer description where there is one
     * @param string $vendorLabel who ships it, shown as secondary detail
     * @param string|null $packageName Composer package, null for a module without one
     * @param bool $isThirdParty false for modules that are part of Magento itself
     * @param DiscoveredJob[] $jobs
     * @param string[] $consumerNames declared message-queue consumers this module owns
     */
    public function __construct(
        public readonly string $moduleName,
        public readonly string $displayName,
        public readonly string $vendorLabel,
        public readonly ?string $packageName,
        public readonly bool $isThirdParty,
        public readonly array $jobs,
        public readonly array $consumerNames,
    ) {
    }

    /**
     * Whether anything here has been measured well enough to alert on yet.
     *
     * Drives the picker's "learning cadence" state: an integration whose jobs
     * have all only just been seen is still offered, it just cannot be given
     * a dependable threshold yet.
     *
     * @return bool
     */
    public function hasConfidentCadence(): bool
    {
        foreach ($this->jobs as $job) {
            if ($job->cadence->isConfident) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether every measured job here runs too erratically to alert on tightly.
     *
     * Distinct from having no measurement at all: these are jobs the
     * connector has watched enough to be sure they have no dependable rhythm,
     * which the picker warns about rather than hiding.
     *
     * @return bool
     */
    public function isErratic(): bool
    {
        $confident = false;

        foreach ($this->jobs as $job) {
            if (!$job->cadence->isConfident) {
                continue;
            }

            if ($job->cadence->isRegular) {
                return false;
            }

            $confident = true;
        }

        return $confident;
    }
}
