<?php
/**
 * Copyright © 2026 Watchtower. All rights reserved.
 * Licensed under the Business Source License 1.1 (BUSL-1.1). See LICENSE.
 */

declare(strict_types=1);

namespace Watchtower\Connector\Test\Unit\Model\IntegrationHealth;

use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;
use Watchtower\Connector\Model\IntegrationHealth\IntegrationHealthConfig;
use Watchtower\Connector\Model\IntegrationHealth\IntegrationHealthConfigValidator;
use Watchtower\Connector\Model\StoreView\LiveStoreViewResolver;

/**
 * Guards the admin picker's submitted rows. The non-obvious property
 * locked here is that validation never short-circuits: the picker renders
 * errors inline per row, so a merchant who got several fields wrong must
 * see all of them at once rather than one per round-trip.
 */
class IntegrationHealthConfigValidatorTest extends TestCase
{
    public function testValidInputProducesNoErrors(): void
    {
        $errors = $this->validator()->validate(
            3,
            IntegrationHealthConfig::SOURCE_TYPE_CRON_JOB,
            'indexer_reindex_all_invalid',
            60
        );

        self::assertSame([], $errors);
    }

    public function testEachOfTheThreeSourceTypesIsAccepted(): void
    {
        $validator = $this->validator();

        foreach ([
            IntegrationHealthConfig::SOURCE_TYPE_CRON_JOB,
            IntegrationHealthConfig::SOURCE_TYPE_QUEUE_CONSUMER,
            IntegrationHealthConfig::SOURCE_TYPE_CONVENTION_EVENT,
        ] as $sourceType) {
            self::assertSame([], $validator->validate(3, $sourceType, 'anything', 15));
        }
    }

    public function testAnInactiveStoreViewIsRejected(): void
    {
        $errors = $this->validator()->validate(
            9,
            IntegrationHealthConfig::SOURCE_TYPE_CRON_JOB,
            'indexer_reindex_all_invalid',
            60
        );

        self::assertSame(['Store view 9 is not a currently live store view.'], $errors);
    }

    public function testAnUnknownStoreViewIsRejected(): void
    {
        $errors = $this->validator()->validate(
            404,
            IntegrationHealthConfig::SOURCE_TYPE_CRON_JOB,
            'indexer_reindex_all_invalid',
            60
        );

        self::assertSame(['Store view 404 is not a currently live store view.'], $errors);
    }

    public function testAnUnsupportedSourceTypeIsRejected(): void
    {
        $errors = $this->validator()->validate(3, 'checkout_event', 'something', 60);

        self::assertSame(['Source type "checkout_event" is not a supported source type.'], $errors);
    }

    public function testAWhitespaceOnlySourceIdentifierIsRejected(): void
    {
        $errors = $this->validator()->validate(3, IntegrationHealthConfig::SOURCE_TYPE_CRON_JOB, '   ', 60);

        self::assertSame(['A source identifier is required.'], $errors);
    }

    public function testAZeroOrNegativeIntervalIsRejected(): void
    {
        $validator = $this->validator();
        $expected = ['The expected max interval must be a positive number of minutes.'];

        self::assertSame($expected, $validator->validate(3, IntegrationHealthConfig::SOURCE_TYPE_CRON_JOB, 'a', 0));
        self::assertSame($expected, $validator->validate(3, IntegrationHealthConfig::SOURCE_TYPE_CRON_JOB, 'a', -5));
    }

    /**
     * watchtower_integration_health_config.expected_max_interval_minutes is
     * an unsigned int column -- a direct POST bypassing the admin form
     * could otherwise overflow it and fatal on save rather than fail
     * validation cleanly.
     */
    public function testAnIntervalOverTheUpperBoundIsRejected(): void
    {
        $errors = $this->validator()->validate(3, IntegrationHealthConfig::SOURCE_TYPE_CRON_JOB, 'a', 99999999999);

        self::assertSame(['The expected max interval must be 43200 minutes or fewer.'], $errors);
    }

    /**
     * source_identifier is varchar(255) -- a direct POST could otherwise
     * overflow it and fatal on save.
     */
    public function testASourceIdentifierOverTheColumnWidthIsRejected(): void
    {
        $errors = $this->validator()->validate(
            3,
            IntegrationHealthConfig::SOURCE_TYPE_QUEUE_CONSUMER,
            str_repeat('a', 256),
            60
        );

        self::assertSame(['The source identifier must be 255 characters or fewer.'], $errors);
    }

    /**
     * A convention_event source_identifier is matched against
     * integration_label, a varchar(64) column -- anything longer could
     * never match a real dispatch, so it's rejected at its own tighter
     * bound rather than only the general 255-character one (C4 architect
     * review S3).
     */
    public function testAConventionEventIdentifierOverTheTighterLabelBoundIsRejected(): void
    {
        $errors = $this->validator()->validate(
            3,
            IntegrationHealthConfig::SOURCE_TYPE_CONVENTION_EVENT,
            str_repeat('a', 65),
            60
        );

        self::assertSame(['A convention event integration label must be 64 characters or fewer.'], $errors);
    }

    /**
     * AvailableSourcesProvider::cronJobCodes() already excludes this
     * module's own watchtower_* jobs from the admin picker's dropdown, but
     * that's a UI-only guard -- a direct POST to Save.php could otherwise
     * still configure the connector to monitor its own reporting job,
     * which is circular.
     */
    public function testAWatchtowerOwnedCronJobIsRejectedEvenViaADirectPost(): void
    {
        $errors = $this->validator()->validate(
            3,
            IntegrationHealthConfig::SOURCE_TYPE_CRON_JOB,
            'watchtower_report',
            60
        );

        self::assertSame(
            ['This connector\'s own scheduled jobs cannot be monitored as an integration.'],
            $errors
        );
    }

    /**
     * The watchtower_* exclusion is specific to cron_job -- a
     * queue_consumer or convention_event identifier happening to start
     * with "watchtower_" is not this module's own job code and must not be
     * rejected on that basis alone.
     */
    public function testTheWatchtowerPrefixExclusionOnlyAppliesToCronJobSources(): void
    {
        $errors = $this->validator()->validate(
            3,
            IntegrationHealthConfig::SOURCE_TYPE_CONVENTION_EVENT,
            'watchtower_report',
            60
        );

        self::assertSame([], $errors);
    }

    public function testEverySimultaneousViolationIsReportedTogether(): void
    {
        $errors = $this->validator()->validate(99, 'nonsense', '', 0);

        self::assertSame([
            'Store view 99 is not a currently live store view.',
            'Source type "nonsense" is not a supported source type.',
            'A source identifier is required.',
            'The expected max interval must be a positive number of minutes.',
        ], $errors);
    }

    /**
     * Store view 3 is live; 9 exists but is disabled; anything else is unknown.
     *
     * @return IntegrationHealthConfigValidator
     */
    private function validator(): IntegrationHealthConfigValidator
    {
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStores')->willReturn([
            3 => $this->store(3, true),
            9 => $this->store(9, false),
        ]);

        return new IntegrationHealthConfigValidator(new LiveStoreViewResolver($storeManager));
    }

    /**
     * @param int $id
     * @param bool $isActive
     * @return Store
     */
    private function store(int $id, bool $isActive): Store
    {
        $store = $this->createStub(Store::class);
        $store->method('getId')->willReturn($id);
        $store->method('getIsActive')->willReturn($isActive);

        return $store;
    }
}
