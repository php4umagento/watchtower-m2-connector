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
use Watchtower\Connector\Model\System\Message\ConnectorUpdateAvailable;

class ConnectorUpdateAvailableTest extends TestCase
{
    public function testIsNotDisplayedWhenAlreadyOnTheLatestVersion(): void
    {
        $message = $this->message($this->state(updateAvailable: false, belowMinimum: false));

        self::assertFalse($message->isDisplayed());
    }

    public function testIsDisplayedWhenAnUpdateIsAvailable(): void
    {
        $message = $this->message($this->state(updateAvailable: true, belowMinimum: false));

        self::assertTrue($message->isDisplayed());
    }

    /**
     * belowMinimum already shows its own, more urgent notice for the same
     * underlying version gap -- showing both would be redundant noise.
     */
    public function testIsNotDisplayedWhenAlsoBelowMinimumVersion(): void
    {
        $message = $this->message($this->state(updateAvailable: true, belowMinimum: true));

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

    public function testTheTextNamesBothTheInstalledAndTheLatestVersion(): void
    {
        $text = (string) $this->message($this->state(updateAvailable: true, belowMinimum: false))->getText();

        self::assertStringContainsString('1.1.0', $text);
        self::assertStringContainsString('1.2.0', $text);
    }

    /**
     * Both versions originate from an HTTP response body, so they are
     * attacker-influenced strings rendered into the admin's own HTML -- they
     * must go through Escaper rather than being interpolated raw.
     */
    public function testBothVersionsAreEscapedBeforeReachingTheAdminHtml(): void
    {
        $escaper = $this->createMock(Escaper::class);
        $escaper->expects(self::exactly(2))->method('escapeHtml')->willReturnCallback(
            static fn (string $value): string => 'escaped(' . $value . ')'
        );

        $text = (string) $this->message($this->state(updateAvailable: true, belowMinimum: false), $escaper)->getText();

        self::assertStringContainsString('escaped(1.1.0)', $text);
        self::assertStringContainsString('escaped(1.2.0)', $text);
    }

    public function testTheIdentityIsStable(): void
    {
        self::assertSame(
            'watchtower_connector_update_available',
            $this->message($this->state(updateAvailable: true, belowMinimum: false))->getIdentity()
        );
    }

    public function testTheSeverityIsNotice(): void
    {
        self::assertSame(
            MessageInterface::SEVERITY_NOTICE,
            $this->message($this->state(updateAvailable: true, belowMinimum: false))->getSeverity()
        );
    }

    private function state(bool $updateAvailable, bool $belowMinimum): ConnectorVersionState
    {
        return new ConnectorVersionState(
            installedVersion: '1.1.0',
            minimumVersion: '1.0.0',
            latestVersion: '1.2.0',
            belowMinimum: $belowMinimum,
            updateAvailable: $updateAvailable,
            checkedAt: new \DateTimeImmutable('2026-08-14T10:00:00+00:00'),
        );
    }

    private function message(ConnectorVersionState $state, ?Escaper $escaper = null): ConnectorUpdateAvailable
    {
        $repository = $this->createStub(ConnectorVersionStateRepository::class);
        $repository->method('get')->willReturn($state);

        if ($escaper === null) {
            $escaper = $this->createStub(Escaper::class);
            $escaper->method('escapeHtml')->willReturnArgument(0);
        }

        return new ConnectorUpdateAvailable($repository, $escaper);
    }
}
