<?php
/**
 * Copyright © 2026 Watchtower. All rights reserved.
 * Licensed under the Business Source License 1.1 (BUSL-1.1). See LICENSE.
 */

declare(strict_types=1);

namespace Watchtower\Connector\Test\Unit\Model\Diagnostics;

use PHPUnit\Framework\TestCase;
use Watchtower\Connector\Model\Api\SignalStatus;
use Watchtower\Connector\Model\Diagnostics\CorroborationLabel;
use Watchtower\Connector\Model\Diagnostics\SignalSnapshot;

class CorroborationLabelTest extends TestCase
{
    public function testActiveCorroboratingStatusReadsAsAQuietStretch(): void
    {
        $label = new CorroborationLabel();

        foreach ([SignalStatus::Normal, SignalStatus::MildSpike, SignalStatus::SevereSpike] as $status) {
            $signal = new SignalSnapshot(
                category: 'checkout',
                status: SignalStatus::InsufficientData,
                sequenceNumber: 1,
                corroboratingCategory: 'basket_quote',
                corroboratingStatus: $status,
            );

            self::assertSame(
                'Cart activity is normal this period. Likely a quiet stretch, not a fault.',
                $label->describe($signal),
                $status->value
            );
        }
    }

    public function testCorroboratingInsufficientDataReadsAsNoFunnelActivity(): void
    {
        $label = new CorroborationLabel();

        $signal = new SignalSnapshot(
            category: 'checkout',
            status: SignalStatus::InsufficientData,
            sequenceNumber: 1,
            corroboratingCategory: 'basket_quote',
            corroboratingStatus: SignalStatus::InsufficientData,
        );

        self::assertSame(
            'No cart activity either. No funnel activity detected this period.',
            $label->describe($signal)
        );
    }

    public function testCorroboratingDropIsStatedPlainlyRatherThanFoldedIntoQuietWording(): void
    {
        $label = new CorroborationLabel();

        foreach ([SignalStatus::MildDrop, SignalStatus::SevereDrop] as $status) {
            $signal = new SignalSnapshot(
                category: 'checkout',
                status: SignalStatus::InsufficientData,
                sequenceNumber: 1,
                corroboratingCategory: 'basket_quote',
                corroboratingStatus: $status,
            );

            self::assertSame(
                'Cart activity is also down this period.',
                $label->describe($signal),
                $status->value
            );
        }
    }

    public function testNoCorroboratingCategoryRendersEmpty(): void
    {
        $label = new CorroborationLabel();

        $signal = new SignalSnapshot(
            category: 'basket_quote',
            status: SignalStatus::InsufficientData,
            sequenceNumber: 1,
        );

        self::assertSame('', $label->describe($signal));
    }

    /**
     * The corroborating category has never been evaluated -- says nothing
     * rather than guessing at "no activity" for data that doesn't exist yet.
     */
    public function testCorroboratingCategoryWithNoStateYetRendersEmpty(): void
    {
        $label = new CorroborationLabel();

        $signal = new SignalSnapshot(
            category: 'checkout',
            status: SignalStatus::InsufficientData,
            sequenceNumber: 1,
            corroboratingCategory: 'basket_quote',
            corroboratingStatus: null,
        );

        self::assertSame('', $label->describe($signal));
    }

    public function testSignalNotInsufficientDataRendersEmptyEvenWithACorroboratingCategory(): void
    {
        $label = new CorroborationLabel();

        $signal = new SignalSnapshot(
            category: 'checkout',
            status: SignalStatus::Normal,
            sequenceNumber: 1,
            corroboratingCategory: 'basket_quote',
            corroboratingStatus: SignalStatus::Normal,
        );

        self::assertSame('', $label->describe($signal));
    }

    public function testUnrecognizedCorroboratingCategoryFallsBackToTheRawCategoryString(): void
    {
        $label = new CorroborationLabel();

        $signal = new SignalSnapshot(
            category: 'checkout',
            status: SignalStatus::InsufficientData,
            sequenceNumber: 1,
            corroboratingCategory: 'some_future_category',
            corroboratingStatus: SignalStatus::InsufficientData,
        );

        self::assertSame(
            'No some_future_category activity either. No funnel activity detected this period.',
            $label->describe($signal)
        );
    }
}
