<?php

declare(strict_types=1);

namespace Watchtower\Connector\Model\IntegrationHealth;

use Watchtower\Connector\Model\StoreView\LiveStoreViewResolver;

/**
 * Validates one submitted row of the admin source picker before it reaches
 * IntegrationHealthConfigRepository::save().
 *
 * Collects every violation rather than short-circuiting on the first, so a
 * merchant who got two fields wrong sees both in the row's inline errors.
 */
class IntegrationHealthConfigValidator
{
    /**
     * Column bounds. Public so the admin picker's template can mirror them in
     * its own client-side validation instead of duplicating the numbers.
     */
    public const MAX_SOURCE_IDENTIFIER_LENGTH = 255;
    public const MAX_EXPECTED_MAX_INTERVAL_MINUTES = 43200;

    /**
     * Matches ConventionEventObserver's write-side bound: a longer label could
     * never match a real dispatch, so accepting it would store unusable config.
     */
    public const MAX_CONVENTION_EVENT_LABEL_LENGTH = 64;

    /**
     * @param LiveStoreViewResolver $liveStoreViewResolver
     */
    public function __construct(
        private readonly LiveStoreViewResolver $liveStoreViewResolver
    ) {
    }

    /**
     * Returns a human-readable error message per violation; an empty array means the input is valid.
     *
     * @param int $storeViewId
     * @param string $sourceType
     * @param string $sourceIdentifier
     * @param int $expectedMaxIntervalMinutes
     * @return string[]
     */
    public function validate(
        int $storeViewId,
        string $sourceType,
        string $sourceIdentifier,
        int $expectedMaxIntervalMinutes
    ): array {
        $errors = [];

        if (!in_array($storeViewId, $this->liveStoreViewResolver->ids(), true)) {
            $errors[] = (string) __('Store view %1 is not a currently live store view.', $storeViewId);
        }

        if (!in_array($sourceType, $this->allowedSourceTypes(), true)) {
            $errors[] = (string) __('Source type "%1" is not a supported source type.', $sourceType);
        }

        if (trim($sourceIdentifier) === '') {
            $errors[] = (string) __('A source identifier is required.');
        } elseif (strlen($sourceIdentifier) > self::MAX_SOURCE_IDENTIFIER_LENGTH) {
            $errors[] = (string) __(
                'The source identifier must be %1 characters or fewer.',
                self::MAX_SOURCE_IDENTIFIER_LENGTH
            );
        } elseif ($sourceType === IntegrationHealthConfig::SOURCE_TYPE_CONVENTION_EVENT
            && strlen($sourceIdentifier) > self::MAX_CONVENTION_EVENT_LABEL_LENGTH
        ) {
            $errors[] = (string) __(
                'A convention event integration label must be %1 characters or fewer.',
                self::MAX_CONVENTION_EVENT_LABEL_LENGTH
            );
        } elseif ($sourceType === IntegrationHealthConfig::SOURCE_TYPE_CRON_JOB
            && str_starts_with($sourceIdentifier, 'watchtower_')
        ) {
            // The dropdown already excludes this module's own jobs, but that guard
            // is UI-only: a direct POST could make the connector monitor itself.
            $errors[] = (string) __('This connector\'s own scheduled jobs cannot be monitored as an integration.');
        }

        if ($expectedMaxIntervalMinutes <= 0) {
            $errors[] = (string) __('The expected max interval must be a positive number of minutes.');
        } elseif ($expectedMaxIntervalMinutes > self::MAX_EXPECTED_MAX_INTERVAL_MINUTES) {
            $errors[] = (string) __(
                'The expected max interval must be %1 minutes or fewer.',
                self::MAX_EXPECTED_MAX_INTERVAL_MINUTES
            );
        }

        return $errors;
    }

    /**
     * The three source types IntegrationHealthConfig itself defines.
     *
     * @return string[]
     */
    private function allowedSourceTypes(): array
    {
        return [
            IntegrationHealthConfig::SOURCE_TYPE_CRON_JOB,
            IntegrationHealthConfig::SOURCE_TYPE_QUEUE_CONSUMER,
            IntegrationHealthConfig::SOURCE_TYPE_CONVENTION_EVENT,
        ];
    }
}
