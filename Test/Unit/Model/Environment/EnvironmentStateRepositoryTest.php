<?php
/**
 * Copyright © 2026 Watchtower. All rights reserved.
 * Licensed under the Business Source License 1.1 (BUSL-1.1). See LICENSE.
 */

declare(strict_types=1);

namespace Watchtower\Connector\Test\Unit\Model\Environment;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use PHPUnit\Framework\TestCase;
use Watchtower\Connector\Model\Api\ConnectorUpdateInfo;
use Watchtower\Connector\Model\Api\MagentoEolInfo;
use Watchtower\Connector\Model\Environment\EnvironmentStateRepository;

/**
 * Mirrors DispersionStateRepositoryTest/OrganizationStateRepositoryTest's own
 * coverage shape for the singleton-row pattern this repository shares with
 * OrganizationStateRepository.
 */
class EnvironmentStateRepositoryTest extends TestCase
{
    public function testGetReturnsAnAllNullStateWhenNoRowExistsYet(): void
    {
        $repository = $this->repositoryReturning(fetchRowResult: false);

        $state = $repository->get();

        self::assertNull($state->magentoVersion);
        self::assertNull($state->magentoEdition);
        self::assertNull($state->connectorVersion);
        self::assertNull($state->magentoEol);
        self::assertNull($state->connectorUpdate);
        self::assertNull($state->syncedAt);
    }

    public function testGetMapsAFullyPopulatedRowToItsTypedFields(): void
    {
        $repository = $this->repositoryReturning(fetchRowResult: [
            'magento_version' => '2.4.7-p5',
            'magento_edition' => 'Community',
            'connector_version' => '1.0.1',
            'magento_is_eol' => '0',
            'magento_eol_date' => '2027-04-09',
            'magento_status_label' => 'supported',
            'connector_update_available' => '1',
            'connector_latest_version' => '1.1.0',
            'synced_at' => '2026-08-14 10:00:00',
        ]);

        $state = $repository->get();

        self::assertSame('2.4.7-p5', $state->magentoVersion);
        self::assertSame('Community', $state->magentoEdition);
        self::assertSame('1.0.1', $state->connectorVersion);
        self::assertNotNull($state->magentoEol);
        self::assertFalse($state->magentoEol->isEol);
        self::assertSame('2027-04-09', $state->magentoEol->eolDate);
        self::assertSame('supported', $state->magentoEol->statusLabel);
        self::assertNotNull($state->connectorUpdate);
        self::assertTrue($state->connectorUpdate->updateAvailable);
        self::assertSame('1.1.0', $state->connectorUpdate->latestVersion);
        self::assertSame('2026-08-14T10:00:00+00:00', $state->syncedAt?->format(\DateTimeInterface::ATOM));
    }

    /**
     * A null magento_is_eol/connector_update_available column (the platform
     * couldn't determine either on the last sync) must map to a null
     * MagentoEolInfo/ConnectorUpdateInfo object, not one with a false-y
     * isEol/updateAvailable -- callers need to tell "known not EOL" apart
     * from "undetermined".
     */
    public function testUndeterminedEolAndUpdateColumnsMapToNullObjectsNotFalseyOnes(): void
    {
        $repository = $this->repositoryReturning(fetchRowResult: [
            'magento_version' => '2.4.7-p5',
            'magento_edition' => 'Community',
            'connector_version' => '1.0.1',
            'magento_is_eol' => null,
            'magento_eol_date' => null,
            'magento_status_label' => null,
            'connector_update_available' => null,
            'connector_latest_version' => null,
            'synced_at' => '2026-08-14 10:00:00',
        ]);

        $state = $repository->get();

        self::assertNull($state->magentoEol);
        self::assertNull($state->connectorUpdate);
    }

    public function testSavePersistsEveryFieldThroughInsertOnDuplicate(): void
    {
        $savedRow = null;

        $connection = $this->createMock(AdapterInterface::class);
        $connection->expects(self::once())->method('insertOnDuplicate')->willReturnCallback(
            function (string $table, array $data) use (&$savedRow) {
                $savedRow = $data;

                return 1;
            }
        );

        $resourceConnection = $this->createStub(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturn('watchtower_environment_state');

        $repository = new EnvironmentStateRepository($resourceConnection);
        $repository->save(
            '2.4.9',
            'Community',
            '1.1.0',
            new MagentoEolInfo(isEol: false, eolDate: '2027-04-09', statusLabel: 'supported'),
            new ConnectorUpdateInfo(updateAvailable: false, latestVersion: '1.1.0'),
            new \DateTimeImmutable('2026-08-14T10:00:00+00:00'),
        );

        self::assertSame(1, $savedRow['id']);
        self::assertSame('2.4.9', $savedRow['magento_version']);
        self::assertSame('Community', $savedRow['magento_edition']);
        self::assertSame('1.1.0', $savedRow['connector_version']);
        self::assertSame(0, $savedRow['magento_is_eol']);
        self::assertSame('2027-04-09', $savedRow['magento_eol_date']);
        self::assertSame(0, $savedRow['connector_update_available']);
        self::assertSame('2026-08-14 10:00:00', $savedRow['synced_at']);
    }

    public function testSavePersistsNullEolAndUpdateInfoAsNullColumnsNotFalse(): void
    {
        $savedRow = null;

        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('insertOnDuplicate')->willReturnCallback(
            function (string $table, array $data) use (&$savedRow) {
                $savedRow = $data;

                return 1;
            }
        );

        $resourceConnection = $this->createStub(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturn('watchtower_environment_state');

        $repository = new EnvironmentStateRepository($resourceConnection);
        $repository->save(null, null, null, null, null, new \DateTimeImmutable('2026-08-14T10:00:00+00:00'));

        self::assertNull($savedRow['magento_is_eol']);
        self::assertNull($savedRow['connector_update_available']);
    }

    private function repositoryReturning(array|false $fetchRowResult): EnvironmentStateRepository
    {
        $select = $this->createStub(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();

        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        $connection->method('fetchRow')->willReturn($fetchRowResult);

        $resourceConnection = $this->createStub(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturn('watchtower_environment_state');

        return new EnvironmentStateRepository($resourceConnection);
    }
}
