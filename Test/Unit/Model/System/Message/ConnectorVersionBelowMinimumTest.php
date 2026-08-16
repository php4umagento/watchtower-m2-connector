<?php
/**
 * Copyright © 2026 Watchtower. All rights reserved.
 * Licensed under the Business Source License 1.1 (BUSL-1.1). See LICENSE.
 */

declare(strict_types=1);

namespace Watchtower\Connector\Test\Unit\Model\System\Message;

use Magento\Framework\Escaper;
use Magento\Framework\Notification\MessageInterface;
use PHPUnit\Framework\TestCase;
use Watchtower\Connector\Model\Environment\ConnectorVersionState;
use Watchtower\Connector\Model\Environment\ConnectorVersionStateRepository;
use Watchtower\Connector\Model\System\Message\ConnectorVersionBelowMinimum;

class ConnectorVersionBelowMinimumTest extends TestCase
{
    public function testIsNotDisplayedWhenTheInstallIsAtOrAboveTheMinimumVersion(): void
    {
        $message = $this->message($this->state(belowMinimum: false));

        self::assertFalse($message->isDisplayed());
    }

    public function testIsNotDisplayedWhenAVersionCheckHasNeverSucceeded(): void
    {
        $message = $this->message(new ConnectorVersionState(
            installedVersion: null,
            minimumVersion: null,
            latestVersion: null,
            belowMinimum: false,
            updateAvailable: false,
            checkedAt: null,
        ));

        self::assertFalse($message->isDisplayed());
    }

    public function testIsDisplayedWhileTheInstallIsBelowTheMinimumVersion(): void
    {
        $message = $this->message($this->state(belowMinimum: true));

        self::assertTrue($message->isDisplayed());
    }

    public function testTheTextNamesBothTheInstalledAndTheRequiredVersion(): void
    {
        $text = (string) $this->message($this->state(belowMinimum: true))->getText();

        self::assertStringContainsString('1.0.1', $text);
        self::assertStringContainsString('1.2.0', $text);
    }

    /**
     * Both versions originate from an HTTP response body, so they are
     * attacker-influenced strings rendered into the admin's own HTML --
     * they must go through Escaper rather than being interpolated raw.
     */
    public function testBothVersionsAreEscapedBeforeReachingTheAdminHtml(): void
    {
        $escaper = $this->createMock(Escaper::class);
        $escaper->expects(self::exactly(2))->method('escapeHtml')->willReturnCallback(
            static fn (string $value): string => 'escaped(' . $value . ')'
        );

        $text = (string) $this->message($this->state(belowMinimum: true), $escaper)->getText();

        self::assertStringContainsString('escaped(1.0.1)', $text);
        self::assertStringContainsString('escaped(1.2.0)', $text);
    }

    /**
     * An install self-disabled before its version could be determined still
     * has to render a notice rather than a fatal -- "unknown" is the
     * fallback, not a null interpolation.
     */
    public function testUnknownVersionsFallBackToAPlaceholderRatherThanRenderingNull(): void
    {
        $text = (string) $this->message(new ConnectorVersionState(
            installedVersion: null,
            minimumVersion: null,
            latestVersion: null,
            belowMinimum: true,
            updateAvailable: false,
            checkedAt: new \DateTimeImmutable('2026-08-14T10:00:00+00:00'),
        ))->getText();

        self::assertStringContainsString('unknown', $text);
    }

    /**
     * Magento persists per-identity dismissal state, so the identity is a
     * stored key rather than an implementation detail -- changing it would
     * resurrect a notice an admin already dealt with.
     */
    public function testTheIdentityIsStable(): void
    {
        self::assertSame(
            'watchtower_connector_below_minimum_version',
            $this->message($this->state(belowMinimum: true))->getIdentity()
        );
    }

    public function testTheSeverityIsMajor(): void
    {
        self::assertSame(
            MessageInterface::SEVERITY_MAJOR,
            $this->message($this->state(belowMinimum: true))->getSeverity()
        );
    }

    private function state(bool $belowMinimum): ConnectorVersionState
    {
        return new ConnectorVersionState(
            installedVersion: '1.0.1',
            minimumVersion: '1.2.0',
            latestVersion: '1.3.0',
            belowMinimum: $belowMinimum,
            updateAvailable: true,
            checkedAt: new \DateTimeImmutable('2026-08-14T10:00:00+00:00'),
        );
    }

    private function message(ConnectorVersionState $state, ?Escaper $escaper = null): ConnectorVersionBelowMinimum
    {
        $repository = $this->createStub(ConnectorVersionStateRepository::class);
        $repository->method('get')->willReturn($state);

        if ($escaper === null) {
            $escaper = $this->createStub(Escaper::class);
            $escaper->method('escapeHtml')->willReturnArgument(0);
        }

        return new ConnectorVersionBelowMinimum($repository, $escaper);
    }
}
