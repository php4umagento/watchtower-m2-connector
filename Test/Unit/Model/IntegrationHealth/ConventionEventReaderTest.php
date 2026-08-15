<?php

declare(strict_types=1);

namespace Watchtower\Connector\Test\Unit\Model\IntegrationHealth;

use PHPUnit\Framework\TestCase;
use Watchtower\Connector\Model\IntegrationHealth\ConventionEventReader;
use Watchtower\Connector\Model\IntegrationHealth\IntegrationHealthEventRepository;
use Watchtower\Connector\Model\IntegrationHealth\Observation;

/**
 * A thin delegation to IntegrationHealthEventRepository::latestObservation()
 * -- the query shape itself is covered by IntegrationHealthEventRepositoryTest.php;
 * this only proves the delegation forwards the right arguments and returns
 * the repository's own result unchanged.
 */
class ConventionEventReaderTest extends TestCase
{
    public function testDelegatesToTheRepositoryWithTheSameArgumentsAndReturnsItsResult(): void
    {
        $now = new \DateTimeImmutable('2026-08-13T15:00:00+00:00');
        $expected = new Observation(latestSuccessAt: $now, latestFailureAt: null);

        $repository = $this->createMock(IntegrationHealthEventRepository::class);
        $repository->expects(self::once())
            ->method('latestObservation')
            ->with(3, 'erp_sync', $now)
            ->willReturn($expected);

        $result = (new ConventionEventReader($repository))->observe(3, 'erp_sync', $now);

        self::assertSame($expected, $result);
    }
}
