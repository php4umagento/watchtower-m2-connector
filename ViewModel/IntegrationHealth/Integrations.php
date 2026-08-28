<?php
/**
 * Copyright © 2026 Watchtower. All rights reserved.
 * Licensed under the Business Source License 1.1 (BUSL-1.1). See LICENSE.
 */

declare(strict_types=1);

namespace Watchtower\Connector\ViewModel\IntegrationHealth;

use Magento\Backend\Model\UrlInterface;
use Magento\Framework\Data\Form\FormKey;
use Magento\Framework\Phrase;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Watchtower\Connector\Model\IntegrationHealth\DiscoveredIntegration;
use Watchtower\Connector\Model\IntegrationHealth\DiscoveredJob;
use Watchtower\Connector\Model\CronJobObservation\CadenceEstimator;
use Watchtower\Connector\Model\CronJobObservation\CronJobRunRecorder;
use Watchtower\Connector\Model\CronJobObservation\JobRunObservation;
use Watchtower\Connector\Model\IntegrationHealth\IntegrationDiscovery;
use Watchtower\Connector\Model\IntegrationHealth\IntegrationHealthEventRepository;
use Watchtower\Connector\Model\IntegrationHealth\WatchedJobResolver;
use Watchtower\Connector\Model\IntegrationHealth\WatchedSetEvaluator;
use Watchtower\Connector\Model\StoreView\LiveStoreViewResolver;
use Watchtower\Connector\Model\IntegrationHealth\WatchedIntegrationRepository;

/**
 * Backs the "which integrations should we watch" page.
 *
 * A view model rather than a block so every decision the page makes about
 * wording, ordering and tick state is reachable from a plain unit test. The
 * template does presentation only; it holds no logic of its own.
 */
class Integrations implements ArgumentInterface
{
    /**
     * @var DiscoveredIntegration[]|null
     */
    private ?array $discovered = null;

    /**
     * @var array<string,true>|null
     */
    private ?array $watchedModules = null;

    /**
     * @var array<string,true>|null
     */
    private ?array $watchedJobCodes = null;

    /**
     * @var array<string,int>|null label => dispatch count
     */
    private ?array $observedEvents = null;

    /**
     * @var array<string,true>|null
     */
    private ?array $watchedEventLabels = null;

    /**
     * @param IntegrationDiscovery $discovery
     * @param WatchedIntegrationRepository $watchedRepository
     * @param CadenceDescriber $cadenceDescriber
     * @param UrlInterface $url
     * @param FormKey $formKey
     * @param IntegrationHealthEventRepository $eventRepository
     * @param CadenceEstimator $cadenceEstimator
     * @param WatchedJobResolver $watchedJobResolver
     * @param WatchedSetEvaluator $watchedSetEvaluator
     * @param LiveStoreViewResolver $liveStoreViewResolver
     */
    public function __construct(
        private readonly IntegrationDiscovery $discovery,
        private readonly WatchedIntegrationRepository $watchedRepository,
        private readonly CadenceDescriber $cadenceDescriber,
        private readonly UrlInterface $url,
        private readonly FormKey $formKey,
        private readonly IntegrationHealthEventRepository $eventRepository,
        private readonly CadenceEstimator $cadenceEstimator,
        private readonly WatchedJobResolver $watchedJobResolver,
        private readonly WatchedSetEvaluator $watchedSetEvaluator,
        private readonly LiveStoreViewResolver $liveStoreViewResolver
    ) {
    }

    /**
     * Convention event labels this install has actually received, in order.
     *
     * Offered instead of a free-text field so a label can only ever be one the
     * connector has really seen dispatched.
     *
     * @return string[]
     */
    public function getObservedEventLabels(): array
    {
        if ($this->observedEvents === null) {
            $this->observedEvents = $this->eventRepository->observedLabels();
        }

        return array_keys($this->observedEvents);
    }

    /**
     * Whether this event label is currently watched.
     *
     * @param string $label
     * @return bool
     */
    public function isEventWatched(string $label): bool
    {
        if ($this->watchedEventLabels === null) {
            $this->watchedEventLabels = array_fill_keys($this->watchedRepository->watchedEventLabels(), true);
        }

        return isset($this->watchedEventLabels[$label]);
    }

    /**
     * How often this label is dispatched, phrased for the picker.
     *
     * Measured across every store view, for display only. Evaluation stays
     * per store view, so a label dispatched at different rates per store view
     * is judged separately even though one summary is shown here.
     *
     * @param string $label
     * @return Phrase
     */
    public function getEventCadenceLabel(string $label): Phrase
    {
        return $this->cadenceDescriber->describe($this->cadenceEstimator->estimate(new JobRunObservation(
            jobCode: $label,
            firstObservedAt: new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
            lastSuccessAt: null,
            observedRunCount: $this->observedEvents[$label] ?? 0,
            gapSamples: $this->eventRepository->successGapSeconds(
                null,
                $label,
                CronJobRunRecorder::MAX_GAP_SAMPLES
            ),
        )));
    }

    /**
     * How many dispatches of this label have been recorded.
     *
     * @param string $label
     * @return int
     */
    public function getEventDispatchCount(string $label): int
    {
        return $this->observedEvents[$label] ?? 0;
    }

    /**
     * The watched integrations currently judged unhealthy, by display name.
     *
     * Named here rather than only in Diagnostics because this is the page a
     * merchant opens to decide what to watch, and "is any of it broken right
     * now" is the obvious next question. The detail stays in Diagnostics.
     *
     * @return string[]
     */
    public function getFailingIntegrationNames(): array
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $failingJobs = array_flip($this->watchedSetEvaluator->unhealthyJobCodes(
            $this->watchedJobResolver->resolve(),
            $now
        ));

        $names = [];

        foreach ($this->integrations() as $integration) {
            foreach ($integration->jobs as $job) {
                if (isset($failingJobs[$job->jobCode]) && $this->isEffectivelyWatched($integration, $job)) {
                    $names[$integration->displayName] = true;

                    break;
                }
            }
        }

        // Convention events are judged per store view, so the same label can be
        // stalled in one and fine in another. Any store view failing is worth
        // surfacing; which one is Diagnostics' job to say.
        $labels = $this->watchedJobResolver->resolveEventLabels();

        foreach ($this->liveStoreViewResolver->all() as $store) {
            foreach ($this->watchedSetEvaluator->unhealthyEventLabels($labels, (int) $store->getId(), $now) as $label) {
                $names[$label] = true;
            }
        }

        return array_keys($names);
    }

    /**
     * Whether this job is watched, either on its own or via its integration.
     *
     * @param DiscoveredIntegration $integration
     * @param DiscoveredJob $job
     * @return bool
     */
    private function isEffectivelyWatched(DiscoveredIntegration $integration, DiscoveredJob $job): bool
    {
        return $this->isWatched($integration) || $this->isJobWatched($job);
    }

    /**
     * Everything the filter box matches this integration against.
     *
     * Built here rather than in the template so the page stays presentation
     * only, and so the match surface is testable: a merchant hunting
     * "ebizmarts" should find Mailchimp by job code, not just by name.
     *
     * @param DiscoveredIntegration $integration
     * @return string
     */
    public function getSearchText(DiscoveredIntegration $integration): string
    {
        $parts = [
            $integration->displayName,
            $integration->vendorLabel,
            $integration->packageName ?? $integration->moduleName,
        ];

        foreach ($integration->jobs as $job) {
            $parts[] = $job->jobCode;
        }

        foreach ($integration->consumerNames as $consumerName) {
            $parts[] = $consumerName;
        }

        return strtolower(trim(implode(' ', $parts)));
    }

    /**
     * Where to send a merchant for the detail behind a failing integration.
     *
     * @return string
     */
    public function getDiagnosticsUrl(): string
    {
        return $this->url->getUrl('watchtower/diagnostics/index');
    }

    /**
     * How often this integration's jobs were measured to run.
     *
     * Rendered for every integration, not only the awkward ones. Previously
     * only "learning" and "runs irregularly" were shown, so a healthy entry
     * and one nothing is known about looked identical.
     *
     * @param DiscoveredIntegration $integration
     * @return Phrase
     */
    public function getCadenceSummary(DiscoveredIntegration $integration): Phrase
    {
        $confident = [];

        foreach ($integration->jobs as $job) {
            if ($job->cadence->isConfident && $job->cadence->periodSeconds !== null) {
                $confident[] = $job->cadence->periodSeconds;
            }
        }

        if ($confident === []) {
            return __('still measuring how often this runs');
        }

        sort($confident);
        $fastest = $this->cadenceDescriber->humanizePeriod($confident[0]);

        return count($confident) === count($integration->jobs)
            ? __('runs %1', $fastest)
            : __('runs %1, other jobs still being measured', $fastest);
    }

    /**
     * How many integrations are currently watched, out of how many exist.
     *
     * @return array{0: int, 1: int}
     */
    public function getWatchedTally(): array
    {
        $watched = 0;
        $total = 0;

        foreach ($this->integrations() as $integration) {
            $total++;

            if ($this->isWatched($integration)) {
                $watched++;

                continue;
            }

            foreach ($integration->jobs as $job) {
                if ($this->isJobWatched($job)) {
                    $watched++;

                    break;
                }
            }
        }

        return [$watched, $total];
    }

    /**
     * The merchant's own extensions, plus anything scheduled that no installed module declares.
     *
     * Kept in discovery's order rather than re-sorted here: it already ranks
     * third-party first so an ERP sync is not buried under core housekeeping.
     *
     * @return DiscoveredIntegration[]
     */
    public function getAddedIntegrations(): array
    {
        return array_values(array_filter(
            $this->integrations(),
            static fn (DiscoveredIntegration $integration): bool => $integration->isThirdParty
        ));
    }

    /**
     * Everything Magento itself schedules, offered but ranked last.
     *
     * @return DiscoveredIntegration[]
     */
    public function getMagentoIntegrations(): array
    {
        return array_values(array_filter(
            $this->integrations(),
            static fn (DiscoveredIntegration $integration): bool => !$integration->isThirdParty
        ));
    }

    /**
     * Whether this whole integration is being watched.
     *
     * @param DiscoveredIntegration $integration
     * @return bool
     */
    public function isWatched(DiscoveredIntegration $integration): bool
    {
        return isset($this->watchedModules()[$integration->moduleName]);
    }

    /**
     * Whether this one job is being watched on its own.
     *
     * @param DiscoveredJob $job
     * @return bool
     */
    public function isJobWatched(DiscoveredJob $job): bool
    {
        return isset($this->watchedJobCodes()[$job->jobCode]);
    }

    /**
     * Whether an integration can be ticked as a single unit.
     *
     * The unattributed bucket cannot: it is not one integration but every
     * scheduled job no installed module claims, so there is no module name to
     * store and nothing the merchant would recognize as "all of it". Its jobs
     * are still individually selectable.
     *
     * @param DiscoveredIntegration $integration
     * @return bool
     */
    public function isSelectableAsWhole(DiscoveredIntegration $integration): bool
    {
        return $integration->moduleName !== IntegrationDiscovery::UNATTRIBUTED_MODULE;
    }

    /**
     * Whether the individual-job list should start expanded.
     *
     * Open only when the merchant has picked individual jobs here, so a choice
     * they made is never hidden behind a click. The unattributed bucket used
     * to open too, which made it the one expanded entry in a list of collapsed
     * ones.
     *
     * @param DiscoveredIntegration $integration
     * @return bool
     */
    public function isDetailOpen(DiscoveredIntegration $integration): bool
    {
        foreach ($integration->jobs as $job) {
            if ($this->isJobWatched($job)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether Magento's own jobs should start expanded.
     *
     * @return bool
     */
    public function isMagentoSectionOpen(): bool
    {
        foreach ($this->getMagentoIntegrations() as $integration) {
            if ($this->isWatched($integration) || $this->isDetailOpen($integration)) {
                return true;
            }
        }

        return false;
    }

    /**
     * What this integration schedules and consumes, in plain terms.
     *
     * @param DiscoveredIntegration $integration
     * @return string
     */
    public function getContentsSummary(DiscoveredIntegration $integration): string
    {
        $parts = [];
        $jobCount = count($integration->jobs);
        $consumerCount = count($integration->consumerNames);

        if ($jobCount > 0) {
            $parts[] = (string) ($jobCount === 1
                ? __('1 scheduled job')
                : __('%1 scheduled jobs', $jobCount));
        }

        if ($consumerCount > 0) {
            $parts[] = (string) ($consumerCount === 1
                ? __('1 queue consumer')
                : __('%1 queue consumers', $consumerCount));
        }

        return implode(', ', $parts);
    }

    /**
     * The caveats to show against the integration as a whole.
     *
     * @param DiscoveredIntegration $integration
     * @return Phrase[]
     */
    public function getNotes(DiscoveredIntegration $integration): array
    {
        $notes = [];

        if (!$integration->hasConfidentCadence()) {
            $notes[] = __('Learning cadence. You can select it now and we will start alerting once we know it.');
        }

        if ($integration->isErratic()) {
            $notes[] = __('Runs irregularly, alerting may be unreliable.');
        }

        return $notes;
    }

    /**
     * How often this job was measured running.
     *
     * @param DiscoveredJob $job
     * @return Phrase
     */
    public function getCadenceLabel(DiscoveredJob $job): Phrase
    {
        return $this->cadenceDescriber->describe($job->cadence);
    }

    /**
     * The caveat to show beside this job, or null when it needs none.
     *
     * @param DiscoveredJob $job
     * @return Phrase|null
     */
    public function getJobWarning(DiscoveredJob $job): ?Phrase
    {
        return $this->cadenceDescriber->warning($job->cadence);
    }

    /**
     * The consumers watched alongside this integration, or an empty string when it declares none.
     *
     * Listed rather than made selectable: consumers belong to the module that
     * handles them, so they are watched with it and there is nothing useful to
     * tick separately.
     *
     * @param DiscoveredIntegration $integration
     * @return string
     */
    public function getConsumerList(DiscoveredIntegration $integration): string
    {
        return implode(', ', $integration->consumerNames);
    }

    /**
     * Where the page-level Save posts the whole watched set.
     *
     * @return string
     */
    public function getSaveUrl(): string
    {
        return $this->url->getUrl('watchtower/integrationhealth/save');
    }

    /**
     * The admin form key this page's POST must carry.
     *
     * @return string
     */
    public function getFormKey(): string
    {
        return $this->formKey->getFormKey();
    }

    /**
     * Every integration this install offers, read once per render.
     *
     * @return DiscoveredIntegration[]
     */
    private function integrations(): array
    {
        if ($this->discovered === null) {
            $this->discovered = $this->discovery->discover();
        }

        return $this->discovered;
    }

    /**
     * The watched module names as a lookup, read once per render.
     *
     * @return array<string,true>
     */
    private function watchedModules(): array
    {
        if ($this->watchedModules === null) {
            $this->watchedModules = array_fill_keys($this->watchedRepository->watchedModules(), true);
        }

        return $this->watchedModules;
    }

    /**
     * The individually watched job codes as a lookup, read once per render.
     *
     * @return array<string,true>
     */
    private function watchedJobCodes(): array
    {
        if ($this->watchedJobCodes === null) {
            $this->watchedJobCodes = array_fill_keys($this->watchedRepository->watchedJobCodes(), true);
        }

        return $this->watchedJobCodes;
    }
}
