<?php
/**
 * Copyright © 2026 Watchtower. All rights reserved.
 * Licensed under the Business Source License 1.1 (BUSL-1.1). See LICENSE.
 */

declare(strict_types=1);

namespace Watchtower\Connector\Test\Unit\Model\Diagnostics;

use PHPUnit\Framework\TestCase;
use Watchtower\Connector\Model\Api\SignalStatus;
use Watchtower\Connector\Model\Diagnostics\InsufficientDataLabel;
use Watchtower\Connector\Model\Diagnostics\SignalSnapshot;
use Watchtower\Connector\Model\Seed\SeedCoverageResult;
use Watchtower\Connector\Model\Seed\SeedCoverageStatus;

class InsufficientDataLabelTest extends TestCase
{
    private function signal(string $category, int $sequence, ?SeedCoverageResult $coverage = null): SignalSnapshot
    {
        return new SignalSnapshot(
            category: $category,
            status: SignalStatus::InsufficientData,
            sequenceNumber: $sequence,
            seedCoverage: $coverage,
        );
    }

    public function testASeededRateSignalIsTooQuietNotWarmingUp(): void
    {
        $label = new InsufficientDataLabel();
        $signal = $this->signal('checkout', 5, new SeedCoverageResult('checkout', 28, 28, SeedCoverageStatus::Seeded));

        self::assertSame('Too quiet to judge', $label->label($signal));
        self::assertFalse($label->isWarmingUp($signal));
    }

    public function testAnUnseededRateSignalWarmsUpUntilAFullWindowOfEvaluations(): void
    {
        $label = new InsufficientDataLabel();

        self::assertSame('Warming up', $label->label($this->signal('customer_account', 100)));
        self::assertTrue($label->isWarmingUp($this->signal('customer_account', 100)));
        self::assertSame('Too quiet to judge', $label->label($this->signal('customer_account', 28 * 24)));
    }

    public function testAStateBasedSignalIsLearningTheScheduleNeverWarmingUpNorQuiet(): void
    {
        $label = new InsufficientDataLabel();

        foreach (['cron_health', 'integration_health'] as $category) {
            self::assertSame('Learning the schedule', $label->label($this->signal($category, 5000)), $category);
        }
    }
}
